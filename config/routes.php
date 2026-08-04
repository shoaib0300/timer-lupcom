<?php

declare(strict_types=1);

use Timer\Controllers\AttendanceController;
use Timer\Controllers\AuthController;
use Timer\Controllers\DashboardController;
use Timer\Controllers\OfficeController;
use Timer\Controllers\PlanioController;
use Timer\Controllers\ProfileController;
use Timer\Controllers\ProjectController;
use Timer\Controllers\ReportsController;
use Timer\Controllers\TaskApiController;
use Timer\Controllers\TaskController;
use Timer\Controllers\TimeEntryController;
use Timer\Controllers\TimerController;
use Timer\Controllers\UserController;

return static function (FastRoute\RouteCollector $r): void {
    $r->get('/login', [AuthController::class, 'showLogin']);
    $r->post('/login', [AuthController::class, 'login']);
    $r->get('/register', [AuthController::class, 'showRegister']);
    $r->post('/register', [AuthController::class, 'register']);
    $r->post('/logout', [AuthController::class, 'logout']);
    $r->get('/setup', [AuthController::class, 'showSetup']);
    $r->post('/setup', [AuthController::class, 'setup']);

    $r->get('/', [DashboardController::class, 'index']);
    $r->get('/reports', [ReportsController::class, 'index']);
    $r->get('/reports/export', [ReportsController::class, 'export']);

    $r->get('/attendance', [AttendanceController::class, 'index']);
    $r->post('/attendance/settings', [AttendanceController::class, 'saveSettings']);
    $r->post('/api/attendance/day', [AttendanceController::class, 'saveDay']);
    $r->post('/api/attendance/bulk-days', [AttendanceController::class, 'saveBulkDays']);
    $r->post('/api/attendance/import', [AttendanceController::class, 'importTimetable']);
    $r->post('/api/attendance/holidays', [AttendanceController::class, 'addHoliday']);
    $r->post('/api/attendance/holidays/remove', [AttendanceController::class, 'removeHoliday']);

    $r->get('/office', [OfficeController::class, 'index']);
    $r->get('/office/export', [OfficeController::class, 'export']);

    $r->post('/api/office/start', [OfficeController::class, 'start']);
    $r->post('/api/office/pause', [OfficeController::class, 'pause']);
    $r->post('/api/office/resume', [OfficeController::class, 'resume']);
    $r->post('/api/office/stop', [OfficeController::class, 'stop']);
    $r->get('/api/office/status', [OfficeController::class, 'status']);

    $r->get('/settings/profile', [ProfileController::class, 'index']);
    $r->post('/settings/profile', [ProfileController::class, 'update']);
    $r->post('/settings/profile/password', [ProfileController::class, 'updatePassword']);
    $r->post('/settings/profile/avatar', [ProfileController::class, 'updateAvatar']);
    $r->post('/settings/profile/avatar/remove', [ProfileController::class, 'removeAvatar']);

    $r->get('/settings/planio', [PlanioController::class, 'index']);
    $r->post('/settings/planio', [PlanioController::class, 'save']);
    $r->post('/settings/planio/disconnect', [PlanioController::class, 'disconnect']);

    $r->get('/settings/users', [UserController::class, 'index']);
    $r->post('/settings/users', [UserController::class, 'store']);
    $r->post('/settings/users/deactivate', [UserController::class, 'deactivate']);
    $r->post('/settings/users/activate', [UserController::class, 'activate']);
    $r->post('/api/planio/test', [PlanioController::class, 'testApi']);
    $r->get('/api/planio/projects', [PlanioController::class, 'projectsApi']);
    $r->get('/api/planio/activities', [PlanioController::class, 'activitiesApi']);
    $r->post('/api/planio/sync', [PlanioController::class, 'sync']);
    $r->post('/api/planio/sync-item', [PlanioController::class, 'syncItem']);
    $r->post('/api/planio/sync-time', [PlanioController::class, 'syncTime']);

    $r->get('/projects', [ProjectController::class, 'index']);
    $r->get('/projects/my-tasks', [ProjectController::class, 'myTasks']);
    $r->get('/projects/create', [ProjectController::class, 'create']);
    $r->post('/projects', [ProjectController::class, 'store']);
    $r->get('/projects/{id:\d+}', [ProjectController::class, 'show']);
    $r->get('/projects/{id:\d+}/edit', [ProjectController::class, 'edit']);
    $r->post('/projects/{id:\d+}', [ProjectController::class, 'update']);
    $r->post('/projects/{id:\d+}/delete', [ProjectController::class, 'destroy']);

    $r->get('/projects/{projectId:\d+}/tasks/create', [TaskController::class, 'create']);
    $r->post('/projects/{projectId:\d+}/tasks', [TaskController::class, 'store']);
    $r->get('/tasks/{id:\d+}', [TaskController::class, 'show']);
    $r->get('/tasks/{id:\d+}/edit', [TaskController::class, 'edit']);
    $r->post('/tasks/{id:\d+}', [TaskController::class, 'update']);
    $r->post('/tasks/{id:\d+}/delete', [TaskController::class, 'destroy']);

    $r->get('/api/projects/{id:\d+}/tasks', [ProjectController::class, 'tasksApi']);
    $r->get('/api/tasks/search', [TaskApiController::class, 'search']);
    $r->get('/api/tasks/frequent', [TaskApiController::class, 'frequent']);

    $r->post('/api/timer/start', [TimerController::class, 'start']);
    $r->post('/api/timer/pause', [TimerController::class, 'pause']);
    $r->post('/api/timer/resume', [TimerController::class, 'resume']);
    $r->post('/api/timer/stop', [TimerController::class, 'stop']);
    $r->get('/api/timer/status', [TimerController::class, 'status']);

    $r->post('/api/time-entries/manual', [TimeEntryController::class, 'storeManual']);
    $r->post('/api/time-entries/{id:\d+}/delete', [TimeEntryController::class, 'delete']);
};
