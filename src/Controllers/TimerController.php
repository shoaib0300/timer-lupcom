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
        return $this->json($this->timerService()->getStatus());
    }

    public function start(Request $request): Response
    {
        $taskId = (int) $request->input('task_id', 0);
        $service = $this->timerService();

        try {
            if ($taskId > 0) {
                $alreadyRunning = $service->isTaskRunning($taskId);
                $entry = $service->startByTaskId($taskId);
            } else {
                $projectId = (int) $request->input('project_id', 0);
                $taskName = trim((string) $request->input('task_name', 'no-work'));

                if ($projectId <= 0) {
                    return $this->json(['error' => 'Project is required.'], 422);
                }

                $project = new ProjectRepository($this->app->db())->find($projectId);

                if ($project === null) {
                    return $this->json(['error' => 'Project not found.'], 404);
                }

                $taskRepo = new TaskRepository($this->app->db());
                $resolvedTaskId = $taskRepo->findOrCreateByName(
                    $projectId,
                    $taskName !== '' ? $taskName : 'no-work',
                );
                $alreadyRunning = $service->isTaskRunning($resolvedTaskId);
                $entry = $service->start($projectId, $taskName);
            }
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], 404);
        }

        $status = $service->getStatus();
        $timer = $this->findTimerInStatus($status, $entry->id);

        return $this->json([
            'message' => ($alreadyRunning ?? false)
                ? $this->trans('timer.already_running')
                : 'Timer started.',
            'already_running' => $alreadyRunning ?? false,
            'entry' => $timer,
            'status' => $status,
        ]);
    }

    public function stop(Request $request): Response
    {
        $entryId = (int) $request->input('entry_id', 0);

        if ($entryId <= 0) {
            return $this->json(['error' => 'Timer entry is required.'], 422);
        }

        $service = $this->timerService();
        $entry = $service->stop($entryId);

        if ($entry === null) {
            return $this->json(['error' => 'Timer not found or already stopped.'], 422);
        }

        return $this->json(array_merge(
            ['message' => 'Timer stopped.', 'status' => $service->getStatus()],
            $this->stoppedEntryPayload($entry),
        ));
    }

    public function pause(Request $request): Response
    {
        $entryId = (int) $request->input('entry_id', 0);

        if ($entryId <= 0) {
            return $this->json(['error' => 'Timer entry is required.'], 422);
        }

        $service = $this->timerService();
        $entry = $service->pause($entryId);

        if ($entry === null) {
            return $this->json(['error' => 'Timer not found or already paused.'], 422);
        }

        return $this->json([
            'message' => 'Timer paused.',
            'status' => $service->getStatus(),
        ]);
    }

    public function resume(Request $request): Response
    {
        $entryId = (int) $request->input('entry_id', 0);

        if ($entryId <= 0) {
            return $this->json(['error' => 'Timer entry is required.'], 422);
        }

        $service = $this->timerService();
        $entry = $service->resume($entryId);

        if ($entry === null) {
            return $this->json(['error' => 'Timer not found or not paused.'], 422);
        }

        return $this->json([
            'message' => 'Timer resumed.',
            'status' => $service->getStatus(),
        ]);
    }

    /** @return array<string, mixed> */
    private function stoppedEntryPayload(\Timer\Models\TimeEntry $entry): array
    {
        $projectRepo = new ProjectRepository($this->app->db());
        $timeEntries = new TimeEntryRepository($this->app->db());
        $project = $entry->projectId !== null
            ? $projectRepo->find($entry->projectId)
            : null;
        $totalToday = $timeEntries->totalSecondsToday();

        return [
            'entry' => [
                'id' => $entry->id,
                'project_id' => $entry->projectId,
                'project_name' => $entry->projectName,
                'project_color' => $entry->projectColor,
                'task_name' => $entry->taskName,
                'reason' => $entry->notes,
                'is_general' => $entry->isGeneral(),
                'duration_seconds' => $entry->durationSeconds,
                'duration_human' => TimeFormatter::secondsToHuman((int) $entry->durationSeconds),
                'ended_at' => $entry->endedAt,
            ],
            'project_total_seconds' => $project?->totalSeconds ?? 0,
            'project_total_human' => TimeFormatter::secondsToHuman($project?->totalSeconds ?? 0),
            'total_today_seconds' => $totalToday,
            'total_today_human' => TimeFormatter::secondsToHuman($totalToday),
        ];
    }

    private function timerService(): TimerService
    {
        $db = $this->app->db();

        return new TimerService(
            new TimeEntryRepository($db),
            new TaskRepository($db),
        );
    }

    /** @param array{timers: list<array<string, mixed>>} $status */
    private function findTimerInStatus(array $status, int $entryId): ?array
    {
        foreach ($status['timers'] as $timer) {
            if ($timer['id'] === $entryId) {
                return $timer;
            }
        }

        return null;
    }
}
