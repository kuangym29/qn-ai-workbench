<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Topic extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'description'];

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
