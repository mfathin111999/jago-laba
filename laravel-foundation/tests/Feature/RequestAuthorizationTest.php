<?php

namespace Tests\Feature;

use App\Auth\AuthenticatedUser;
use App\Http\Requests\MarketplaceExpenseRequest;
use App\Http\Requests\MarketplaceHppBatchRequest;
use App\Http\Requests\MarketplaceStockRequest;
use App\Http\Requests\MarketplaceSyncRequest;
use Illuminate\Http\Request;
use Tests\TestCase;

/** A write request refuses a role that lacks the ability even if a route forgot the `can:` middleware. */
final class RequestAuthorizationTest extends TestCase
{
    private function authorized(string $class, string $role): bool
    {
        $user = new AuthenticatedUser(['id' => 'u', 'username' => 'u', 'role_id' => $role]);
        $request = $class::createFromBase(Request::create('/x', 'POST'));
        $request->setUserResolver(fn () => $user);

        return $request->authorize();
    }

    public function test_each_write_request_follows_its_ability(): void
    {
        $this->assertTrue($this->authorized(MarketplaceExpenseRequest::class, 'ROLE-KEUANGAN'));
        $this->assertFalse($this->authorized(MarketplaceExpenseRequest::class, 'ROLE-PEMBACA'));

        $this->assertTrue($this->authorized(MarketplaceHppBatchRequest::class, 'ROLE-KEUANGAN'));
        $this->assertFalse($this->authorized(MarketplaceHppBatchRequest::class, 'ROLE-OPERASIONAL'));

        $this->assertTrue($this->authorized(MarketplaceSyncRequest::class, 'ROLE-OPERASIONAL'));
        $this->assertFalse($this->authorized(MarketplaceSyncRequest::class, 'ROLE-PEMBACA'));
    }

    public function test_reading_needs_report_view_and_an_unknown_role_has_nothing(): void
    {
        $this->assertTrue($this->authorized(MarketplaceStockRequest::class, 'ROLE-PEMBACA'));
        $this->assertFalse($this->authorized(MarketplaceStockRequest::class, 'ROLE-UNKNOWN'));
        $this->assertTrue($this->authorized(MarketplaceExpenseRequest::class, 'ROLE-OWNER_SUPER_ADMIN'));
    }

    public function test_a_guest_is_never_authorized(): void
    {
        $request = MarketplaceExpenseRequest::createFromBase(Request::create('/x', 'POST'));
        $request->setUserResolver(fn () => null);

        $this->assertFalse($request->authorize());
    }
}
