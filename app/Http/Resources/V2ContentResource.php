<?php

namespace App\Http\Resources;

use App\Models\ContentBranch;
use App\Models\ContentItem;
use App\Models\Inspiration;
use App\Models\Topic;

/** Explicit V2 contract, independent of the legacy nested API resources. */
class V2ContentResource
{
    public static function inspiration(Inspiration $model): array
    {
        return $model->only(['id', 'project_id', 'body', 'note']);
    }

    public static function topic(Topic $model): array
    {
        $model->loadMissing(['inspiration', 'contentItem']);

        return [
            ...$model->only(['id', 'title', 'description', 'content_column_id']),
            'status' => $model->status->value, 'is_backup' => $model->is_backup,
            'inspiration' => $model->inspiration?->only(['id', 'body']),
            'has_content_item' => $model->contentItem !== null,
            'content_item_id' => $model->contentItem?->id,
        ];
    }

    public static function branch(ContentBranch $model): array
    {
        return [
            ...$model->only(['id', 'project_id', 'content_item_id']),
            'channel' => $model->channel->value, 'format' => $model->format->value,
            'channel_label' => match ($model->channel->value) {
                'wechat_official' => '微信公众号', 'wechat_channels' => '微信视频号', 'xiaohongshu' => '小红书',
            },
            'format_label' => match ($model->format->value) {
                'image_post' => '图片文章（小绿书）', 'article' => '图文文章', 'video' => '视频',
            },
            'stopped_at' => $model->stopped_at?->toISOString(),
        ];
    }

    public static function item(ContentItem $model): array
    {
        $model->loadMissing(['topic.inspiration', 'contentBranches']);

        return [
            ...$model->only(['id', 'project_id', 'topic_id', 'content_column_id', 'title', 'core_message', 'story_line']),
            'topic' => ['id' => $model->topic->id, 'title' => $model->topic->title, 'inspiration' => $model->topic->inspiration?->only(['id', 'body'])],
            'content_branches' => $model->contentBranches->map(self::branch(...))->values()->all(),
        ];
    }
}
