<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ChannelAssetBinding extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Channel asset bindings are immutable.'));
        static::deleting(fn () => throw new LogicException('Channel asset bindings are immutable.'));
    }

    protected function casts(): array
    {
        return ['binding_no' => 'integer'];
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

    public function channelTask(): BelongsTo
    {
        return $this->belongsTo(ChannelTask::class);
    }

    public function contentPage(): BelongsTo
    {
        return $this->belongsTo(ContentPage::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function assetVersion(): BelongsTo
    {
        return $this->belongsTo(AssetVersion::class);
    }

    public function copyRevision(): BelongsTo
    {
        return $this->belongsTo(ContentCopyRevision::class, 'copy_revision_id');
    }
}
