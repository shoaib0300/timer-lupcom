<?php

declare(strict_types=1);

use Timer\Controllers\DashboardController;
use Timer\Controllers\PlanioController;
use Timer\Controllers\ProjectController;
use Timer\Controllers\ReportsController;
use Timer\Controllers\TaskController;
use Timer\Controllers\TimeEntryController;
use Timer\Controllers\TimerController;

return static function (FastRoute\RouteCollector $r): void {
    $r->get('/', [DashboardController::class, 'index']);
    $r->get('/reports', [ReportsController::class, 'index']);

    $r->get('/settings/planio', [PlanioController::class, 'index']);
    $r->post('/settings/planio', [PlanioController::class, 'save']);
    $r->post('/settings/planio/disconnect', [PlanioController::class, 'disconnect']);
    $r->post('/api/planio/test', [PlanioController::class, 'testApi']);
    $r->get('/api/planio/projects', [PlanioController::class, 'projectsApi']);
    $r->post('/api/planio/sync', [PlanioController::class, 'sync']);

    $r->get('/projects', [ProjectController::class, 'index']);
    $r->get('/projects/create', [ProjectController::class, 'create']);
    $r->post('/projects', [ProjectController::class, 'store']);
    $r->get('/projects/{id:\d+}', [ProjectController::class, 'show']);
    $r->get('/projects/{id:\d+}/edit', [ProjectController::class, 'edit']);
    $r->post('/projects/{id:\d+}', [ProjectController::class, 'update']);
    $r->post('/projects/{id:\d+}/delete', [ProjectController::class, 'destroy']);

    $r->get('/projects/{projectId:\d+}/tasks/create', [TaskController::class, 'create']);
    $r->post('/projects/{projectId:\d+}/tasks', [TaskController::class, 'store']);
    $r->get('/tasks/{id:\d+}/edit', [TaskController::class, 'edit']);
    $r->post('/tasks/{id:\d+}', [TaskController::class, 'update']);
    $r->post('/tasks/{id:\d+}/delete', [TaskController::class, 'destroy']);

    $r->get('/api/projects/{id:\d+}/tasks', [ProjectController::class, 'tasksApi']);

    $r->post('/api/timer/start', [TimerController::class, 'start']);
    $r->post('/api/timer/pause', [TimerController::class, 'pause']);
    $r->post('/api/timer/resume', [TimerController::class, 'resume']);
    $r->post('/api/timer/stop', [TimerController::class, 'stop']);
    $r->get('/api/timer/status', [TimerController::class, 'status']);

    $r->post('/api/time-entries/manual', [TimeEntryController::class, 'storeManual']);
};
