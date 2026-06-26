<?php

declare(strict_types=1);

namespace Timer\Models;

final class AttendanceDay
{
    public const TYPE_WORK = 'work';
    public const TYPE_VACATION = 'vacation';
    public const TYPE_SICK = 'sick';

    public function __construct(
        public readonly string $workDate,
        public readonly string $dayType,
        public readonly ?string $morningStart,
        public readonly ?string $morningEnd,
        public readonly ?string $afternoonStart,
        public readonly ?string $afternoonEnd,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            workDate: (string) $row['work_date'],
            dayType: (string) $row['day_type'],
            morningStart: self::nullableTime($row['morning_start'] ?? null),
            morningEnd: self::nullableTime($row['morning_end'] ?? null),
            afternoonStart: self::nullableTime($row['afternoon_start'] ?? null),
            afternoonEnd: self::nullableTime($row['afternoon_end'] ?? null),
        );
    }

    private static function nullableTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $time = substr((string) $value, 0, 5);

        return $time === '00:00' ? null : $time;
    }
}
