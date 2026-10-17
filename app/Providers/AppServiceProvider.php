<?php

namespace App\Providers;

use App\Contracts\V2\AssetNameSource;
use App\Contracts\V2\ReusableAssetReferenceReader;
use App\Services\V2\ContentPageReusableAssetReferenceReader;
use App\Services\V2\ReusableAssetNameSource;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // I06B：ImageBatch 资产名称来源正式绑定到 ReusableAssetNameSource。
        // P5-I03：「页面引用了哪些人物 / 场景」首次有正式显式真源——
        //   content_page_reusable_asset_references（用户在 ImageBatch 里显式选择），
        // 因此生产容器把 ReusableAssetReferenceReader 绑定到真实实现，
        // 正式替换 EmptyAssetReferenceReader（后者保留为测试 / fallback 类，生产不再绑定）。
        // ReusableAssetNameSource / AssetNameSource 的既有绑定与职责保持不变。
        $this->app->bind(AssetNameSource::class, ReusableAssetNameSource::class);
        $this->app->bind(ReusableAssetReferenceReader::class, ContentPageReusableAssetReferenceReader::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
