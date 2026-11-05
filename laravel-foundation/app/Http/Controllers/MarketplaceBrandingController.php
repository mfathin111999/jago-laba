<?php

namespace App\Http\Controllers;

use App\Auth\LegacyUser;
use App\Services\Marketplace\MarketplaceBrandingService;
use App\Policies\RolePolicy;
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
 * Legacy-compatible /api/marketplace-branding.php: GET ?image=1 serves the logo to marketplace users,
 * POST saves logo and display options (Owner/Admin only).
 */
final class MarketplaceBrandingController
{
    private const MAX_BYTES = MarketplaceBrandingService::MAX_BYTES;

    public function __construct(
        private readonly MarketplaceBrandingService $service,
        private readonly RolePolicy $policy,
        private readonly CsrfTokenValidator $csrf,
    ) {}

    public function __invoke(Request $request): SymfonyResponse
    {
        $companyKey = $request->companyKey();

        try {
            if ($request->isMethod('GET') && $request->query('image') === '1') {
                return $this->image($companyKey);
            }
            if (! $request->isMethod('POST')) {
                throw new DomainException('Gunakan formulir pengaturan logo.');
            }

            $user = $request->user('web');
            if (! $user instanceof LegacyUser) {
                throw new DomainException('Autentikasi diperlukan.');
            }
            if (! $this->csrf->valid($request)) {
                return $this->json(['success' => false, 'message' => 'Token CSRF tidak valid.', 'code' => 'CSRF_INVALID'], 419);
            }
            if (! $this->policy->allows($user, 'settings.manage')) {
                throw new DomainException('Logo hanya dapat diubah oleh Owner atau Admin.');
            }

            $branding = $this->service->save(
                $user,
                $companyKey,
                $this->uploadedBytes($request),
                (int) $request->input('logo_size', 32),
                (string) $request->input('logo_fit', 'contain'),
                $request->input('reset_logo') === '1',
            );

            return $this->json(['success' => true, 'message' => 'Identitas dan logo tersimpan.', 'branding' => $branding]);
        } catch (InvalidArgumentException $exception) {
            return $this->json(['success' => false, 'message' => $exception->getMessage()], 422);
        } catch (DomainException $exception) {
            return $this->json(['success' => false, 'message' => $exception->getMessage()], 403);
        } catch (Throwable $exception) {
            Log::error('Marketplace branding failed.', ['exception' => $exception::class, 'message' => $exception->getMessage()]);

            return $this->json(['success' => false, 'message' => 'Logo belum dapat disimpan. Coba kembali.'], 403);
        }
    }

    private function image(string $companyKey): SymfonyResponse
    {
        $brand = $this->service->read($companyKey, true);
        if (empty($brand['logo_blob'])) {
            return new Response('', 404);
        }
        if (! in_array($brand['logo_mime'], ['image/png', 'image/jpeg', 'image/webp'], true)) {
            throw new DomainException('Logo tidak valid.');
        }

        return new Response((string) $brand['logo_blob'], 200, [
            'Content-Type' => $brand['logo_mime'],
            'Content-Disposition' => 'inline; filename="logo-marketplace"',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function uploadedBytes(Request $request): ?string
    {
        $file = $request->file('logo');
        if ($file === null) {
            return null;
        }
        if (! $file->isValid() || (int) $file->getSize() > self::MAX_BYTES) {
            throw new InvalidArgumentException('Unggahan logo gagal. Pilih gambar maksimal 2 MB.');
        }

        $bytes = file_get_contents((string) $file->getRealPath());

        return $bytes === false ? throw new InvalidArgumentException('Unggahan logo gagal. Pilih gambar maksimal 2 MB.') : $bytes;
    }

    /** @param array<string, mixed> $payload */
    private function json(array $payload, int $status = 200): JsonResponse
    {
        return response()->json($payload, $status)->header('Cache-Control', 'no-store, private')->header('X-Content-Type-Options', 'nosniff');
    }
}
