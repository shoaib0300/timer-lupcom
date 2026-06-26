<?php

declare(strict_types=1);

use Timer\Database\Migration;

return new class implements Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE attendance_days (
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
            'CREATE TABLE attendance_holiday_overrides (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                holiday_date DATE NOT NULL,
                country_code VARCHAR(2) NOT NULL DEFAULT \'DE\',
                state_code VARCHAR(10) NOT NULL DEFAULT \'\',
                override_type ENUM(\'add\', \'remove\') NOT NULL,
                name VARCHAR(128) NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_attendance_holiday_override (holiday_date, country_code, state_code, override_type)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $stmt = $pdo->prepare('INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)');
        $defaults = [
            ['attendance.country', 'DE'],
            ['attendance.state', 'MV'],
            ['attendance.daily_hours', '8'],
            ['attendance.break_minutes', '30'],
        ];
        foreach ($defaults as [$key, $value]) {
            $stmt->execute([$key, $value]);
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS attendance_holiday_overrides');
        $pdo->exec('DROP TABLE IF EXISTS attendance_days');
        $pdo->exec("DELETE FROM settings WHERE setting_key LIKE 'attendance.%'");
    }
};
