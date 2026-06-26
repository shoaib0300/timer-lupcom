<?php

declare(strict_types=1);

namespace Timer\Controllers;

use Timer\Http\Request;
use Timer\Http\Response;
use Timer\Repositories\TaskRepository;
use Timer\Support\TimeFormatter;

final class TaskApiController extends BaseController
{
    public function search(Request $request): Response
    {
        $query = trim((string) $request->query('q', ''));
        $tasks = new TaskRepository($this->app->db())->search($query);

        return $this->json([
            'tasks' => array_map($this->formatTask(...), $tasks),
            'total' => count($tasks),
        ]);
    }

    public function frequent(Request $request): Response
    {
        $tasks = new TaskRepository($this->app->db())->mostUsed(8);

        return $this->json([
            'tasks' => array_map($this->formatTask(...), $tasks),
        ]);
    }

    /** @param array<string, mixed> $row */
    private function formatTask(array $row): array
    {
        $totalSeconds = (int) ($row['total_seconds'] ?? 0);

        return [
            'id' => (int) $row['id'],
            'project_id' => (int) $row['project_id'],
            'name' => (string) $row['name'],
            'status' => (string) $row['status'],
            'planio_issue_id' => isset($row['planio_issue_id']) && $row['planio_issue_id'] !== null
                ? (int) $row['planio_issue_id']
                : null,
            'planio_assignee' => $row['planio_assignee'] ?? null,
            'project_name' => (string) $row['project_name'],
            'project_color' => (string) ($row['project_color'] ?? '#3b82f6'),
            'total_seconds' => $totalSeconds,
            'total_human' => TimeFormatter::secondsToHuman($totalSeconds),
            'session_count' => isset($row['session_count']) ? (int) $row['session_count'] : null,
        ];
    }
}
