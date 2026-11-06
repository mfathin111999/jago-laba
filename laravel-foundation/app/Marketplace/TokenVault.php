<?php

namespace App\Marketplace;

use Illuminate\Support\Facades\Crypt;

/**
 * Marketplace access/refresh tokens and partner secrets are stored encrypted with Laravel's encrypter (APP_KEY; old keys
 * stay readable through APP_PREVIOUS_KEYS when the key is rotated). Plain text never reaches the database.
 */
final class TokenVault
{
    public function configured(): bool
    {
        return (string) config('app.key') !== '';
    }

    public function encrypt(string $plaintext): string
    {
        return Crypt::encryptString($plaintext);
    }

    public function decrypt(string $stored): string
    {
        return $stored === '' ? '' : Crypt::decryptString($stored);
    }
}
