<?php

declare(strict_types=1);

namespace Timer\Repositories;

use PDO;
use Timer\Models\Project;

final class ProjectRepository
{
    private const string STATS_SELECT = 'SELECT p.*,
                COALESCE(SUM(te.duration_seconds), 0) AS total_seconds,
                (SELECT COUNT(*) FROM tasks t WHERE t.project_id = p.id) AS task_count,
                (SELECT MAX(COALESCE(te2.ended_at, te2.started_at))
                    FROM time_entries te2 WHERE te2.project_id = p.id) AS last_activity_at';

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    /** @return list<Project> */
    public function allWithStats(): array
    {
        $sql = self::STATS_SELECT . '
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
            self::STATS_SELECT . '
            FROM projects p
            LEFT JOIN time_entries te ON te.project_id = p.id AND te.ended_at IS NOT NULL
            WHERE p.id = ?
            GROUP BY p.id',
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ? Project::fromRow($row) : null;
    }

    public function create(string $name, ?string $description, string $color, ?int $planioId = null): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO projects (name, description, color, planio_id) VALUES (?, ?, ?, ?)',
        );
        $stmt->execute([$name, $description, $color, $planioId]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findByPlanioId(int $planioId): ?Project
    {
        $stmt = $this->pdo->prepare(
            self::STATS_SELECT . '
            FROM projects p
            LEFT JOIN time_entries te ON te.project_id = p.id AND te.ended_at IS NOT NULL
            WHERE p.planio_id = ?
            GROUP BY p.id',
        );
        $stmt->execute([$planioId]);
        $row = $stmt->fetch();

        return $row ? Project::fromRow($row) : null;
    }

    /** @return list<int> */
    public function linkedPlanioIds(): array
    {
        $stmt = $this->pdo->query('SELECT planio_id FROM projects WHERE planio_id IS NOT NULL');
        if (!$stmt) {
            return [];
        }

        return array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
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
