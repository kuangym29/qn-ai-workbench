<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

class ContentCopyRevision extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Copy revisions are immutable.'));
        static::deleting(fn () => throw new LogicException('Copy revisions are immutable.'));
    }

    protected function casts(): array
    {
        return ['revision_no' => 'integer', 'confirmed_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    public function pageVersions(): HasMany
    {
        return $this->hasMany(ContentPageVersion::class, 'copy_revision_id');
    }
}
