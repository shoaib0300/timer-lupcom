<?php

declare(strict_types=1);

namespace Timer\Controllers;

use Timer\Auth\SessionAuth;
use Timer\Http\Request;
use Timer\Http\Response;
use Timer\Repositories\AttendanceDayRepository;
use Timer\Repositories\UserSettingsRepository;
use Timer\Services\AttendanceImportService;
use Timer\Services\LupcomTimetableParser;
use Timer\Services\LupcomXlsxTimetableReader;

final class AuthController extends BaseController
{
    public function showLogin(Request $request): Response
    {
        return $this->view('auth/login.html.twig', [
            'redirect' => (string) $request->query('redirect', '/'),
        ]);
    }

    public function login(Request $request): Response
    {
        if ($response = $this->validateCsrfOrRedirect($request, '/login')) {
            return $response;
        }

        $email = trim((string) $request->input('email', ''));
        $password = (string) $request->input('password', '');
        $redirect = (string) $request->input('redirect', '/');

        if ($email === '' || $password === '') {
            return $this->view('auth/login.html.twig', [
                'error' => $this->trans('auth.missing_credentials'),
                'email' => $email,
                'redirect' => $redirect,
            ]);
        }

        $users = $this->users();
        $user = $users->findByEmail($email);

        if ($user === null || !$user->isActive || !$users->verifyPassword($user, $password)) {
            return $this->view('auth/login.html.twig', [
                'error' => $this->trans('auth.invalid_credentials'),
                'email' => $email,
                'redirect' => $redirect,
            ]);
        }

        SessionAuth::login($user);
        (new UserSettingsRepository($this->app->db(), $user->id))->seedAttendanceDefaults();

        return $this->redirect($this->safeRedirect($redirect));
    }

    public function showRegister(Request $request): Response
    {
        if ($this->app->needsSetup()) {
            return $this->redirect('/setup');
        }

        return $this->view('auth/register.html.twig');
    }

    public function register(Request $request): Response
    {
        if ($this->app->needsSetup()) {
            return $this->redirect('/setup');
        }

        if ($response = $this->validateCsrfOrRedirect($request, '/register')) {
            return $response;
        }

        $result = $this->createAccountFromRequest($request, 'auth/register.html.twig', 'user');

        if ($result instanceof Response) {
            return $result;
        }

        return $this->redirect('/settings/planio?welcome=1');
    }

    public function logout(Request $request): Response
    {
        if ($request->method() === 'POST') {
            if ($response = $this->validateCsrfOrRedirect($request, '/')) {
                return $response;
            }
        }

        SessionAuth::logout();

        return $this->redirect('/login');
    }

    public function showSetup(Request $request): Response
    {
        if (!$this->app->needsSetup()) {
            return $this->redirect('/login');
        }

        return $this->view('auth/setup.html.twig');
    }

    public function setup(Request $request): Response
    {
        if (!$this->app->needsSetup()) {
            return $this->redirect('/login');
        }

        if ($response = $this->validateCsrfOrRedirect($request, '/setup')) {
            return $response;
        }

        $result = $this->createAccountFromRequest($request, 'auth/setup.html.twig', 'admin');

        if ($result instanceof Response) {
            return $result;
        }

        $importError = $this->importOptionalTimetable($request);

        return $this->redirect(
            $importError !== null
                ? '/settings/planio?welcome=1&setup_import_error=1'
                : '/settings/planio?welcome=1',
        );
    }

    private function importOptionalTimetable(Request $request): ?string
    {
        $file = $request->file('timetable');
        if ($file === null) {
            return null;
        }

        if (!is_readable($file['tmp_name']) || filesize($file['tmp_name']) === 0) {
            return 'empty_file';
        }

        $importService = new AttendanceImportService(
            new AttendanceDayRepository($this->app->db(), $this->requireUser()->id),
            new LupcomTimetableParser(),
            new LupcomXlsxTimetableReader(),
        );

        try {
            $importService->importFile(
                $file['tmp_name'],
                (string) ($file['name'] ?? ''),
                AttendanceImportService::MODE_MERGE,
            );
        } catch (\Throwable) {
            return 'import_failed';
        }

        return null;
    }

    private function createAccountFromRequest(
        Request $request,
        string $errorTemplate,
        string $role,
    ): true|Response {
        $name = trim((string) $request->input('name', ''));
        $email = trim((string) $request->input('email', ''));
        $password = (string) $request->input('password', '');
        $passwordConfirm = (string) $request->input('password_confirmation', '');

        $formData = [
            'name' => $name,
            'email' => $email,
        ];

        if ($name === '' || $email === '' || $password === '') {
            return $this->view($errorTemplate, array_merge($formData, [
                'error' => $this->trans('auth.setup_missing_fields'),
            ]));
        }

        if ($password !== $passwordConfirm) {
            return $this->view($errorTemplate, array_merge($formData, [
                'error' => $this->trans('auth.password_mismatch'),
            ]));
        }

        if (strlen($password) < 8) {
            return $this->view($errorTemplate, array_merge($formData, [
                'error' => $this->trans('auth.password_too_short'),
            ]));
        }

        try {
            $userId = $this->users()->create($email, $password, $name, $role);
        } catch (\InvalidArgumentException $exception) {
            return $this->view($errorTemplate, array_merge($formData, [
                'error' => $exception->getMessage(),
            ]));
        }

        $user = $this->users()->find($userId);
        if ($user !== null) {
            SessionAuth::login($user);
            (new UserSettingsRepository($this->app->db(), $user->id))->seedAttendanceDefaults();
        }

        return true;
    }

    private function safeRedirect(string $redirect): string
    {
        if ($redirect === '' || !str_starts_with($redirect, '/')) {
            return '/';
        }

        return $redirect;
    }
}
