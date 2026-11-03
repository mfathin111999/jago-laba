<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Legacy report URLs (/api/marketplace-orders.php, /api/marketplace-dashboard.php).
 * ?format=csv keeps downloading the CSV; any other request moves to the Laravel page.
 */
final class MarketplaceLegacyReportController
{
    public function orders(Request $request): Response|RedirectResponse
    {
        if ($request->query('format') === 'csv') {
            return app()->call([app(MarketplaceOrderController::class), 'export']);
        }

        return redirect()->route('pilot.marketplace.orders', $request->except('format'));
    }

    public function dashboard(Request $request): Response|RedirectResponse
    {
        if ($request->query('format') === 'csv') {
            return app()->call([app(MarketplacePilotController::class), 'export']);
        }

        return redirect()->route('pilot.marketplace', $request->except('format'));
    }
}
