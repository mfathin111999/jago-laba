<?php

namespace Tests\Feature;

use App\Pilots\Konveksi\KonveksiPilotReadService;
use App\Pilots\Marketplace\MarketplacePilotReadService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class PilotReadOnlyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('auth_users_v2', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('username')->unique();
            $table->string('name')->nullable();
            $table->text('password_hash');
            $table->string('role_id')->nullable();
            $table->string('status')->default('aktif');
            $table->timestamps();
        });
        Schema::create('marketplace_stores', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_key');
            $table->string('platform')->nullable();
            $table->string('store_name')->nullable();
            $table->string('store_identifier')->nullable();
            $table->unsignedBigInteger('default_account_id')->nullable();
            $table->decimal('default_admin_fee_pct', 5, 2)->default(11.0);
            $table->decimal('default_free_shipping_pct', 5, 2)->default(4.0);
            $table->decimal('default_payment_fee_pct', 5, 2)->default(1.0);
            $table->string('sync_mode')->default('API');
            $table->string('status')->default('Terputus');
            $table->string('authorization_status')->nullable();
            $table->unsignedInteger('total_transactions')->default(0);
            $table->dateTime('last_synced_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });
        Schema::create('marketplace_oauth_connections', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('store_id');
            $table->string('company_key');
            $table->string('status')->nullable();
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('last_successful_sync_at')->nullable();
        });
        Schema::create('marketplace_orders', function (Blueprint $table): void {
            $table->string('id');
            $table->string('store_id');
            $table->string('source_kind');
            $table->dateTime('order_date');
            $table->string('order_status')->nullable();
            $table->decimal('gross_sales', 18, 2)->nullable();
            $table->text('transaction_detail_json')->nullable();
            $table->string('internal_state')->nullable();
        });
        Schema::create('marketplace_expenses', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('store_id');
            $table->date('expense_date');
            $table->string('category')->nullable();
            $table->string('description')->nullable();
            $table->string('vendor_name')->nullable();
            $table->decimal('amount', 18, 2)->nullable();
            $table->string('payment_method')->nullable();
            $table->unsignedBigInteger('account_id')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('affects_profit')->default(true);
            $table->boolean('is_cashflow_only')->default(false);
            $table->dateTime('created_at')->nullable();
        });
        Schema::create('idempotency_keys', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('company_key')->nullable();
            $table->string('idempotency_key');
            $table->string('module')->nullable();
            $table->string('reference_id')->nullable();
            $table->text('response_json')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->unique(['company_key', 'module', 'idempotency_key'], 'uq_idempotency_scope');
        });
        Schema::create('system_audit_logs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('event_id');
            $table->dateTime('event_time');
            $table->string('user_id')->nullable();
            $table->string('username')->nullable();
            $table->string('module')->nullable();
            $table->string('action');
            $table->string('record_id')->nullable();
            $table->string('reference_id')->nullable();
            $table->text('before_json')->nullable();
            $table->text('after_json')->nullable();
            $table->string('status')->default('SUCCESS');
            $table->text('notes')->nullable();
        });
        Schema::create('workspace_user_access', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('user_key');
            $table->string('workspace');
        });
        Schema::create('system_table_map', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('api_key')->unique();
            $table->string('sheet_name')->nullable();
            $table->string('table_name');
        });
        Schema::create('system_settings', function (Blueprint $table): void {
            $table->string('setting_key')->primary();
            $table->text('setting_value')->nullable();
        });

        foreach (['bahan', 'produk', 'master_stok'] as $key) {
            $table = 'jl_data_'.$key;
            Schema::create($table, function (Blueprint $blueprint): void {
                $blueprint->bigIncrements('_row_id');
                $blueprint->unsignedInteger('_row_order')->default(0);
                $blueprint->text('_data_json');
            });
            DB::table('system_table_map')->insert([
                'api_key' => $key,
                'sheet_name' => $key,
                'table_name' => $table,
            ]);
        }

        DB::table('auth_users_v2')->insert([
            'username' => 'owner',
            'name' => 'Owner Pilot',
            'password_hash' => password_hash('owner-secret', PASSWORD_BCRYPT),
            'role_id' => 'ROLE-OWNER_SUPER_ADMIN',
            'status' => 'aktif',
        ]);
        DB::table('auth_users_v2')->insert([
            'username' => 'reader',
            'name' => 'Reader Pilot',
            'password_hash' => password_hash('reader-secret', PASSWORD_BCRYPT),
            'role_id' => 'ROLE-PEMBACA',
            'status' => 'aktif',
        ]);
        $readerId = (string) DB::table('auth_users_v2')->where('username', 'reader')->value('id');
        DB::table('workspace_user_access')->insert(['user_key' => $readerId, 'workspace' => 'marketplace']);
        DB::table('marketplace_stores')->insert([
            ['id' => 'store-a', 'company_key' => 'LOCAL-COMPANY', 'platform' => 'Shopee', 'store_name' => 'Toko A', 'store_identifier' => 'shop-a', 'authorization_status' => 'verified'],
            ['id' => 'store-foreign', 'company_key' => 'OTHER-COMPANY', 'platform' => 'Shopee', 'store_name' => 'Asing', 'store_identifier' => 'shop-x', 'authorization_status' => 'verified'],
        ]);
        DB::table('marketplace_oauth_connections')->insert([
            'store_id' => 'store-a',
            'company_key' => 'LOCAL-COMPANY',
            'status' => 'authorized',
            'authorized_at' => '2026-10-01 00:00:00',
            'verified_at' => '2026-10-01 00:00:00',
            'last_successful_sync_at' => '2026-10-30 08:00:00',
        ]);
        $this->insertMarketplaceFixture();
        DB::table('system_settings')->insert(['setting_key' => 'KONVEKSI_RESET_EPOCH', 'setting_value' => 'epoch-1']);
        DB::table('jl_data_bahan')->insert(['_row_order' => 1, '_data_json' => json_encode(['id' => 'B-1', 'nama' => 'Kain'])]);
        DB::table('jl_data_produk')->insert([
            ['_row_order' => 1, '_data_json' => json_encode(['id' => 'P-1', 'id_stok_item' => 'STOK-1'])],
            ['_row_order' => 2, '_data_json' => json_encode(['id' => 'P-2', 'id_stok_item' => 'STOK-1'])],
        ]);
        DB::table('jl_data_master_stok')->insert(['_row_order' => 1, '_data_json' => json_encode(['id' => 'STOK-1', 'stok_akhir' => -2])]);
    }

    public function test_marketplace_fixture_matches_legacy_dashboard_contract(): void
    {
        $orders = $this->fixtureOrders();
        $dashboard = MarketplacePilotReadService::aggregate($orders, []);

        $this->assertSame(128340.0, $dashboard['groups']['final']['sales']);
        $this->assertSame(24338.0, $dashboard['groups']['final']['fees']);
        $this->assertSame(45002.0, $dashboard['groups']['final']['profit']);
        $this->assertSame(80000.0, $dashboard['groups']['estimated']['net']);
        $this->assertNull($dashboard['groups']['unknown']['net']);
        $this->assertNull($dashboard['costs']['ads']);
        $this->assertNull($dashboard['net_profit']);
        $this->assertSame(1, $dashboard['attention']['cancelled']);
        $this->assertSame(1, $dashboard['attention']['unpaid']);
        $this->assertSame(2, $dashboard['subtotal_orders']);
    }

    public function test_marketplace_legacy_and_laravel_runtime_match_on_the_same_fixture(): void
    {
        $this->loginAndSelect('marketplace');
        $_SESSION['company_key'] = 'LOCAL-COMPANY';
        $filters = [
            'period' => 'custom',
            'date_start' => '2026-10-01',
            'date_end' => '2026-10-31',
            'finance_mode' => 'final',
        ];

        $legacyStarted = hrtime(true);
        $legacy = $this->legacyMarketplaceReport('store-a', $filters);
        $legacyElapsed = (hrtime(true) - $legacyStarted) / 1_000_000_000;

        $laravelStarted = hrtime(true);
        $response = $this->inertiaGet('/pilot/marketplace?store=store-a&period=custom&date_start=2026-10-01&date_end=2026-10-31&finance_mode=final');
        $laravelElapsed = (hrtime(true) - $laravelStarted) / 1_000_000_000;
        $response->assertStatus(200);

        $props = $response->json('props');
        $legacyComparable = $legacy;
        unset($legacyComparable['generated_at']);
        $laravelComparable = $props['dashboard'];
        unset($laravelComparable['generated_at'], $laravelComparable['finance_mode']);

        $this->assertSame(
            $this->normalizeParityValue($legacyComparable),
            $this->normalizeParityValue($laravelComparable),
            'Legacy and Laravel dashboard payloads diverged on the same fixture.'
        );
        $this->assertTrue($props['read_only']);
        $this->assertSame('marketplace', $props['workspace']);
        $this->assertSame(['company_key' => 'LOCAL-COMPANY', 'store_id' => 'store-a'], $props['scope']);
        $this->assertSame($filters, array_intersect_key($props['filters'], $filters));
        $this->assertSame($legacy['period'], $props['query']['period']);
        $this->assertSame('api', $props['query']['orders']['where']['source_kind']);
        $this->assertSame(['null', 'active'], $props['query']['orders']['where']['internal_state']);
        $this->assertSame($legacy['period']['start'], $props['query']['orders']['where']['order_date_gte']);
        $this->assertSame($legacy['period']['exclusive_end'], $props['query']['orders']['where']['order_date_lt']);
        $this->assertSame(['order_date', 'id'], $props['query']['orders']['order_by']);
        $this->assertSame(['expense_date', 'id'], $props['query']['expenses']['order_by']);
        $this->assertLessThan(2.0, $legacyElapsed, 'Legacy fixture read took unexpectedly long.');
        $this->assertLessThan(2.0, $laravelElapsed, 'Laravel/Inertia fixture read took unexpectedly long.');

        $legacyForeignDenied = false;
        try {
            $this->legacyMarketplaceReport('store-foreign', $filters);
        } catch (\DomainException) {
            $legacyForeignDenied = true;
        }
        $this->assertTrue($legacyForeignDenied, 'Legacy runtime did not reject a foreign-company store.');
        $this->inertiaGet('/pilot/marketplace?store=store-foreign')->assertForbidden();
    }

    public function test_marketplace_pilot_is_scoped_read_only_and_returns_inertia_contract(): void
    {
        $this->loginAndSelect('marketplace');
        $beforeOrders = DB::table('marketplace_orders')->count();
        $beforeExpenses = DB::table('marketplace_expenses')->count();
        $started = microtime(true);

        $response = $this->inertiaGet('/pilot/marketplace?store=store-a&period=custom&date_start=2026-10-01&date_end=2026-10-31&finance_mode=final');

        $elapsed = microtime(true) - $started;
        $response->assertOk()->assertJsonPath('props.read_only', true)
            ->assertJsonPath('props.scope.company_key', 'LOCAL-COMPANY')
            ->assertJsonPath('props.scope.store_id', 'store-a')
            ->assertJsonPath('props.dashboard.groups.final.sales', 128340)
            ->assertJsonPath('props.dashboard.groups.final.profit', 45002)
            ->assertJsonPath('props.query.orders.where.source_kind', 'api');
        $this->assertLessThan(2.0, $elapsed, 'Pilot read took unexpectedly long.');
        $this->assertSame($beforeOrders, DB::table('marketplace_orders')->count());
        $this->assertSame($beforeExpenses, DB::table('marketplace_expenses')->count());
    }

    public function test_marketplace_pilot_rejects_foreign_store(): void
    {
        $this->loginAndSelect('marketplace');

        $this->inertiaGet('/pilot/marketplace?store=store-foreign')->assertForbidden();
    }

    public function test_marketplace_analytics_pilot_matches_legacy_finance_contract_and_is_read_only(): void
    {
        $this->loginAndSelect('marketplace');
        $beforeOrders = DB::table('marketplace_orders')->count();
        $beforeExpenses = DB::table('marketplace_expenses')->count();
        $filters = [
            'period' => 'custom',
            'date_start' => '2026-10-01',
            'date_end' => '2026-10-31',
            'date_basis' => 'created',
            'analytics_mode' => 'all',
        ];

        $legacy = $this->legacyMarketplaceAnalyticsReport('store-a', $filters);
        unset($legacy['generated_at']);

        $response = $this->inertiaGet('/pilot/marketplace/analytics?store=store-a&period=custom&date_start=2026-10-01&date_end=2026-10-31&date_basis=created&analytics_mode=all');
        $response->assertOk()
            ->assertJsonPath('component', 'Pilot/MarketplaceAnalytics')
            ->assertJsonPath('props.read_only', true)
            ->assertJsonPath('props.workspace', 'marketplace')
            ->assertJsonPath('props.scope.company_key', 'LOCAL-COMPANY')
            ->assertJsonPath('props.scope.store_id', 'store-a')
            ->assertJsonPath('props.query.provider_call', false)
            ->assertJsonPath('props.query.write', false)
            ->assertJsonPath('props.query.where.source_kind.0', 'api')
            ->assertJsonPath('props.query.where.source_kind.1', 'import')
            ->assertJsonPath('props.analytics.current.all.final_orders', 1)
            ->assertJsonPath('props.analytics.current.all.estimated_orders', 1)
            ->assertJsonPath('props.analytics.current.all.missing_income', 1)
            ->assertJsonPath('props.analytics.current.all.missing_hpp', 1);

        $laravel = $response->json('props.analytics');
        unset($laravel['generated_at']);
        $this->assertSame(
            $this->normalizeParityValue($legacy),
            $this->normalizeParityValue($laravel),
            'Legacy and Laravel analytics payloads diverged on the same fixture.'
        );
        $this->assertSame($beforeOrders, DB::table('marketplace_orders')->count());
        $this->assertSame($beforeExpenses, DB::table('marketplace_expenses')->count());
    }

    public function test_marketplace_analytics_pilot_rejects_foreign_store(): void
    {
        $this->loginAndSelect('marketplace');

        $this->inertiaGet('/pilot/marketplace/analytics?store=store-foreign')->assertForbidden();
    }

    public function test_analytics_csv_export_matches_the_legacy_writer_on_the_same_fixture(): void
    {
        $this->loginAndSelect('marketplace');
        $filters = ['period' => 'custom', 'date_start' => '2026-10-01', 'date_end' => '2026-10-31', 'date_basis' => 'created', 'analytics_mode' => 'all'];
        $report = $this->legacyMarketplaceAnalyticsReport('store-a', $filters);
        require_once dirname(__DIR__, 3).'/app/Modules/Marketplace/Services/MarketplaceAnalyticsCsv.php';
        $out = fopen('php://temp', 'w+');
        \MarketplaceAnalyticsCsv::write($out, $report);
        rewind($out);
        $expected = stream_get_contents($out);
        fclose($out);
        $response = $this->get('/marketplace/analytics/export?'.http_build_query($filters + ['store' => 'store-a', 'format' => 'csv']));
        $response->assertOk()->assertDownload('analitik-global-2026-10-01-2026-10-31.csv');
        $withoutClock = static fn ($s) => preg_replace('/^"Dibuat \(WIB\)".*$/m', '', $s);
        $this->assertSame($withoutClock($expected), $withoutClock($response->streamedContent()));
    }

    public function test_analytics_xlsx_export_preserves_legacy_workbook_cells_and_rejects_foreign_store(): void
    {
        $this->loginAndSelect('marketplace');
        $filters = ['period' => 'custom', 'date_start' => '2026-10-01', 'date_end' => '2026-10-31', 'date_basis' => 'created', 'analytics_mode' => 'all'];
        $report = $this->legacyMarketplaceAnalyticsReport('store-a', $filters);
        require_once dirname(__DIR__, 3).'/app/Modules/Marketplace/Services/MarketplaceAnalyticsXlsx.php';
        $expected = \MarketplaceAnalyticsXlsx::bytes($report);
        $response = $this->get('/marketplace/analytics/export?'.http_build_query($filters + ['store' => 'store-a', 'format' => 'xlsx']))->assertOk();
        $files = [];
        try {
            foreach ([$expected, $response->getContent()] as $bytes) {
                $path = tempnam(sys_get_temp_dir(), 'jl-export-test-');
                $files[] = $path;
                file_put_contents($path, $bytes);
            }
            $before = new \ZipArchive;
            $after = new \ZipArchive;
            $this->assertTrue($before->open($files[0]));
            $this->assertTrue($after->open($files[1]));
            $this->assertSame($before->numFiles, $after->numFiles);
            // Sheet 1 contains the generation clock; compare every financial/product sheet byte-for-byte.
            foreach (['xl/workbook.xml', 'xl/worksheets/sheet2.xml', 'xl/worksheets/sheet3.xml', 'xl/worksheets/sheet4.xml', 'xl/worksheets/sheet5.xml'] as $entry) {
                $this->assertSame($before->getFromName($entry), $after->getFromName($entry), $entry);
            }
            $before->close();
            $after->close();
        } finally {
            foreach ($files as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
        $this->get('/marketplace/analytics/export?'.http_build_query($filters + ['store' => 'store-foreign', 'format' => 'xlsx']))->assertForbidden();
    }

    public function test_marketplace_analytics_pilot_preserves_unknown_finance_as_null(): void
    {
        $this->loginAndSelect('marketplace');

        $response = $this->inertiaGet('/pilot/marketplace/analytics?store=store-a&period=custom&date_start=2026-10-01&date_end=2026-10-31');

        $response->assertOk();
        $this->assertNull($response->json('props.analytics.current.all.net_income'));
        $this->assertNull($response->json('props.analytics.current.all.cogs'));
        $this->assertNull($response->json('props.analytics.current.all.gross_profit'));
        $this->assertSame(1, $response->json('props.analytics.current.all.missing_income'));
        $this->assertSame(1, $response->json('props.analytics.current.all.missing_hpp'));
    }

    public function test_marketplace_analytics_pilot_rejects_period_longer_than_legacy_limit(): void
    {
        $this->loginAndSelect('marketplace');

        $this->inertiaGet('/pilot/marketplace/analytics?store=store-a&period=custom&date_start=2026-10-01&date_end=2026-11-30')
            ->assertStatus(422);
    }

    public function test_marketplace_order_read_route_preserves_scope_filters_and_does_not_write(): void
    {
        $this->loginAndSelect('marketplace');
        $beforeOrders = DB::table('marketplace_orders')->count();

        $response = $this->inertiaGet('/pilot/marketplace/orders?store=store-a&period=custom&date_start=2026-10-01&date_end=2026-10-31&order_tab=all&per_page=25');

        $response->assertOk()
            ->assertJsonPath('component', 'Pilot/MarketplaceOrders')
            ->assertJsonPath('props.read_only', true)
            ->assertJsonPath('props.workspace', 'marketplace')
            ->assertJsonPath('props.scope.company_key', 'LOCAL-COMPANY')
            ->assertJsonPath('props.scope.store_id', 'store-a')
            ->assertJsonPath('props.orders.total', 5)
            ->assertJsonPath('props.orders.counts.new', 1)
            ->assertJsonPath('props.orders.counts.shipped', 1)
            ->assertJsonPath('props.orders.counts.cancelled', 1)
            ->assertJsonPath('props.query.provider_call', false);
        $this->assertSame('2026-10-01', $response->json('props.orders.period.start'));
        $this->assertSame('2026-11-01', $response->json('props.orders.period.exclusive_end'));
        $this->assertSame($beforeOrders, DB::table('marketplace_orders')->count());

        $this->inertiaGet('/pilot/marketplace/orders?store=store-foreign')->assertForbidden();
    }

    public function test_marketplace_orders_and_dashboard_csv_exports_cover_all_rows_and_stay_scoped(): void
    {
        $this->loginAndSelect('marketplace');
        $query = 'store=store-a&period=custom&date_start=2026-10-01&date_end=2026-10-31';

        $orders = $this->get('/pilot/marketplace/orders/export?'.$query.'&per_page=25&page=2')->assertOk();
        $this->assertStringContainsString('text/csv', (string) $orders->headers->get('Content-Type'));
        $this->assertStringContainsString('pesanan-toko.csv', (string) $orders->headers->get('Content-Disposition'));
        $lines = array_values(array_filter(preg_split('/\r?\n/', ltrim($orders->streamedContent(), "\xEF\xBB\xBF"))));
        $this->assertSame(['Toko', 'Tanggal', 'Kode Pesanan', 'Nilai Pesanan', 'Unit', 'Kurir', 'Resi', 'Status'], str_getcsv($lines[0], ',', '"', ''));
        $this->assertCount(6, $lines, 'header plus all 5 orders, regardless of page and per_page');
        $this->assertStringStartsWith("'", str_getcsv($lines[1], ',', '"', '')[2]);

        $legacy = $this->get('/api/marketplace-orders.php?'.$query.'&format=csv')->assertOk();
        $this->assertSame($orders->streamedContent(), $legacy->streamedContent());
        $this->get('/api/marketplace-orders.php?'.$query)->assertRedirect('/pilot/marketplace/orders?'.$query);

        $dashboard = $this->get('/pilot/marketplace/export?'.$query.'&finance_mode=final')->assertOk();
        $this->assertStringContainsString('rincian-dashboard.csv', (string) $dashboard->headers->get('Content-Disposition'));
        $rows = array_values(array_filter(preg_split('/\r?\n/', ltrim($dashboard->streamedContent(), "\xEF\xBB\xBF"))));
        $this->assertSame(['Pesanan', 'Keuangan', 'Penjualan', 'Untuk Dibayar', 'HPP Produk', 'Laba setelah HPP'], str_getcsv($rows[0], ',', '"', ''));
        $expected = count($this->inertiaGet('/pilot/marketplace?'.$query.'&finance_mode=final')->json('props.dashboard.rows'));
        $this->assertCount($expected + 1, $rows);
        $this->assertGreaterThan(0, $expected);
        $this->get('/api/marketplace-dashboard.php?'.$query.'&format=csv')->assertOk();

        $this->get('/pilot/marketplace/orders/export?store=store-foreign')->assertForbidden();
        $this->get('/pilot/marketplace/export?store=store-foreign')->assertForbidden();
        $this->get('/api/marketplace-orders.php?store=store-foreign&format=csv')->assertForbidden();
    }

    public function test_marketplace_order_detail_and_return_filter_are_scoped_read_only(): void
    {
        Schema::create('marketplace_order_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('order_id');
            $table->string('sku')->nullable();
            $table->string('name')->nullable();
            $table->unsignedInteger('quantity')->nullable();
        });
        Schema::create('marketplace_sync_records', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('store_id');
            $table->string('resource_type');
            $table->string('external_id')->nullable();
            $table->text('payload_json')->nullable();
            $table->dateTime('source_updated_at')->nullable();
        });

        $snapshot = json_decode((string) DB::table('marketplace_orders')->where('id', 'FINAL')->value('transaction_detail_json'), true);
        $snapshot['items'] = [[
            'name' => 'Produk Pilot', 'variant' => 'Biru', 'sku' => 'SKU-PILOT', 'quantity' => 2,
            'unit_price' => 112500, 'total_price' => 225000, 'unit_cogs' => 29500, 'total_cogs' => 59000,
        ]];
        $snapshot['packages'] = [['id' => 'PKG-1', 'tracking_number' => 'RESI-1', 'shipping_provider_name' => 'Kurir Pilot', 'status' => 'SHIPPED']];
        DB::table('marketplace_orders')->where('id', 'FINAL')->update(['transaction_detail_json' => json_encode($snapshot)]);
        DB::table('marketplace_order_items')->insert(['order_id' => 'FINAL', 'sku' => 'SKU-PILOT', 'name' => 'Produk Pilot', 'quantity' => 2]);
        DB::table('marketplace_sync_records')->insert([
            'store_id' => 'store-a', 'resource_type' => 'return', 'external_id' => 'RETURN-1',
            'payload_json' => json_encode([
                'return_id' => 'RETURN-1', 'order_id' => 'FINAL', 'return_type' => 'REFUND',
                'return_status' => 'RETURN_OR_REFUND_REQUEST_COMPLETE', 'refund_amount' => ['currency' => 'IDR', 'amount' => '120.00'],
                'create_time' => strtotime('2026-10-15 09:00:00'), 'update_time' => strtotime('2026-10-15 10:00:00'),
            ]),
            'source_updated_at' => '2026-10-15 10:00:00',
        ]);

        $this->loginAndSelect('marketplace');
        $beforeOrders = DB::table('marketplace_orders')->count();
        $beforeReturns = DB::table('marketplace_sync_records')->count();

        $detail = $this->withHeaders(['Accept' => 'application/json'])->get('/pilot/marketplace/orders/FINAL/detail?store_id=store-a');
        $detail->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.read_only', true)
            ->assertJsonPath('data.scope.company_key', 'LOCAL-COMPANY')
            ->assertJsonPath('data.presentation.order_id', 'FINAL')
            ->assertJsonPath('data.presentation.gross_amount', 225000)
            ->assertJsonPath('data.presentation.net_profit', 45002)
            ->assertJsonPath('data.presentation.items.0.sku', 'SKU-PILOT')
            ->assertJsonPath('data.presentation.packages.0.tracking', 'RESI-1')
            ->assertJsonPath('data.presentation.return_cases.0.id', 'RETURN-1');

        $returns = $this->inertiaGet('/pilot/marketplace/orders?store=store-a&period=custom&date_start=2026-10-01&date_end=2026-10-31&order_tab=returns&return_type=refund');
        $returns->assertOk()->assertJsonPath('props.orders.total', 1);
        $this->inertiaGet('/pilot/marketplace/orders?store=store-a&period=custom&date_start=2026-10-01&date_end=2026-10-31&order_tab=returns&return_type=goods')
            ->assertOk()->assertJsonPath('props.orders.total', 0);

        $this->withHeaders(['Accept' => 'application/json'])->get('/pilot/marketplace/orders/FINAL/detail?store_id=store-foreign')->assertForbidden();
        $this->assertSame($beforeOrders, DB::table('marketplace_orders')->count());
        $this->assertSame($beforeReturns, DB::table('marketplace_sync_records')->count());
    }

    public function test_marketplace_expense_write_success_is_scoped_audited_and_idempotent(): void
    {
        $this->loginAndSelect('marketplace');

        $response = $this->postExpense(['description' => 'Biaya iklan pilot', 'amount' => 'Rp 12.500'], 'expense-success-key');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('expense_id', 1)
            ->assertJsonPath('duplicate', false)
            ->assertHeader('X-Request-Id');
        $this->assertDatabaseHas('marketplace_expenses', [
            'id' => 1,
            'store_id' => 'store-a',
            'description' => 'Biaya iklan pilot',
            'amount' => 12500,
            'affects_profit' => 0,
            'is_cashflow_only' => 0,
        ]);
        $this->assertDatabaseHas('system_audit_logs', ['action' => 'CREATE_EXPENSE', 'record_id' => '1']);
        $this->assertDatabaseHas('idempotency_keys', [
            'company_key' => 'LOCAL-COMPANY',
            'module' => 'marketplace.expense',
            'idempotency_key' => 'expense-success-key',
            'reference_id' => '1',
        ]);

        $duplicate = $this->postExpense(['description' => 'Biaya iklan pilot', 'amount' => 'Rp 12.500'], 'expense-success-key');
        $duplicate->assertOk()->assertJsonPath('duplicate', true)->assertJsonPath('expense_id', 1);

        $this->postExpense(['description' => 'Payload berbeda', 'amount' => 'Rp 12.500'], 'expense-success-key')
            ->assertStatus(409)
            ->assertJsonPath('code', 'IDEMPOTENCY_CONFLICT');
        $this->assertSame(1, DB::table('marketplace_expenses')->count());
        $this->assertSame(1, DB::table('system_audit_logs')->count());
    }

    public function test_marketplace_expense_idempotency_is_scoped_by_company_and_module(): void
    {
        $this->loginAndSelect('marketplace');

        DB::table('idempotency_keys')->insert([
            'company_key' => 'LOCAL-COMPANY',
            'module' => 'marketplace.sync',
            'idempotency_key' => 'shared-module-key',
            'reference_id' => 'sync-1',
            'response_json' => json_encode(['request_fingerprint' => 'sync-fingerprint']),
            'created_at' => now(),
        ]);
        DB::table('idempotency_keys')->insert([
            'company_key' => 'OTHER-COMPANY',
            'module' => 'marketplace.expense',
            'idempotency_key' => 'shared-company-key',
            'reference_id' => 'other-expense-1',
            'response_json' => json_encode(['request_fingerprint' => 'other-fingerprint']),
            'created_at' => now(),
        ]);

        $sameModuleKey = $this->postExpense([
            'description' => 'Module scope tetap terpisah',
            'amount' => '1000',
        ], 'shared-module-key');
        $sameModuleKey->assertOk()
            ->assertJsonPath('duplicate', false)
            ->assertJsonPath('expense_id', 1);

        $sameCompanyKey = $this->postExpense([
            'description' => 'Company scope tetap terpisah',
            'amount' => '2000',
        ], 'shared-company-key');
        $sameCompanyKey->assertOk()
            ->assertJsonPath('duplicate', false)
            ->assertJsonPath('expense_id', 2);

        $this->assertSame(2, DB::table('marketplace_expenses')->count());
        $this->assertSame(4, DB::table('idempotency_keys')->count());
        $this->assertSame(2, DB::table('idempotency_keys')
            ->where('company_key', 'LOCAL-COMPANY')
            ->where('module', 'marketplace.expense')
            ->count());
    }

    public function test_marketplace_expense_write_validation_and_role_are_rejected(): void
    {
        $this->loginAndSelect('marketplace');

        $this->from('/pilot/marketplace')
            ->withHeaders([
                'X-Inertia' => 'true',
                'X-Requested-With' => 'XMLHttpRequest',
                'X-CSRF-TOKEN' => csrf_token(),
                'X-Idempotency-Key' => 'expense-inertia-invalid-key',
            ])
            ->post('/pilot/marketplace/expenses', [
                'store_id' => 'store-a',
                'description' => '',
                'amount' => '0',
            ])
            ->assertStatus(303)
            ->assertRedirect('/pilot/marketplace')
            ->assertSessionHasErrors(['description', 'amount']);

        $this->postExpense(['description' => '', 'amount' => '0'], 'expense-invalid-key')
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->loginAndSelect('marketplace', 'reader', 'reader-secret');
        $this->postExpense(['description' => 'Reader cannot write', 'amount' => '1000'], 'expense-reader-key')
            ->assertForbidden()
            ->assertJsonPath('code', 'ROLE_FORBIDDEN');
        $this->assertSame(0, DB::table('marketplace_expenses')->count());
    }

    public function test_marketplace_expense_write_enforces_csrf_store_scope_and_legacy_action_contract(): void
    {
        $this->loginAndSelect('marketplace');

        $this->withHeader('X-CSRF-TOKEN', 'wrong-token')
            ->postJson('/api/marketplace-ajax.php?action=add_expense', [
                'store_id' => 'store-a',
                'description' => 'CSRF failure',
                'amount' => '1000',
            ])
            ->assertStatus(419);

        $this->postExpense(['store_id' => 'store-foreign', 'description' => 'Foreign store', 'amount' => '1000'], 'expense-foreign-key')
            ->assertForbidden()
            ->assertJsonPath('code', 'STORE_FORBIDDEN');

        $this->withHeader('X-CSRF-TOKEN', csrf_token())
            ->postJson('/api/marketplace-ajax.php?action=unsupported_action', ['store_id' => 'store-a'])
            ->assertStatus(503);

        $this->assertSame(0, DB::table('marketplace_expenses')->count());
    }

    public function test_marketplace_expense_write_rolls_back_on_database_failure(): void
    {
        $this->loginAndSelect('marketplace');
        Schema::drop('marketplace_expenses');

        $this->postExpense(['description' => 'Database failure', 'amount' => '1000'], 'expense-failure-key')
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'WRITE_FAILED');
        $this->assertSame(0, DB::table('idempotency_keys')->count());
        $this->assertSame(0, DB::table('system_audit_logs')->count());
    }

    public function test_marketplace_expense_inertia_redirect_refreshes_the_dashboard(): void
    {
        $this->loginAndSelect('marketplace');

        $this->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
            ->withHeader('X-CSRF-TOKEN', csrf_token())
            ->withHeader('X-Idempotency-Key', 'expense-inertia-key')
            ->post('/pilot/marketplace/expenses', [
                'store_id' => 'store-a',
                'description' => 'Refresh dashboard',
                'amount' => '2500',
                'expense_date' => '2026-10-15',
                'affects_profit' => true,
            ])
            ->assertStatus(303)
            ->assertRedirect('/pilot/marketplace?store=store-a');

        $this->inertiaGet('/pilot/marketplace?store=store-a&period=custom&date_start=2026-10-01&date_end=2026-10-31')
            ->assertOk()
            ->assertJsonCount(1, 'props.dashboard.expenses');
    }

    public function test_legacy_marketplace_dashboard_url_forwards_without_writing(): void
    {
        $this->loginAndSelect('marketplace');

        $this->get('/marketplace.php?tab=dashboard&store=store-a&period=month')
            ->assertStatus(303)
            ->assertRedirect('/pilot/marketplace?store=store-a&tab=dashboard&period=month');
    }

    public function test_marketplace_expense_delete_via_legacy_and_pilot_routes(): void
    {
        $this->loginAndSelect('marketplace');

        // Create an expense first
        $res = $this->postExpense(['description' => 'Expense to delete', 'amount' => '3000'], 'expense-to-delete-key')
            ->assertOk()
            ->assertJsonPath('success', true);
        $expenseId = $res->json('expense_id');
        $this->assertNotNull($expenseId);
        $this->assertSame(1, DB::table('marketplace_expenses')->where('id', $expenseId)->count());

        // Attempt delete on foreign store should fail 403
        $this->withHeader('X-CSRF-TOKEN', csrf_token())
            ->postJson('/api/marketplace-ajax.php?action=delete_expense', [
                'store_id' => 'store-foreign',
                'id' => $expenseId,
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'STORE_FORBIDDEN');

        // Attempt delete with invalid ID should fail 404
        $this->withHeader('X-CSRF-TOKEN', csrf_token())
            ->postJson('/api/marketplace-ajax.php?action=delete_expense', [
                'store_id' => 'store-a',
                'id' => 999999,
            ])
            ->assertStatus(404)
            ->assertJsonPath('code', 'EXPENSE_NOT_FOUND');

        // Successful delete via legacy route /api/marketplace-ajax.php?action=delete_expense
        $this->withHeader('X-CSRF-TOKEN', csrf_token())
            ->postJson('/api/marketplace-ajax.php?action=delete_expense', [
                'store_id' => 'store-a',
                'id' => $expenseId,
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('expense_id', $expenseId);

        $this->assertSame(0, DB::table('marketplace_expenses')->where('id', $expenseId)->count());

        // Create another expense and delete via pilot DELETE route
        $res2 = $this->postExpense(['description' => 'Expense pilot delete', 'amount' => '4000'], 'expense-pilot-delete-key')
            ->assertOk();
        $expenseId2 = $res2->json('expense_id');

        $this->withHeader('X-CSRF-TOKEN', csrf_token())
            ->deleteJson("/pilot/marketplace/expenses/{$expenseId2}?store_id=store-a")
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('expense_id', $expenseId2);

        $this->assertSame(0, DB::table('marketplace_expenses')->where('id', $expenseId2)->count());
    }

    public function test_marketplace_store_create_update_and_toggle_via_legacy_and_pilot_routes(): void
    {
        $this->loginAndSelect('marketplace');

        // 1. Add store via legacy route
        $res = $this->withHeader('X-CSRF-TOKEN', csrf_token())
            ->postJson('/api/marketplace-ajax.php?action=add_store', [
                'platform' => 'Shopee',
                'store_name' => 'Toko Baru Legacy',
                'store_identifier' => 'toko_baru_legacy',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $newStoreId = $res->json('store_id');
        $this->assertNotEmpty($newStoreId);
        $this->assertSame(1, DB::table('marketplace_stores')->where('id', $newStoreId)->count());
        $this->assertSame('Terputus', DB::table('marketplace_stores')->where('id', $newStoreId)->value('status'));

        // Direct credentials must be rejected
        $this->withHeader('X-CSRF-TOKEN', csrf_token())
            ->postJson('/api/marketplace-ajax.php?action=add_store', [
                'platform' => 'Shopee',
                'store_name' => 'Toko Kredensial',
                'partner_id' => '12345',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        // 2. Update store via legacy route
        $this->withHeader('X-CSRF-TOKEN', csrf_token())
            ->postJson('/api/marketplace-ajax.php?action=update_store', [
                'store_id' => $newStoreId,
                'platform' => 'Shopee',
                'store_name' => 'Toko Baru Updated',
                'store_identifier' => 'toko_baru_updated',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('Toko Baru Updated', DB::table('marketplace_stores')->where('id', $newStoreId)->value('store_name'));

        // 3. Toggle status to Nonaktif via legacy route
        $this->withHeader('X-CSRF-TOKEN', csrf_token())
            ->postJson('/api/marketplace-ajax.php?action=toggle_store_status', [
                'store_id' => $newStoreId,
                'status' => 'Nonaktif',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'Nonaktif');

        $this->assertSame('Nonaktif', DB::table('marketplace_stores')->where('id', $newStoreId)->value('status'));

        // Setting directly to 'Terhubung' must be prohibited
        $this->withHeader('X-CSRF-TOKEN', csrf_token())
            ->postJson('/api/marketplace-ajax.php?action=toggle_store_status', [
                'store_id' => $newStoreId,
                'status' => 'Terhubung',
            ])
            ->assertStatus(422);

        // 4. Create store via pilot REST route
        $resPilot = $this->withHeader('X-CSRF-TOKEN', csrf_token())
            ->postJson('/pilot/marketplace/stores', [
                'platform' => 'TikTok Shop',
                'store_name' => 'Toko Pilot Native',
                'store_identifier' => 'toko_pilot_native',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $pilotStoreId = $resPilot->json('store_id');
        $this->assertNotEmpty($pilotStoreId);
        $this->assertSame(1, DB::table('marketplace_stores')->where('id', $pilotStoreId)->count());

        // 5. Update store via pilot REST route
        $this->withHeader('X-CSRF-TOKEN', csrf_token())
            ->putJson("/pilot/marketplace/stores/{$pilotStoreId}", [
                'store_id' => $pilotStoreId,
                'platform' => 'TikTok Shop',
                'store_name' => 'Toko Pilot Native Edit',
                'store_identifier' => 'toko_pilot_native_edit',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('Toko Pilot Native Edit', DB::table('marketplace_stores')->where('id', $pilotStoreId)->value('store_name'));

        // 6. Toggle status via pilot REST route
        $this->withHeader('X-CSRF-TOKEN', csrf_token())
            ->patchJson("/pilot/marketplace/stores/{$pilotStoreId}/status", [
                'store_id' => $pilotStoreId,
                'status' => 'Terputus',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('status', 'Terputus');

        $this->assertSame('Terputus', DB::table('marketplace_stores')->where('id', $pilotStoreId)->value('status'));
        $this->assertSame('disconnected', DB::table('marketplace_stores')->where('id', $pilotStoreId)->value('authorization_status'));
    }

    public function test_konveksi_pilot_preserves_load_semua_data_shape_without_writes(): void
    {
        $this->loginAndSelect('konveksi');
        $beforeMap = DB::table('system_table_map')->count();
        $beforeBahan = DB::table('jl_data_bahan')->count();

        $response = $this->inertiaGet('/pilot/konveksi?tab=dashboard');

        $response->assertOk()->assertJsonPath('props.read_only', true)
            ->assertJsonPath('props.workspace', 'konveksi')
            ->assertJsonPath('props.scope.company_key', 'LOCAL-COMPANY')
            ->assertJsonPath('props.data.bahan.0.nama', 'Kain')
            ->assertJsonPath('props.data.__meta.domain', 'konveksi')
            ->assertJsonPath('props.data.__meta.health.missing_stock_ids', 0)
            ->assertJsonPath('props.data.__meta.health.duplicate_stock_ids', 1)
            ->assertJsonPath('props.data.__meta.health.negative_stock', 1)
            ->assertJsonPath('props.query.method', 'loadSemuaData');
        $this->assertSame($beforeMap, DB::table('system_table_map')->count());
        $this->assertSame($beforeBahan, DB::table('jl_data_bahan')->count());
    }

    public function test_konveksi_legacy_and_laravel_runtime_match_on_the_same_fixture(): void
    {
        $this->loginAndSelect('konveksi');
        $_SESSION['company_key'] = 'LOCAL-COMPANY';

        $legacyStarted = hrtime(true);
        $legacy = $this->legacyKonveksiData();
        $legacyElapsed = (hrtime(true) - $legacyStarted) / 1_000_000_000;

        $laravelStarted = hrtime(true);
        $response = $this->inertiaGet('/pilot/konveksi?tab=dashboard');
        $laravelElapsed = (hrtime(true) - $laravelStarted) / 1_000_000_000;
        $response->assertStatus(200);

        $props = $response->json('props');
        $legacyComparable = $this->withoutVolatileKonveksiMeta($legacy);
        $laravelComparable = $this->withoutVolatileKonveksiMeta($props['data']);

        $this->assertSame($legacyComparable, $laravelComparable, 'Legacy and Laravel Konveksi payloads diverged on the same fixture.');
        $this->assertSame($legacyComparable, $this->withoutVolatileKonveksiMeta($props['collections']));
        $this->assertTrue($props['read_only']);
        $this->assertSame('konveksi', $props['workspace']);
        $this->assertSame('LOCAL-COMPANY', $props['scope']['company_key']);
        $this->assertSame('dashboard', $props['tab']);
        $this->assertSame(\KonveksiCollectionStore::COLLECTIONS, $props['query']['tables']);
        $this->assertSame(['_row_order', '_row_id'], $props['query']['order_by']);
        $this->assertSame('loadSemuaData', $props['query']['method']);
        $this->assertLessThan(2.0, $legacyElapsed, 'Legacy Konveksi fixture read took unexpectedly long.');
        $this->assertLessThan(2.0, $laravelElapsed, 'Laravel/Inertia Konveksi fixture read took unexpectedly long.');
    }

    public function test_legacy_konveksi_dashboard_url_forwards_without_writing(): void
    {
        $this->loginAndSelect('konveksi');

        $this->get('/index.php?tab=dashboard')
            ->assertStatus(303)
            ->assertRedirect('/pilot/konveksi?tab=dashboard');
    }

    public function test_legacy_konveksi_read_rpc_uses_the_new_adapter_shape(): void
    {
        $this->loginAndSelect('konveksi');

        $this->withHeader('X-CSRF-TOKEN', csrf_token())
            ->postJson('/api/rpc.php', ['method' => 'loadSemuaData', 'args' => []])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('result.bahan.0.nama', 'Kain')
            ->assertJsonPath('result.__meta.domain', 'konveksi');
    }

    public function test_konveksi_adapter_rejects_a_foreign_company_before_reading_data(): void
    {
        config()->set('jago.konveksi_database_isolated', false);

        $this->expectException(\DomainException::class);
        app(KonveksiPilotReadService::class)->overview('OTHER-COMPANY');
    }

    public function test_konveksi_adapter_rejects_a_table_mapping_outside_the_allowlist(): void
    {
        DB::table('system_table_map')->where('api_key', 'bahan')->update(['table_name' => 'jl_data_unexpected']);
        config()->set('jago.konveksi_database_isolated', false);

        $this->expectException(\RuntimeException::class);
        app(KonveksiPilotReadService::class)->overview('LOCAL-COMPANY');
    }

    public function test_pilot_routes_require_the_selected_workspace(): void
    {
        $this->postJson('/api/rpc.php', ['method' => 'prosesLoginBackend', 'args' => ['owner', 'owner-secret']])->assertOk();

        $this->inertiaGet('/pilot/marketplace?store=store-a')->assertForbidden();
        $this->inertiaGet('/pilot/konveksi')->assertForbidden();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fixtureOrders(): array
    {
        return [
            $this->order('FINAL', ['gross_amount' => 225000, 'finance_revenue' => 128340, 'net_income' => 104002, 'total_fees' => 120998, 'total_cogs' => 59000, 'is_estimated' => false]),
            $this->order('EST', ['gross_amount' => 100000, 'net_income' => 80000, 'total_cogs' => 45000, 'is_estimated' => true], 'IN_TRANSIT'),
            $this->order('UNKNOWN', ['gross_amount' => 50000, 'net_income' => null, 'total_cogs' => null], 'AWAITING_SHIPMENT'),
            $this->order('CANCEL', ['net_income' => 0, 'total_cogs' => 59000], 'CANCELLED'),
            $this->order('UNPAID', [], 'UNPAID'),
        ];
    }

    /**
     * Execute the actual legacy Marketplace read service against this test's
     * PDO. This intentionally avoids booting the legacy provider or touching
     * the live MySQL connection.
     *
     * @return array<string, mixed>
     */
    private function legacyMarketplaceReport(string $storeId, array $filters): array
    {
        $root = dirname(__DIR__, 3);
        require_once $root.'/app/Modules/Marketplace/Services/MarketplaceDashboardService.php';

        return (new \MarketplaceDashboardService(DB::connection()->getPdo()))->report($storeId, $filters);
    }

    /**
     * Execute the actual legacy Marketplace analytics read against this test's
     * PDO. Session scope is restored immediately after the read.
     *
     * @return array<string, mixed>
     */
    private function legacyMarketplaceAnalyticsReport(string $storeId, array $filters): array
    {
        $root = dirname(__DIR__, 3);
        require_once $root.'/app/Shared/Companies/MarketplaceScopeService.php';
        require_once $root.'/app/Modules/Marketplace/Services/MarketplaceOrderPresentation.php';
        require_once $root.'/app/Modules/Marketplace/Services/MarketplaceAnalyticsService.php';

        $hadCompany = array_key_exists('company_key', $_SESSION ?? []);
        $previousCompany = $_SESSION['company_key'] ?? null;
        $_SESSION['company_key'] = 'LOCAL-COMPANY';

        try {
            return (new \MarketplaceAnalyticsService(DB::connection()->getPdo()))->report($filters + [
                'store_id' => $storeId,
                'analytics_stores' => [],
                'include_disconnected' => false,
            ]);
        } finally {
            if ($hadCompany) {
                $_SESSION['company_key'] = $previousCompany;
            } else {
                unset($_SESSION['company_key']);
            }
        }
    }

    /**
     * Execute the actual legacy Konveksi collection read against this test's
     * PDO. Only allowlisted collection reads are exercised here.
     *
     * @return array<string, mixed>
     */
    private function legacyKonveksiData(): array
    {
        $root = dirname(__DIR__, 3);
        require_once $root.'/app/Core/Database/Database.php';
        require_once $root.'/app/Shared/Support/SnapshotImporter.php';
        require_once $root.'/app/Shared/Support/CollectionStore.php';
        require_once $root.'/app/Modules/Konveksi/Services/KonveksiDatabase.php';
        require_once $root.'/app/Modules/Konveksi/Repositories/KonveksiCollectionStore.php';

        // The isolated-database flag is a scope fact. Mirror the legacy
        // deployment flag in this isolated fixture before reading Laravel.
        $legacyConfig = \KonveksiDatabase::config();
        config()->set('jago.konveksi_database_isolated', (bool) ($legacyConfig['isolated'] ?? false));
        \KonveksiDatabase::assertCompany();

        return (new \KonveksiCollectionStore(DB::connection()->getPdo()))->allKnown();
    }

    /**
     * `loaded_at` is intentionally generated by each runtime and is not a
     * parity field. All data, checksums and health values remain comparable.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function withoutVolatileKonveksiMeta(array $data): array
    {
        unset($data['__meta']['loaded_at']);

        return $data;
    }

    private function normalizeParityValue(mixed $value): mixed
    {
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->normalizeParityValue($item);
            }

            return $value;
        }

        return is_int($value) || is_float($value) ? (float) $value : $value;
    }

    private function insertMarketplaceFixture(): void
    {
        foreach ($this->fixtureOrders() as $order) {
            DB::table('marketplace_orders')->insert([
                'id' => $order['id'],
                'store_id' => 'store-a',
                'source_kind' => 'api',
                'order_date' => $order['order_date'],
                'order_status' => $order['order_status'],
                'gross_sales' => $order['gross_sales'],
                'transaction_detail_json' => $order['transaction_detail_json'],
                'internal_state' => 'active',
            ]);
        }
        DB::table('marketplace_orders')->insert([
            'id' => 'MANUAL', 'store_id' => 'store-a', 'source_kind' => 'manual', 'order_date' => '2026-10-15 12:00:00',
            'order_status' => 'COMPLETED', 'gross_sales' => 999999, 'transaction_detail_json' => '{}', 'internal_state' => 'active',
        ]);
    }

    private function order(string $id, array $snapshot, string $status = 'COMPLETED'): array
    {
        return [
            'id' => $id,
            'source_kind' => 'api',
            'order_date' => '2026-10-15 12:00:00',
            'order_status' => $status,
            'gross_sales' => 999999,
            'transaction_detail_json' => json_encode($snapshot + ['source_status' => $status]),
        ];
    }

    private function loginAndSelect(string $workspace, string $username = 'owner', string $password = 'owner-secret'): void
    {
        $this->postJson('/api/rpc.php', ['method' => 'prosesLoginBackend', 'args' => [$username, $password]])
            ->assertOk()->assertJsonPath('result.status', 'success');
        $this->withHeader('X-CSRF-TOKEN', csrf_token())
            ->postJson('/api/workspace.php', ['workspace' => $workspace])
            ->assertOk()->assertJsonPath('workspace', $workspace);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function postExpense(array $payload, string $idempotencyKey): TestResponse
    {
        return $this->withHeader('X-CSRF-TOKEN', csrf_token())
            ->withHeader('X-Idempotency-Key', $idempotencyKey)
            ->postJson('/api/marketplace-ajax.php?action=add_expense', array_merge([
                'store_id' => 'store-a',
                'category' => 'Iklan',
            ], $payload));
    }

    private function inertiaGet(string $uri): TestResponse
    {
        $headers = [
            'X-Inertia' => 'true',
            'X-Requested-With' => 'XMLHttpRequest',
            'Accept' => 'text/html, application/xhtml+xml',
        ];
        $manifest = public_path('build/manifest.json');
        if (is_file($manifest)) {
            $headers['X-Inertia-Version'] = hash_file('xxh128', $manifest);
        }

        return $this->withHeaders($headers)->get($uri);
    }
}
