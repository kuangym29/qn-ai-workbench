<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 一份视频号视频制作（一个 video 分支一份）。
 *
 * 只保存「用户可编辑制作信息」：batch_instruction / narration / units。
 * 序号、来源页、来源标签、timeline、total_duration 全部由系统在 GET 时计算，不入库。
 */
class VideoContent extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'content_item_id',
        'content_branch_id',
        'batch_instruction',
        'narration',
        'units',
        'source_fingerprint',
    ];

    protected $casts = [
        'units' => 'array',
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

    public function versions(): HasMany
    {
        return $this->hasMany(VideoContentVersion::class)->orderBy('version_no');
    }
}
