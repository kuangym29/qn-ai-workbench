<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChannelAssetWorkspaceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $workspace = $this->resource;

        return [
            'channel_task_id' => $workspace['channel']->id,
            'channel' => $workspace['channel']->channel->value,
            'expected_asset_role' => $workspace['expected_role']->value,
            'production_task_id' => $workspace['production']->id,
            'copy_revision_id' => $workspace['revision']?->id,
            'copy_revision_no' => $workspace['revision']?->revision_no,
            'is_production_copy_current' => $workspace['is_production_copy_current'],
            'is_complete' => $workspace['is_complete'],
            'bound_page_count' => $workspace['bound_page_count'],
            'total_page_count' => $workspace['total_page_count'],
            'pages' => array_map(function (array $page) use ($request): array {
                return [
                    'content_page_id' => $page['snapshot']->content_page_id,
                    'page_no' => $page['snapshot']->page_no_snapshot,
                    'page_type' => $page['snapshot']->page_type_snapshot,
                    'asset_id' => $page['asset']?->id,
                    'available_versions' => $page['versions']->map(fn ($version) => (new AssetVersionResource($version))->resolve($request))->all(),
                    'current_binding' => $page['current_binding'] ? (new ChannelAssetBindingResource($page['current_binding']))->resolve($request) : null,
                    'latest_binding' => $page['latest_binding'] ? (new ChannelAssetBindingResource($page['latest_binding']))->resolve($request) : null,
                ];
            }, $workspace['pages']),
        ];
    }
}
