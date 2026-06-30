<?php

declare(strict_types=1);

namespace Timer\Models;

use DateTimeImmutable;

final readonly class OfficeSession
{
    public function __construct(
        public int $id,
        public string $workDate,
        public string $startedAt,
        public ?string $endedAt,
        public ?int $durationSeconds,
        public int $elapsedOffset,
        public ?string $pausedAt,
        public ?int $unassignedSeconds,
        public ?int $gapEntryId,
        public string $createdAt,
        public string $updatedAt,
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

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            $row['work_date'],
            $row['started_at'],
            $row['ended_at'] ?? null,
            isset($row['duration_seconds']) ? (int) $row['duration_seconds'] : null,
            (int) ($row['elapsed_offset'] ?? 0),
            $row['paused_at'] ?? null,
            isset($row['unassigned_seconds']) ? (int) $row['unassigned_seconds'] : null,
            isset($row['gap_entry_id']) ? (int) $row['gap_entry_id'] : null,
            $row['created_at'],
            $row['updated_at'],
        );
    }
}
