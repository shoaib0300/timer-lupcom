<?php

declare(strict_types=1);

namespace Timer\Controllers;

use Timer\Http\Request;
use Timer\Http\Response;
use Timer\Support\TimeFormatter;

final class TimerController extends BaseController
{
    public function status(Request $request): Response
    {
        return $this->json($this->timerService()->getStatus());
    }

    public function start(Request $request): Response
    {
        if ($response = $this->validateCsrf($request)) {
            return $response;
        }

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

                $project = $this->projects()->find($projectId);

                if ($project === null) {
                    return $this->json(['error' => 'Project not found.'], 404);
                }

                $resolvedTaskId = $this->tasks()->findOrCreateByName(
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
        if ($response = $this->validateCsrf($request)) {
            return $response;
        }

        $entryId = (int) $request->input('entry_id', 0);

        if ($entryId <= 0) {
            return $this->json(['error' => 'Timer entry is required.'], 422);
        }

        $comment = $this->requestText($request, ['notes', 'comment']);
        $activityName = $this->requestText($request, ['activity_name']);
        $activityId = (int) $this->requestText($request, ['activity_id']);

        if ($comment === '') {
            $comment = 'Timer stopped';
        }

        $notes = $activityName !== ''
            ? '[' . $activityName . '] ' . $comment
            : $comment;

        $service = $this->timerService();
        $entry = $service->stop($entryId, $notes);

        if ($entry === null) {
            return $this->json(['error' => 'Timer not found or already stopped.'], 422);
        }

        $response = [
            'message' => 'Timer stopped.',
            'status' => $service->getStatus(),
            'planio_pushed' => false,
            'planio_error' => null,
            'planio_time_entry_id' => null,
        ];

        $task = $entry->taskId !== null ? $this->tasks()->find((int) $entry->taskId) : null;
        $settings = $this->userSettings();

        if ($task?->planioIssueId && $settings->isPlanioConfigured()) {
            if ($activityId <= 0) {
                $response['planio_error'] = 'Choose an activity to push this booking to Planio.';
            } else {
                try {
                    $pushed = $this->pushStoppedEntryToPlanio($entry, (int) $task->planioIssueId, $comment, $activityId);
                    $response['planio_pushed'] = true;
                    $response['planio_time_entry_id'] = $pushed;
                } catch (\Throwable $exception) {
                    $response['planio_error'] = $exception->getMessage();
                }
            }
        }

        return $this->json(array_merge($response, $this->stoppedEntryPayload($entry)));
    }

    public function pause(Request $request): Response
    {
        if ($response = $this->validateCsrf($request)) {
            return $response;
        }

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
        if ($response = $this->validateCsrf($request)) {
            return $response;
        }

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
        $timeEntries = $this->timeEntries();
        $project = $entry->projectId !== null
            ? $this->projects()->find($entry->projectId)
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
                'subject' => $entry->subject(),
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

    private function pushStoppedEntryToPlanio(
        \Timer\Models\TimeEntry $entry,
        int $planioIssueId,
        string $comment,
        int $activityId,
    ): int {
        $endedAt = $entry->endedAt ?? $entry->startedAt;
        $spentOn = (new \DateTimeImmutable($endedAt))->format('Y-m-d');
        $durationSeconds = (int) ($entry->durationSeconds ?? 0);
        $hours = TimeFormatter::secondsToPlanioHours($durationSeconds);
        if ($hours <= 0) {
            throw new \RuntimeException('Duration is too small to push to Planio.');
        }

        $created = $this->planioSync()->clientFromSettings()->createTimeEntry(
            $planioIssueId,
            $hours,
            $comment,
            $activityId,
            $spentOn,
        );

        $planioTimeEntryId = (int) ($created['id'] ?? 0);
        if ($planioTimeEntryId <= 0) {
            throw new \RuntimeException('Planio did not return a valid time entry id.');
        }

        $this->timeEntries()->setPlanioTimeEntryId($entry->id, $planioTimeEntryId);

        return $planioTimeEntryId;
    }

    /** @param list<string> $keys */
    private function requestText(Request $request, array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string) $request->input($key, ''));
            if ($value !== '') {
                return $value;
            }
        }

        $rawBody = file_get_contents('php://input');
        if (!is_string($rawBody) || $rawBody === '') {
            return '';
        }

        parse_str($rawBody, $rawParsed);
        if (!is_array($rawParsed)) {
            return '';
        }

        foreach ($keys as $key) {
            $value = trim((string) ($rawParsed[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }
}
