<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * P5-I03：ContentPage → ReusableAsset 的显式引用关系。
 *
 * 只保存稳定身份（reusable_asset_id）。name / type / status 一律实时读
 * reusable_assets（P5-I02 已冻结 name / type 不可修改），此处绝不复制。
 */
class ContentPageReusableAssetReference extends Model
{
    use HasFactory;

    protected $fillable = [
        'content_page_id',
        'reusable_asset_id',
    ];

    public function contentPage(): BelongsTo
    {
        return $this->belongsTo(ContentPage::class);
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(ReusableAsset::class, 'reusable_asset_id');
    }
}
