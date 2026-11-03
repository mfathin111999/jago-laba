<?php

namespace App\Pilots\Marketplace;

use App\Audit\AuditLogger;
use App\Auth\LegacyUser;
use DomainException;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Throwable;

/**
 * Local-only permanent store erasure (port of the legacy MarketplaceStoreDeletion).
 * Never calls a marketplace API. Work runs in bounded, repeatable batches.
 * Database triggers and advisory locks are MySQL-only; other drivers (tests) skip them.
 */
final class MarketplaceStoreDeletionService
{
    public const OWNED = ['marketplace_ad_spends', 'marketplace_api_credentials', 'marketplace_auto_sync', 'marketplace_catalog_variants', 'marketplace_expenses', 'marketplace_fulfillment_actions', 'marketplace_hpp_changes', 'marketplace_hpp_history', 'marketplace_journal_entries', 'marketplace_payout_reconciliations', 'marketplace_price_actions', 'marketplace_product_ads', 'marketplace_return_claims', 'marketplace_shipping_documents', 'marketplace_shipping_document_audit', 'marketplace_sku_mappings', 'marketplace_stock_minimums', 'marketplace_stock_minimum_audit', 'marketplace_stock_snapshots', 'marketplace_sync_checkpoints', 'marketplace_sync_object_checks', 'marketplace_sync_jobs', 'marketplace_sync_records', 'marketplace_sync_workers', 'marketplace_withdrawals', 'marketplace_withdrawal_audit'];

    private const BATCH = 500;

    public function __construct(
        private readonly DatabaseManager $database,
        private readonly AuditLogger $audit,
    ) {}

    public function ready(): bool
    {
        return Schema::hasTable('marketplace_store_deletions');
    }

    /** Creates the deletion tables and (MySQL only) the write barriers. Idempotent. */
    public function install(): void
    {
        if (! Schema::hasTable('marketplace_store_deletions')) {
            Schema::create('marketplace_store_deletions', function ($table): void {
                $table->string('store_id', 191)->primary();
                $table->string('company_key', 191);
                $table->string('platform', 40);
                $table->char('identity_hash', 64);
                $table->string('actor', 191);
                $table->string('requested_at', 32);
                $table->string('status', 24)->default('pending');
                $table->longText('counts_json');
                $table->longText('files_json');
                $table->string('error_code', 60)->nullable();
                $table->dateTime('updated_at');
                $table->dateTime('completed_at')->nullable();
                $table->index(['company_key', 'identity_hash'], 'identity_lookup');
            });
        }
        if (! Schema::hasTable('marketplace_store_files')) {
            Schema::create('marketplace_store_files', function ($table): void {
                $table->string('store_id', 191);
                $table->string('relative_path', 240);
                $table->primary(['store_id', 'relative_path']);
            });
        }

        if (! $this->mysql()) {
            return;
        }
        $tables = array_merge(self::OWNED, ['jl_data_marketplace_sync_queue', 'jl_data_marketplace_product_mapping', 'marketplace_orders', 'marketplace_oauth_connections', 'marketplace_store_files']);
        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                $this->guard($table, 'NEW.store_id');
            }
        }
        $this->guard('marketplace_stores', 'NEW.id');
        foreach (['marketplace_api_capabilities', 'marketplace_integration_bindings'] as $table) {
            if (Schema::hasTable($table)) {
                $this->guard($table, '(SELECT store_id FROM marketplace_oauth_connections WHERE id=NEW.connection_id)', 'NOT EXISTS(SELECT 1 FROM marketplace_oauth_connections WHERE id=NEW.connection_id)');
            }
        }
        if (Schema::hasTable('marketplace_order_items')) {
            $this->guard('marketplace_order_items', '(SELECT store_id FROM marketplace_orders WHERE id=NEW.order_id)', 'NOT EXISTS(SELECT 1 FROM marketplace_orders WHERE id=NEW.order_id)');
        }
    }

    public function blocked(string $storeId): bool
    {
        return $this->ready() && $this->db()->table('marketplace_store_deletions')->where('store_id', $storeId)->exists();
    }

    /** @return array<string, mixed>|null */
    public function status(string $companyKey, string $storeId): ?array
    {
        $row = $this->db()->table('marketplace_store_deletions')
            ->where('store_id', $storeId)->where('company_key', $companyKey)
            ->first(['store_id', 'status', 'counts_json', 'error_code', 'requested_at', 'completed_at']);

        return $row ? $this->present((array) $row) : null;
    }

    /** @return list<array<string, mixed>> */
    public function recentStatuses(string $companyKey): array
    {
        return $this->db()->table('marketplace_store_deletions')
            ->where('company_key', $companyKey)->orderByDesc('requested_at')->limit(20)
            ->get(['store_id', 'platform', 'status', 'counts_json', 'error_code', 'requested_at', 'completed_at'])
            ->map(fn ($row) => $this->present((array) $row))->all();
    }

    /** @return list<string> Store ids whose erasure is queued, running or needs a retry. */
    public function unfinishedStoreIds(): array
    {
        if (! $this->ready()) {
            return [];
        }

        return $this->db()->table('marketplace_store_deletions')
            ->whereIn('status', ['pending', 'running', 'failed'])->orderBy('requested_at')
            ->pluck('store_id')->map(fn ($id) => (string) $id)->all();
    }

    /** @return array<string, mixed> */
    public function preview(string $companyKey, string $storeId): array
    {
        $store = $this->store($storeId, $companyKey);
        $counts = [];
        foreach ($this->plan() as $table => $where) {
            $counts[$table] = (int) $this->db()->selectOne('SELECT COUNT(*) AS c FROM '.$this->wrap($table).' WHERE '.$where, [$storeId])->c;
        }
        $counts['marketplace_stores'] = 1;

        $existing = 0;
        foreach ($this->files($storeId) as $file) {
            try {
                if (is_file($this->safePath($file))) {
                    $existing++;
                }
            } catch (Throwable) {
            }
        }

        $connectionRow = Schema::hasTable('marketplace_oauth_connections')
            ? $this->db()->table('marketplace_oauth_connections')->where('store_id', $storeId)->where('company_key', $companyKey)->orderByDesc('id')->first()
            : null;
        $environment = $this->environment($store, $connectionRow);

        return [
            'store_id' => $storeId,
            'name' => $store->store_name,
            'platform' => $store->platform,
            'external_id' => $connectionRow->external_store_id ?? $store->store_identifier,
            'environment' => $environment,
            'counts' => $counts,
            'file_count' => $existing,
            'file_types' => ['Dokumen PDF dan daftar pengemasan di database', 'Berkas terdaftar dan log worker'],
            'job' => $this->status($companyKey, $storeId),
        ];
    }

    /** @return array<string, mixed> */
    public function request(LegacyUser $user, string $companyKey, string $storeId, string $confirmation): array
    {
        if ($old = $this->status($companyKey, $storeId)) {
            return $old;
        }
        $store = $this->store($storeId, $companyKey);
        if (! hash_equals((string) $store->store_name, trim($confirmation))) {
            throw new DomainException('Nama konfirmasi harus sama dengan nama toko.');
        }
        $this->plan();
        $this->assertGuards();
        $actor = $user->getLegacyUserId();

        $this->db()->transaction(function () use ($storeId, $companyKey, $actor, $confirmation, $user): void {
            $store = $this->store($storeId, $companyKey, true);
            if ($this->status($companyKey, $storeId)) {
                return;
            }
            if (! hash_equals((string) $store->store_name, trim($confirmation))) {
                throw new DomainException('Nama toko berubah. Muat ulang pratinjau.');
            }
            $now = now();
            $this->db()->table('marketplace_stores')->where('id', $storeId)->where('company_key', $companyKey)->update([
                'archived_at' => $store->archived_at ?? $now,
                'archived_by' => $actor,
                'status' => 'Terputus',
                'authorization_status' => 'disconnected',
                'api_access_token' => null,
                'updated_at' => $now,
            ]);
            if (Schema::hasTable('marketplace_auto_sync')) {
                $this->db()->table('marketplace_auto_sync')->where('store_id', $storeId)->update(['enabled' => 0]);
            }
            if (Schema::hasTable('marketplace_sync_jobs')) {
                $this->db()->table('marketplace_sync_jobs')->where('store_id', $storeId)->update([
                    'status' => 'cancelled', 'finished_at' => $now, 'last_error_code' => 'STORE_DELETING', 'lease_token' => null, 'lease_until' => null,
                ]);
            }
            if (Schema::hasTable('marketplace_oauth_connections')) {
                $this->db()->table('marketplace_oauth_connections')->where('store_id', $storeId)->update([
                    'status' => 'disconnected', 'access_token_encrypted' => null, 'refresh_token_encrypted' => null,
                ]);
            }

            $files = $this->files($storeId);
            $hash = $this->identity($store);
            $requestedAt = $now->format('Y-m-d H:i:s.u');
            // Durable deletion intent (not a data backup); required before the irreversible cleanup is committed.
            $this->writeLedger(['store_id' => $storeId, 'company_key' => $companyKey, 'platform' => $store->platform, 'identity_hash' => $hash, 'requested_at' => $requestedAt, 'actor' => $actor]);
            $this->db()->table('marketplace_store_deletions')->insert([
                'store_id' => $storeId, 'company_key' => $companyKey, 'platform' => $store->platform, 'identity_hash' => $hash,
                'actor' => $actor, 'requested_at' => $requestedAt, 'status' => 'pending', 'counts_json' => '{}',
                'files_json' => json_encode($files, JSON_THROW_ON_ERROR), 'updated_at' => $now,
            ]);
            $this->audit->record($user, 'Marketplace', 'REQUEST_STORE_DELETION', $storeId, $storeId,
                ['store_name' => (string) $store->store_name, 'platform' => (string) $store->platform], ['status' => 'pending'], 'Penghapusan permanen toko diminta.');
        });

        return $this->status($companyKey, $storeId) ?? throw new RuntimeException('DELETION_NOT_RECORDED');
    }

    /**
     * One bounded batch, safe to repeat after a crash. Returns true when the job is finished
     * (or absent) and false when more work remains or another worker holds the lock.
     */
    public function step(string $storeId): bool
    {
        $lock = 'jl-delete-'.hash('sha1', $storeId);
        if (! $this->acquire($lock)) {
            return false;
        }
        $held = [];
        try {
            $job = $this->db()->table('marketplace_store_deletions')->where('store_id', $storeId)->first();
            if (! $job || $job->status === 'completed') {
                return true;
            }
            foreach ($this->workerLocks($job, $storeId) as $workerLock) {
                if (! $this->acquire($workerLock)) {
                    return false;
                }
                $held[] = $workerLock;
            }
            $this->assertGuards();
            $counts = json_decode((string) $job->counts_json, true) ?: [];

            foreach ($this->plan() as $table => $where) {
                $deleted = $this->db()->transaction(function () use ($table, $where, $storeId, &$counts): int {
                    $limit = $this->mysql() ? ' LIMIT '.self::BATCH : '';
                    $n = $this->db()->delete('DELETE FROM '.$this->wrap($table).' WHERE '.$where.$limit, [$storeId]);
                    $counts[$table] = ($counts[$table] ?? 0) + $n;
                    $this->db()->table('marketplace_store_deletions')->where('store_id', $storeId)->update([
                        'status' => 'running', 'counts_json' => json_encode($counts, JSON_THROW_ON_ERROR), 'error_code' => null, 'updated_at' => now(),
                    ]);

                    return $n;
                });
                if ($deleted > 0) {
                    return false;
                }
            }

            $this->cleanPending($job);
            foreach (json_decode((string) $job->files_json, true) ?: [] as $relative) {
                $path = $this->safePath($relative);
                if (is_file($path) && ! unlink($path)) {
                    throw new RuntimeException('FILE_CLEANUP_FAILED');
                }
                if (file_exists($path)) {
                    throw new RuntimeException('FILE_CLEANUP_FAILED');
                }
            }

            // The parent row stays until every child row and file is verified absent.
            $this->db()->transaction(function () use ($storeId, $job, &$counts): void {
                foreach ($this->plan() as $table => $where) {
                    if ((int) $this->db()->selectOne('SELECT COUNT(*) AS c FROM '.$this->wrap($table).' WHERE '.$where, [$storeId])->c > 0) {
                        throw new RuntimeException('VERIFICATION_FAILED');
                    }
                }
                $n = $this->db()->table('marketplace_stores')->where('id', $storeId)->where('company_key', $job->company_key)->whereNotNull('archived_at')->delete();
                $counts['marketplace_stores'] = ($counts['marketplace_stores'] ?? 0) + $n;
                $this->db()->table('marketplace_store_deletions')->where('store_id', $storeId)->update([
                    'status' => 'completed', 'counts_json' => json_encode($counts, JSON_THROW_ON_ERROR), 'files_json' => '[]',
                    'error_code' => null, 'completed_at' => now(), 'updated_at' => now(),
                ]);
            });

            return true;
        } catch (Throwable $e) {
            $this->db()->table('marketplace_store_deletions')->where('store_id', $storeId)->update([
                'status' => 'failed', 'error_code' => 'CLEANUP_RETRY_REQUIRED', 'updated_at' => now(),
            ]);
            throw $e;
        } finally {
            foreach (array_reverse($held) as $workerLock) {
                $this->release($workerLock);
            }
            $this->release($lock);
        }
    }

    /** Blocks re-authorizing a store identity that is being (or was just) erased. */
    public function guardAuthorization(string $companyKey, string $platform, string $externalId, ?string $authorizationStarted): bool
    {
        if (! $this->ready()) {
            return false;
        }
        $row = $this->db()->table('marketplace_store_deletions')
            ->where('company_key', $companyKey)->where('identity_hash', hash('sha256', $platform.':'.$externalId))
            ->orderByDesc('requested_at')->lockForUpdate()->first(['status', 'requested_at']);
        if (! $row) {
            return false;
        }
        if ($row->status !== 'completed' || empty($authorizationStarted) || strcmp($authorizationStarted, (string) $row->requested_at) <= 0) {
            throw new DomainException('Toko sedang atau sudah dihapus. Mulai otorisasi baru setelah penghapusan selesai.');
        }

        return true;
    }

    // ---------------------------------------------------------------- internals

    private function db(): Connection
    {
        return $this->database->connection();
    }

    private function mysql(): bool
    {
        return in_array($this->db()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function wrap(string $table): string
    {
        return $this->db()->getQueryGrammar()->wrapTable($table);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function present(array $row): array
    {
        $row['counts'] = json_decode((string) ($row['counts_json'] ?? ''), true) ?: [];
        unset($row['counts_json']);

        return $row;
    }

    private function store(string $storeId, string $companyKey, bool $lock = false): object
    {
        $query = $this->db()->table('marketplace_stores')->where('id', $storeId)->where('company_key', $companyKey);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first() ?? throw new DomainException('Toko milik perusahaan ini tidak ditemukan.');
    }

    private function identity(object $store): string
    {
        return hash('sha256', $store->platform.':'.$store->store_identifier);
    }

    /** @return array<string, string> table => WHERE clause (single ? binding is the store id) */
    private function plan(): array
    {
        $plan = [
            'marketplace_order_items' => 'order_id IN (SELECT id FROM marketplace_orders WHERE store_id=?)',
            'marketplace_api_capabilities' => 'connection_id IN (SELECT id FROM marketplace_oauth_connections WHERE store_id=?)',
            'marketplace_integration_bindings' => 'connection_id IN (SELECT id FROM marketplace_oauth_connections WHERE store_id=?)',
        ];
        foreach (self::OWNED as $table) {
            $plan[$table] = 'store_id=?';
        }
        foreach (['jl_data_marketplace_sync_queue', 'jl_data_marketplace_product_mapping', 'marketplace_orders', 'marketplace_oauth_connections', 'marketplace_store_files'] as $table) {
            $plan[$table] = 'store_id=?';
        }

        // Fail closed: any other table carrying store_id must be reviewed before erasure is allowed.
        $allowed = array_merge(array_keys($plan), ['marketplace_stores', 'marketplace_store_deletions']);
        foreach (Schema::getTableListing(null, false) as $table) {
            if (! in_array($table, $allowed, true) && Schema::hasColumn($table, 'store_id') && ! $this->isExemptTable($table)) {
                throw new DomainException('Relasi toko baru perlu diaudit sebelum penghapusan: '.$table);
            }
        }
        if ($this->mysql()) {
            $refs = $this->db()->select('SELECT TABLE_NAME AS t, REFERENCED_TABLE_NAME AS r FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL');
            foreach ($refs as $ref) {
                if (in_array($ref->r, $allowed, true) && ! in_array($ref->t, $allowed, true)) {
                    throw new DomainException('Relasi turunan perlu diaudit: '.$ref->t);
                }
            }
        }

        return array_filter($plan, fn ($where, $table) => Schema::hasTable($table), ARRAY_FILTER_USE_BOTH);
    }

    /** Framework tables that legitimately never hold marketplace store data. */
    private function isExemptTable(string $table): bool
    {
        return in_array($table, ['jobs', 'failed_jobs', 'job_batches', 'cache', 'cache_locks', 'sessions', 'migrations'], true);
    }

    /** @return list<string> */
    private function files(string $storeId): array
    {
        $files = Schema::hasTable('marketplace_store_files')
            ? $this->db()->table('marketplace_store_files')->where('store_id', $storeId)->pluck('relative_path')->all()
            : [];
        if (Schema::hasTable('marketplace_sync_workers') && Schema::hasColumn('marketplace_sync_workers', 'launch_key')) {
            foreach ($this->db()->table('marketplace_sync_workers')->where('store_id', $storeId)->pluck('launch_key') as $key) {
                if (preg_match('/^(catalog-)?[a-f0-9]{32}$/', (string) $key)) {
                    foreach (['out.log', 'err.log', 'launch.log', 'launch.err.log'] as $suffix) {
                        $files[] = 'sync-worker/'.$key.'.'.$suffix;
                    }
                }
            }
        }

        return array_values(array_unique($files));
    }

    private function environment(object $store, ?object $connection): string
    {
        $environment = null;
        if ($connection && Schema::hasTable('marketplace_integration_bindings')) {
            $environment = match ((string) $this->db()->table('marketplace_integration_bindings')->where('connection_id', $connection->id)->value('environment')) {
                'live' => 'Live', 'sandbox' => 'Sandbox', 'development' => 'Development', default => null,
            };
        }
        if ($environment !== null) {
            return $environment;
        }
        $isSandbox = str_contains(strtoupper((string) ($store->store_name ?? '')), 'SANDBOX')
            || str_contains(strtoupper((string) ($connection->external_store_name ?? '')), 'SANDBOX');

        return $isSandbox ? 'Sandbox' : 'Live';
    }

    private function cleanPending(object $job): void
    {
        if (! Schema::hasTable('marketplace_oauth_pending')) {
            return;
        }
        $rows = $this->db()->table('marketplace_oauth_pending')
            ->where('company_key', $job->company_key)->where('platform', $job->platform)
            ->where('created_at', '<=', $job->requested_at)->get(['id', 'shops_json']);
        foreach ($rows as $row) {
            $payload = json_decode((string) $row->shops_json, true) ?: [];
            $shops = $payload['shops'] ?? [];
            $kept = array_values(array_filter($shops, fn ($s) => hash('sha256', $job->platform.':'.($s['id'] ?? '')) !== $job->identity_hash));
            if (count($kept) === count($shops)) {
                continue;
            }
            if ($kept === []) {
                $this->db()->table('marketplace_oauth_pending')->where('id', $row->id)->delete();
            } else {
                $payload['shops'] = $kept;
                $this->db()->table('marketplace_oauth_pending')->where('id', $row->id)->update(['shops_json' => json_encode($payload)]);
            }
        }
    }

    private function safePath(string $relative): string
    {
        if (! preg_match('~^(sync-worker|private/marketplace|cache/marketplace|temporary/marketplace)/[a-zA-Z0-9_./-]+$~D', $relative) || str_contains($relative, '..')) {
            throw new RuntimeException('UNSAFE_FILE_PATH');
        }
        $root = realpath(storage_path());
        if (! $root) {
            throw new RuntimeException('STORAGE_UNAVAILABLE');
        }
        $path = $root.'/'.$relative;
        $parent = dirname($path);
        while (! file_exists($parent) && $parent !== dirname($parent)) {
            $parent = dirname($parent);
        }
        $resolved = realpath($parent);
        $norm = fn (string $p) => strtolower(str_replace('\\', '/', $p));
        if (! $resolved || ! str_starts_with($norm($resolved).'/', $norm($root).'/') || is_link($path)) {
            throw new RuntimeException('UNSAFE_FILE_PATH');
        }
        for ($p = dirname($path); strlen($p) > strlen($root); $p = dirname($p)) {
            if (is_link($p)) {
                throw new RuntimeException('UNSAFE_FILE_PATH');
            }
        }

        return $path;
    }

    /** @param array<string, mixed> $record */
    private function writeLedger(array $record): void
    {
        $directory = (string) (config('jago.store_deletion.ledger_dir') ?: storage_path('app/private/deletions'));
        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('DELETION_LEDGER_UNAVAILABLE');
        }
        $path = $directory.'/'.hash('sha256', $record['company_key'].':'.$record['store_id']).'.json';
        if (file_put_contents($path, json_encode($record, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw new RuntimeException('DELETION_LEDGER_UNAVAILABLE');
        }
    }

    /** @return list<string> */
    private function workerLocks(object $job, string $storeId): array
    {
        $db = $this->mysql() ? (string) $this->db()->selectOne('SELECT DATABASE() AS d')->d : '';

        return [
            'jl-sync-'.substr(hash('sha256', $job->company_key.':'.$storeId), 0, 50),
            'jl-read-store-'.sha1($db.':'.$job->company_key.':'.$storeId),
        ];
    }

    private function acquire(string $name): bool
    {
        return ! $this->mysql() || (bool) $this->db()->selectOne('SELECT GET_LOCK(?, 0) AS l', [$name])->l;
    }

    private function release(string $name): void
    {
        if ($this->mysql()) {
            $this->db()->select('SELECT RELEASE_LOCK(?)', [$name]);
        }
    }

    private function guard(string $table, string $id, string $missing = 'FALSE'): void
    {
        foreach (['INSERT', 'UPDATE'] as $event) {
            $name = $this->triggerName($table, $event);
            if ($this->db()->selectOne('SELECT COUNT(*) AS c FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?', [$name])->c) {
                continue;
            }
            $this->db()->unprepared("CREATE TRIGGER `$name` BEFORE $event ON `$table` FOR EACH ROW BEGIN
                IF $missing OR EXISTS(SELECT 1 FROM marketplace_store_deletions WHERE store_id=$id) THEN
                    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='STORE_PERMANENTLY_DELETING';
                END IF; END");
        }
    }

    private function triggerName(string $table, string $event): string
    {
        return 'jl_erase_'.substr(hash('sha256', $table.$event), 0, 24);
    }

    private function assertGuards(): void
    {
        if (! $this->mysql()) {
            return;
        }
        $tables = array_merge(array_keys($this->plan()), ['marketplace_stores']);
        $missing = fn () => collect($tables)->crossJoin(['INSERT', 'UPDATE'])->contains(
            fn ($pair) => ! $this->db()->selectOne('SELECT COUNT(*) AS c FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME=?', [$this->triggerName($pair[0], $pair[1])])->c
        );
        if ($missing()) {
            $this->install();
        }
        if ($missing()) {
            throw new DomainException('Pengaman penghapusan belum terpasang. Jalankan migrasi penghapusan toko.');
        }
    }
}
