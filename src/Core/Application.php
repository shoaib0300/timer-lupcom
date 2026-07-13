<?php

declare(strict_types=1);

namespace Timer\Core;

use Dotenv\Dotenv;
use PDO;
use PDOException;
use RuntimeException;
use Timer\Auth\RouteGuard;
use Timer\Auth\SessionAuth;
use Timer\Http\AuthenticatedUser;
use Timer\Http\Request;
use Timer\Http\Response;
use Timer\Repositories\UserRepository;
use Timer\Support\Translator;

final class Application
{
    private array $config;
    private ?PDO $pdo = null;
    private ?Translator $translator = null;
    private ?AuthenticatedUser $authenticatedUser = null;
    private bool $authResolved = false;

    public function __construct(
        private readonly string $basePath,
    ) {
        $this->loadEnvironment();
        $this->config = [
            'app' => require $this->basePath . '/config/app.php',
            'database' => require $this->basePath . '/config/database.php',
        ];

        date_default_timezone_set($this->config['app']['timezone']);
    }

    public function run(): void
    {
        SessionAuth::start($this->config['app']);

        $this->translator = Translator::fromRequest(
            $this->basePath . '/resources/lang',
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null,
        );

        $request = Request::fromGlobals();
        $router = new Router(require $this->basePath . '/config/routes.php');
        $guard = new RouteGuard($this->needsSetup());
        $guardResponse = $guard->beforeDispatch($request, $this->authenticatedUser());

        if ($guardResponse !== null) {
            $guardResponse->send();

            return;
        }

        $response = $router->dispatch($request, $this, $this->authenticatedUser());
        $response->send();
    }

    public function authenticatedUser(): ?AuthenticatedUser
    {
        if (!$this->authResolved) {
            $this->authenticatedUser = SessionAuth::resolve(new UserRepository($this->db()));
            $this->authResolved = true;
        }

        return $this->authenticatedUser;
    }

    public function needsSetup(): bool
    {
        return (new UserRepository($this->db()))->countAll() === 0;
    }

    public function translator(): Translator
    {
        if ($this->translator === null) {
            $this->translator = Translator::fromRequest(
                $this->basePath . '/resources/lang',
                null,
            );
        }

        return $this->translator;
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    public function config(string $key): array
    {
        return $this->config[$key] ?? [];
    }

    public function db(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = Database::connect($this->config['database']['url']);
        }

        return $this->pdo;
    }

    public function view(): View
    {
        return new View(
            $this->config['app']['views_path'],
            $this->config['app']['debug'],
            $this->translator(),
        );
    }

    private function loadEnvironment(): void
    {
        if (!is_file($this->basePath . '/.env')) {
            return;
        }

        $dotenv = Dotenv::createImmutable($this->basePath);
        $dotenv->safeLoad();
    }
}
