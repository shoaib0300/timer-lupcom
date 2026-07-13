<?php

declare(strict_types=1);

namespace Timer\Controllers;

use Timer\Http\Request;
use Timer\Http\Response;
use Timer\Services\OfficeExportService;
use Timer\Support\DateHelper;
use Timer\Support\TimeFormatter;

final class OfficeController extends BaseController
{
    public function index(Request $request): Response
    {
        [$from, $to] = $this->resolveDateRange($request);

        $officeService = $this->officeService();
        $dailyOverview = $officeService->buildDailyOverview($from, $to);
        $periodTotalSeconds = $officeService->totalWorkSeconds($dailyOverview);

        return $this->view('office/index.html.twig', [
            'daily_overview' => $dailyOverview,
            'from' => $from,
            'to' => $to,
            'work_today_seconds' => $officeService->workSecondsToday(),
            'period_total' => TimeFormatter::secondsToHuman($periodTotalSeconds),
            'today_date' => DateHelper::todayString(),
            'export_query' => http_build_query(['from' => $from, 'to' => $to]),
        ]);
    }

    public function export(Request $request): Response
    {
        $format = strtolower((string) $request->query('format', 'csv'));
        if (!in_array($format, ['csv', 'pdf'], true)) {
            return $this->redirect('/office');
        }

        [$from, $to] = $this->resolveDateRange($request);
        $officeService = $this->officeService();
        $dailyOverview = $officeService->buildDailyOverview($from, $to);
        $totalSeconds = $officeService->totalWorkSeconds($dailyOverview);

        $exportService = new OfficeExportService($this->app->view(), $this->app->translator());
        $stem = $exportService->filenameStem($from, $to);

        if ($format === 'pdf') {
            $body = $exportService->toPdf(
                $dailyOverview,
                $from,
                $to,
                $totalSeconds,
                $this->app->translator()->locale(),
            );

            return Response::download($body, 'application/pdf', $stem . '.pdf');
        }

        $body = $exportService->toCsv($dailyOverview, $from, $to, $totalSeconds);

        return Response::download($body, 'text/csv; charset=utf-8', $stem . '.csv');
    }

    /** @return array{0: string, 1: string} */
    private function resolveDateRange(Request $request): array
    {
        $from = trim((string) $request->query('from', ''));
        $to = trim((string) $request->query('to', ''));

        if ($from === '' || $to === '') {
            $today = new \DateTimeImmutable('today');
            $from = $today->modify('first day of this month')->format('Y-m-d');
            $to = $today->format('Y-m-d');
        }

        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return [$from, $to];
    }

    public function status(Request $request): Response
    {
        return $this->json($this->officeService()->getStatusWithStats());
    }

    public function start(Request $request): Response
    {
        if ($response = $this->validateCsrf($request)) {
            return $response;
        }

        $service = $this->officeService();
        $alreadyActive = $service->getStatus()['active'];

        try {
            $service->start();
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
        if ($response = $this->validateCsrf($request)) {
            return $response;
        }

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
        if ($response = $this->validateCsrf($request)) {
            return $response;
        }

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
        if ($response = $this->validateCsrf($request)) {
            return $response;
        }

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
}
