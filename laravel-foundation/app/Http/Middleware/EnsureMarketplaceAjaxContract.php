<?php

namespace App\Http\Middleware;

use App\Authorization\RequestContext;
use App\Authorization\StoreContext;
use App\Http\Responses\UnavailableResponse;
use App\Policies\RolePolicy;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnsureMarketplaceAjaxContract
{
    private const ALLOWED_ACTIONS = [
        'add_expense',
        'delete_expense',
        'add_store',
        'update_store',
        'toggle_store_status',
        'archive_store',
        'restore_store',
        'update_sku_hpp',
        'update_global_product_hpp',
        'save_hpp_batch',
        'trigger_cron_worker',
        'sync_store',
        'add_order',
        'update_manual_order',
        'archive_order',
        'get_journal_entries',
        'simulate_price',
        'get_saved_simulations',
        'get_store_detail',
        'save_app_settings',
        'update_return_claim',
        'import_product_ad_report',
        'import_settlement_csv',
        'test_api_connection',
        'sync_marketing_ads',
    ];

    public function __construct(
        private readonly RolePolicy $policy,
        private readonly StoreContext $store,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $action = trim((string) ($request->input('action') ?: $request->query('action', '')));

        if (! in_array($action, self::ALLOWED_ACTIONS, true)) {
            return UnavailableResponse::unavailable('rpc');
        }

        $user = Auth::guard('web')->user();
        if (! $user instanceof Authenticatable) {
            return response()->json(['message' => 'Autentikasi diperlukan.', 'code' => 'AUTH_REQUIRED'], 401);
        }

        // Action-specific role check
        $requiredRoleAction = match ($action) {
            'add_expense', 'delete_expense' => 'expense.manage',
            'add_store', 'update_store', 'toggle_store_status', 'archive_store', 'restore_store', 'test_api_connection' => 'integration.manage',
            'sync_marketing_ads' => 'sync.trigger',
            'update_sku_hpp', 'update_global_product_hpp', 'save_hpp_batch' => 'hpp.edit',
            'trigger_cron_worker', 'sync_store' => 'sync.trigger',
            'add_order', 'update_manual_order', 'archive_order', 'update_return_claim' => 'orders.manage',
            'get_journal_entries', 'get_saved_simulations' => 'report.view',
            'simulate_price' => 'price.manage',
            'get_store_detail', 'save_app_settings' => 'settings.manage',
            'import_product_ad_report', 'import_settlement_csv' => 'finance.manage',
        };

        if (! $this->policy->allows($user, $requiredRoleAction)) {
            return response()->json(['message' => 'Aksi tidak diizinkan.', 'code' => 'ROLE_FORBIDDEN'], 403);
        }

        // Store context check for actions targeting a specific store
        if (in_array($action, ['add_expense', 'delete_expense', 'update_store', 'toggle_store_status', 'archive_store', 'restore_store', 'update_sku_hpp', 'update_global_product_hpp', 'trigger_cron_worker', 'sync_store', 'add_order', 'update_manual_order', 'archive_order', 'get_journal_entries', 'get_store_detail', 'update_return_claim', 'import_product_ad_report', 'import_settlement_csv', 'test_api_connection', 'sync_marketing_ads'], true)) {
            $value = $request->input('store_id') ?? $request->query('store_id') ?? $request->header('X-Store-Id');
            $storeId = is_scalar($value) ? trim((string) $value) : '';

            if ($action !== 'restore_store') {
                if ($storeId === '' || ! $this->store->canAccess($user, $storeId)) {
                    return response()->json(['message' => 'Store tidak diizinkan.', 'code' => 'STORE_FORBIDDEN'], 403);
                }
            }

            $request->attributes->set('store_id', $storeId);
            app(RequestContext::class)->setStoreId($storeId);
            $request->session()->put('store_id', $storeId);
        }

        return $next($request);
    }
}
