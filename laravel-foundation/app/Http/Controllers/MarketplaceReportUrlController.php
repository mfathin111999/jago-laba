<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Legacy report URLs (/api/marketplace-orders.php, /api/marketplace-dashboard.php).
 * ?format=csv keeps downloading the CSV; any other request moves to the Laravel page.
 */
final class MarketplaceReportUrlController
{
    public function orders(Request $request): Response|RedirectResponse
    {
        if ($request->query('format') === 'csv') {
            return app()->call([app(MarketplaceOrderController::class), 'export']);
        }

        return redirect()->route('pilot.marketplace.orders', $request->except('format'));
    }

    /** The legacy analytics URL names the store `store_filter`; Laravel reads `store`. */
    public function analytics(Request $request): Response|RedirectResponse
    {
        $request->merge(['store' => $request->query('store_filter', $request->query('store'))]);

        if (in_array($request->query('format'), ['csv', 'xlsx'], true)) {
            return app()->call([app(MarketplaceAnalyticsController::class), '__invoke']);
        }

        return redirect()->route('pilot.marketplace.analytics', $request->except(['format', 'store_filter']));
    }

    public function dashboard(Request $request): Response|RedirectResponse
    {
        if ($request->query('format') === 'csv') {
            return app()->call([app(MarketplacePilotController::class), 'export']);
        }

        return redirect()->route('pilot.marketplace', $request->except('format'));
    }
}
