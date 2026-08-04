<?php

declare(strict_types=1);

namespace Timer\Controllers;

use Timer\Http\Request;
use Timer\Http\Response;
use Timer\Repositories\ProjectRepository;
use Timer\Repositories\TaskRepository;
use Timer\Services\PlanioClient;

final class TaskController extends BaseController
{
    public function show(Request $request, int $id): Response
    {
        return $this->redirect('/tasks/' . $id . '/edit');
    }

    public function create(Request $request, int $projectId): Response
    {
        $project = $this->projects()->find($projectId);

        if ($project === null) {
            return Response::html('Project not found', 404);
        }

        return $this->view('tasks/form.html.twig', [
            'task' => null,
            'project' => $project,
            'action' => '/projects/' . $projectId . '/tasks',
            'title' => $this->trans('tasks.title_new'),
            'planio_state' => [
                'is_linked' => false,
                'available' => false,
                'warning' => null,
                'subject' => null,
                'description' => null,
            ],
        ]);
    }

    public function store(Request $request, int $projectId): Response
    {
        $projectRepo = $this->projects();
        $project = $projectRepo->find($projectId);

        if ($project === null) {
            return Response::html('Project not found', 404);
        }

        $name = trim((string) $request->input('name', ''));

        if ($name === '') {
            return $this->view('tasks/form.html.twig', [
                'task' => null,
                'project' => $project,
                'action' => '/projects/' . $projectId . '/tasks',
                'title' => $this->trans('tasks.title_new'),
                'error' => 'Task name is required.',
                'planio_state' => [
                    'is_linked' => false,
                    'available' => false,
                    'warning' => null,
                    'subject' => null,
                    'description' => null,
                ],
            ]);
        }

        $this->tasks()->create(
            $projectId,
            $name,
            $this->nullableString($request->input('description')),
            $this->sanitizeStatus((string) $request->input('status', 'open')),
        );

        return $this->redirect('/projects/' . $projectId);
    }

    public function edit(Request $request, int $id): Response
    {
        $repo = $this->tasks();
        $task = $repo->find($id);

        if ($task === null) {
            return Response::html('Task not found', 404);
        }

        return $this->renderEditView($request, $task);
    }

    public function update(Request $request, int $id): Response
    {
        $repo = $this->tasks();
        $task = $repo->find($id);

        if ($task === null) {
            return Response::html('Task not found', 404);
        }

        $name = trim((string) $request->input('name', ''));
        $planioIssueId = $task->planioIssueId;
        $isPlanioLinked = $planioIssueId !== null && $this->userSettings()->isPlanioConfigured();

        // Planio-linked tasks keep subject/description from Planio (read-only in app).
        if ($isPlanioLinked) {
            $name = $task->name;
        } elseif ($name === '') {
            return $this->renderEditView($request, $task, [
                'error' => 'Task name is required.',
                'form_name' => $name,
                'form_description' => $this->nullableString($request->input('description')),
            ]);
        }

        $description = $isPlanioLinked
            ? $task->description
            : $this->nullableString($request->input('description'));

        if ($isPlanioLinked) {
            $submittedStatusId = $this->optionalPositiveInt($request->input('planio_status_id'));
            $submittedAssigneeId = $this->optionalPositiveInt($request->input('planio_assigned_to_id'));
            $submittedPriorityId = $this->optionalPositiveInt($request->input('planio_priority_id'));
            $submittedStartDate = $this->planioDateFromInput($request->input('planio_start_date'));
            $submittedDueDate = $this->planioDateFromInput($request->input('planio_due_date'));
            $submittedLockVersion = (int) $request->input('planio_lock_version', -1);

            if ($submittedStatusId === null) {
                return $this->renderEditView($request, $task, [
                    'error' => $this->trans('tasks.planio_status_required'),
                ]);
            }

            try {
                $client = $this->planioSync()->clientFromSettings();
                $latestIssue = $client->issue($planioIssueId, true, ['journals', 'attachments']);
                $latestLockVersion = (int) ($latestIssue['lock_version'] ?? 0);

                if ($submittedLockVersion !== $latestLockVersion) {
                    return $this->renderEditView($request, $task, [
                        'error' => $this->trans('tasks.planio_conflict_reload'),
                        'planio_issue' => $latestIssue,
                    ]);
                }

                $effectiveAssigneeId = $submittedAssigneeId;
                if ($effectiveAssigneeId === null) {
                    $effectiveAssigneeId = (int) ($latestIssue['assigned_to']['id'] ?? 0) ?: null;
                }

                $client->updateIssue(
                    $planioIssueId,
                    $submittedStatusId,
                    $effectiveAssigneeId,
                    $submittedLockVersion,
                    $submittedPriorityId,
                    $submittedStartDate,
                    $submittedDueDate,
                );

                $freshIssue = $client->issue($planioIssueId, true, ['journals', 'attachments']);
                $freshName = trim((string) ($freshIssue['subject'] ?? $task->name));
                $freshDescription = trim((string) ($freshIssue['description'] ?? ''));
                $freshDescription = $freshDescription !== '' ? $freshDescription : null;

                $repo->update(
                    $id,
                    $freshName !== '' ? $freshName : $task->name,
                    $freshDescription,
                    PlanioClient::issueStatusLabel($freshIssue),
                );
                $repo->updatePlanioState(
                    $id,
                    PlanioClient::issueStatusLabel($freshIssue),
                    PlanioClient::issueAssigneeLabel($freshIssue),
                );

                return $this->redirectWithFlash('/tasks/' . $id . '/edit', 'success', $this->trans('tasks.planio_sync_success'));
            } catch (\Throwable $exception) {
                return $this->renderEditView($request, $task, [
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $status = $this->sanitizeStatus((string) $request->input('status', $task->status));
        $repo->update($id, $name, $description, $status);

        return $this->redirect('/tasks/' . $id . '/edit');
    }

    public function addPlanioNote(Request $request, int $id): Response
    {
        $task = $this->tasks()->find($id);

        if ($task === null) {
            return Response::html('Task not found', 404);
        }

        $planioIssueId = $task->planioIssueId;
        if ($planioIssueId === null || !$this->userSettings()->isPlanioConfigured()) {
            return $this->redirectWithFlash('/tasks/' . $id . '/edit', 'error', $this->trans('tasks.planio_unavailable'));
        }

        $note = trim((string) $request->input('note', ''));
        $files = $request->files('attachments');
        $maxBytes = 10 * 1024 * 1024;

        if ($note === '' && $files === []) {
            return $this->redirectWithFlash('/tasks/' . $id . '/edit', 'error', $this->trans('tasks.planio_note_required'));
        }

        try {
            $client = $this->planioSync()->clientFromSettings();
            $uploads = [];

            foreach ($files as $file) {
                if ((int) ($file['size'] ?? 0) > $maxBytes) {
                    throw new \RuntimeException($this->trans('tasks.planio_attachment_too_large'));
                }
                if (!is_readable($file['tmp_name']) || (int) filesize($file['tmp_name']) === 0) {
                    throw new \RuntimeException($this->trans('tasks.planio_attachment_invalid'));
                }

                $uploads[] = $client->uploadFile(
                    (string) $file['name'],
                    (string) $file['tmp_name'],
                    (string) ($file['type'] ?? ''),
                );
            }

            $client->addIssueNote($planioIssueId, $note, $uploads);

            return $this->redirectWithFlash('/tasks/' . $id . '/edit', 'success', $this->trans('tasks.planio_note_success'));
        } catch (\Throwable $exception) {
            return $this->redirectWithFlash('/tasks/' . $id . '/edit', 'error', $exception->getMessage());
        }
    }

    public function deletePlanioAttachment(Request $request, int $id, int $attachmentId): Response
    {
        $task = $this->tasks()->find($id);

        if ($task === null) {
            return Response::html('Task not found', 404);
        }

        if ($task->planioIssueId === null || !$this->userSettings()->isPlanioConfigured()) {
            return $this->redirectWithFlash('/tasks/' . $id . '/edit', 'error', $this->trans('tasks.planio_unavailable'));
        }

        if ($attachmentId <= 0) {
            return $this->redirectWithFlash('/tasks/' . $id . '/edit', 'error', $this->trans('tasks.planio_attachment_delete_failed'));
        }

        try {
            $this->planioSync()->clientFromSettings()->deleteAttachment($attachmentId);

            return $this->redirectWithFlash('/tasks/' . $id . '/edit', 'success', $this->trans('tasks.planio_attachment_deleted'));
        } catch (\Throwable $exception) {
            return $this->redirectWithFlash('/tasks/' . $id . '/edit', 'error', $exception->getMessage());
        }
    }

    public function deletePlanioJournalNotes(Request $request, int $id, int $journalId): Response
    {
        $task = $this->tasks()->find($id);

        if ($task === null) {
            return Response::html('Task not found', 404);
        }

        if ($task->planioIssueId === null || !$this->userSettings()->isPlanioConfigured()) {
            return $this->redirectWithFlash('/tasks/' . $id . '/edit', 'error', $this->trans('tasks.planio_unavailable'));
        }

        if ($journalId <= 0) {
            return $this->redirectWithFlash('/tasks/' . $id . '/edit', 'error', $this->trans('tasks.planio_note_delete_failed'));
        }

        try {
            $this->planioSync()->clientFromSettings()->clearJournalNotes($journalId);

            return $this->redirectWithFlash('/tasks/' . $id . '/edit', 'success', $this->trans('tasks.planio_note_deleted'));
        } catch (\Throwable $exception) {
            $message = $exception->getMessage();
            if (
                str_contains($message, 'does not allow this API user to edit or delete issue notes')
                || str_contains($message, 'denied permission')
            ) {
                // Keep Planio user name from the client message when present.
                $message = $this->trans('tasks.planio_note_delete_forbidden')
                    . (preg_match('/Planio user: ([^.]+)\./', $exception->getMessage(), $matches) === 1
                        ? ' (' . trim($matches[1]) . ')'
                        : '');
            }

            return $this->redirectWithFlash('/tasks/' . $id . '/edit', 'error', $message);
        }
    }

    public function planioAttachmentContent(Request $request, int $id, int $attachmentId): Response
    {
        $task = $this->tasks()->find($id);

        if ($task === null) {
            return Response::html('Task not found', 404);
        }

        if ($task->planioIssueId === null || !$this->userSettings()->isPlanioConfigured()) {
            return Response::html($this->trans('tasks.planio_unavailable'), 404);
        }

        if ($attachmentId <= 0) {
            return Response::html('Attachment not found', 404);
        }

        try {
            $file = $this->planioSync()->clientFromSettings()->downloadAttachment($attachmentId);
            $forceDownload = (string) $request->query('download', '') === '1';
            $isImage = str_starts_with(strtolower($file['content_type']), 'image/');

            return Response::binary(
                $file['body'],
                $file['content_type'],
                $file['filename'],
                $isImage && !$forceDownload,
            );
        } catch (\Throwable $exception) {
            return Response::html($exception->getMessage(), 502);
        }
    }

    public function destroy(Request $request, int $id): Response
    {
        $repo = $this->tasks();
        $task = $repo->find($id);

        if ($task === null) {
            return Response::html('Task not found', 404);
        }

        $projectId = $task->projectId;
        $repo->delete($id);

        return $this->redirect('/projects/' . $projectId);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function sanitizeStatus(string $status): string
    {
        return in_array($status, ['open', 'in_progress', 'done'], true) ? $status : 'open';
    }

    /** @param array<string, mixed> $extra */
    private function renderEditView(Request $request, \Timer\Models\Task $task, array $extra = []): Response
    {
        $project = $this->projects()->find($task->projectId);
        $entries = $this->taskEntriesForView($task);
        $planioState = $this->buildPlanioEditState(
            $task,
            $extra['planio_issue'] ?? null,
            $project?->planioId,
        );

        return $this->view('tasks/form.html.twig', array_merge([
            'task' => $task,
            'project' => $project,
            'task_entries' => $entries,
            'action' => '/tasks/' . $task->id,
            'title' => $this->trans('tasks.title_edit'),
            'flash_success' => $this->pullFlash('success'),
            'flash_error' => $this->pullFlash('error'),
            'planio_state' => $planioState,
        ], $extra));
    }

    /** @return list<array<string, mixed>> */
    private function taskEntriesForView(\Timer\Models\Task $task): array
    {
        $rawEntries = $this->timeEntries()->forTask($task->id);

        return array_map(function (\Timer\Models\TimeEntry $entry): array {
            $notes = trim((string) ($entry->notes ?? ''));
            $activity = '';
            $comment = $notes;

            if (preg_match('/^\[([^\]]+)\]\s*(.*)$/', $notes, $matches) === 1) {
                $activity = trim((string) ($matches[1] ?? ''));
                $comment = trim((string) ($matches[2] ?? ''));
            }

            return [
                'id' => $entry->id,
                'project_name' => $entry->projectName,
                'duration_seconds' => (int) ($entry->durationSeconds ?? 0),
                'duration_human' => \Timer\Support\TimeFormatter::secondsToHuman((int) ($entry->durationSeconds ?? 0)),
                'started_at' => $entry->startedAt,
                'ended_at' => $entry->endedAt,
                'activity' => $activity,
                'comment' => $comment,
                'raw_notes' => $notes,
            ];
        }, $rawEntries);
    }

    /** @param array<string, mixed>|null $issueOverride */
    /** @return array<string, mixed> */
    private function buildPlanioEditState(
        \Timer\Models\Task $task,
        ?array $issueOverride = null,
        ?int $knownPlanioProjectId = null,
    ): array {
        $state = [
            'is_linked' => false,
            'available' => false,
            'warning' => null,
            'status_options' => [],
            'assignee_options' => [],
            'priority_options' => [],
            'current_status_id' => null,
            'current_assigned_to_id' => null,
            'current_priority_id' => null,
            'start_date' => null,
            'due_date' => null,
            'lock_version' => null,
            'subject' => null,
            'description' => null,
            'issue_id' => null,
            'author' => null,
            'created_on' => null,
            'updated_on' => null,
            'activity' => [],
        ];

        if ($task->planioIssueId === null) {
            return $state;
        }

        $state['is_linked'] = true;
        $state['issue_id'] = $task->planioIssueId;

        if (!$this->userSettings()->isPlanioConfigured()) {
            $state['warning'] = $this->trans('tasks.planio_unavailable');

            return $state;
        }

        try {
            $client = $this->planioSync()->clientFromSettings();
            $bundle = $issueOverride !== null
                ? [
                    'issue' => $issueOverride,
                    'assignees' => $this->cachedPlanioAssignees(
                        $client,
                        $knownPlanioProjectId
                            ?? (int) ($issueOverride['project']['id'] ?? 0),
                    ),
                ]
                : $this->issueEditBundleCached($client, $task->planioIssueId, $knownPlanioProjectId);

            $issue = $bundle['issue'];
            // Conflict overrides may lack journals/attachments — refill when missing.
            if (
                $issueOverride !== null
                && (!isset($issue['journals']) || !isset($issue['attachments']))
            ) {
                $fullIssue = $client->issue($task->planioIssueId, true, ['journals', 'attachments']);
                $issue['journals'] = $fullIssue['journals'] ?? [];
                $issue['attachments'] = $fullIssue['attachments'] ?? [];
                if (!isset($issue['allowed_statuses'])) {
                    $issue['allowed_statuses'] = $fullIssue['allowed_statuses'] ?? [];
                }
            }

            $statusOptions = [];
            foreach (($issue['allowed_statuses'] ?? []) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $id = (int) ($item['id'] ?? 0);
                $name = trim((string) ($item['name'] ?? ''));
                if ($id <= 0 || $name === '') {
                    continue;
                }
                $statusOptions[] = ['id' => $id, 'name' => $name];
            }

            $currentAssigneeId = (int) ($issue['assigned_to']['id'] ?? 0) ?: null;
            $currentAssigneeName = trim((string) ($issue['assigned_to']['name'] ?? ''));

            $assignees = [];
            foreach ($bundle['assignees'] as $principal) {
                $assignees[(int) $principal['id']] = $principal;
            }

            if ($currentAssigneeId !== null && $currentAssigneeName !== '') {
                $assignees[$currentAssigneeId] = [
                    'id' => $currentAssigneeId,
                    'name' => $currentAssigneeName,
                ];
            }

            usort($assignees, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

            $priorityOptions = $this->cachedPlanioPriorities($client);
            $currentPriorityId = (int) ($issue['priority']['id'] ?? 0) ?: null;
            $currentPriorityName = trim((string) ($issue['priority']['name'] ?? ''));
            if ($currentPriorityId !== null && $currentPriorityName !== '') {
                $found = false;
                foreach ($priorityOptions as $option) {
                    if ((int) $option['id'] === $currentPriorityId) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $priorityOptions[] = ['id' => $currentPriorityId, 'name' => $currentPriorityName];
                }
            }

            $state['available'] = true;
            $state['status_options'] = $statusOptions;
            $state['assignee_options'] = array_values($assignees);
            $state['priority_options'] = $priorityOptions;
            $state['current_status_id'] = (int) ($issue['status']['id'] ?? 0) ?: null;
            $state['current_assigned_to_id'] = $currentAssigneeId;
            $state['current_priority_id'] = $currentPriorityId;
            $state['start_date'] = $this->normalizePlanioDate($issue['start_date'] ?? null);
            $state['due_date'] = $this->normalizePlanioDate($issue['due_date'] ?? null);
            $state['lock_version'] = (int) ($issue['lock_version'] ?? 0);
            $state['subject'] = trim((string) ($issue['subject'] ?? $task->name));
            $state['description'] = trim((string) ($issue['description'] ?? $task->description ?? ''));
            $state['issue_id'] = (int) ($issue['id'] ?? $task->planioIssueId);
            $state['author'] = trim((string) ($issue['author']['name'] ?? ''));
            $state['created_on'] = isset($issue['created_on']) ? (string) $issue['created_on'] : null;
            $state['updated_on'] = isset($issue['updated_on']) ? (string) $issue['updated_on'] : null;
            $state['activity'] = $this->mapPlanioActivity(
                $issue['journals'] ?? [],
                $issue['attachments'] ?? [],
                $task->id,
            );
            if ($state['assignee_options'] === []) {
                $state['warning'] = $this->trans('tasks.planio_no_assignable_users');
            }
        } catch (\Throwable $exception) {
            $state['warning'] = $this->trans('tasks.planio_unavailable') . ' ' . $exception->getMessage();
        }

        return $state;
    }

    /**
     * Group journal notes with attachments added in the same Planio journal entry.
     *
     * @param mixed $journals
     * @param mixed $attachments
     * @return list<array{journal_id:?int, author:string, created_on:?string, notes:string, attachments:list<array<string, mixed>>}>
     */
    private function mapPlanioActivity(mixed $journals, mixed $attachments, int $localTaskId): array
    {
        $attachmentById = [];
        foreach ($this->mapPlanioAttachmentList($attachments, $localTaskId) as $attachment) {
            $attachmentById[$attachment['id']] = $attachment;
        }

        $linkedIds = [];
        $seenJournalIds = [];
        $activity = [];

        if (is_array($journals)) {
            foreach ($journals as $journal) {
                if (!is_array($journal)) {
                    continue;
                }

                $notes = trim((string) ($journal['notes'] ?? ''));
                $journalAttachments = [];
                $details = $journal['details'] ?? [];
                if (is_array($details)) {
                    foreach ($details as $detail) {
                        if (!is_array($detail)) {
                            continue;
                        }
                        if (($detail['property'] ?? '') !== 'attachment') {
                            continue;
                        }
                        // Attachment add has new_value; removals only have old_value.
                        $newValue = $detail['new_value'] ?? null;
                        if ($newValue === null || $newValue === '') {
                            continue;
                        }
                        $attachmentId = (int) ($detail['name'] ?? 0);
                        if ($attachmentId <= 0 || !isset($attachmentById[$attachmentId])) {
                            continue;
                        }
                        if (isset($linkedIds[$attachmentId]) || isset($journalAttachments[$attachmentId])) {
                            // Same file referenced twice in details — keep one.
                            $linkedIds[$attachmentId] = true;
                            continue;
                        }
                        $journalAttachments[$attachmentId] = $attachmentById[$attachmentId];
                        $linkedIds[$attachmentId] = true;
                    }
                }

                $journalAttachments = array_values($journalAttachments);

                if ($notes === '' && $journalAttachments === []) {
                    continue;
                }

                $journalId = (int) ($journal['id'] ?? 0);
                if ($journalId > 0 && isset($seenJournalIds[$journalId])) {
                    continue;
                }
                if ($journalId > 0) {
                    $seenJournalIds[$journalId] = true;
                }

                $activity[] = [
                    'journal_id' => $journalId > 0 ? $journalId : null,
                    'author' => trim((string) ($journal['user']['name'] ?? '')),
                    'created_on' => isset($journal['created_on']) ? (string) $journal['created_on'] : null,
                    'notes' => $notes,
                    'attachments' => $journalAttachments,
                ];
            }
        }

        foreach ($attachmentById as $attachmentId => $attachment) {
            if (isset($linkedIds[$attachmentId])) {
                continue;
            }
            $activity[] = [
                'journal_id' => null,
                'author' => $attachment['author'],
                'created_on' => $attachment['created_on'],
                'notes' => '',
                'attachments' => [$attachment],
            ];
        }

        usort(
            $activity,
            static function (array $a, array $b): int {
                return strcmp((string) ($b['created_on'] ?? ''), (string) ($a['created_on'] ?? ''));
            },
        );

        return $activity;
    }

    /**
     * @param mixed $attachments
     * @return list<array{id:int, filename:string, filesize:int, content_type:string, author:string, created_on:?string, download_url:string, is_image:bool}>
     */
    private function mapPlanioAttachmentList(mixed $attachments, int $localTaskId): array
    {
        if (!is_array($attachments)) {
            return [];
        }

        $mapped = [];
        foreach ($attachments as $attachment) {
            if (!is_array($attachment)) {
                continue;
            }
            $id = (int) ($attachment['id'] ?? 0);
            $filename = trim((string) ($attachment['filename'] ?? ''));
            if ($id <= 0 || $filename === '') {
                continue;
            }

            $contentType = trim((string) ($attachment['content_type'] ?? ''));
            $proxyUrl = '/tasks/' . $localTaskId . '/planio-attachments/' . $id . '/content';
            $isImage = str_starts_with(strtolower($contentType), 'image/');
            if (!$isImage) {
                $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
                $isImage = in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'svg'], true);
            }

            $mapped[] = [
                'id' => $id,
                'filename' => $filename,
                'filesize' => (int) ($attachment['filesize'] ?? 0),
                'content_type' => $contentType,
                'author' => trim((string) ($attachment['author']['name'] ?? '')),
                'created_on' => isset($attachment['created_on']) ? (string) $attachment['created_on'] : null,
                'download_url' => $proxyUrl,
                'is_image' => $isImage,
            ];
        }

        return $mapped;
    }

    /**
     * @return list<array{id:int,name:string}>
     */
    private function cachedPlanioPriorities(PlanioClient $client): array
    {
        $key = 'planio_issue_priorities';
        $entry = $_SESSION[$key] ?? null;
        if (is_array($entry) && (int) ($entry['expires'] ?? 0) >= time() && is_array($entry['data'] ?? null)) {
            /** @var list<array{id:int,name:string}> $data */
            $data = $entry['data'];

            return $data;
        }

        $priorities = $client->issuePriorities();
        $_SESSION[$key] = [
            'expires' => time() + 300,
            'data' => $priorities,
        ];

        return $priorities;
    }

    /**
     * @return array{issue: array<string, mixed>, assignees: list<array{id:int,name:string}>}
     */
    private function issueEditBundleCached(
        PlanioClient $client,
        int $planioIssueId,
        ?int $knownPlanioProjectId,
    ): array {
        if ($knownPlanioProjectId !== null && $knownPlanioProjectId > 0) {
            $cachedAssignees = $this->readPlanioAssigneeCache($knownPlanioProjectId);
            if ($cachedAssignees !== null) {
                return [
                    'issue' => $client->issue($planioIssueId, true, ['journals', 'attachments']),
                    'assignees' => $cachedAssignees,
                ];
            }
        }

        $bundle = $client->issueEditBundle($planioIssueId, $knownPlanioProjectId);
        $projectId = $knownPlanioProjectId !== null && $knownPlanioProjectId > 0
            ? $knownPlanioProjectId
            : (int) ($bundle['issue']['project']['id'] ?? 0);
        if ($projectId > 0) {
            $this->writePlanioAssigneeCache($projectId, $bundle['assignees']);
        }

        return $bundle;
    }

    /**
     * @return list<array{id:int,name:string}>
     */
    private function cachedPlanioAssignees(PlanioClient $client, int $planioProjectId): array
    {
        if ($planioProjectId <= 0) {
            return [];
        }

        $cached = $this->readPlanioAssigneeCache($planioProjectId);
        if ($cached !== null) {
            return $cached;
        }

        $assignees = $client->assignablePrincipalsForProject($planioProjectId);
        $this->writePlanioAssigneeCache($planioProjectId, $assignees);

        return $assignees;
    }

    /** @return list<array{id:int,name:string}>|null */
    private function readPlanioAssigneeCache(int $planioProjectId): ?array
    {
        $key = 'planio_assignees_' . $planioProjectId;
        $entry = $_SESSION[$key] ?? null;
        if (!is_array($entry)) {
            return null;
        }
        $expires = (int) ($entry['expires'] ?? 0);
        if ($expires < time() || !is_array($entry['data'] ?? null)) {
            unset($_SESSION[$key]);

            return null;
        }

        /** @var list<array{id:int,name:string}> $data */
        $data = $entry['data'];

        return $data;
    }

    /** @param list<array{id:int,name:string}> $assignees */
    private function writePlanioAssigneeCache(int $planioProjectId, array $assignees): void
    {
        $_SESSION['planio_assignees_' . $planioProjectId] = [
            'expires' => time() + 300,
            'data' => $assignees,
        ];
    }

    /** Empty string clears the Planio date. */
    private function planioDateFromInput(mixed $value): string
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return '';
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $matches) === 1) {
            return substr($matches[0], 0, 10);
        }

        return '';
    }

    /** Normalize Planio issue date for form display. */
    private function normalizePlanioDate(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value, $matches) === 1) {
            return substr($matches[0], 0, 10);
        }

        return null;
    }

    private function optionalPositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
