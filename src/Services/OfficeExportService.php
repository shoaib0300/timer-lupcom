<?php

declare(strict_types=1);

namespace Timer\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Timer\Core\View;
use Timer\Support\DateHelper;
use Timer\Support\TimeFormatter;
use Timer\Support\Translator;

final class OfficeExportService
{
    public function __construct(
        private readonly View $view,
        private readonly Translator $translator,
    ) {
    }

    /**
     * @param list<array{date: string, work_seconds: int}> $days
     */
    public function toCsv(array $days, string $from, string $to, int $totalSeconds): string
    {
        $delimiter = $this->translator->locale() === 'de' ? ';' : ',';
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new \RuntimeException('Unable to create CSV buffer.');
        }

        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, [
            $this->translator->trans('common.date'),
            $this->translator->trans('office.in_office'),
        ], $delimiter, '"', '\\');

        foreach ($days as $day) {
            fputcsv($handle, [
                DateHelper::formatCompactDate($day['date']),
                TimeFormatter::secondsToClock($day['work_seconds']),
            ], $delimiter, '"', '\\');
        }

        fputcsv($handle, [], $delimiter, '"', '\\');
        fputcsv($handle, [
            $this->translator->trans('common.total'),
            TimeFormatter::secondsToClock($totalSeconds),
        ], $delimiter, '"', '\\');

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return is_string($csv) ? $csv : '';
    }

    /**
     * @param list<array{date: string, work_seconds: int}> $days
     */
    public function toPdf(array $days, string $from, string $to, int $totalSeconds, string $locale): string
    {
        $fromLabel = DateHelper::formatCompactDate($from);
        $toLabel = DateHelper::formatCompactDate($to);
        $periodLabel = $from === $to ? $fromLabel : $fromLabel . ' – ' . $toLabel;

        $html = $this->view->renderToString('office/export-pdf.html.twig', [
            'days' => $days,
            'period_label' => $periodLabel,
            'total_label' => TimeFormatter::secondsToHuman($totalSeconds),
            'total_seconds' => $totalSeconds,
            'generated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i'),
        ]);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }

    public function filenameStem(string $from, string $to): string
    {
        return 'burozeit-' . $from . '-' . $to;
    }
}
