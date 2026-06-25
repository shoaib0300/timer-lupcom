<?php

declare(strict_types=1);

namespace Timer\Database;

use PDO;

final class Migrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationsPath,
    ) {
    }

    public function migrate(): void
    {
        $this->ensureMigrationsTable();

        $applied = $this->appliedMigrations();
        $files = glob($this->migrationsPath . '/*.php') ?: [];
        sort($files);

        foreach ($files as $file) {
            $name = basename($file, '.php');

            if (in_array($name, $applied, true)) {
                continue;
            }

            $migration = require $file;
            $migration->up($this->pdo);
            $this->recordMigration($name);

            echo "Migrated: {$name}\n";
        }
    }

    public function rollback(int $steps = 1): void
    {
        $this->ensureMigrationsTable();

        $applied = array_reverse($this->appliedMigrations());
        $files = glob($this->migrationsPath . '/*.php') ?: [];
        $migrations = [];

        foreach ($files as $file) {
            $migrations[basename($file, '.php')] = require $file;
        }

        $rolled = 0;

        foreach ($applied as $name) {
            if ($rolled >= $steps) {
                break;
            }

            if (!isset($migrations[$name])) {
                continue;
            }

            $migrations[$name]->down($this->pdo);
            $this->removeMigration($name);

            echo "Rolled back: {$name}\n";
            $rolled++;
        }
    }

    private function ensureMigrationsTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL UNIQUE,
                applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    /** @return list<string> */
    private function appliedMigrations(): array
    {
        $stmt = $this->pdo->query('SELECT migration FROM migrations ORDER BY id ASC');

        return $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    }

    private function recordMigration(string $name): void
    {
        $stmt = $this->pdo->prepare('INSERT INTO migrations (migration) VALUES (?)');
        $stmt->execute([$name]);
    }

    private function removeMigration(string $name): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM migrations WHERE migration = ?');
        $stmt->execute([$name]);
    }
}
