<?php

namespace App\Services\Marketplace;

use App\Auth\AuthenticatedUser;
use DomainException;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Schema;

/**
 * Confirms a previously staged withdrawal import. Local database write only; no provider call.
 * Rows are upserted by (company, store, external id) and every change is recorded in the audit table.
 */
final class MarketplaceWithdrawalImportService
{
    public const SESSION_KEY = 'marketplace.withdrawal_preview';

    private const IDENTITY = ['company_key', 'store_id', 'external_id'];

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly MarketplaceWithdrawalPreviewService $preview,
    ) {}

    /**
     * @return array{inserted: int, updated: int, unchanged: int}
     */
    public function confirm(Session $session, AuthenticatedUser $user, string $companyKey, string $key): array
    {
        $stage = $session->get(self::SESSION_KEY, []);
        if (! is_array($stage)
            || ($stage['company_key'] ?? '') !== $companyKey
            || ($stage['actor'] ?? '') !== $this->preview->actorKey($user)
            || (int) ($stage['expires_at'] ?? 0) < time()
            || ! hash_equals((string) ($stage['key'] ?? ''), $key)
        ) {
            throw new DomainException('Pratinjau kedaluwarsa. Unggah kembali laporan.');
        }

        $counts = $this->import($user, $companyKey, (string) $stage['store_id'], (array) $stage['rows'], (string) $stage['hash']);
        $session->forget(self::SESSION_KEY);

        return $counts;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{inserted: int, updated: int, unchanged: int}
     */
    private function import(AuthenticatedUser $user, string $companyKey, string $storeId, array $rows, string $sourceHash): array
    {
        $connection = $this->database->connection();
        $actor = $this->preview->actorKey($user);
        $columns = Schema::getColumnListing('marketplace_withdrawals');
        $counts = ['inserted' => 0, 'updated' => 0, 'unchanged' => 0];

        $connection->transaction(function () use ($connection, $companyKey, $storeId, $rows, $sourceHash, $actor, $columns, &$counts): void {
            $store = $connection->table('marketplace_stores')
                ->where('id', $storeId)->where('company_key', $companyKey)->whereNull('archived_at')
                ->lockForUpdate()->first();
            if ($store === null) {
                throw new DomainException('Toko marketplace tidak ditemukan dalam perusahaan aktif.');
            }

            $now = now();
            foreach ($rows as $row) {
                $identity = ['company_key' => $companyKey, 'store_id' => $storeId, 'external_id' => (string) $row['external_id']];
                $old = $connection->table('marketplace_withdrawals')->where($identity)->lockForUpdate()->first(['id', 'row_hash']);
                if ($old !== null && $old->row_hash === $row['row_hash']) {
                    $counts['unchanged']++;

                    continue;
                }

                // Only known columns are written; the stored identity always wins over staged values.
                $data = array_intersect_key($row, array_flip($columns)) + [
                    'source_kind' => 'import', 'source_hash' => $sourceHash, 'imported_by' => $actor, 'imported_at' => $now, 'updated_at' => $now,
                ];
                $data = array_diff_key($data, array_flip(['id'])) + $identity;
                $data = array_merge($data, $identity);

                if ($old === null) {
                    $connection->table('marketplace_withdrawals')->insert($data);
                    $counts['inserted']++;
                } else {
                    $connection->table('marketplace_withdrawals')->where('id', $old->id)->update(array_diff_key($data, array_flip(self::IDENTITY)));
                    $counts['updated']++;
                }

                $connection->table('marketplace_withdrawal_audit')->insert([
                    'company_key' => $companyKey, 'store_id' => $storeId, 'external_id' => $identity['external_id'], 'actor' => $actor,
                    'previous_hash' => $old->row_hash ?? null, 'new_hash' => $row['row_hash'], 'source_hash' => $sourceHash, 'created_at' => $now,
                ]);
            }
        });

        return $counts;
    }
}
