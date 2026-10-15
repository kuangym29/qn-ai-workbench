<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 图片批次草稿：只保存用户对「这一批怎么做」的补充。
 */
class ImageBatchDraft extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'content_item_id',
        'content_branch_id',
        'batch_instruction',
        'batch_note',
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

    public function unitSettings(): HasMany
    {
        return $this->hasMany(ImageBatchUnitSetting::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ImageBatchRun::class);
    }
}
