<?php

namespace Tests\Unit;

use App\Enums\AnalyticsMode;
use App\Enums\ConnectionStatus;
use App\Enums\FinanceState;
use App\Enums\PayoutStatus;
use App\Enums\Platform;
use App\Enums\StockStatus;
use PHPUnit\Framework\TestCase;

/** The enum values are the strings the database and the clients already use. */
final class EnumsTest extends TestCase
{
    public function test_values_match_what_is_stored(): void
    {
        $this->assertSame(['final', 'estimated', 'unknown'], array_column(FinanceState::cases(), 'value'));
        $this->assertSame(['succeeded', 'processing', 'failed', 'cancelled', 'unknown'], array_column(PayoutStatus::cases(), 'value'));
        $this->assertSame(['safe', 'low', 'out', 'unknown', 'unconfigured'], array_column(StockStatus::cases(), 'value'));
        $this->assertSame(['verified', 'reauthorize', 'disconnected', 'pending'], array_column(ConnectionStatus::cases(), 'value'));
        $this->assertSame(['all', 'settled'], array_column(AnalyticsMode::cases(), 'value'));
        $this->assertSame('TikTok Shop', Platform::TikTokShop->value);
    }

    public function test_only_shopee_and_tiktok_shop_connect_to_a_real_api(): void
    {
        $live = array_filter(Platform::cases(), fn (Platform $p) => $p->isLive());

        $this->assertSame([Platform::Shopee, Platform::TikTokShop], array_values($live));
        $this->assertSame(Platform::Lazada, Platform::from('Lazada'));
        $this->assertNull(Platform::tryFrom('Amazon'));
    }
}
