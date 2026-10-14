<?php

namespace App\Models;

use App\Enums\ImageBatchUnitStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 单页生产设置：特殊要求 + 来源指纹 + 生产状态。
 * 页面内容（final_copy / visual_evidence 等）一律实时读 ContentPage，不复制。
 */
class ImageBatchUnitSetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'image_batch_draft_id',
        'content_page_id',
        'special_requirement',
        'source_fingerprint',
        'status',
    ];

    protected function casts(): array
    {
        return ['status' => ImageBatchUnitStatus::class];
    }

    public function draft(): BelongsTo
    {
        return $this->belongsTo(ImageBatchDraft::class, 'image_batch_draft_id');
    }

    public function contentPage(): BelongsTo
    {
        return $this->belongsTo(ContentPage::class);
    }
}
