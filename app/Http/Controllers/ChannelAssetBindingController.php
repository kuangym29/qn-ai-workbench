<?php

namespace App\Http\Controllers;

use App\Enums\Channel;
use App\Http\Requests\AppendChannelAssetBindingRequest;
use App\Http\Resources\ChannelAssetBindingResource;
use App\Http\Resources\ChannelAssetWorkspaceResource;
use App\Models\ChannelTask;
use App\Models\ContentItem;
use App\Models\Project;
use App\Services\ChannelAssetBindingService;
use App\Support\ProjectContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChannelAssetBindingController extends Controller
{
    private function item(Request $request, Project $project, int $column, int $topic, int $item): ContentItem
    {
        app(ProjectContext::class)->assertCurrent($request, $project);

        return $project->contentColumns()->findOrFail($column)
            ->topics()->findOrFail($topic)
            ->contentItems()->findOrFail($item);
    }

    private function channel(Request $request, ContentItem $item): ChannelTask
    {
        $type = Channel::tryFrom((string) $request->route('channel')) ?? abort(404);
        $production = $item->productionTask()->firstOrFail();

        return $production->channelTasks()->where('channel', $type->value)->firstOrFail();
    }

    public function index(Request $request, Project $project, int $column, int $topic, int $item, ChannelAssetBindingService $service): ChannelAssetWorkspaceResource
    {
        $content = $this->item($request, $project, $column, $topic, $item);

        return new ChannelAssetWorkspaceResource($service->workspace($this->channel($request, $content)));
    }

    public function store(AppendChannelAssetBindingRequest $request, Project $project, int $column, int $topic, int $item, ChannelAssetBindingService $service): JsonResponse
    {
        $content = $this->item($request, $project, $column, $topic, $item);
        $type = Channel::tryFrom((string) $request->route('channel')) ?? abort(404);
        $binding = $service->append($content, $type, $request->validated());

        return (new ChannelAssetBindingResource($binding->load([
            'copyRevision', 'assetVersion.copyRevision', 'assetVersion.file',
        ])))->response()->setStatusCode(201);
    }
}
