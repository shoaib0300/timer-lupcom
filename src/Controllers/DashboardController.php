<?php

declare(strict_types=1);

namespace Timer\Controllers;

use Timer\Http\Request;
use Timer\Http\Response;
use Timer\Repositories\ProjectRepository;
use Timer\Repositories\TaskRepository;
use Timer\Repositories\TimeEntryRepository;
use Timer\Support\ProjectSorter;
use Timer\Support\TimeFormatter;

final class DashboardController extends BaseController
{
    public function index(Request $request): Response
    {
        $projects = new ProjectRepository($this->app->db())->allWithStats();
        $timeEntries = new TimeEntryRepository($this->app->db());
        $timerService = new \Timer\Services\TimerService(
            $timeEntries,
            new TaskRepository($this->app->db()),
        );

        $timerStatus = $timerService->getStatus();
        $runningProjectIds = array_map(
            static fn (array $timer): int => (int) $timer['project_id'],
            $timerStatus['timers'],
        );
        $projects = ProjectSorter::forDashboard($projects, $runningProjectIds);

        $totalTodaySeconds = $timeEntries->totalSecondsToday();

        return $this->view('dashboard/index.html.twig', [
            'projects' => $projects,
            'projects_visible_limit' => ProjectSorter::visibleLimit(),
            'projects_show_increment' => ProjectSorter::showIncrement(),
            'recent_entries' => $timeEntries->recentToday(),
            'total_today' => TimeFormatter::secondsToHuman($totalTodaySeconds),
            'total_today_seconds' => $totalTodaySeconds,
            'timer' => $timerStatus,
        ]);
    }
}
