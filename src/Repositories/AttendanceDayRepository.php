<?php

declare(strict_types=1);

namespace Timer\Repositories;

use PDO;
use Timer\Models\AttendanceDay;

final class AttendanceDayRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $userId,
    ) {
    }

    public function find(string $date): ?AttendanceDay
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM attendance_days WHERE user_id = ? AND work_date = ?',
        );
        $stmt->execute([$this->userId, $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? AttendanceDay::fromRow($row) : null;
    }

    /**
     * @return array<string, AttendanceDay>
     */
    public function forRange(string $from, string $to): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM attendance_days
            WHERE user_id = ? AND work_date >= ? AND work_date <= ?
            ORDER BY work_date',
        );
        $stmt->execute([$this->userId, $from, $to]);
        $map = [];

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $day = AttendanceDay::fromRow($row);
            $map[$day->workDate] = $day;
        }

        return $map;
    }

    public function save(
        string $date,
        string $dayType,
        ?string $morningStart,
        ?string $morningEnd,
        ?string $afternoonStart,
        ?string $afternoonEnd,
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO attendance_days
                (user_id, work_date, day_type, morning_start, morning_end, afternoon_start, afternoon_end)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                day_type = VALUES(day_type),
                morning_start = VALUES(morning_start),
                morning_end = VALUES(morning_end),
                afternoon_start = VALUES(afternoon_start),
                afternoon_end = VALUES(afternoon_end)',
        );
        $stmt->execute([
            $this->userId,
            $date,
            $dayType,
            self::nullableTimeForDb($morningStart),
            self::nullableTimeForDb($morningEnd),
            self::nullableTimeForDb($afternoonStart),
            self::nullableTimeForDb($afternoonEnd),
        ]);
    }

    public function delete(string $date): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM attendance_days WHERE user_id = ? AND work_date = ?',
        );
        $stmt->execute([$this->userId, $date]);
    }

    public function deleteAll(): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM attendance_days WHERE user_id = ?');
        $stmt->execute([$this->userId]);
    }

    private static function nullableTimeForDb(?string $time): ?string
    {
        if ($time === null || $time === '' || $time === '00:00') {
            return null;
        }

        return strlen($time) === 5 ? $time . ':00' : $time;
    }
}
