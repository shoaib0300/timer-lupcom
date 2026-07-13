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
        public ?int $planioIssueId = null,
        public ?string $planioAssignee = null,
        public int $totalSeconds = 0,
        public ?string $projectName = null,
        public ?string $projectColor = null,
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
            isset($row['planio_issue_id']) && $row['planio_issue_id'] !== null
                ? (int) $row['planio_issue_id']
                : null,
            isset($row['planio_assignee']) && $row['planio_assignee'] !== null && $row['planio_assignee'] !== ''
                ? (string) $row['planio_assignee']
                : null,
            (int) ($row['total_seconds'] ?? 0),
            $row['project_name'] ?? null,
            isset($row['project_color']) && $row['project_color'] !== ''
                ? (string) $row['project_color']
                : null,
        );
    }
}
