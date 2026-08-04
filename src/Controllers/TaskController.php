<?php

declare(strict_types=1);

namespace Timer\Controllers;

use Timer\Http\Request;
use Timer\Http\Response;
use Timer\Repositories\ProjectRepository;
use Timer\Repositories\TaskRepository;

final class TaskController extends BaseController
{
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

        $project = $this->projects()->find($task->projectId);
        $rawEntries = $this->timeEntries()->forTask($task->id);
        $entries = array_map(function (\Timer\Models\TimeEntry $entry): array {
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

        return $this->view('tasks/form.html.twig', [
            'task' => $task,
            'project' => $project,
            'task_entries' => $entries,
            'action' => '/tasks/' . $id,
            'title' => $this->trans('tasks.title_edit'),
        ]);
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
            $project = $this->projects()->find($task->projectId);

            return $this->view('tasks/form.html.twig', [
                'task' => $task,
                'project' => $project,
                'action' => '/tasks/' . $id,
                'title' => $this->trans('tasks.title_edit'),
                'error' => 'Task name is required.',
            ]);
        }

        $status = $this->sanitizeStatus((string) $request->input('status', $task->status));

        $repo->update(
            $id,
            $name,
            $this->nullableString($request->input('description')),
            $status,
        );

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
}
