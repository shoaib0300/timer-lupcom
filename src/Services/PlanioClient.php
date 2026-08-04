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

    /**
     * Issues assigned to the API key user (Planio "me").
     *
     * @return list<array<string, mixed>>
     */
    public function issuesAssignedToMe(string $statusId = 'open'): array
    {
        $issues = [];
        $offset = 0;

        do {
            $data = $this->get('/issues.json', [
                'assigned_to_id' => 'me',
                'status_id' => $statusId,
                'limit' => 100,
                'offset' => $offset,
            ]);
            $batch = $data['issues'] ?? [];
            if (!is_array($batch)) {
                break;
            }

            $issues = array_merge($issues, $batch);
            $offset += count($batch);
            $total = (int) ($data['total_count'] ?? count($issues));
        } while ($offset < $total && $batch !== []);

        return $issues;
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

    /** @return list<array{id:int,name:string}> */
    public function issuePriorities(): array
    {
        $data = $this->get('/enumerations/issue_priorities.json');
        $items = $data['issue_priorities'] ?? [];

        if (!is_array($items)) {
            return [];
        }

        $priorities = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $id = (int) ($item['id'] ?? 0);
            $name = trim((string) ($item['name'] ?? ''));
            if ($id <= 0 || $name === '') {
                continue;
            }
            $priorities[] = ['id' => $id, 'name' => $name];
        }

        return $priorities;
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

    public function baseUrl(): string
    {
        return $this->baseUrl;
    }

    /**
     * @param list<string> $extraIncludes Valid: journals, attachments, relations, changesets, children, watchers, allowed_statuses
     * @return array<string, mixed>
     */
    public function issue(
        int $issueId,
        bool $includeAllowedStatuses = true,
        array $extraIncludes = [],
    ): array {
        if ($issueId <= 0) {
            throw new RuntimeException('Invalid Planio issue id.');
        }

        // Redmine/Planio issue includes do NOT support assignable_users.
        $includes = [];
        if ($includeAllowedStatuses) {
            $includes[] = 'allowed_statuses';
        }
        foreach ($extraIncludes as $include) {
            $include = trim((string) $include);
            if ($include === '' || in_array($include, $includes, true)) {
                continue;
            }
            $includes[] = $include;
        }

        $query = [];
        if ($includes !== []) {
            $query['include'] = implode(',', $includes);
        }

        $data = $this->get('/issues/' . $issueId . '.json', $query);

        return $data['issue'] ?? throw new RuntimeException('Issue not found on Planio.');
    }

    /**
     * Load issue + project assignee options with fewer round-trips.
     * When the Planio project id is known, issue and memberships are fetched in parallel.
     *
     * @return array{issue: array<string, mixed>, assignees: list<array{id:int,name:string}>}
     */
    public function issueEditBundle(int $issueId, ?int $knownPlanioProjectId = null): array
    {
        $knownProjectId = $knownPlanioProjectId !== null && $knownPlanioProjectId > 0
            ? $knownPlanioProjectId
            : null;

        if ($knownProjectId !== null) {
            $responses = $this->getMany([
                '/issues/' . $issueId . '.json?' . http_build_query([
                    'include' => 'allowed_statuses,journals,attachments',
                ]),
                '/projects/' . $knownProjectId . '/memberships.json?' . http_build_query([
                    'limit' => 100,
                    'offset' => 0,
                ]),
            ]);

            $issuePayload = $responses[0] ?? [];
            $issue = is_array($issuePayload['issue'] ?? null)
                ? $issuePayload['issue']
                : throw new RuntimeException('Issue not found on Planio.');

            $membershipPayload = $responses[1] ?? [];
            $memberships = is_array($membershipPayload['memberships'] ?? null)
                ? $membershipPayload['memberships']
                : [];
            $total = (int) ($membershipPayload['total_count'] ?? count($memberships));
            $offset = count($memberships);
            while ($offset < $total && $memberships !== []) {
                $page = $this->get('/projects/' . $knownProjectId . '/memberships.json', [
                    'limit' => 100,
                    'offset' => $offset,
                ]);
                $batch = $page['memberships'] ?? [];
                if (!is_array($batch) || $batch === []) {
                    break;
                }
                $memberships = array_merge($memberships, $batch);
                $offset += count($batch);
            }

            return [
                'issue' => $issue,
                'assignees' => $this->principalsFromMemberships($memberships),
            ];
        }

        $issue = $this->issue($issueId, true, ['journals', 'attachments']);
        $projectId = (int) ($issue['project']['id'] ?? 0);

        return [
            'issue' => $issue,
            'assignees' => $this->assignablePrincipalsForProject($projectId),
        ];
    }

    /**
     * Upload a file to Planio and return the upload token for attaching to an issue.
     *
     * @return array{token: string, filename: string, content_type: string}
     */
    public function uploadFile(string $filename, string $tmpPath, ?string $contentType = null): array
    {
        $filename = $this->sanitizeUploadFilename($filename);
        if ($filename === '') {
            throw new RuntimeException('Invalid upload filename.');
        }
        if (!is_readable($tmpPath) || filesize($tmpPath) === 0) {
            throw new RuntimeException('Upload file is empty or unreadable.');
        }

        $contents = file_get_contents($tmpPath);
        if ($contents === false) {
            throw new RuntimeException('Could not read upload file.');
        }

        $contentType = trim((string) $contentType);
        if ($contentType === '') {
            $contentType = mime_content_type($tmpPath) ?: 'application/octet-stream';
        }

        $url = $this->baseUrl . '/uploads.json?' . http_build_query(['filename' => $filename]);
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Could not initialize HTTP client.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_HTTPHEADER => [
                'X-Redmine-API-Key: ' . $this->apiKey,
                'Accept: application/json',
                'Content-Type: application/octet-stream',
            ],
            CURLOPT_POSTFIELDS => $contents,
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Planio upload failed: ' . $error);
        }
        if ($status === 401 || $status === 403) {
            throw new RuntimeException('Planio rejected the API key. Check your credentials.');
        }
        if ($status >= 400) {
            throw new RuntimeException('Planio upload returned HTTP ' . $status . '.');
        }

        $decoded = json_decode($body, true);
        $token = is_array($decoded) ? trim((string) ($decoded['upload']['token'] ?? '')) : '';
        if ($token === '') {
            throw new RuntimeException('Planio upload did not return a token.');
        }

        return [
            'token' => $token,
            'filename' => $filename,
            'content_type' => $contentType,
        ];
    }

    /**
     * Add a note and/or previously uploaded files to a Planio issue.
     *
     * @param list<array{token: string, filename: string, content_type?: string}> $uploads
     */
    public function addIssueNote(int $issueId, string $notes, array $uploads = []): void
    {
        if ($issueId <= 0) {
            throw new RuntimeException('Invalid Planio issue id.');
        }

        $notes = trim($notes);
        $normalizedUploads = [];
        foreach ($uploads as $upload) {
            if (!is_array($upload)) {
                continue;
            }
            $token = trim((string) ($upload['token'] ?? ''));
            $filename = $this->sanitizeUploadFilename((string) ($upload['filename'] ?? ''));
            if ($token === '' || $filename === '') {
                continue;
            }
            $entry = [
                'token' => $token,
                'filename' => $filename,
            ];
            $contentType = trim((string) ($upload['content_type'] ?? ''));
            if ($contentType !== '') {
                $entry['content_type'] = $contentType;
            }
            $normalizedUploads[] = $entry;
        }

        if ($notes === '' && $normalizedUploads === []) {
            throw new RuntimeException('A note or at least one attachment is required.');
        }

        $issuePayload = [
            'notes' => $notes,
        ];
        if ($normalizedUploads !== []) {
            $issuePayload['uploads'] = $normalizedUploads;
        }

        $this->put('/issues/' . $issueId . '.json', ['issue' => $issuePayload]);
    }

    public function deleteAttachment(int $attachmentId): void
    {
        if ($attachmentId <= 0) {
            throw new RuntimeException('Invalid Planio attachment id.');
        }

        $this->request('DELETE', $this->baseUrl . '/attachments/' . $attachmentId . '.json');
    }

    public function clearJournalNotes(int $journalId): void
    {
        if ($journalId <= 0) {
            throw new RuntimeException('Invalid Planio journal id.');
        }

        try {
            $this->put('/journals/' . $journalId . '.json', [
                'journal' => [
                    'notes' => '',
                ],
            ]);
        } catch (RuntimeException $exception) {
            $message = $exception->getMessage();
            if (str_contains($message, 'denied permission') || str_contains($message, 'HTTP 403')) {
                $who = $this->currentUserLabel();
                throw new RuntimeException(
                    'Planio does not allow this API user to edit or delete issue notes.'
                    . ($who !== '' ? ' Planio user: ' . $who . '.' : '')
                    . ' Enable “Edit notes” and “Edit own notes” for that user’s role under Administration → Roles and permissions → Issue tracking.',
                    0,
                    $exception,
                );
            }
            if (str_contains($message, 'HTTP 404')) {
                throw new RuntimeException(
                    'Planio could not find this note, or journal editing is not available via API on this Planio version.',
                    0,
                    $exception,
                );
            }

            throw $exception;
        }
    }

    private function currentUserLabel(): string
    {
        try {
            $user = $this->currentUser();
            $name = trim((string) ($user['firstname'] ?? '') . ' ' . (string) ($user['lastname'] ?? ''));
            $login = trim((string) ($user['login'] ?? ''));
            if ($name !== '' && $login !== '') {
                return $name . ' (' . $login . ')';
            }

            return $name !== '' ? $name : $login;
        } catch (\Throwable) {
            return '';
        }
    }

    /**
     * Download an attachment body via Planio API key.
     *
     * @return array{body: string, content_type: string, filename: string}
     */
    public function downloadAttachment(int $attachmentId, ?string $preferredFilename = null): array
    {
        if ($attachmentId <= 0) {
            throw new RuntimeException('Invalid Planio attachment id.');
        }

        $meta = $this->get('/attachments/' . $attachmentId . '.json');
        $attachment = is_array($meta['attachment'] ?? null) ? $meta['attachment'] : [];
        $filename = trim((string) ($preferredFilename ?: ($attachment['filename'] ?? '')));
        if ($filename === '') {
            $filename = 'attachment-' . $attachmentId;
        }
        $contentType = trim((string) ($attachment['content_type'] ?? 'application/octet-stream'));
        if ($contentType === '') {
            $contentType = 'application/octet-stream';
        }

        $contentUrl = trim((string) ($attachment['content_url'] ?? ''));
        if ($contentUrl === '') {
            $contentUrl = $this->baseUrl . '/attachments/download/' . $attachmentId . '/' . rawurlencode($filename);
        } elseif (!str_starts_with($contentUrl, 'http://') && !str_starts_with($contentUrl, 'https://')) {
            $contentUrl = $this->baseUrl . '/' . ltrim($contentUrl, '/');
        }

        $ch = curl_init($contentUrl);
        if ($ch === false) {
            throw new RuntimeException('Could not initialize HTTP client.');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => [
                'X-Redmine-API-Key: ' . $this->apiKey,
                'Accept: */*',
            ],
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        $responseType = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException('Planio attachment download failed: ' . $error);
        }
        if ($status === 401 || $status === 403) {
            throw new RuntimeException('Planio rejected the API key. Check your credentials.');
        }
        if ($status >= 400) {
            throw new RuntimeException('Planio attachment download returned HTTP ' . $status . '.');
        }

        if ($responseType !== '' && !str_contains(strtolower($responseType), 'text/html')) {
            $contentType = explode(';', $responseType)[0];
        }

        return [
            'body' => $body,
            'content_type' => $contentType,
            'filename' => $filename,
        ];
    }

    private function sanitizeUploadFilename(string $filename): string
    {
        $filename = basename(str_replace(["\0", '/', '\\'], '', trim($filename)));
        $filename = preg_replace('/[^\w.\- ()\[\]]+/u', '_', $filename) ?? '';

        return trim($filename, '._ ') !== '' ? $filename : '';
    }

    /**
     * Direct members of a Planio project (users + groups) for assignee selection.
     * Inherited memberships are excluded.
     *
     * @return list<array{id:int,name:string}>
     */
    public function assignablePrincipalsForProject(int $projectId): array
    {
        if ($projectId <= 0) {
            return [];
        }

        return $this->principalsFromMemberships($this->projectMembershipsRaw($projectId));
    }

    /**
     * @param list<array<string, mixed>> $memberships
     * @return list<array{id:int,name:string}>
     */
    private function principalsFromMemberships(array $memberships): array
    {
        $principals = [];

        foreach ($memberships as $membership) {
            if (!is_array($membership) || $this->membershipIsInheritedOnly($membership)) {
                continue;
            }

            $isGroup = is_array($membership['group'] ?? null);
            $principal = $isGroup
                ? $membership['group']
                : (is_array($membership['user'] ?? null) ? $membership['user'] : null);

            if (!is_array($principal)) {
                continue;
            }

            $id = (int) ($principal['id'] ?? 0);
            $name = trim((string) ($principal['name'] ?? ''));
            if ($id <= 0 || $name === '') {
                continue;
            }

            $principals[$id] = ['id' => $id, 'name' => $name];
        }

        $list = array_values($principals);
        usort($list, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return $list;
    }

    /** @return list<array<string, mixed>> */
    private function projectMembershipsRaw(int $projectId): array
    {
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

        return $memberships;
    }

    /**
     * @param array<string, mixed> $membership
     */
    private function membershipIsInheritedOnly(array $membership): bool
    {
        $roles = $membership['roles'] ?? [];
        if (!is_array($roles) || $roles === []) {
            return false;
        }

        foreach ($roles as $role) {
            if (!is_array($role)) {
                continue;
            }
            if (!($role['inherited'] ?? false)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Parallel GET helper for independent Planio JSON endpoints.
     *
     * @param list<string> $paths Paths beginning with /, optionally including query string
     * @return list<array<string, mixed>>
     */
    private function getMany(array $paths): array
    {
        if ($paths === []) {
            return [];
        }

        if (count($paths) === 1) {
            $path = $paths[0];
            $queryPos = strpos($path, '?');
            if ($queryPos === false) {
                return [$this->get($path)];
            }

            parse_str(substr($path, $queryPos + 1), $query);

            return [$this->get(substr($path, 0, $queryPos), $query)];
        }

        $multi = curl_multi_init();
        if ($multi === false) {
            $out = [];
            foreach ($paths as $path) {
                $queryPos = strpos($path, '?');
                if ($queryPos === false) {
                    $out[] = $this->get($path);
                } else {
                    parse_str(substr($path, $queryPos + 1), $query);
                    $out[] = $this->get(substr($path, 0, $queryPos), $query);
                }
            }

            return $out;
        }

        $handles = [];
        foreach ($paths as $index => $path) {
            $url = $this->baseUrl . $path;
            $ch = curl_init($url);
            if ($ch === false) {
                continue;
            }
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_HTTPHEADER => [
                    'X-Redmine-API-Key: ' . $this->apiKey,
                    'Accept: application/json',
                ],
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[$index] = $ch;
        }

        $running = null;
        do {
            $status = curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $results = [];
        foreach ($handles as $index => $ch) {
            $body = curl_multi_getcontent($ch);
            $httpStatus = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);

            if ($body === false || $body === '') {
                throw new RuntimeException('Planio request failed: ' . ($error !== '' ? $error : 'empty response'));
            }
            if ($httpStatus === 401 || $httpStatus === 403) {
                throw new RuntimeException('Planio rejected the API key. Check your credentials.');
            }
            if ($httpStatus >= 400) {
                throw new RuntimeException('Planio returned HTTP ' . $httpStatus . '.');
            }

            $decoded = json_decode($body, true);
            if (!is_array($decoded)) {
                throw new RuntimeException('Planio returned an invalid response.');
            }
            $results[$index] = $decoded;
        }

        curl_multi_close($multi);
        ksort($results);

        return array_values($results);
    }

    /**
     * @param ?string $startDate Y-m-d or empty string to clear
     * @param ?string $dueDate Y-m-d or empty string to clear
     */
    public function updateIssue(
        int $issueId,
        int $statusId,
        ?int $assignedToId,
        int $lockVersion,
        ?int $priorityId = null,
        ?string $startDate = null,
        ?string $dueDate = null,
    ): void {
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
        if ($priorityId !== null && $priorityId > 0) {
            $payload['issue']['priority_id'] = $priorityId;
        }
        if ($startDate !== null) {
            $payload['issue']['start_date'] = $startDate;
        }
        if ($dueDate !== null) {
            $payload['issue']['due_date'] = $dueDate;
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

        if ($status === 401) {
            throw new RuntimeException('Planio rejected the API key. Check your credentials.');
        }

        if ($status === 403) {
            $detail = $this->formatPlanioErrorDetail($body);
            throw new RuntimeException(
                'Planio denied permission for this action.' . $detail,
            );
        }

        if ($status >= 400) {
            $detail = $this->formatPlanioErrorDetail($body);
            throw new RuntimeException('Planio returned HTTP ' . $status . '.' . $detail);
        }

        // 204 No Content (and some empty 200 PUT/DELETE responses)
        if (($method === 'DELETE' || $method === 'PUT') && trim((string) $body) === '') {
            return [];
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Planio returned an invalid response.');
        }

        return $decoded;
    }

    private function formatPlanioErrorDetail(string $body): string
    {
        $decodedError = json_decode($body, true);
        if (!is_array($decodedError)) {
            return '';
        }

        $errors = $decodedError['errors'] ?? null;
        if (!is_array($errors) || $errors === []) {
            return '';
        }

        return ' ' . implode(' ', array_map(static fn (mixed $item): string => (string) $item, $errors));
    }
}
