<?php

declare(strict_types=1);

namespace Timer\Controllers;

use Timer\Http\Request;
use Timer\Http\Response;
use Timer\Support\DateHelper;
use Timer\Support\ProjectSorter;
use Timer\Support\TimeFormatter;

final class DashboardController extends BaseController
{
    public function index(Request $request): Response
    {
        $projects = $this->projects()->allWithStats();
        $timeEntries = $this->timeEntries();
        $timerService = $this->timerService();

        $timerStatus = $timerService->getStatus();
        $runningProjectIds = array_map(
            static fn (array $timer): int => (int) $timer['project_id'],
            $timerStatus['timers'],
        );
        $projects = ProjectSorter::forDashboard($projects, $runningProjectIds);

        $totalTodaySeconds = $timeEntries->totalSecondsToday();
        $officeStatus = $this->officeService()->getStatusWithStats();

        return $this->view('dashboard/index.html.twig', [
            'projects' => $projects,
            'projects_visible_limit' => ProjectSorter::visibleLimit(),
            'projects_show_increment' => ProjectSorter::showIncrement(),
            'recent_entries' => $timeEntries->recentToday(),
            'total_today' => TimeFormatter::secondsToHuman($totalTodaySeconds),
            'total_today_seconds' => $totalTodaySeconds,
            'office_today' => TimeFormatter::secondsToHuman($officeStatus['office_today_seconds']),
            'office_today_seconds' => $officeStatus['office_today_seconds'],
            'unassigned_today' => TimeFormatter::secondsToHuman($officeStatus['unassigned_today_seconds']),
            'unassigned_today_seconds' => $officeStatus['unassigned_today_seconds'],
            'office' => $officeStatus,
            'today_date' => DateHelper::todayString(),
            'timer' => $timerStatus,
        ]);
    }
}
