<?php

namespace App\Providers;

use App\Auth\LegacyUserProvider;
use App\Authorization\RequestContext;
use App\Pilots\Konveksi\KonveksiConnection;
use App\Pilots\Marketplace\MarketplaceBrandingService;
use App\Policies\RolePolicy;
use Illuminate\Http\Request;
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
        // One instance per request (and per queued job): the company/store/workspace the context middleware decided.
        $this->app->scoped(RequestContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // $request->companyKey(): the active company set by EnsureCompanyContext ('' when the route has no company context).
        Request::macro('companyKey', function (): string {
            /** @var Request $this */
            return (string) (app(RequestContext::class)->companyKey() ?? $this->attributes->get('company_key', ''));
        });

        Auth::provider('legacy', function ($app, array $config): LegacyUserProvider {
            return new LegacyUserProvider($app['db'], $config['connection'] ?? null);
        });

        // The company logo for the Marketplace sidebar; resolved lazily and never allowed to break a page.
        Inertia::share('marketplaceBrand', static function (): ?array {
            $companyKey = request()->companyKey();
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

        // One ability per role action (hpp.edit, report.view, ...): routes use `can:hpp.edit`, code uses $user->can(...).
        foreach (RolePolicy::abilities() as $action) {
            Gate::define($action, static fn ($user): bool => app(RolePolicy::class)->allows($user, $action));
        }
    }
}
