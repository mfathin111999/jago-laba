<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\URL;
use Tests\TestCase;

final class HostingContractTest extends TestCase
{
    public function test_laravel_callback_is_public_root_relative_without_public_segment(): void
    {
        config([
            'app.url' => 'https://staging.example.test',
            'jago.oauth.return_path' => 'marketplace.php?scope=global&tab=integrasi',
        ]);
        URL::forceRootUrl('https://staging.example.test');
        URL::forceScheme('https');

        $callback = route('legacy.marketplace.oauth', ['action' => 'callback']);
        $laravelCallback = route('pilot.marketplace.oauth.callback');

        $this->assertSame('https://staging.example.test/api/marketplace-oauth.php?action=callback', $callback);
        $this->assertSame('https://staging.example.test/pilot/marketplace/oauth/callback', $laravelCallback);
        $this->assertStringNotContainsString('/public/', $callback);
        $this->assertStringNotContainsString('/public/', $laravelCallback);
    }

}
