<?php

namespace App\Http\Controllers;

use App\Pilots\Marketplace\MarketplaceSettlementAndClaimService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use LogicException;

final class MarketplaceSettlementAndClaimController
{
    public function __construct(private readonly MarketplaceSettlementAndClaimService $service) {}

    public function updateClaim(Request $request): JsonResponse
    {
        $claimId = (int) ($request->input('claim_id') ?? 0);
        $storeId = (string) ($request->input('store_id') ?? '');
        $status = (string) ($request->input('claim_status') ?? 'MENUNGGU_FISIK');
        $reimburse = (float) str_replace(['Rp', '.', ' ', ','], ['', '', '', '.'], (string) ($request->input('reimbursement_amount') ?? '0'));
        $notes = $request->input('notes') ? trim((string) $request->input('notes')) : null;

        try {
            $res = $this->service->updateReturnClaimStatus(
                $request->user('web'),
                (string) $request->attributes->get('company_key'),
                $claimId,
                $storeId,
                $status,
                $reimburse,
                $notes,
            );

            return response()->json($res);
        } catch (InvalidArgumentException|LogicException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function importAdReport(Request $request): JsonResponse
    {
        $storeId = (string) ($request->input('store_id') ?? '');
        $file = $request->file('report');
        if (! $file || ! $file->isValid()) {
            return response()->json(['success' => false, 'message' => 'Unggah CSV valid maksimal 2 MB.'], 422);
        }

        try {
            $count = $this->service->importProductAdReport(
                $request->user('web'),
                (string) $request->attributes->get('company_key'),
                $storeId,
                $file->getRealPath(),
            );

            return response()->json([
                'success' => true,
                'message' => "{$count} laporan produk berhasil diimpor. Data periode yang sama diperbarui tanpa menggandakan biaya.",
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }

    public function importSettlement(Request $request): JsonResponse
    {
        $storeId = (string) ($request->input('store_id') ?? '');
        $file = $request->file('settlement_file');
        if (! $file || ! $file->isValid()) {
            return response()->json(['success' => false, 'message' => 'Silakan pilih file CSV/Excel settlement untuk diunggah.'], 422);
        }

        try {
            $res = $this->service->importSettlementCSV(
                $request->user('web'),
                (string) $request->attributes->get('company_key'),
                $storeId,
                $file->getRealPath(),
                $file->getClientOriginalName(),
            );

            return response()->json($res);
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }
}
