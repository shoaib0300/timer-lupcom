<?php

declare(strict_types=1);

use Timer\Models\UserWorkingHours;
use Timer\Services\SollWorkingTimeService;
use Timer\Support\AttendanceHours;
use Timer\Support\ContractualSollCalculator;

require dirname(__DIR__) . '/vendor/autoload.php';

final class Assert
{
    private static int $failed = 0;
    private static int $passed = 0;

    public static function same(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            self::$failed++;
            fwrite(STDERR, "FAIL: {$message}\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n");

            return;
        }

        self::$passed++;
        echo "OK: {$message}\n";
    }

    public static function true(bool $value, string $message): void
    {
        self::same(true, $value, $message);
    }

    public static function summary(): int
    {
        echo "\n" . self::$passed . ' passed, ' . self::$failed . " failed\n";

        return self::$failed === 0 ? 0 : 1;
    }
}

function profile(int $weeklyMinutes, array $weekdays, string $from = '1970-01-01', ?string $until = null): UserWorkingHours
{
    return new UserWorkingHours(1, 1, $weeklyMinutes, $weekdays, $from, $until);
}

// 1. Five-day / 35h
$laura = profile(35 * 60, [1, 2, 3, 4, 5]);
Assert::same(7 * 60, $laura->dailyMinutes(), 'Laura daily Soll 7:00');
Assert::same('7:00', AttendanceHours::formatMinutesClock($laura->dailyMinutes()), 'Laura clock label');

// 2. Four-day / 25h
$svetlana = profile(25 * 60, [2, 3, 4, 5]);
Assert::same(6 * 60 + 15, $svetlana->dailyMinutes(), 'Svetlana daily Soll 6:15');
Assert::same(0, ContractualSollCalculator::dailyMinutes($svetlana, 1), 'Svetlana Monday 0');
Assert::same(6 * 60 + 15, ContractualSollCalculator::dailyMinutes($svetlana, 2), 'Svetlana Tuesday Soll');
Assert::same(0, ContractualSollCalculator::dailyMinutes($svetlana, 6), 'Svetlana Saturday 0');
Assert::same(0, ContractualSollCalculator::dailyMinutes($svetlana, 7), 'Svetlana Sunday 0');

// 3–4. 38h / 37h
Assert::same(7 * 60 + 36, profile(38 * 60, [1, 2, 3, 4, 5])->dailyMinutes(), 'Juliane daily 7:36');
Assert::same(7 * 60 + 24, profile(37 * 60, [1, 2, 3, 4, 5])->dailyMinutes(), 'Wilhelm daily 7:24');

// 6. Weekend with Mon–Fri profile
Assert::same(0, ContractualSollCalculator::dailyMinutes($laura, 6), 'Weekend Saturday 0');
Assert::same(0, ContractualSollCalculator::dailyMinutes($laura, 7), 'Weekend Sunday 0');

// 7–8. Monthly / yearly = sum of days (not ×52)
$jan2026 = ContractualSollCalculator::sumRange(
    static fn (): UserWorkingHours => $laura,
    new DateTimeImmutable('2026-01-01'),
    new DateTimeImmutable('2026-01-31'),
);
$manualJan = 0;
for ($d = new DateTimeImmutable('2026-01-01'); $d <= new DateTimeImmutable('2026-01-31'); $d = $d->modify('+1 day')) {
    $manualJan += ContractualSollCalculator::dailyMinutes($laura, (int) $d->format('N'));
}
Assert::same($manualJan, $jan2026, 'January Soll equals sum of daily Soll');
Assert::true($jan2026 !== 35 * 60 * 52, 'Yearly must not use weekly×52 shortcut for month');

$year2026 = ContractualSollCalculator::sumRange(
    static fn (): UserWorkingHours => $laura,
    new DateTimeImmutable('2026-01-01'),
    new DateTimeImmutable('2026-12-31'),
);
Assert::true($year2026 !== 35 * 60 * 52, 'Yearly Soll is not weekly×52');
Assert::true($year2026 > 0, 'Yearly Soll positive');

// 9. Leap year February 2024
$febLeap = ContractualSollCalculator::sumRange(
    static fn (): UserWorkingHours => $laura,
    new DateTimeImmutable('2024-02-01'),
    new DateTimeImmutable('2024-02-29'),
);
$febNonLeap = ContractualSollCalculator::sumRange(
    static fn (): UserWorkingHours => $laura,
    new DateTimeImmutable('2025-02-01'),
    new DateTimeImmutable('2025-02-28'),
);
Assert::true($febLeap >= $febNonLeap, 'Leap February has at least as many Soll minutes');
Assert::same(29, (int) (new DateTimeImmutable('2024-02-29'))->format('j'), '2024-02-29 exists');

// 10–11. Contract start / end via effective dating
$midStart = profile(35 * 60, [1, 2, 3, 4, 5], '2026-01-15', null);
$partialJan = ContractualSollCalculator::sumRange(
    static function (string $date) use ($midStart): ?UserWorkingHours {
        return $date >= $midStart->effectiveFrom ? $midStart : null;
    },
    new DateTimeImmutable('2026-01-01'),
    new DateTimeImmutable('2026-01-31'),
);
$fullJan = ContractualSollCalculator::sumRange(
    static fn (): UserWorkingHours => $laura,
    new DateTimeImmutable('2026-01-01'),
    new DateTimeImmutable('2026-01-31'),
);
Assert::true($partialJan < $fullJan, 'Mid-month start yields less Soll than full month');

$ended = profile(35 * 60, [1, 2, 3, 4, 5], '1970-01-01', '2026-01-10');
$endedJan = ContractualSollCalculator::sumRange(
    static function (string $date) use ($ended): ?UserWorkingHours {
        return $date <= (string) $ended->effectiveUntil ? $ended : null;
    },
    new DateTimeImmutable('2026-01-01'),
    new DateTimeImmutable('2026-01-31'),
);
Assert::true($endedJan < $fullJan, 'Early contract end yields less Soll than full month');

// 12. Historical contract change
$before = profile(35 * 60, [1, 2, 3, 4, 5], '1970-01-01', '2026-09-30');
$after = profile(32 * 60, [1, 2, 3, 4, 5], '2026-10-01', null);
$resolver = static function (string $date) use ($before, $after): UserWorkingHours {
    return $date >= '2026-10-01' ? $after : $before;
};
Assert::same(
    7 * 60,
    ContractualSollCalculator::dailyMinutes($resolver('2026-09-29'), 1),
    'Historical Sep still 7:00/day from 35h',
);
Assert::same(
    intdiv(32 * 60, 5),
    ContractualSollCalculator::dailyMinutes($resolver('2026-10-01'), 4),
    'From Oct uses 32h weekly → 6:24/day',
);

// Parse + format helpers
Assert::same(35 * 60, SollWorkingTimeService::parseWeeklyHoursInput('35:00'), 'parse 35:00');
Assert::same(25 * 60, SollWorkingTimeService::parseWeeklyHoursInput('25'), 'parse 25');
Assert::same(null, SollWorkingTimeService::parseWeeklyHoursInput('abc'), 'reject invalid');
Assert::same(null, SollWorkingTimeService::parseWeeklyHoursInput('200:00'), 'reject >168h');
Assert::same('7:36', AttendanceHours::formatMinutesClock(456), 'format 7:36');

exit(Assert::summary());
