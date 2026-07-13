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
                    FROM time_entries te2
                    WHERE te2.project_id = p.id AND te2.user_id = ';

    private const string STATS_JOIN = ' FROM projects p
            LEFT JOIN time_entries te ON te.project_id = p.id
                AND te.ended_at IS NOT NULL
                AND te.user_id = ';

    public function __construct(
        private readonly PDO $pdo,
        private readonly int $userId,
    ) {
    }

    private function statsJoinSql(): string
    {
        return self::STATS_JOIN . $this->userId;
    }

    private function statsSubquerySql(): string
    {
        return self::STATS_SELECT . $this->userId . ') AS last_activity_at';
    }

    /** @return list<Project> */
    public function allWithStats(): array
    {
        $sql = $this->statsSubquerySql() . $this->statsJoinSql() . '
            WHERE p.user_id = ?
            GROUP BY p.id
            ORDER BY p.name ASC';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$this->userId]);

        return array_map(
            Project::fromRow(...),
            $stmt->fetchAll(),
        );
    }

    public function find(int $id): ?Project
    {
        $stmt = $this->pdo->prepare(
            $this->statsSubquerySql() . $this->statsJoinSql() . '
            WHERE p.id = ? AND p.user_id = ?
            GROUP BY p.id',
        );
        $stmt->execute([$id, $this->userId]);
        $row = $stmt->fetch();

        return $row ? Project::fromRow($row) : null;
    }

    public function create(string $name, ?string $description, string $color, ?int $planioId = null): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO projects (user_id, name, description, color, planio_id) VALUES (?, ?, ?, ?, ?)',
        );
        $stmt->execute([$this->userId, $name, $description, $color, $planioId]);

        return (int) $this->pdo->lastInsertId();
    }

    public function findByPlanioId(int $planioId): ?Project
    {
        $stmt = $this->pdo->prepare(
            $this->statsSubquerySql() . $this->statsJoinSql() . '
            WHERE p.planio_id = ? AND p.user_id = ?
            GROUP BY p.id',
        );
        $stmt->execute([$planioId, $this->userId]);
        $row = $stmt->fetch();

        return $row ? Project::fromRow($row) : null;
    }

    /** @return list<int> */
    public function linkedPlanioIds(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT planio_id FROM projects WHERE planio_id IS NOT NULL AND user_id = ?',
        );
        $stmt->execute([$this->userId]);

        return array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return list<int> */
    public function importedLocalIds(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id FROM projects WHERE planio_id IS NOT NULL AND user_id = ?',
        );
        $stmt->execute([$this->userId]);

        return array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    public function update(int $id, string $name, ?string $description, string $color): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE projects SET name = ?, description = ?, color = ? WHERE id = ? AND user_id = ?',
        );
        $stmt->execute([$name, $description, $color, $id, $this->userId]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM projects WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $this->userId]);
    }
}
