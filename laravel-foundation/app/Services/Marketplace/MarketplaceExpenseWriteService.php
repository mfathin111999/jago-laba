<?php

namespace App\Services\Marketplace;

use App\Audit\AuditLogger;
use App\Auth\LegacyUser;
use App\Exceptions\IdempotencyConflict;
use App\Exceptions\ResourceNotFound;
use App\Exceptions\StoreForbidden;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

final class MarketplaceExpenseWriteService
{
    public const IDEMPOTENCY_MODULE = 'marketplace.expense';

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function create(LegacyUser $user, string $companyKey, array $data, string $idempotencyKey): array
    {
        $companyKey = trim($companyKey);
        $idempotencyKey = trim($idempotencyKey);
        if ($companyKey === '' || strlen($companyKey) > 120) {
            throw new RuntimeException('Company scope tidak valid.');
        }
        if ($idempotencyKey === '' || strlen($idempotencyKey) > 255) {
            throw new RuntimeException('Idempotency key tidak valid.');
        }

        $fingerprint = $this->fingerprint($data);
        $connection = $this->database->connection();

        $this->assertScopedIdempotencySchema();

        try {
            return $connection->transaction(function () use ($connection, $user, $companyKey, $data, $idempotencyKey, $fingerprint): array {
                $existing = $connection->table('idempotency_keys')
                    ->where('company_key', $companyKey)
                    ->where('module', self::IDEMPOTENCY_MODULE)
                    ->where('idempotency_key', $idempotencyKey)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    return $this->replayExisting($existing, $fingerprint);
                }

                $store = $connection->table('marketplace_stores')
                    ->where('id', (string) $data['store_id'])
                    ->where('company_key', $companyKey)
                    ->whereNull('archived_at')
                    ->first();

                if ($store === null) {
                    throw new StoreForbidden;
                }

                $expenseId = $connection->table('marketplace_expenses')->insertGetId([
                    'store_id' => (string) $data['store_id'],
                    'description' => (string) $data['description'],
                    'vendor_name' => (string) $data['vendor_name'],
                    'expense_date' => (string) $data['expense_date'],
                    'category' => (string) $data['category'],
                    'amount' => (float) $data['amount'],
                    'payment_method' => (string) $data['payment_method'],
                    'account_id' => $data['account_id'] ?? null,
                    'notes' => (string) ($data['notes'] ?? ''),
                    'affects_profit' => (int) $data['affects_profit'],
                    'is_cashflow_only' => (int) $data['is_cashflow_only'],
                    'created_at' => now(),
                ]);

                $response = [
                    'success' => true,
                    'expense_id' => (int) $expenseId,
                    'message' => 'Pengeluaran marketplace berhasil dicatat.',
                    'duplicate' => false,
                    'idempotency_key' => $idempotencyKey,
                    'request_fingerprint' => $fingerprint,
                ];

                $this->audit->record(
                    $user,
                    'Marketplace',
                    'CREATE_EXPENSE',
                    (string) $expenseId,
                    $idempotencyKey,
                    null,
                    [
                        'company_key' => $companyKey,
                        'store_id' => (string) $data['store_id'],
                        'amount' => (float) $data['amount'],
                        'category' => (string) $data['category'],
                        'expense_date' => (string) $data['expense_date'],
                        'affects_profit' => (int) $data['affects_profit'],
                        'is_cashflow_only' => (int) $data['is_cashflow_only'],
                    ],
                    'Marketplace expense write pilot',
                );

                $connection->table('idempotency_keys')->insert([
                    'company_key' => $companyKey,
                    'idempotency_key' => $idempotencyKey,
                    'module' => self::IDEMPOTENCY_MODULE,
                    'reference_id' => (string) $expenseId,
                    'response_json' => json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                ]);

                return $response;
            });
        } catch (QueryException $exception) {
            // A concurrent request can win the composite unique key after the
            // initial lookup. Re-read that scoped receipt and return the
            // winner instead of creating a second expense or a 500 response.
            if (! $this->isUniqueViolation($exception)) {
                throw $exception;
            }

            $existing = $connection->table('idempotency_keys')
                ->where('company_key', $companyKey)
                ->where('module', self::IDEMPOTENCY_MODULE)
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing === null) {
                throw $exception;
            }

            return $this->replayExisting($existing, $fingerprint);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function delete(LegacyUser $user, string $companyKey, string $storeId, int $expenseId): array
    {
        $companyKey = trim($companyKey);
        $storeId = trim($storeId);
        if ($companyKey === '' || strlen($companyKey) > 120) {
            throw new RuntimeException('Company scope tidak valid.');
        }
        if ($storeId === '' || strlen($storeId) > 50) {
            throw new RuntimeException('Store ID tidak valid.');
        }

        $connection = $this->database->connection();

        return $connection->transaction(function () use ($connection, $user, $companyKey, $storeId, $expenseId): array {
            $store = $connection->table('marketplace_stores')
                ->where('id', $storeId)
                ->where('company_key', $companyKey)
                ->whereNull('archived_at')
                ->first();

            if ($store === null) {
                throw new StoreForbidden;
            }

            $expense = $connection->table('marketplace_expenses')
                ->where('id', $expenseId)
                ->where('store_id', $storeId)
                ->lockForUpdate()
                ->first();

            if ($expense === null) {
                throw new ResourceNotFound('Pengeluaran marketplace tidak ditemukan.', 'EXPENSE_NOT_FOUND');
            }

            $connection->table('marketplace_expenses')
                ->where('id', $expenseId)
                ->where('store_id', $storeId)
                ->delete();

            $this->audit->record(
                $user,
                'Marketplace',
                'DELETE_EXPENSE',
                (string) $expenseId,
                'EXP-MKT-'.$expenseId,
                [
                    'company_key' => $companyKey,
                    'store_id' => $storeId,
                    'amount' => (float) $expense->amount,
                    'category' => (string) $expense->category,
                    'description' => (string) $expense->description,
                    'expense_date' => (string) $expense->expense_date,
                ],
                null,
                'Marketplace expense delete',
            );

            return [
                'success' => true,
                'message' => 'Pengeluaran marketplace berhasil dihapus.',
                'expense_id' => $expenseId,
                'store_id' => $storeId,
            ];
        });
    }

    private function assertScopedIdempotencySchema(): void
    {
        if (! Schema::hasTable('idempotency_keys')) {
            throw new RuntimeException('Tabel idempotency belum tersedia.');
        }

        foreach (['company_key', 'module', 'idempotency_key'] as $column) {
            if (! Schema::hasColumn('idempotency_keys', $column)) {
                throw new RuntimeException('Schema idempotency belum di-upgrade ke scope company/module.');
            }
        }

        if (! Schema::hasIndex('idempotency_keys', ['company_key', 'module', 'idempotency_key'], 'unique')) {
            throw new RuntimeException('Unique constraint idempotency company/module belum tersedia.');
        }
    }

    /**
     * @param  object{response_json?: string|null}  $existing
     * @return array<string, mixed>
     */
    private function replayExisting(object $existing, string $fingerprint): array
    {
        $stored = json_decode((string) ($existing->response_json ?? ''), true);
        if (! is_array($stored) || ($stored['request_fingerprint'] ?? null) !== $fingerprint) {
            throw new IdempotencyConflict('Idempotency key sudah digunakan untuk payload berbeda dalam scope company/module ini.');
        }

        $stored['duplicate'] = true;

        return $stored;
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $code = (string) $exception->getCode();
        $message = strtolower($exception->getMessage());

        return in_array($code, ['23000', '23505'], true)
            || str_contains($message, 'duplicate entry')
            || str_contains($message, 'unique constraint');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fingerprint(array $data): string
    {
        $payload = [
            'store_id' => (string) ($data['store_id'] ?? ''),
            'description' => (string) ($data['description'] ?? ''),
            'vendor_name' => (string) ($data['vendor_name'] ?? ''),
            'expense_date' => (string) ($data['expense_date'] ?? ''),
            'category' => (string) ($data['category'] ?? ''),
            'amount' => (string) ($data['amount'] ?? ''),
            'payment_method' => (string) ($data['payment_method'] ?? ''),
            'account_id' => $data['account_id'] ?? null,
            'notes' => (string) ($data['notes'] ?? ''),
            'affects_profit' => (int) ($data['affects_profit'] ?? 0),
            'is_cashflow_only' => (int) ($data['is_cashflow_only'] ?? 0),
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
