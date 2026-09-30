<?php

namespace App\Models;

use App\Enums\Channel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelTask extends Model
{
    use HasFactory;

    protected $fillable = ['channel', 'video_status', 'publish_status'];

    protected function casts(): array
    {
        return ['channel' => Channel::class];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function productionTask(): BelongsTo
    {
        return $this->belongsTo(ProductionTask::class);
    }
}
