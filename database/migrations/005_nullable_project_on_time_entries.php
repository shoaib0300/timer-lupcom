<?php

declare(strict_types=1);

use Timer\Database\Migration;

return new class implements Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE time_entries DROP FOREIGN KEY fk_time_entries_project');
        $pdo->exec(
            'ALTER TABLE time_entries
                MODIFY project_id INT UNSIGNED NULL',
        );
        $pdo->exec(
            'ALTER TABLE time_entries
                ADD CONSTRAINT fk_time_entries_project
                    FOREIGN KEY (project_id) REFERENCES projects(id)
                    ON DELETE CASCADE',
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE time_entries DROP FOREIGN KEY fk_time_entries_project');
        $pdo->exec(
            'DELETE FROM time_entries WHERE project_id IS NULL',
        );
        $pdo->exec(
            'ALTER TABLE time_entries
                MODIFY project_id INT UNSIGNED NOT NULL',
        );
        $pdo->exec(
            'ALTER TABLE time_entries
                ADD CONSTRAINT fk_time_entries_project
                    FOREIGN KEY (project_id) REFERENCES projects(id)
                    ON DELETE CASCADE',
        );
    }
};
