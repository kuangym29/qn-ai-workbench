<?php

namespace App\Models;

use App\Enums\TopicStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Topic extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'description', 'status', 'is_backup'];

    protected function casts(): array
    {
        return ['status' => TopicStatus::class, 'is_backup' => 'boolean'];
    }

    public function inspiration(): BelongsTo
    {
        return $this->belongsTo(Inspiration::class);
    }

    public function contentItem(): HasOne
    {
        return $this->hasOne(ContentItem::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contentColumn(): BelongsTo
    {
        return $this->belongsTo(ContentColumn::class);
    }

    public function contentItems(): HasMany
    {
        return $this->hasMany(ContentItem::class);
    }
}
