<?php

declare(strict_types=1);

namespace Timer\Models;

final readonly class Project
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $description,
        public string $color,
        public string $createdAt,
        public string $updatedAt,
        public int $totalSeconds = 0,
        public int $taskCount = 0,
    ) {
    }

  /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            $row['name'],
            $row['description'] ?? null,
            $row['color'],
            $row['created_at'],
            $row['updated_at'],
            (int) ($row['total_seconds'] ?? 0),
            (int) ($row['task_count'] ?? 0),
        );
    }
}
