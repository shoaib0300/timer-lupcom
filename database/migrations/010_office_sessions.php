<?php

declare(strict_types=1);

use Timer\Database\Migration;

return new class implements Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE office_sessions (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                work_date DATE NOT NULL,
                started_at DATETIME NOT NULL,
                ended_at DATETIME NULL,
                duration_seconds INT UNSIGNED NULL,
                elapsed_offset INT UNSIGNED NOT NULL DEFAULT 0,
                paused_at DATETIME NULL,
                unassigned_seconds INT UNSIGNED NULL,
                gap_entry_id INT UNSIGNED NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_office_sessions_work_date (work_date),
                INDEX idx_office_sessions_started_at (started_at),
                INDEX idx_office_sessions_running (ended_at),
                CONSTRAINT fk_office_sessions_gap_entry
                    FOREIGN KEY (gap_entry_id) REFERENCES time_entries(id)
                    ON DELETE SET NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS office_sessions');
    }
};
