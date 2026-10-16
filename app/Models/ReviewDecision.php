<?php

namespace App\Models;

use App\Enums\ReviewDecision as ReviewDecisionEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 一条审核决策记录。append-only：只新增，不更新，不删除。
 *
 * 仅 created_at（决策不可变）。decision_no 同一 review_request 从 1 递增。
 */
class ReviewDecision extends Model
{
    use HasFactory;

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;

    protected $fillable = [
        'review_request_id',
        'decision_no',
        'decision',
        'comment',
        'target_label',
    ];

    protected $casts = [
        'decision' => ReviewDecisionEnum::class,
        'created_at' => 'datetime',
    ];

    public function reviewRequest(): BelongsTo
    {
        return $this->belongsTo(ReviewRequest::class);
    }
}
