<?php

namespace App\Models;

use App\Enums\Channel;
use App\Enums\ContentFormat;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContentBranch extends Model
{
    use HasFactory;

    protected $fillable = ['channel', 'format'];

    protected function casts(): array
    {
        return ['channel' => Channel::class, 'format' => ContentFormat::class, 'stopped_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    public function isStopped(): bool
    {
        return $this->stopped_at !== null;
    }

    public function stop(): void
    {
        if (! $this->isStopped()) {
            $this->stopped_at = now();
            $this->save();
        }
    }

    public function resume(): void
    {
        if ($this->isStopped()) {
            $this->stopped_at = null;
            $this->save();
        }
    }
}
