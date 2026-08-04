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

        if ($name === '') {
            return $this->renderEditView($request, $task, [
                'error' => 'Task name is required.',
                'form_name' => $name,
                'form_description' => $this->nullableString($request->input('description')),
            ]);
        }

        $description = $this->nullableString($request->input('description'));
        $planioIssueId = $task->planioIssueId;

        if ($planioIssueId !== null && $this->userSettings()->isPlanioConfigured()) {
            $submittedStatusId = $this->optionalPositiveInt($request->input('planio_status_id'));
            $submittedAssigneeId = $this->optionalPositiveInt($request->input('planio_assigned_to_id'));
            $submittedLockVersion = (int) $request->input('planio_lock_version', -1);

            if ($submittedStatusId === null) {
                return $this->renderEditView($request, $task, [
                    'error' => $this->trans('tasks.planio_status_required'),
                    'form_name' => $name,
                    'form_description' => $description,
                ]);
            }

            try {
                $client = $this->planioSync()->clientFromSettings();
                $latestIssue = $client->issue($planioIssueId, true, true);
                $latestLockVersion = (int) ($latestIssue['lock_version'] ?? 0);

                if ($submittedLockVersion !== $latestLockVersion) {
                    return $this->renderEditView($request, $task, [
                        'error' => $this->trans('tasks.planio_conflict_reload'),
                        'form_name' => $name,
                        'form_description' => $description,
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
                );

                $freshIssue = $client->issue($planioIssueId, true, true);
                $repo->update(
                    $id,
                    $name,
                    $description,
                    PlanioClient::issueStatusLabel($freshIssue),
                );
                $repo->updatePlanioState(
                    $id,
                    PlanioClient::issueStatusLabel($freshIssue),
                    PlanioClient::issueAssigneeLabel($freshIssue),
                );

                return $this->redirect('/tasks/' . $id . '/edit?success=' . urlencode($this->trans('tasks.planio_sync_success')));
            } catch (\Throwable $exception) {
                return $this->renderEditView($request, $task, [
                    'error' => $exception->getMessage(),
                    'form_name' => $name,
                    'form_description' => $description,
                ]);
            }
        }

        $status = $this->sanitizeStatus((string) $request->input('status', $task->status));
        $repo->update($id, $name, $description, $status);

        return $this->redirect('/tasks/' . $id . '/edit');
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
        $planioState = $this->buildPlanioEditState($task, $extra['planio_issue'] ?? null);

        return $this->view('tasks/form.html.twig', array_merge([
            'task' => $task,
            'project' => $project,
            'task_entries' => $entries,
            'action' => '/tasks/' . $task->id,
            'title' => $this->trans('tasks.title_edit'),
            'flash_success' => $request->query('success'),
            'flash_error' => $request->query('error'),
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
    private function buildPlanioEditState(\Timer\Models\Task $task, ?array $issueOverride = null): array
    {
        $state = [
            'is_linked' => false,
            'available' => false,
            'warning' => null,
            'status_options' => [],
            'assignee_options' => [],
            'current_status_id' => null,
            'current_assigned_to_id' => null,
            'lock_version' => null,
        ];

        if ($task->planioIssueId === null) {
            return $state;
        }

        $state['is_linked'] = true;

        if (!$this->userSettings()->isPlanioConfigured()) {
            $state['warning'] = $this->trans('tasks.planio_unavailable');

            return $state;
        }

        try {
            $client = $this->planioSync()->clientFromSettings();
            $issue = $issueOverride ?? $client->issue($task->planioIssueId, true, true);
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

            $assignees = [];
            foreach (($issue['assignable_users'] ?? []) as $user) {
                if (!is_array($user)) {
                    continue;
                }
                $id = (int) ($user['id'] ?? 0);
                $name = trim((string) ($user['name'] ?? ''));
                if ($id <= 0 || $name === '') {
                    continue;
                }
                $assignees[$id] = ['id' => $id, 'name' => $name];
            }

            if ($assignees === []) {
                $planioProjectId = (int) ($issue['project']['id'] ?? 0);
                foreach ($client->projectMemberships($planioProjectId) as $user) {
                    $assignees[(int) $user['id']] = $user;
                }
            }

            $state['available'] = true;
            $state['status_options'] = $statusOptions;
            $state['assignee_options'] = array_values($assignees);
            $state['current_status_id'] = (int) ($issue['status']['id'] ?? 0) ?: null;
            $state['current_assigned_to_id'] = (int) ($issue['assigned_to']['id'] ?? 0) ?: null;
            $state['lock_version'] = (int) ($issue['lock_version'] ?? 0);
        } catch (\Throwable $exception) {
            $state['warning'] = $this->trans('tasks.planio_unavailable') . ' ' . $exception->getMessage();
        }

        return $state;
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
