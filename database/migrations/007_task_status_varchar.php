<?php

declare(strict_types=1);

use Timer\Database\Migration;

return new class implements Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'ALTER TABLE tasks
                MODIFY status VARCHAR(100) NOT NULL DEFAULT "open"',
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec(
            'ALTER TABLE tasks
                MODIFY status ENUM("open", "in_progress", "done") NOT NULL DEFAULT "open"',
        );
    }
};
