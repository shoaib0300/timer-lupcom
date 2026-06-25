<?php

declare(strict_types=1);

namespace Timer\Core;

use Dotenv\Dotenv;
use PDO;
use PDOException;
use RuntimeException;
use Timer\Http\Request;
use Timer\Http\Response;
use Timer\Support\Translator;

final class Application
{
    private array $config;
    private ?PDO $pdo = null;
    private ?Translator $translator = null;

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
        $this->translator = Translator::fromRequest(
            $this->basePath . '/resources/lang',
            $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? null,
        );

        $request = Request::fromGlobals();
        $router = new Router(require $this->basePath . '/config/routes.php');
        $response = $router->dispatch($request, $this);

        $response->send();
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
