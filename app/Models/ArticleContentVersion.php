<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 图文文章版本快照。
 *
 * snapshot 保存当次正式内容（article 的 5 个字段），用于历史版本保留。
 * version_no 在同一个 article_content_id 内自增。
 */
class ArticleContentVersion extends Model
{
    use HasFactory;

    protected $fillable = ['article_content_id', 'version_no', 'snapshot'];

    protected function casts(): array
    {
        return ['snapshot' => 'array'];
    }

    public function articleContent(): BelongsTo
    {
        return $this->belongsTo(ArticleContent::class);
    }
}
