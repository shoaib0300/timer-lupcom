<?php

declare(strict_types=1);

namespace Timer\Services;

use DateTimeImmutable;
use Timer\Models\UserWorkingHours;
use Timer\Repositories\UserWorkingHoursRepository;
use Timer\Support\AttendanceHours;
use Timer\Support\ContractualSollCalculator;

/**
 * Calendar-aware Soll (target) working time from contractual weekly hours.
 *
 * Source of truth: weekly_minutes + working_weekdays + effective dating.
 * Annual/monthly totals are sums of daily Soll over actual dates (never weekly × 52).
 *
 * Holidays/absences: this service only answers "scheduled contractual Soll for date".
 * AttendanceService applies holiday/vacation Ist-credit rules on top.
 */
final class SollWorkingTimeService
{
    public const MAX_WEEKLY_MINUTES = 168 * 60;

    public function __construct(
        private readonly UserWorkingHoursRepository $profiles,
        private readonly int $userId,
    ) {
    }

    public function profileForDate(string $date): ?UserWorkingHours
    {
        return $this->profiles->findForDate($this->userId, $date);
    }

    public function currentProfile(?DateTimeImmutable $on = null): ?UserWorkingHours
    {
        return $this->profiles->findCurrent($this->userId, $on);
    }

    public function ensureDefault(int $dailyHoursFallback = 8): UserWorkingHours
    {
        return $this->profiles->ensureDefault($this->userId, $dailyHoursFallback);
    }

    /**
     * Contractual daily Soll for a calendar date (0 if non-working weekday or outside contract).
     */
    public function getDailySollMinutes(string $date): int
    {
        $profile = $this->profiles->findForDate($this->userId, $date);
        $dow = (int) (new DateTimeImmutable($date))->format('N');

        return ContractualSollCalculator::dailyMinutes($profile, $dow);
    }

    public function isWorkingDate(string $date): bool
    {
        return $this->isConfiguredWorkingWeekday($date);
    }

    public function isConfiguredWorkingWeekday(string $date): bool
    {
        $profile = $this->profiles->findForDate($this->userId, $date);
        if ($profile === null) {
            return false;
        }

        $dow = (int) (new DateTimeImmutable($date))->format('N');

        return $profile->isWorkingWeekday($dow);
    }

    public function getSollMinutesForRange(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        return ContractualSollCalculator::sumRange(
            fn (string $date): ?UserWorkingHours => $this->profiles->findForDate($this->userId, $date),
            $from,
            $to,
        );
    }

    public function getMonthlySollMinutes(int $year, int $month): int
    {
        $from = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
        $to = $from->modify('last day of this month');

        return $this->getSollMinutesForRange($from, $to);
    }

    public function getYearlySollMinutes(int $year): int
    {
        $from = new DateTimeImmutable(sprintf('%04d-01-01', $year));
        $to = new DateTimeImmutable(sprintf('%04d-12-31', $year));

        return $this->getSollMinutesForRange($from, $to);
    }

    /**
     * @return array{
     *     weekly_minutes: int,
     *     weekly_label: string,
     *     daily_minutes: int,
     *     daily_label: string,
     *     daily_hours_approx: float,
     *     working_weekdays: list<int>,
     *     working_days_count: int,
     *     effective_from: string,
     *     effective_until: ?string
     * }|null
     */
    public function displayConfig(?DateTimeImmutable $on = null): ?array
    {
        $profile = $this->currentProfile($on);
        if ($profile === null) {
            return null;
        }

        return [
            'weekly_minutes' => $profile->weeklyMinutes,
            'weekly_label' => AttendanceHours::formatMinutesClock($profile->weeklyMinutes),
            'daily_minutes' => $profile->dailyMinutes(),
            'daily_label' => AttendanceHours::formatMinutesClock($profile->dailyMinutes()),
            'daily_hours_approx' => round($profile->dailyMinutes() / 60, 2),
            'working_weekdays' => $profile->workingWeekdays,
            'working_days_count' => $profile->workingDayCount(),
            'effective_from' => $profile->effectiveFrom,
            'effective_until' => $profile->effectiveUntil,
        ];
    }

    /**
     * @param list<int|string> $workingWeekdays
     */
    public function saveContract(
        int $weeklyMinutes,
        array $workingWeekdays,
        string $effectiveFrom,
    ): UserWorkingHours {
        return $this->profiles->saveNewProfile(
            $this->userId,
            $weeklyMinutes,
            $workingWeekdays,
            $effectiveFrom,
        );
    }

    public static function parseWeeklyHoursInput(string $raw): ?int
    {
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }

        if (preg_match('/^(\d{1,3}):([0-5]\d)$/', $raw, $m) === 1) {
            $minutes = ((int) $m[1]) * 60 + (int) $m[2];
        } elseif (preg_match('/^\d{1,3}$/', $raw) === 1) {
            $minutes = ((int) $raw) * 60;
        } elseif (preg_match('/^\d{1,3}[.,]\d{1,2}$/', $raw) === 1) {
            $normalized = str_replace(',', '.', $raw);
            $minutes = (int) round(((float) $normalized) * 60);
        } else {
            return null;
        }

        if ($minutes < 0 || $minutes > self::MAX_WEEKLY_MINUTES) {
            return null;
        }

        return $minutes;
    }
}
