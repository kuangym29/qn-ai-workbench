<?php

namespace App\Services;

use App\Models\ContentBranch;
use App\Models\ContentItem;
use App\Models\Topic;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class StartContentService
{
    public static function validateSelections(array $selections): void
    {
        Validator::make(['branches' => $selections], [
            'branches' => ['required', 'array', 'min:1'],
            'branches.*' => ['required', 'array:channel,format'],
            'branches.*.channel' => ['required', 'string'],
            'branches.*.format' => ['required', 'string'],
        ])->validate();
        $seen = [];
        foreach ($selections as $index => $selection) {
            $pair = $selection['channel'].':'.$selection['format'];
            if (! in_array($pair, ['wechat_official:image_post', 'wechat_official:article', 'wechat_channels:video'], true) || isset($seen[$pair])) {
                throw ValidationException::withMessages(["branches.$index.format" => '请选择有效且不重复的渠道和内容形式。']);
            }
            $seen[$pair] = true;
        }
    }

    public function start(Topic $topic, array $branchSelections): ContentItem
    {
        self::validateSelections($branchSelections);

        return DB::transaction(function () use ($topic, $branchSelections): ContentItem {
            $locked = Topic::query()->whereKey($topic->id)->lockForUpdate()->firstOrFail();
            if ($existing = $locked->contentItem()->first()) {
                throw new HttpResponseException(response()->json(['message' => '该选题已开始创作。', 'content_item_id' => $existing->id], 409));
            }
            $item = $locked->contentItems()->forceCreate([
                'project_id' => $locked->project_id, 'content_column_id' => $locked->content_column_id,
                'title' => $locked->title, 'copy_status' => 'not_started',
            ]);
            foreach ($branchSelections as $selection) {
                $item->contentBranches()->forceCreate(['project_id' => $item->project_id, ...$selection]);
            }

            return $item->refresh();
        });
    }

    public function addBranch(ContentItem $item, array $selection): ContentBranch
    {
        self::validateSelections([$selection]);

        return DB::transaction(function () use ($item, $selection): ContentBranch {
            ContentItem::query()->whereKey($item->id)->lockForUpdate()->firstOrFail();
            $branch = $item->contentBranches()->where($selection)->first()
                ?? $item->contentBranches()->forceCreate(['project_id' => $item->project_id, ...$selection]);
            $branch->resume();

            return $branch->refresh();
        });
    }
}
