<?php

declare(strict_types=1);

use Timer\Database\Migration;
use PDO;

return new class implements Migration {
    public function up(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE time_entries (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                project_id INT UNSIGNED NOT NULL,
                task_id INT UNSIGNED NULL,
                started_at DATETIME NOT NULL,
                ended_at DATETIME NULL,
                duration_seconds INT UNSIGNED NULL,
                notes TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_time_entries_project_id (project_id),
                INDEX idx_time_entries_task_id (task_id),
                INDEX idx_time_entries_started_at (started_at),
                INDEX idx_time_entries_running (ended_at),
                CONSTRAINT fk_time_entries_project
                    FOREIGN KEY (project_id) REFERENCES projects(id)
                    ON DELETE CASCADE,
                CONSTRAINT fk_time_entries_task
                    FOREIGN KEY (task_id) REFERENCES tasks(id)
                    ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    public function down(PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS time_entries');
    }
};
