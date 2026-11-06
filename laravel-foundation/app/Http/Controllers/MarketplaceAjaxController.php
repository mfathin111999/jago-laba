<?php

namespace App\Http\Controllers;

use App\Http\Responses\UnavailableResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class MarketplaceAjaxController
{
    public function __construct(
        private readonly MarketplaceExpenseController $expenseCreate,
        private readonly MarketplaceExpenseDeleteController $expenseDelete,
        private readonly MarketplaceStoreWriteController $storeWrite,
        private readonly MarketplaceHppWriteController $hppWrite,
        private readonly MarketplaceSyncController $sync,
        private readonly MarketplaceOrderWriteController $orderWrite,
        private readonly MarketplaceSettingsController $settings,
        private readonly MarketplaceSettlementAndClaimController $claimsAndSettlement,
    ) {}

    public function __invoke(Request $request): Response
    {
        $action = trim((string) ($request->input('action') ?: $request->query('action', '')));

        return match ($action) {
            'add_expense' => app()->call($this->expenseCreate),
            'delete_expense' => app()->call($this->expenseDelete),
            'add_store' => app()->call([$this->storeWrite, 'create']),
            'update_store' => app()->call([$this->storeWrite, 'update']),
            'toggle_store_status' => app()->call([$this->storeWrite, 'toggleStatus']),
            'archive_store' => app()->call([$this->storeWrite, 'archive']),
            'restore_store' => app()->call([$this->storeWrite, 'restore']),
            'update_sku_hpp' => app()->call([$this->hppWrite, 'updateSku']),
            'update_global_product_hpp' => app()->call([$this->hppWrite, 'updateGlobal']),
            'save_hpp_batch' => app()->call([$this->hppWrite, 'saveBatch']),
            'trigger_cron_worker', 'sync_store' => app()->call($this->sync),
            'add_order' => app()->call([$this->orderWrite, 'addManual']),
            'update_manual_order' => app()->call([$this->orderWrite, 'updateManual']),
            'archive_order' => app()->call([$this->orderWrite, 'archive']),
            'get_journal_entries' => app()->call([$this->orderWrite, 'getJournal']),
            'simulate_price' => app()->call([$this->settings, 'simulate']),
            'get_saved_simulations' => app()->call([$this->settings, 'getSimulations']),
            'get_store_detail' => app()->call([$this->settings, 'getStoreDetail']),
            'test_api_connection' => app()->call([$this->settings, 'testConnection']),
            'sync_marketing_ads' => app()->call([$this->settings, 'syncMarketingAds']),
            'save_app_settings' => app()->call([$this->settings, 'saveSettings']),
            'update_return_claim' => app()->call([$this->claimsAndSettlement, 'updateClaim']),
            'import_product_ad_report' => app()->call([$this->claimsAndSettlement, 'importAdReport']),
            'import_settlement_csv' => app()->call([$this->claimsAndSettlement, 'importSettlement']),
            default => UnavailableResponse::unavailable('rpc'),
        };
    }
}
