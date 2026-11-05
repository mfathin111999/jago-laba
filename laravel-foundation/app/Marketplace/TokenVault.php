<?php

namespace App\Marketplace;

use Illuminate\Support\Facades\Crypt;
use RuntimeException;
use Throwable;

/**
 * Marketplace access/refresh tokens are stored in the legacy vault format (libsodium secretbox, key from
 * JL_MARKETPLACE_TOKEN_ENCRYPTION_KEY or its key file), so the legacy sync engine and the legacy application can read
 * what Laravel writes and the other way round. Without that key the sandbox falls back to Laravel's own encryption,
 * but a live connection refuses to store tokens that the rest of the system could not read.
 */
final class TokenVault
{
    public function configured(): bool
    {
        $this->loadLegacyVault();

        return \MarketplaceTokenVault::isConfigured();
    }

    public function encrypt(string $plaintext): string
    {
        if ($this->configured()) {
            return \MarketplaceTokenVault::encrypt($plaintext);
        }
        if (strtolower((string) config('jago.oauth.mode', 'sandbox')) === 'live') {
            throw new RuntimeException('JL_MARKETPLACE_TOKEN_ENCRYPTION_KEY belum diisi; koneksi live tidak boleh menyimpan token tanpa kunci.');
        }

        return Crypt::encryptString($plaintext);
    }

    public function decrypt(string $stored): string
    {
        if ($stored === '') {
            return '';
        }
        if ($this->configured()) {
            try {
                return \MarketplaceTokenVault::decrypt($stored);
            } catch (Throwable) {
                // Tokens written before the key was configured used Laravel's encryption.
            }
        }

        return Crypt::decryptString($stored);
    }

    private function loadLegacyVault(): void
    {
        if (! class_exists(\MarketplaceTokenVault::class, false)) {
            LegacyRuntime::shared('Security/MarketplaceTokenVault');
        }
        // The legacy vault reads the process environment; the values come from config (env() is unusable once the config is cached).
        $legacy = (array) config('jago.legacy_env', []);
        LegacyRuntime::exportToEnvironment(array_intersect_key($legacy, array_flip(['JL_MARKETPLACE_TOKEN_ENCRYPTION_KEY', 'JL_MARKETPLACE_TOKEN_ENCRYPTION_KEY_FILE'])));
    }
}
