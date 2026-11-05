<?php

namespace App\Http\Controllers;

use App\Auth\LegacyAuthenticationService;
use App\Auth\LegacySessionStore;
use App\Auth\LegacyUser;
use App\Authorization\CompanyContext;
use App\Authorization\WorkspaceContext;
use App\Exceptions\KonveksiRequestRejected;
use App\Http\Responses\LegacyResponse;
use App\Pilots\Konveksi\KonveksiBackupService;
use App\Pilots\Konveksi\KonveksiDocumentAndAiService;
use App\Pilots\Konveksi\KonveksiHutangService;
use App\Pilots\Konveksi\KonveksiInventoryService;
use App\Pilots\Konveksi\KonveksiOperationGate;
use App\Pilots\Konveksi\KonveksiPilotReadService;
use App\Pilots\Konveksi\KonveksiPurchaseWriteService;
use App\Pilots\Konveksi\KonveksiSalesWriteService;
use App\Pilots\Konveksi\KonveksiSyncAndHppService;
use App\Policies\RolePolicy;
use App\Security\CsrfTokenValidator;
use App\Support\RequestId;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use LogicException;
use PDOException;
use RuntimeException;
use Throwable;

final class LegacyRpcController
{
    /** Methods any Konveksi user may call; every other method requires an administrator. */
    private const READ_METHODS = ['loadSemuaData', 'getRiwayatBackupJagoLaba', 'getGeminiApiKeyStatusServerRpc'];

    /** Writes that must run through the operation gate: serialised, idempotent and checked for stale data. */
    private const GATED_METHODS = [
        'saveDraftPenjualanProduksiServer', 'postPenjualanProduksiServer', 'correctPenjualanProduksiServer',
        'voidPenjualanProduksiServer', 'retryBackupPenjualanProduksiServer', 'createHutangKonveksiManual',
        'bayarHutangKonveksi', 'saveMaterialMasterServerRpc', 'savePurchaseInvoiceServerRpc', 'syncTab',
        'syncMultiTabs', 'activateHppProductionServerRpc', 'syncBahanHppFromPurchasesServerRpc',
        'saveDocumentTemplate', 'saveGeminiApiKeyServerRpc', 'auditInventoryConsistencyServer',
        'migrateInventoryIdStokItemServer',
    ];

    public function __construct(
        private readonly LegacyAuthenticationService $authentication,
        private readonly LegacySessionStore $legacySession,
        private readonly CsrfTokenValidator $csrf,
        private readonly CompanyContext $company,
        private readonly WorkspaceContext $workspace,
        private readonly RolePolicy $policy,
        private readonly KonveksiPilotReadService $konveksi,
        private readonly KonveksiSalesWriteService $salesWrite,
        private readonly KonveksiHutangService $hutang,
        private readonly KonveksiPurchaseWriteService $purchase,
        private readonly KonveksiBackupService $backup,
        private readonly KonveksiSyncAndHppService $syncAndHpp,
        private readonly KonveksiDocumentAndAiService $docsAndAi,
        private readonly KonveksiInventoryService $inventory,
        private readonly KonveksiOperationGate $gate,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $payload = $request->json()->all();
        $method = is_array($payload) ? trim((string) ($payload['method'] ?? '')) : '';
        $args = is_array($payload) && isset($payload['args']) && is_array($payload['args']) ? $payload['args'] : null;

        if ($method === '' || $args === null) {
            return $this->error('Payload RPC tidak valid.', 'RPC_INVALID_REQUEST', 422);
        }

        if ($method === 'prosesLoginBackend') {
            return $this->login($request, $args);
        }

        if (! Auth::guard('web')->check()) {
            return $this->error('Sesi login berakhir. Silakan login kembali.', 'AUTH_REQUIRED', 401);
        }

        if (! $this->csrf->valid($request)) {
            return $this->error('Token CSRF tidak valid.', 'CSRF_INVALID', 403);
        }

        if ($denied = $this->authorizeKonveksi($method)) {
            return $denied;
        }

        try {
            if (in_array($method, self::GATED_METHODS, true)) {
                return $this->runGated($request, $method, $args);
            }

            return $this->dispatch($request, $method, $args);
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    /** @param array<int, mixed> $args */
    private function dispatch(Request $request, string $method, array $args): JsonResponse
    {
        if ($method === 'loadSemuaData') {
            return $this->loadKonveksiData($request);
        }

        if ($method === 'saveDraftPenjualanProduksiServer') {
            return $this->saveDraftSale($request, $args);
        }

        if ($method === 'postPenjualanProduksiServer') {
            return $this->postSale($request, $args);
        }

        if ($method === 'createHutangKonveksiManual') {
            return $this->createHutang($request, $args);
        }

        if ($method === 'bayarHutangKonveksi') {
            return $this->bayarHutang($request, $args);
        }

        if ($method === 'saveMaterialMasterServerRpc') {
            return $this->saveMaterial($request, $args);
        }

        if ($method === 'savePurchaseInvoiceServerRpc') {
            return $this->savePurchaseInvoice($request, $args);
        }

        if ($method === 'correctPenjualanProduksiServer') {
            return $this->correctSale($request, $args);
        }

        if ($method === 'voidPenjualanProduksiServer') {
            return $this->voidSale($request, $args);
        }

        if (in_array($method, ['backupSekarangJagoLaba', 'buatBackupManualSekarang'], true)) {
            return $this->backupNow($request, $args);
        }

        if ($method === 'jadwalkanSnapshotRinganJagoLaba') {
            return $this->scheduleSnapshot();
        }

        if ($method === 'retryBackupPenjualanProduksiServer') {
            return $this->retrySaleBackup($args);
        }

        if ($method === 'retryUploadPurchaseDocumentServerRpc') {
            return $this->retryPurchaseDocument($args);
        }

        if ($method === 'getRiwayatBackupJagoLaba') {
            return $this->backupHistory($request);
        }

        if ($method === 'syncTab') {
            return $this->syncSingleTab($request, $args);
        }

        if ($method === 'syncMultiTabs') {
            return $this->syncMultipleTabs($request, $args);
        }

        if ($method === 'activateHppProductionServerRpc') {
            return $this->activateHpp($request, $args);
        }

        if ($method === 'syncBahanHppFromPurchasesServerRpc') {
            return $this->syncPurchasesHpp($request);
        }

        if ($method === 'saveDocumentFileServer') {
            return $this->saveDocument($request, $args);
        }

        if ($method === 'saveDocumentTemplate') {
            return $this->saveTemplate($request, $args);
        }

        if ($method === 'getGeminiApiKeyStatusServerRpc') {
            return $this->geminiStatus();
        }

        if ($method === 'saveGeminiApiKeyServerRpc') {
            return $this->saveGeminiKey($request, $args);
        }

        if ($method === 'extractPurchaseInvoiceServerRpc') {
            return $this->extractInvoice($request, $args);
        }

        if ($method === 'auditInventoryConsistencyServer') {
            return $this->auditInventory($request);
        }

        if ($method === 'migrateInventoryIdStokItemServer') {
            return $this->migrateInventoryIds($request);
        }

        return LegacyResponse::unavailable('rpc');
    }

    private function auditInventory(Request $request): JsonResponse
    {
        $user = Auth::guard('web')->user();
        $companyKey = $this->company->key($user);
        $res = $this->inventory->audit($user, (string) $companyKey);

        return response()->json(['ok' => true, 'result' => $res]);
    }

    private function migrateInventoryIds(Request $request): JsonResponse
    {
        $user = Auth::guard('web')->user();
        $companyKey = $this->company->key($user);
        $res = $this->inventory->migrateIds($user, (string) $companyKey);

        return response()->json(['ok' => true, 'result' => $res]);
    }

    private function saveDocument(Request $request, array $args): JsonResponse
    {
        $user = Auth::guard('web')->user();
        $companyKey = $this->company->key($user);
        $base64 = (string) ($args[0] ?? '');
        $fileName = (string) ($args[1] ?? 'document.pdf');
        $type = (string) ($args[2] ?? 'General');
        $refId = (string) ($args[3] ?? '');
        $res = $this->docsAndAi->saveBase64Document($user, (string) $companyKey, $base64, $fileName, $type, $refId);

        return response()->json(['ok' => true, 'result' => $res]);
    }

    private function saveTemplate(Request $request, array $args): JsonResponse
    {
        $user = Auth::guard('web')->user();
        $companyKey = $this->company->key($user);
        $data = is_array($args[0] ?? null) ? $args[0] : (is_string($args[0] ?? null) ? json_decode($args[0], true) : []);
        $res = $this->docsAndAi->saveTemplate($user, (string) $companyKey, (array) $data);

        return response()->json(['ok' => true, 'result' => $res]);
    }

    private function geminiStatus(): JsonResponse
    {
        $res = $this->docsAndAi->geminiStatus();

        return response()->json(['ok' => true, 'result' => $res]);
    }

    private function saveGeminiKey(Request $request, array $args): JsonResponse
    {
        $user = Auth::guard('web')->user();
        $key = (string) ($args[0] ?? '');
        $res = $this->docsAndAi->saveGeminiKey($user, $key);

        return response()->json(['ok' => true, 'result' => $res]);
    }

    private function extractInvoice(Request $request, array $args): JsonResponse
    {
        $data = is_array($args[0] ?? null) ? $args[0] : (is_string($args[0] ?? null) ? json_decode($args[0], true) : []);
        $res = $this->docsAndAi->extractInvoice((array) $data);

        return response()->json(['ok' => true, 'result' => $res]);
    }

    private function syncSingleTab(Request $request, array $args): JsonResponse
    {
        $user = Auth::guard('web')->user();
        $companyKey = $this->company->key($user);
        $tab = (string) ($args[0] ?? '');
        $payload = $args[1] ?? [];
        $res = $this->syncAndHpp->syncTab($user, (string) $companyKey, $tab, $payload);

        return response()->json(['ok' => true, 'result' => $res]);
    }

    private function syncMultipleTabs(Request $request, array $args): JsonResponse
    {
        $user = Auth::guard('web')->user();
        $companyKey = $this->company->key($user);
        $payload = $args[0] ?? [];
        $res = $this->syncAndHpp->syncMultiTabs($user, (string) $companyKey, $payload);

        return response()->json(['ok' => true, 'result' => $res]);
    }

    private function activateHpp(Request $request, array $args): JsonResponse
    {
        $user = Auth::guard('web')->user();
        $companyKey = $this->company->key($user);
        $data = is_array($args[0] ?? null) ? $args[0] : (is_string($args[0] ?? null) ? json_decode($args[0], true) : []);
        $res = $this->syncAndHpp->activateHpp($user, (string) $companyKey, (array) $data);

        return response()->json(['ok' => true, 'result' => $res]);
    }

    private function syncPurchasesHpp(Request $request): JsonResponse
    {
        $user = Auth::guard('web')->user();
        $companyKey = $this->company->key($user);
        $res = $this->syncAndHpp->syncFromPurchases($user, (string) $companyKey);

        return response()->json(['ok' => true, 'result' => $res]);
    }

    private function correctSale(Request $request, array $args): JsonResponse
    {
        $user = Auth::guard('web')->user();
        $companyKey = $this->company->key($user);
        $data = is_array($args[0] ?? null) ? $args[0] : (is_string($args[0] ?? null) ? json_decode($args[0], true) : []);
        $res = $this->salesWrite->correct($user, (string) $companyKey, (array) $data);

        return response()->json(['ok' => true, 'result' => $res]);
    }

    private function voidSale(Request $request, array $args): JsonResponse
    {
        $user = Auth::guard('web')->user();
        $companyKey = $this->company->key($user);
        $data = is_array($args[0] ?? null) ? $args[0] : (is_string($args[0] ?? null) ? json_decode($args[0], true) : []);
        $res = $this->salesWrite->void($user, (string) $companyKey, (array) $data);

        return response()->json(['ok' => true, 'result' => $res]);
    }

    private function backupNow(Request $request, array $args): JsonResponse
    {
        $user = Auth::guard('web')->user();
        $mode = (string) ($args[0] ?? 'MANUAL');
        $res = $this->publicBackup($this->backup->create($user, $mode ?: 'MANUAL'));

        return response()->json(['ok' => true, 'result' => $res]);
    }

    /**
     * Runs a write RPC inside the operation gate. The wrapped call keeps its normal behaviour; the gate adds
     * the write lock, idempotent replay, stale-data and reset-epoch checks, and the receipt.
     *
     * @param  array<int, mixed>  $args
     */
    private function runGated(Request $request, string $method, array $args): JsonResponse
    {
        $user = Auth::guard('web')->user();
        $companyKey = $this->company->key($user);
        if ($companyKey === null) {
            return $this->error('Company context tidak tersedia.', 'COMPANY_REQUIRED', 403);
        }

        try {
            $result = $this->gate->run($user, $companyKey, $method, $args, $request, function () use ($request, $method, $args): array {
                $response = $this->dispatch($request, $method, $args);
                $body = json_decode((string) $response->getContent(), true);
                if (! is_array($body) || ($body['ok'] ?? false) !== true) {
                    throw KonveksiRequestRejected::passthrough($response);
                }

                return is_array($body['result'] ?? null) ? $body['result'] : [];
            });
        } catch (KonveksiRequestRejected $rejection) {
            return $rejection->response ?? $this->error($rejection->getMessage(), $rejection->errorCode, $rejection->status);
        }

        return response()->json(['ok' => true, 'result' => $result])->header('Cache-Control', 'no-store, private');
    }

    private function scheduleSnapshot(): JsonResponse
    {
        $res = $this->backup->scheduleSmart(Auth::guard('web')->user());

        return response()->json(['ok' => true, 'result' => $res]);
    }

    /** @param array<int, mixed> $args */
    private function retrySaleBackup(array $args): JsonResponse
    {
        $user = Auth::guard('web')->user();
        $data = is_array($args[0] ?? null) ? $args[0] : (is_string($args[0] ?? null) ? json_decode($args[0], true) : []);
        $res = $this->salesWrite->retryBackup($user, (string) $this->company->key($user), (array) $data);

        return response()->json(['ok' => true, 'result' => $res]);
    }

    /** @param array<int, mixed> $args */
    private function retryPurchaseDocument(array $args): JsonResponse
    {
        $user = Auth::guard('web')->user();
        $data = is_array($args[0] ?? null) ? $args[0] : (is_string($args[0] ?? null) ? json_decode($args[0], true) : []);
        $res = $this->purchase->retryDocument($user, (string) $this->company->key($user), (array) $data);

        return response()->json(['ok' => true, 'result' => $res]);
    }

    /**
     * Backup rows keep full server paths in the database for operators; the browser only gets file names, so a
     * signed-in user never learns the server's directory layout. The screen's download link falls back to '#'.
     *
     * @param  array<string, mixed>  $backup
     * @return array<string, mixed>
     */
    private function publicBackup(array $backup): array
    {
        foreach (['sql_file', 'snapshot_file', 'manifest_file'] as $field) {
            if (isset($backup[$field]) && is_string($backup[$field]) && $backup[$field] !== '') {
                $backup[$field] = $this->fileName($backup[$field]);
            }
        }
        if (array_key_exists('external_files', $backup)) {
            $external = (array) $backup['external_files'];
            $backup['external_files'] = isset($external['error']) ? ['error' => 'Salinan eksternal gagal.'] : array_map(fn (mixed $path): string => $this->fileName((string) $path), $external);
        }
        if (array_key_exists('url', $backup)) {
            $backup['url'] = '#';
        }

        return $backup;
    }

    /** The last path segment of either a Windows or a POSIX path. */
    private function fileName(string $path): string
    {
        return basename(strtr($path, [chr(92) => '/']));
    }

    private function backupHistory(Request $request): JsonResponse
    {
        $res = array_map(fn (array $row): array => $this->publicBackup($row), $this->backup->history());

        return response()->json(['ok' => true, 'result' => $res]);
    }

    private function createHutang(Request $request, array $args): JsonResponse
    {
        $user = Auth::guard('web')->user();
        $companyKey = $this->company->key($user);
        $data = is_array($args[0] ?? null) ? $args[0] : (is_string($args[0] ?? null) ? json_decode($args[0], true) : []);
        $res = $this->hutang->createManual($user, (string) $companyKey, (array) $data);

        return response()->json(['ok' => true, 'result' => $res]);
    }

    private function bayarHutang(Request $request, array $args): JsonResponse
    {
        $user = Auth::guard('web')->user();
        $companyKey = $this->company->key($user);
        $data = is_array($args[0] ?? null) ? $args[0] : (is_string($args[0] ?? null) ? json_decode($args[0], true) : []);
        $res = $this->hutang->pay($user, (string) $companyKey, (array) $data);

        return response()->json(['ok' => true, 'result' => $res]);
    }

    private function saveMaterial(Request $request, array $args): JsonResponse
    {
        $user = Auth::guard('web')->user();
        $companyKey = $this->company->key($user);
        $data = is_array($args[0] ?? null) ? $args[0] : (is_string($args[0] ?? null) ? json_decode($args[0], true) : []);
        $res = $this->purchase->saveMaterial($user, (string) $companyKey, (array) $data);

        return response()->json(['ok' => true, 'result' => $res]);
    }

    private function savePurchaseInvoice(Request $request, array $args): JsonResponse
    {
        $user = Auth::guard('web')->user();
        $companyKey = $this->company->key($user);
        $data = is_array($args[0] ?? null) ? $args[0] : (is_string($args[0] ?? null) ? json_decode($args[0], true) : []);
        $res = $this->purchase->saveInvoice($user, (string) $companyKey, (array) $data);

        return response()->json(['ok' => true, 'result' => $res]);
    }

    private function saveDraftSale(Request $request, array $args): JsonResponse
    {
        $user = Auth::guard('web')->user();
        $activeWorkspace = $this->workspace->active($user);

        if ($activeWorkspace !== WorkspaceContext::KONVEKSI) {
            return $this->error('Workspace Konveksi belum aktif.', 'WORKSPACE_NOT_ACTIVE', 403);
        }

        $companyKey = $this->company->key($user);
        if ($companyKey === null) {
            return $this->error('Company context tidak tersedia.', 'COMPANY_REQUIRED', 403);
        }

        $data = is_array($args[0] ?? null) ? $args[0] : (is_string($args[0] ?? null) ? json_decode($args[0], true) : []);
        if (! is_array($data)) {
            $data = [];
        }

        $res = $this->salesWrite->saveDraft($user, $companyKey, $data);

        return response()->json([
            'ok' => true,
            'result' => $res,
        ])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'X-Request-Id' => RequestId::current(),
        ]);
    }

    private function postSale(Request $request, array $args): JsonResponse
    {
        $user = Auth::guard('web')->user();
        $activeWorkspace = $this->workspace->active($user);

        if ($activeWorkspace !== WorkspaceContext::KONVEKSI) {
            return $this->error('Workspace Konveksi belum aktif.', 'WORKSPACE_NOT_ACTIVE', 403);
        }

        $companyKey = $this->company->key($user);
        if ($companyKey === null) {
            return $this->error('Company context tidak tersedia.', 'COMPANY_REQUIRED', 403);
        }

        $data = is_array($args[0] ?? null) ? $args[0] : (is_string($args[0] ?? null) ? json_decode($args[0], true) : []);
        if (! is_array($data)) {
            $data = [];
        }

        $res = $this->salesWrite->post($user, $companyKey, $data);

        return response()->json([
            'ok' => true,
            'result' => $res,
        ])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'X-Request-Id' => RequestId::current(),
        ]);
    }

    private function loadKonveksiData(Request $request): JsonResponse
    {
        $user = Auth::guard('web')->user();
        $activeWorkspace = $this->workspace->active($user);

        if ($activeWorkspace !== WorkspaceContext::KONVEKSI) {
            return $this->error(
                'Workspace Konveksi belum aktif.',
                $this->workspace->can($user, WorkspaceContext::KONVEKSI) ? 'WORKSPACE_NOT_ACTIVE' : 'WORKSPACE_FORBIDDEN',
                403,
            );
        }

        $companyKey = $this->company->key($user);
        if ($companyKey === null) {
            return $this->error('Company context tidak tersedia.', 'COMPANY_REQUIRED', 403);
        }

        return response()->json([
            'ok' => true,
            'result' => $this->konveksi->allKnown(),
        ])->withHeaders([
            'Cache-Control' => 'no-store, private',
            'X-Request-Id' => RequestId::current(),
        ]);
    }

    /**
     * @param  array<int, mixed>  $args
     */
    private function login(Request $request, array $args): JsonResponse
    {
        $username = is_scalar($args[0] ?? null) ? trim((string) $args[0]) : '';
        $password = is_scalar($args[1] ?? null) ? (string) $args[1] : '';

        if ($username === '' || $password === '') {
            return response()->json([
                'ok' => true,
                'result' => [
                    'status' => 'error',
                    'code' => 'AUTH_REQUIRED',
                    'message' => 'Username dan password wajib diisi.',
                ],
            ]);
        }

        $user = $this->authentication->attempt($request, $username, $password);

        if (! $user instanceof LegacyUser) {
            return response()->json([
                'ok' => true,
                'result' => [
                    'status' => 'error',
                    'code' => 'AUTH_INVALID_CREDENTIALS',
                    'message' => 'Username atau password tidak valid.',
                ],
            ]);
        }

        $legacySessionId = $this->legacySession->syncUser($request, $user);
        $response = response()->json([
            'ok' => true,
            'result' => $this->authentication->legacyResult($user, $request),
        ]);

        if ($legacySessionId !== null) {
            $response->withCookie(cookie()->make(
                $this->legacySession->cookieName(),
                $legacySessionId,
                (int) config('session.lifetime', 120),
                config('session.path', '/'),
                config('session.domain'),
                (bool) config('session.secure', false),
                (bool) config('session.http_only', true),
                false,
                config('session.same_site', 'lax'),
            ));
        }

        return $response;
    }

    /**
     * Konveksi RPC needs the active Konveksi workspace; anything that is not a read needs an administrator
     * (the same rule as the legacy RpcService). Login is handled before this point.
     */
    private function authorizeKonveksi(string $method): ?JsonResponse
    {
        $user = Auth::guard('web')->user();

        if (! $this->workspace->can($user, WorkspaceContext::KONVEKSI)) {
            return $this->error('Workspace tidak diizinkan.', 'WORKSPACE_FORBIDDEN', 403);
        }
        if ($this->workspace->active($user) !== WorkspaceContext::KONVEKSI) {
            return $this->error('Workspace belum dipilih.', 'WORKSPACE_NOT_ACTIVE', 403);
        }
        if (! in_array($method, self::READ_METHODS, true) && ! $this->policy->allows($user, 'settings.manage')) {
            return $this->error('Perubahan Konveksi memerlukan izin administrator.', 'ROLE_FORBIDDEN', 403);
        }

        return null;
    }

    /**
     * Turns an exception into the legacy RPC error contract so the screen can show the reason:
     * conflict 409, refused 403, invalid input 422, business rule 400. Database failures are masked
     * and reported with a request id that can be found in the log.
     */
    private function failure(Throwable $exception): JsonResponse
    {
        if ($exception instanceof DomainException) {
            return $exception->getCode() === 409
                ? $this->error($exception->getMessage(), 'CONFLICT', 409)
                : $this->error($exception->getMessage(), 'OPERATION_REFUSED', 403);
        }
        if ($exception instanceof InvalidArgumentException) {
            return $this->error($exception->getMessage(), 'INVALID_INPUT', 422);
        }
        if (($exception instanceof RuntimeException || $exception instanceof LogicException) && ! $exception instanceof PDOException) {
            return $this->error($exception->getMessage(), 'OPERATION_FAILED', 400);
        }

        $requestId = RequestId::current();
        Log::error('Konveksi RPC failed.', ['request_id' => $requestId, 'exception' => $exception::class, 'message' => $exception->getMessage()]);

        return $this->error('Penyimpanan belum berhasil. Hubungi administrator dengan ID permintaan '.$requestId, 'STORAGE_FAILURE', 500, $requestId);
    }

    private function error(string $message, string $code, int $status, ?string $requestId = null): JsonResponse
    {
        $requestId ??= RequestId::current();

        return response()->json([
            'ok' => false,
            'error' => [
                'message' => $message,
                'code' => $code,
                'request_id' => $requestId,
            ],
        ], $status)->header('X-Request-Id', $requestId);
    }
}
