<?php

namespace App\Services;

use App\Enums\Channel;
use App\Models\AssetVersion;
use App\Models\ChannelAssetBinding;
use App\Models\ChannelTask;
use App\Models\ContentItem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ChannelAssetBindingService
{
    public function workspace(ChannelTask $channel): array
    {
        $production = $channel->productionTask;
        $revision = $production->copyRevision;
        $snapshots = $revision?->pageVersions()->orderBy('page_no_snapshot')->get() ?? collect();
        $pageIds = $snapshots->pluck('content_page_id');
        $role = $channel->channel->expectedAssetRole();
        $assets = $production->assets()->where('role', $role->value)
            ->whereIn('content_page_id', $pageIds)
            ->with(['versions' => fn ($query) => $query->where('copy_revision_id', $production->copy_revision_id)->orderByDesc('version_no'),
                'versions.copyRevision', 'versions.file'])
            ->get()->keyBy('content_page_id');
        $bindings = $channel->channelAssetBindings()->whereIn('content_page_id', $pageIds)
            ->with(['copyRevision', 'assetVersion.copyRevision', 'assetVersion.file'])
            ->orderByDesc('binding_no')->get()->groupBy('content_page_id');

        $bound = 0;
        $pages = $snapshots->map(function ($snapshot) use ($assets, $bindings, $production, &$bound): array {
            $history = $bindings->get($snapshot->content_page_id, collect());
            $current = $history->firstWhere('copy_revision_id', $production->copy_revision_id);
            if ($current !== null) {
                $bound++;
            }
            $asset = $assets->get($snapshot->content_page_id);

            return [
                'snapshot' => $snapshot,
                'asset' => $asset,
                'versions' => $asset?->versions ?? collect(),
                'current_binding' => $current,
                'latest_binding' => $history->first(),
            ];
        })->all();
        $total = count($pages);
        $currentRevisionId = $production->contentItem->contentCopyRevisions()->orderByDesc('revision_no')->value('id');

        return [
            'channel' => $channel,
            'production' => $production,
            'revision' => $revision,
            'expected_role' => $role,
            'is_production_copy_current' => $revision !== null && (int) $currentRevisionId === $revision->id,
            'is_complete' => $total > 0 && $bound === $total,
            'bound_page_count' => $bound,
            'total_page_count' => $total,
            'pages' => $pages,
        ];
    }

    public function isComplete(ChannelTask $channel): bool
    {
        return $this->workspace($channel)['is_complete'];
    }

    public function append(ContentItem $content, Channel $channel, array $data): ChannelAssetBinding
    {
        try {
            return DB::transaction(function () use ($content, $channel, $data): ChannelAssetBinding {
                $item = ContentItem::query()->whereKey($content->id)->lockForUpdate()->firstOrFail();
                $production = $item->productionTask()->lockForUpdate()->firstOrFail();
                $task = $production->channelTasks()->where('channel', $channel->value)->lockForUpdate()->firstOrFail();
                if ($production->copy_revision_id === null) {
                    throw ValidationException::withMessages(['production' => 'Production task has no pinned copy revision.']);
                }
                $page = $item->contentPages()->findOrFail($data['content_page_id']);
                $inRevision = $item->contentCopyRevisions()->whereKey($production->copy_revision_id)
                    ->whereHas('pageVersions', fn ($query) => $query->where('content_page_id', $page->id))->exists();
                if (! $inRevision) {
                    throw ValidationException::withMessages(['content_page_id' => 'Page is not part of the production copy revision.']);
                }
                $version = AssetVersion::query()->where('project_id', $item->project_id)
                    ->where('content_item_id', $item->id)->whereKey($data['asset_version_id'])
                    ->whereHas('asset', fn ($query) => $query->where('production_task_id', $production->id))
                    ->firstOrFail();
                $asset = $version->asset;
                if ($asset->content_page_id !== $page->id ||
                    $asset->role !== $channel->expectedAssetRole() ||
                    $version->copy_revision_id !== $production->copy_revision_id) {
                    throw ValidationException::withMessages(['asset_version_id' => 'Select a version for this page, channel role, and production copy revision.']);
                }

                return $task->channelAssetBindings()->create([
                    'project_id' => $item->project_id,
                    'content_item_id' => $item->id,
                    'production_task_id' => $production->id,
                    'content_page_id' => $page->id,
                    'asset_id' => $asset->id,
                    'asset_version_id' => $version->id,
                    'copy_revision_id' => $production->copy_revision_id,
                    'binding_no' => ((int) $task->channelAssetBindings()->where('content_page_id', $page->id)->max('binding_no')) + 1,
                ]);
            });
        } catch (QueryException $error) {
            $code = (string) ($error->errorInfo[0] ?? $error->getCode());
            if (in_array($code, ['23000', '23505', '19'], true)) {
                throw ValidationException::withMessages(['asset_version_id' => 'Binding conflicted with another request. Refresh and retry.']);
            }
            throw $error;
        }
    }
}
