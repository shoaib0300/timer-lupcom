<?php

declare(strict_types=1);

namespace Timer\Services;

use DateTimeImmutable;
use Timer\Models\TimeEntry;
use Timer\Repositories\TimeEntryRepository;

final class TimerService
{
    public function __construct(
        private readonly TimeEntryRepository $timeEntries,
    ) {
    }

    public function getStatus(): array
    {
        $running = $this->timeEntries->findRunning();

        if ($running === null) {
            return [
                'running' => false,
                'entry' => null,
                'elapsed_seconds' => 0,
            ];
        }

        $startedAt = new DateTimeImmutable($running->startedAt);
        $elapsed = max(0, (new DateTimeImmutable())->getTimestamp() - $startedAt->getTimestamp());

        return [
            'running' => true,
            'entry' => [
                'id' => $running->id,
                'project_id' => $running->projectId,
                'project_name' => $running->projectName,
                'project_color' => $running->projectColor,
                'task_id' => $running->taskId,
                'task_name' => $running->taskName,
                'started_at' => $running->startedAt,
            ],
            'elapsed_seconds' => $elapsed,
        ];
    }

    public function start(int $projectId, ?int $taskId): TimeEntry
    {
        $this->timeEntries->stopRunning();

        $entryId = $this->timeEntries->start($projectId, $taskId);
        $entry = $this->timeEntries->findById($entryId);

        if ($entry === null) {
            throw new \RuntimeException('Failed to start timer.');
        }

        return $entry;
    }

    public function stop(): ?TimeEntry
    {
        return $this->timeEntries->stopRunning();
    }
}
