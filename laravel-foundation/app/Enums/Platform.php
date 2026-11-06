<?php

namespace App\Enums;

/** A marketplace a store can belong to. The values are what the database stores. */
enum Platform: string
{
    case Shopee = 'Shopee';
    case TikTokShop = 'TikTok Shop';
    case Tokopedia = 'Tokopedia';
    case Lazada = 'Lazada';
    case Blibli = 'Blibli';

    /** Platforms whose APIs are connected for real (the others only exist in the sandbox). */
    public function isLive(): bool
    {
        return in_array($this, [self::Shopee, self::TikTokShop], true);
    }
}
