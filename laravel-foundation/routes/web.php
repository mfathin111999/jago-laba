<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DiagnosticsController;
use App\Http\Controllers\FoundationController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\KonveksiDocumentController;
use App\Http\Controllers\KonveksiAppController;
use App\Http\Controllers\KonveksiPilotController;
use App\Http\Controllers\MarketplaceAjaxController;
use App\Http\Controllers\PilotPageController;
use App\Http\Controllers\KonveksiRpcController;
use App\Http\Controllers\MarketplaceAnalyticsController;
use App\Http\Controllers\MarketplaceAutoSyncController;
use App\Http\Controllers\MarketplaceBrandingController;
use App\Http\Controllers\MarketplaceCatalogController;
use App\Http\Controllers\MarketplaceExpenseController;
use App\Http\Controllers\MarketplaceExpenseDeleteController;
use App\Http\Controllers\MarketplaceExpensesReadController;
use App\Http\Controllers\MarketplaceFulfillmentController;
use App\Http\Controllers\MarketplaceHppWriteController;
use App\Http\Controllers\MarketplaceIncomePageController;
use App\Http\Controllers\MarketplaceIntegrationsPageController;
use App\Http\Controllers\MarketplaceIntegrationStatusController;
use App\Http\Controllers\MarketplaceReportUrlController;
use App\Http\Controllers\MarketplaceMarketingPageController;
use App\Http\Controllers\MarketplaceOAuthController;
use App\Http\Controllers\MarketplaceOrderController;
use App\Http\Controllers\MarketplacePilotController;
use App\Http\Controllers\MarketplacePriceController;
use App\Http\Controllers\MarketplaceProviderBoundaryController;
use App\Http\Controllers\MarketplaceShippingDocumentController;
use App\Http\Controllers\MarketplaceStockMinimumController;
use App\Http\Controllers\MarketplaceStockPageController;
use App\Http\Controllers\MarketplaceStoreDeletionController;
use App\Http\Controllers\MarketplaceStoresPageController;
use App\Http\Controllers\MarketplaceStoreWriteController;
use App\Http\Controllers\MarketplaceSyncApiController;
use App\Http\Controllers\MarketplaceSyncController;
use App\Http\Controllers\MarketplaceSyncPageController;
use App\Http\Controllers\MarketplaceWithdrawalApiController;
use App\Http\Controllers\MarketplaceWithdrawalPreviewController;
use App\Http\Controllers\SandboxMarketplaceOAuthController;
use App\Http\Controllers\StoreSelectionController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get('/', FoundationController::class)->name('foundation');
Route::get('/foundation', FoundationController::class)->name('foundation.read');
Route::get('/login', FoundationController::class)->name('login');
Route::get('/api/health.php', HealthController::class)->name('legacy.health');

Route::middleware(['auth', 'company.context'])->group(function (): void {
    Route::middleware('workspace.context:marketplace')->group(function (): void {
        // Read-only pages and exports: the report.view ability.
        Route::middleware('can:report.view')->group(function (): void {
            Route::get('/marketplace/expenses', MarketplaceExpensesReadController::class)->name('marketplace.expenses.read');
            Route::get('/marketplace/catalog', MarketplaceCatalogController::class)->name('marketplace.catalog');
            Route::get('/marketplace/stock', MarketplaceStockPageController::class)->name('marketplace.stock');
            Route::get('/marketplace/income', MarketplaceIncomePageController::class)->name('marketplace.income');
            Route::get('/marketplace/marketing/template', [MarketplaceMarketingPageController::class, 'template'])->name('marketplace.marketing.template');
            Route::get('/marketplace/marketing', MarketplaceMarketingPageController::class)->name('marketplace.marketing');
            Route::get('/marketplace/sync', MarketplaceSyncPageController::class)->name('marketplace.sync');
            Route::get('/marketplace/integrations', MarketplaceIntegrationsPageController::class)->name('marketplace.integrations');
            Route::get('/marketplace/stores', MarketplaceStoresPageController::class)->name('marketplace.stores');
            Route::get('/marketplace/analytics/export', MarketplaceAnalyticsController::class)->name('marketplace.analytics.export');
            Route::get('/pilot/marketplace/orders/export', [MarketplaceOrderController::class, 'export'])->name('pilot.marketplace.orders.export');
            Route::get('/pilot/marketplace/export', [MarketplacePilotController::class, 'export'])->name('pilot.marketplace.export');
        });

        Route::prefix('pilot/marketplace')->group(function (): void {
            Route::get('/', MarketplacePilotController::class)->name('pilot.marketplace');
            Route::get('/analytics', MarketplaceAnalyticsController::class)->name('pilot.marketplace.analytics');
            Route::get('/orders', [MarketplaceOrderController::class, 'index'])->name('pilot.marketplace.orders');
            Route::get('/orders/{order}/detail', [MarketplaceOrderController::class, 'detail'])->name('pilot.marketplace.orders.detail');

            // Writes: CSRF-checked JSON endpoints.
            Route::post('/expenses', MarketplaceExpenseController::class)
                ->middleware(['can:expense.manage', 'store.context:store_id', 'browser.csrf'])
                ->name('pilot.marketplace.expenses');
            Route::delete('/expenses/{id}', MarketplaceExpenseDeleteController::class)
                ->middleware(['can:expense.manage', 'store.context:store_id', 'browser.csrf'])
                ->name('pilot.marketplace.expenses.delete');

            Route::controller(MarketplaceStoreWriteController::class)->group(function (): void {
                Route::post('/stores', 'create')
                    ->middleware(['can:integration.manage', 'browser.csrf'])
                    ->name('pilot.marketplace.stores.create');
                Route::put('/stores/{store}', 'update')
                    ->middleware(['can:integration.manage', 'store.context:store', 'browser.csrf'])
                    ->name('pilot.marketplace.stores.update');
                Route::patch('/stores/{store}/status', 'toggleStatus')
                    ->middleware(['can:integration.manage', 'store.context:store', 'browser.csrf'])
                    ->name('pilot.marketplace.stores.toggle');
                Route::post('/stores/{store}/archive', 'archive')
                    ->middleware(['can:integration.manage', 'store.context:store', 'browser.csrf'])
                    ->name('pilot.marketplace.stores.archive');
                Route::post('/stores/{store}/restore', 'restore')
                    ->middleware(['can:integration.manage', 'browser.csrf'])
                    ->name('pilot.marketplace.stores.restore');
            });

            Route::controller(MarketplaceHppWriteController::class)->middleware('can:hpp.edit')->group(function (): void {
                Route::post('/hpp/sku', 'updateSku')->middleware(['store.context:store_id', 'browser.csrf'])->name('pilot.marketplace.hpp.sku');
                Route::post('/hpp/global', 'updateGlobal')->middleware(['store.context:store_id', 'browser.csrf'])->name('pilot.marketplace.hpp.global');
                Route::post('/hpp/batch', 'saveBatch')->middleware('browser.csrf')->name('pilot.marketplace.hpp.batch');
            });

            Route::post('/sync', MarketplaceSyncController::class)
                ->middleware(['can:sync.trigger', 'store.context:store_id', 'browser.csrf'])
                ->name('pilot.marketplace.sync');
            Route::post('/withdrawals/preview', MarketplaceWithdrawalPreviewController::class)
                ->middleware(['can:withdrawal.manage', 'store.context:store_id', 'browser.csrf', 'throttle:imports'])
                ->name('pilot.marketplace.withdrawals.preview');

            // OAuth: one invokable controller, the action comes from the route default.
            Route::prefix('oauth')->group(function (): void {
                Route::get('/status', MarketplaceOAuthController::class)->defaults('oauth_action', 'status')->name('pilot.marketplace.oauth.status');
                Route::post('/start', MarketplaceOAuthController::class)->defaults('oauth_action', 'start')->middleware(['browser.csrf', 'throttle:oauth'])->name('pilot.marketplace.oauth.start');
                Route::get('/callback', MarketplaceOAuthController::class)->defaults('oauth_action', 'callback')->name('pilot.marketplace.oauth.callback');
                Route::get('/pending', MarketplaceOAuthController::class)->defaults('oauth_action', 'pending')->name('pilot.marketplace.oauth.pending');
                Route::post('/select', MarketplaceOAuthController::class)->defaults('oauth_action', 'select')->middleware(['browser.csrf', 'throttle:oauth'])->name('pilot.marketplace.oauth.select');
                Route::post('/refresh', MarketplaceOAuthController::class)->defaults('oauth_action', 'refresh')->middleware(['browser.csrf', 'throttle:oauth'])->name('pilot.marketplace.oauth.refresh');
            });
        });
    });

    Route::get('/pilot/konveksi', KonveksiPilotController::class)
        ->middleware('workspace.context:konveksi')
        ->name('pilot.konveksi');
});

Route::prefix('auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])->name('auth.login');
    Route::middleware('auth')->group(function (): void {
        Route::get('/me', [AuthController::class, 'me'])->name('auth.me');
        Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
        Route::post('/workspace', [WorkspaceController::class, 'select'])->name('auth.workspace.select');
        Route::post('/store', StoreSelectionController::class)
            ->middleware(['company.context', 'workspace.context:marketplace', 'store.context:store_id', 'browser.csrf'])
            ->name('auth.store.select');
    });
});

// Old entry points and *.php endpoints stay available for the legacy clients (kept until the post-cutover cleanup).
Route::middleware(['auth', 'company.context'])->group(function (): void {
    Route::middleware('workspace.context:konveksi')->group(function (): void {
        Route::get('/konveksi', KonveksiAppController::class)->name('legacy.konveksi.page');
        Route::get('/api/konveksi-document.php', KonveksiDocumentController::class)->name('legacy.konveksi.document');
    });

    Route::middleware('workspace.context:marketplace')->group(function (): void {
        Route::get('/marketplace.php', [PilotPageController::class, 'marketplace'])->name('legacy.marketplace.page');

        Route::post('/api/marketplace-fulfillment.php', [MarketplaceFulfillmentController::class, 'handle'])
            ->middleware(['can:shipping.fulfill', 'browser.csrf'])
            ->name('legacy.marketplace.fulfillment');
        Route::post('/api/marketplace-store-deletion.php', MarketplaceStoreDeletionController::class)
            ->middleware(['can:store.delete', 'browser.csrf'])
            ->name('legacy.marketplace.store-deletion');
        Route::match(['GET', 'POST'], '/api/marketplace-sync.php', MarketplaceSyncApiController::class)
            ->middleware('can:integration.manage')
            ->name('legacy.marketplace.sync');
        Route::match(['GET', 'POST'], '/api/marketplace-withdrawals.php', MarketplaceWithdrawalApiController::class)
            ->middleware(['can:withdrawal.manage', 'throttle:imports'])
            ->name('legacy.marketplace.withdrawals');
        Route::post('/api/marketplace-price.php', MarketplacePriceController::class)
            ->middleware('can:price.manage')
            ->name('legacy.marketplace.price');
        Route::post('/api/marketplace-stock.php', MarketplaceStockMinimumController::class)
            ->middleware('can:settings.manage')
            ->name('legacy.marketplace.stock');
        Route::match(['GET', 'POST'], '/api/marketplace-auto-sync.php', [MarketplaceAutoSyncController::class, 'states'])->name('legacy.marketplace.auto-sync');
        Route::post('/api/marketplace-browser-pulse.php', [MarketplaceAutoSyncController::class, 'pulse'])->name('legacy.marketplace.browser-pulse');
        Route::match(['GET', 'POST'], '/api/marketplace-branding.php', MarketplaceBrandingController::class)->name('legacy.marketplace.branding');

        Route::middleware('can:report.view')->group(function (): void {
            Route::get('/api/marketplace-orders.php', [MarketplaceReportUrlController::class, 'orders'])->name('legacy.marketplace.orders');
            Route::get('/api/marketplace-analytics.php', [MarketplaceReportUrlController::class, 'analytics'])->name('legacy.marketplace.analytics');
            Route::get('/api/marketplace-dashboard.php', [MarketplaceReportUrlController::class, 'dashboard'])->name('legacy.marketplace.dashboard');
        });

        Route::post('/api/marketplace-order-finance.php', [MarketplaceProviderBoundaryController::class, 'orderFinance'])
            ->middleware('can:integration.manage')
            ->name('legacy.marketplace.order-finance');
        Route::post('/api/marketplace-partner-settings.php', [MarketplaceProviderBoundaryController::class, 'partnerSettings'])
            ->middleware('can:integration.manage')
            ->name('legacy.marketplace.partner-settings');
        Route::match(['GET', 'POST'], '/api/marketplace-integration-status.php', MarketplaceIntegrationStatusController::class)->name('legacy.marketplace.integration-status');
        Route::match(['GET', 'POST'], '/api/marketplace-shipping-document.php', MarketplaceShippingDocumentController::class)
            ->middleware('can:shipping.fulfill')
            ->name('legacy.marketplace.shipping-document');
    });
});

// The marketplace AJAX contract guard runs before authentication, so this route keeps its own explicit list.
Route::post('/api/marketplace-ajax.php', MarketplaceAjaxController::class)
    ->middleware(['legacy.marketplace.ajax', 'auth', 'company.context', 'workspace.context:marketplace', 'browser.csrf'])
    ->name('legacy.marketplace.ajax');

// Login stays on the old RPC contract; every other method is gated inside the controller.
Route::post('/api/rpc.php', KonveksiRpcController::class)->name('legacy.rpc');

Route::middleware('auth')->group(function (): void {
    Route::get('/workspace.php', FoundationController::class)->name('legacy.workspace.page');
    Route::post('/api/workspace.php', [WorkspaceController::class, 'select'])->name('legacy.workspace.select');
    Route::post('/api/logout.php', [AuthController::class, 'logout'])->name('legacy.logout');

    Route::middleware('can:store.delete')->group(function (): void {
        Route::get('/api/diagnostics.php', [DiagnosticsController::class, 'health'])->name('legacy.diagnostics');
        Route::get('/api/auth-diagnostic.php', [DiagnosticsController::class, 'auth'])->name('legacy.auth-diagnostic');
    });
});

// The OAuth provider calls back without our session.
Route::match(['GET', 'POST'], '/api/marketplace-oauth.php', MarketplaceOAuthController::class)->name('legacy.marketplace.oauth');

Route::get('/__sandbox/marketplace/oauth/authorize', SandboxMarketplaceOAuthController::class)
    ->name('sandbox.marketplace.oauth.authorize');
