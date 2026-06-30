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
    ) {
    }

    public function findRunning(): ?OfficeSession
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM office_sessions WHERE ended_at IS NULL ORDER BY started_at DESC LIMIT 1',
        );
        $row = $stmt ? $stmt->fetch() : false;

        return $row ? OfficeSession::fromRow($row) : null;
    }

    public function findById(int $id): ?OfficeSession
    {
        $stmt = $this->pdo->prepare('SELECT * FROM office_sessions WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ? OfficeSession::fromRow($row) : null;
    }

    public function start(): int
    {
        $now = new DateTimeImmutable();
        $stmt = $this->pdo->prepare(
            'INSERT INTO office_sessions (work_date, started_at) VALUES (?, ?)',
        );
        $stmt->execute([
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
            'UPDATE office_sessions SET elapsed_offset = ?, paused_at = ? WHERE id = ?',
        );
        $stmt->execute([
            $session->elapsedSeconds(),
            (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            $sessionId,
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
            'UPDATE office_sessions SET started_at = ?, paused_at = NULL WHERE id = ?',
        );
        $stmt->execute([(new DateTimeImmutable())->format('Y-m-d H:i:s'), $sessionId]);

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
            WHERE id = ?',
        );
        $stmt->execute([
            $endedAt->format('Y-m-d H:i:s'),
            $durationSeconds,
            $unassignedSeconds > 0 ? $unassignedSeconds : null,
            $gapEntryId,
            $sessionId,
        ]);

        return $this->findById($sessionId);
    }

    public function totalSecondsToday(): int
    {
        $running = $this->findRunning();
        $completed = 0;

        $stmt = $this->pdo->query(
            "SELECT COALESCE(SUM(duration_seconds), 0)
            FROM office_sessions
            WHERE ended_at IS NOT NULL AND work_date = CURDATE()",
        );
        $completed = (int) ($stmt ? $stmt->fetchColumn() : 0);

        return $completed + ($running?->elapsedSeconds() ?? 0);
    }

    public function totalUnassignedToday(): int
    {
        $stmt = $this->pdo->query(
            "SELECT COALESCE(SUM(unassigned_seconds), 0)
            FROM office_sessions
            WHERE work_date = CURDATE()",
        );

        return (int) ($stmt ? $stmt->fetchColumn() : 0);
    }

    /**
     * @return list<OfficeSession>
     */
    public function forDateRange(string $from, string $to, int $limit = 100): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM office_sessions
            WHERE work_date BETWEEN ? AND ?
            ORDER BY started_at DESC
            LIMIT ?',
        );
        $stmt->bindValue(1, $from);
        $stmt->bindValue(2, $to);
        $stmt->bindValue(3, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            OfficeSession::fromRow(...),
            $stmt->fetchAll(),
        );
    }

    /**
     * @return array<string, int> date => total office seconds
     */
    public function dailyTotals(string $from, string $to): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT work_date, COALESCE(SUM(duration_seconds), 0) AS total_seconds
            FROM office_sessions
            WHERE ended_at IS NOT NULL AND work_date BETWEEN ? AND ?
            GROUP BY work_date',
        );
        $stmt->execute([$from, $to]);

        $totals = [];
        foreach ($stmt->fetchAll() as $row) {
            $totals[(string) $row['work_date']] = (int) $row['total_seconds'];
        }

        return $totals;
    }
}
