<?php

declare(strict_types=1);

namespace Timer\Support;

use Timer\Models\Project;

final class ProjectSorter
{
    private const int VISIBLE_LIMIT = 6;

    /**
     * @param list<Project> $projects
     * @param list<int> $runningProjectIds
     *
     * @return list<Project>
     */
    public static function forDashboard(array $projects, array $runningProjectIds): array
    {
        $running = array_flip($runningProjectIds);

        usort($projects, static function (Project $a, Project $b) use ($running): int {
            $aRunning = isset($running[$a->id]);
            $bRunning = isset($running[$b->id]);

            if ($aRunning !== $bRunning) {
                return $bRunning <=> $aRunning;
            }

            return strcmp($b->updatedAt, $a->updatedAt);
        });

        return $projects;
    }

    public static function visibleLimit(): int
    {
        return self::VISIBLE_LIMIT;
    }
}
