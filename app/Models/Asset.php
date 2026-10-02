<?php

namespace App\Models;

use App\Enums\AssetRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class Asset extends Model
{
    use HasFactory;

    protected $fillable = [];

    protected static function booted(): void
    {
        static::updating(function (self $asset): void {
            foreach (['project_id', 'content_item_id', 'production_task_id', 'content_page_id', 'role'] as $field) {
                if ($asset->isDirty($field)) {
                    throw new LogicException('Asset identity and role are immutable.');
                }
            }
        });
    }

    protected function casts(): array
    {
        return ['role' => AssetRole::class];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    public function productionTask(): BelongsTo
    {
        return $this->belongsTo(ProductionTask::class);
    }

    public function contentPage(): BelongsTo
    {
        return $this->belongsTo(ContentPage::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(AssetVersion::class);
    }
}
