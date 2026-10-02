<?php

namespace App\Models;

use App\Enums\SourceAuthority;
use App\Enums\SourceRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SourceReference extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id', 'content_item_id', 'role', 'authority', 'source_path', 'note',
    ];

    protected function casts(): array
    {
        return [
            'role' => SourceRole::class,
            'authority' => SourceAuthority::class,
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }
}
