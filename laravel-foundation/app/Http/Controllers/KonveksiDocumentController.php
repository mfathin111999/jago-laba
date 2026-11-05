<?php

namespace App\Http\Controllers;

use App\Services\Konveksi\KonveksiDocumentAndAiService;
use App\Services\Konveksi\KonveksiPilotReadService;
use DomainException;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Legacy-compatible GET /api/konveksi-document.php?id=DOC-...: serves a private Konveksi document
 * to a signed-in user of the Konveksi workspace. The file is never reachable by a public URL.
 */
final class KonveksiDocumentController
{
    private const MIME_TYPES = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    private const UNAVAILABLE = 'Dokumen tidak dapat diakses. Periksa sesi dan izin ruang kerja.';

    public function __construct(
        private readonly KonveksiDocumentAndAiService $documents,
        private readonly KonveksiPilotReadService $konveksi,
    ) {}

    public function __invoke(Request $request): Response
    {
        try {
            $this->konveksi->assertCompany($request->companyKey());
            $located = $this->documents->locate((string) $request->query('id', ''));
        } catch (InvalidArgumentException) {
            return $this->text(422);
        } catch (DomainException) {
            return $this->text(404);
        } catch (Throwable) {
            return $this->text(404);
        }
        if ($located === null) {
            return $this->text(404);
        }

        $mime = in_array($located['file']->mime_type, self::MIME_TYPES, true) ? $located['file']->mime_type : 'application/octet-stream';
        $name = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $located['file']->file_name);

        return response()->file($located['path'], [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.$name.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function text(int $status): Response
    {
        return response(self::UNAVAILABLE, $status, ['Content-Type' => 'text/plain; charset=utf-8']);
    }
}
