<?php

namespace App\Http\Controllers;

use App\Auth\LegacyUser;
use App\Pilots\Marketplace\MarketplaceWithdrawalImportService;
use App\Security\CsrfTokenValidator;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

/**
 * Legacy-compatible /api/marketplace-withdrawals.php.
 * template (GET) downloads the CSV format; preview delegates to the existing preview controller;
 * confirm writes the staged rows. No provider is called.
 */
final class MarketplaceWithdrawalLegacyController
{
    private const TEMPLATE_HEADER = "withdrawal_id,requested_at,completed_at,amount,fee,net_amount,currency,status,type,bank_name,account_number,failure_reason\r\n";

    public function __construct(
        private readonly MarketplaceWithdrawalPreviewController $preview,
        private readonly MarketplaceWithdrawalImportService $import,
        private readonly CsrfTokenValidator $csrf,
    ) {}

    public function __invoke(Request $request): SymfonyResponse
    {
        $action = (string) $request->input('action', '');

        if ($action === 'template') {
            return new Response(self::TEMPLATE_HEADER, 200, [
                'Content-Type' => 'text/csv; charset=utf-8',
                'Content-Disposition' => 'attachment; filename="format-penarikan.csv"',
                'Cache-Control' => 'no-store, private',
            ]);
        }

        try {
            if (! $request->isMethod('POST')) {
                throw new DomainException('Gunakan POST.');
            }
            if (! $this->csrf->valid($request)) {
                return $this->json(['success' => false, 'message' => 'Token CSRF tidak valid.', 'code' => 'CSRF_INVALID'], 419);
            }

            return match ($action) {
                'preview' => app()->call($this->preview),
                'confirm' => $this->confirm($request),
                default => throw new InvalidArgumentException('Aksi tidak dikenal.'),
            };
        } catch (DomainException $exception) {
            return $this->json(['success' => false, 'message' => $exception->getMessage()], 403);
        } catch (InvalidArgumentException $exception) {
            return $this->json(['success' => false, 'message' => $exception->getMessage()], 400);
        } catch (Throwable $exception) {
            Log::error('Marketplace withdrawal import failed.', ['exception' => $exception::class, 'message' => $exception->getMessage()]);

            return $this->json(['success' => false, 'message' => 'Impor gagal. Tidak ada perubahan yang dikonfirmasi.'], 400);
        }
    }

    private function confirm(Request $request): JsonResponse
    {
        $user = $request->user('web');
        if (! $user instanceof LegacyUser) {
            throw new DomainException('Autentikasi diperlukan.');
        }

        $data = $this->import->confirm(
            $request->session(),
            $user,
            (string) $request->attributes->get('company_key'),
            (string) $request->input('key', ''),
        );

        return $this->json(['success' => true, 'data' => $data]);
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)->header('Cache-Control', 'no-store, private');
    }
}
