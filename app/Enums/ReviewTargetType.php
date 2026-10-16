<?php

namespace App\Enums;

/**
 * 稳定内部值：审核目标类型。前端不要自己猜 label，后端统一输出正式 label。
 *
 * 第一阶段支持：
 *   image_post_copy   图片文章
 *   article_copy      公众号图文
 *   video_production  视频制作
 *   image_batch       图片批次
 */
enum ReviewTargetType: string
{
    case ImagePostCopy = 'image_post_copy';
    case ArticleCopy = 'article_copy';
    case VideoProduction = 'video_production';
    case ImageBatch = 'image_batch';

    public function label(): string
    {
        return match ($this) {
            self::ImagePostCopy => '图片文章',
            self::ArticleCopy => '公众号图文',
            self::VideoProduction => '视频制作',
            self::ImageBatch => '图片批次',
        };
    }
}
