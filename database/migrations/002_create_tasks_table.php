<?php

declare(strict_types=1);

use Timer\Database\Migration;

return new class implements Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE tasks (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                project_id INT UNSIGNED NOT NULL,
                name VARCHAR(255) NOT NULL,
                description TEXT NULL,
                status ENUM("open", "in_progress", "done") NOT NULL DEFAULT "open",
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_tasks_project_id (project_id),
                INDEX idx_tasks_status (status),
                CONSTRAINT fk_tasks_project
                    FOREIGN KEY (project_id) REFERENCES projects(id)
                    ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS tasks');
    }
};
