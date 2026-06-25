<?php

declare(strict_types=1);

use Timer\Controllers\DashboardController;
use Timer\Controllers\ProjectController;
use Timer\Controllers\TaskController;
use Timer\Controllers\TimerController;

return static function (FastRoute\RouteCollector $r): void {
    $r->get('/', [DashboardController::class, 'index']);

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

    $r->post('/api/timer/start', [TimerController::class, 'start']);
    $r->post('/api/timer/stop', [TimerController::class, 'stop']);
    $r->get('/api/timer/status', [TimerController::class, 'status']);
};
