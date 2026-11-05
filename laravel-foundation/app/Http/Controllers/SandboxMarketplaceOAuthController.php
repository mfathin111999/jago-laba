<?php

namespace App\Http\Controllers;

use App\Exceptions\MarketplaceOAuthException;
use App\Services\Marketplace\MarketplaceOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class SandboxMarketplaceOAuthController
{
    public function __construct(private readonly MarketplaceOAuthService $oauth) {}

    public function __invoke(Request $request): RedirectResponse
    {
        $platform = trim((string) $request->query('platform'));
        if (! in_array($platform, $this->oauth->platforms(), true)) {
            abort(404);
        }

        $state = trim((string) $request->query('state'));
        $redirectUri = trim((string) $request->query('redirect_uri'));
        $expected = $this->oauth->legacyCallbackUrl();
        if (! preg_match('/^[a-f0-9]{64}$/D', $state) || $redirectUri !== $expected) {
            throw new MarketplaceOAuthException('Sandbox OAuth request tidak valid.', 'SANDBOX_REQUEST_INVALID');
        }

        $scenario = $this->scenario((string) $request->query('scenario', 'success'));
        $params = [
            'action' => 'callback',
            'state' => $state,
            'platform' => $platform,
        ];

        if ($scenario === 'cancel') {
            $params['error'] = 'access_denied';
        } else {
            $params['code'] = 'sandbox|'.Str::slug($platform, '-').'|'.$scenario.'|'.Str::lower(Str::random(12));
        }

        $separator = str_contains($redirectUri, '?') ? '&' : '?';

        return redirect()->to($redirectUri.$separator.http_build_query($params), 303);
    }

    private function scenario(string $scenario): string
    {
        $allowed = [
            'success', 'cancel', 'multi_store', 'foreign_store', 'exchange_fail', 'refresh_fail',
            'timeout', 'timeout_once', 'rate_limit', 'rate_limit_once',
        ];

        return in_array($scenario, $allowed, true) ? $scenario : 'success';
    }
}
