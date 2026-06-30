<?php

declare(strict_types=1);

namespace Timer\Controllers;

use Timer\Http\Request;
use Timer\Http\Response;
use Timer\Repositories\OfficeSessionRepository;
use Timer\Repositories\TimeEntryRepository;
use Timer\Services\OfficeSessionService;
use Timer\Support\DateHelper;
use Timer\Support\TimeFormatter;

final class OfficeController extends BaseController
{
    public function index(Request $request): Response
    {
        $from = trim((string) $request->query('from', ''));
        $to = trim((string) $request->query('to', ''));

        if ($from === '' || $to === '') {
            $today = new \DateTimeImmutable('today');
            $from = $today->modify('first day of this month')->format('Y-m-d');
            $to = $today->format('Y-m-d');
        }

        $sessions = new OfficeSessionRepository($this->app->db());
        $timeEntries = new TimeEntryRepository($this->app->db());
        $officeService = $this->officeService();

        $rows = [];
        foreach ($sessions->forDateRange($from, $to) as $session) {
            $tracked = 0;
            if ($session->endedAt !== null) {
                $tracked = $timeEntries->totalSecondsInRange(
                    $session->startedAt,
                    $session->endedAt,
                );
            }

            $rows[] = [
                'session' => $session,
                'tracked_seconds' => $tracked,
            ];
        }

        $monthOfficeTotal = array_sum($sessions->dailyTotals($from, $to));
        $monthTrackedTotal = $timeEntries->totalSecondsByDateRange($from, $to);

        return $this->view('office/index.html.twig', [
            'sessions' => $rows,
            'from' => $from,
            'to' => $to,
            'office_status' => $officeService->getStatusWithStats(),
            'month_office_total' => TimeFormatter::secondsToHuman($monthOfficeTotal),
            'month_tracked_total' => TimeFormatter::secondsToHuman($monthTrackedTotal),
            'today_date' => DateHelper::todayString(),
        ]);
    }

    public function status(Request $request): Response
    {
        return $this->json($this->officeService()->getStatusWithStats());
    }

    public function start(Request $request): Response
    {
        $service = $this->officeService();
        $alreadyActive = $service->getStatus()['active'];

        try {
            $session = $service->start();
        } catch (\RuntimeException $exception) {
            return $this->json(['error' => $exception->getMessage()], 500);
        }

        $status = $service->getStatusWithStats();

        return $this->json([
            'message' => $alreadyActive
                ? $this->trans('office.already_active')
                : $this->trans('office.started'),
            'already_active' => $alreadyActive,
            'session' => $status['session'],
            'status' => $status,
        ]);
    }

    public function pause(Request $request): Response
    {
        $sessionId = (int) $request->input('session_id', 0);

        if ($sessionId <= 0) {
            return $this->json(['error' => 'Session ID is required.'], 422);
        }

        $service = $this->officeService();
        $session = $service->pause($sessionId);

        if ($session === null) {
            return $this->json(['error' => 'Session not found or already paused.'], 422);
        }

        return $this->json([
            'message' => $this->trans('office.paused'),
            'status' => $service->getStatusWithStats(),
        ]);
    }

    public function resume(Request $request): Response
    {
        $sessionId = (int) $request->input('session_id', 0);

        if ($sessionId <= 0) {
            return $this->json(['error' => 'Session ID is required.'], 422);
        }

        $service = $this->officeService();
        $session = $service->resume($sessionId);

        if ($session === null) {
            return $this->json(['error' => 'Session not found or not paused.'], 422);
        }

        return $this->json([
            'message' => $this->trans('office.resumed'),
            'status' => $service->getStatusWithStats(),
        ]);
    }

    public function stop(Request $request): Response
    {
        $sessionId = (int) $request->input('session_id', 0);

        if ($sessionId <= 0) {
            return $this->json(['error' => 'Session ID is required.'], 422);
        }

        $service = $this->officeService();

        try {
            $result = $service->stop($sessionId);
        } catch (\InvalidArgumentException $exception) {
            return $this->json(['error' => $exception->getMessage()], 422);
        }

        $session = $result['session'];
        $gapEntry = $result['gap_entry'];
        $status = $service->getStatusWithStats();

        return $this->json([
            'message' => $this->trans('office.stopped'),
            'status' => $status,
            'session' => [
                'id' => $session->id,
                'duration_seconds' => $session->durationSeconds,
                'duration_human' => TimeFormatter::secondsToHuman((int) $session->durationSeconds),
                'unassigned_seconds' => $session->unassignedSeconds,
                'unassigned_human' => TimeFormatter::secondsToHuman((int) ($session->unassignedSeconds ?? 0)),
                'ended_at' => $session->endedAt,
            ],
            'gap_entry' => $gapEntry !== null ? [
                'id' => $gapEntry['id'],
                'duration_seconds' => $gapEntry['duration_seconds'],
                'duration_human' => TimeFormatter::secondsToHuman((int) $gapEntry['duration_seconds']),
                'notes' => $gapEntry['notes'],
            ] : null,
            'total_today_seconds' => $status['tracked_today_seconds'],
            'total_today_human' => TimeFormatter::secondsToHuman($status['tracked_today_seconds']),
        ]);
    }

    private function officeService(): OfficeSessionService
    {
        $db = $this->app->db();

        return new OfficeSessionService(
            new OfficeSessionRepository($db),
            new TimeEntryRepository($db),
        );
    }
}
