<?php

declare(strict_types=1);

namespace Timer\Services;

use Timer\Repositories\ProjectRepository;
use Timer\Repositories\SettingsRepository;
use Timer\Repositories\TaskRepository;

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

        $client = $this->clientFromSettings();
        $remoteProjects = $client->allProjects();
        $remoteById = [];
        foreach ($remoteProjects as $project) {
            $remoteById[(int) $project['id']] = $project;
        }

        $stats = [
            'projects_created' => 0,
            'projects_updated' => 0,
            'tasks_created' => 0,
            'tasks_updated' => 0,
        ];

        foreach ($planioProjectIds as $planioId) {
            if (!isset($remoteById[$planioId])) {
                continue;
            }

            $remote = $remoteById[$planioId];
            $localId = $this->upsertProject($remote, $stats);

            if ($importIssues && $localId !== null) {
                $this->syncIssues($client, $planioId, $localId, $stats);
            }
        }

        $this->settings->set('planio.last_sync_at', (new \DateTimeImmutable())->format('Y-m-d H:i:s'));

        return $stats;
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
        $issues = $client->openIssuesForProject($planioProjectId);

        foreach ($issues as $issue) {
            $planioIssueId = (int) ($issue['id'] ?? 0);
            if ($planioIssueId <= 0) {
                continue;
            }

            $name = (string) ($issue['subject'] ?? 'Issue ' . $planioIssueId);
            $description = isset($issue['description']) ? trim((string) $issue['description']) : null;
            $description = $description !== '' ? $description : null;
            $status = $this->mapIssueStatus($issue);

            $existing = $this->tasks->findByPlanioIssueId($localProjectId, $planioIssueId);

            if ($existing !== null) {
                $this->tasks->update($existing->id, $name, $description, $status);
                $stats['tasks_updated']++;
                continue;
            }

            $this->tasks->create($localProjectId, $name, $description, $status, $planioIssueId);
            $stats['tasks_created']++;
        }
    }

    /** @param array<string, mixed> $issue */
    private function mapIssueStatus(array $issue): string
    {
        if (!empty($issue['closed_on'])) {
            return 'done';
        }

        $statusName = strtolower((string) ($issue['status']['name'] ?? ''));

        if (
            str_contains($statusName, 'progress')
            || str_contains($statusName, 'bearbeit')
            || str_contains($statusName, 'feedback')
        ) {
            return 'in_progress';
        }

        return 'open';
    }
}
