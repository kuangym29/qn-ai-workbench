<?php

namespace App\Models;

use App\Enums\ArtworkStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductionTask extends Model
{
    use HasFactory;

    protected $fillable = ['artwork_status'];

    protected function casts(): array
    {
        return ['artwork_status' => ArtworkStatus::class];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    public function copyRevision(): BelongsTo
    {
        return $this->belongsTo(ContentCopyRevision::class, 'copy_revision_id');
    }

    public function channelTasks(): HasMany
    {
        return $this->hasMany(ChannelTask::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }
}
