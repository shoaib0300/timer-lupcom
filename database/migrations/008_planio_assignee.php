<?php

declare(strict_types=1);

use Timer\Database\Migration;

return new class implements Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'ALTER TABLE tasks
                ADD COLUMN planio_assignee VARCHAR(255) NULL AFTER planio_issue_id',
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE tasks DROP COLUMN planio_assignee');
    }
};
