<?php

declare(strict_types=1);

namespace Timer\Support;

use DateTimeImmutable;
use Timer\Models\UserWorkingHours;

/**
 * Pure calendar Soll math (no I/O). Sums daily contractual minutes over real dates.
 */
final class ContractualSollCalculator
{
    public static function dailyMinutes(?UserWorkingHours $profile, int $isoWeekday): int
    {
        if ($profile === null || !$profile->isWorkingWeekday($isoWeekday)) {
            return 0;
        }

        return $profile->dailyMinutes();
    }

    /**
     * @param callable(string): ?UserWorkingHours $profileForDate
     */
    public static function sumRange(callable $profileForDate, DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        if ($to < $from) {
            return 0;
        }

        $total = 0;
        $cursor = $from;
        while ($cursor <= $to) {
            $date = $cursor->format('Y-m-d');
            $dow = (int) $cursor->format('N');
            $total += self::dailyMinutes($profileForDate($date), $dow);
            $cursor = $cursor->modify('+1 day');
        }

        return $total;
    }
}
