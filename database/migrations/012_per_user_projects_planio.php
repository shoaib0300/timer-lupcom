<?php

declare(strict_types=1);

use Timer\Database\Migration;

return new class implements Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE projects ADD COLUMN user_id INT UNSIGNED NULL AFTER id');

        $adminId = (int) $pdo->query('SELECT id FROM users ORDER BY id ASC LIMIT 1')->fetchColumn();
        if ($adminId > 0) {
            $pdo->exec('UPDATE projects SET user_id = ' . $adminId . ' WHERE user_id IS NULL');

            $stmt = $pdo->prepare(
                'INSERT INTO user_settings (user_id, setting_key, setting_value)
                SELECT ?, setting_key, setting_value
                FROM settings
                WHERE setting_key LIKE ?',
            );
            $stmt->execute([$adminId, 'planio.%']);
        }

        $pdo->exec("DELETE FROM settings WHERE setting_key LIKE 'planio.%'");

        $pdo->exec(
            'ALTER TABLE projects
                MODIFY user_id INT UNSIGNED NOT NULL,
                ADD INDEX idx_projects_user_id (user_id),
                ADD CONSTRAINT fk_projects_user
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
        );
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE projects DROP FOREIGN KEY fk_projects_user');
        $pdo->exec('ALTER TABLE projects DROP INDEX idx_projects_user_id');
        $pdo->exec('ALTER TABLE projects DROP COLUMN user_id');

        $stmt = $pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value)
            SELECT setting_key, setting_value
            FROM user_settings
            WHERE user_id = (SELECT id FROM users ORDER BY id ASC LIMIT 1)
              AND setting_key LIKE ?',
        );
        $stmt->execute(['planio.%']);
    }
};
