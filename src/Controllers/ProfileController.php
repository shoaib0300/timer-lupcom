<?php

declare(strict_types=1);

namespace Timer\Controllers;

use Timer\Auth\SessionAuth;
use Timer\Http\Request;
use Timer\Http\Response;
use Timer\Repositories\UserRepository;
use Timer\Support\ProfileAvatar;

final class ProfileController extends BaseController
{
    public function index(Request $request): Response
    {
        $user = $this->users()->find($this->requireUser()->id);
        if ($user === null) {
            return Response::html('User not found', 404);
        }

        return $this->view('settings/profile.html.twig', [
            'profile_user' => $user,
            'avatar_url' => ProfileAvatar::publicUrl($this->userSettings()->avatarFilename()),
            'flash_success' => $request->query('success'),
            'flash_error' => $request->query('error'),
            'active_nav' => 'profile',
        ]);
    }

    public function update(Request $request): Response
    {
        if ($response = $this->validateCsrfOrRedirect($request, '/settings/profile')) {
            return $response;
        }

        $userId = $this->requireUser()->id;
        $name = trim((string) $request->input('name', ''));
        $email = trim((string) $request->input('email', ''));

        try {
            $this->users()->updateProfile($userId, $name, $email);
        } catch (\InvalidArgumentException $exception) {
            return $this->redirectWith('/settings/profile', 'error', $exception->getMessage());
        }

        $user = $this->users()->find($userId);
        if ($user !== null) {
            SessionAuth::login($user);
        }

        return $this->redirectWith('/settings/profile', 'success', $this->trans('profile.saved'));
    }

    public function updatePassword(Request $request): Response
    {
        if ($response = $this->validateCsrfOrRedirect($request, '/settings/profile')) {
            return $response;
        }

        $users = $this->users();
        $user = $users->find($this->requireUser()->id);
        if ($user === null) {
            return Response::html('User not found', 404);
        }

        $current = (string) $request->input('current_password', '');
        $password = (string) $request->input('password', '');
        $confirm = (string) $request->input('password_confirmation', '');

        if (!$users->verifyPassword($user, $current)) {
            return $this->redirectWith('/settings/profile', 'error', $this->trans('profile.current_password_invalid'));
        }

        if ($password === '' || $password !== $confirm) {
            return $this->redirectWith('/settings/profile', 'error', $this->trans('auth.password_mismatch'));
        }

        if (strlen($password) < 8) {
            return $this->redirectWith('/settings/profile', 'error', $this->trans('auth.password_too_short'));
        }

        $users->updatePassword($user->id, $password);

        return $this->redirectWith('/settings/profile', 'success', $this->trans('profile.password_saved'));
    }

    public function updateAvatar(Request $request): Response
    {
        if ($response = $this->validateCsrfOrRedirect($request, '/settings/profile')) {
            return $response;
        }

        $file = $request->file('avatar');
        if ($file === null) {
            return $this->redirectWith('/settings/profile', 'error', $this->trans('profile.avatar_missing'));
        }

        $settings = $this->userSettings();
        $userId = $this->requireUser()->id;

        try {
            ProfileAvatar::deleteFile($settings->avatarFilename());
            $filename = ProfileAvatar::storeUpload($userId, $file);
            $settings->setAvatarFilename($filename);
        } catch (\Throwable $exception) {
            return $this->redirectWith('/settings/profile', 'error', $exception->getMessage());
        }

        return $this->redirectWith('/settings/profile', 'success', $this->trans('profile.avatar_saved'));
    }

    public function removeAvatar(Request $request): Response
    {
        if ($response = $this->validateCsrfOrRedirect($request, '/settings/profile')) {
            return $response;
        }

        $settings = $this->userSettings();
        ProfileAvatar::deleteFile($settings->avatarFilename());
        $settings->setAvatarFilename(null);

        return $this->redirectWith('/settings/profile', 'success', $this->trans('profile.avatar_removed'));
    }

    private function redirectWith(string $path, string $type, string $message): Response
    {
        return $this->redirect($path . '?' . http_build_query([$type => $message]));
    }
}
