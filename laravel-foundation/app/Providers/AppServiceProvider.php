<?php

namespace App\Providers;

use App\Auth\LegacyUserProvider;
use App\Authorization\RequestContext;
use App\Services\Konveksi\KonveksiConnection;
use App\Services\Marketplace\MarketplaceBrandingService;
use App\Policies\RolePolicy;
use App\Http\Responses\ApiResponse;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
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

        // Jobs that were queued before the move to App\Jobs are stored with the old class name; keep them runnable.
        foreach (['MarketplaceSyncJob', 'MarketplaceLiveSyncJob'] as $job) {
            if (! class_exists('App\\Pilots\\Marketplace\\'.$job, false)) {
                class_alias('App\\Jobs\\'.$job, 'App\\Pilots\\Marketplace\\'.$job);
            }
        }
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

        // Limits for actions that are expensive or call a provider; per signed-in user (IP when anonymous).
        // Login has its own limiter (LegacyAuthenticationService). Callbacks from a provider are never limited.
        foreach (['imports' => [10, 'Terlalu banyak impor dalam satu menit.'], 'oauth' => [20, 'Terlalu banyak permintaan koneksi toko dalam satu menit.']] as $name => [$perMinute, $message]) {
            RateLimiter::for($name, static fn (Request $request): Limit => Limit::perMinute($perMinute)
                ->by($name.'|'.($request->user('web')?->getAuthIdentifier() ?: $request->ip()))
                ->response(static fn (Request $request, array $headers) => ApiResponse::error($message.' Coba lagi sebentar lagi.', 'RATE_LIMITED', 429)->withHeaders($headers)));
        }

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
