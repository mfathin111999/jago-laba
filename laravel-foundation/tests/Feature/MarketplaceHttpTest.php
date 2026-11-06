<?php

namespace Tests\Feature;

use App\Exceptions\MarketplaceIntegrationException;
use App\Services\Marketplace\Http\MarketplaceApiOrigin;
use App\Services\Marketplace\Http\MarketplaceHttp;
use App\Services\Marketplace\Http\Signing\ShopeeSigner;
use App\Services\Marketplace\Http\Signing\TikTokSigner;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class MarketplaceHttpTest extends TestCase
{
    private const URL = 'https://partner.shopeemobile.com/api/v2/shop/get_shop_info?x=1';

    private function send(string $method = 'GET', string $url = self::URL, array $headers = [], ?array $body = null): array
    {
        return app(MarketplaceHttp::class)->request($method, $url, $headers, $body);
    }

    private function failureCode(callable $call): MarketplaceIntegrationException
    {
        try {
            $call();
        } catch (MarketplaceIntegrationException $exception) {
            return $exception;
        }
        $this->fail('Expected a MarketplaceIntegrationException');
    }

    public function test_a_json_answer_is_returned_as_an_array_and_the_request_is_json(): void
    {
        Http::fake(['*' => Http::response(['response' => ['shop_id' => 7]])]);

        $this->assertSame(['response' => ['shop_id' => 7]], $this->send('POST', self::URL, ['x-tts-access-token' => 'T', 'Content-Type: text/plain'], ['a' => 1]));

        Http::assertSent(function (Request $request): bool {
            return $request->method() === 'POST'
                && $request->header('Accept') === ['application/json']
                && $request->header('x-tts-access-token') === ['T']
                && $request->header('Content-Type') === ['application/json']
                && $request->data() === ['a' => 1];
        });
    }

    public function test_a_host_that_is_not_a_known_marketplace_is_refused_before_anything_is_sent(): void
    {
        Http::fake();

        foreach (['https://evil.example.com/x', 'http://partner.shopeemobile.com/x', 'https://user:pw@partner.shopeemobile.com/x', 'https://partner.shopeemobile.com:8443/x'] as $url) {
            $this->assertSame('API_HOST_INVALID', $this->failureCode(fn () => $this->send('GET', $url))->errorCode, $url);
        }
        Http::assertNothingSent();
    }

    public function test_a_failure_exposes_machine_identifiers_only(): void
    {
        Http::fake(['*' => Http::response(['error' => 'error_auth', 'code' => '105001', 'request_id' => 'rq-1', 'message' => 'SECRET token=abc https://signed.example/?sig=zzz'], 403)]);

        $exception = $this->failureCode(fn () => $this->send());

        $this->assertSame('HTTP_403', $exception->errorCode);
        $this->assertStringContainsString('Error: error_auth', $exception->getMessage());
        $this->assertStringContainsString('Code: 105001', $exception->getMessage());
        $this->assertStringContainsString('Request ID: rq-1', $exception->getMessage());
        $this->assertStringNotContainsString('SECRET', $exception->getMessage());
        $this->assertStringNotContainsString('signed.example', $exception->getMessage());
    }

    public function test_rate_limits_carry_the_retry_after_value_capped_at_one_day(): void
    {
        Http::fake(['*' => Http::sequence()
            ->push('', 429, ['Retry-After' => '120'])
            ->push('', 429, ['Retry-After' => '999999999'])
            ->push('', 503)]);

        $this->assertSame(120, $this->failureCode(fn () => $this->send())->retryAfterSeconds);
        $this->assertSame(86400, $this->failureCode(fn () => $this->send())->retryAfterSeconds);
        $this->assertNull($this->failureCode(fn () => $this->send())->retryAfterSeconds);
    }

    public function test_tiktok_error_36009004_gets_a_specific_hint(): void
    {
        Http::fake(['*' => Http::response(['code' => '36009004', 'message' => 'Invalid timestamp'], 400)]);

        $this->assertStringContainsString('Timestamp permintaan tidak valid', $this->failureCode(fn () => $this->send())->getMessage());
    }

    public function test_an_answer_that_is_not_a_json_array_is_invalid(): void
    {
        $bodies = ['not json', '"text"', '42', ''];
        $sequence = Http::sequence();
        foreach ($bodies as $body) {
            $sequence->push($body, 200);
        }
        Http::fake(['*' => $sequence]);

        foreach ($bodies as $body) {
            $this->assertSame('INVALID_PLATFORM_RESPONSE', $this->failureCode(fn () => $this->send())->errorCode, var_export($body, true));
        }
    }

    public function test_a_network_failure_is_a_transport_error(): void
    {
        Http::fake(['*' => fn () => throw new ConnectionException('cURL error 28')]);

        $this->assertSame('HTTP_TRANSPORT_ERROR', $this->failureCode(fn () => $this->send())->errorCode);
    }

    public function test_redirects_are_never_followed(): void
    {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://evil.example.com/'])]);

        $this->assertSame('HTTP_302', $this->failureCode(fn () => $this->send())->errorCode);
        Http::assertSentCount(1);
    }

    public function test_the_origin_helper_accepts_only_the_known_api_hosts(): void
    {
        $this->assertSame('https://partner.shopeemobile.com', MarketplaceApiOrigin::validate('Shopee', 'https://partner.shopeemobile.com/'));
        $this->assertSame('https://open-api.tiktokglobalshop.com', MarketplaceApiOrigin::validate('TikTok Shop', 'https://open-api.tiktokglobalshop.com'));
        foreach ([['Shopee', 'https://open-api.tiktokglobalshop.com'], ['TikTok Shop', 'https://partner.shopeemobile.com'], ['Shopee', 'https://partner.shopeemobile.com/path'], ['Shopee', 'http://partner.shopeemobile.com']] as [$platform, $base]) {
            $this->assertSame('API_HOST_INVALID', $this->failureCode(fn () => MarketplaceApiOrigin::validate($platform, $base))->errorCode, $base);
        }
        $this->assertSame('SHOPEE_ERROR_AUTH', $this->failureCode(fn () => MarketplaceApiOrigin::shopeeError('error_auth'))->errorCode);
        $this->assertSame('SHOPEE_REJECTED', $this->failureCode(fn () => MarketplaceApiOrigin::shopeeError('bad value!'))->errorCode);
    }

    public function test_the_shopee_signature_matches_the_values_the_legacy_client_produced(): void
    {
        $this->assertSame('657a145329c53a16c57fc25a4ef0463fda639d86dd8c05358efa787e163c3e34', ShopeeSigner::sign('100001', 'test-partner-key-not-real', '/api/v2/shop/get_shop_info', 1700000000, 'TOKEN', '1234567'));
        $this->assertSame('c11c0694149f88b099580940b8a86a5f17aae71352d86932da1b452fc3d52f85', ShopeeSigner::sign('100001', 'test-partner-key-not-real', '/api/v2/auth/token/get', 1700000000));
    }

    public function test_the_tiktok_signature_sorts_the_query_ignores_sign_and_token_and_includes_the_body(): void
    {
        $query = ['timestamp' => 1700000000, 'shop_cipher' => 'CIP', 'app_key' => 'AK', 'page_size' => 20, 'sign' => 'old', 'access_token' => 'T'];
        $body = json_encode(['a' => 1]);

        $this->assertSame('62f6aafcf6f32214e9a290d77bc4f795a8772867e093c23cb807ef2098d7f32e', TikTokSigner::sign('sec', '/order/202309/orders/search', $query, $body, false));
        $this->assertSame(
            TikTokSigner::sign('sec', '/p', ['b' => '', 'a' => '1']),
            TikTokSigner::sign('sec', '/p', ['a' => '1']),
            'the authorization calls skip empty values',
        );
        $this->assertNotSame(TikTokSigner::sign('sec', '/p', ['b' => '', 'a' => '1'], '', false), TikTokSigner::sign('sec', '/p', ['a' => '1'], '', false));
    }
}
