<?php

declare(strict_types=1);

namespace Timer\Services;

use Timer\Models\TimeEntry;
use Timer\Repositories\TaskRepository;
use Timer\Repositories\TimeEntryRepository;

final class TimerService
{
    public function __construct(
        private readonly TimeEntryRepository $timeEntries,
        private readonly TaskRepository $tasks,
    ) {
    }

    /** @return array{timers: list<array<string, mixed>>, running: bool} */
    public function getStatus(): array
    {
        $running = $this->timeEntries->findAllRunning();
        $timers = array_map($this->formatRunningEntry(...), $running);

        return [
            'timers' => $timers,
            'running' => $timers !== [],
        ];
    }

    public function start(int $projectId, string $taskName): TimeEntry
    {
        $taskName = trim($taskName) !== '' ? trim($taskName) : 'no-work';
        $taskId = $this->tasks->findOrCreateByName($projectId, $taskName);

        return $this->startWithTaskId($projectId, $taskId);
    }

    public function startByTaskId(int $taskId): TimeEntry
    {
        $task = $this->tasks->find($taskId);

        if ($task === null) {
            throw new \InvalidArgumentException('Task not found.');
        }

        return $this->startWithTaskId($task->projectId, $taskId);
    }

    private function startWithTaskId(int $projectId, int $taskId): TimeEntry
    {
        $existing = $this->timeEntries->findRunningByTaskId($taskId);

        if ($existing !== null) {
            return $existing;
        }

        $entryId = $this->timeEntries->start($projectId, $taskId);
        $entry = $this->timeEntries->findById($entryId);

        if ($entry === null) {
            throw new \RuntimeException('Failed to start timer.');
        }

        return $entry;
    }

    public function isTaskRunning(int $taskId): bool
    {
        return $this->timeEntries->findRunningByTaskId($taskId) !== null;
    }

    public function stop(int $entryId): ?TimeEntry
    {
        return $this->timeEntries->stop($entryId);
    }

    public function pause(int $entryId): ?TimeEntry
    {
        return $this->timeEntries->pause($entryId);
    }

    public function resume(int $entryId): ?TimeEntry
    {
        return $this->timeEntries->resume($entryId);
    }

    /** @return array<string, mixed> */
    private function formatRunningEntry(TimeEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'project_id' => $entry->projectId,
            'project_name' => $entry->projectName,
            'project_color' => $entry->projectColor,
            'task_id' => $entry->taskId,
            'task_name' => $entry->taskName,
            'started_at' => $entry->startedAt,
            'elapsed_seconds' => $entry->elapsedSeconds(),
            'is_paused' => $entry->isPaused(),
        ];
    }
}
