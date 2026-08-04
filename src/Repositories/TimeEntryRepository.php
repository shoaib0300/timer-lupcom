<?php

declare(strict_types=1);

namespace Timer\Repositories;

use DateTimeImmutable;
use PDO;
use Timer\Models\TimeEntry;
use Timer\Support\TimeFormatter;

final class TimeEntryRepository
{
    private const string ENTRY_SELECT = 'SELECT te.*, p.name AS project_name, p.color AS project_color, t.name AS task_name
            FROM time_entries te
            LEFT JOIN tasks t ON t.id = te.task_id
            LEFT JOIN projects p ON p.id = COALESCE(te.project_id, t.project_id)';

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?int $userId = null,
    ) {
    }

    /** @return array{0: string, 1: list<int|float|string>} */
    private function userScope(string $alias = 'te'): array
    {
        if ($this->userId === null) {
            return ['', []];
        }

        return [" AND {$alias}.user_id = ?", [$this->userId]];
    }

    /** @return array{0: string, 1: list<int|float|string>} */
    private function tableUserScope(): array
    {
        if ($this->userId === null) {
            return ['', []];
        }

        return [' AND user_id = ?', [$this->userId]];
    }

    private function requireUserId(): int
    {
        if ($this->userId === null) {
            throw new \RuntimeException('User scope is required for this operation.');
        }

        return $this->userId;
    }

    /** @return list<TimeEntry> */
    public function findAllRunning(): array
    {
        [$userSql, $userParams] = $this->userScope();
        $stmt = $this->pdo->prepare(
            self::ENTRY_SELECT . '
            WHERE te.ended_at IS NULL' . $userSql . '
            ORDER BY te.started_at ASC',
        );
        $stmt->execute($userParams);

        $entries = array_map(
            TimeEntry::fromRow(...),
            $stmt->fetchAll(),
        );

        return $this->dedupeRunningByTask($entries);
    }

    public function findRunningByTaskId(int $taskId): ?TimeEntry
    {
        [$userSql, $userParams] = $this->userScope();
        $stmt = $this->pdo->prepare(
            self::ENTRY_SELECT . '
            WHERE te.ended_at IS NULL AND te.task_id = ?' . $userSql . '
            ORDER BY te.started_at ASC
            LIMIT 1',
        );
        $stmt->execute([$taskId, ...$userParams]);
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
            'INSERT INTO time_entries (user_id, project_id, task_id, started_at) VALUES (?, ?, ?, ?)',
        );
        $stmt->execute([$this->requireUserId(), $projectId, $taskId, $now]);

        return (int) $this->pdo->lastInsertId();
    }

    public function stop(int $entryId, ?string $notes = null): ?TimeEntry
    {
        $entry = $this->findById($entryId);

        if ($entry === null || !$entry->isRunning()) {
            return null;
        }

        $endedAt = new DateTimeImmutable();
        $duration = TimeFormatter::roundToNearestMinute($entry->elapsedSeconds());

        $noteText = trim((string) $notes);
        if ($noteText === '') {
            $noteText = (string) ($entry->notes ?? '');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE time_entries
            SET ended_at = ?, duration_seconds = ?, paused_at = NULL, notes = ?,
                project_id = CASE WHEN task_id IS NOT NULL THEN NULL ELSE project_id END
            WHERE id = ?',
        );
        $stmt->execute([$endedAt->format('Y-m-d H:i:s'), $duration, $noteText !== '' ? $noteText : null, $entryId]);

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
        [$userSql, $userParams] = $this->userScope();
        $stmt = $this->pdo->prepare(
            self::ENTRY_SELECT . '
            WHERE te.id = ?' . $userSql,
        );
        $stmt->execute([$id, ...$userParams]);
        $row = $stmt->fetch();

        return $row ? TimeEntry::fromRow($row) : null;
    }

    /** @return list<TimeEntry> */
    public function recentToday(int $limit = 50): array
    {
        [$userSql, $userParams] = $this->userScope();
        $stmt = $this->pdo->prepare(
            self::ENTRY_SELECT . '
            WHERE te.ended_at IS NOT NULL
              AND DATE(te.started_at) = CURDATE()' . $userSql . '
            ORDER BY te.ended_at DESC
            LIMIT ?',
        );
        $stmt->execute([...$userParams, $limit]);

        return array_map(
            TimeEntry::fromRow(...),
            $stmt->fetchAll(),
        );
    }

    /** @return list<TimeEntry> */
    public function recent(int $limit = 20): array
    {
        [$userSql, $userParams] = $this->userScope();
        $stmt = $this->pdo->prepare(
            self::ENTRY_SELECT . '
            WHERE te.ended_at IS NOT NULL' . $userSql . '
            ORDER BY te.ended_at DESC
            LIMIT ?',
        );
        $stmt->execute([...$userParams, $limit]);

        return array_map(
            TimeEntry::fromRow(...),
            $stmt->fetchAll(),
        );
    }

    public function totalSecondsToday(): int
    {
        [$userSql, $userParams] = $this->tableUserScope();
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(duration_seconds), 0)
            FROM time_entries
            WHERE ended_at IS NOT NULL
              AND DATE(started_at) = CURDATE()" . $userSql,
        );
        $stmt->execute($userParams);

        return (int) $stmt->fetchColumn();
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

        [$userSql, $userParams] = $this->tableUserScope();
        $sql .= $userSql;
        $params = array_merge($params, $userParams);

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

        [$userSql, $userParams] = $this->userScope();
        $sql .= $userSql;
        $params = array_merge($params, $userParams);

        if ($projectId !== null) {
            $sql .= ' AND te.project_id = ?';
            $params[] = $projectId;
        }

        if ($taskId !== null) {
            $sql .= ' AND te.task_id = ?';
            $params[] = $taskId;
        }

        $sql .= ' ORDER BY te.started_at DESC LIMIT ?';
        $params[] = $limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map(
            TimeEntry::fromRow(...),
            $stmt->fetchAll(),
        );
    }

    /** @return list<TimeEntry> */
    public function forDateRange(
        string $from,
        string $to,
        ?int $projectId = null,
        ?int $taskId = null,
    ): array {
        $sql = self::ENTRY_SELECT . '
            WHERE te.ended_at IS NOT NULL
              AND DATE(te.started_at) BETWEEN ? AND ?';
        $params = [$from, $to];

        [$userSql, $userParams] = $this->userScope();
        $sql .= $userSql;
        $params = array_merge($params, $userParams);

        if ($projectId !== null) {
            $sql .= ' AND te.project_id = ?';
            $params[] = $projectId;
        }

        if ($taskId !== null) {
            $sql .= ' AND te.task_id = ?';
            $params[] = $taskId;
        }

        $sql .= ' ORDER BY te.started_at ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map(
            TimeEntry::fromRow(...),
            $stmt->fetchAll(),
        );
    }

    /** @return list<TimeEntry> */
    public function forTask(int $taskId, int $limit = 200): array
    {
        if ($taskId <= 0) {
            return [];
        }

        $limit = max(1, min($limit, 1000));
        [$userSql, $userParams] = $this->userScope();
        $stmt = $this->pdo->prepare(
            self::ENTRY_SELECT . '
            WHERE te.task_id = ?
              AND te.ended_at IS NOT NULL' . $userSql . '
            ORDER BY te.started_at DESC
            LIMIT ?',
        );
        $stmt->execute([$taskId, ...$userParams, $limit]);

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
            $endedAt = $startedAt->modify('+' . $durationSeconds . ' seconds');
        }

        if ($durationSeconds <= 0) {
            throw new \InvalidArgumentException('Duration is too long for the selected date.');
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO time_entries (user_id, project_id, task_id, started_at, ended_at, duration_seconds, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?)',
        );
        $stmt->execute([
            $this->requireUserId(),
            $projectId,
            $taskId,
            $startedAt->format('Y-m-d H:i:s'),
            $endedAt->format('Y-m-d H:i:s'),
            $durationSeconds,
            $notes !== '' ? $notes : null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findByPlanioTimeEntryId(int $planioTimeEntryId): ?TimeEntry
    {
        [$userSql, $userParams] = $this->userScope();
        $stmt = $this->pdo->prepare(
            self::ENTRY_SELECT . '
            WHERE te.planio_time_entry_id = ?' . $userSql,
        );
        $stmt->execute([$planioTimeEntryId, ...$userParams]);
        $row = $stmt->fetch();

        return $row ? TimeEntry::fromRow($row) : null;
    }

    public function setPlanioTimeEntryId(int $entryId, int $planioTimeEntryId): void
    {
        if ($entryId <= 0 || $planioTimeEntryId <= 0) {
            throw new \InvalidArgumentException('Entry id and Planio time entry id are required.');
        }

        [$userSql, $userParams] = $this->tableUserScope();
        $stmt = $this->pdo->prepare(
            'UPDATE time_entries
             SET planio_time_entry_id = ?
             WHERE id = ?' . $userSql,
        );
        $stmt->execute([$planioTimeEntryId, $entryId, ...$userParams]);
    }

    /**
     * @return 'imported'|'updated'
     */
    public function upsertFromPlanio(
        int $planioTimeEntryId,
        int $projectId,
        ?int $taskId,
        int $durationSeconds,
        DateTimeImmutable $startedAt,
        DateTimeImmutable $endedAt,
        ?string $notes,
    ): string {
        $existing = $this->findByPlanioTimeEntryId($planioTimeEntryId);

        if ($existing !== null) {
            $stmt = $this->pdo->prepare(
                'UPDATE time_entries
                SET project_id = ?, task_id = ?, started_at = ?, ended_at = ?, duration_seconds = ?, notes = ?
                WHERE id = ? AND user_id = ?',
            );
            $stmt->execute([
                $projectId,
                $taskId,
                $startedAt->format('Y-m-d H:i:s'),
                $endedAt->format('Y-m-d H:i:s'),
                $durationSeconds,
                $notes,
                $existing->id,
                $this->requireUserId(),
            ]);

            return 'updated';
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO time_entries (
                user_id, project_id, task_id, started_at, ended_at, duration_seconds, notes, planio_time_entry_id
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        );
        $stmt->execute([
            $this->requireUserId(),
            $projectId,
            $taskId,
            $startedAt->format('Y-m-d H:i:s'),
            $endedAt->format('Y-m-d H:i:s'),
            $durationSeconds,
            $notes,
            $planioTimeEntryId,
        ]);

        return 'imported';
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

        [$userSql, $userParams] = $this->userScope();
        $sql .= $userSql;
        $params = array_merge($params, $userParams);

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
            'INSERT INTO time_entries (user_id, project_id, task_id, started_at, ended_at, duration_seconds, notes)
            VALUES (?, NULL, NULL, ?, ?, ?, ?)',
        );
        $stmt->execute([
            $this->requireUserId(),
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

        [$userSql, $userParams] = $this->tableUserScope();
        $sql .= $userSql;
        $params = array_merge($params, $userParams);

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
