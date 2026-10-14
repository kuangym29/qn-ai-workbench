<?php

namespace App\Models;

use App\Enums\ImageBatchRunMode;
use App\Enums\ImageBatchRunStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 批次执行记录（轻量历史）。第一阶段只记录，不真实生图。
 */
class ImageBatchRun extends Model
{
    use HasFactory;

    protected $fillable = [
        'image_batch_draft_id',
        'mode',
        'unit_ids',
        'status',
        'instruction_snapshot',
    ];

    /** 只保留 created_at（spec）：关闭 updated_at。 */
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'mode' => ImageBatchRunMode::class,
            'status' => ImageBatchRunStatus::class,
            'unit_ids' => 'array',
            'instruction_snapshot' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(ImageBatchDraft::class, 'image_batch_draft_id');
    }
}
