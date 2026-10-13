<?php

namespace App\Models;

use App\Enums\ReusableAssetStatus;
use App\Enums\ReusableAssetType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 复用资产索引条目：某个 Project 下的一个长期人物 / 稳定场景母版。
 *
 * 这是「索引」，不是素材库：没有文件、没有版本、没有审批。
 */
class ReusableAsset extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'type',
        'name',
        'status',
        'thumbnail_url',
        'description',
        'last_used_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => ReusableAssetType::class,
            'status' => ReusableAssetStatus::class,
            'last_used_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @param  Builder<self>  $query */
    public function scopeOfType(Builder $query, ReusableAssetType $type): void
    {
        $query->where('type', $type->value);
    }

    /** @param  Builder<self>  $query */
    public function scopeWithStatus(Builder $query, ReusableAssetStatus $status): void
    {
        $query->where('status', $status->value);
    }

    public function isExisting(): bool
    {
        return $this->status === ReusableAssetStatus::Existing;
    }

    public function isMissing(): bool
    {
        return $this->status === ReusableAssetStatus::Missing;
    }
}
