<?php

namespace App\Services\Konveksi;

use App\Auth\LegacyUser;
use App\Exceptions\KonveksiRequestRejected;
use Illuminate\Http\Request;
use JsonException;

/**
 * Wraps every Konveksi write so it is serialised, idempotent and safe against stale screens
 * (port of the legacy KonveksiOperationGate):
 *
 *  - one write at a time, using the same advisory lock name as the legacy app so both serialise against each other;
 *  - an X-Idempotency-Key is required and a repeated key replays the stored response instead of running again;
 *    reusing a key with different content is refused;
 *  - after a data reset, writes need the current X-JL-Reset-Epoch;
 *  - syncTab/syncMultiTabs need the X-JL-Collection-Versions the client loaded and are refused (409) if the data changed.
 *
 * The receipts table belongs to the Konveksi database; without it every write is refused rather than run unprotected.
 */
final class KonveksiOperationGate
{
    public const CONFLICT = 409;

    private const KEY_PATTERN = '/^[A-Za-z0-9_.:-]{16,120}$/';

    private const LOCK_WAIT_SECONDS = 10;

    public function __construct(
        private readonly KonveksiConnection $konveksi,
        private readonly KonveksiPilotReadService $reader,
    ) {}

    /**
     * @param  array<int, mixed>  $args
     * @param  callable(): array<string, mixed>  $operation
     * @return array<string, mixed>
     */
    public function run(LegacyUser $user, string $companyKey, string $method, array $args, Request $request, callable $operation): array
    {
        $this->reader->assertCompany($companyKey);
        $connection = $this->konveksi->connection();
        if (! $connection->getSchemaBuilder()->hasTable('konveksi_request_receipts')) {
            throw new KonveksiRequestRejected('Skema idempotensi Konveksi belum dipasang oleh administrator server.', 'IDEMPOTENCY_SCHEMA_MISSING', 503);
        }

        $lock = 'jl-konveksi-write-'.sha1($connection->getDatabaseName());
        if (! $this->acquire($lock)) {
            throw new KonveksiRequestRejected('Transaksi lain sedang diproses. Coba kembali.', 'WRITE_IN_PROGRESS', self::CONFLICT);
        }

        try {
            $epoch = $this->reader->resetEpoch();
            if ($epoch !== '0' && ! hash_equals($epoch, (string) $request->header('X-JL-Reset-Epoch', ''))) {
                throw new KonveksiRequestRejected('Data operasional telah dikosongkan. Muat ulang halaman sebelum menyimpan.', 'RESET_EPOCH_MISMATCH', self::CONFLICT);
            }

            $key = (string) $request->header('X-Idempotency-Key', '');
            if (preg_match(self::KEY_PATTERN, $key) !== 1) {
                throw new KonveksiRequestRejected('Identitas permintaan belum tersedia. Muat ulang halaman.', 'IDEMPOTENCY_KEY_REQUIRED', 422);
            }
            $requestKey = hash('sha256', $companyKey.'|'.$user->getLegacyUserId().'|'.$key);
            $hash = hash('sha256', json_encode([$method, $args], JSON_THROW_ON_ERROR));

            return $connection->transaction(function () use ($connection, $requestKey, $hash, $method, $args, $request, $operation, $companyKey): array {
                $receipt = $connection->table('konveksi_request_receipts')->where('request_key', $requestKey)->first();
                if ($receipt !== null) {
                    if (! hash_equals((string) $receipt->request_hash, $hash)) {
                        throw new KonveksiRequestRejected('Identitas permintaan sudah dipakai untuk isi berbeda.', 'IDEMPOTENCY_KEY_REUSED', self::CONFLICT);
                    }

                    return $this->decode((string) $receipt->response_json);
                }

                if (in_array($method, ['syncTab', 'syncMultiTabs'], true)) {
                    $this->assertFreshVersions($method, $args, $request);
                }

                $result = $operation();
                $result['__meta'] = ['versions' => $this->reader->collectionVersions(), 'domain' => 'konveksi'];

                $connection->table('konveksi_request_receipts')->insert([
                    'request_key' => $requestKey, 'company_key' => $companyKey, 'request_hash' => $hash,
                    'response_json' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now(),
                ]);

                return $result;
            });
        } finally {
            $this->release($lock);
        }
    }

    /** @param array<int, mixed> $args */
    private function assertFreshVersions(string $method, array $args, Request $request): void
    {
        try {
            $sent = json_decode((string) $request->header('X-JL-Collection-Versions', '{}'), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new KonveksiRequestRejected('Versi data tidak valid. Muat ulang data sebelum menyimpan.', 'COLLECTION_VERSIONS_INVALID', 422);
        }
        $sent = is_array($sent) ? $sent : [];

        if ($method === 'syncTab') {
            $names = [(string) ($args[0] ?? '')];
        } else {
            $payload = is_array($args[0] ?? null) ? $args[0] : (json_decode((string) ($args[0] ?? '{}'), true) ?? []);
            $names = array_map('strval', array_keys(is_array($payload) ? $payload : []));
        }

        foreach ($names as $name) {
            $current = $this->reader->collectionVersions([$name])[$name] ?? null;
            if ($current === null || ! isset($sent[$name]) || ! hash_equals($current, (string) $sent[$name])) {
                throw new KonveksiRequestRejected('Data '.$name.' telah berubah atau belum dimuat. Muat ulang data sebelum menyimpan.', 'COLLECTION_STALE', self::CONFLICT);
            }
        }
    }

    /** @return array<string, mixed> */
    private function decode(string $json): array
    {
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function acquire(string $name): bool
    {
        return ! $this->mysql() || (bool) $this->konveksi->connection()->selectOne('SELECT GET_LOCK(?, ?) AS l', [$name, self::LOCK_WAIT_SECONDS])->l;
    }

    private function release(string $name): void
    {
        if ($this->mysql()) {
            $this->konveksi->connection()->select('SELECT RELEASE_LOCK(?)', [$name]);
        }
    }

    private function mysql(): bool
    {
        return in_array($this->konveksi->connection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
}
