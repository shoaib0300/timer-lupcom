<?php

declare(strict_types=1);

namespace Timer\Models;

final readonly class Task
{
    public function __construct(
        public int $id,
        public int $projectId,
        public string $name,
        public ?string $description,
        public string $status,
        public string $createdAt,
        public string $updatedAt,
        public int $totalSeconds = 0,
        public ?string $projectName = null,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['project_id'],
            $row['name'],
            $row['description'] ?? null,
            $row['status'],
            $row['created_at'],
            $row['updated_at'],
            (int) ($row['total_seconds'] ?? 0),
            $row['project_name'] ?? null,
        );
    }
}
