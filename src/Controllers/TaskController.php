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
        $project = new ProjectRepository($this->app->db())->find($projectId);

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
        $projectRepo = new ProjectRepository($this->app->db());
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

        new TaskRepository($this->app->db())->create(
            $projectId,
            $name,
            $this->nullableString($request->input('description')),
            $this->sanitizeStatus((string) $request->input('status', 'open')),
        );

        return $this->redirect('/projects/' . $projectId);
    }

    public function edit(Request $request, int $id): Response
    {
        $repo = new TaskRepository($this->app->db());
        $task = $repo->find($id);

        if ($task === null) {
            return Response::html('Task not found', 404);
        }

        $project = new ProjectRepository($this->app->db())->find($task->projectId);

        return $this->view('tasks/form.html.twig', [
            'task' => $task,
            'project' => $project,
            'action' => '/tasks/' . $id,
            'title' => $this->trans('tasks.title_edit'),
        ]);
    }

    public function update(Request $request, int $id): Response
    {
        $repo = new TaskRepository($this->app->db());
        $task = $repo->find($id);

        if ($task === null) {
            return Response::html('Task not found', 404);
        }

        $name = trim((string) $request->input('name', ''));

        if ($name === '') {
            $project = new ProjectRepository($this->app->db())->find($task->projectId);

            return $this->view('tasks/form.html.twig', [
                'task' => $task,
                'project' => $project,
                'action' => '/tasks/' . $id,
                'title' => $this->trans('tasks.title_edit'),
                'error' => 'Task name is required.',
            ]);
        }

        $status = $task->planioIssueId !== null
            ? $task->status
            : $this->sanitizeStatus((string) $request->input('status', $task->status));

        $repo->update(
            $id,
            $name,
            $this->nullableString($request->input('description')),
            $status,
        );

        return $this->redirect('/projects/' . $task->projectId);
    }

    public function destroy(Request $request, int $id): Response
    {
        $repo = new TaskRepository($this->app->db());
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
