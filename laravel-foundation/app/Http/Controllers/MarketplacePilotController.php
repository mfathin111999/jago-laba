<?php

namespace App\Http\Controllers;

use App\Http\Requests\MarketplacePilotRequest;
use App\Http\Responses\CsvDownload;
use App\Pilots\Marketplace\MarketplacePilotReadService;
use App\Pilots\Marketplace\OrderReadService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

final class MarketplacePilotController
{
    public function __construct(private readonly MarketplacePilotReadService $pilot, private readonly OrderReadService $orders) {}

    public function __invoke(MarketplacePilotRequest $request): Response|RedirectResponse
    {
        $companyKey = $request->companyKey();

        try {
            $payload = $this->pilot->overview($companyKey, $request->storeId(), $request->filters());
        } catch (Throwable $exception) {
            if ($exception instanceof \DomainException && $exception->getCode() === MarketplacePilotReadService::NO_INTEGRATED_STORE) {
                return Inertia::render('Pilot/Marketplace', [
                    'read_only' => true,
                    'workspace' => 'marketplace',
                    'scope' => ['company_key' => $companyKey, 'store_id' => null],
                    'stores' => $this->pilot->stores($companyKey),
                    'filters' => $request->filters(),
                    'dashboard' => null,
                    'query' => ['tab' => 'dashboard', 'redirect' => 'marketplace.php?tab=pengaturan&peng_sub=tambah_toko'],
                    'message' => 'Belum ada store Marketplace yang terhubung.',
                ]);
            }

            if ($exception instanceof \DomainException && $exception->getCode() === MarketplacePilotReadService::STORE_SELECTION_REQUIRED) {
                return Inertia::render('Pilot/Marketplace', [
                    'read_only' => true,
                    'workspace' => 'marketplace',
                    'scope' => ['company_key' => $companyKey, 'store_id' => null],
                    'stores' => $this->pilot->stores($companyKey),
                    'filters' => $request->filters(),
                    'dashboard' => null,
                    'query' => ['tab' => 'dashboard'],
                    'message' => $exception->getMessage(),
                ]);
            }

            if ($exception instanceof \DomainException && $exception->getCode() === MarketplacePilotReadService::STORE_FORBIDDEN) {
                abort(403, $exception->getMessage());
            }

            throw $exception;
        }

        $payload['order_summary'] = $this->orderSummary($companyKey, (string) ($payload['active_store']['id'] ?? ''), $payload['dashboard']['period'] ?? []);

        return Inertia::render('Pilot/Marketplace', $payload);
    }

    /**
     * Counts for the "Pesanan Baru / Menunggu Pickup / ..." strip, as the legacy dashboard shows them.
     * Never allowed to break the dashboard: null when the orders read fails.
     *
     * @param  array<string, mixed>  $period
     * @return array<string, mixed>|null
     */
    private function orderSummary(string $companyKey, string $storeId, array $period): ?array
    {
        if ($storeId === '' || ! isset($period['start'], $period['end'])) {
            return null;
        }

        try {
            $overview = $this->orders->overview($companyKey, $storeId, ['period' => 'custom', 'date_start' => (string) $period['start'], 'date_end' => (string) $period['end'], 'per_page' => 1]);
        } catch (Throwable) {
            return null;
        }

        return [
            'counts' => $overview['orders']['counts'] ?? null,
            'return_available' => (bool) ($overview['orders']['return_coverage']['available'] ?? false),
        ];
    }

    public function export(MarketplacePilotRequest $request): SymfonyResponse
    {
        try {
            $payload = $this->pilot->overview($request->companyKey(), $request->storeId(), $request->filters());
        } catch (\InvalidArgumentException $exception) {
            return response($exception->getMessage(), 422);
        } catch (\DomainException $exception) {
            return match ($exception->getCode()) {
                MarketplacePilotReadService::NO_INTEGRATED_STORE, MarketplacePilotReadService::STORE_SELECTION_REQUIRED => response($exception->getMessage(), 422),
                MarketplacePilotReadService::STORE_FORBIDDEN => response($exception->getMessage(), 403),
                default => throw $exception,
            };
        }

        $rows = array_map(static fn (array $row): array => [
            "'".$row['id'], $row['state'], $row['sales'], $row['net'], $row['hpp'], $row['profit'],
        ], $payload['dashboard']['rows'] ?? []);

        return CsvDownload::stream(
            'rincian-dashboard.csv',
            ['Pesanan', 'Keuangan', 'Penjualan', 'Untuk Dibayar', 'HPP Produk', 'Laba setelah HPP'],
            $rows,
        );
    }
}
