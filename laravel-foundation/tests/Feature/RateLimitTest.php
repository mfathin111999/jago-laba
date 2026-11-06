<?php

namespace Tests\Feature;

use App\Auth\AuthenticatedUser;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class RateLimitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', 'throttle:imports'])->get('/_test/imports', fn () => response()->json(['ok' => true]));
        Route::middleware(['web', 'throttle:oauth'])->get('/_test/oauth', fn () => response()->json(['ok' => true]));
    }

    public function test_imports_are_limited_to_ten_a_minute_with_the_project_error_shape(): void
    {
        $this->actingAs(new AuthenticatedUser(['auth_identifier' => 'u1', 'id' => 'u1', 'username' => 'u1', 'role_id' => 'ROLE-OWNER_SUPER_ADMIN', 'company_key' => 'A']), 'web');

        for ($i = 0; $i < 10; $i++) {
            $this->getJson('/_test/imports')->assertOk();
        }

        $this->getJson('/_test/imports')
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'RATE_LIMITED');
    }

    public function test_the_limit_is_per_user_not_global(): void
    {
        $this->actingAs(new AuthenticatedUser(['auth_identifier' => 'u1', 'id' => 'u1', 'username' => 'u1', 'role_id' => 'ROLE-OWNER_SUPER_ADMIN']), 'web');
        for ($i = 0; $i < 11; $i++) {
            $this->getJson('/_test/imports');
        }
        $this->getJson('/_test/imports')->assertStatus(429);

        $this->actingAs(new AuthenticatedUser(['auth_identifier' => 'u2', 'id' => 'u2', 'username' => 'u2', 'role_id' => 'ROLE-OWNER_SUPER_ADMIN']), 'web');
        $this->getJson('/_test/imports')->assertOk();
    }

    public function test_oauth_allows_twenty_a_minute(): void
    {
        $this->actingAs(new AuthenticatedUser(['auth_identifier' => 'u3', 'id' => 'u3', 'username' => 'u3', 'role_id' => 'ROLE-OWNER_SUPER_ADMIN']), 'web');
        RateLimiter::clear('oauth|u3');

        for ($i = 0; $i < 20; $i++) {
            $this->getJson('/_test/oauth')->assertOk();
        }
        $this->getJson('/_test/oauth')->assertStatus(429)->assertJsonPath('code', 'RATE_LIMITED');
    }
}
