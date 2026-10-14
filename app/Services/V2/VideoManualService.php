<?php

namespace App\Services\V2;

use App\Enums\Channel;
use App\Enums\ContentFormat;
use App\Models\ContentBranch;
use App\Models\ContentItem;
use App\Models\VideoContent;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * 视频号视频制作的「人工编辑 / 保存 / 继续沿用来源」服务。
 *
 * 关键不变量：
 *   - 只有 channel = wechat_channels 且 format = video 的分支可调用；其它 branch 一律拒绝（422）。
 *   - PUT 白名单：batch_instruction / narration / units[]。title / source_label / order /
 *     source_changed / source_change_summary / timeline / total_duration 全部由系统计算，禁止写入。
 *   - 真正变化才写 VideoContentVersion；完全 unchanged 不产生无意义版本。
 *   - 来源变化检测：保存时记录当时的来源 fingerprint；keep 只更新 fingerprint，不动内容。
 */
class VideoManualService
{
    public function __construct(private readonly VideoSourceService $source = new VideoSourceService) {}

    /**
     * 读取视频制作（未保存过也能读，返回空内容 + 默认视频单元）。
     *
     * @return array<string, mixed>
     */
    public function read(ContentBranch $branch): array
    {
        $this->assertVideo($branch);
        $item = $branch->contentItem;
        $video = VideoContent::query()
            ->where('content_branch_id', $branch->id)
            ->first();

        $units = $this->source->units($item, $video);
        [$timeline, $total] = $this->timeline($units);

        return [
            'video' => [
                'id' => $video?->id,
                'project_id' => $branch->project_id,
                'content_item_id' => $item->id,
                'title' => $item->title,
                'source_label' => $this->branchLabel($branch),
                'batch_instruction' => (string) ($video->batch_instruction ?? ''),
                'narration' => (string) ($video->narration ?? ''),
                'units' => $units,
                'source_changed' => $this->source->sourceChanged($video, $item),
                'source_change_summary' => $this->source->changeSummary($video, $item),
            ],
            'total_duration' => $total,
            'timeline' => $timeline,
            'narration_note' => '完整旁白对应整条视频；各视频单元的画面动效与时长独立维护。',
        ];
    }

    /**
     * 人工 PUT 保存。返回是否真正发生变化（决定是否写版本）。
     *
     * @param  array{batch_instruction?:string,narration?:string,units?:array<int,array<string,mixed>>}  $data
     */
    public function save(ContentBranch $branch, array $data): bool
    {
        $this->assertVideo($branch);
        $item = $branch->contentItem;

        return DB::transaction(function () use ($branch, $item, $data): bool {
            $video = VideoContent::query()
                ->where('content_branch_id', $branch->id)
                ->lockForUpdate()
                ->first();

            $before = $video === null ? null : [
                'batch_instruction' => (string) $video->batch_instruction,
                'narration' => (string) $video->narration,
                'units' => $this->normalizeUnits($item, [], $video),
            ];

            if ($video === null) {
                $snapshot = [
                    'batch_instruction' => (string) ($data['batch_instruction'] ?? ''),
                    'narration' => (string) ($data['narration'] ?? ''),
                    'units' => $this->normalizeUnits($item, $data['units'] ?? [], null),
                ];

                $video = VideoContent::create([
                    'project_id' => $branch->project_id,
                    'content_item_id' => $item->id,
                    'content_branch_id' => $branch->id,
                    ...$snapshot,
                    'source_fingerprint' => $this->source->currentFingerprint($item),
                ]);

                // 新建即第一版：即使内容为空也记录 version 1（之后 unchanged 不再产生版本）。
                $this->snapshot($video, $snapshot);

                return true;
            }

            $after = [
                'batch_instruction' => array_key_exists('batch_instruction', $data)
                    ? (string) $data['batch_instruction']
                    : (string) $video->batch_instruction,
                'narration' => array_key_exists('narration', $data)
                    ? (string) $data['narration']
                    : (string) $video->narration,
                'units' => array_key_exists('units', $data)
                    ? $this->normalizeUnits($item, $data['units'], $video)
                    : $this->normalizeUnits($item, [], $video),
            ];

            if ($before === $after) {
                return false; // 完全 unchanged → 不写版本
            }

            $video->update([
                'batch_instruction' => $after['batch_instruction'],
                'narration' => $after['narration'],
                'units' => $after['units'],
                'source_fingerprint' => $this->source->currentFingerprint($item),
            ]);

            $this->snapshot($video, $after);

            return true;
        }, 3);
    }

    /**
     * 继续沿用来源：只把 source_fingerprint 更新为当前来源版本。
     * 不修改 batch_instruction / narration / units。
     */
    public function keepSource(ContentBranch $branch): void
    {
        $this->assertVideo($branch);
        $item = $branch->contentItem;

        $video = VideoContent::query()
            ->where('content_branch_id', $branch->id)
            ->first();

        if ($video === null) {
            return;
        }

        $video->update(['source_fingerprint' => $this->source->currentFingerprint($item)]);
    }

    /**
     * timeline 自动累积 start/end；total_duration 为各 unit 时长之和。
     *
     * @param  array<int, array<string, mixed>>  $units
     * @return array{0: array<int, array<string, mixed>>, 1: float}
     */
    public function timeline(array $units): array
    {
        $cursor = 0.0;
        $timeline = [];

        foreach ($units as $unit) {
            $duration = $this->source->normalizeDuration($unit['duration_seconds'] ?? null);
            $timeline[] = [
                'order' => $unit['order'],
                'source_page_id' => $unit['source_page_id'],
                'source_label' => $unit['source_label'],
                'duration_seconds' => $duration,
                'start_seconds' => round($cursor, 2),
                'end_seconds' => round($cursor + $duration, 2),
            ];
            $cursor += $duration;
        }

        return [$timeline, round($cursor, 2)];
    }

    /**
     * units 归一：只接受来源页白名单字段，按来源页顺序排列。
     * 来源页不存在 → 抛 InvalidArgumentException（422）。
     */
    private function normalizeUnits(ContentItem $item, array $raw, ?VideoContent $video): array
    {
        $validPageIds = $this->source->sourcePages($item)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $byPage = [];
        foreach ($raw as $unit) {
            $pageId = (int) ($unit['source_page_id'] ?? 0);
            if (! in_array($pageId, $validPageIds, true)) {
                throw new InvalidArgumentException("来源页 {$pageId} 不属于当前内容，无法写入视频单元。");
            }
            $byPage[$pageId] = [
                'source_page_id' => $pageId,
                'dynamic_instruction' => (string) ($unit['dynamic_instruction'] ?? ''),
                'duration_seconds' => $this->source->normalizeDuration($unit['duration_seconds'] ?? null),
                'note' => $unit['note'] ?? null,
            ];
        }

        // 保持来源页顺序：与既有保存值合并，未提及的来源页沿用旧值。
        $existing = [];
        foreach ($video?->units ?? [] as $unit) {
            if (isset($unit['source_page_id'])) {
                $existing[(int) $unit['source_page_id']] = $unit;
            }
        }

        $merged = [];
        foreach ($validPageIds as $pageId) {
            if (isset($byPage[$pageId])) {
                $merged[] = $byPage[$pageId];
            } elseif (isset($existing[$pageId])) {
                $merged[] = [
                    'source_page_id' => $pageId,
                    'dynamic_instruction' => (string) ($existing[$pageId]['dynamic_instruction'] ?? ''),
                    'duration_seconds' => $this->source->normalizeDuration($existing[$pageId]['duration_seconds'] ?? null),
                    'note' => $existing[$pageId]['note'] ?? null,
                ];
            }
        }

        return $merged;
    }

    /**
     * @param  array{batch_instruction:string,narration:string,units:array<int,array<string,mixed>>}  $snapshot
     */
    private function snapshot(VideoContent $video, array $snapshot): void
    {
        $nextNo = (int) $video->versions()->max('version_no') + 1;
        $video->versions()->create([
            'version_no' => $nextNo,
            'snapshot' => $snapshot,
        ]);
    }

    private function branchLabel(ContentBranch $branch): string
    {
        return $branch->format === ContentFormat::Video
            ? '视频制作'
            : (string) $branch->channel->value;
    }

    private function assertVideo(ContentBranch $branch): void
    {
        if ($branch->format !== ContentFormat::Video) {
            throw new InvalidArgumentException('仅 video 分支支持视频制作接口。');
        }
        if ($branch->channel !== Channel::WechatChannels) {
            throw new InvalidArgumentException('仅视频号（wechat_channels）分支支持视频制作接口。');
        }
    }
}
