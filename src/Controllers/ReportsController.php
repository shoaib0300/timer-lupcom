<?php

declare(strict_types=1);

namespace Timer\Controllers;

use DateTimeImmutable;
use Timer\Http\Request;
use Timer\Http\Response;
use Timer\Repositories\ProjectRepository;
use Timer\Repositories\TaskRepository;
use Timer\Repositories\TimeEntryRepository;
use Timer\Support\CalendarGrid;
use Timer\Support\Locale;
use Timer\Support\TimeFormatter;

final class ReportsController extends BaseController
{
    public function index(Request $request): Response
    {
        $month = $this->resolveMonth((string) $request->query('month', ''));
        $projectId = $this->optionalInt($request->query('project_id'));
        $taskId = $this->optionalInt($request->query('task_id'));
        $selectedDay = $this->resolveDay((string) $request->query('day', ''));

        if ($selectedDay !== null && substr($selectedDay, 0, 7) !== $month) {
            return $this->redirect(
                '/reports?' . $this->filterQuery(substr($selectedDay, 0, 7), $projectId, $taskId)
                . '&day=' . $selectedDay,
            );
        }

        if ($taskId !== null && $projectId === null) {
            $taskId = null;
        }

        if ($taskId !== null) {
            $task = new TaskRepository($this->app->db())->find($taskId);
            if ($task === null || $task->projectId !== $projectId) {
                $taskId = null;
            }
        }

        $firstDay = new DateTimeImmutable($month . '-01');
        $lastDay = $firstDay->modify('last day of this month');
        $from = $firstDay->format('Y-m-d');
        $to = $lastDay->format('Y-m-d');

        $timeEntries = new TimeEntryRepository($this->app->db());
        $dailyTotals = $timeEntries->dailyTotals($from, $to, $projectId, $taskId);
        $monthTotalSeconds = $timeEntries->totalSecondsInRange($from, $to, $projectId, $taskId);
        $calendarCells = CalendarGrid::build($month, $dailyTotals);

        $dayEntries = [];
        $dayTotalSeconds = 0;
        if ($selectedDay !== null) {
            $dayEntries = $timeEntries->forDate($selectedDay, $projectId, $taskId);
            $dayTotalSeconds = array_sum(
                array_map(static fn ($entry) => $entry->durationSeconds ?? 0, $dayEntries),
            );
        }

        $projects = new ProjectRepository($this->app->db())->allWithStats();
        $tasks = $projectId !== null
            ? new TaskRepository($this->app->db())->forProject($projectId)
            : [];

        $prevMonth = $firstDay->modify('-1 month')->format('Y-m');
        $nextMonth = $firstDay->modify('+1 month')->format('Y-m');

        $locale = $this->app->translator()->locale();

        return $this->view('reports/index.html.twig', [
            'month' => $month,
            'month_label' => Locale::formatMonth($firstDay, $locale),
            'prev_month_query' => $this->filterQuery($prevMonth, $projectId, $taskId),
            'next_month_query' => $this->filterQuery($nextMonth, $projectId, $taskId),
            'project_id' => $projectId,
            'task_id' => $taskId,
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
            'filter_query' => $this->filterQuery($month, $projectId, $taskId),
        ]);
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
