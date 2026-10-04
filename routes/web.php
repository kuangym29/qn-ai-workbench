<?php

use App\Http\Controllers\AssetController;
use App\Http\Controllers\ChannelAssetBindingController;
use App\Http\Controllers\ChannelTaskController;
use App\Http\Controllers\ContentColumnController;
use App\Http\Controllers\ContentItemController;
use App\Http\Controllers\ContentPageController;
use App\Http\Controllers\DuplicateReviewController;
use App\Http\Controllers\ProductionTaskController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\SourceReferenceController;
use App\Http\Controllers\TopicController;
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

// Topics (DEV-W03) — always nested under Project → Column. These are Inertia page entries
// only; all real data comes from the frozen DEV-005 API under /api (see below).
Route::get('/projects/{project}/columns/{column}/topics', fn (int $project, int $column) => Inertia::render('Topics/Index', ['projectId' => $project, 'columnId' => $column]))->name('topics.index');
Route::get('/projects/{project}/columns/{column}/topics/create', fn (int $project, int $column) => Inertia::render('Topics/Form', ['mode' => 'create', 'projectId' => $project, 'columnId' => $column]))->name('topics.create');
Route::get('/projects/{project}/columns/{column}/topics/{topic}/edit', fn (int $project, int $column, int $topic) => Inertia::render('Topics/Form', ['mode' => 'edit', 'projectId' => $project, 'columnId' => $column, 'id' => $topic]))->name('topics.edit');

// ContentItems (DEV-W03) — always nested under Project → Column → Topic. Inertia page
// entries only; data comes from the DEV-005 API.
Route::get('/projects/{project}/columns/{column}/topics/{topic}/items', fn (int $project, int $column, int $topic) => Inertia::render('ContentItems/Index', ['projectId' => $project, 'columnId' => $column, 'topicId' => $topic]))->name('items.index');
Route::get('/projects/{project}/columns/{column}/topics/{topic}/items/create', fn (int $project, int $column, int $topic) => Inertia::render('ContentItems/Form', ['mode' => 'create', 'projectId' => $project, 'columnId' => $column, 'topicId' => $topic]))->name('items.create');
Route::get('/projects/{project}/columns/{column}/topics/{topic}/items/{item}/edit', fn (int $project, int $column, int $topic, int $item) => Inertia::render('ContentItems/Form', ['mode' => 'edit', 'projectId' => $project, 'columnId' => $column, 'topicId' => $topic, 'id' => $item]))->name('items.edit');

// ContentPage copy editor (DEV-W04) — scoped to Project → Column → Topic → ContentItem.
// Inertia entry only; all data is fetched client-side from the DEV-007A API under /api.
Route::get('/projects/{project}/columns/{column}/topics/{topic}/items/{item}/copy', fn (int $project, int $column, int $topic, int $item) => Inertia::render('ContentPages/Editor', [
    'projectId' => $project,
    'columnId' => $column,
    'topicId' => $topic,
    'itemId' => $item,
]))->name('items.copy');

// Production + Channel workspace (DEV-W06) — scoped to Project → Column → Topic → ContentItem.
// Inertia entry only; it passes ids and never queries business data. Everything else is
// fetched client-side from the frozen DEV-009A / DEV-W05 APIs under /api.
Route::get('/projects/{project}/columns/{column}/topics/{topic}/items/{item}/production', fn (int $project, int $column, int $topic, int $item) => Inertia::render('Production/Workspace', [
    'projectId' => $project,
    'columnId' => $column,
    'topicId' => $topic,
    'itemId' => $item,
]))->name('items.production');

// Source reference management (DEV-W07) — Project level and per content item.
// Inertia entries only; all data comes from the DEV-W07 API under /api.
Route::get('/projects/{project}/sources', fn (int $project) => Inertia::render('Sources/ProjectSources', ['projectId' => $project]))->name('sources.project');
Route::get('/projects/{project}/columns/{column}/topics/{topic}/items/{item}/sources', fn (int $project, int $column, int $topic, int $item) => Inertia::render('Sources/ItemSources', [
    'projectId' => $project,
    'columnId' => $column,
    'topicId' => $topic,
    'itemId' => $item,
]))->name('sources.item');

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

    Route::get('/projects/{project}/columns/{column}/topics', [TopicController::class, 'index']);
    Route::post('/projects/{project}/columns/{column}/topics', [TopicController::class, 'store']);
    Route::get('/projects/{project}/columns/{column}/topics/{topic}', [TopicController::class, 'show']);
    Route::patch('/projects/{project}/columns/{column}/topics/{topic}', [TopicController::class, 'update']);

    Route::get('/projects/{project}/columns/{column}/topics/{topic}/items', [ContentItemController::class, 'index']);
    Route::post('/projects/{project}/columns/{column}/topics/{topic}/items', [ContentItemController::class, 'store']);
    Route::get('/projects/{project}/columns/{column}/topics/{topic}/items/{item}', [ContentItemController::class, 'show']);
    Route::patch('/projects/{project}/columns/{column}/topics/{topic}/items/{item}', [ContentItemController::class, 'update']);

    Route::get('/projects/{project}/columns/{column}/topics/{topic}/items/{item}/pages', [ContentPageController::class, 'index']);
    Route::post('/projects/{project}/columns/{column}/topics/{topic}/items/{item}/pages', [ContentPageController::class, 'store']);
    Route::post('/projects/{project}/columns/{column}/topics/{topic}/items/{item}/pages/reorder', [ContentPageController::class, 'reorder']);
    Route::get('/projects/{project}/columns/{column}/topics/{topic}/items/{item}/pages/{page}', [ContentPageController::class, 'show']);
    Route::patch('/projects/{project}/columns/{column}/topics/{topic}/items/{item}/pages/{page}', [ContentPageController::class, 'update']);
    Route::post('/projects/{project}/columns/{column}/topics/{topic}/items/{item}/pages/{page}/drafts', [ContentPageController::class, 'appendDraft']);

    Route::post('/projects/{project}/columns/{column}/topics/{topic}/items/{item}/copy/confirm', [ContentPageController::class, 'confirm']);
    Route::get('/projects/{project}/columns/{column}/topics/{topic}/items/{item}/copy/revisions', [ContentPageController::class, 'revisions']);
    Route::get('/projects/{project}/columns/{column}/topics/{topic}/items/{item}/copy/revisions/{revision}', [ContentPageController::class, 'revision']);
    Route::get('/projects/{project}/columns/{column}/topics/{topic}/items/{item}/copy/current', [ContentPageController::class, 'current']);
    Route::get('/projects/{project}/columns/{column}/topics/{topic}/items/{item}/copy/working', [ContentPageController::class, 'working']);

    Route::get('/projects/{project}/columns/{column}/topics/{topic}/items/{item}/duplicate-review', [DuplicateReviewController::class, 'index']);
    Route::post('/projects/{project}/columns/{column}/topics/{topic}/items/{item}/duplicate-review/decisions', [DuplicateReviewController::class, 'store']);

    Route::get('/projects/{project}/columns/{column}/topics/{topic}/items/{item}/production', [ProductionTaskController::class, 'show']);
    Route::post('/projects/{project}/columns/{column}/topics/{topic}/items/{item}/production', [ProductionTaskController::class, 'store']);
    Route::patch('/projects/{project}/columns/{column}/topics/{topic}/items/{item}/production', [ProductionTaskController::class, 'update']);
    Route::post('/projects/{project}/columns/{column}/topics/{topic}/items/{item}/production/use-current-copy', [ProductionTaskController::class, 'useCurrentCopy']);

    Route::get('/projects/{project}/columns/{column}/topics/{topic}/items/{item}/production/assets', [AssetController::class, 'index']);
    Route::get('/projects/{project}/columns/{column}/topics/{topic}/items/{item}/production/assets/{asset}', [AssetController::class, 'show']);
    Route::post('/projects/{project}/columns/{column}/topics/{topic}/items/{item}/production/assets/versions', [AssetController::class, 'store']);

    // DEV-W05 ChannelTask — 微信公众号 / 微信视频号渠道流程。渠道只引用共享 Production，
    // 不复制篇目与文案。不开放 DELETE：Lite V1.0 没有任何删除 API。
    Route::get('/projects/{project}/columns/{column}/topics/{topic}/items/{item}/production/channels', [ChannelTaskController::class, 'index']);
    Route::post('/projects/{project}/columns/{column}/topics/{topic}/items/{item}/production/channels', [ChannelTaskController::class, 'store']);
    Route::post('/projects/{project}/columns/{column}/topics/{topic}/items/{item}/production/restart-with-current-copy', [ChannelTaskController::class, 'restartWithCurrentCopy']);
    Route::get('/projects/{project}/columns/{column}/topics/{topic}/items/{item}/production/channels/{channel}', [ChannelTaskController::class, 'show']);
    Route::patch('/projects/{project}/columns/{column}/topics/{topic}/items/{item}/production/channels/{channel}/video', [ChannelTaskController::class, 'updateVideo']);
    Route::patch('/projects/{project}/columns/{column}/topics/{topic}/items/{item}/production/channels/{channel}/publish', [ChannelTaskController::class, 'updatePublish']);
    Route::get('/projects/{project}/columns/{column}/topics/{topic}/items/{item}/production/channels/{channel}/assets', [ChannelAssetBindingController::class, 'index']);
    Route::post('/projects/{project}/columns/{column}/topics/{topic}/items/{item}/production/channels/{channel}/assets/bindings', [ChannelAssetBindingController::class, 'store']);
    // DEV-W07 SourceReference Lite API. Project-scoped and item-scoped roles are managed
    // through separate paths; a record reached through the wrong scope is 404. There is
    // intentionally NO DELETE route: provenance records are not deletable here.
    Route::get('/projects/{project}/sources', [SourceReferenceController::class, 'projectIndex']);
    Route::post('/projects/{project}/sources', [SourceReferenceController::class, 'projectStore']);
    Route::get('/projects/{project}/sources/{sourceReference}', [SourceReferenceController::class, 'projectShow']);
    Route::patch('/projects/{project}/sources/{sourceReference}', [SourceReferenceController::class, 'projectUpdate']);

    Route::get('/projects/{project}/columns/{column}/topics/{topic}/items/{item}/sources', [SourceReferenceController::class, 'itemIndex']);
    Route::post('/projects/{project}/columns/{column}/topics/{topic}/items/{item}/sources', [SourceReferenceController::class, 'itemStore']);
    Route::get('/projects/{project}/columns/{column}/topics/{topic}/items/{item}/sources/{sourceReference}', [SourceReferenceController::class, 'itemShow']);
    Route::patch('/projects/{project}/columns/{column}/topics/{topic}/items/{item}/sources/{sourceReference}', [SourceReferenceController::class, 'itemUpdate']);
});
