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
}
