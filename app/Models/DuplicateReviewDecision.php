<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class DuplicateReviewDecision extends Model
{
    protected $fillable = [
        'project_id', 'content_item_id', 'query_page_version_id', 'query_field',
        'match_page_version_id', 'match_field', 'decision_no', 'decision', 'note',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Duplicate review decisions are immutable.'));
        static::deleting(fn () => throw new LogicException('Duplicate review decisions are immutable.'));
    }

    protected function casts(): array
    {
        return [
            'query_page_version_id' => 'integer',
            'match_page_version_id' => 'integer',
            'decision_no' => 'integer',
        ];
    }

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }
}
