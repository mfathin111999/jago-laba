<?php

use App\Exceptions\ApiException;
use App\Exceptions\IdempotencyConflict;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureBrowserCsrf;
use App\Http\Middleware\EnsureCompanyContext;
use App\Http\Middleware\EnsureLegacyMarketplaceAjaxContract;
use App\Http\Middleware\EnsureLegacyMarketplaceExpenseAction;
use App\Http\Middleware\EnsureRoleAction;
use App\Http\Middleware\EnsureStoreContext;
use App\Http\Middleware\EnsureWorkspace;
use App\Http\Middleware\LegacySessionBridge;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Inertia\Middleware as InertiaMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([__DIR__.'/../app/Console/Commands'])
    ->withMiddleware(function (Middleware $middleware): void {
        $allowedHosts = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('JL_ALLOWED_HOSTS', '')),
        )));
        if ($allowedHosts !== []) {
            $middleware->trustHosts(array_map(
                static fn (string $host): string => '^'.preg_quote($host, '/').'$',
                $allowedHosts,
            ), false);
        }

        $trustedProxies = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('JL_TRUSTED_PROXIES', '')),
        )));
        $middleware->trustProxies($trustedProxies === [] ? null : $trustedProxies);

        $middleware->redirectGuestsTo(fn (): string => route('foundation'));
        $middleware->alias([
            'legacy.session' => LegacySessionBridge::class,
            'company.context' => EnsureCompanyContext::class,
            'store.context' => EnsureStoreContext::class,
            'workspace.context' => EnsureWorkspace::class,
            'role.action' => EnsureRoleAction::class,
            'legacy.marketplace.ajax' => EnsureLegacyMarketplaceAjaxContract::class,
            'legacy.marketplace.expense' => EnsureLegacyMarketplaceExpenseAction::class,
            'browser.csrf' => EnsureBrowserCsrf::class,
        ]);
        $middleware->web(prepend: [AssignRequestId::class], append: [LegacySessionBridge::class, InertiaMiddleware::class]);
        $middleware->preventRequestForgery(except: ['api/rpc.php']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Typed refusals answer the client through their own render(); they are not server errors worth logging.
        $exceptions->dontReport([ApiException::class, IdempotencyConflict::class]);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
