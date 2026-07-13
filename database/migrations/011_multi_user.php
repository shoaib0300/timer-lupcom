<?php

declare(strict_types=1);

use Timer\Database\Migration;

return new class implements Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE users (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                email VARCHAR(255) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                name VARCHAR(120) NOT NULL,
                role ENUM(\'admin\', \'user\') NOT NULL DEFAULT \'user\',
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_users_email (email)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $pdo->exec(
            'CREATE TABLE user_settings (
                user_id INT UNSIGNED NOT NULL,
                setting_key VARCHAR(64) NOT NULL,
                setting_value TEXT NULL,
                PRIMARY KEY (user_id, setting_key),
                CONSTRAINT fk_user_settings_user
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $defaultPassword = password_hash('changeme', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare(
            'INSERT INTO users (email, password_hash, name, role) VALUES (?, ?, ?, ?)',
        );
        $stmt->execute(['admin@local', $defaultPassword, 'Administrator', 'admin']);
        $adminId = (int) $pdo->lastInsertId();

        $pdo->exec('ALTER TABLE time_entries ADD COLUMN user_id INT UNSIGNED NULL AFTER id');
        $pdo->exec('ALTER TABLE office_sessions ADD COLUMN user_id INT UNSIGNED NULL AFTER id');

        $pdo->exec(
            'CREATE TABLE attendance_days_user (
                user_id INT UNSIGNED NOT NULL,
                work_date DATE NOT NULL,
                day_type ENUM(\'work\', \'vacation\', \'sick\') NOT NULL DEFAULT \'work\',
                morning_start TIME NULL,
                morning_end TIME NULL,
                afternoon_start TIME NULL,
                afternoon_end TIME NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id, work_date),
                CONSTRAINT fk_attendance_days_user
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $copyAttendance = $pdo->prepare(
            'INSERT INTO attendance_days_user
                (user_id, work_date, day_type, morning_start, morning_end, afternoon_start, afternoon_end, updated_at)
            SELECT ?, work_date, day_type, morning_start, morning_end, afternoon_start, afternoon_end, updated_at
            FROM attendance_days',
        );
        $copyAttendance->execute([$adminId]);

        $pdo->exec('DROP TABLE attendance_days');
        $pdo->exec('RENAME TABLE attendance_days_user TO attendance_days');

        $pdo->exec('UPDATE time_entries SET user_id = ' . $adminId . ' WHERE user_id IS NULL');
        $pdo->exec('UPDATE office_sessions SET user_id = ' . $adminId . ' WHERE user_id IS NULL');

        $pdo->exec(
            'ALTER TABLE time_entries
                MODIFY user_id INT UNSIGNED NOT NULL,
                ADD INDEX idx_time_entries_user_id (user_id),
                ADD CONSTRAINT fk_time_entries_user
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
        );

        $pdo->exec(
            'ALTER TABLE office_sessions
                MODIFY user_id INT UNSIGNED NOT NULL,
                ADD INDEX idx_office_sessions_user_id (user_id),
                ADD CONSTRAINT fk_office_sessions_user
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE',
        );

        $settingsStmt = $pdo->prepare(
            'INSERT INTO user_settings (user_id, setting_key, setting_value)
            SELECT ?, setting_key, setting_value
            FROM settings
            WHERE setting_key LIKE ?',
        );
        $settingsStmt->execute([$adminId, 'attendance.%']);

        $pdo->exec("DELETE FROM settings WHERE setting_key LIKE 'attendance.%'");
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('ALTER TABLE time_entries DROP FOREIGN KEY fk_time_entries_user');
        $pdo->exec('ALTER TABLE time_entries DROP INDEX idx_time_entries_user_id');
        $pdo->exec('ALTER TABLE time_entries DROP COLUMN user_id');

        $pdo->exec('ALTER TABLE office_sessions DROP FOREIGN KEY fk_office_sessions_user');
        $pdo->exec('ALTER TABLE office_sessions DROP INDEX idx_office_sessions_user_id');
        $pdo->exec('ALTER TABLE office_sessions DROP COLUMN user_id');

        $pdo->exec(
            'CREATE TABLE attendance_days_single (
                work_date DATE NOT NULL PRIMARY KEY,
                day_type ENUM(\'work\', \'vacation\', \'sick\') NOT NULL DEFAULT \'work\',
                morning_start TIME NULL,
                morning_end TIME NULL,
                afternoon_start TIME NULL,
                afternoon_end TIME NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $pdo->exec(
            'INSERT INTO attendance_days_single
                (work_date, day_type, morning_start, morning_end, afternoon_start, afternoon_end, updated_at)
            SELECT work_date, day_type, morning_start, morning_end, afternoon_start, afternoon_end, updated_at
            FROM attendance_days
            ORDER BY user_id ASC, work_date ASC
            ON DUPLICATE KEY UPDATE
                day_type = VALUES(day_type),
                morning_start = VALUES(morning_start),
                morning_end = VALUES(morning_end),
                afternoon_start = VALUES(afternoon_start),
                afternoon_end = VALUES(afternoon_end)',
        );

        $pdo->exec('DROP TABLE attendance_days');
        $pdo->exec('RENAME TABLE attendance_days_single TO attendance_days');

        $pdo->exec('DROP TABLE user_settings');
        $pdo->exec('DROP TABLE users');
    }
};
