<?php

namespace App\Models;

use App\Enums\PageType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class ContentPage extends Model
{
    use HasFactory;

    protected $fillable = ['page_no', 'page_type'];

    protected static function booted(): void
    {
        static::saving(function (self $page): void {
            if ($page->page_no < 1 || $page->page_no > PHP_INT_MAX) {
                throw new InvalidArgumentException('page_no must be a positive integer.');
            }
        });
    }

    protected function casts(): array
    {
        return ['page_type' => PageType::class, 'page_no' => 'integer'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function contentItem(): BelongsTo
    {
        return $this->belongsTo(ContentItem::class);
    }

    public function versions(): HasMany
    {
        return $this->hasMany(ContentPageVersion::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }
}
