<?php

namespace App\Providers;

use App\Auth\LegacyUserProvider;
use App\Pilots\Konveksi\KonveksiConnection;
use App\Pilots\Marketplace\MarketplaceBrandingService;
use App\Policies\RolePolicy;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Throwable;

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

        // The company logo for the Marketplace sidebar; resolved lazily and never allowed to break a page.
        Inertia::share('marketplaceBrand', static function (): ?array {
            $companyKey = (string) request()->attributes->get('company_key', '');
            if ($companyKey === '') {
                return null;
            }
            try {
                $brand = app(MarketplaceBrandingService::class)->read($companyKey);
            } catch (Throwable) {
                return null;
            }

            return [
                'has_logo' => ! empty($brand['logo_mime']),
                'logo_size' => (int) $brand['logo_size'],
                'logo_fit' => (string) $brand['logo_fit'],
                'version' => (string) ($brand['logo_hash'] ?? ''),
            ];
        });

        // Shown in the sidebar footer, like the legacy "Pengguna Jago Laba" card.
        Inertia::share('authUserName', static function (): ?string {
            $user = Auth::guard('web')->user();
            $name = $user === null ? '' : (string) ($user->name ?? $user->username ?? '');

            return $name !== '' ? $name : null;
        });

        Gate::define('role-action', function ($user, string $action): bool {
            return app(RolePolicy::class)->allows($user, $action);
        });
    }
}
