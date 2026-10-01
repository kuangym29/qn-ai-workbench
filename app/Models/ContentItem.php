<?php

namespace App\Models;

use App\Enums\CopyStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ContentItem extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'copy_status'];

    protected function casts(): array
    {
        return ['copy_status' => CopyStatus::class];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contentColumn(): BelongsTo
    {
        return $this->belongsTo(ContentColumn::class);
    }

    public function topic(): BelongsTo
    {
        return $this->belongsTo(Topic::class);
    }

    public function productionTask(): HasOne
    {
        return $this->hasOne(ProductionTask::class);
    }
}
