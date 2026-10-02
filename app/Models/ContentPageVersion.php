<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ContentPageVersion extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (self $version): void {
            throw new LogicException('Page versions are append-only.');
        });

        static::deleting(function (self $version): void {
            if ($version->getRawOriginal('copy_revision_id') !== null) {
                throw new LogicException('Confirmed page versions cannot be deleted.');
            }
        });
    }

    protected function casts(): array
    {
        return ['version_no' => 'integer', 'page_no_snapshot' => 'integer'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    public function contentPage(): BelongsTo
    {
        return $this->belongsTo(ContentPage::class);
    }

    public function contentCopyRevision(): BelongsTo
    {
        return $this->belongsTo(ContentCopyRevision::class, 'copy_revision_id');
    }
}
