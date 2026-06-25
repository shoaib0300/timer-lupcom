<?php

declare(strict_types=1);

namespace Timer\Repositories;

use DateTimeImmutable;
use PDO;
use Timer\Models\TimeEntry;

final class TimeEntryRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public function findRunning(): ?TimeEntry
    {
        $stmt = $this->pdo->query(
            'SELECT te.*, p.name AS project_name, p.color AS project_color, t.name AS task_name
            FROM time_entries te
            JOIN projects p ON p.id = te.project_id
            LEFT JOIN tasks t ON t.id = te.task_id
            WHERE te.ended_at IS NULL
            ORDER BY te.started_at DESC
            LIMIT 1',
        );
        $row = $stmt ? $stmt->fetch() : false;

        return $row ? TimeEntry::fromRow($row) : null;
    }

    public function start(int $projectId, ?int $taskId): int
    {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO time_entries (project_id, task_id, started_at) VALUES (?, ?, ?)',
        );
        $stmt->execute([$projectId, $taskId, $now]);

        return (int) $this->pdo->lastInsertId();
    }

    public function stop(int $entryId): ?TimeEntry
    {
        $entry = $this->findById($entryId);

        if ($entry === null || !$entry->isRunning()) {
            return null;
        }

        $endedAt = new DateTimeImmutable();
        $startedAt = new DateTimeImmutable($entry->startedAt);
        $duration = max(0, $endedAt->getTimestamp() - $startedAt->getTimestamp());

        $stmt = $this->pdo->prepare(
            'UPDATE time_entries SET ended_at = ?, duration_seconds = ? WHERE id = ?',
        );
        $stmt->execute([$endedAt->format('Y-m-d H:i:s'), $duration, $entryId]);

        return $this->findById($entryId);
    }

    public function stopRunning(): ?TimeEntry
    {
        $running = $this->findRunning();

        return $running ? $this->stop($running->id) : null;
    }

    public function findById(int $id): ?TimeEntry
    {
        $stmt = $this->pdo->prepare(
            'SELECT te.*, p.name AS project_name, p.color AS project_color, t.name AS task_name
            FROM time_entries te
            JOIN projects p ON p.id = te.project_id
            LEFT JOIN tasks t ON t.id = te.task_id
            WHERE te.id = ?',
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ? TimeEntry::fromRow($row) : null;
    }

    /** @return list<TimeEntry> */
    public function recent(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT te.*, p.name AS project_name, p.color AS project_color, t.name AS task_name
            FROM time_entries te
            JOIN projects p ON p.id = te.project_id
            LEFT JOIN tasks t ON t.id = te.task_id
            WHERE te.ended_at IS NOT NULL
            ORDER BY te.ended_at DESC
            LIMIT ?',
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            TimeEntry::fromRow(...),
            $stmt->fetchAll(),
        );
    }

    public function totalSecondsToday(): int
    {
        $stmt = $this->pdo->query(
            "SELECT COALESCE(SUM(duration_seconds), 0)
            FROM time_entries
            WHERE ended_at IS NOT NULL
              AND DATE(started_at) = CURDATE()",
        );

        return (int) ($stmt ? $stmt->fetchColumn() : 0);
    }
}
