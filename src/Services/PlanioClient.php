<?php

declare(strict_types=1);

namespace Timer\Services;

use RuntimeException;

/**
 * Read-only Planio API client. Only GET requests — nothing is created or updated on Planio.
 */
final class PlanioClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
    ) {
    }

    public static function normalizeBaseUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
            $url = 'https://' . $url;
        }

        return rtrim($url, '/');
    }

    /** @return array<string, mixed> */
    public function currentUser(): array
    {
        $data = $this->get('/users/current.json');

        return $data['user'] ?? throw new RuntimeException('Invalid user response from Planio.');
    }

    /** @return list<array<string, mixed>> */
    public function allProjects(): array
    {
        $projects = [];
        $offset = 0;

        do {
            $data = $this->get('/projects.json', ['limit' => 100, 'offset' => $offset]);
            $batch = $data['projects'] ?? [];
            $projects = array_merge($projects, $batch);
            $offset += count($batch);
            $total = (int) ($data['total_count'] ?? count($projects));
        } while ($offset < $total && $batch !== []);

        return $projects;
    }

    /** @return array<string, mixed> */
    public function project(int $planioProjectId): array
    {
        $data = $this->get('/projects/' . $planioProjectId . '.json');

        return $data['project'] ?? throw new RuntimeException('Project not found on Planio.');
    }

    /** @return list<array<string, mixed>> */
    public function importableIssuesForProject(int $planioProjectId): array
    {
        $issues = [];
        $offset = 0;

        do {
            $data = $this->get('/issues.json', [
                'project_id' => $planioProjectId,
                'status_id' => 'open',
                'limit' => 100,
                'offset' => $offset,
            ]);
            $batch = $data['issues'] ?? [];
            $issues = array_merge($issues, $batch);
            $offset += count($batch);
            $total = (int) ($data['total_count'] ?? count($issues));
        } while ($offset < $total && $batch !== []);

        return array_values(array_filter(
            $issues,
            static fn (array $issue): bool => !self::isClosedIssue($issue),
        ));
    }

    /** @param array<string, mixed> $issue */
    public static function isClosedIssue(array $issue): bool
    {
        $statusId = (int) ($issue['status']['id'] ?? $issue['status_id'] ?? 0);
        if ($statusId === 4) {
            return true;
        }

        $statusName = mb_strtolower(trim((string) ($issue['status']['name'] ?? '')));
        if ($statusName === 'erledigt') {
            return true;
        }

        return (bool) ($issue['status']['is_closed'] ?? false);
    }

    /** @param array<string, mixed> $issue */
    public static function issueStatusLabel(array $issue): string
    {
        $name = trim((string) ($issue['status']['name'] ?? ''));

        return $name !== '' ? $name : 'Unknown';
    }

    /** @param array<string, mixed> $issue */
    public static function issueAssigneeLabel(array $issue): ?string
    {
        $name = trim((string) ($issue['assigned_to']['name'] ?? ''));

        return $name !== '' ? $name : null;
    }

    /** @return list<array<string, mixed>> */
    public function openIssuesForProject(int $planioProjectId): array
    {
        return $this->importableIssuesForProject($planioProjectId);
    }

    /** @param array<string, scalar> $query */
    /** @return array<string, mixed> */
    private function get(string $path, array $query = []): array
    {
        $url = $this->baseUrl . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Could not initialize HTTP client.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'X-Redmine-API-Key: ' . $this->apiKey,
                'Accept: application/json',
            ],
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Planio request failed: ' . $error);
        }

        if ($status === 401 || $status === 403) {
            throw new RuntimeException('Planio rejected the API key. Check your credentials.');
        }

        if ($status >= 400) {
            throw new RuntimeException('Planio returned HTTP ' . $status . '.');
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Planio returned an invalid response.');
        }

        return $decoded;
    }
}
