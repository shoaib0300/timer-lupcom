<?php

declare(strict_types=1);

namespace Timer\Repositories;

use DateTimeImmutable;
use PDO;
use Timer\Models\UserWorkingHours;

final class UserWorkingHoursRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public function findForDate(int $userId, string $date): ?UserWorkingHours
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM user_working_hours
             WHERE user_id = ?
               AND effective_from <= ?
               AND (effective_until IS NULL OR effective_until >= ?)
             ORDER BY effective_from DESC
             LIMIT 1',
        );
        $stmt->execute([$userId, $date, $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? UserWorkingHours::fromRow($row) : null;
    }

    public function findCurrent(int $userId, ?DateTimeImmutable $on = null): ?UserWorkingHours
    {
        $date = ($on ?? new DateTimeImmutable('today'))->format('Y-m-d');

        return $this->findForDate($userId, $date);
    }

    /** @return list<UserWorkingHours> */
    public function forUser(int $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM user_working_hours WHERE user_id = ? ORDER BY effective_from DESC',
        );
        $stmt->execute([$userId]);

        return array_map(
            static fn (array $row): UserWorkingHours => UserWorkingHours::fromRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
        );
    }

    /**
     * Save a new profile effective from $effectiveFrom (closes any open-ended prior row).
     *
     * @param list<int> $workingWeekdays
     */
    public function saveNewProfile(
        int $userId,
        int $weeklyMinutes,
        array $workingWeekdays,
        string $effectiveFrom,
    ): UserWorkingHours {
        $weekdays = $this->normalizeWeekdays($workingWeekdays);
        if ($weekdays === []) {
            throw new \InvalidArgumentException('working_days_required');
        }
        if ($weeklyMinutes < 0 || $weeklyMinutes > 168 * 60) {
            throw new \InvalidArgumentException('weekly_hours_invalid');
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveFrom) !== 1) {
            throw new \InvalidArgumentException('effective_from_invalid');
        }

        $this->pdo->beginTransaction();
        try {
            $priorUntil = (new DateTimeImmutable($effectiveFrom))->modify('-1 day')->format('Y-m-d');
            $close = $this->pdo->prepare(
                'UPDATE user_working_hours
                 SET effective_until = ?
                 WHERE user_id = ?
                   AND effective_from < ?
                   AND (effective_until IS NULL OR effective_until >= ?)',
            );
            $close->execute([$priorUntil, $userId, $effectiveFrom, $effectiveFrom]);

            $existing = $this->pdo->prepare(
                'SELECT id FROM user_working_hours WHERE user_id = ? AND effective_from = ?',
            );
            $existing->execute([$userId, $effectiveFrom]);
            $existingId = $existing->fetchColumn();

            $json = json_encode(array_values($weekdays), JSON_THROW_ON_ERROR);

            if ($existingId !== false) {
                $update = $this->pdo->prepare(
                    'UPDATE user_working_hours
                     SET weekly_minutes = ?, working_weekdays = ?, effective_until = NULL
                     WHERE id = ?',
                );
                $update->execute([$weeklyMinutes, $json, (int) $existingId]);
                $id = (int) $existingId;
            } else {
                $insert = $this->pdo->prepare(
                    'INSERT INTO user_working_hours
                        (user_id, weekly_minutes, working_weekdays, effective_from, effective_until)
                     VALUES (?, ?, ?, ?, NULL)',
                );
                $insert->execute([$userId, $weeklyMinutes, $json, $effectiveFrom]);
                $id = (int) $this->pdo->lastInsertId();
            }

            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }

        $profile = $this->findById($id);
        if ($profile === null) {
            throw new \RuntimeException('Failed to save working hours profile.');
        }

        return $profile;
    }

    public function ensureDefault(int $userId, int $dailyHours = 8): UserWorkingHours
    {
        $current = $this->findCurrent($userId);
        if ($current !== null) {
            return $current;
        }

        $weekly = max(0, $dailyHours) * 60 * 5;

        return $this->saveNewProfile($userId, $weekly, [1, 2, 3, 4, 5], '1970-01-01');
    }

    public function findById(int $id): ?UserWorkingHours
    {
        $stmt = $this->pdo->prepare('SELECT * FROM user_working_hours WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? UserWorkingHours::fromRow($row) : null;
    }

    /**
     * @param list<int|string> $weekdays
     * @return list<int>
     */
    private function normalizeWeekdays(array $weekdays): array
    {
        $normalized = [];
        foreach ($weekdays as $day) {
            $day = (int) $day;
            if ($day >= 1 && $day <= 7) {
                $normalized[$day] = $day;
            }
        }
        sort($normalized);

        return array_values($normalized);
    }
}
