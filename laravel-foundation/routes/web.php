<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\FoundationController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\KonveksiPilotController;
use App\Http\Controllers\LegacyMarketplaceAjaxController;
use App\Http\Controllers\LegacyPilotPageController;
use App\Http\Controllers\LegacyRpcController;
use App\Http\Controllers\MarketplaceAnalyticsController;
use App\Http\Controllers\MarketplaceCatalogController;
use App\Http\Controllers\MarketplaceExpenseController;
use App\Http\Controllers\MarketplaceExpenseDeleteController;
use App\Http\Controllers\MarketplaceExpensesReadController;
use App\Http\Controllers\MarketplaceFulfillmentController;
use App\Http\Controllers\MarketplaceHppWriteController;
use App\Http\Controllers\MarketplaceOAuthController;
use App\Http\Controllers\MarketplaceOrderController;
use App\Http\Controllers\MarketplacePilotController;
use App\Http\Controllers\MarketplacePriceController;
use App\Http\Controllers\MarketplaceStoreDeletionController;
use App\Http\Controllers\MarketplaceStoreWriteController;
use App\Http\Controllers\MarketplaceSyncApiController;
use App\Http\Controllers\MarketplaceSyncController;
use App\Http\Controllers\MarketplaceWithdrawalLegacyController;
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
    Route::get('/marketplace/expenses', MarketplaceExpensesReadController::class)
        ->middleware(['workspace.context:marketplace', 'role.action:report.view'])->name('marketplace.expenses.read');
    Route::get('/marketplace/catalog', MarketplaceCatalogController::class)
        ->middleware(['workspace.context:marketplace', 'role.action:report.view'])->name('marketplace.catalog');
    Route::get('/pilot/marketplace', MarketplacePilotController::class)
        ->middleware('workspace.context:marketplace')
        ->name('pilot.marketplace');
    Route::get('/pilot/marketplace/analytics', MarketplaceAnalyticsController::class)
        ->middleware('workspace.context:marketplace')
        ->name('pilot.marketplace.analytics');
    Route::get('/marketplace/analytics/export', MarketplaceAnalyticsController::class)
        ->middleware(['workspace.context:marketplace', 'role.action:report.view'])->name('marketplace.analytics.export');
    Route::get('/pilot/marketplace/orders', [MarketplaceOrderController::class, 'index'])
        ->middleware('workspace.context:marketplace')
        ->name('pilot.marketplace.orders');
    Route::get('/pilot/marketplace/orders/{order}/detail', [MarketplaceOrderController::class, 'detail'])
        ->middleware('workspace.context:marketplace')
        ->name('pilot.marketplace.orders.detail');
    Route::get('/pilot/konveksi', KonveksiPilotController::class)
        ->middleware('workspace.context:konveksi')
        ->name('pilot.konveksi');
    Route::post('/pilot/marketplace/expenses', MarketplaceExpenseController::class)
        ->middleware(['workspace.context:marketplace', 'role.action:expense.manage', 'store.context:store_id', 'browser.csrf'])
        ->name('pilot.marketplace.expenses');
    Route::delete('/pilot/marketplace/expenses/{id}', MarketplaceExpenseDeleteController::class)
        ->middleware(['workspace.context:marketplace', 'role.action:expense.manage', 'store.context:store_id', 'browser.csrf'])
        ->name('pilot.marketplace.expenses.delete');
    Route::post('/pilot/marketplace/stores', [MarketplaceStoreWriteController::class, 'create'])
        ->middleware(['workspace.context:marketplace', 'role.action:integration.manage', 'browser.csrf'])
        ->name('pilot.marketplace.stores.create');
    Route::put('/pilot/marketplace/stores/{store}', [MarketplaceStoreWriteController::class, 'update'])
        ->middleware(['workspace.context:marketplace', 'role.action:integration.manage', 'store.context:store', 'browser.csrf'])
        ->name('pilot.marketplace.stores.update');
    Route::patch('/pilot/marketplace/stores/{store}/status', [MarketplaceStoreWriteController::class, 'toggleStatus'])
        ->middleware(['workspace.context:marketplace', 'role.action:integration.manage', 'store.context:store', 'browser.csrf'])
        ->name('pilot.marketplace.stores.toggle');
    Route::post('/pilot/marketplace/stores/{store}/archive', [MarketplaceStoreWriteController::class, 'archive'])
        ->middleware(['workspace.context:marketplace', 'role.action:integration.manage', 'store.context:store', 'browser.csrf'])
        ->name('pilot.marketplace.stores.archive');
    Route::post('/pilot/marketplace/stores/{store}/restore', [MarketplaceStoreWriteController::class, 'restore'])
        ->middleware(['workspace.context:marketplace', 'role.action:integration.manage', 'browser.csrf'])
        ->name('pilot.marketplace.stores.restore');
    Route::post('/pilot/marketplace/hpp/sku', [MarketplaceHppWriteController::class, 'updateSku'])
        ->middleware(['workspace.context:marketplace', 'role.action:hpp.edit', 'store.context:store_id', 'browser.csrf'])
        ->name('pilot.marketplace.hpp.sku');
    Route::post('/pilot/marketplace/hpp/global', [MarketplaceHppWriteController::class, 'updateGlobal'])
        ->middleware(['workspace.context:marketplace', 'role.action:hpp.edit', 'store.context:store_id', 'browser.csrf'])
        ->name('pilot.marketplace.hpp.global');
    Route::post('/pilot/marketplace/hpp/batch', [MarketplaceHppWriteController::class, 'saveBatch'])
        ->middleware(['workspace.context:marketplace', 'role.action:hpp.edit', 'browser.csrf'])
        ->name('pilot.marketplace.hpp.batch');
    Route::post('/pilot/marketplace/sync', MarketplaceSyncController::class)
        ->middleware(['workspace.context:marketplace', 'role.action:sync.trigger', 'store.context:store_id', 'browser.csrf'])
        ->name('pilot.marketplace.sync');
    Route::post('/pilot/marketplace/withdrawals/preview', MarketplaceWithdrawalPreviewController::class)
        ->middleware(['workspace.context:marketplace', 'role.action:withdrawal.manage', 'store.context:store_id', 'browser.csrf'])
        ->name('pilot.marketplace.withdrawals.preview');
    Route::get('/pilot/marketplace/oauth/status', MarketplaceOAuthController::class)
        ->defaults('oauth_action', 'status')
        ->middleware('workspace.context:marketplace')
        ->name('pilot.marketplace.oauth.status');
    Route::post('/pilot/marketplace/oauth/start', MarketplaceOAuthController::class)
        ->defaults('oauth_action', 'start')
        ->middleware(['workspace.context:marketplace', 'browser.csrf'])
        ->name('pilot.marketplace.oauth.start');
    Route::get('/pilot/marketplace/oauth/callback', MarketplaceOAuthController::class)
        ->defaults('oauth_action', 'callback')
        ->middleware('workspace.context:marketplace')
        ->name('pilot.marketplace.oauth.callback');
    Route::get('/pilot/marketplace/oauth/pending', MarketplaceOAuthController::class)
        ->defaults('oauth_action', 'pending')
        ->middleware('workspace.context:marketplace')
        ->name('pilot.marketplace.oauth.pending');
    Route::post('/pilot/marketplace/oauth/select', MarketplaceOAuthController::class)
        ->defaults('oauth_action', 'select')
        ->middleware(['workspace.context:marketplace', 'browser.csrf'])
        ->name('pilot.marketplace.oauth.select');
    Route::post('/pilot/marketplace/oauth/refresh', MarketplaceOAuthController::class)
        ->defaults('oauth_action', 'refresh')
        ->middleware(['workspace.context:marketplace', 'browser.csrf'])
        ->name('pilot.marketplace.oauth.refresh');
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

// The two dashboard entry points now forward only their read-only pilot tab.
Route::middleware('legacy.session')->group(function (): void {
    Route::middleware(['auth', 'company.context', 'workspace.context:konveksi'])
        ->get('/index.php', [LegacyPilotPageController::class, 'konveksi'])
        ->name('legacy.konveksi.page');
    Route::middleware(['auth', 'company.context', 'workspace.context:marketplace'])
        ->get('/marketplace.php', [LegacyPilotPageController::class, 'marketplace'])
        ->name('legacy.marketplace.page');

    Route::get('/workspace.php', FoundationController::class)->middleware('auth')->name('legacy.workspace.page');

    // Login remains available under the old RPC contract. Other methods stay
    // reserved until their business adapter is migrated.
    Route::post('/api/rpc.php', LegacyRpcController::class)->name('legacy.rpc');
    Route::post('/api/marketplace-ajax.php', LegacyMarketplaceAjaxController::class)
        ->middleware(['legacy.marketplace.ajax', 'auth', 'company.context', 'workspace.context:marketplace', 'browser.csrf'])
        ->name('legacy.marketplace.ajax');
    Route::post('/api/marketplace-fulfillment.php', [MarketplaceFulfillmentController::class, 'handle'])
        ->middleware(['auth', 'company.context', 'workspace.context:marketplace', 'role.action:shipping.fulfill', 'browser.csrf'])
        ->name('legacy.marketplace.fulfillment');
    Route::post('/api/marketplace-store-deletion.php', MarketplaceStoreDeletionController::class)
        ->middleware(['auth', 'company.context', 'workspace.context:marketplace', 'role.action:store.delete', 'browser.csrf'])
        ->name('legacy.marketplace.store-deletion');
    Route::match(['GET', 'POST'], '/api/marketplace-sync.php', MarketplaceSyncApiController::class)
        ->middleware(['auth', 'company.context', 'workspace.context:marketplace', 'role.action:integration.manage'])
        ->name('legacy.marketplace.sync');
    Route::match(['GET', 'POST'], '/api/marketplace-withdrawals.php', MarketplaceWithdrawalLegacyController::class)
        ->middleware(['auth', 'company.context', 'workspace.context:marketplace', 'role.action:withdrawal.manage'])
        ->name('legacy.marketplace.withdrawals');
    Route::post('/api/marketplace-price.php', MarketplacePriceController::class)
        ->middleware(['auth', 'company.context', 'workspace.context:marketplace', 'role.action:price.manage'])
        ->name('legacy.marketplace.price');
    Route::post('/api/workspace.php', [WorkspaceController::class, 'select'])
        ->middleware('auth')
        ->name('legacy.workspace.select');
    Route::post('/api/logout.php', [AuthController::class, 'logout'])
        ->middleware('auth')
        ->name('legacy.logout');
    Route::match(['GET', 'POST'], '/api/marketplace-oauth.php', MarketplaceOAuthController::class)
        ->name('legacy.marketplace.oauth');
});

Route::get('/__sandbox/marketplace/oauth/authorize', SandboxMarketplaceOAuthController::class)
    ->name('sandbox.marketplace.oauth.authorize');
