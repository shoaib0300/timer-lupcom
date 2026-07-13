<?php

declare(strict_types=1);

namespace Timer\Auth;

use Timer\Http\AuthenticatedUser;
use Timer\Models\User;
use Timer\Repositories\UserRepository;

final class SessionAuth
{
    private const string SESSION_USER_KEY = 'auth_user_id';

    /** @param array<string, mixed> $config */
    public static function start(array $config): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $session = $config['session'] ?? [];
        session_name((string) ($session['name'] ?? 'timer_session'));
        session_set_cookie_params([
            'lifetime' => (int) ($session['lifetime'] ?? 0),
            'path' => '/',
            'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
    }

    public static function login(User $user): void
    {
        session_regenerate_id(true);
        $_SESSION[self::SESSION_USER_KEY] = $user->id;
    }

    public static function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'] ?? '',
                (bool) $params['secure'],
                (bool) $params['httponly'],
            );
        }

        session_destroy();
    }

    public static function currentUserId(): ?int
    {
        $id = $_SESSION[self::SESSION_USER_KEY] ?? null;

        return is_int($id) ? $id : (is_numeric($id) ? (int) $id : null);
    }

    public static function resolve(UserRepository $users): ?AuthenticatedUser
    {
        $userId = self::currentUserId();

        if ($userId === null) {
            return null;
        }

        $user = $users->findActive($userId);

        if ($user === null) {
            self::logout();

            return null;
        }

        return new AuthenticatedUser(
            $user->id,
            $user->email,
            $user->name,
            $user->role,
        );
    }
}
