<?php

namespace App\Enums;

enum Channel: string
{
    case WechatOfficial = 'wechat_official';

    case WechatChannels = 'wechat_channels';

    public function expectedAssetRole(): AssetRole
    {
        return match ($this) {
            self::WechatOfficial => AssetRole::CopyMaster,
            self::WechatChannels => AssetRole::CleanMaster,
        };
    }
}
