<?php

declare(strict_types=1);

use Timer\Database\Migration;

return new class implements Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'ALTER TABLE time_entries
                ADD COLUMN planio_time_entry_id INT UNSIGNED NULL AFTER notes,
                ADD UNIQUE KEY uq_time_entries_user_planio (user_id, planio_time_entry_id)',
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec(
            'ALTER TABLE time_entries
                DROP INDEX uq_time_entries_user_planio,
                DROP COLUMN planio_time_entry_id',
        );
    }
};
