<?php

declare(strict_types=1);

namespace Timer\Services;

use RuntimeException;

/**
 * Planio API client.
 * Read operations are used across the app; time entries can also be posted on timer stop.
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

    /** @return list<array<string, mixed>> */
    public function timeEntriesForUser(int $userId, string $from, string $to): array
    {
        $entries = [];
        $offset = 0;

        do {
            $data = $this->get('/time_entries.json', [
                'user_id' => $userId,
                'from' => $from,
                'to' => $to,
                'limit' => 100,
                'offset' => $offset,
            ]);
            $batch = $data['time_entries'] ?? [];
            $entries = array_merge($entries, $batch);
            $offset += count($batch);
            $total = (int) ($data['total_count'] ?? count($entries));
        } while ($offset < $total && $batch !== []);

        return $entries;
    }

    /** @return list<array{id:int,name:string}> */
    public function timeEntryActivities(): array
    {
        $data = $this->get('/enumerations/time_entry_activities.json');
        $items = $data['time_entry_activities'] ?? [];

        if (!is_array($items)) {
            return [];
        }

        return array_values(array_map(
            static function (array $item): array {
                return [
                    'id' => (int) ($item['id'] ?? 0),
                    'name' => trim((string) ($item['name'] ?? '')),
                ];
            },
            array_filter($items, static fn (mixed $item): bool => is_array($item)),
        ));
    }

    /** @return array{id:int, hours:float, comments:string, spent_on:string} */
    public function createTimeEntry(
        ?int $issueId,
        ?int $projectId,
        float $hours,
        string $comments,
        int $activityId,
        string $spentOn,
    ): array {
        $issueId = $issueId !== null && $issueId > 0 ? $issueId : null;
        $projectId = $projectId !== null && $projectId > 0 ? $projectId : null;

        if ($issueId === null && $projectId === null) {
            throw new RuntimeException('Cannot push time entry without a Planio issue or project id.');
        }
        if ($issueId !== null && $projectId !== null) {
            throw new RuntimeException('Planio time entry target must be either issue or project, not both.');
        }
        if ($hours <= 0) {
            throw new RuntimeException('Cannot push time entry with zero hours.');
        }
        if ($activityId <= 0) {
            throw new RuntimeException('Cannot push time entry without a Planio activity.');
        }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $spentOn)) {
            throw new RuntimeException('Invalid spent_on date format.');
        }

        $payload = [
            'time_entry' => [
                ...($issueId !== null ? ['issue_id' => $issueId] : ['project_id' => $projectId]),
                'hours' => round($hours, 2),
                'comments' => trim($comments),
                'activity_id' => $activityId,
                'spent_on' => $spentOn,
            ],
        ];

        $data = $this->post('/time_entries.json', $payload);
        $entry = $data['time_entry'] ?? null;
        if (!is_array($entry)) {
            throw new RuntimeException('Planio did not return a created time entry.');
        }

        return [
            'id' => (int) ($entry['id'] ?? 0),
            'hours' => (float) ($entry['hours'] ?? 0.0),
            'comments' => (string) ($entry['comments'] ?? ''),
            'spent_on' => (string) ($entry['spent_on'] ?? ''),
        ];
    }

    public function deleteTimeEntry(int $planioTimeEntryId): void
    {
        if ($planioTimeEntryId <= 0) {
            throw new RuntimeException('Cannot delete Planio time entry without a valid id.');
        }

        $this->request('DELETE', $this->baseUrl . '/time_entries/' . $planioTimeEntryId . '.json');
    }

    /** @return array<string, mixed> */
    public function issue(
        int $issueId,
        bool $includeAllowedStatuses = true,
        bool $includeAssignableUsers = true,
    ): array {
        if ($issueId <= 0) {
            throw new RuntimeException('Invalid Planio issue id.');
        }

        $include = [];
        if ($includeAllowedStatuses) {
            $include[] = 'allowed_statuses';
        }
        if ($includeAssignableUsers) {
            $include[] = 'assignable_users';
        }

        $query = [];
        if ($include !== []) {
            $query['include'] = implode(',', $include);
        }

        $data = $this->get('/issues/' . $issueId . '.json', $query);

        return $data['issue'] ?? throw new RuntimeException('Issue not found on Planio.');
    }

    /** @return list<array{id:int,name:string}> */
    public function projectMemberships(int $projectId): array
    {
        if ($projectId <= 0) {
            return [];
        }

        $memberships = [];
        $offset = 0;

        do {
            $data = $this->get('/projects/' . $projectId . '/memberships.json', [
                'limit' => 100,
                'offset' => $offset,
            ]);
            $batch = $data['memberships'] ?? [];
            if (!is_array($batch)) {
                break;
            }

            $memberships = array_merge($memberships, $batch);
            $offset += count($batch);
            $total = (int) ($data['total_count'] ?? count($memberships));
        } while ($offset < $total && $batch !== []);

        $users = [];
        foreach ($memberships as $membership) {
            if (!is_array($membership) || !is_array($membership['user'] ?? null)) {
                continue;
            }
            $id = (int) ($membership['user']['id'] ?? 0);
            $name = trim((string) ($membership['user']['name'] ?? ''));
            if ($id <= 0 || $name === '') {
                continue;
            }
            $users[$id] = ['id' => $id, 'name' => $name];
        }

        usort($users, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return array_values($users);
    }

    public function updateIssue(int $issueId, int $statusId, ?int $assignedToId, int $lockVersion): void
    {
        if ($issueId <= 0) {
            throw new RuntimeException('Invalid Planio issue id.');
        }
        if ($statusId <= 0) {
            throw new RuntimeException('Invalid Planio status id.');
        }
        if ($lockVersion < 0) {
            throw new RuntimeException('Invalid Planio lock version.');
        }

        $payload = [
            'issue' => [
                'status_id' => $statusId,
            ],
        ];
        if ($assignedToId !== null && $assignedToId > 0) {
            $payload['issue']['assigned_to_id'] = $assignedToId;
        }

        $this->put('/issues/' . $issueId . '.json', $payload);
    }

    /** @param array<string, scalar> $query */
    /** @return array<string, mixed> */
    private function get(string $path, array $query = []): array
    {
        $url = $this->baseUrl . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }
        return $this->request('GET', $url);
    }

    /** @param array<string, mixed> $payload */
    /** @return array<string, mixed> */
    private function post(string $path, array $payload): array
    {
        $url = $this->baseUrl . $path;

        return $this->request('POST', $url, $payload);
    }

    /** @param array<string, mixed> $payload */
    /** @return array<string, mixed> */
    private function put(string $path, array $payload): array
    {
        $url = $this->baseUrl . $path;

        return $this->request('PUT', $url, $payload);
    }

    /**
     * @param 'GET'|'POST'|'PUT'|'DELETE' $method
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function request(string $method, string $url, ?array $payload = null): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Could not initialize HTTP client.');
        }

        $headers = [
            'X-Redmine-API-Key: ' . $this->apiKey,
            'Accept: application/json',
        ];
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CUSTOMREQUEST => $method,
        ];

        if ($method === 'POST' || $method === 'PUT') {
            $headers[] = 'Content-Type: application/json';
            $options[CURLOPT_HTTPHEADER] = $headers;
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE);
        }

        curl_setopt_array($ch, $options);

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
            $detail = '';
            $decodedError = json_decode($body, true);
            if (is_array($decodedError)) {
                $errors = $decodedError['errors'] ?? null;
                if (is_array($errors) && $errors !== []) {
                    $detail = ' ' . implode(' ', array_map(static fn (mixed $item): string => (string) $item, $errors));
                }
            }

            throw new RuntimeException('Planio returned HTTP ' . $status . '.' . $detail);
        }

        if (($method === 'DELETE' || $method === 'PUT') && trim($body) === '') {
            return [];
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Planio returned an invalid response.');
        }

        return $decoded;
    }
}
