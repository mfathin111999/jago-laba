<?php

namespace App\Pilots\Marketplace;

use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Schema;

/**
 * Store lists for the Stores page. Only an explicit set of fields is exposed: tokens, credentials and
 * encrypted values never leave the server.
 */
final class MarketplaceStoreReadService
{
    private const STORE_FIELDS = [
        'id', 'platform', 'store_name', 'store_identifier', 'status', 'authorization_status', 'sync_mode',
        'default_admin_fee_pct', 'default_free_shipping_pct', 'default_payment_fee_pct', 'default_account_id',
        'last_synced_at', 'created_at',
    ];

    public function __construct(private readonly DatabaseManager $database) {}

    /**
     * @return array{stores: list<array<string, mixed>>, archived: list<array<string, mixed>>}
     */
    public function overview(string $companyKey): array
    {
        $connection = $this->database->connection();
        $rows = $connection->table('marketplace_stores')->where('company_key', $companyKey)->orderBy('platform')->orderBy('store_name')->get();

        $connections = [];
        if (Schema::hasTable('marketplace_oauth_connections')) {
            foreach ($connection->table('marketplace_oauth_connections')->where('company_key', $companyKey)->get() as $row) {
                $connections[(string) $row->store_id] = [
                    'status' => $row->status ?? null,
                    'authorized_at' => $row->authorized_at ?? null,
                    'token_expires_at' => $row->token_expires_at ?? null,
                    'last_successful_sync_at' => $row->last_successful_sync_at ?? null,
                ];
            }
        }

        $stores = [];
        $archived = [];
        foreach ($rows as $row) {
            $data = (array) $row;
            if (! empty($data['archived_at'])) {
                $archived[] = [
                    'id' => $data['id'], 'platform' => $data['platform'] ?? null, 'store_name' => $data['store_name'] ?? null,
                    'archived_at' => $data['archived_at'], 'archived_by' => $data['archived_by'] ?? null,
                ];

                continue;
            }

            $store = [];
            foreach (self::STORE_FIELDS as $field) {
                $store[$field] = $data[$field] ?? null;
            }
            $store['connection'] = $connections[(string) $data['id']] ?? null;
            $stores[] = $store;
        }

        return ['stores' => $stores, 'archived' => $archived];
    }
}
