<?php

declare(strict_types=1);

namespace Timer\Services;

use DateTimeImmutable;
use Timer\Repositories\ProjectRepository;
use Timer\Repositories\TaskRepository;
use Timer\Repositories\TimeEntryRepository;
use Timer\Repositories\UserSettingsRepository;

final class PlanioTimeImportService
{
    public function __construct(
        private readonly UserSettingsRepository $settings,
        private readonly ProjectRepository $projects,
        private readonly TaskRepository $tasks,
        private readonly TimeEntryRepository $timeEntries,
    ) {
    }

    /**
     * @return array{imported: int, updated: int, skipped: int, from: string, to: string}
     */
    public function sync(string $from, string $to): array
    {
        $config = $this->settings->planioConfig();
        $planioUserId = (int) ($config['user_id'] ?? 0);

        if ($planioUserId <= 0) {
            throw new \InvalidArgumentException('Connect Planio and verify your account first.');
        }

        $client = new PlanioClient(
            PlanioClient::normalizeBaseUrl((string) ($config['base_url'] ?? '')),
            (string) ($config['api_key'] ?? ''),
        );

        $remoteEntries = $client->timeEntriesForUser($planioUserId, $from, $to);
        $stats = [
            'imported' => 0,
            'updated' => 0,
            'skipped' => 0,
            'from' => $from,
            'to' => $to,
        ];

        foreach ($remoteEntries as $remote) {
            $result = $this->importEntry($remote);
            if ($result === 'imported') {
                $stats['imported']++;
            } elseif ($result === 'updated') {
                $stats['updated']++;
            } else {
                $stats['skipped']++;
            }
        }

        $this->settings->set('planio.last_time_import_at', (new DateTimeImmutable())->format('Y-m-d H:i:s'));

        return $stats;
    }

    /** @param array<string, mixed> $remote */
    private function importEntry(array $remote): string
    {
        $planioTimeEntryId = (int) ($remote['id'] ?? 0);
        if ($planioTimeEntryId <= 0) {
            return 'skipped';
        }

        $planioProjectId = (int) ($remote['project']['id'] ?? 0);
        if ($planioProjectId <= 0) {
            return 'skipped';
        }

        $project = $this->projects->findByPlanioId($planioProjectId);
        if ($project === null) {
            return 'skipped';
        }

        $hours = (float) ($remote['hours'] ?? 0);
        if ($hours <= 0) {
            return 'skipped';
        }

        $durationSeconds = max(60, (int) round($hours * 3600));
        $spentOn = (string) ($remote['spent_on'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $spentOn)) {
            return 'skipped';
        }

        $taskId = null;
        $planioIssueId = (int) ($remote['issue']['id'] ?? 0);
        if ($planioIssueId > 0) {
            $task = $this->tasks->findByPlanioIssueId($project->id, $planioIssueId);
            $taskId = $task?->id;
        }

        $comments = trim((string) ($remote['comments'] ?? ''));
        $notes = $comments !== '' ? $comments : null;

        [$startedAt, $endedAt, $durationSeconds] = $this->resolveTimes(
            $spentOn,
            $durationSeconds,
            isset($remote['created_on']) ? (string) $remote['created_on'] : null,
        );

        return $this->timeEntries->upsertFromPlanio(
            $planioTimeEntryId,
            $project->id,
            $taskId,
            $durationSeconds,
            $startedAt,
            $endedAt,
            $notes,
        );
    }

    /**
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable, 2: int}
     */
    private function resolveTimes(string $spentOn, int $durationSeconds, ?string $createdOn): array
    {
        $day = new DateTimeImmutable($spentOn);
        $today = new DateTimeImmutable('today');
        $endedAt = null;

        if ($createdOn !== null && $createdOn !== '') {
            try {
                $endedAt = new DateTimeImmutable($createdOn);
            } catch (\Exception) {
                $endedAt = null;
            }
        }

        if ($endedAt === null) {
            $endedAt = $day->format('Y-m-d') === $today->format('Y-m-d')
                ? new DateTimeImmutable()
                : $day->setTime(17, 0, 0);
        }

        if ($endedAt->format('Y-m-d') !== $day->format('Y-m-d')) {
            $endedAt = $day->setTime(
                (int) $endedAt->format('H'),
                (int) $endedAt->format('i'),
                (int) $endedAt->format('s'),
            );
        }

        $startedAt = $endedAt->modify('-' . $durationSeconds . ' seconds');
        $dayStart = $day->setTime(0, 0, 0);

        if ($startedAt < $dayStart) {
            $startedAt = $dayStart;
            $durationSeconds = max(60, $endedAt->getTimestamp() - $startedAt->getTimestamp());
        }

        return [$startedAt, $endedAt, $durationSeconds];
    }
}
