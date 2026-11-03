<?php

namespace App\Marketplace;

use App\Auth\LegacyUser;
use Illuminate\Database\DatabaseManager;

final class LegacyExecutionContext
{
    public function __construct(private readonly DatabaseManager $database) {}

    public function run(LegacyUser $user, string $companyKey, callable $callback): mixed
    {
        if (trim($companyKey) === '') {
            throw new \DomainException('Company context wajib tersedia.');
        }

        $previous = $_SESSION ?? [];
        $_SESSION = [
            'user_id' => $user->getAuthIdentifier(),
            'username' => $user->getUsername(),
            'role' => $user->getRole() ?: 'ROLE-OWNER_SUPER_ADMIN',
            'company_key' => $companyKey,
        ];

        try {
            return $callback($this->database->connection()->getPdo());
        } finally {
            $_SESSION = $previous;
        }
    }
}
