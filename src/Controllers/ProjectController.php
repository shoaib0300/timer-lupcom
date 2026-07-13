<?php

declare(strict_types=1);

namespace Timer\Controllers;

use Timer\Http\Request;
use Timer\Http\Response;
use Timer\Repositories\ProjectRepository;
use Timer\Repositories\TaskRepository;

final class ProjectController extends BaseController
{
    public function index(Request $request): Response
    {
        $user = $this->requireUser();
        $settings = $this->userSettings();
        $assigneeFilter = (string) $request->query('assignee', '') === 'me';
        $assigneeLabels = $this->assigneeLabels($user, $settings);

        $projects = $this->projects()->allWithStats();
        $timerService = $this->timerService();

        $timerStatus = $timerService->getStatus();
        $runningProjectIds = array_map(
            static fn (array $timer): int => (int) $timer['project_id'],
            $timerStatus['timers'],
        );
        $projects = \Timer\Support\ProjectSorter::forDashboard($projects, $runningProjectIds);

        $myTasks = $assigneeFilter
            ? $this->tasks()->assignedToLabels($assigneeLabels, $user->id)
            : [];

        return $this->view('projects/index.html.twig', [
            'projects' => $projects,
            'my_tasks' => $myTasks,
            'assignee_filter' => $assigneeFilter,
            'has_assignee_labels' => $assigneeLabels !== [],
        ]);
    }

    /** @return list<string> */
    private function assigneeLabels(\Timer\Http\AuthenticatedUser $user, \Timer\Repositories\UserSettingsRepository $settings): array
    {
        return array_values(array_unique(array_filter([
            $user->name,
            $settings->get('planio.user_name'),
            $settings->get('planio.user_login'),
        ], static fn (?string $value): bool => $value !== null && trim($value) !== '')));
    }

    public function create(Request $request): Response
    {
        return $this->view('projects/form.html.twig', [
            'project' => null,
            'action' => '/projects',
            'title' => $this->trans('projects.new'),
        ]);
    }

    public function store(Request $request): Response
    {
        $name = trim((string) $request->input('name', ''));

        if ($name === '') {
            return $this->view('projects/form.html.twig', [
                'project' => null,
                'action' => '/projects',
                'title' => $this->trans('projects.new'),
                'error' => 'Project name is required.',
            ]);
        }

        $repo = $this->projects();
        $id = $repo->create(
            $name,
            $this->nullableString($request->input('description')),
            $this->sanitizeColor((string) $request->input('color', '#3b82f6')),
        );

        return $this->redirect('/projects/' . $id);
    }

    public function show(Request $request, int $id): Response
    {
        $repo = $this->projects();
        $project = $repo->find($id);

        if ($project === null) {
            return Response::html('Project not found', 404);
        }

        $tasks = $this->tasks()->forProject($id);

        return $this->view('projects/show.html.twig', [
            'project' => $project,
            'tasks' => $tasks,
        ]);
    }

    public function tasksApi(Request $request, int $id): Response
    {
        $project = $this->projects()->find($id);

        if ($project === null) {
            return $this->json(['error' => 'Project not found.'], 404);
        }

        $tasks = $this->tasks()->forProject($id);

        return $this->json([
            'tasks' => array_map(static fn ($task) => [
                'id' => $task->id,
                'name' => $task->name,
                'description' => $task->description,
                'status' => $task->status,
                'planio_issue_id' => $task->planioIssueId,
                'planio_assignee' => $task->planioAssignee,
                'total_seconds' => $task->totalSeconds,
                'total_human' => \Timer\Support\TimeFormatter::secondsToHuman($task->totalSeconds),
            ], $tasks),
        ]);
    }

    public function edit(Request $request, int $id): Response
    {
        $project = $this->projects()->find($id);

        if ($project === null) {
            return Response::html('Project not found', 404);
        }

        return $this->view('projects/form.html.twig', [
            'project' => $project,
            'action' => '/projects/' . $id,
            'title' => $this->trans('projects.edit'),
        ]);
    }

    public function update(Request $request, int $id): Response
    {
        $repo = $this->projects();
        $project = $repo->find($id);

        if ($project === null) {
            return Response::html('Project not found', 404);
        }

        $name = trim((string) $request->input('name', ''));

        if ($name === '') {
            return $this->view('projects/form.html.twig', [
                'project' => $project,
                'action' => '/projects/' . $id,
                'title' => $this->trans('projects.edit'),
                'error' => 'Project name is required.',
            ]);
        }

        $repo->update(
            $id,
            $name,
            $this->nullableString($request->input('description')),
            $this->sanitizeColor((string) $request->input('color', $project->color)),
        );

        return $this->redirect('/projects/' . $id);
    }

    public function destroy(Request $request, int $id): Response
    {
        $repo = $this->projects();

        if ($repo->find($id) === null) {
            return Response::html('Project not found', 404);
        }

        $repo->delete($id);

        return $this->redirect('/projects');
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function sanitizeColor(string $color): string
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? $color : '#3b82f6';
    }
}
