<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class AssetVersion extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Asset versions are immutable.'));
        static::deleting(fn () => throw new LogicException('Asset versions are immutable.'));
    }

    protected function casts(): array
    {
        return ['version_no' => 'integer'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function copyRevision(): BelongsTo
    {
        return $this->belongsTo(ContentCopyRevision::class, 'copy_revision_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function channelAssetBindings(): HasMany
    {
        return $this->hasMany(ChannelAssetBinding::class);
    }
}
