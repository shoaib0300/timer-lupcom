<?php

declare(strict_types=1);

namespace Timer\Controllers;

use Timer\Http\Request;
use Timer\Http\Response;
use Timer\Services\PlanioClient;

final class PlanioController extends BaseController
{
    public function index(Request $request): Response
    {
        $settings = $this->userSettings();
        $config = $settings->planioConfig();

        return $this->view('settings/planio.html.twig', [
            'planio' => $config,
            'has_api_key' => ($config['api_key'] ?? '') !== '',
            'linked_planio_ids' => $this->projects()->linkedPlanioIds(),
            'flash_success' => $request->query('success'),
            'flash_error' => $request->query('error'),
            'show_welcome' => $request->query('welcome') === '1',
            'setup_import_error' => $request->query('setup_import_error') === '1',
            'active_nav' => 'planio',
        ]);
    }

    public function save(Request $request): Response
    {
        $settings = $this->userSettings();
        $baseUrl = PlanioClient::normalizeBaseUrl((string) $request->input('base_url', ''));
        $apiKey = trim((string) $request->input('api_key', ''));

        if ($baseUrl === '') {
            return $this->redirectWith('/settings/planio', 'error', 'Planio URL is required.');
        }

        $settings->set('planio.base_url', $baseUrl);

        if ($apiKey !== '') {
            $settings->set('planio.api_key', $apiKey);
        } elseif (!$settings->isPlanioConfigured()) {
            return $this->redirectWith('/settings/planio', 'error', 'API key is required.');
        }

        $sync = $this->planioSync();

        try {
            $config = $settings->planioConfig();
            $user = $sync->testConnection($baseUrl, (string) $config['api_key']);
            $settings->savePlanioUser([
                'user_id' => $user['id'],
                'user_login' => $user['login'],
                'user_name' => $user['name'],
                'user_email' => $user['mail'],
            ]);
        } catch (\Throwable $exception) {
            return $this->redirectWith('/settings/planio', 'error', $exception->getMessage());
        }

        return $this->redirectWith('/settings/planio', 'success', 'Planio connection saved.');
    }

    public function disconnect(Request $request): Response
    {
        $settings = $this->userSettings();
        $sync = $this->planioSync();
        $db = $this->app->db();

        $db->beginTransaction();

        try {
            $removed = $sync->purgeImportedProjects($this->timeEntries());
            $settings->clearPlanio();
            $db->commit();
        } catch (\Throwable $exception) {
            $db->rollBack();

            return $this->redirectWith('/settings/planio', 'error', $exception->getMessage());
        }

        return $this->redirectWith(
            '/settings/planio',
            'success',
            $this->trans('planio.disconnected', ['count' => (string) $removed]),
        );
    }

    public function testApi(Request $request): Response
    {
        $settings = $this->userSettings();

        if (!$settings->isPlanioConfigured()) {
            return $this->json(['error' => 'Save your Planio URL and API key first.'], 422);
        }

        $sync = $this->planioSync();

        try {
            $config = $settings->planioConfig();
            $user = $sync->testConnection((string) $config['base_url'], (string) $config['api_key']);
            $settings->savePlanioUser([
                'user_id' => $user['id'],
                'user_login' => $user['login'],
                'user_name' => $user['name'],
                'user_email' => $user['mail'],
            ]);

            return $this->json([
                'message' => 'Connected successfully.',
                'user' => $user,
            ]);
        } catch (\Throwable $exception) {
            return $this->json(['error' => $exception->getMessage()], 422);
        }
    }

    public function projectsApi(Request $request): Response
    {
        $settings = $this->userSettings();

        if (!$settings->isPlanioConfigured()) {
            return $this->json(['error' => 'Planio is not configured.'], 422);
        }

        try {
            $projects = $this->planioSync()->clientFromSettings()->allProjects();
            $linked = $this->projects()->linkedPlanioIds();

            $items = array_map(static function (array $project) use ($linked): array {
                $id = (int) $project['id'];

                return [
                    'id' => $id,
                    'name' => (string) ($project['name'] ?? ''),
                    'identifier' => (string) ($project['identifier'] ?? ''),
                    'parent_name' => $project['parent']['name'] ?? null,
                    'is_linked' => in_array($id, $linked, true),
                ];
            }, $projects);

            usort($items, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

            return $this->json([
                'projects' => $items,
                'total' => count($items),
            ]);
        } catch (\Throwable $exception) {
            return $this->json(['error' => $exception->getMessage()], 422);
        }
    }

    public function sync(Request $request): Response
    {
        $settings = $this->userSettings();

        if (!$settings->isPlanioConfigured()) {
            return $this->json(['error' => 'Planio is not configured.'], 422);
        }

        $rawIds = $request->input('project_ids', []);
        if (!is_array($rawIds)) {
            $rawIds = [];
        }

        $projectIds = array_values(array_filter(
            array_map(intval(...), $rawIds),
            static fn (int $id): bool => $id > 0,
        ));

        $importIssues = filter_var($request->input('import_issues', false), FILTER_VALIDATE_BOOL);

        try {
            $stats = $this->planioSync()->sync($projectIds, $importIssues);

            return $this->json([
                'message' => $this->syncMessage($stats),
                'stats' => $stats,
            ]);
        } catch (\Throwable $exception) {
            return $this->json(['error' => $exception->getMessage()], 422);
        }
    }

    public function syncItem(Request $request): Response
    {
        $settings = $this->userSettings();

        if (!$settings->isPlanioConfigured()) {
            return $this->json(['error' => 'Planio is not configured.'], 422);
        }

        $projectId = (int) $request->input('project_id', 0);
        $importIssues = filter_var($request->input('import_issues', false), FILTER_VALIDATE_BOOL);
        $finalize = filter_var($request->input('finalize', false), FILTER_VALIDATE_BOOL);

        try {
            $stats = $this->planioSync()->syncProject($projectId, $importIssues);

            if ($finalize) {
                $this->planioSync()->markSyncComplete();
            }

            return $this->json([
                'stats' => $stats,
                'project_name' => $stats['project_name'],
            ]);
        } catch (\Throwable $exception) {
            return $this->json(['error' => $exception->getMessage()], 422);
        }
    }

    /** @param array{projects_created: int, projects_updated: int, tasks_created: int, tasks_updated: int} $stats */
    private function syncMessage(array $stats): string
    {
        return sprintf(
            'Imported from Planio: %d new and %d updated projects locally. Tasks: %d new, %d updated. Nothing was sent to Planio.',
            $stats['projects_created'],
            $stats['projects_updated'],
            $stats['tasks_created'],
            $stats['tasks_updated'],
        );
    }

    private function redirectWith(string $path, string $type, string $message): Response
    {
        $query = http_build_query([$type => $message]);

        return $this->redirect($path . '?' . $query);
    }
}
