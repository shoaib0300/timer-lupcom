<?php

declare(strict_types=1);

namespace Timer\Controllers;

use DateTimeImmutable;
use Timer\Http\Request;
use Timer\Http\Response;
use Timer\Models\AttendanceDay;
use Timer\Repositories\AttendanceDayRepository;
use Timer\Repositories\AttendanceHolidayRepository;
use Timer\Repositories\SettingsRepository;
use Timer\Services\AttendanceService;
use Timer\Support\GermanHolidays;
use Timer\Support\Locale;

final class AttendanceController extends BaseController
{
    public function index(Request $request): Response
    {
        $month = $this->resolveMonth((string) $request->query('month', ''));
        $service = $this->service();

        $firstDay = new DateTimeImmutable($month . '-01');
        $prevMonth = $firstDay->modify('-1 month')->format('Y-m');
        $nextMonth = $firstDay->modify('+1 month')->format('Y-m');
        $config = $service->config();

        return $this->view('attendance/index.html.twig', [
            'month' => $month,
            'month_label' => Locale::formatMonth($firstDay, $this->app->translator()->locale()),
            'prev_month' => $prevMonth,
            'next_month' => $nextMonth,
            'weeks' => $service->weeksForMonth($month),
            'summary' => $service->monthSummary($month),
            'config' => $config,
            'states' => GermanHolidays::STATES,
            'countries' => ['DE' => 'Deutschland'],
            'daily_hours' => $config['daily_hours'],
            'break_minutes' => $config['break_minutes'],
            'year' => (int) substr($month, 0, 4),
            'holidays' => $service->holidayList((int) substr($month, 0, 4)),
            'flash_success' => $request->query('success'),
            'flash_error' => $request->query('error'),
        ]);
    }

    public function saveSettings(Request $request): Response
    {
        $country = strtoupper(trim((string) $request->input('country', 'DE')));
        $state = strtoupper(trim((string) $request->input('state', 'MV')));
        $month = $this->resolveMonth((string) $request->input('month', ''));

        if ($country !== 'DE') {
            return $this->redirect('/attendance?month=' . $month . '&error=country');
        }

        if (!isset(GermanHolidays::STATES[$state])) {
            return $this->redirect('/attendance?month=' . $month . '&error=state');
        }

        $this->service()->saveConfig($country, $state);

        return $this->redirect('/attendance?month=' . $month . '&success=settings');
    }

    public function saveDay(Request $request): Response
    {
        $date = (string) $request->input('date', '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return $this->json(['error' => 'invalid_date'], 422);
        }

        $dayType = (string) $request->input('day_type', AttendanceDay::TYPE_WORK);
        if (!in_array($dayType, [AttendanceDay::TYPE_WORK, AttendanceDay::TYPE_VACATION, AttendanceDay::TYPE_SICK], true)) {
            return $this->json(['error' => 'invalid_type'], 422);
        }

        $morningStart = $this->normalizeTime($request->input('morning_start'));
        $morningEnd = $this->normalizeTime($request->input('morning_end'));
        $afternoonStart = $this->normalizeTime($request->input('afternoon_start'));
        $afternoonEnd = $this->normalizeTime($request->input('afternoon_end'));

        if ($dayType === AttendanceDay::TYPE_WORK) {
            foreach ([
                'morning_start' => $request->input('morning_start'),
                'morning_end' => $request->input('morning_end'),
                'afternoon_start' => $request->input('afternoon_start'),
                'afternoon_end' => $request->input('afternoon_end'),
            ] as $field => $raw) {
                if ($raw !== null && trim((string) $raw) !== '' && $this->normalizeTime($raw) === null) {
                    return $this->json(['error' => 'invalid_time', 'field' => $field], 422);
                }
            }
        }

        $repo = new AttendanceDayRepository($this->app->db());

        if ($dayType === AttendanceDay::TYPE_WORK
            && $morningStart === null
            && $morningEnd === null
            && $afternoonStart === null
            && $afternoonEnd === null
        ) {
            $repo->delete($date);
        } else {
            if (in_array($dayType, [AttendanceDay::TYPE_VACATION, AttendanceDay::TYPE_SICK], true)) {
                $morningStart = null;
                $morningEnd = null;
                $afternoonStart = null;
                $afternoonEnd = null;
            }

            $repo->save($date, $dayType, $morningStart, $morningEnd, $afternoonStart, $afternoonEnd);
        }

        $service = $this->service();
        $month = substr($date, 0, 7);

        return $this->json([
            'ok' => true,
            'summary' => $service->monthSummary($month),
            'weeks' => $service->weeksForMonth($month),
        ]);
    }

    public function addHoliday(Request $request): Response
    {
        $date = (string) $request->input('date', '');
        $name = trim((string) $request->input('name', ''));

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || $name === '') {
            return $this->json(['error' => 'invalid'], 422);
        }

        $config = $this->service()->config();
        new AttendanceHolidayRepository($this->app->db())->addManual(
            $date,
            $config['country'],
            $config['state'],
            $name,
        );

        return $this->json([
            'ok' => true,
            'holidays' => $this->service()->holidayList((int) substr($date, 0, 4)),
        ]);
    }

    public function removeHoliday(Request $request): Response
    {
        $date = (string) $request->input('date', '');
        $action = (string) $request->input('action', 'delete');

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            return $this->json(['error' => 'invalid_date'], 422);
        }

        $config = $this->service()->config();
        $repo = new AttendanceHolidayRepository($this->app->db());

        if ($action === 'restore') {
            $repo->restoreBuiltin($date, $config['country'], $config['state']);
        } elseif ($action === 'exclude') {
            $repo->removeBuiltin($date, $config['country'], $config['state']);
        } else {
            $repo->deleteManual($date, $config['country'], $config['state']);
        }

        return $this->json([
            'ok' => true,
            'holidays' => $this->service()->holidayList((int) substr($date, 0, 4)),
        ]);
    }

    private function service(): AttendanceService
    {
        $db = $this->app->db();

        return new AttendanceService(
            new SettingsRepository($db),
            new AttendanceDayRepository($db),
            new AttendanceHolidayRepository($db),
        );
    }

    private function resolveMonth(string $month): string
    {
        if (preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            return $month;
        }

        return (new DateTimeImmutable())->format('Y-m');
    }

    private function normalizeTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $time = trim((string) $value);
        if ($time === '00:00') {
            return null;
        }

        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $time, $matches) !== 1) {
            return null;
        }

        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];

        if ($hours > 23 || $minutes > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hours, $minutes);
    }
}
