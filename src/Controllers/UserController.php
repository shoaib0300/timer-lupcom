<?php

declare(strict_types=1);

namespace Timer\Controllers;

use Timer\Http\Request;
use Timer\Http\Response;
use Timer\Repositories\UserSettingsRepository;

final class UserController extends BaseController
{
    public function index(Request $request): Response
    {
        return $this->view('users/index.html.twig', [
            'users' => $this->users()->all(),
            'active_nav' => 'users',
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
        } catch (\InvalidArgumentException $exception) {
            return $this->view('users/index.html.twig', [
                'users' => $this->users()->all(),
                'error' => $exception->getMessage(),
                'form' => compact('name', 'email', 'role'),
                'active_nav' => 'users',
            ]);
        }

        return $this->redirect('/settings/users');
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
}
