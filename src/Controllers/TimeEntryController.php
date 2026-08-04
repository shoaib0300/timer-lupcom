<?php

declare(strict_types=1);

namespace Timer\Controllers;

use DateTimeImmutable;
use Timer\Http\Request;
use Timer\Http\Response;
use Timer\Support\DateHelper;
use Timer\Support\TimeFormatter;

final class TimeEntryController extends BaseController
{
    public function storeManual(Request $request): Response
    {
        if ($response = $this->validateCsrf($request)) {
            return $response;
        }

        $notes = trim((string) $request->input('reason', ''));
        $hours = max(0, (int) $request->input('duration_hours', 0));
        $minutes = max(0, min(59, (int) $request->input('duration_minutes', 0)));
        $workDateInput = trim((string) $request->input('work_date', ''));
        $projectId = $this->optionalPositiveInt($request->input('project_id'));
        $taskId = $this->optionalPositiveInt($request->input('task_id'));
        $activityId = $this->optionalPositiveInt($request->input('activity_id'));

        $durationSeconds = ($hours * 3600) + ($minutes * 60);

        if ($durationSeconds <= 0) {
            return $this->json(['error' => 'Enter a duration of at least one minute.'], 422);
        }

        $workDate = DateHelper::parseDateOnly($workDateInput);
        if ($workDate === null) {
            return $this->json(['error' => 'Invalid date.'], 422);
        }

        if (DateHelper::isFutureDate($workDate->format('Y-m-d'))) {
            return $this->json(['error' => 'Date cannot be in the future.'], 422);
        }

        $project = null;
        $task = null;
        if ($projectId === null) {
            if ($notes === '') {
                return $this->json(['error' => 'Reason is required when no project is selected.'], 422);
            }
            $taskId = null;
        } else {
            $project = $this->projects()->find($projectId);
            if ($project === null) {
                return $this->json(['error' => 'Project not found.'], 404);
            }

            if ($taskId !== null) {
                $task = $this->tasks()->find($taskId);
                if ($task === null || $task->projectId !== $projectId) {
                    return $this->json(['error' => 'Task not found for this project.'], 422);
                }
            }
        }

        $repo = $this->timeEntries();

        try {
            $entryId = $repo->createManual(
                $durationSeconds,
                $workDate,
                $projectId,
                $taskId,
                $notes !== '' ? $notes : null,
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], 422);
        }

        $entry = $repo->findById($entryId);

        if ($entry === null) {
            return $this->json(['error' => 'Could not save entry.'], 500);
        }

        $totalToday = $repo->totalSecondsToday();
        $isToday = $workDate->format('Y-m-d') === DateHelper::todayString();

        $planioPushed = false;
        $planioError = null;
        $planioTimeEntryId = null;

        if ($project !== null && $project->planioId !== null && $this->userSettings()->isPlanioConfigured()) {
            if ($activityId === null) {
                $planioError = $this->trans('planio.manual_activity_required');
            } else {
                try {
                    $planioIssueId = $task?->planioIssueId;
                    $hoursFloat = TimeFormatter::secondsToPlanioHours($durationSeconds);
                    $comment = $notes !== '' ? $notes : 'Manual log';

                    $created = $this->planioSync()->clientFromSettings()->createTimeEntry(
                        $planioIssueId,
                        $planioIssueId !== null ? null : $project->planioId,
                        $hoursFloat,
                        $comment,
                        $activityId,
                        $workDate->format('Y-m-d'),
                    );

                    $planioTimeEntryId = (int) ($created['id'] ?? 0);
                    if ($planioTimeEntryId > 0) {
                        $repo->setPlanioTimeEntryId($entry->id, $planioTimeEntryId);
                        $planioPushed = true;
                    } else {
                        $planioError = $this->trans('planio.manual_push_failed');
                    }
                } catch (\Throwable $exception) {
                    $planioError = $exception->getMessage();
                }
            }
        }

        return $this->json([
            'message' => $isToday
                ? 'Time logged for today.'
                : 'Time logged for ' . $workDate->format('j M Y') . '.',
            'entry' => $this->formatEntry($entry),
            'work_date' => $workDate->format('Y-m-d'),
            'work_date_label' => $workDate->format('j M Y'),
            'total_today_seconds' => $totalToday,
            'total_today_human' => TimeFormatter::secondsToHuman($totalToday),
            'is_today' => $isToday,
            'project_total_seconds' => $project?->totalSeconds ?? 0,
            'project_total_human' => TimeFormatter::secondsToHuman($project?->totalSeconds ?? 0),
            'planio_pushed' => $planioPushed,
            'planio_error' => $planioError,
            'planio_time_entry_id' => $planioTimeEntryId,
        ]);
    }

    public function delete(Request $request, int $id): Response
    {
        if ($response = $this->validateCsrf($request)) {
            return $response;
        }

        $repo = $this->timeEntries();
        $entry = $repo->findById($id);
        if ($entry === null || $entry->isRunning()) {
            return $this->json(['error' => 'Time entry not found.'], 404);
        }

        $projectId = $entry->projectId;
        $planioId = $entry->planioTimeEntryId;
        $deleted = $repo->deleteById($entry->id);
        if (!$deleted) {
            return $this->json(['error' => 'Could not delete time entry.'], 500);
        }

        $planioDeleted = false;
        $planioError = null;
        if ($planioId !== null && $planioId > 0 && $this->userSettings()->isPlanioConfigured()) {
            try {
                $this->planioSync()->clientFromSettings()->deleteTimeEntry($planioId);
                $planioDeleted = true;
            } catch (\Throwable $exception) {
                $planioError = $exception->getMessage();
            }
        }

        $totalToday = $repo->totalSecondsToday();
        $project = $projectId !== null ? $this->projects()->find($projectId) : null;

        return $this->json([
            'deleted' => true,
            'entry_id' => $entry->id,
            'planio_deleted' => $planioDeleted,
            'planio_error' => $planioError,
            'total_today_seconds' => $totalToday,
            'total_today_human' => TimeFormatter::secondsToHuman($totalToday),
            'project_total_seconds' => $project?->totalSeconds ?? 0,
            'project_total_human' => TimeFormatter::secondsToHuman($project?->totalSeconds ?? 0),
            'project_id' => $projectId,
        ]);
    }

    private function optionalPositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    /** @return array<string, mixed> */
    private function formatEntry(\Timer\Models\TimeEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'project_id' => $entry->projectId,
            'project_name' => $entry->projectName,
            'project_color' => $entry->projectColor,
            'task_name' => $entry->taskName,
            'reason' => $entry->notes,
            'subject' => $entry->subject(),
            'duration_seconds' => $entry->durationSeconds,
            'duration_human' => TimeFormatter::secondsToHuman((int) $entry->durationSeconds),
            'started_at' => $entry->startedAt,
            'ended_at' => $entry->endedAt,
            'is_general' => $entry->isGeneral(),
            'planio_time_entry_id' => $entry->planioTimeEntryId,
        ];
    }
}
