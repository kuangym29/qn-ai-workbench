<?php

namespace App\Http\Controllers;

use App\Enums\ArtworkStatus;
use App\Enums\Channel;
use App\Enums\CopyStatus;
use App\Enums\PublishStatus;
use App\Enums\VideoStatus;
use App\Http\Requests\CreateChannelTaskRequest;
use App\Http\Requests\RestartProductionWithCurrentCopyRequest;
use App\Http\Requests\UpdateChannelPublishRequest;
use App\Http\Requests\UpdateChannelVideoRequest;
use App\Http\Resources\ChannelTaskResource;
use App\Http\Resources\ProductionTaskResource;
use App\Models\ChannelTask;
use App\Models\ContentCopyRevision;
use App\Models\ContentItem;
use App\Models\ProductionTask;
use App\Models\Project;
use App\Support\ProjectContext;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * DEV-W05 — 渠道流程服务端 API。
 *
 * 业务链：ContentItem → Formal CopyRevision → ProductionTask → ChannelTask。
 *
 * 四维状态严格独立：图稿（ProductionTask.artwork_status）、视频、发布三者互不
 * 推导。渠道创建、视频终态、排期、正式发布与整链 restart 均以「当前正式 Revision +
 * 已验收共享图稿」为门禁。
 *
 * 并发：统一行锁顺序为 ContentItem → ProductionTask → ChannelTask(s)，与 DEV-009A
 * `use-current-copy` 保持一致。任何路径都不得反向加锁（ChannelTask → … → ContentItem），
 * 否则存在死锁风险。数据库的 unique(production_task_id, channel) 作为最后一道并发约束。
 */
class ChannelTaskController extends Controller
{
    /**
     * 逐层解析 Project → ContentColumn → Topic → ContentItem，并断言 Session 当前 Project。
     * 任何祖先错配都是 404；绝不根据 URL 自动 select Project。
     */
    private function item(Request $request, Project $project, int $column, int $topic, int $item): ContentItem
    {
        app(ProjectContext::class)->assertCurrent($request, $project);

        return $project->contentColumns()->findOrFail($column)
            ->topics()->findOrFail($topic)
            ->contentItems()->findOrFail($item);
    }

    /**
     * 当前正式 Revision = 同 ContentItem 最大 revision_no。不得用 copy_status 推断。
     */
    private function currentRevision(ContentItem $item): ?ContentCopyRevision
    {
        return $item->contentCopyRevisions()->orderByDesc('revision_no')->first();
    }

    /**
     * Production 是否建立在「当前正式文案 + 已验收共享图稿」之上。
     *
     * 注意：这里刻意不要求 copy_status === confirmed。Revision 1 已确认后用户只保存了
     * 新草稿时 copy_status 会变 editing，但当前最大正式 Revision 仍是 Revision 1，
     * Production 依然 current。只有真正确认 Revision 2 之后 Production 才变 stale。
     */
    private function productionIsReady(ContentItem $item, ProductionTask $task): bool
    {
        $current = $this->currentRevision($item);

        return $current !== null
            && $task->copy_revision_id !== null
            && $task->copy_revision_id === $current->id
            && $task->artwork_status === ArtworkStatus::Approved;
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    /**
     * 把客户端提交的 datetime 归一为 UTC 再落库。
     *
     * Eloquent 的 datetime cast 写库时用 `Y-m-d H:i:s` 格式化，会直接丢弃时区偏移
     * （`2026-10-11T08:00:00+08:00` 会被写成裸的 `08:00:00`），从而破坏「数据库统一
     * 存 UTC」的约定。这里显式先换算到 UTC，之后格式化才不会丢失偏移量。
     */
    private function toUtc(?string $value): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value)->utc();
    }

    private function resource(ChannelTask $task): ChannelTaskResource
    {
        return new ChannelTaskResource($task->load(['productionTask.copyRevision', 'productionTask.contentItem']));
    }

    /** 从 URL 的 {channel} 段解析枚举；未知渠道一律 404（不是 422）。 */
    private function channelFromRoute(Request $request): Channel
    {
        $value = (string) $request->route('channel');

        return Channel::tryFrom($value) ?? abort(404);
    }

    // ---------------------------------------------------------------- 读取

    public function index(Request $request, Project $project, int $column, int $topic, int $item): AnonymousResourceCollection|JsonResponse
    {
        $content = $this->item($request, $project, $column, $topic, $item);
        $task = $content->productionTask;
        if ($task === null) {
            abort(404);
        }

        // 只返回已创建的渠道，不自动补建缺失的 ChannelTask。
        $tasks = $task->channelTasks()
            ->orderByRaw('CASE channel WHEN ? THEN 0 WHEN ? THEN 1 ELSE 2 END', [
                Channel::WechatOfficial->value,
                Channel::WechatChannels->value,
            ])
            ->get();

        return ChannelTaskResource::collection($tasks);
    }

    public function show(Request $request, Project $project, int $column, int $topic, int $item): ChannelTaskResource
    {
        $content = $this->item($request, $project, $column, $topic, $item);
        $channel = $this->channelFromRoute($request);
        $task = $content->productionTask;
        if ($task === null) {
            abort(404);
        }

        $channelTask = $task->channelTasks()->where('channel', $channel->value)->first();
        if ($channelTask === null) {
            abort(404);
        }

        return $this->resource($channelTask);
    }

    // ---------------------------------------------------------------- 创建

    public function store(CreateChannelTaskRequest $request, Project $project, int $column, int $topic, int $item): JsonResponse
    {
        $content = $this->item($request, $project, $column, $topic, $item);
        $channel = Channel::from($request->validated('channel'));

        $created = DB::transaction(function () use ($content, $project, $channel) {
            // 锁顺序：ContentItem → ProductionTask（此时还没有 ChannelTask 可锁）。
            $lockedItem = ContentItem::query()->whereKey($content->id)->lockForUpdate()->firstOrFail();
            $production = $lockedItem->productionTask()->lockForUpdate()->first();

            if ($production === null) {
                $this->fail('channel', 'Start a production task before creating a channel task.');
            }

            if (! $this->productionIsReady($lockedItem, $production)) {
                $this->fail('channel', 'Channel tasks require approved artwork on the current formal copy revision.');
            }

            if ($production->channelTasks()->where('channel', $channel->value)->exists()) {
                $this->fail('channel', "Channel [{$channel->value}] already exists for this production task.");
            }

            $defaults = $channel === Channel::WechatOfficial
                ? ['video_status' => VideoStatus::NotApplicable, 'publish_status' => PublishStatus::Unpublished]
                : ['video_status' => VideoStatus::NotStarted, 'publish_status' => PublishStatus::Unpublished];

            try {
                return $production->channelTasks()->forceCreate(array_merge($defaults, [
                    'project_id' => $project->id,
                    'channel' => $channel->value,
                    'scheduled_at' => null,
                    'published_at' => null,
                ]));
            } catch (QueryException $exception) {
                // unique(production_task_id, channel) 是最后一道并发约束：
                // 并发重复创建必须转成 422，不能漏成 500。
                if ($this->isUniqueViolation($exception)) {
                    $this->fail('channel', "Channel [{$channel->value}] already exists for this production task.");
                }

                throw $exception;
            }
        });

        return $this->resource($created)->response()->setStatusCode(201);
    }

    // ---------------------------------------------------------------- 视频

    public function updateVideo(UpdateChannelVideoRequest $request, Project $project, int $column, int $topic, int $item): ChannelTaskResource
    {
        $content = $this->item($request, $project, $column, $topic, $item);
        $channel = $this->channelFromRoute($request);
        $status = VideoStatus::from($request->validated('video_status'));

        $updated = DB::transaction(function () use ($content, $channel, $status) {
            // 锁顺序：ContentItem → ProductionTask → ChannelTask
            $lockedItem = ContentItem::query()->whereKey($content->id)->lockForUpdate()->firstOrFail();
            $production = $lockedItem->productionTask()->lockForUpdate()->first();
            if ($production === null) {
                abort(404);
            }
            $channelTask = $production->channelTasks()
                ->where('channel', $channel->value)
                ->lockForUpdate()
                ->first();
            if ($channelTask === null) {
                abort(404);
            }

            // 公众号没有视频环节，video_status 永久 not_applicable。
            if ($channel === Channel::WechatOfficial) {
                $this->fail('video_status', 'WeChat Official Account has no video stage.');
            }

            // 视频号不接受 not_applicable。
            if ($status === VideoStatus::NotApplicable) {
                $this->fail('video_status', 'WeChat Channels requires a video stage.');
            }

            // 中间状态允许停留在 stale Production 上；只有终态 approved 必须建立在
            // current Production 之上。
            if ($status === VideoStatus::Approved && ! $this->productionIsReady($lockedItem, $production)) {
                $this->fail('video_status', 'Approve the video only for the current formal copy revision with approved artwork.');
            }

            // 只改 video_status，绝不联动 publish_status / scheduled_at / published_at。
            $channelTask->update(['video_status' => $status]);

            return $channelTask;
        });

        return $this->resource($updated);
    }

    // ---------------------------------------------------------------- 发布

    public function updatePublish(UpdateChannelPublishRequest $request, Project $project, int $column, int $topic, int $item): ChannelTaskResource
    {
        $content = $this->item($request, $project, $column, $topic, $item);
        $channel = $this->channelFromRoute($request);
        $target = PublishStatus::from($request->validated('publish_status'));
        $scheduledAt = $request->validated('scheduled_at');
        $publishedAt = $request->validated('published_at');

        $updated = DB::transaction(function () use ($content, $channel, $target, $scheduledAt, $publishedAt) {
            // 锁顺序：ContentItem → ProductionTask → ChannelTask
            $lockedItem = ContentItem::query()->whereKey($content->id)->lockForUpdate()->firstOrFail();
            $production = $lockedItem->productionTask()->lockForUpdate()->first();
            if ($production === null) {
                abort(404);
            }
            $channelTask = $production->channelTasks()
                ->where('channel', $channel->value)
                ->lockForUpdate()
                ->first();
            if ($channelTask === null) {
                abort(404);
            }

            $isPublished = $channelTask->publish_status === PublishStatus::Published;

            // 已发布是历史事实：不允许通过普通接口抹掉。
            if ($isPublished && $target === PublishStatus::Unpublished) {
                $this->fail('publish_status', 'A published channel task cannot be reset to unpublished.');
            }

            // 幂等：已发布再次 published 且未提供新时间 → 200 no-op，保留原 published_at。
            if ($isPublished && $target === PublishStatus::Published) {
                if ($publishedAt !== null) {
                    $this->fail('published_at', 'published_at is already recorded and cannot be changed.');
                }

                return $channelTask;
            }

            // 排期与正式发布都要求 current Production + approved artwork。
            if ($target === PublishStatus::Scheduled || $target === PublishStatus::Published) {
                if (! $this->productionIsReady($lockedItem, $production)) {
                    $this->fail('publish_status', 'Publishing requires approved artwork on the current formal copy revision.');
                }

                // 视频号额外要求视频验收；公众号无需视频审核。
                if ($channel === Channel::WechatChannels && $channelTask->video_status !== VideoStatus::Approved) {
                    $this->fail('publish_status', 'Approve the video before scheduling or publishing WeChat Channels.');
                }
            }

            $attributes = match ($target) {
                // 取消排期 / 回到未发布：统一清空两个时间。
                PublishStatus::Unpublished => [
                    'publish_status' => PublishStatus::Unpublished,
                    'scheduled_at' => null,
                    'published_at' => null,
                ],
                // 重新排期允许改 scheduled_at；不影响视频与图稿。
                PublishStatus::Scheduled => [
                    'publish_status' => PublishStatus::Scheduled,
                    'scheduled_at' => $this->toUtc($scheduledAt),
                ],
                // 首次进入 published：缺省用 now()；若此前是 scheduled 则保留 scheduled_at 作为排期历史。
                PublishStatus::Published => [
                    'publish_status' => PublishStatus::Published,
                    'published_at' => $this->toUtc($publishedAt) ?? now(),
                ],
            };

            $channelTask->update($attributes);

            return $channelTask;
        });

        return $this->resource($updated);
    }

    // ---------------------------------------------------------------- 整链 restart

    /**
     * DEV-W05：解决 DEV-009A 留下的 stale 死路。
     *
     * DEV-009A 的 use-current-copy 只在「没有任何 ChannelTask」时可换版；一旦渠道任务已经
     * 建立，后来又确认了 Revision 2，就必须有一个显式的 destructive reset 动作把整条
     * 生产链重新开始。该动作只能由用户主动调用，绝不在 confirm 时自动触发。
     */
    public function restartWithCurrentCopy(RestartProductionWithCurrentCopyRequest $request, Project $project, int $column, int $topic, int $item): JsonResponse
    {
        $content = $this->item($request, $project, $column, $topic, $item);

        $result = DB::transaction(function () use ($content) {
            // 锁顺序：ContentItem → ProductionTask → ChannelTask(s)
            $lockedItem = ContentItem::query()->whereKey($content->id)->lockForUpdate()->firstOrFail();
            $production = $lockedItem->productionTask()->lockForUpdate()->first();
            if ($production === null) {
                abort(404);
            }

            $channels = $production->channelTasks()->lockForUpdate()->get();

            // 没有渠道任务时这不是 restart 的职责范围，交给 DEV-009A use-current-copy。
            if ($channels->isEmpty()) {
                $this->fail('production', 'No channel tasks exist. Use use-current-copy instead.');
            }

            $current = $this->currentRevision($lockedItem);
            if ($lockedItem->copy_status !== CopyStatus::Confirmed || $current === null) {
                $this->fail('production', 'Finish the copy and confirm a formal revision before restarting production.');
            }

            // 已发布是历史事实：任何渠道已发布都禁止整链 reset。
            foreach ($channels as $channelTask) {
                if ($channelTask->publish_status === PublishStatus::Published) {
                    $this->fail('production', 'A published channel task blocks restarting production.');
                }
            }

            // Production 已是 current：200 no-op，不改动任何状态与时间戳。
            if ($production->copy_revision_id === $current->id) {
                return ['task' => $production, 'channels' => $channels];
            }

            $production->forceFill([
                'copy_revision_id' => $current->id,
                'artwork_status' => ArtworkStatus::NotStarted,
            ])->save();

            // 保留 ChannelTask.id 与 channel，只重置状态与时间。
            foreach ($channels as $channelTask) {
                $channelTask->forceFill([
                    'video_status' => $channelTask->channel === Channel::WechatChannels
                        ? VideoStatus::NotStarted
                        : VideoStatus::NotApplicable,
                    'publish_status' => PublishStatus::Unpublished,
                    'scheduled_at' => null,
                    'published_at' => null,
                ])->save();
            }

            return ['task' => $production, 'channels' => $channels->fresh()];
        });

        return response()->json([
            'data' => [
                'production' => new ProductionTaskResource($result['task']->load(['copyRevision', 'contentItem'])),
                'channels' => ChannelTaskResource::collection($result['channels']),
            ],
        ]);
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

        // 23000/23505 = MySQL & PostgreSQL unique violation; 19 = SQLite constraint violation.
        return $sqlState === '23000' || $sqlState === '23505' || $sqlState === '19'
            || str_contains($exception->getMessage(), 'UNIQUE constraint failed')
            || str_contains($exception->getMessage(), 'Duplicate entry');
    }
}
