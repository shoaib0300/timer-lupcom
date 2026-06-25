<?php

declare(strict_types=1);

namespace Timer\Services;

use Timer\Repositories\ProjectRepository;
use Timer\Repositories\SettingsRepository;
use Timer\Repositories\TaskRepository;
use Timer\Repositories\TimeEntryRepository;

final class PlanioSyncService
{
    private const array COLORS = [
        '#3b82f6', '#22c55e', '#f59e0b', '#ec4899', '#8b5cf6',
        '#06b6d4', '#ef4444', '#84cc16', '#f97316', '#6366f1',
    ];

    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly ProjectRepository $projects,
        private readonly TaskRepository $tasks,
    ) {
    }

    public function clientFromSettings(): PlanioClient
    {
        $config = $this->settings->planioConfig();
        $baseUrl = PlanioClient::normalizeBaseUrl((string) ($config['base_url'] ?? ''));
        $apiKey = (string) ($config['api_key'] ?? '');

        if ($baseUrl === '' || $apiKey === '') {
            throw new \InvalidArgumentException('Planio is not configured yet.');
        }

        return new PlanioClient($baseUrl, $apiKey);
    }

    /** @return array<string, mixed> */
    public function testConnection(string $baseUrl, string $apiKey): array
    {
        $client = new PlanioClient(PlanioClient::normalizeBaseUrl($baseUrl), $apiKey);
        $user = $client->currentUser();

        return [
            'id' => (string) ($user['id'] ?? ''),
            'login' => (string) ($user['login'] ?? ''),
            'name' => (string) ($user['name'] ?? trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? ''))),
            'mail' => (string) ($user['mail'] ?? ''),
        ];
    }

    /**
     * @param list<int> $planioProjectIds
     *
     * @return array{projects_created: int, projects_updated: int, tasks_created: int, tasks_updated: int}
     */
    public function sync(array $planioProjectIds, bool $importIssues): array
    {
        if ($planioProjectIds === []) {
            throw new \InvalidArgumentException('Select at least one project to import.');
        }

        $stats = self::emptyStats();

        foreach ($planioProjectIds as $planioId) {
            $itemStats = $this->syncProject($planioId, $importIssues);
            self::mergeStats($stats, $itemStats);
        }

        $this->settings->set('planio.last_sync_at', (new \DateTimeImmutable())->format('Y-m-d H:i:s'));

        return $stats;
    }

    /**
     * @return array{projects_created: int, projects_updated: int, tasks_created: int, tasks_updated: int, project_name: string}
     */
    public function syncProject(int $planioProjectId, bool $importIssues): array
    {
        if ($planioProjectId <= 0) {
            throw new \InvalidArgumentException('Invalid project id.');
        }

        $client = $this->clientFromSettings();
        $remote = $client->project($planioProjectId);
        $stats = self::emptyStats();
        $localId = $this->upsertProject($remote, $stats);

        if ($importIssues && $localId !== null) {
            $this->syncIssues($client, $planioProjectId, $localId, $stats);
        }

        $stats['project_name'] = (string) ($remote['name'] ?? 'Project ' . $planioProjectId);

        return $stats;
    }

    public function markSyncComplete(): void
    {
        $this->settings->set('planio.last_sync_at', (new \DateTimeImmutable())->format('Y-m-d H:i:s'));
    }

    /**
     * Re-sync every locally linked Planio project (names + issue statuses).
     *
     * @return array{projects_created: int, projects_updated: int, tasks_created: int, tasks_updated: int, projects: int}
     */
    public function refreshAllLinkedProjects(): array
    {
        $planioProjectIds = $this->projects->linkedPlanioIds();

        if ($planioProjectIds === []) {
            return [
                'projects_created' => 0,
                'projects_updated' => 0,
                'tasks_created' => 0,
                'tasks_updated' => 0,
                'projects' => 0,
            ];
        }

        $stats = $this->sync($planioProjectIds, true);
        $stats['projects'] = count($planioProjectIds);

        return $stats;
    }

    /** @return array{projects_created: int, projects_updated: int, tasks_created: int, tasks_updated: int} */
    private static function emptyStats(): array
    {
        return [
            'projects_created' => 0,
            'projects_updated' => 0,
            'tasks_created' => 0,
            'tasks_updated' => 0,
        ];
    }

    /**
     * @param array{projects_created: int, projects_updated: int, tasks_created: int, tasks_updated: int} $into
     * @param array{projects_created: int, projects_updated: int, tasks_created: int, tasks_updated: int} $from
     */
    private static function mergeStats(array &$into, array $from): void
    {
        $into['projects_created'] += $from['projects_created'];
        $into['projects_updated'] += $from['projects_updated'];
        $into['tasks_created'] += $from['tasks_created'];
        $into['tasks_updated'] += $from['tasks_updated'];
    }

    /** @param array<string, mixed> $remote */
    private function upsertProject(array $remote, array &$stats): ?int
    {
        $planioId = (int) $remote['id'];
        $name = (string) ($remote['name'] ?? 'Project ' . $planioId);
        $description = isset($remote['description']) ? trim((string) $remote['description']) : null;
        $description = $description !== '' ? $description : null;

        $existing = $this->projects->findByPlanioId($planioId);

        if ($existing !== null) {
            $this->projects->update($existing->id, $name, $description, $existing->color);
            $stats['projects_updated']++;

            return $existing->id;
        }

        $color = self::COLORS[$planioId % count(self::COLORS)];
        $id = $this->projects->create($name, $description, $color, $planioId);
        $stats['projects_created']++;

        return $id;
    }

  /** @param array{projects_created: int, projects_updated: int, tasks_created: int, tasks_updated: int} $stats */
    private function syncIssues(PlanioClient $client, int $planioProjectId, int $localProjectId, array &$stats): void
    {
        $issues = $client->importableIssuesForProject($planioProjectId);

        foreach ($issues as $issue) {
            $planioIssueId = (int) ($issue['id'] ?? 0);
            if ($planioIssueId <= 0 || PlanioClient::isClosedIssue($issue)) {
                continue;
            }

            $name = (string) ($issue['subject'] ?? 'Issue ' . $planioIssueId);
            $description = isset($issue['description']) ? trim((string) $issue['description']) : null;
            $description = $description !== '' ? $description : null;
            $status = PlanioClient::issueStatusLabel($issue);
            $assignee = PlanioClient::issueAssigneeLabel($issue);

            $existing = $this->tasks->findByPlanioIssueId($localProjectId, $planioIssueId);

            if ($existing !== null) {
                $this->tasks->updateFromPlanio($existing->id, $name, $description, $status, $assignee);
                $stats['tasks_updated']++;
                continue;
            }

            $this->tasks->create($localProjectId, $name, $description, $status, $planioIssueId, $assignee);
            $stats['tasks_created']++;
        }
    }

    public function purgeImportedProjects(TimeEntryRepository $timeEntries): int
    {
        $projectIds = $this->projects->importedLocalIds();

        if ($projectIds === []) {
            return 0;
        }

        $timeEntries->stopRunningForProjects($projectIds);
        $timeEntries->detachFromProjects($projectIds);

        foreach ($projectIds as $projectId) {
            $this->projects->delete($projectId);
        }

        return count($projectIds);
    }
}
