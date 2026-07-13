<?php

declare(strict_types=1);

namespace Timer\Controllers;

use Timer\Auth\Csrf;
use Timer\Core\Application;
use Timer\Http\AuthenticatedUser;
use Timer\Http\Request;
use Timer\Http\Response;
use Timer\Repositories\AttendanceDayRepository;
use Timer\Repositories\AttendanceHolidayRepository;
use Timer\Repositories\OfficeSessionRepository;
use Timer\Repositories\ProjectRepository;
use Timer\Repositories\SettingsRepository;
use Timer\Repositories\TaskRepository;
use Timer\Repositories\TimeEntryRepository;
use Timer\Repositories\UserRepository;
use Timer\Repositories\UserSettingsRepository;
use Timer\Services\AttendanceService;
use Timer\Services\OfficeSessionService;
use Timer\Services\TimerService;
use Timer\Services\PlanioSyncService;
use Timer\Services\PlanioTimeImportService;
use Timer\Support\ProfileAvatar;

abstract class BaseController
{
    public function __construct(
        protected readonly Application $app,
        protected readonly ?AuthenticatedUser $user = null,
    ) {
    }

    protected function view(string $template, array $data = []): Response
    {
        $shared = [
            'current_user' => $this->user,
            'csrf_token' => Csrf::token(),
        ];

        if ($this->user !== null) {
            $settings = new UserSettingsRepository($this->app->db(), $this->user->id);
            $shared['profile_avatar_url'] = ProfileAvatar::publicUrl($settings->avatarFilename());
            $shared['planio_configured'] = $settings->isPlanioConfigured();
        }

        return $this->app->view()->render($template, array_merge($shared, $data));
    }

    /** @param array<string, scalar> $params */
    protected function trans(string $key, array $params = []): string
    {
        return $this->app->translator()->trans($key, $params);
    }

    protected function redirect(string $path): Response
    {
        return Response::redirect($path);
    }

    protected function json(array $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }

    protected function requireUser(): AuthenticatedUser
    {
        if ($this->user === null) {
            throw new \RuntimeException('Authenticated user required.');
        }

        return $this->user;
    }

    protected function validateCsrf(Request $request): ?Response
    {
        $token = (string) $request->input('_token', '');

        if (!Csrf::validate($token)) {
            return $this->json(['error' => $this->trans('auth.invalid_csrf')], 419);
        }

        return null;
    }

    protected function validateCsrfOrRedirect(Request $request, string $fallback = '/'): ?Response
    {
        $token = (string) $request->input('_token', '');

        if (!Csrf::validate($token)) {
            return $this->redirect($fallback);
        }

        return null;
    }

    protected function users(): UserRepository
    {
        return new UserRepository($this->app->db());
    }

    protected function settings(): SettingsRepository
    {
        return new SettingsRepository($this->app->db());
    }

    protected function userSettings(): UserSettingsRepository
    {
        return new UserSettingsRepository($this->app->db(), $this->requireUser()->id);
    }

    protected function timeEntries(): TimeEntryRepository
    {
        return new TimeEntryRepository($this->app->db(), $this->requireUser()->id);
    }

    protected function globalTimeEntries(): TimeEntryRepository
    {
        return new TimeEntryRepository($this->app->db());
    }

    protected function officeSessions(): OfficeSessionRepository
    {
        return new OfficeSessionRepository($this->app->db(), $this->requireUser()->id);
    }

    protected function attendanceDays(): AttendanceDayRepository
    {
        return new AttendanceDayRepository($this->app->db(), $this->requireUser()->id);
    }

    protected function projects(): ProjectRepository
    {
        return new ProjectRepository($this->app->db(), $this->requireUser()->id);
    }

    protected function tasks(): TaskRepository
    {
        return new TaskRepository($this->app->db(), $this->requireUser()->id);
    }

    protected function planioSync(): PlanioSyncService
    {
        return new PlanioSyncService(
            $this->userSettings(),
            $this->projects(),
            $this->tasks(),
        );
    }

    protected function planioTimeImport(): PlanioTimeImportService
    {
        return new PlanioTimeImportService(
            $this->userSettings(),
            $this->projects(),
            $this->tasks(),
            $this->timeEntries(),
        );
    }

    protected function timerService(): TimerService
    {
        return new TimerService($this->timeEntries(), $this->tasks());
    }

    protected function officeService(): OfficeSessionService
    {
        return new OfficeSessionService($this->officeSessions(), $this->timeEntries());
    }

    protected function attendanceService(): AttendanceService
    {
        return new AttendanceService(
            $this->userSettings(),
            $this->attendanceDays(),
            new AttendanceHolidayRepository($this->app->db()),
        );
    }
}
