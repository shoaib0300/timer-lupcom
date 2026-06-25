<?php

declare(strict_types=1);

namespace Timer\Repositories;

use PDO;

final class SettingsRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $stmt = $this->pdo->prepare('SELECT setting_value FROM settings WHERE setting_key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();

        return $value !== false ? (string) $value : $default;
    }

    public function set(string $key, ?string $value): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)',
        );
        $stmt->execute([$key, $value]);
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
        $stmt = $this->pdo->prepare('DELETE FROM settings WHERE setting_key LIKE ?');
        $stmt->execute(['planio.%']);
    }
}
