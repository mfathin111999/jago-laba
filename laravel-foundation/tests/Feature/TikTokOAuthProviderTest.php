<?php

namespace Tests\Feature;

use App\Exceptions\MarketplaceOAuthException;
use App\Marketplace\IntegrationPolicy;
use App\Marketplace\OAuth\MarketplaceOAuthProviderFactory;
use App\Marketplace\OAuth\ShopeeOAuthProvider;
use App\Marketplace\OAuth\TikTokShopOAuthProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class TikTokOAuthProviderTest extends TestCase
{
    private const CONFIG = [
        'app_key' => 'APPKEY', 'app_secret' => 'APPSECRET', 'service_id' => 'SVC1', 'region' => 'row',
        'auth_url' => 'https://services.tiktokshop.com/open/authorize', 'api_base_url' => 'https://open-api.tiktokglobalshop.com',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        config(['jago.oauth.mode' => 'live', 'services.tiktok_shop' => self::CONFIG]);
    }

    private function provider(): TikTokShopOAuthProvider
    {
        $provider = app(MarketplaceOAuthProviderFactory::class)->for('TikTok Shop');
        $this->assertInstanceOf(TikTokShopOAuthProvider::class, $provider);

        return $provider;
    }

    private function fakeTikTok(?array $token = null): void
    {
        $token ??= ['code' => 0, 'data' => ['access_token' => 'TT-ACCESS', 'refresh_token' => 'TT-REFRESH', 'access_token_expire_in' => 3600, 'refresh_token_expire_in' => 86400, 'user_type' => 0, 'granted_scopes' => ['seller.authorization.info', 'seller.product.basic']]];
        Http::fake([
            'auth.tiktok-shops.com/*' => Http::response($token),
            'open-api.tiktokglobalshop.com/authorization/*' => Http::response(['code' => 0, 'data' => ['shops' => [['id' => 'S1', 'name' => 'Toko TikTok', 'cipher' => 'CIPHER1', 'region' => 'ID']]]]),
        ]);
    }

    public function test_the_authorization_url_carries_the_service_id_and_state(): void
    {
        $url = $this->provider()->authorizationUrl('STATE1', 'https://app.test/callback');

        $this->assertStringStartsWith('https://services.tiktokshop.com/open/authorize?', $url);
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame(['service_id' => 'SVC1', 'state' => 'STATE1'], $query);
    }

    public function test_exchanging_a_code_returns_tokens_scopes_and_the_authorized_shops(): void
    {
        $this->fakeTikTok();

        $tokens = $this->provider()->exchangeCode('AUTHCODE', []);

        $this->assertSame('TT-ACCESS', $tokens['access_token']);
        $this->assertSame('TT-REFRESH', $tokens['refresh_token']);
        $this->assertSame(['seller.authorization.info', 'seller.product.basic'], $tokens['granted_scopes']);
        $this->assertSame([['id' => 'S1', 'name' => 'Toko TikTok', 'cipher' => 'CIPHER1', 'region' => 'ID']], $tokens['shops']);
        $this->assertNotNull($tokens['token_expires_at']);

        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'auth.tiktok-shops.com/api/v2/token/get') && str_contains($r->url(), 'auth_code=AUTHCODE') && str_contains($r->url(), 'grant_type=authorized_code'));
        Http::assertSent(function (Request $r): bool {
            if (! str_contains($r->url(), '/authorization/202309/shops')) {
                return false;
            }
            parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);
            $expected = hash_hmac('sha256', 'APPSECRET/authorization/202309/shopsapp_keyAPPKEYtimestamp'.$q['timestamp'].'APPSECRET', 'APPSECRET');

            return $q['sign'] === $expected && $r->header('x-tts-access-token') === ['TT-ACCESS'];
        });
    }

    public function test_a_refresh_keeps_the_old_refresh_token_when_the_platform_sends_none(): void
    {
        $this->fakeTikTok(['code' => 0, 'data' => ['access_token' => 'NEW', 'user_type' => 0, 'access_token_expire_in' => 3600]]);

        $tokens = $this->provider()->refreshToken('OLD-REFRESH', 'S1', 'CIPHER1');

        $this->assertSame('NEW', $tokens['access_token']);
        $this->assertSame('OLD-REFRESH', $tokens['refresh_token']);
    }

    public function test_a_buyer_token_is_not_accepted(): void
    {
        $this->fakeTikTok(['code' => 0, 'data' => ['access_token' => 'X', 'refresh_token' => 'Y', 'user_type' => 1]]);

        try {
            $this->provider()->exchangeCode('AUTHCODE', []);
            $this->fail('A non-seller token must be refused');
        } catch (MarketplaceOAuthException $exception) {
            $this->assertSame('TOKEN_EXCHANGE_FAILED', $exception->errorCode);
        }
    }

    public function test_a_rejected_token_and_a_network_failure_map_to_the_screen_codes(): void
    {
        Http::fake(['auth.tiktok-shops.com/*' => Http::sequence()->push(['code' => 105001, 'message' => 'SECRET detail'])->push('', 503)]);
        try {
            $this->provider()->exchangeCode('AUTHCODE', []);
            $this->fail('rejected');
        } catch (MarketplaceOAuthException $exception) {
            $this->assertSame('TOKEN_EXCHANGE_FAILED', $exception->errorCode);
            $this->assertStringNotContainsString('SECRET', $exception->getMessage());
        }

        try {
            $this->provider()->exchangeCode('AUTHCODE', []);
            $this->fail('503');
        } catch (MarketplaceOAuthException $exception) {
            $this->assertSame('CONNECTION_NOT_VERIFIED', $exception->errorCode);
        }
    }

    public function test_missing_settings_are_listed_and_block_the_authorization_url(): void
    {
        config(['services.tiktok_shop' => ['app_key' => '', 'app_secret' => '', 'service_id' => '', 'auth_url' => 'https://services.tiktokshop.com/open/authorize', 'api_base_url' => 'https://open-api.tiktokglobalshop.com']]);
        $provider = $this->provider();

        $this->assertSame(['JL_TIKTOK_SHOP_APP_KEY', 'JL_TIKTOK_SHOP_APP_SECRET', 'JL_TIKTOK_SHOP_SERVICE_ID'], $provider->configurationMissing());
        $this->expectException(MarketplaceOAuthException::class);
        $provider->authorizationUrl('S', 'https://app.test/cb');
    }

    public function test_shopee_needs_a_matching_host_pair_for_its_environment(): void
    {
        $factory = app(MarketplaceOAuthProviderFactory::class);
        config(['services.shopee' => ['partner_id' => '1', 'partner_key' => 'k', 'region' => 'sandbox', 'auth_url' => 'https://open.shopee.com/auth', 'api_base_url' => 'https://partner.shopeemobile.com']]);
        $provider = $factory->for('Shopee');

        $this->assertInstanceOf(ShopeeOAuthProvider::class, $provider);
        $this->assertSame(['JL_SHOPEE_ENVIRONMENT_URLS'], $provider->configurationMissing(), 'a live host with the sandbox region is refused');
        $this->assertSame('sandbox', $provider->context()['environment']);
    }

    public function test_the_context_fingerprint_changes_with_the_credentials(): void
    {
        $a = IntegrationPolicy::fingerprint('TikTok Shop', self::CONFIG);
        $b = IntegrationPolicy::fingerprint('TikTok Shop', ['app_secret' => 'OTHER'] + self::CONFIG);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a);
        $this->assertNotSame($a, $b);
        $this->assertSame('unspecified', IntegrationPolicy::environment('TikTok Shop', self::CONFIG));
        $this->assertNull(IntegrationPolicy::retry('HTTP_404', 0));
        $this->assertNull(IntegrationPolicy::retry('HTTP_429', 3));
        $this->assertGreaterThanOrEqual(120, IntegrationPolicy::retry('HTTP_429', 0, 120));
    }

    public function test_an_unsupported_platform_is_refused_in_live_mode(): void
    {
        $this->expectException(MarketplaceOAuthException::class);
        app(MarketplaceOAuthProviderFactory::class)->for('Lazada');
    }
}
