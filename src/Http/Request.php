<?php

declare(strict_types=1);

namespace Timer\Http;

final class Request
{
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query,
        private readonly array $body,
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        $body = $method === 'POST' ? $_POST : [];

        return new self($method, $path, $_GET, $body);
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->body;
    }

    /** @return array{name: string, type: string, tmp_name: string, error: int, size: int}|null */
    public function file(string $key): ?array
    {
        $file = $_FILES[$key] ?? null;
        if (!is_array($file)) {
            return null;
        }

        // Multi-upload shape: name is an array.
        if (isset($file['name']) && is_array($file['name'])) {
            return null;
        }

        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            return null;
        }

        return [
            'name' => (string) ($file['name'] ?? ''),
            'type' => (string) ($file['type'] ?? ''),
            'tmp_name' => (string) ($file['tmp_name'] ?? ''),
            'error' => $error,
            'size' => (int) ($file['size'] ?? 0),
        ];
    }

    /**
     * @return list<array{name: string, type: string, tmp_name: string, error: int, size: int}>
     */
    public function files(string $key): array
    {
        $file = $_FILES[$key] ?? null;
        if (!is_array($file)) {
            return [];
        }

        if (!isset($file['name']) || !is_array($file['name'])) {
            $single = $this->file($key);

            return $single !== null ? [$single] : [];
        }

        $out = [];
        foreach ($file['name'] as $index => $name) {
            $error = (int) ($file['error'][$index] ?? UPLOAD_ERR_NO_FILE);
            if ($error !== UPLOAD_ERR_OK) {
                continue;
            }

            $out[] = [
                'name' => (string) $name,
                'type' => (string) ($file['type'][$index] ?? ''),
                'tmp_name' => (string) ($file['tmp_name'][$index] ?? ''),
                'error' => $error,
                'size' => (int) ($file['size'][$index] ?? 0),
            ];
        }

        return $out;
    }
}
