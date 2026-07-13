<?php

declare(strict_types=1);

namespace Timer\Services;

final class LupcomTimetableParser
{
    /**
     * @return list<ParsedDay>
     */
    public function parse(string $content): array
    {
        $content = $this->stripBom($content);
        $lines = preg_split('/\R/', $content) ?: [];
        $rows = [];

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }

            $rows[] = str_getcsv($line, ';', '"', '\\');
        }

        return $this->parseRows($rows);
    }

    /**
     * @param list<list<string>> $rows
     * @return list<ParsedDay>
     */
    public function parseRows(array $rows): array
    {
        /** @var array<int, string> $weekDates column index => Y-m-d */
        $weekDates = [];
        /** @var array<string, ParsedDay> $days */
        $days = [];
        $afterLunch = false;

        foreach ($rows as $cells) {
            if ($this->isWeekBoundaryRow($cells)) {
                $weekDates = [];
                $afterLunch = false;
            }

            $foundDates = $this->extractWeekDates($cells, $weekDates);

            if ($foundDates) {
                $afterLunch = false;

                foreach ($weekDates as $date) {
                    if (!isset($days[$date])) {
                        $days[$date] = $this->emptyDay($date);
                    }
                }
            }

            if ($weekDates === []) {
                continue;
            }

            $label = mb_strtolower(trim((string) ($cells[0] ?? '')));

            if ($label === 'arbeitsbeginn') {
                $this->applyTimeRow($cells, $weekDates, $days, $afterLunch, true);
            } elseif ($label === 'arbeitsende') {
                $this->applyTimeRow($cells, $weekDates, $days, $afterLunch, false);
            } elseif ($label === 'mittagspause') {
                $afterLunch = true;
            }
        }

        ksort($days);

        return array_values($days);
    }

    /**
     * @param list<string> $cells
     * @param array<int, string> $weekDates
     */
    private function extractWeekDates(array $cells, array &$weekDates): bool
    {
        /** @var array<int, string> $datesInRow */
        $datesInRow = [];

        foreach ($cells as $index => $cell) {
            $date = $this->parseGermanDate(trim($cell));
            if ($date === null) {
                continue;
            }

            $datesInRow[(int) $index] = $date;
        }

        if (count($datesInRow) < 2) {
            return false;
        }

        $weekDates = $datesInRow;

        return true;
    }

    /** @param list<string> $cells */
    private function isWeekBoundaryRow(array $cells): bool
    {
        foreach ($cells as $cell) {
            $value = mb_strtolower(trim($cell));
            if ($value === '') {
                continue;
            }

            if (str_contains($value, 'woche beginnt am')
                || str_contains($value, 'name des mitarbeiters')
                || str_contains($value, 'lupcom media')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $cells
     * @param array<int, string> $weekDates
     * @param array<string, ParsedDay> $days
     */
    private function applyTimeRow(
        array $cells,
        array $weekDates,
        array &$days,
        bool $afterLunch,
        bool $isStart,
    ): void {
        foreach ($weekDates as $column => $date) {
            $raw = trim((string) ($cells[$column] ?? ''));
            if ($raw === '') {
                continue;
            }

            $time = $this->normalizeTime($raw);
            $field = $this->fieldName($afterLunch, $isStart);

            if (!isset($days[$date])) {
                $days[$date] = $this->emptyDay($date);
            }

            $days[$date][$field] = $time;
        }
    }

    private function fieldName(bool $afterLunch, bool $isStart): string
    {
        if ($afterLunch) {
            return $isStart ? 'afternoon_start' : 'afternoon_end';
        }

        return $isStart ? 'morning_start' : 'morning_end';
    }

    /** @return ParsedDay */
    private function emptyDay(string $date): array
    {
        return [
            'date' => $date,
            'morning_start' => null,
            'morning_end' => null,
            'afternoon_start' => null,
            'afternoon_end' => null,
        ];
    }

    private function parseGermanDate(string $value): ?string
    {
        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $value, $matches) !== 1) {
            return null;
        }

        $day = (int) $matches[1];
        $month = (int) $matches[2];
        $year = (int) $matches[3];

        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function normalizeTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || $value === '0:00' || $value === '00:00') {
            return null;
        }

        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $value, $matches) !== 1) {
            return null;
        }

        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];

        if ($hours > 23 || $minutes > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hours, $minutes);
    }

    private function stripBom(string $content): string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            return substr($content, 3);
        }

        return $content;
    }
}
