<?php

declare(strict_types=1);

namespace Timer\Database;

use DateInterval;
use DateTimeImmutable;
use PDO;

final class Seeder
{
    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public function run(): void
    {
        $this->pdo->exec('DELETE FROM projects');

        $projects = [
            ['Website Redesign', 'Homepage and component library refresh', '#3b82f6'],
            ['Client Support', 'Tickets and onboarding calls', '#22c55e'],
            ['Internal Tools', 'Admin dashboard and reporting', '#f59e0b'],
            ['Marketing Campaign', 'Q2 launch content and ads', '#ec4899'],
            ['API Integration', 'Third-party sync and webhooks', '#8b5cf6'],
        ];

        $taskNames = [
            'Website Redesign' => ['Wireframes', 'Homepage build', 'Component QA', 'Accessibility audit'],
            'Client Support' => ['Email triage', 'Onboarding call', 'Bug investigation', 'Documentation'],
            'Internal Tools' => ['Reports page', 'Timer API', 'Database cleanup', 'UI polish'],
            'Marketing Campaign' => ['Landing page copy', 'Social posts', 'Ad creatives', 'Analytics review'],
            'API Integration' => ['Auth flow', 'Webhook handler', 'Error monitoring', 'Staging tests'],
        ];

        $projectIds = [];
        $tasksByProject = [];

        $projectStmt = $this->pdo->prepare(
            'INSERT INTO projects (name, description, color) VALUES (?, ?, ?)',
        );
        $taskStmt = $this->pdo->prepare(
            'INSERT INTO tasks (project_id, name, status) VALUES (?, ?, ?)',
        );

        foreach ($projects as [$name, $description, $color]) {
            $projectStmt->execute([$name, $description, $color]);
            $projectId = (int) $this->pdo->lastInsertId();
            $projectIds[$name] = $projectId;
            $tasksByProject[$projectId] = [];

            foreach ($taskNames[$name] as $index => $taskName) {
                $status = match (true) {
                    $index === 0 => 'done',
                    $index === 1 => 'in_progress',
                    default => 'open',
                };
                $taskStmt->execute([$projectId, $taskName, $status]);
                $tasksByProject[$projectId][] = (int) $this->pdo->lastInsertId();
            }
        }

        $entryStmt = $this->pdo->prepare(
            'INSERT INTO time_entries (project_id, task_id, started_at, ended_at, duration_seconds)
            VALUES (?, ?, ?, ?, ?)',
        );

        $today = new DateTimeImmutable('today');
        $projectIdList = array_values($projectIds);

        for ($daysAgo = 35; $daysAgo >= 0; $daysAgo--) {
            $day = $today->modify("-{$daysAgo} days");
            $weekday = (int) $day->format('N');
            $isWeekend = $weekday >= 6;

            $sessionCount = $isWeekend
                ? ($daysAgo % 3 === 0 ? 1 : 0)
                : 2 + ($daysAgo % 3);

            for ($session = 0; $session < $sessionCount; $session++) {
                $projectId = $projectIdList[($daysAgo + $session) % count($projectIdList)];
                $taskIds = $tasksByProject[$projectId];
                $taskId = $taskIds[($daysAgo + $session) % count($taskIds)];

                $startHour = 8 + (($daysAgo + $session * 2) % 9);
                $startMinute = ($session * 17) % 60;
                $durationSeconds = (30 + (($daysAgo * 7 + $session * 13) % 150)) * 60;

                $startedAt = $day->setTime($startHour, $startMinute, 0);
                $endedAt = $startedAt->add(new DateInterval('PT' . $durationSeconds . 'S'));

                $entryStmt->execute([
                    $projectId,
                    $taskId,
                    $startedAt->format('Y-m-d H:i:s'),
                    $endedAt->format('Y-m-d H:i:s'),
                    $durationSeconds,
                ]);
            }
        }

        echo "Seeded " . count($projectIds) . " projects with tasks and sessions for the last 36 days.\n";
    }
}
