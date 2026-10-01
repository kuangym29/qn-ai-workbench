<?php

use App\Http\Controllers\ContentColumnController;
use App\Http\Controllers\ProjectController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// DEV-001 connectivity check — kept for stack verification.
Route::get('/bootstrap', fn () => Inertia::render('Bootstrap'));

// Landing: project context is first, so go straight to the project list.
Route::redirect('/', '/projects');

// Projects (DEV-W01 frontend; data served by the real DEV-003 API).
Route::get('/projects', fn () => Inertia::render('Projects/Index'))->name('projects.index');
Route::get('/projects/create', fn () => Inertia::render('Projects/Form', ['mode' => 'create']))->name('projects.create');
Route::get('/projects/{project}/edit', fn (int $project) => Inertia::render('Projects/Form', ['mode' => 'edit', 'id' => $project]))->name('projects.edit');

// Columns — always scoped to a project (Project is the highest isolation boundary).
Route::get('/projects/{project}/columns', fn (int $project) => Inertia::render('Columns/Index', ['projectId' => $project]))->name('columns.index');
Route::get('/projects/{project}/columns/create', fn (int $project) => Inertia::render('Columns/Form', ['mode' => 'create', 'projectId' => $project]))->name('columns.create');
Route::get('/projects/{project}/columns/{column}/edit', fn (int $project, int $column) => Inertia::render('Columns/Form', ['mode' => 'edit', 'projectId' => $project, 'id' => $column]))->name('columns.edit');

// Real DEV-003 REST API. Same-origin browser session + CSRF. All responses are wrapped
// as {"data": ...}. The current project lives in the server session (ProjectContext),
// so there is deliberately NO GET /api/projects/{project} show endpoint.
Route::prefix('api')->group(function (): void {
    Route::get('/projects', [ProjectController::class, 'index']);
    Route::post('/projects', [ProjectController::class, 'store']);
    Route::get('/projects/current', [ProjectController::class, 'current']);
    Route::post('/projects/{project}/select', [ProjectController::class, 'select']);
    Route::patch('/projects/{project}', [ProjectController::class, 'update']);

    Route::get('/projects/{project}/columns', [ContentColumnController::class, 'index']);
    Route::post('/projects/{project}/columns', [ContentColumnController::class, 'store']);
    Route::get('/projects/{project}/columns/{column}', [ContentColumnController::class, 'show']);
    Route::patch('/projects/{project}/columns/{column}', [ContentColumnController::class, 'update']);
});
