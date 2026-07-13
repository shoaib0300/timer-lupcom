<?php

declare(strict_types=1);

namespace Timer\Controllers;

use DateTimeImmutable;
use Timer\Http\Request;
use Timer\Http\Response;
use Timer\Repositories\ProjectRepository;
use Timer\Repositories\TaskRepository;
use Timer\Services\ReportsExportService;
use Timer\Support\CalendarGrid;
use Timer\Support\Locale;
use Timer\Support\ReportsFilter;
use Timer\Support\TimeFormatter;

final class ReportsController extends BaseController
{
    public function index(Request $request): Response
    {
        $filter = $this->resolveFilter($request);
        $selectedDay = $this->resolveDay((string) $request->query('day', ''));

        if ($selectedDay !== null && substr($selectedDay, 0, 7) !== $filter->month) {
            return $this->redirect(
                '/reports?' . $filter->queryString() . '&day=' . $selectedDay,
            );
        }

        $timeEntries = $this->timeEntries();
        $dailyTotals = $timeEntries->dailyTotals($filter->from, $filter->to, $filter->projectId, $filter->taskId);
        $monthTotalSeconds = $timeEntries->totalSecondsInRange(
            $filter->from,
            $filter->to,
            $filter->projectId,
            $filter->taskId,
        );
        $calendarCells = CalendarGrid::build($filter->month, $dailyTotals);

        $dayEntries = [];
        $dayTotalSeconds = 0;
        if ($selectedDay !== null) {
            $dayEntries = $timeEntries->forDate($selectedDay, $filter->projectId, $filter->taskId);
            $dayTotalSeconds = array_sum(
                array_map(static fn ($entry) => $entry->durationSeconds ?? 0, $dayEntries),
            );
        }

        $projects = $this->projects()->allWithStats();
        $tasks = $filter->projectId !== null
            ? $this->tasks()->forProject($filter->projectId)
            : [];

        $firstDay = new DateTimeImmutable($filter->month . '-01');
        $prevMonth = $firstDay->modify('-1 month')->format('Y-m');
        $nextMonth = $firstDay->modify('+1 month')->format('Y-m');

        $locale = $this->app->translator()->locale();

        return $this->view('reports/index.html.twig', [
            'month' => $filter->month,
            'month_label' => $filter->monthLabel($locale),
            'prev_month_query' => $this->filterQuery($prevMonth, $filter->projectId, $filter->taskId),
            'next_month_query' => $this->filterQuery($nextMonth, $filter->projectId, $filter->taskId),
            'project_id' => $filter->projectId,
            'task_id' => $filter->taskId,
            'selected_day' => $selectedDay,
            'selected_day_label' => $selectedDay
                ? Locale::formatDay(new DateTimeImmutable($selectedDay), $locale)
                : null,
            'projects' => $projects,
            'tasks' => $tasks,
            'calendar_cells' => $calendarCells,
            'month_total' => TimeFormatter::secondsToHuman($monthTotalSeconds),
            'month_total_seconds' => $monthTotalSeconds,
            'day_entries' => $dayEntries,
            'day_total' => TimeFormatter::secondsToHuman($dayTotalSeconds),
            'filter_query' => $filter->queryString(),
        ]);
    }

    public function export(Request $request): Response
    {
        $format = strtolower((string) $request->query('format', 'csv'));
        if (!in_array($format, ['csv', 'pdf'], true)) {
            return $this->redirect('/reports');
        }

        $filter = $this->resolveFilter($request);
        $timeEntries = $this->timeEntries();
        $entries = $timeEntries->forDateRange(
            $filter->from,
            $filter->to,
            $filter->projectId,
            $filter->taskId,
        );
        $totalSeconds = $timeEntries->totalSecondsByDateRange(
            $filter->from,
            $filter->to,
            $filter->projectId,
            $filter->taskId,
        );

        $exportService = new ReportsExportService($this->app->view(), $this->app->translator());
        $stem = $filter->exportFilenameStem();

        if ($format === 'pdf') {
            $body = $exportService->toPdf(
                $entries,
                $filter,
                $totalSeconds,
                $this->app->translator()->locale(),
            );

            return Response::download($body, 'application/pdf', $stem . '.pdf');
        }

        $body = $exportService->toCsv($entries, $filter, $totalSeconds);

        return Response::download($body, 'text/csv; charset=utf-8', $stem . '.csv');
    }

    private function resolveFilter(Request $request): ReportsFilter
    {
        $month = $this->resolveMonth((string) $request->query('month', ''));
        $projectId = $this->optionalInt($request->query('project_id'));
        $taskId = $this->optionalInt($request->query('task_id'));

        if ($taskId !== null && $projectId === null) {
            $taskId = null;
        }

        $projectName = null;
        $taskName = null;

        if ($projectId !== null) {
            $project = $this->projects()->find($projectId);
            if ($project === null) {
                $projectId = null;
            } else {
                $projectName = $project->name;
            }
        }

        if ($taskId !== null) {
            $task = $this->tasks()->find($taskId);
            if ($task === null || $task->projectId !== $projectId) {
                $taskId = null;
            } else {
                $taskName = $task->name;
            }
        }

        $firstDay = new DateTimeImmutable($month . '-01');
        $lastDay = $firstDay->modify('last day of this month');

        return new ReportsFilter(
            $month,
            $projectId,
            $taskId,
            $firstDay->format('Y-m-d'),
            $lastDay->format('Y-m-d'),
            $projectName,
            $taskName,
        );
    }

    private function resolveMonth(string $month): string
    {
        if (preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            return $month;
        }

        return (new DateTimeImmutable())->format('Y-m');
    }

    private function resolveDay(string $day): ?string
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) !== 1) {
            return null;
        }

        return $day;
    }

    private function optionalInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    private function filterQuery(string $month, ?int $projectId, ?int $taskId): string
    {
        $params = ['month' => $month];

        if ($projectId !== null) {
            $params['project_id'] = (string) $projectId;
        }

        if ($taskId !== null) {
            $params['task_id'] = (string) $taskId;
        }

        return http_build_query($params);
    }
}
