<?php

declare(strict_types=1);

namespace Timer\Repositories;

use DateTimeImmutable;
use PDO;
use Timer\Models\OfficeSession;

final class OfficeSessionRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $userId,
    ) {
    }

    public function findRunning(): ?OfficeSession
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM office_sessions WHERE ended_at IS NULL AND user_id = ? ORDER BY started_at DESC LIMIT 1',
        );
        $stmt->execute([$this->userId]);
        $row = $stmt->fetch();

        return $row ? OfficeSession::fromRow($row) : null;
    }

    public function findById(int $id): ?OfficeSession
    {
        $stmt = $this->pdo->prepare('SELECT * FROM office_sessions WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $this->userId]);
        $row = $stmt->fetch();

        return $row ? OfficeSession::fromRow($row) : null;
    }

    public function start(): int
    {
        $now = new DateTimeImmutable();
        $stmt = $this->pdo->prepare(
            'INSERT INTO office_sessions (user_id, work_date, started_at) VALUES (?, ?, ?)',
        );
        $stmt->execute([
            $this->userId,
            $now->format('Y-m-d'),
            $now->format('Y-m-d H:i:s'),
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function pause(int $sessionId): ?OfficeSession
    {
        $session = $this->findById($sessionId);

        if ($session === null || !$session->isRunning() || $session->isPaused()) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE office_sessions SET elapsed_offset = ?, paused_at = ? WHERE id = ? AND user_id = ?',
        );
        $stmt->execute([
            $session->elapsedSeconds(),
            (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            $sessionId,
            $this->userId,
        ]);

        return $this->findById($sessionId);
    }

    public function resume(int $sessionId): ?OfficeSession
    {
        $session = $this->findById($sessionId);

        if ($session === null || !$session->isRunning() || !$session->isPaused()) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            'UPDATE office_sessions SET started_at = ?, paused_at = NULL WHERE id = ? AND user_id = ?',
        );
        $stmt->execute([
            (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            $sessionId,
            $this->userId,
        ]);

        return $this->findById($sessionId);
    }

    public function stop(
        int $sessionId,
        int $durationSeconds,
        int $unassignedSeconds,
        ?int $gapEntryId,
    ): ?OfficeSession {
        $session = $this->findById($sessionId);

        if ($session === null || !$session->isRunning()) {
            return null;
        }

        $endedAt = new DateTimeImmutable();
        $stmt = $this->pdo->prepare(
            'UPDATE office_sessions
            SET ended_at = ?, duration_seconds = ?, paused_at = NULL,
                unassigned_seconds = ?, gap_entry_id = ?
            WHERE id = ? AND user_id = ?',
        );
        $stmt->execute([
            $endedAt->format('Y-m-d H:i:s'),
            $durationSeconds,
            $unassignedSeconds > 0 ? $unassignedSeconds : null,
            $gapEntryId,
            $sessionId,
            $this->userId,
        ]);

        return $this->findById($sessionId);
    }

    public function totalSecondsToday(): int
    {
        $running = $this->findRunning();
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(duration_seconds), 0)
            FROM office_sessions
            WHERE ended_at IS NOT NULL AND work_date = CURDATE() AND user_id = ?",
        );
        $stmt->execute([$this->userId]);
        $completed = (int) $stmt->fetchColumn();

        return $completed + ($running?->elapsedSeconds() ?? 0);
    }

    public function totalUnassignedToday(): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(unassigned_seconds), 0)
            FROM office_sessions
            WHERE work_date = CURDATE() AND user_id = ?",
        );
        $stmt->execute([$this->userId]);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<OfficeSession> */
    public function forDateRange(string $from, string $to, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM office_sessions
            WHERE work_date BETWEEN ? AND ? AND user_id = ?
            ORDER BY started_at DESC
            LIMIT ?',
        );
        $stmt->execute([$from, $to, $this->userId, $limit]);

        return array_map(
            OfficeSession::fromRow(...),
            $stmt->fetchAll(),
        );
    }

    /** @return array<string, int> */
    public function dailyTotals(string $from, string $to): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT work_date, COALESCE(SUM(duration_seconds), 0) AS total_seconds
            FROM office_sessions
            WHERE ended_at IS NOT NULL AND work_date BETWEEN ? AND ? AND user_id = ?
            GROUP BY work_date',
        );
        $stmt->execute([$from, $to, $this->userId]);

        $totals = [];
        foreach ($stmt->fetchAll() as $row) {
            $totals[(string) $row['work_date']] = (int) $row['total_seconds'];
        }

        return $totals;
    }
}
