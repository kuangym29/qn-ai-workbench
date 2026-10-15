<?php

namespace App\Providers;

use App\Contracts\V2\AssetNameSource;
use App\Contracts\V2\ReusableAssetReferenceReader;
use App\Services\V2\EmptyAssetReferenceReader;
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
        // 默认引用读取器为空（Foundation 还没有「页面 → 资产」显式登记），
        // 因此生产环境默认返回空引用：不推断、不猜测人物或场景。
        // 真正「页面引用了哪些名字」只能由显式数据来源回答（例如后续页面标注 /
        // unit setting 引用），届时只需把 ReusableAssetReferenceReader 绑定到真实实现。
        $this->app->bind(AssetNameSource::class, ReusableAssetNameSource::class);
        $this->app->bind(ReusableAssetReferenceReader::class, EmptyAssetReferenceReader::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
