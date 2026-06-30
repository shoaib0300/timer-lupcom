<?php

declare(strict_types=1);

namespace Timer\Services;

use DateTimeImmutable;
use Timer\Models\OfficeSession;
use Timer\Repositories\OfficeSessionRepository;
use Timer\Repositories\TimeEntryRepository;

final class OfficeSessionService
{
    public const string GAP_NOTE = 'No project work';

    public function __construct(
        private readonly OfficeSessionRepository $sessions,
        private readonly TimeEntryRepository $timeEntries,
    ) {
    }

    /** @return array{session: ?array<string, mixed>, active: bool} */
    public function getStatus(): array
    {
        $session = $this->sessions->findRunning();

        return [
            'session' => $session !== null ? $this->formatSession($session) : null,
            'active' => $session !== null,
        ];
    }

    /** @return array{session: array<string, mixed>, active: bool} */
    public function getStatusWithStats(): array
    {
        $status = $this->getStatus();

        return array_merge($status, [
            'office_today_seconds' => $this->sessions->totalSecondsToday(),
            'unassigned_today_seconds' => $this->sessions->totalUnassignedToday(),
            'tracked_today_seconds' => $this->timeEntries->totalSecondsToday(),
        ]);
    }

    public function start(): OfficeSession
    {
        $existing = $this->sessions->findRunning();

        if ($existing !== null) {
            return $existing;
        }

        $sessionId = $this->sessions->start();
        $session = $this->sessions->findById($sessionId);

        if ($session === null) {
            throw new \RuntimeException('Failed to start office session.');
        }

        return $session;
    }

    public function pause(int $sessionId): ?OfficeSession
    {
        return $this->sessions->pause($sessionId);
    }

    public function resume(int $sessionId): ?OfficeSession
    {
        return $this->sessions->resume($sessionId);
    }

    /**
     * @return array{session: OfficeSession, gap_entry: ?array<string, mixed>}
     */
    public function stop(int $sessionId): array
    {
        $session = $this->sessions->findById($sessionId);

        if ($session === null || !$session->isRunning()) {
            throw new \InvalidArgumentException('Office session not found or already ended.');
        }

        $endedAt = new DateTimeImmutable();
        $officeDuration = $session->elapsedSeconds();
        $trackedDuring = $this->timeEntries->totalSecondsInRange(
            $session->startedAt,
            $endedAt->format('Y-m-d H:i:s'),
        );
        $unassigned = max(0, $officeDuration - $trackedDuring);

        $gapEntryId = null;
        $gapEntry = null;

        if ($unassigned > 0) {
            $gapStartedAt = $endedAt->modify('-' . $unassigned . ' seconds');
            $gapEntryId = $this->timeEntries->createOfficeGap(
                $unassigned,
                $gapStartedAt,
                $endedAt,
                self::GAP_NOTE,
            );
            $gapEntryModel = $this->timeEntries->findById($gapEntryId);
            if ($gapEntryModel !== null) {
                $gapEntry = [
                    'id' => $gapEntryModel->id,
                    'duration_seconds' => $gapEntryModel->durationSeconds,
                    'notes' => $gapEntryModel->notes,
                ];
            }
        }

        $stopped = $this->sessions->stop($sessionId, $officeDuration, $unassigned, $gapEntryId);

        if ($stopped === null) {
            throw new \RuntimeException('Failed to stop office session.');
        }

        return [
            'session' => $stopped,
            'gap_entry' => $gapEntry,
        ];
    }

    /** @return array<string, mixed> */
    private function formatSession(OfficeSession $session): array
    {
        return [
            'id' => $session->id,
            'work_date' => $session->workDate,
            'started_at' => $session->startedAt,
            'elapsed_seconds' => $session->elapsedSeconds(),
            'is_paused' => $session->isPaused(),
        ];
    }
}
