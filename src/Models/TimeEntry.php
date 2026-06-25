<?php

declare(strict_types=1);

namespace Timer\Models;

use DateTimeImmutable;

final readonly class TimeEntry
{
    public function __construct(
        public int $id,
        public ?int $projectId,
        public ?int $taskId,
        public string $startedAt,
        public ?string $endedAt,
        public ?int $durationSeconds,
        public int $elapsedOffset,
        public ?string $pausedAt,
        public ?string $notes,
        public string $createdAt,
        public string $updatedAt,
        public ?string $projectName = null,
        public ?string $taskName = null,
        public ?string $projectColor = null,
    ) {
    }

    public function isRunning(): bool
    {
        return $this->endedAt === null;
    }

    public function isPaused(): bool
    {
        return $this->pausedAt !== null;
    }

    public function elapsedSeconds(): int
    {
        if ($this->endedAt !== null && $this->durationSeconds !== null) {
            return $this->durationSeconds;
        }

        if ($this->isPaused()) {
            return $this->elapsedOffset;
        }

        $startedAt = new DateTimeImmutable($this->startedAt);

        return $this->elapsedOffset + max(
            0,
            (new DateTimeImmutable())->getTimestamp() - $startedAt->getTimestamp(),
        );
    }

    public function isGeneral(): bool
    {
        return $this->projectId === null;
    }

    public function displayLabel(): string
    {
        if ($this->isGeneral()) {
            return $this->notes ?? 'General time';
        }

        return $this->taskName ?? '—';
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            isset($row['project_id']) && $row['project_id'] !== null
                ? (int) $row['project_id']
                : null,
            isset($row['task_id']) ? (int) $row['task_id'] : null,
            $row['started_at'],
            $row['ended_at'] ?? null,
            isset($row['duration_seconds']) ? (int) $row['duration_seconds'] : null,
            (int) ($row['elapsed_offset'] ?? 0),
            $row['paused_at'] ?? null,
            $row['notes'] ?? null,
            $row['created_at'],
            $row['updated_at'],
            $row['project_name'] ?? null,
            $row['task_name'] ?? null,
            $row['project_color'] ?? null,
        );
    }
}
