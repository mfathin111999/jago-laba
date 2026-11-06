<?php

namespace Tests\Unit;

use App\Auth\LegacyUser;
use App\Policies\RolePolicy;
use PHPUnit\Framework\TestCase;

/** Role x action decisions captured from the retired legacy policy (fixtures/role-policy-matrix.json). */
final class RolePolicyParityTest extends TestCase
{
    public function test_every_role_and_action_matches_the_captured_legacy_decisions(): void
    {
        $matrix = json_decode((string) file_get_contents(__DIR__.'/fixtures/role-policy-matrix.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertNotEmpty($matrix);
        foreach ($matrix as $role => $actions) {
            $user = new LegacyUser(['role_id' => $role]);
            foreach ($actions as $action => $expected) {
                $this->assertSame($expected, (new RolePolicy)->allows($user, (string) $action), $role.':'.$action);
            }
        }
    }
}
