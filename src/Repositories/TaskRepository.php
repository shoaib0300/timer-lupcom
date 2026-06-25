<?php

declare(strict_types=1);

namespace Timer\Repositories;

use PDO;
use Timer\Models\Task;

final class TaskRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /** @return list<Task> */
    public function forProject(int $projectId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT t.*,
                COALESCE(SUM(te.duration_seconds), 0) AS total_seconds
            FROM tasks t
            LEFT JOIN time_entries te ON te.task_id = t.id AND te.ended_at IS NOT NULL
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
            LEFT JOIN time_entries te ON te.task_id = t.id AND te.ended_at IS NOT NULL
            WHERE t.id = ?
            GROUP BY t.id',
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ? Task::fromRow($row) : null;
    }

    public function create(int $projectId, string $name, ?string $description, string $status): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tasks (project_id, name, description, status) VALUES (?, ?, ?, ?)',
        );
        $stmt->execute([$projectId, $name, $description, $status]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $name, ?string $description, string $status): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE tasks SET name = ?, description = ?, status = ? WHERE id = ?',
        );
        $stmt->execute([$name, $description, $status, $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM tasks WHERE id = ?');
        $stmt->execute([$id]);
    }
}
