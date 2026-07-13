<?php

declare(strict_types=1);

namespace Timer\Auth;

use Timer\Http\AuthenticatedUser;
use Timer\Http\Request;
use Timer\Http\Response;

final class RouteGuard
{
    /** @var list<array{0: string, 1: string}> */
    private const array PUBLIC_ROUTES = [
        ['GET', '/login'],
        ['POST', '/login'],
        ['GET', '/register'],
        ['POST', '/register'],
        ['GET', '/setup'],
        ['POST', '/setup'],
    ];

    /** @var list<array{0: string, 1: string}> */
    private const array ADMIN_ROUTES = [
        ['GET', '/settings/users'],
        ['POST', '/settings/users'],
        ['POST', '/settings/users/deactivate'],
        ['POST', '/settings/users/activate'],
    ];

    public function __construct(
        private readonly bool $needsSetup,
    ) {
    }

    public function beforeDispatch(
        Request $request,
        ?AuthenticatedUser $user,
    ): ?Response {
        $method = $request->method();
        $path = $request->path();

        if ($this->needsSetup) {
            if ($this->matches($method, $path, [['GET', '/setup'], ['POST', '/setup']])) {
                return null;
            }

            return Response::redirect('/setup');
        }

        if ($this->isPublicRoute($method, $path)) {
            if ($user !== null && in_array($path, ['/login', '/register'], true)) {
                return Response::redirect('/');
            }

            return null;
        }

        if ($user === null) {
            $target = $path !== '/' ? '?redirect=' . rawurlencode($path) : '';

            return Response::redirect('/login' . $target);
        }

        if ($this->isAdminRoute($method, $path) && !$user->isAdmin()) {
            return Response::html('Forbidden', 403);
        }

        if ($method === 'POST' && !$this->isCsrfExempt($path) && !Csrf::validate((string) ($_POST['_token'] ?? ''))) {
            if (str_starts_with($path, '/api/')) {
                return Response::json(['error' => 'invalid_csrf'], 419);
            }

            return Response::html('Session expired. Please go back and try again.', 419);
        }

        return null;
    }

    private function isCsrfExempt(string $path): bool
    {
        return in_array($path, ['/login', '/register', '/setup'], true);
    }

    private function isPublicRoute(string $method, string $path): bool
    {
        return $this->matches($method, $path, self::PUBLIC_ROUTES);
    }

    private function isAdminRoute(string $method, string $path): bool
    {
        return $this->matches($method, $path, self::ADMIN_ROUTES);
    }

    /**
     * @param list<array{0: string, 1: string}> $routes
     */
    private function matches(string $method, string $path, array $routes): bool
    {
        foreach ($routes as [$routeMethod, $routePath]) {
            if ($routeMethod === $method && $routePath === $path) {
                return true;
            }
        }

        return false;
    }
}
