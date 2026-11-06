<?php

namespace App\Services\Marketplace\Http\Signing;

/**
 * TikTok Shop Open API signature: HMAC-SHA256, keyed by the app secret, over
 * secret + path + the query as sorted key/value pairs + the JSON body + secret.
 * `sign` and `access_token` never take part. The authorization calls skip empty values; the data calls sign them as they are.
 */
final class TikTokSigner
{
    /** @param array<string, mixed> $query */
    public static function sign(string $secret, string $path, array $query, string $body = '', bool $skipEmpty = true): string
    {
        unset($query['sign'], $query['access_token']);
        ksort($query);
        $input = $path;
        foreach ($query as $key => $value) {
            if ($skipEmpty && ($value === null || $value === '')) {
                continue;
            }
            $input .= $key.(string) $value;
        }

        return hash_hmac('sha256', $secret.$input.$body.$secret, $secret);
    }
}
