<?php

namespace Tests\Feature;

use App\Auth\LegacyUser;
use App\Services\Marketplace\MarketplaceSettlementAndClaimService;
use InvalidArgumentException;
use Tests\TestCase;

final class MarketplaceClaimStatusTest extends TestCase
{
    public function test_an_unknown_claim_status_is_refused_before_touching_the_database(): void
    {
        $user = new LegacyUser(['id' => 'u1', 'username' => 'u1', 'role_id' => 'ROLE-OWNER_SUPER_ADMIN', 'company_key' => 'A']);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Status klaim tidak dikenal.');

        app(MarketplaceSettlementAndClaimService::class)->updateReturnClaimStatus($user, 'A', 1, 'store-a', 'ASAL-ASALAN');
    }
}
