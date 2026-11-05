<?php

namespace App\Http\Controllers;

use App\Marketplace\LegacyRuntime;
use App\Http\Requests\MarketplaceAnalyticsRequest;
use App\Pilots\Marketplace\MarketplaceAnalyticsReadService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

final class MarketplaceAnalyticsController
{
    public function __construct(private readonly MarketplaceAnalyticsReadService $analytics) {}

    public function __invoke(MarketplaceAnalyticsRequest $request): Response|RedirectResponse|SymfonyResponse
    {
        $companyKey = $request->companyKey();

        try {
            $payload = $this->analytics->overview(
                $companyKey,
                $request->storeId(),
                $request->filters(),
            );
            $format = $request->validated('format');
            if ($format === 'xlsx') {
                LegacyRuntime::service('MarketplaceAnalyticsXlsx');
                $report = $payload['analytics'];

                return response(\MarketplaceAnalyticsXlsx::bytes($report), 200, [
                    'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'Content-Disposition' => 'attachment; filename="analitik-toko-'.$report['start'].'-'.$report['end'].'.xlsx"',
                    'Cache-Control' => 'no-store, private',
                ]);
            }
            if ($format === 'csv') {
                LegacyRuntime::service('MarketplaceAnalyticsCsv');
                $report = $payload['analytics'];

                return response()->streamDownload(function () use ($report): void {
                    $out = fopen('php://output', 'w');
                    \MarketplaceAnalyticsCsv::write($out, $report);
                    fclose($out);
                }, 'analitik-global-'.$report['start'].'-'.$report['end'].'.csv', [
                    'Content-Type' => 'text/csv; charset=utf-8', 'Cache-Control' => 'no-store, private',
                ]);
            }

            return Inertia::render('Pilot/MarketplaceAnalytics', $payload);
        } catch (Throwable $exception) {
            if ($exception instanceof InvalidArgumentException) {
                return Inertia::render('Pilot/MarketplaceAnalytics', [
                    'read_only' => true,
                    'workspace' => 'marketplace',
                    'scope' => ['company_key' => $companyKey, 'store_id' => $request->storeId()],
                    'stores' => $this->analytics->stores($companyKey),
                    'active_store' => null,
                    'filters' => $request->filters(),
                    'analytics' => null,
                    'query' => ['tab' => 'analitik', 'read_only' => true, 'provider_call' => false, 'write' => false],
                    'message' => $exception->getMessage(),
                ])->toResponse($request)->setStatusCode(422);
            }

            if ($exception instanceof \DomainException && $exception->getCode() === MarketplaceAnalyticsReadService::NO_INTEGRATED_STORE) {
                return $this->emptyPage($companyKey, $request, 'Belum ada store Marketplace yang terhubung.', [
                    'tab' => 'analitik',
                    'redirect' => 'marketplace.php?tab=pengaturan&peng_sub=tambah_toko',
                ]);
            }

            if ($exception instanceof \DomainException && $exception->getCode() === MarketplaceAnalyticsReadService::STORE_SELECTION_REQUIRED) {
                return $this->emptyPage($companyKey, $request, $exception->getMessage(), ['tab' => 'analitik']);
            }

            if ($exception instanceof \DomainException && $exception->getCode() === MarketplaceAnalyticsReadService::STORE_FORBIDDEN) {
                abort(403, $exception->getMessage());
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $query
     */
    private function emptyPage(string $companyKey, MarketplaceAnalyticsRequest $request, string $message, array $query): Response
    {
        return Inertia::render('Pilot/MarketplaceAnalytics', [
            'read_only' => true,
            'workspace' => 'marketplace',
            'scope' => ['company_key' => $companyKey, 'store_id' => null],
            'stores' => $this->analytics->stores($companyKey),
            'active_store' => null,
            'filters' => $request->filters(),
            'analytics' => null,
            'query' => $query + ['read_only' => true, 'provider_call' => false, 'write' => false],
            'message' => $message,
        ]);
    }
}
