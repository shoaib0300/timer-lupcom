<?php

declare(strict_types=1);

namespace Timer\Core;

use PDO;
use PDOException;
use RuntimeException;

final class Database
{
    public static function connect(string $databaseUrl): PDO
    {
        $parts = parse_url($databaseUrl);

        if ($parts === false || !isset($parts['scheme'], $parts['host'], $parts['user'], $parts['pass'])) {
            throw new RuntimeException('Invalid DATABASE_URL format.');
        }

        $dbname = ltrim($parts['path'] ?? '/db', '/');
        $port = $parts['port'] ?? 3306;
        $dsn = sprintf(
            '%s:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $parts['scheme'],
            $parts['host'],
            $port,
            $dbname,
        );

        try {
            $pdo = new PDO($dsn, $parts['user'], $parts['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (PDOException $e) {
            throw new RuntimeException('Database connection failed: ' . $e->getMessage(), 0, $e);
        }

        return $pdo;
    }
}
