<?php

declare(strict_types=1);

namespace Timer\Repositories;

use PDO;
use Timer\Models\Task;

final class TaskRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly ?int $statsUserId = null,
    ) {
    }

    private function timeEntryJoin(): string
    {
        if ($this->statsUserId === null) {
            return 'LEFT JOIN time_entries te ON te.task_id = t.id AND te.ended_at IS NOT NULL';
        }

        return 'LEFT JOIN time_entries te ON te.task_id = t.id AND te.ended_at IS NOT NULL AND te.user_id = '
            . (int) $this->statsUserId;
    }

    private function innerTimeEntryJoin(): string
    {
        if ($this->statsUserId === null) {
            return 'INNER JOIN time_entries te ON te.task_id = t.id AND te.ended_at IS NOT NULL';
        }

        return 'INNER JOIN time_entries te ON te.task_id = t.id AND te.ended_at IS NOT NULL AND te.user_id = '
            . (int) $this->statsUserId;
    }

    /** @return list<Task> */
    public function forProject(int $projectId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*,
                COALESCE(SUM(te.duration_seconds), 0) AS total_seconds
            FROM tasks t
            ' . $this->timeEntryJoin() . '
            WHERE t.project_id = ?
            GROUP BY t.id
            ORDER BY t.name ASC',
        );
        $stmt->execute([$projectId]);

        return array_map(
            Task::fromRow(...),
            $stmt->fetchAll(),
        );
    }

    public function find(int $id): ?Task
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*, p.name AS project_name,
                COALESCE(SUM(te.duration_seconds), 0) AS total_seconds
            FROM tasks t
            JOIN projects p ON p.id = t.project_id
            ' . $this->timeEntryJoin() . '
            WHERE t.id = ?
            GROUP BY t.id',
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ? Task::fromRow($row) : null;
    }

    public function findOrCreateByName(int $projectId, string $name): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM tasks WHERE project_id = ? AND name = ? LIMIT 1',
        );
        $stmt->execute([$projectId, $name]);
        $id = $stmt->fetchColumn();

        if ($id !== false) {
            return (int) $id;
        }

        return $this->create($projectId, $name, null, 'in_progress');
    }

    public function create(
        int $projectId,
        string $name,
        ?string $description,
        string $status,
        ?int $planioIssueId = null,
        ?string $planioAssignee = null,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tasks (project_id, name, description, status, planio_issue_id, planio_assignee) VALUES (?, ?, ?, ?, ?, ?)',
        );
        $stmt->execute([$projectId, $name, $description, $status, $planioIssueId, $planioAssignee]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findByPlanioIssueId(int $projectId, int $planioIssueId): ?Task
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*, p.name AS project_name, 0 AS total_seconds
            FROM tasks t
            JOIN projects p ON p.id = t.project_id
            WHERE t.project_id = ? AND t.planio_issue_id = ?
            LIMIT 1',
        );
        $stmt->execute([$projectId, $planioIssueId]);
        $row = $stmt->fetch();

        return $row ? Task::fromRow($row) : null;
    }

    public function update(int $id, string $name, ?string $description, string $status): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE tasks SET name = ?, description = ?, status = ? WHERE id = ?',
        );
        $stmt->execute([$name, $description, $status, $id]);
    }

    public function updateFromPlanio(
        int $id,
        string $name,
        ?string $description,
        string $status,
        ?string $planioAssignee,
    ): void {
        $stmt = $this->pdo->prepare(
            'UPDATE tasks SET name = ?, description = ?, status = ?, planio_assignee = ? WHERE id = ?',
        );
        $stmt->execute([$name, $description, $status, $planioAssignee, $id]);
    }

    public function updatePlanioState(int $id, string $status, ?string $planioAssignee): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE tasks SET status = ?, planio_assignee = ? WHERE id = ?',
        );
        $stmt->execute([$status, $planioAssignee, $id]);
    }

    /**
     * @param list<string> $assigneeLabels
     * @return list<Task>
     */
    public function assignedToLabels(array $assigneeLabels, int $projectUserId): array
    {
        $labels = array_values(array_unique(array_filter(array_map(
            static fn (string $label): string => mb_strtolower(trim($label)),
            $assigneeLabels,
        ))));

        if ($labels === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($labels), '?'));

        $stmt = $this->pdo->prepare(
            'SELECT t.*, p.name AS project_name, p.color AS project_color,
                COALESCE(SUM(te.duration_seconds), 0) AS total_seconds
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id AND p.user_id = ?
            ' . $this->timeEntryJoin() . '
            WHERE LOWER(COALESCE(t.planio_assignee, \'\')) IN (' . $placeholders . ')
            GROUP BY t.id
            ORDER BY p.name ASC, t.name ASC',
        );
        $stmt->execute([$projectUserId, ...$labels]);

        return array_map(
            Task::fromRow(...),
            $stmt->fetchAll(),
        );
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM tasks WHERE id = ?');
        $stmt->execute([$id]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function search(string $query, int $limit = 12): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $limit = max(1, min($limit, 25));
        $like = '%' . $query . '%';
        $isNumeric = ctype_digit($query);

        $sql = 'SELECT t.id, t.project_id, t.name, t.status, t.planio_issue_id, t.planio_assignee,
                p.name AS project_name, p.color AS project_color,
                COALESCE(SUM(te.duration_seconds), 0) AS total_seconds
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            ' . $this->timeEntryJoin() . '
            WHERE (
                t.name LIKE ?
                OR p.name LIKE ?
                OR COALESCE(t.planio_assignee, \'\') LIKE ?';

        $params = [$like, $like, $like];

        if ($isNumeric) {
            $sql .= ' OR t.id = ? OR t.planio_issue_id = ?';
            $params[] = (int) $query;
            $params[] = (int) $query;
        }

        $sql .= ')
            GROUP BY t.id, t.project_id, t.name, t.status, t.planio_issue_id, t.planio_assignee, p.name, p.color
            ORDER BY
                CASE
                    WHEN t.name LIKE ? THEN 0
                    WHEN p.name LIKE ? THEN 1
                    ELSE 2
                END,
                total_seconds DESC,
                t.name ASC
            LIMIT ?';

        $params[] = $like;
        $params[] = $like;
        $params[] = $limit;

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function mostUsed(int $limit = 8): array
    {
        $limit = max(1, min($limit, 20));

        $stmt = $this->pdo->prepare(
            'SELECT t.id, t.project_id, t.name, t.status, t.planio_issue_id, t.planio_assignee,
                p.name AS project_name, p.color AS project_color,
                COUNT(te.id) AS session_count,
                COALESCE(SUM(te.duration_seconds), 0) AS total_seconds
            FROM tasks t
            INNER JOIN projects p ON p.id = t.project_id
            ' . $this->innerTimeEntryJoin() . '
            GROUP BY t.id, t.project_id, t.name, t.status, t.planio_issue_id, t.planio_assignee, p.name, p.color
            ORDER BY session_count DESC, total_seconds DESC, t.name ASC
            LIMIT ?',
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }
}
