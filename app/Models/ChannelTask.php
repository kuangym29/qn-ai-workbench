<?php

namespace App\Models;

use App\Enums\Channel;
use App\Enums\PublishStatus;
use App\Enums\VideoStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChannelTask extends Model
{
    use HasFactory;

    protected $fillable = ['channel', 'video_status', 'publish_status', 'scheduled_at', 'published_at'];

    protected function casts(): array
    {
        return [
            'channel' => Channel::class,
            'video_status' => VideoStatus::class,
            'publish_status' => PublishStatus::class,
            // DEV-W05: both timestamps are stored in UTC by the database.
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function productionTask(): BelongsTo
    {
        return $this->belongsTo(ProductionTask::class);
    }

    public function channelAssetBindings(): HasMany
    {
        return $this->hasMany(ChannelAssetBinding::class);
    }
}
