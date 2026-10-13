<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * 视频制作版本快照。append-only：只新增，不更新，不删除。
 *
 * 仅 created_at。version_no 同一 video_content 从 1 递增。
 */
class VideoContentVersion extends Model
{
    use HasFactory;

    public const CREATED_AT = 'created_at';

    public const UPDATED_AT = null;

    protected $fillable = [
        'video_content_id',
        'version_no',
        'snapshot',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            throw new LogicException('Video content versions are append-only.');
        });

        static::deleting(function (self $version): void {
            throw new LogicException('Video content versions cannot be deleted.');
        });
    }

    public function videoContent(): BelongsTo
    {
        return $this->belongsTo(VideoContent::class);
    }
}
