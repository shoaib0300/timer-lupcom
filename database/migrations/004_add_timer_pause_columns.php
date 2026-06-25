<?php

declare(strict_types=1);

use Timer\Database\Migration;

return new class implements Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'ALTER TABLE time_entries
                ADD COLUMN elapsed_offset INT UNSIGNED NOT NULL DEFAULT 0 AFTER duration_seconds,
                ADD COLUMN paused_at DATETIME NULL AFTER elapsed_offset',
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec(
            'ALTER TABLE time_entries
                DROP COLUMN elapsed_offset,
                DROP COLUMN paused_at',
        );
    }
};
