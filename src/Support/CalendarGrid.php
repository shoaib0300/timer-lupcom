<?php

declare(strict_types=1);

namespace Timer\Support;

use DateTimeImmutable;

final class CalendarGrid
{
    /**
     * @param array<string, int> $dailyTotals
     *
     * @return list<array{
     *     date: string,
     *     day: int,
     *     in_month: bool,
     *     total_seconds: int,
     *     is_today: bool
     * }>
     */
    public static function build(string $month, array $dailyTotals): array
    {
        $first = new DateTimeImmutable($month . '-01');
        $pad = (int) $first->format('N') - 1;
        $cursor = $first->modify("-{$pad} days");
        $today = (new DateTimeImmutable())->format('Y-m-d');
        $cells = [];

        for ($i = 0; $i < 42; $i++) {
            $date = $cursor->format('Y-m-d');
            $cells[] = [
                'date' => $date,
                'day' => (int) $cursor->format('j'),
                'in_month' => $cursor->format('Y-m') === $month,
                'total_seconds' => $dailyTotals[$date] ?? 0,
                'is_today' => $date === $today,
            ];
            $cursor = $cursor->modify('+1 day');
        }

        return $cells;
    }
}
