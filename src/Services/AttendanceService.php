<?php

declare(strict_types=1);

namespace Timer\Services;

use DateTimeImmutable;
use Timer\Models\AttendanceDay;
use Timer\Repositories\AttendanceDayRepository;
use Timer\Repositories\AttendanceHolidayRepository;
use Timer\Repositories\SettingsRepository;
use Timer\Support\AttendanceHours;
use Timer\Support\GermanHolidays;

final class AttendanceService
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly AttendanceDayRepository $days,
        private readonly AttendanceHolidayRepository $holidays,
    ) {
    }

    /** @return array{country: string, state: string, daily_hours: int, break_minutes: int} */
    public function config(): array
    {
        return [
            'country' => $this->settings->get('attendance.country', 'DE') ?? 'DE',
            'state' => $this->settings->get('attendance.state', 'MV') ?? 'MV',
            'daily_hours' => (int) ($this->settings->get('attendance.daily_hours', '8') ?? '8'),
            'break_minutes' => (int) ($this->settings->get('attendance.break_minutes', '30') ?? '30'),
        ];
    }

    public function saveConfig(string $country, string $state): void
    {
        $this->settings->set('attendance.country', strtoupper($country));
        $this->settings->set('attendance.state', strtoupper($state));
    }

    /**
     * @return array<string, string> date => name
     */
    public function resolvedHolidays(int $year): array
    {
        $config = $this->config();
        $country = $config['country'];
        $state = $config['state'];

        if ($country !== 'DE') {
            return $this->applyOverrides([], $country, $state, $year);
        }

        $builtin = GermanHolidays::forState($year, $state);

        return $this->applyOverrides($builtin, $country, $state, $year);
    }

    /**
     * @param array<string, string> $builtin
     *
     * @return array<string, string>
     */
    private function applyOverrides(array $builtin, string $country, string $state, int $year): array
    {
        $overrides = $this->holidays->overridesForYear($country, $state, $year);

        foreach ($overrides as $override) {
            $date = $override['holiday_date'];
            if ($override['override_type'] === 'remove') {
                unset($builtin[$date]);
            } else {
                $builtin[$date] = (string) ($override['name'] ?? 'Feiertag');
            }
        }

        ksort($builtin);

        return $builtin;
    }

    /**
     * @return list<array{
     *     week_start: string,
     *     week_end: string,
     *     week_total_minutes: int,
     *     week_total_label: string,
     *     days: list<array<string, mixed>>
     * }>
     */
    public function weeksForMonth(string $month): array
    {
        $config = $this->config();
        $targetMinutes = AttendanceHours::dailyTargetMinutes($config['daily_hours']);
        $year = (int) substr($month, 0, 4);
        $holidayMap = $this->resolvedHolidays($year);

        $first = new DateTimeImmutable($month . '-01');
        $last = $first->modify('last day of this month');
        $rangeFrom = $first->modify('-' . ((int) $first->format('N') - 1) . ' days');
        $rangeTo = $last->modify('+' . (7 - (int) $last->format('N')) . ' days');

        $storedDays = $this->days->forRange(
            $rangeFrom->format('Y-m-d'),
            $rangeTo->format('Y-m-d'),
        );

        $weeks = [];
        $cursor = $rangeFrom;

        while ($cursor <= $rangeTo) {
            $weekStart = $cursor;
            $weekEnd = $cursor->modify('+6 days');
            $dayCells = [];
            $weekMinutes = 0;

            for ($i = 0; $i < 7; $i++) {
                $date = $weekStart->modify("+{$i} days");
                $dateStr = $date->format('Y-m-d');
                $inMonth = $date->format('Y-m') === $month;
                $dow = (int) $date->format('N');
                $isWeekend = $dow >= 6;
                $stored = $storedDays[$dateStr] ?? null;
                $holidayName = $holidayMap[$dateStr] ?? null;

                $resolved = $this->resolveDay(
                    $dateStr,
                    $stored,
                    $holidayName,
                    $isWeekend,
                    $targetMinutes,
                );

                if ($inMonth && !$isWeekend) {
                    $weekMinutes += $resolved['worked_minutes'];
                }

                $dayCells[] = array_merge($resolved, [
                    'date' => $dateStr,
                    'day' => (int) $date->format('j'),
                    'weekday' => $dow,
                    'in_month' => $inMonth,
                    'is_weekend' => $isWeekend,
                    'holiday_name' => $holidayName,
                ]);
            }

            $hasMonthDay = array_reduce(
                $dayCells,
                static fn (bool $carry, array $d): bool => $carry || $d['in_month'],
                false,
            );

            if ($hasMonthDay) {
                $weeks[] = [
                    'week_start' => $weekStart->format('Y-m-d'),
                    'week_end' => $weekEnd->format('Y-m-d'),
                    'week_start_label' => $weekStart->format('d.m.Y'),
                    'week_end_label' => $weekEnd->format('d.m.Y'),
                    'week_total_minutes' => $weekMinutes,
                    'week_total_label' => AttendanceHours::formatMinutesGerman($weekMinutes),
                    'days' => $dayCells,
                ];
            }

            $cursor = $cursor->modify('+7 days');
        }

        return $weeks;
    }

    /**
     * @return array{
     *     soll_minutes: int,
     *     ist_minutes: int,
     *     diff_minutes: int,
     *     soll_label: string,
     *     ist_label: string,
     *     diff_label: string,
     *     yearly_diff_minutes: int,
     *     yearly_diff_label: string
     * }
     */
    public function monthSummary(string $month): array
    {
        $config = $this->config();
        $targetMinutes = AttendanceHours::dailyTargetMinutes($config['daily_hours']);
        $year = (int) substr($month, 0, 4);
        $holidayMap = $this->resolvedHolidays($year);

        $first = new DateTimeImmutable($month . '-01');
        $last = $first->modify('last day of this month');
        $storedDays = $this->days->forRange($first->format('Y-m-d'), $last->format('Y-m-d'));

        $soll = 0;
        $ist = 0;
        $cursor = $first;

        while ($cursor <= $last) {
            $dateStr = $cursor->format('Y-m-d');
            $dow = (int) $cursor->format('N');

            if ($dow < 6) {
                $soll += $targetMinutes;
                $resolved = $this->resolveDay(
                    $dateStr,
                    $storedDays[$dateStr] ?? null,
                    $holidayMap[$dateStr] ?? null,
                    false,
                    $targetMinutes,
                );
                $ist += $resolved['worked_minutes'];
            }

            $cursor = $cursor->modify('+1 day');
        }

        $yearStart = new DateTimeImmutable(sprintf('%d-01-01', $year));
        $yearSummary = $this->rangeSummary($yearStart, $last, $targetMinutes);

        $diff = $ist - $soll;

        return [
            'soll_minutes' => $soll,
            'ist_minutes' => $ist,
            'diff_minutes' => $diff,
            'soll_label' => AttendanceHours::formatMinutesGerman($soll),
            'ist_label' => AttendanceHours::formatMinutesGerman($ist),
            'diff_label' => ($diff >= 0 ? '+' : '') . AttendanceHours::formatMinutesGerman($diff),
            'yearly_diff_minutes' => $yearSummary['diff_minutes'],
            'yearly_diff_label' => ($yearSummary['diff_minutes'] >= 0 ? '+' : '')
                . AttendanceHours::formatMinutesGerman($yearSummary['diff_minutes']),
        ];
    }

    /**
     * @return array{soll_minutes: int, ist_minutes: int, diff_minutes: int}
     */
    private function rangeSummary(DateTimeImmutable $from, DateTimeImmutable $to, int $targetMinutes): array
    {
        $year = (int) $from->format('Y');
        $holidayMap = $this->resolvedHolidays($year);
        if ((int) $to->format('Y') !== $year) {
            $holidayMap = array_merge(
                $holidayMap,
                $this->resolvedHolidays((int) $to->format('Y')),
            );
        }

        $storedDays = $this->days->forRange($from->format('Y-m-d'), $to->format('Y-m-d'));
        $soll = 0;
        $ist = 0;
        $cursor = $from;

        while ($cursor <= $to) {
            $dateStr = $cursor->format('Y-m-d');
            $dow = (int) $cursor->format('N');

            if ($dow < 6) {
                $soll += $targetMinutes;
                $resolved = $this->resolveDay(
                    $dateStr,
                    $storedDays[$dateStr] ?? null,
                    $holidayMap[$dateStr] ?? null,
                    false,
                    $targetMinutes,
                );
                $ist += $resolved['worked_minutes'];
            }

            $cursor = $cursor->modify('+1 day');
        }

        return [
            'soll_minutes' => $soll,
            'ist_minutes' => $ist,
            'diff_minutes' => $ist - $soll,
        ];
    }

    /**
     * @return array{
     *     kind: string,
     *     worked_minutes: int,
     *     worked_label: string,
     *     morning_start: ?string,
     *     morning_end: ?string,
     *     afternoon_start: ?string,
     *     afternoon_end: ?string,
     *     day_type: string
     * }
     */
    private function resolveDay(
        string $date,
        ?AttendanceDay $stored,
        ?string $holidayName,
        bool $isWeekend,
        int $targetMinutes,
    ): array {
        if ($isWeekend) {
            return $this->dayPayload('weekend', 0, null, AttendanceDay::TYPE_WORK);
        }

        if ($stored !== null && $stored->dayType === AttendanceDay::TYPE_VACATION) {
            return $this->dayPayload('vacation', $targetMinutes, $stored, AttendanceDay::TYPE_VACATION);
        }

        if ($stored !== null && $stored->dayType === AttendanceDay::TYPE_SICK) {
            return $this->dayPayload('sick', $targetMinutes, $stored, AttendanceDay::TYPE_SICK);
        }

        if ($holidayName !== null) {
            return $this->dayPayload('holiday', $targetMinutes, $stored, AttendanceDay::TYPE_WORK);
        }

        $minutes = AttendanceHours::workDayMinutes(
            $stored?->morningStart,
            $stored?->morningEnd,
            $stored?->afternoonStart,
            $stored?->afternoonEnd,
        );

        return $this->dayPayload('work', $minutes, $stored, AttendanceDay::TYPE_WORK);
    }

    /**
     * @return array{
     *     kind: string,
     *     worked_minutes: int,
     *     worked_label: string,
     *     morning_start: ?string,
     *     morning_end: ?string,
     *     afternoon_start: ?string,
     *     afternoon_end: ?string,
     *     day_type: string
     * }
     */
    private function dayPayload(string $kind, int $minutes, ?AttendanceDay $stored, string $dayType): array
    {
        return [
            'kind' => $kind,
            'worked_minutes' => $minutes,
            'worked_label' => AttendanceHours::formatMinutesGerman($minutes),
            'morning_start' => $stored?->morningStart,
            'morning_end' => $stored?->morningEnd,
            'afternoon_start' => $stored?->afternoonStart,
            'afternoon_end' => $stored?->afternoonEnd,
            'day_type' => $stored?->dayType ?? $dayType,
        ];
    }

    /**
     * @return list<array{date: string, name: string, source: string}>
     */
    public function holidayList(int $year): array
    {
        $config = $this->config();
        $country = $config['country'];
        $state = $config['state'];
        $resolved = $this->resolvedHolidays($year);
        $overrides = $this->holidays->overridesForYear($country, $state, $year);
        $manualDates = [];

        foreach ($overrides as $override) {
            if ($override['override_type'] === 'add') {
                $manualDates[$override['holiday_date']] = true;
            }
        }

        $list = [];

        foreach ($resolved as $date => $name) {
            $list[] = [
                'date' => $date,
                'name' => $name,
                'source' => isset($manualDates[$date]) ? 'manual' : 'builtin',
            ];
        }

        return $list;
    }
}
