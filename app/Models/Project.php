<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'slug', 'description'];

    public function contentColumns(): HasMany
    {
        return $this->hasMany(ContentColumn::class);
    }

    public function topics(): HasMany
    {
        return $this->hasMany(Topic::class);
    }

    public function contentItems(): HasMany
    {
        return $this->hasMany(ContentItem::class);
    }

    public function sourceReferences(): HasMany
    {
        return $this->hasMany(SourceReference::class);
    }

    public function productionTasks(): HasMany
    {
        return $this->hasMany(ProductionTask::class);
    }

    public function channelTasks(): HasMany
    {
        return $this->hasMany(ChannelTask::class);
    }
}
