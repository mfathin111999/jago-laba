<?php

namespace App\Providers;

use App\Auth\LegacyUserProvider;
use App\Pilots\Konveksi\KonveksiConnection;
use App\Policies\RolePolicy;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(KonveksiConnection::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Auth::provider('legacy', function ($app, array $config): LegacyUserProvider {
            return new LegacyUserProvider($app['db'], $config['connection'] ?? null);
        });

        Gate::define('role-action', function ($user, string $action): bool {
            return app(RolePolicy::class)->allows($user, $action);
        });
    }
}
