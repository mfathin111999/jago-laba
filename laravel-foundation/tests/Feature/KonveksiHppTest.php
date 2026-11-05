<?php

namespace Tests\Feature;

use App\Auth\LegacyUser;
use App\Services\Konveksi\KonveksiSyncAndHppService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

final class KonveksiHppTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('system_audit_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            foreach (['event_id', 'event_time', 'user_id', 'username', 'module', 'action', 'record_id', 'reference_id', 'before_json', 'after_json', 'status', 'notes'] as $column) {
                $table->text($column)->nullable();
            }
        });
        Schema::create('system_table_map', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('sheet_name');
            $table->string('api_key');
            $table->string('table_name');
        });
        foreach (['produk', 'hpp_history', 'hpp_model_bom', 'pembelian', 'bahan_hpp'] as $key) {
            Schema::create('jl_data_'.$key, function (Blueprint $table): void {
                $table->bigIncrements('_row_id');
                $table->unsignedInteger('_row_order')->default(0);
                $table->longText('_data_json');
            });
            DB::table('system_table_map')->insert(['sheet_name' => $key, 'api_key' => $key, 'table_name' => 'jl_data_'.$key]);
        }

        $this->addRow('produk', ['id' => 'P1', 'model_tas' => 'Tas A', 'nama' => 'Tas A Merah', 'hpp_produk' => 20000]);
        $this->addRow('produk', ['id' => 'P2', 'model_tas' => 'Tas A', 'nama' => 'Tas A Biru', 'hpp_produk' => 18000]);
        $this->addRow('produk', ['id' => 'P3', 'model_tas' => 'Tas B', 'nama' => 'Tas B', 'hpp_produk' => 99000]);
    }

    /** @param array<string, mixed> $data */
    private function addRow(string $key, array $data): void
    {
        DB::table('jl_data_'.$key)->insert(['_row_order' => 0, '_data_json' => json_encode($data)]);
    }

    /** @return list<array<string, mixed>> */
    private function all(string $key): array
    {
        return DB::table('jl_data_'.$key)->orderBy('_row_id')->pluck('_data_json')->map(fn ($json) => json_decode($json, true))->all();
    }

    private function service(): KonveksiSyncAndHppService
    {
        return app(KonveksiSyncAndHppService::class);
    }

    private function user(): LegacyUser
    {
        return new LegacyUser(['id' => 'u1', 'username' => 'admin', 'role_id' => 'ROLE-OWNER_SUPER_ADMIN']);
    }

    /** @return array<string, mixed> */
    private function formula(array $overrides = []): array
    {
        return $overrides + [
            'model_key' => 'Tas A', 'effective_date' => '2026-11-04', 'catatan' => 'Harga kain naik',
            'bom_items' => [
                ['nama_bahan' => 'Kain', 'qty_pakai_per_pcs' => 2, 'waste_pct' => 10, 'harga_satuan_pakai' => 5000],
                ['nama_bahan' => 'Resleting', 'qty_pakai_per_pcs' => 1, 'harga_satuan_pakai' => 3000],
            ],
            'upah' => ['potong' => 2000, 'jahit' => 3000],
            'packaging' => [500],
            'operasional' => ['mode' => 'persen', 'persen' => 10],
            'biaya_manual' => [['total' => 1000]],
        ];
    }

    public function test_activation_builds_the_cost_from_all_components_and_updates_only_that_model(): void
    {
        $result = $this->service()->activateHpp($this->user(), 'A', $this->formula());

        // material 2.2 x 5000 + 3000 = 14000; labour 5000; packaging 500; overhead 10% of 19000 = 1900; manual 1000
        $this->assertEquals(22400, $result['data']['hpp']);
        $this->assertSame('V01', $result['data']['version']);

        $products = $this->all('produk');
        $this->assertEquals([22400, 22400, 99000], array_column($products, 'hpp_produk'), 'only the Tas A products change');
        $this->assertSame('V01', $products[0]['hpp_version']);
        $this->assertEquals(2000, $products[0]['upah_potong']);
        $this->assertEquals(3000, $products[0]['upah_jahit']);
        $this->assertArrayNotHasKey('hpp_version', $products[2]);

        $history = $this->all('hpp_history')[0];
        $this->assertEquals(20000, $history['hpp_lama'], 'the previous cost is kept for the record');
        $this->assertEquals([14000, 5000, 500, 1900, 1000], [$history['material_total'], $history['upah_total'], $history['packaging_total'], $history['operasional_total'], $history['manual_total']]);
        $this->assertSame('Tas A', json_decode($history['formula_snapshot'], true)['model_key']);
        $this->assertSame('admin', $history['operator']);

        $bom = $this->all('hpp_model_bom');
        $this->assertCount(2, $bom);
        $this->assertEquals(11000, $bom[0]['hpp_material_per_pcs']);
        $this->assertEquals(2.2, $bom[0]['qty_efektif_per_pcs']);
        $this->assertDatabaseHas('system_audit_logs', ['action' => 'ACTIVATE_HPP']);
    }

    public function test_each_activation_gets_the_next_version_and_replaces_the_bill_of_materials(): void
    {
        $this->service()->activateHpp($this->user(), 'A', $this->formula());
        $second = $this->service()->activateHpp($this->user(), 'A', $this->formula(['biaya_manual' => []]));
        $other = $this->service()->activateHpp($this->user(), 'A', $this->formula(['model_key' => 'Tas B']));

        $this->assertSame('V02', $second['data']['version']);
        $this->assertEquals(22400, $second['data']['history']['hpp_lama'] ?? null);
        $this->assertEquals(21400, $second['data']['hpp']);
        $this->assertSame('V01', $other['data']['version'], 'versions are counted per model');
        $this->assertCount(4, $this->all('hpp_model_bom'), '2 lines for Tas A (replaced, not added) + 2 for Tas B');
        $this->assertSame(['V01', 'V02', 'V01'], array_column($this->all('hpp_history'), 'version'));
    }

    public function test_a_material_without_an_active_price_is_refused_instead_of_understating_the_cost(): void
    {
        $formula = $this->formula();
        $formula['bom_items'][1]['harga_satuan_pakai'] = 0;

        try {
            $this->service()->activateHpp($this->user(), 'A', $formula);
            $this->fail('A material without a price must stop the activation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Material Resleting belum memiliki harga aktif.', $exception->getMessage());
        }

        $this->assertEquals(20000, $this->all('produk')[0]['hpp_produk']);
        $this->assertSame([], $this->all('hpp_history'));
        $this->assertSame([], $this->all('hpp_model_bom'));
    }

    public function test_a_missing_model_or_a_zero_total_is_refused(): void
    {
        foreach ([$this->formula(['model_key' => '']), ['model_key' => 'Tas A', 'bom_items' => [], 'upah' => [], 'operasional' => ['mode' => 'nominal', 'nominal' => 0]]] as $formula) {
            try {
                $this->service()->activateHpp($this->user(), 'A', $formula);
                $this->fail('Expected a refusal.');
            } catch (RuntimeException) {
            }
        }

        $this->assertEquals(20000, $this->all('produk')[0]['hpp_produk']);
        $this->assertSame([], $this->all('hpp_history'));
    }

    public function test_purchase_prices_are_copied_to_the_hpp_records_for_review(): void
    {
        $this->addRow('bahan_hpp', ['id_bahan_hpp' => 'HPP-B1', 'id_bahan' => 'B1', 'harga_aktif' => 4000]);
        $this->addRow('bahan_hpp', ['id_bahan_hpp' => 'HPP-B2', 'id_bahan_gudang' => 'B2', 'harga_aktif' => 100]);
        $this->addRow('bahan_hpp', ['id_bahan_hpp' => 'HPP-B3', 'id_bahan' => 'B3', 'harga_aktif' => 7]);
        $this->addRow('pembelian', ['id' => 'PBL-1', 'items' => [['id_bahan' => 'B1', 'harga_satuan' => 150000]]]);
        $this->addRow('pembelian', ['id' => 'PBL-2', 'items' => [['id_bahan' => 'B1', 'harga_satuan' => 160000], ['id_bahan' => 'B2', 'qty' => 4, 'subtotal' => 2000]]]);

        $result = $this->service()->syncFromPurchases($this->user(), 'A');

        $this->assertSame(2, $result['updated']);
        $rows = $this->all('bahan_hpp');
        $this->assertEquals(160000, $rows[0]['harga_beli_terakhir'], 'the latest purchase wins');
        $this->assertEquals(500, $rows[1]['harga_beli_terakhir'], 'price derived from subtotal / qty when no unit price');
        $this->assertTrue($rows[0]['price_review_required']);
        $this->assertEquals(4000, $rows[0]['harga_aktif'], 'the active price is only flagged, not replaced');
        $this->assertArrayNotHasKey('harga_beli_terakhir', $rows[2]);
    }
}
