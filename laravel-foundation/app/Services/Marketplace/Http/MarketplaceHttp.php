<?php

namespace App\Services\Marketplace\Http;

use App\Exceptions\MarketplaceIntegrationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;

/**
 * The single door for outgoing marketplace calls. Rules that protect tokens and keep errors safe:
 *  - the host is checked before anything is sent (MarketplaceApiOrigin), redirects are never followed;
 *  - connect timeout 8 s, total 25 s, no retry here (callers decide, see IntegrationPolicy::retry());
 *  - a 2xx answer must be a JSON object/array, anything else is INVALID_PLATFORM_RESPONSE;
 *  - a failure exposes machine identifiers only (error, code, request_id), never provider messages or signed URLs.
 */
final class MarketplaceHttp
{
    private const CONNECT_TIMEOUT = 8;

    private const TIMEOUT = 25;

    /**
     * @param  array<int|string, string>  $headers  ['Name' => 'value'] or lines like 'Name: value'
     * @param  array<string, mixed>|null  $jsonBody
     * @return array<string, mixed>
     */
    public function request(string $method, string $url, array $headers = [], ?array $jsonBody = null): array
    {
        MarketplaceApiOrigin::assertUrl($url);

        $request = Http::acceptJson()
            ->withHeaders($this->headers($headers, $jsonBody !== null))
            ->connectTimeout(self::CONNECT_TIMEOUT)
            ->timeout(self::TIMEOUT)
            ->withoutRedirecting();

        try {
            $response = $request->send(strtoupper($method), $url, $jsonBody !== null ? ['json' => $jsonBody] : []);
        } catch (ConnectionException) {
            throw new MarketplaceIntegrationException('Platform tidak dapat dihubungi.', 'HTTP_TRANSPORT_ERROR');
        }

        if (! $response->successful()) {
            throw $this->failure($response);
        }

        try {
            $decoded = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new MarketplaceIntegrationException('Respons platform tidak lengkap.', 'INVALID_PLATFORM_RESPONSE');
        }
        if (! is_array($decoded)) {
            throw new MarketplaceIntegrationException('Respons platform tidak lengkap.', 'INVALID_PLATFORM_RESPONSE');
        }

        return $decoded;
    }

    /**
     * @param  array<int|string, string>  $headers
     * @return array<string, string>
     */
    private function headers(array $headers, bool $hasBody): array
    {
        $named = [];
        foreach ($headers as $key => $value) {
            if (is_int($key)) {
                [$key, $value] = array_pad(explode(':', (string) $value, 2), 2, '');
            }
            $key = trim((string) $key);
            // A body is always sent as application/json once: a duplicate Content-Type may be combined and rejected as HTTP 415.
            if ($key === '' || ($hasBody && strcasecmp($key, 'Content-Type') === 0)) {
                continue;
            }
            $named[$key] = trim((string) $value);
        }

        return $named;
    }

    private function failure(Response $response): MarketplaceIntegrationException
    {
        $status = $response->status();
        $failure = json_decode($response->body(), true);
        $details = [];
        if (is_array($failure)) {
            foreach (['error' => 'Error', 'code' => 'Code', 'request_id' => 'Request ID'] as $field => $label) {
                $value = $failure[$field] ?? null;
                if (is_scalar($value) && preg_match('/^[a-zA-Z0-9_.:-]{1,100}$/', (string) $value)) {
                    $details[] = $label.': '.$value;
                }
            }
        }
        // Only machine identifiers are exposed. Messages/bodies can contain credentials or signed URLs.
        $detail = $details !== [] ? ' '.implode('; ', $details).'.' : ' Respons tidak menyertakan kode error API yang dapat ditampilkan.';
        $hint = match (true) {
            $status === 401, $status === 403 => 'Periksa pasangan ID/key, lingkungan Sandbox/Live, izin endpoint, dan pembatasan IP pada platform.',
            $status === 404 => 'Periksa alamat host dan endpoint untuk lingkungan yang dipilih.',
            $status === 429 => 'Batas permintaan platform tercapai; coba lagi setelah beberapa saat.',
            $status >= 500 => 'Layanan platform bermasalah; coba lagi setelah beberapa saat.',
            default => 'Periksa konfigurasi permintaan dan akses aplikasi.',
        };
        if (is_array($failure) && (string) ($failure['code'] ?? '') === '36009004') {
            $reason = strtolower((string) ($failure['message'] ?? ''));
            if (str_contains($reason, 'version')) {
                $hint = 'Versi API pada endpoint tidak valid. Periksa path endpoint promo.';
            } elseif (str_contains($reason, 'timestamp')) {
                $hint = 'Timestamp permintaan tidak valid. Periksa waktu server dan zona waktu.';
            } elseif (str_contains($reason, 'param')) {
                $hint = 'Parameter permintaan tidak valid. Periksa format data promo.';
            }
        }

        return new MarketplaceIntegrationException(
            "Platform menolak permintaan koneksi (HTTP $status).$detail $hint",
            'HTTP_'.$status,
            $this->retryAfter($response),
        );
    }

    /** Retry-After as seconds (a number or an HTTP date), at most one day. */
    private function retryAfter(Response $response): ?int
    {
        $value = trim((string) $response->header('Retry-After'));
        if ($value === '') {
            return null;
        }
        $seconds = ctype_digit($value) ? (int) $value : max(0, (strtotime($value) ?: time()) - time());

        return min(86400, max(0, $seconds));
    }
}
