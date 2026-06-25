<?php

declare(strict_types=1);

namespace Timer\Controllers;

use Timer\Http\Request;
use Timer\Http\Response;
use Timer\Repositories\ProjectRepository;
use Timer\Repositories\TaskRepository;
use Timer\Support\TimeFormatter;

final class ProjectController extends BaseController
{
    public function index(Request $request): Response
    {
        $projects = new ProjectRepository($this->app->db())->allWithStats();

        return $this->view('projects/index.html.twig', [
            'projects' => $projects,
            'format_time' => [TimeFormatter::class, 'secondsToHuman'],
        ]);
    }

    public function create(Request $request): Response
    {
        return $this->view('projects/form.html.twig', [
            'project' => null,
            'action' => '/projects',
            'title' => 'New Project',
        ]);
    }

    public function store(Request $request): Response
    {
        $name = trim((string) $request->input('name', ''));

        if ($name === '') {
            return $this->view('projects/form.html.twig', [
                'project' => null,
                'action' => '/projects',
                'title' => 'New Project',
                'error' => 'Project name is required.',
            ]);
        }

        $repo = new ProjectRepository($this->app->db());
        $id = $repo->create(
            $name,
            $this->nullableString($request->input('description')),
            $this->sanitizeColor((string) $request->input('color', '#3b82f6')),
        );

        return $this->redirect('/projects/' . $id);
    }

    public function show(Request $request, int $id): Response
    {
        $repo = new ProjectRepository($this->app->db());
        $project = $repo->find($id);

        if ($project === null) {
            return Response::html('Project not found', 404);
        }

        $tasks = new TaskRepository($this->app->db())->forProject($id);

        return $this->view('projects/show.html.twig', [
            'project' => $project,
            'tasks' => $tasks,
            'format_time' => [TimeFormatter::class, 'secondsToHuman'],
        ]);
    }

    public function edit(Request $request, int $id): Response
    {
        $project = new ProjectRepository($this->app->db())->find($id);

        if ($project === null) {
            return Response::html('Project not found', 404);
        }

        return $this->view('projects/form.html.twig', [
            'project' => $project,
            'action' => '/projects/' . $id,
            'title' => 'Edit Project',
        ]);
    }

    public function update(Request $request, int $id): Response
    {
        $repo = new ProjectRepository($this->app->db());
        $project = $repo->find($id);

        if ($project === null) {
            return Response::html('Project not found', 404);
        }

        $name = trim((string) $request->input('name', ''));

        if ($name === '') {
            return $this->view('projects/form.html.twig', [
                'project' => $project,
                'action' => '/projects/' . $id,
                'title' => 'Edit Project',
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
        new ProjectRepository($this->app->db())->delete($id);

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
