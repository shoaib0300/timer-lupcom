<?php

declare(strict_types=1);

namespace Timer\Services;

use DateTimeImmutable;
use SimpleXMLElement;
use ZipArchive;

final class LupcomXlsxTimetableReader
{
    /**
     * @return list<list<string>>
     */
    public function rows(string $filePath): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new \InvalidArgumentException('xlsx_not_supported');
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new \InvalidArgumentException('invalid_xlsx');
        }

        $sharedStrings = $this->readSharedStrings($zip);
        $rows = [];

        for ($sheet = 1; $sheet <= 20; ++$sheet) {
            $sheetPath = 'xl/worksheets/sheet' . $sheet . '.xml';
            if ($zip->locateName($sheetPath) === false) {
                continue;
            }

            $xml = $zip->getFromName($sheetPath);
            if ($xml === false) {
                continue;
            }

            $rows = array_merge($rows, $this->readSheetRows($xml, $sharedStrings));
        }

        $zip->close();

        if ($rows === []) {
            throw new \InvalidArgumentException('no_days_found');
        }

        return $rows;
    }

    /** @return list<string> */
    private function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $doc = new SimpleXMLElement($xml);
        $strings = [];

        foreach ($doc->si as $item) {
            if (isset($item->t)) {
                $strings[] = (string) $item->t;
                continue;
            }

            $parts = [];
            foreach ($item->r as $run) {
                $parts[] = (string) $run->t;
            }

            $strings[] = implode('', $parts);
        }

        return $strings;
    }

    /**
     * @param list<string> $sharedStrings
     * @return list<list<string>>
     */
    private function readSheetRows(string $xml, array $sharedStrings): array
    {
        $doc = new SimpleXMLElement($xml);
        if (!isset($doc->sheetData->row)) {
            return [];
        }

        /** @var array<int, array<int, string>> $grid */
        $grid = [];

        foreach ($doc->sheetData->row as $row) {
            $rowNumber = (int) ($row['r'] ?? 0);
            if ($rowNumber <= 0) {
                continue;
            }

            foreach ($row->c as $cell) {
                $ref = (string) ($cell['r'] ?? '');
                if ($ref === '') {
                    continue;
                }

                [$parsedRow, $columnIndex] = $this->parseCellReference($ref);
                if ($columnIndex < 0) {
                    continue;
                }

                $rowNumber = $parsedRow;
                $type = isset($cell['t']) ? (string) $cell['t'] : null;
                $rawValue = isset($cell->v) ? (string) $cell->v : '';
                $grid[$rowNumber][$columnIndex] = $this->formatCellValue($rawValue, $type, $sharedStrings);
            }
        }

        if ($grid === []) {
            return [];
        }

        ksort($grid);
        $rows = [];

        foreach ($grid as $cells) {
            if ($cells === []) {
                continue;
            }

            $maxColumn = max(array_keys($cells));
            $line = [];
            for ($column = 0; $column <= $maxColumn; ++$column) {
                $line[] = $cells[$column] ?? '';
            }

            if (trim(implode('', $line)) === '') {
                continue;
            }

            $rows[] = $line;
        }

        return $rows;
    }

    /** @return array{0: int, 1: int} */
    private function parseCellReference(string $reference): array
    {
        if (preg_match('/^([A-Z]+)(\d+)$/', strtoupper($reference), $matches) !== 1) {
            return [0, -1];
        }

        $letters = $matches[1];
        $row = (int) $matches[2];
        $columnNumber = 0;

        foreach (str_split($letters) as $letter) {
            $columnNumber = ($columnNumber * 26) + (ord($letter) - 64);
        }

        return [$row, $columnNumber - 2];
    }

    private function formatCellValue(string $value, ?string $type, array $sharedStrings): string
    {
        if ($value === '') {
            return '';
        }

        if ($type === 's') {
            return $sharedStrings[(int) $value] ?? '';
        }

        if ($type === 'str' || $type === 'inlineStr') {
            return $value;
        }

        if (!is_numeric($value)) {
            return $value;
        }

        $number = (float) $value;

        if ($number >= 30000 && $number < 60000) {
            return $this->serialToGermanDate((int) round($number));
        }

        if ($number > 0 && $number < 1) {
            return $this->fractionToTime($number);
        }

        if ($number === 0.0) {
            return '0:00';
        }

        return $value;
    }

    private function serialToGermanDate(int $serial): string
    {
        $date = (new DateTimeImmutable('1899-12-30'))->modify('+' . $serial . ' days');

        return $date->format('d.m.Y');
    }

    private function fractionToTime(float $fraction): string
    {
        $totalMinutes = (int) round($fraction * 24 * 60);
        $hours = intdiv($totalMinutes, 60) % 24;
        $minutes = $totalMinutes % 60;

        return sprintf('%d:%02d', $hours, $minutes);
    }
}
