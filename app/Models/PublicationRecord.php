<?php

namespace App\Models;

use App\Enums\ReviewTargetType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 一条发布记录：用户点击"标记已发布"产生的历史事实。
 *
 * append-only：一旦创建，禁止 UPDATE / DELETE（由模型 booted 事件强制）。
 * 内容修改后产生新版本；新版本审核通过后可产生第二条记录，旧记录永久保留。
 */
class PublicationRecord extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'content_item_id',
        'content_branch_id',
        'review_request_id',
        'review_target_type',
        'version_label',
        'published_by_user_id',
        'published_at',
    ];

    protected function casts(): array
    {
        return [
            'review_target_type' => ReviewTargetType::class,
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // 发布记录是历史事实：禁止 UPDATE。
        static::updating(function (self $record): void {
            throw new \RuntimeException('PublicationRecord is append-only and cannot be updated.');
        });
        // 发布记录是历史事实：禁止 DELETE。
        static::deleting(function (self $record): void {
            throw new \RuntimeException('PublicationRecord is append-only and cannot be deleted.');
        });
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

    public function reviewRequest(): BelongsTo
    {
        return $this->belongsTo(ReviewRequest::class);
    }

    public function publishedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }
}
