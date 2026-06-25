<?php

declare(strict_types=1);

namespace Timer\Controllers;

use Timer\Http\Request;
use Timer\Http\Response;
use Timer\Repositories\ProjectRepository;
use Timer\Repositories\TaskRepository;
use Timer\Repositories\TimeEntryRepository;
use Timer\Services\TimerService;
use Timer\Support\TimeFormatter;

final class TimerController extends BaseController
{
    public function status(Request $request): Response
    {
        $service = $this->timerService();

        return $this->json($service->getStatus());
    }

    public function start(Request $request): Response
    {
        $projectId = (int) $request->input('project_id', 0);
        $taskId = $request->input('task_id');
        $taskId = $taskId !== null && $taskId !== '' ? (int) $taskId : null;

        if ($projectId <= 0) {
            return $this->json(['error' => 'Project is required.'], 422);
        }

        $project = new ProjectRepository($this->app->db())->find($projectId);

        if ($project === null) {
            return $this->json(['error' => 'Project not found.'], 404);
        }

        if ($taskId !== null) {
            $task = new TaskRepository($this->app->db())->find($taskId);

            if ($task === null || $task->projectId !== $projectId) {
                return $this->json(['error' => 'Task not found for this project.'], 404);
            }
        }

        $service = $this->timerService();
        $entry = $service->start($projectId, $taskId);
        $status = $service->getStatus();

        return $this->json([
            'message' => 'Timer started.',
            'status' => $status,
            'duration_human' => TimeFormatter::secondsToHuman($status['elapsed_seconds']),
        ]);
    }

    public function stop(Request $request): Response
    {
        $service = $this->timerService();
        $entry = $service->stop();

        if ($entry === null) {
            return $this->json(['error' => 'No running timer.'], 422);
        }

        return $this->json([
            'message' => 'Timer stopped.',
            'entry' => [
                'id' => $entry->id,
                'project_name' => $entry->projectName,
                'task_name' => $entry->taskName,
                'duration_seconds' => $entry->durationSeconds,
                'duration_human' => TimeFormatter::secondsToHuman((int) $entry->durationSeconds),
            ],
            'status' => $service->getStatus(),
        ]);
    }

    private function timerService(): TimerService
    {
        return new TimerService(new TimeEntryRepository($this->app->db()));
    }
}
