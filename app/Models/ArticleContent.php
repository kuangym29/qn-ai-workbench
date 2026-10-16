<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 公众号 · 图文文章（article）的正式内容。
 *
 * 不是逐页内容，因此与 ContentPage 无关。正式字段只保留产品确认的 5 个：
 *   headline / summary / body / image_suggestions(JSON) / cover_suggestion
 * 文章级共享字段（title / core_message / story_line）来自 ContentItem，不重复存储。
 *
 * 一个 ContentBranch 只对应一份 article 正式内容（content_branch_id 唯一）。
 */
class ArticleContent extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id', 'content_item_id', 'content_branch_id',
        'headline', 'summary', 'body', 'image_suggestions', 'cover_suggestion',
    ];

    protected function casts(): array
    {
        return ['image_suggestions' => 'array'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    public function contentBranch(): BelongsTo
    {
        return $this->belongsTo(ContentBranch::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ArticleContentVersion::class);
    }
}
