<?php

declare(strict_types=1);

namespace Timer\Support;

final class TimeFormatter
{
    public static function secondsToHuman(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        if ($hours > 0) {
            return sprintf('%dh %02dm', $hours, $minutes);
        }

        if ($minutes > 0) {
            return sprintf('%dm', $minutes);
        }

        return '0m';
    }

    public static function secondsToClock(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        return sprintf('%02d:%02d', $hours, $minutes);
    }

    /** Planio reports hours with limited decimal precision — round to whole minutes first. */
    public static function secondsFromPlanioHours(float $hours): int
    {
        $minutes = (int) round($hours * 60);

        if ($minutes <= 0) {
            return 0;
        }

        return $minutes * 60;
    }

    /** Round local timer durations to the nearest minute for storage and display. */
    public static function roundToNearestMinute(int $seconds): int
    {
        if ($seconds <= 0) {
            return 0;
        }

        return max(60, (int) round($seconds / 60) * 60);
    }
}
