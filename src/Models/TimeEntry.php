<?php

declare(strict_types=1);

namespace Timer\Models;

final readonly class TimeEntry
{
    public function __construct(
        public int $id,
        public int $projectId,
        public ?int $taskId,
        public string $startedAt,
        public ?string $endedAt,
        public ?int $durationSeconds,
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

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['project_id'],
            isset($row['task_id']) ? (int) $row['task_id'] : null,
            $row['started_at'],
            $row['ended_at'] ?? null,
            isset($row['duration_seconds']) ? (int) $row['duration_seconds'] : null,
            $row['notes'] ?? null,
            $row['created_at'],
            $row['updated_at'],
            $row['project_name'] ?? null,
            $row['task_name'] ?? null,
            $row['project_color'] ?? null,
        );
    }
}
