<?php

declare(strict_types=1);

namespace Timer\Repositories;

use PDO;

final class UserSettingsRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $userId,
    ) {
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT setting_value FROM user_settings WHERE user_id = ? AND setting_key = ?',
        );
        $stmt->execute([$this->userId, $key]);
        $value = $stmt->fetchColumn();

        return $value !== false ? (string) $value : $default;
    }

    public function set(string $key, ?string $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO user_settings (user_id, setting_key, setting_value) VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
        );
        $stmt->execute([$this->userId, $key, $value]);
    }

    public function seedAttendanceDefaults(): void
    {
        $defaults = [
            'attendance.country' => 'DE',
            'attendance.state' => 'MV',
            'attendance.daily_hours' => '8',
            'attendance.break_minutes' => '30',
        ];

        foreach ($defaults as $key => $value) {
            if ($this->get($key) === null) {
                $this->set($key, $value);
            }
        }
    }

    /** @return array<string, string|null> */
    public function planioConfig(): array
    {
        return [
            'base_url' => $this->get('planio.base_url'),
            'api_key' => $this->get('planio.api_key'),
            'user_id' => $this->get('planio.user_id'),
            'user_login' => $this->get('planio.user_login'),
            'user_name' => $this->get('planio.user_name'),
            'user_email' => $this->get('planio.user_email'),
            'last_sync_at' => $this->get('planio.last_sync_at'),
        ];
    }

    public function isPlanioConfigured(): bool
    {
        $url = $this->get('planio.base_url');
        $key = $this->get('planio.api_key');

        return $url !== null && $url !== '' && $key !== null && $key !== '';
    }

    /** @param array<string, string|null> $user */
    public function savePlanioUser(array $user): void
    {
        foreach ($user as $key => $value) {
            if ($value !== null) {
                $this->set('planio.' . $key, $value);
            }
        }
    }

    public function clearPlanio(): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM user_settings WHERE user_id = ? AND setting_key LIKE ?',
        );
        $stmt->execute([$this->userId, 'planio.%']);
    }
}
