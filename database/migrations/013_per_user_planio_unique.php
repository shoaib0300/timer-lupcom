<?php

declare(strict_types=1);

use Timer\Database\Migration;

return new class implements Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE projects DROP INDEX uq_projects_planio_id');
        $pdo->exec(
            'ALTER TABLE projects
                ADD UNIQUE KEY uq_projects_user_planio (user_id, planio_id)',
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE projects DROP INDEX uq_projects_user_planio');
        $pdo->exec('ALTER TABLE projects ADD UNIQUE KEY uq_projects_planio_id (planio_id)');
    }
};
