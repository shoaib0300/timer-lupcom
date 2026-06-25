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

    /** @return list<TimeEntry> */
    public function findAllRunning(): array
    {
        $stmt = $this->pdo->query(
            'SELECT te.*, p.name AS project_name, p.color AS project_color, t.name AS task_name
            FROM time_entries te
            JOIN projects p ON p.id = te.project_id
            LEFT JOIN tasks t ON t.id = te.task_id
            WHERE te.ended_at IS NULL
            ORDER BY te.started_at ASC',
        );

        return array_map(
            TimeEntry::fromRow(...),
            $stmt ? $stmt->fetchAll() : [],
        );
    }

    public function findRunning(): ?TimeEntry
    {
        $all = $this->findAllRunning();

        return $all[0] ?? null;
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
        $duration = $entry->elapsedSeconds();

        $stmt = $this->pdo->prepare(
            'UPDATE time_entries SET ended_at = ?, duration_seconds = ?, paused_at = NULL WHERE id = ?',
        );
        $stmt->execute([$endedAt->format('Y-m-d H:i:s'), $duration, $entryId]);

        return $this->findById($entryId);
    }

    public function pause(int $entryId): ?TimeEntry
    {
        $entry = $this->findById($entryId);

        if ($entry === null || !$entry->isRunning() || $entry->isPaused()) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE time_entries SET elapsed_offset = ?, paused_at = ? WHERE id = ?',
        );
        $stmt->execute([
            $entry->elapsedSeconds(),
            (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            $entryId,
        ]);

        return $this->findById($entryId);
    }

    public function resume(int $entryId): ?TimeEntry
    {
        $entry = $this->findById($entryId);

        if ($entry === null || !$entry->isRunning() || !$entry->isPaused()) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE time_entries SET started_at = ?, paused_at = NULL WHERE id = ?',
        );
        $stmt->execute([(new DateTimeImmutable())->format('Y-m-d H:i:s'), $entryId]);

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

    /**
     * @return array<string, int> date (Y-m-d) => total seconds
     */
    public function dailyTotals(
        string $from,
        string $to,
        ?int $projectId = null,
        ?int $taskId = null,
    ): array {
        $sql = 'SELECT DATE(started_at) AS work_date, COALESCE(SUM(duration_seconds), 0) AS total_seconds
            FROM time_entries
            WHERE ended_at IS NOT NULL
              AND DATE(started_at) BETWEEN ? AND ?';
        $params = [$from, $to];

        if ($projectId !== null) {
            $sql .= ' AND project_id = ?';
            $params[] = $projectId;
        }

        if ($taskId !== null) {
            $sql .= ' AND task_id = ?';
            $params[] = $taskId;
        }

        $sql .= ' GROUP BY DATE(started_at)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $totals = [];
        foreach ($stmt->fetchAll() as $row) {
            $totals[(string) $row['work_date']] = (int) $row['total_seconds'];
        }

        return $totals;
    }

    /** @return list<TimeEntry> */
    public function forDate(
        string $date,
        ?int $projectId = null,
        ?int $taskId = null,
        int $limit = 100,
    ): array {
        $sql = 'SELECT te.*, p.name AS project_name, p.color AS project_color, t.name AS task_name
            FROM time_entries te
            JOIN projects p ON p.id = te.project_id
            LEFT JOIN tasks t ON t.id = te.task_id
            WHERE te.ended_at IS NOT NULL
              AND DATE(te.started_at) = ?';
        $params = [$date];

        if ($projectId !== null) {
            $sql .= ' AND te.project_id = ?';
            $params[] = $projectId;
        }

        if ($taskId !== null) {
            $sql .= ' AND te.task_id = ?';
            $params[] = $taskId;
        }

        $sql .= ' ORDER BY te.started_at DESC LIMIT ?';

        $stmt = $this->pdo->prepare($sql);
        $bindIndex = 1;
        $stmt->bindValue($bindIndex++, $date);
        if ($projectId !== null) {
            $stmt->bindValue($bindIndex++, $projectId, PDO::PARAM_INT);
        }
        if ($taskId !== null) {
            $stmt->bindValue($bindIndex++, $taskId, PDO::PARAM_INT);
        }
        $stmt->bindValue($bindIndex, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            TimeEntry::fromRow(...),
            $stmt->fetchAll(),
        );
    }

    public function totalSecondsInRange(
        string $from,
        string $to,
        ?int $projectId = null,
        ?int $taskId = null,
    ): int {
        $sql = 'SELECT COALESCE(SUM(duration_seconds), 0)
            FROM time_entries
            WHERE ended_at IS NOT NULL
              AND DATE(started_at) BETWEEN ? AND ?';
        $params = [$from, $to];

        if ($projectId !== null) {
            $sql .= ' AND project_id = ?';
            $params[] = $projectId;
        }

        if ($taskId !== null) {
            $sql .= ' AND task_id = ?';
            $params[] = $taskId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }
}
