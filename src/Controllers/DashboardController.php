<?php

declare(strict_types=1);

namespace Timer\Controllers;

use Timer\Http\Request;
use Timer\Http\Response;
use Timer\Repositories\ProjectRepository;
use Timer\Repositories\TaskRepository;
use Timer\Repositories\TimeEntryRepository;
use Timer\Support\TimeFormatter;

final class DashboardController extends BaseController
{
    public function index(Request $request): Response
    {
        $projects = new ProjectRepository($this->app->db())->allWithStats();
        $timeEntries = new TimeEntryRepository($this->app->db());
        $tasks = new TaskRepository($this->app->db());

        $tasksByProject = [];
        foreach ($projects as $project) {
            $tasksByProject[$project->id] = $tasks->forProject($project->id);
        }

        $timerStatus = (new \Timer\Services\TimerService($timeEntries))->getStatus();

        return $this->view('dashboard/index.html.twig', [
            'projects' => $projects,
            'tasks_by_project' => $tasksByProject,
            'recent_entries' => $timeEntries->recent(),
            'total_today' => TimeFormatter::secondsToHuman($timeEntries->totalSecondsToday()),
            'timer' => $timerStatus,
            'format_time' => [TimeFormatter::class, 'secondsToHuman'],
            'format_clock' => [TimeFormatter::class, 'secondsToClock'],
        ]);
    }
}
