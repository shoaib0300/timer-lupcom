<?php

declare(strict_types=1);

namespace Timer\Support;

final class AttendanceHours
{
    public static function dailyTargetMinutes(int $dailyHours = 8): int
    {
        return $dailyHours * 60;
    }

    public static function timeToMinutes(?string $time): int
    {
        if ($time === null || $time === '' || $time === '00:00') {
            return 0;
        }

        $parts = explode(':', $time);
        $hours = (int) ($parts[0] ?? 0);
        $minutes = (int) ($parts[1] ?? 0);

        return $hours * 60 + $minutes;
    }

    public static function blockMinutes(?string $start, ?string $end): int
    {
        $startMin = self::timeToMinutes($start);
        $endMin = self::timeToMinutes($end);

        if ($startMin === 0 && $endMin === 0) {
            return 0;
        }

        if ($endMin <= $startMin) {
            return 0;
        }

        return $endMin - $startMin;
    }

    public static function workDayMinutes(
        ?string $morningStart,
        ?string $morningEnd,
        ?string $afternoonStart,
        ?string $afternoonEnd,
    ): int {
        return self::blockMinutes($morningStart, $morningEnd)
            + self::blockMinutes($afternoonStart, $afternoonEnd);
    }

    public static function minutesToDecimal(float $minutes): float
    {
        return round($minutes / 60, 2);
    }

    public static function formatDecimalGerman(float $hours): string
    {
        return number_format($hours, 2, ',', '');
    }

    public static function formatMinutesGerman(int $minutes): string
    {
        return self::formatDecimalGerman(self::minutesToDecimal((float) $minutes));
    }
}
