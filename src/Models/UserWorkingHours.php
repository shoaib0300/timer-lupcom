<?php

declare(strict_types=1);

namespace Timer\Models;

final readonly class UserWorkingHours
{
    /**
     * @param list<int> $workingWeekdays ISO-8601 weekdays (1=Mon … 7=Sun)
     */
    public function __construct(
        public int $id,
        public int $userId,
        public int $weeklyMinutes,
        public array $workingWeekdays,
        public string $effectiveFrom,
        public ?string $effectiveUntil,
    ) {
    }

    public function workingDayCount(): int
    {
        return count($this->workingWeekdays);
    }

    public function dailyMinutes(): int
    {
        $days = $this->workingDayCount();
        if ($days <= 0) {
            return 0;
        }

        return intdiv($this->weeklyMinutes, $days);
    }

    public function isWorkingWeekday(int $isoWeekday): bool
    {
        return in_array($isoWeekday, $this->workingWeekdays, true);
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        $weekdays = $row['working_weekdays'] ?? '[]';
        if (is_string($weekdays)) {
            $decoded = json_decode($weekdays, true);
            $weekdays = is_array($decoded) ? $decoded : [];
        }

        $normalized = [];
        foreach ($weekdays as $day) {
            $day = (int) $day;
            if ($day >= 1 && $day <= 7) {
                $normalized[$day] = $day;
            }
        }
        sort($normalized);

        return new self(
            (int) $row['id'],
            (int) $row['user_id'],
            (int) $row['weekly_minutes'],
            array_values($normalized),
            (string) $row['effective_from'],
            isset($row['effective_until']) && $row['effective_until'] !== null && $row['effective_until'] !== ''
                ? (string) $row['effective_until']
                : null,
        );
    }
}
