<?php

namespace App\Enums;

enum Channel: string
{
    case WechatOfficial = 'wechat_official';

    case WechatChannels = 'wechat_channels';

    case Xiaohongshu = 'xiaohongshu';

    public function expectedAssetRole(): AssetRole
    {
        return match ($this) {
            self::WechatOfficial => AssetRole::CopyMaster,
            self::WechatChannels => AssetRole::CleanMaster,
            self::Xiaohongshu => throw new \LogicException('Reserved channel has no legacy asset role.'),
        };
    }
}
