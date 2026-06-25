<?php

declare(strict_types=1);

use Timer\Database\Migration;

return new class implements Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE settings (
                setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
                setting_value TEXT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $pdo->exec(
            'ALTER TABLE projects
                ADD COLUMN planio_id INT UNSIGNED NULL AFTER color,
                ADD UNIQUE KEY uq_projects_planio_id (planio_id)',
        );

        $pdo->exec(
            'ALTER TABLE tasks
                ADD COLUMN planio_issue_id INT UNSIGNED NULL AFTER status,
                ADD UNIQUE KEY uq_tasks_planio_issue (project_id, planio_issue_id)',
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE tasks DROP INDEX uq_tasks_planio_issue, DROP COLUMN planio_issue_id');
        $pdo->exec('ALTER TABLE projects DROP INDEX uq_projects_planio_id, DROP COLUMN planio_id');
        $pdo->exec('DROP TABLE IF EXISTS settings');
    }
};
