<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// DEV-001 connectivity check — kept for stack verification.
Route::get('/bootstrap', fn () => Inertia::render('Bootstrap'));

// Landing: project context is first, so go straight to the project list.
Route::redirect('/', '/projects');

// Projects (DEV-W01 frontend; data is served via the client-side Mock layer).
Route::get('/projects', fn () => Inertia::render('Projects/Index'))->name('projects.index');
Route::get('/projects/create', fn () => Inertia::render('Projects/Form', ['mode' => 'create']))->name('projects.create');
Route::get('/projects/{project}/edit', fn (string $project) => Inertia::render('Projects/Form', ['mode' => 'edit', 'id' => $project]))->name('projects.edit');

// Columns — always scoped to a project (Project is the highest isolation boundary).
// Route params are plain strings (no model binding) so no DB query happens here; the
// frontend Mock layer handles data. Backend CRUD belongs to DEV-002.
Route::get('/projects/{project}/columns', fn (string $project) => Inertia::render('Columns/Index', ['projectId' => $project]))->name('columns.index');
Route::get('/projects/{project}/columns/create', fn (string $project) => Inertia::render('Columns/Form', ['mode' => 'create', 'projectId' => $project]))->name('columns.create');
Route::get('/projects/{project}/columns/{column}/edit', fn (string $project, string $column) => Inertia::render('Columns/Form', ['mode' => 'edit', 'projectId' => $project, 'id' => $column]))->name('columns.edit');
