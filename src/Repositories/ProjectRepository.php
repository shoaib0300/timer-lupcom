<?php

declare(strict_types=1);

namespace Timer\Repositories;

use PDO;
use Timer\Models\Project;

final class ProjectRepository
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /** @return list<Project> */
    public function allWithStats(): array
    {
        $sql = 'SELECT p.*,
                COALESCE(SUM(te.duration_seconds), 0) AS total_seconds,
                (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id) AS task_count
            FROM projects p
            LEFT JOIN time_entries te ON te.project_id = p.id AND te.ended_at IS NOT NULL
            GROUP BY p.id
            ORDER BY p.name ASC';

        $stmt = $this->pdo->query($sql);

        return array_map(
            Project::fromRow(...),
            $stmt ? $stmt->fetchAll() : [],
        );
    }

    public function find(int $id): ?Project
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.*,
                COALESCE(SUM(te.duration_seconds), 0) AS total_seconds,
                (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id) AS task_count
            FROM projects p
            LEFT JOIN time_entries te ON te.project_id = p.id AND te.ended_at IS NOT NULL
            WHERE p.id = ?
            GROUP BY p.id',
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ? Project::fromRow($row) : null;
    }

    public function create(string $name, ?string $description, string $color): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO projects (name, description, color) VALUES (?, ?, ?)',
        );
        $stmt->execute([$name, $description, $color]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, string $name, ?string $description, string $color): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE projects SET name = ?, description = ?, color = ? WHERE id = ?',
        );
        $stmt->execute([$name, $description, $color, $id]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM projects WHERE id = ?');
        $stmt->execute([$id]);
    }
}
