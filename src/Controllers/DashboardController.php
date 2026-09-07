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
        $attendance = $this->attendanceService();
        $workingHours = $attendance->config();
        $month = (new \DateTimeImmutable('today'))->format('Y-m');
        $monthSummary = $attendance->monthSummary($month);
        $weekdayLabels = [
            1 => $this->trans('attendance.weekday.mon'),
            2 => $this->trans('attendance.weekday.tue'),
            3 => $this->trans('attendance.weekday.wed'),
            4 => $this->trans('attendance.weekday.thu'),
            5 => $this->trans('attendance.weekday.fri'),
            6 => $this->trans('attendance.weekday.sat'),
            7 => $this->trans('attendance.weekday.sun'),
        ];
        $workingDayLabels = array_map(
            static fn (int $d): string => $weekdayLabels[$d] ?? (string) $d,
            $workingHours['working_weekdays'],
        );

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
            'working_time' => [
                'weekly_label' => $workingHours['weekly_label'],
                'daily_label' => $workingHours['daily_label'],
                'working_days_label' => $workingDayLabels !== []
                    ? implode(' – ', [reset($workingDayLabels), end($workingDayLabels)])
                    : '—',
                'soll_month_label' => $monthSummary['soll_label'],
                'ist_month_label' => $monthSummary['ist_label'],
                'diff_month_label' => $monthSummary['diff_label'],
                'diff_month_minutes' => $monthSummary['diff_minutes'],
            ],
        ]);
    }
}
