<?php

namespace Tests\Feature;

use App\Auth\LegacyUser;
use App\Authorization\RequestContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class RequestContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'auth', 'company.context'])->get('/_test/company', fn (Request $request) => response()->json(['company' => $request->companyKey(), 'context' => app(RequestContext::class)->companyKey()]));
        Route::middleware(['web'])->get('/_test/no-company', fn (Request $request) => response()->json(['company' => $request->companyKey(), 'context' => app(RequestContext::class)->companyKey()]));
    }

    private function as(string $company): void
    {
        $this->actingAs(new LegacyUser(['id' => 'u-'.$company, 'username' => 'u', 'role_id' => 'ROLE-OWNER_SUPER_ADMIN', 'company_key' => $company]), 'web');
    }

    public function test_the_company_context_middleware_fills_the_request_context_and_the_macro(): void
    {
        $this->as('A');

        $this->getJson('/_test/company')->assertOk()->assertJson(['company' => 'A', 'context' => 'A']);
    }

    public function test_the_context_does_not_leak_into_the_next_request(): void
    {
        $this->as('A');
        $this->getJson('/_test/company')->assertJson(['company' => 'A']);

        // A route without the company middleware must not see company A from the previous request.
        $this->getJson('/_test/no-company')->assertOk()->assertJson(['company' => '', 'context' => null]);

        $this->as('B');
        $this->getJson('/_test/company')->assertJson(['company' => 'B', 'context' => 'B']);
    }
}
