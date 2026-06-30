<?php

declare(strict_types=1);

namespace Timer\Repositories;

use DateTimeImmutable;
use PDO;
use Timer\Models\TimeEntry;

final class TimeEntryRepository
{
    private const string ENTRY_SELECT = 'SELECT te.*, p.name AS project_name, p.color AS project_color, t.name AS task_name
            FROM time_entries te
            LEFT JOIN projects p ON p.id = te.project_id
            LEFT JOIN tasks t ON t.id = te.task_id';

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /** @return list<TimeEntry> */
    public function findAllRunning(): array
    {
        $stmt = $this->pdo->query(
            self::ENTRY_SELECT . '
            WHERE te.ended_at IS NULL
            ORDER BY te.started_at ASC',
        );

        $entries = array_map(
            TimeEntry::fromRow(...),
            $stmt ? $stmt->fetchAll() : [],
        );

        return $this->dedupeRunningByTask($entries);
    }

    public function findRunningByTaskId(int $taskId): ?TimeEntry
    {
        $stmt = $this->pdo->prepare(
            self::ENTRY_SELECT . '
            WHERE te.ended_at IS NULL AND te.task_id = ?
            ORDER BY te.started_at ASC
            LIMIT 1',
        );
        $stmt->execute([$taskId]);
        $row = $stmt->fetch();

        return $row ? TimeEntry::fromRow($row) : null;
    }

    /**
     * @param list<TimeEntry> $entries
     * @return list<TimeEntry>
     */
    private function dedupeRunningByTask(array $entries): array
    {
        $kept = [];
        $seenTaskIds = [];

        foreach ($entries as $entry) {
            if ($entry->taskId === null) {
                $kept[] = $entry;
                continue;
            }

            if (isset($seenTaskIds[$entry->taskId])) {
                $this->stop($entry->id);
                continue;
            }

            $seenTaskIds[$entry->taskId] = true;
            $kept[] = $entry;
        }

        return $kept;
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
            self::ENTRY_SELECT . '
            WHERE te.id = ?',
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ? TimeEntry::fromRow($row) : null;
    }

    /** @return list<TimeEntry> */
    public function recentToday(int $limit = 50): array
    {
        $stmt = $this->pdo->prepare(
            self::ENTRY_SELECT . '
            WHERE te.ended_at IS NOT NULL
              AND DATE(te.started_at) = CURDATE()
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

    /** @return list<TimeEntry> */
    public function recent(int $limit = 20): array
    {
        $stmt = $this->pdo->prepare(
            self::ENTRY_SELECT . '
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
        $sql = self::ENTRY_SELECT . '
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

    public function createManual(
        int $durationSeconds,
        DateTimeImmutable $workDate,
        ?int $projectId = null,
        ?int $taskId = null,
        ?string $notes = null,
    ): int {
        if ($durationSeconds <= 0) {
            throw new \InvalidArgumentException('Duration must be greater than zero.');
        }

        $notes = $notes !== null ? trim($notes) : null;
        $isGeneral = $projectId === null;

        if ($isGeneral && ($notes === null || $notes === '')) {
            throw new \InvalidArgumentException('Reason is required when no project is selected.');
        }

        $today = new DateTimeImmutable('today');
        $endedAt = $workDate->format('Y-m-d') === $today->format('Y-m-d')
            ? new DateTimeImmutable()
            : $workDate->setTime(17, 0, 0);

        $startedAt = $endedAt->modify('-' . $durationSeconds . ' seconds');
        $dayStart = $workDate->setTime(0, 0, 0);

        if ($startedAt < $dayStart) {
            $startedAt = $dayStart;
            $durationSeconds = max(0, $endedAt->getTimestamp() - $startedAt->getTimestamp());
        }

        if ($durationSeconds <= 0) {
            throw new \InvalidArgumentException('Duration is too long for the selected date.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO time_entries (project_id, task_id, started_at, ended_at, duration_seconds, notes)
            VALUES (?, ?, ?, ?, ?, ?)',
        );
        $stmt->execute([
            $projectId,
            $taskId,
            $startedAt->format('Y-m-d H:i:s'),
            $endedAt->format('Y-m-d H:i:s'),
            $durationSeconds,
            $notes !== '' ? $notes : null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @param list<int> $projectIds */
    public function stopRunningForProjects(array $projectIds): void
    {
        if ($projectIds === []) {
            return;
        }

        foreach ($this->findAllRunning() as $entry) {
            if ($entry->projectId !== null && in_array($entry->projectId, $projectIds, true)) {
                $this->stop($entry->id);
            }
        }
    }

    /** @param list<int> $projectIds */
    public function detachFromProjects(array $projectIds): void
    {
        if ($projectIds === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($projectIds), '?'));
        $sql = "UPDATE time_entries te
            INNER JOIN projects p ON p.id = te.project_id
            LEFT JOIN tasks t ON t.id = te.task_id
            SET
                te.notes = CASE
                    WHEN te.notes IS NULL OR te.notes = '' THEN
                        CONCAT(
                            p.name,
                            IF(t.name IS NOT NULL AND t.name != '', CONCAT(' · ', t.name), '')
                        )
                    ELSE
                        CONCAT(
                            te.notes,
                            ' (',
                            p.name,
                            IF(t.name IS NOT NULL AND t.name != '', CONCAT(' · ', t.name), ''),
                            ')'
                        )
                END,
                te.project_id = NULL,
                te.task_id = NULL
            WHERE te.project_id IN ($placeholders)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($projectIds);
    }

    public function totalSecondsInRange(
        string $from,
        string $to,
        ?int $projectId = null,
        ?int $taskId = null,
    ): int {
        $fromTs = (new DateTimeImmutable($from))->getTimestamp();
        $toTs = (new DateTimeImmutable($to))->getTimestamp();

        if ($toTs <= $fromTs) {
            return 0;
        }

        $sql = self::ENTRY_SELECT . '
            WHERE te.started_at < ?
              AND (te.ended_at IS NULL OR te.ended_at > ?)';
        $params = [$to, $from];

        if ($projectId !== null) {
            $sql .= ' AND te.project_id = ?';
            $params[] = $projectId;
        }

        if ($taskId !== null) {
            $sql .= ' AND te.task_id = ?';
            $params[] = $taskId;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        $total = 0;
        foreach ($stmt->fetchAll() as $row) {
            $entry = TimeEntry::fromRow($row);
            $entryStart = max($fromTs, (new DateTimeImmutable($entry->startedAt))->getTimestamp());
            $entryEnd = $entry->isRunning()
                ? $toTs
                : min($toTs, (new DateTimeImmutable((string) $entry->endedAt))->getTimestamp());

            if ($entryEnd > $entryStart) {
                $total += $entryEnd - $entryStart;
            }
        }

        return $total;
    }

    public function createOfficeGap(
        int $durationSeconds,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $endedAt,
        string $notes,
    ): int {
        if ($durationSeconds <= 0) {
            throw new \InvalidArgumentException('Duration must be greater than zero.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO time_entries (project_id, task_id, started_at, ended_at, duration_seconds, notes)
            VALUES (NULL, NULL, ?, ?, ?, ?)',
        );
        $stmt->execute([
            $startedAt->format('Y-m-d H:i:s'),
            $endedAt->format('Y-m-d H:i:s'),
            $durationSeconds,
            $notes,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function totalSecondsByDateRange(
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
