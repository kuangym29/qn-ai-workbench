<?php

use App\Http\Controllers\ContentColumnController;
use App\Http\Controllers\ProjectController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('Bootstrap');
});

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
