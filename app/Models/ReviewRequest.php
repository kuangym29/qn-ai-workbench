<?php

namespace App\Models;

use App\Enums\ReviewStatus;
use App\Enums\ReviewTargetType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 一条审核请求（待我审核 / 通过 / 退回修改）。
 *
 * 审核中心只保存轻量可读信息，不理解各模块全部内部结构。
 */
class ReviewRequest extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'content_item_id',
        'content_branch_id',
        'review_target_type',
        'review_target_label',
        'version_label',
        'preview_summary',
        'preview',
        'status',
        'submitted_at',
        'decided_at',
    ];

    protected $casts = [
        'review_target_type' => ReviewTargetType::class,
        'status' => ReviewStatus::class,
        'preview' => 'array',
        'submitted_at' => 'datetime',
        'decided_at' => 'datetime',
    ];

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

    public function decisions(): HasMany
    {
        return $this->hasMany(ReviewDecision::class)->orderBy('decision_no');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', ReviewStatus::Pending);
    }

    public function isPending(): bool
    {
        return $this->status === ReviewStatus::Pending;
    }
}
