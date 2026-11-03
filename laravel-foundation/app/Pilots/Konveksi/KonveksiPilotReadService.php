<?php

namespace App\Pilots\Konveksi;

use DomainException;
use Illuminate\Database\Connection;
use RuntimeException;
use Throwable;

final class KonveksiPilotReadService
{
    public const COLLECTIONS = [
        'rekening', 'produk', 'bahan', 'bahan_hpp', 'hpp_model_bom', 'hpp_history', 'bahan_hpp_history',
        'pegawai', 'supplier', 'pembelian', 'pembelian_item', 'potong', 'potong_item', 'jahit', 'jahit_item',
        'keuangan', 'kasbon', 'profil', 'distribusi', 'pengguna', 'hak_akses', 'master_stok', 'mutasi_stok',
        'pembelian_vendor', 'riwayat_bayar_hutang', 'hutang_konveksi', 'riwayat_bayar_hutang_konveksi',
        'template_dokumen', 'penjualan', 'penjualan_item', 'penjualan_koreksi', 'material_alias', 'quality_control',
        'mutasi_stok_konveksi', 'hutangsupplier', 'hutang_supplier',
    ];

    public function __construct(private readonly KonveksiConnection $konveksi) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(string $companyKey, string $tab = 'dashboard'): array
    {
        $this->assertCompany($companyKey);
        $collections = $this->allKnown();

        return [
            'read_only' => true,
            'workspace' => 'konveksi',
            'scope' => ['company_key' => $companyKey, 'database_isolated' => (bool) config('jago.konveksi_database_isolated', false)],
            'tab' => $tab,
            'collections' => $collections,
            'data' => $collections,
            'query' => [
                'method' => 'loadSemuaData',
                'args' => [],
                'tables' => self::COLLECTIONS,
                'order_by' => ['_row_order', '_row_id'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function allKnown(): array
    {
        $out = [];
        foreach (self::COLLECTIONS as $key) {
            $out[$key] = $this->get($key);
        }

        $missing = 0;
        $negative = 0;
        $identities = [];
        $duplicates = 0;
        foreach ($out['produk'] as $product) {
            $id = (string) ($product['id_stok_item'] ?? '');
            if ($id === '') {
                $missing++;
            } elseif (isset($identities[$id])) {
                $duplicates++;
            } else {
                $identities[$id] = true;
            }
        }
        foreach ($out['master_stok'] as $stock) {
            if (isset($stock['stok_akhir']) && is_numeric($stock['stok_akhir']) && $stock['stok_akhir'] < 0) {
                $negative++;
            }
        }

        $out['__meta'] = [
            'versions' => array_map(fn (string $key): string => hash('sha256', json_encode($out[$key], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), array_combine(self::COLLECTIONS, self::COLLECTIONS)),
            'domain' => 'konveksi',
            'database_isolated' => (bool) config('jago.konveksi_database_isolated', false),
            'reset_epoch' => $this->resetEpoch(),
            'loaded_at' => now()->toIso8601String(),
            'health' => ['missing_stock_ids' => $missing, 'duplicate_stock_ids' => $duplicates, 'negative_stock' => $negative],
            'company_bound' => true,
        ];

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function get(string $key): array
    {
        $mapping = $this->connection()->table('system_table_map')
            ->where(function ($query) use ($key): void {
                $query->where('api_key', $key)
                    ->orWhereRaw('LOWER(sheet_name) = LOWER(?)', [$key]);
            })
            ->first();

        if ($mapping === null) {
            return [];
        }

        $table = (string) ($mapping->table_name ?? '');
        if (! preg_match('/^jl_data_[a-z0-9_]+$/', $table)) {
            throw new RuntimeException('Pemetaan tabel Konveksi tidak valid.');
        }

        $expectedTables = ['jl_data_'.$key];
        if ($key === 'hutangsupplier') {
            $expectedTables[] = 'jl_data_hutang_supplier';
        }
        if (! in_array($table, $expectedTables, true)) {
            throw new RuntimeException('Pemetaan tabel Konveksi berada di luar allowlist.');
        }
        if (! $this->connection()->getSchemaBuilder()->hasTable($table)) {
            throw new RuntimeException('Tabel Konveksi yang dipetakan tidak ditemukan.');
        }

        return $this->connection()->table($table)
            ->orderBy('_row_order')
            ->orderBy('_row_id')
            ->pluck('_data_json')
            ->map(static function ($json): ?array {
                $value = json_decode((string) $json, true);

                return is_array($value) ? $value : null;
            })
            ->filter()
            ->values()
            ->all();
    }

    private function resetEpoch(): string
    {
        try {
            if (! $this->connection()->getSchemaBuilder()->hasTable('system_settings')) {
                return '0';
            }

            return (string) ($this->connection()->table('system_settings')->where('setting_key', 'KONVEKSI_RESET_EPOCH')->value('setting_value') ?? '0');
        } catch (Throwable) {
            return '0';
        }
    }

    private function assertCompany(string $companyKey): void
    {
        $expected = (string) config('jago.company_key', 'LOCAL-COMPANY');
        if ((bool) config('jago.konveksi_database_isolated', false)) {
            $config = $this->konveksi->config();
            if (! filter_var($config['isolated'] ?? false, FILTER_VALIDATE_BOOL)) {
                throw new RuntimeException('Konfigurasi Konveksi mengklaim isolated tetapi sumber database tidak menandainya demikian.');
            }
            $expected = trim((string) ($config['company_key'] ?? $expected));
        }
        if ($expected !== '' && ! hash_equals($expected, $companyKey)) {
            throw new DomainException('Data Konveksi perusahaan ini belum dimigrasikan.');
        }
    }

    private function connection(): Connection
    {
        return $this->konveksi->connection();
    }
}
