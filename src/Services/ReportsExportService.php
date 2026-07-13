<?php

declare(strict_types=1);

namespace Timer\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Timer\Core\View;
use Timer\Models\TimeEntry;
use Timer\Support\DateHelper;
use Timer\Support\ReportsFilter;
use Timer\Support\TimeFormatter;
use Timer\Support\Translator;

final class ReportsExportService
{
    public function __construct(
        private readonly View $view,
        private readonly Translator $translator,
    ) {
    }

    /**
     * @param list<TimeEntry> $entries
     */
    public function toCsv(array $entries, ReportsFilter $filter, int $totalSeconds): string
    {
        $delimiter = $this->translator->locale() === 'de' ? ';' : ',';
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            throw new \RuntimeException('Unable to create CSV buffer.');
        }

        fwrite($handle, "\xEF\xBB\xBF");

        fputcsv($handle, [
            $this->translator->trans('common.date'),
            $this->translator->trans('common.project'),
            $this->translator->trans('dashboard.task_reason'),
            $this->translator->trans('reports.subject'),
            $this->translator->trans('common.started'),
            $this->translator->trans('common.ended'),
            $this->translator->trans('common.duration'),
        ], $delimiter, '"', '\\');

        foreach ($entries as $entry) {
            fputcsv($handle, [
                DateHelper::formatCompactDate($entry->startedAt),
                $this->projectLabel($entry),
                $this->taskLabel($entry),
                $entry->subject() ?? '',
                DateHelper::formatCompactDateTime($entry->startedAt),
                $entry->endedAt !== null ? DateHelper::formatCompactDateTime($entry->endedAt) : '',
                TimeFormatter::secondsToClock($entry->durationSeconds ?? 0),
            ], $delimiter, '"', '\\');
        }

        fputcsv($handle, [], $delimiter, '"', '\\');
        fputcsv($handle, [
            '',
            '',
            '',
            '',
            '',
            $this->translator->trans('common.total'),
            TimeFormatter::secondsToClock($totalSeconds),
        ], $delimiter, '"', '\\');

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return is_string($csv) ? $csv : '';
    }

    /**
     * @param list<TimeEntry> $entries
     */
    public function toPdf(
        array $entries,
        ReportsFilter $filter,
        int $totalSeconds,
        string $locale,
    ): string {
        $html = $this->view->renderToString('reports/export-pdf.html.twig', [
            'entries' => $entries,
            'filter' => $filter,
            'period_label' => $filter->periodLabel($locale),
            'project_label' => $filter->projectName ?? $this->translator->trans('common.all_projects'),
            'task_label' => $filter->taskName ?? $this->translator->trans('common.all_tasks'),
            'total_seconds' => $totalSeconds,
            'total_label' => TimeFormatter::secondsToHuman($totalSeconds),
            'generated_at' => (new \DateTimeImmutable())->format('Y-m-d H:i'),
        ]);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return $dompdf->output();
    }

    private function projectLabel(TimeEntry $entry): string
    {
        if ($entry->isGeneral()) {
            return $this->translator->trans('common.general');
        }

        return $entry->projectName ?? '—';
    }

    private function taskLabel(TimeEntry $entry): string
    {
        if ($entry->isGeneral()) {
            return $entry->notes ?: $this->translator->trans('common.general_time');
        }

        return $entry->displayLabel();
    }
}
