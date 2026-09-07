<?php

declare(strict_types=1);

namespace Timer\Controllers;

use DateTimeImmutable;
use Timer\Http\Request;
use Timer\Http\Response;
use Timer\Repositories\UserSettingsRepository;
use Timer\Services\SollWorkingTimeService;
use Timer\Support\AttendanceHours;

final class UserController extends BaseController
{
    public function index(Request $request): Response
    {
        return $this->view('users/index.html.twig', [
            'users' => $this->usersWithWorkingHours(),
            'weekday_options' => $this->weekdayOptions(),
            'today' => (new DateTimeImmutable('today'))->format('Y-m-d'),
            'active_nav' => 'users',
            'flash_success' => $this->pullFlash('success'),
            'flash_error' => $this->pullFlash('error'),
        ]);
    }

    public function store(Request $request): Response
    {
        if ($response = $this->validateCsrfOrRedirect($request, '/settings/users')) {
            return $response;
        }

        $name = trim((string) $request->input('name', ''));
        $email = trim((string) $request->input('email', ''));
        $password = (string) $request->input('password', '');
        $role = (string) $request->input('role', 'user');

        try {
            if ($password === '') {
                throw new \InvalidArgumentException($this->trans('auth.password_required'));
            }

            if (strlen($password) < 8) {
                throw new \InvalidArgumentException($this->trans('auth.password_too_short'));
            }

            $userId = $this->users()->create($email, $password, $name, $role);
            (new UserSettingsRepository($this->app->db(), $userId))->seedAttendanceDefaults();
            $this->workingHours()->ensureDefault($userId, 8);
        } catch (\InvalidArgumentException $exception) {
            return $this->view('users/index.html.twig', [
                'users' => $this->usersWithWorkingHours(),
                'weekday_options' => $this->weekdayOptions(),
                'today' => (new DateTimeImmutable('today'))->format('Y-m-d'),
                'error' => $exception->getMessage(),
                'form' => compact('name', 'email', 'role'),
                'active_nav' => 'users',
            ]);
        }

        return $this->redirect('/settings/users');
    }

    public function saveWorkingHours(Request $request): Response
    {
        if ($response = $this->validateCsrfOrRedirect($request, '/settings/users')) {
            return $response;
        }

        $userId = (int) $request->input('user_id', 0);
        $user = $userId > 0 ? $this->users()->find($userId) : null;
        if ($user === null) {
            return $this->redirectWithFlash('/settings/users', 'error', $this->trans('users.working_hours_error'));
        }

        $weeklyMinutes = SollWorkingTimeService::parseWeeklyHoursInput(
            trim((string) $request->input('weekly_hours', '')),
        );
        if ($weeklyMinutes === null) {
            return $this->redirectWithFlash('/settings/users', 'error', $this->trans('attendance.error.weekly_hours'));
        }

        $weekdaysRaw = $request->input('working_weekdays', []);
        if (!is_array($weekdaysRaw)) {
            $weekdaysRaw = [];
        }
        $weekdays = array_values(array_unique(array_map('intval', $weekdaysRaw)));
        if ($weeklyMinutes > 0 && $weekdays === []) {
            return $this->redirectWithFlash('/settings/users', 'error', $this->trans('attendance.error.working_days'));
        }

        $effectiveFrom = trim((string) $request->input('effective_from', ''));
        if ($effectiveFrom === '' || preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveFrom) !== 1) {
            $effectiveFrom = (new DateTimeImmutable('today'))->format('Y-m-d');
        }

        try {
            $this->attendanceService($userId)->saveWorkingHours($weeklyMinutes, $weekdays, $effectiveFrom);
        } catch (\InvalidArgumentException) {
            return $this->redirectWithFlash('/settings/users', 'error', $this->trans('users.working_hours_error'));
        }

        return $this->redirectWithFlash('/settings/users', 'success', $this->trans('users.working_hours_saved'));
    }

    public function deactivate(Request $request): Response
    {
        if ($response = $this->validateCsrfOrRedirect($request, '/settings/users')) {
            return $response;
        }

        $userId = (int) $request->input('user_id', 0);
        $current = $this->requireUser();

        if ($userId > 0 && $userId !== $current->id) {
            $this->users()->setActive($userId, false);
        }

        return $this->redirect('/settings/users');
    }

    public function activate(Request $request): Response
    {
        if ($response = $this->validateCsrfOrRedirect($request, '/settings/users')) {
            return $response;
        }

        $userId = (int) $request->input('user_id', 0);

        if ($userId > 0) {
            $this->users()->setActive($userId, true);
        }

        return $this->redirect('/settings/users');
    }

    /** @return list<array<string, mixed>> */
    private function usersWithWorkingHours(): array
    {
        $weekdayLabels = $this->weekdayOptions();
        $rows = [];

        foreach ($this->users()->all() as $user) {
            $profile = $this->workingHours()->ensureDefault($user->id, 8);
            $days = array_map(
                static fn (int $d): string => $weekdayLabels[$d] ?? (string) $d,
                $profile->workingWeekdays,
            );

            $rows[] = [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'isAdmin' => $user->isAdmin(),
                'isActive' => $user->isActive,
                'weekly_label' => AttendanceHours::formatMinutesClock($profile->weeklyMinutes),
                'daily_label' => AttendanceHours::formatMinutesClock($profile->dailyMinutes()),
                'working_weekdays' => $profile->workingWeekdays,
                'working_days_label' => $days !== [] ? implode('–', [reset($days), end($days)]) : '—',
                'working_days_short' => implode(', ', $days),
            ];
        }

        return $rows;
    }

    /** @return array<int, string> */
    private function weekdayOptions(): array
    {
        return [
            1 => $this->trans('attendance.weekday.mon'),
            2 => $this->trans('attendance.weekday.tue'),
            3 => $this->trans('attendance.weekday.wed'),
            4 => $this->trans('attendance.weekday.thu'),
            5 => $this->trans('attendance.weekday.fri'),
            6 => $this->trans('attendance.weekday.sat'),
            7 => $this->trans('attendance.weekday.sun'),
        ];
    }
}
