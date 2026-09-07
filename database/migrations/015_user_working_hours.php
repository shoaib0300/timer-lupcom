<?php

declare(strict_types=1);

use Timer\Database\Migration;

/**
 * Contractual weekly working hours with effective dating.
 *
 * Migration strategy:
 * - Timetable rows in attendance_days remain Ist (observed) times only.
 * - Existing attendance.daily_hours seeds weekly_minutes = daily_hours * 5 for Mon–Fri.
 * - Historical Soll uses effective_from / effective_until; new saves close prior open-ended rows.
 */
return new class implements Migration {
    public function up(\PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE user_working_hours (
                id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                weekly_minutes INT UNSIGNED NOT NULL,
                working_weekdays JSON NOT NULL,
                effective_from DATE NOT NULL,
                effective_until DATE NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uq_user_working_hours_from (user_id, effective_from),
                KEY idx_user_working_hours_user (user_id),
                CONSTRAINT fk_user_working_hours_user
                    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
        );

        $users = $pdo->query('SELECT id FROM users')->fetchAll(\PDO::FETCH_COLUMN);
        $weekdays = json_encode([1, 2, 3, 4, 5], JSON_THROW_ON_ERROR);
        $insert = $pdo->prepare(
            'INSERT INTO user_working_hours (user_id, weekly_minutes, working_weekdays, effective_from, effective_until)
             VALUES (?, ?, ?, \'1970-01-01\', NULL)',
        );
        $dailyHoursStmt = $pdo->prepare(
            'SELECT setting_value FROM user_settings WHERE user_id = ? AND setting_key = ?',
        );

        foreach ($users as $userId) {
            $userId = (int) $userId;
            $dailyHoursStmt->execute([$userId, 'attendance.daily_hours']);
            $dailyHours = $dailyHoursStmt->fetchColumn();
            $hours = is_numeric($dailyHours) ? (int) $dailyHours : 8;
            if ($hours < 0) {
                $hours = 8;
            }
            $weeklyMinutes = $hours * 60 * 5;
            $insert->execute([$userId, $weeklyMinutes, $weekdays]);
        }
    }

    public function down(\PDO $pdo): void
    {
        $pdo->exec('DROP TABLE IF EXISTS user_working_hours');
    }
};
