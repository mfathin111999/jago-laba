<?php

namespace Tests\Feature;

use App\Auth\AuthenticatedUser;
use App\Services\Marketplace\MarketplaceHppService;
use DomainException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

final class MarketplaceHppRecalculationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('marketplace_stores', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_key');
            $table->timestamp('archived_at')->nullable();
        });
        Schema::create('marketplace_orders', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('store_id');
            $table->string('order_date');
            $table->decimal('net_settlement_amount', 16, 2)->nullable();
            $table->decimal('total_cogs_hpp', 16, 2)->default(0);
            $table->decimal('net_margin_profit', 16, 2)->nullable()->storedAs('net_settlement_amount - total_cogs_hpp'); // generated, like the real schema
            $table->longText('transaction_detail_json')->nullable();
        });
        Schema::create('marketplace_order_items', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('order_id');
            $table->string('sku');
            $table->integer('qty');
            $table->decimal('unit_cogs_hpp', 16, 2)->default(0);
            $table->decimal('subtotal_cogs', 16, 2)->default(0);
        });
        Schema::create('marketplace_hpp_history', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('store_id');
            $table->string('marketplace_sku');
            $table->string('product_variant_id')->nullable();
            $table->decimal('unit_hpp', 16, 2);
            $table->string('effective_from');
            $table->string('source')->nullable();
            $table->string('reason')->nullable();
            $table->string('changed_by')->nullable();
        });
        Schema::create('marketplace_hpp_changes', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('store_id');
            $table->string('sku');
            $table->decimal('old_hpp', 16, 2)->nullable();
            $table->decimal('new_hpp', 16, 2);
            $table->string('changed_by')->nullable();
            $table->integer('affected_orders')->default(0);
            $table->dateTime('created_at')->nullable();
        });
        Schema::create('marketplace_catalog_variants', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('store_id');
            $table->string('sku');
        });
        Schema::create('marketplace_sku_mappings', function (Blueprint $table): void {
            $table->string('store_id');
            $table->string('marketplace_sku');
        });
        Schema::create('marketplace_product_ads', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('store_id');
            $table->string('sku');
            $table->decimal('unit_cogs_hpp', 16, 2)->nullable();
        });

        DB::table('marketplace_stores')->insert([
            ['id' => 'a', 'company_key' => 'A', 'archived_at' => null],
            ['id' => 'b', 'company_key' => 'B', 'archived_at' => null],
        ]);
        DB::table('marketplace_catalog_variants')->insert([['store_id' => 'a', 'sku' => 'S1']]);
        DB::table('marketplace_hpp_history')->insert(['store_id' => 'a', 'marketplace_sku' => 'S1', 'unit_hpp' => 10, 'effective_from' => '2026-01-01']);
        DB::table('marketplace_orders')->insert([
            ['id' => 'O-OLD', 'store_id' => 'a', 'order_date' => '2026-10-01 10:00:00', 'net_settlement_amount' => 100, 'total_cogs_hpp' => 20],
            ['id' => 'O-NEW', 'store_id' => 'a', 'order_date' => '2026-11-03 10:00:00', 'net_settlement_amount' => 100, 'total_cogs_hpp' => 30],
            ['id' => 'O-UNKNOWN', 'store_id' => 'a', 'order_date' => '2026-11-03 11:00:00', 'net_settlement_amount' => null, 'total_cogs_hpp' => 5],
        ]);
        DB::table('marketplace_order_items')->insert([
            ['order_id' => 'O-OLD', 'sku' => 'S1', 'qty' => 2, 'unit_cogs_hpp' => 10, 'subtotal_cogs' => 20],
            ['order_id' => 'O-NEW', 'sku' => 'S1', 'qty' => 3, 'unit_cogs_hpp' => 10, 'subtotal_cogs' => 30],
            ['order_id' => 'O-UNKNOWN', 'sku' => 'OTHER', 'qty' => 1, 'unit_cogs_hpp' => 5, 'subtotal_cogs' => 5],
        ]);
        DB::table('marketplace_product_ads')->insert([['store_id' => 'a', 'sku' => 'S1', 'unit_cogs_hpp' => 10]]);
    }

    private function service(): MarketplaceHppService
    {
        return app(MarketplaceHppService::class);
    }

    private function user(): AuthenticatedUser
    {
        return new AuthenticatedUser(['id' => 'u1', 'username' => 'admin', 'role_id' => 'ROLE-OWNER_SUPER_ADMIN']);
    }

    private function order(string $id): object
    {
        return DB::table('marketplace_orders')->where('id', $id)->first();
    }

    public function test_a_new_hpp_only_touches_orders_from_its_effective_date_onward(): void
    {
        $affected = $this->service()->updateHpp($this->user(), 'A', 'a', 'S1', '20', false, '2026-11-01');

        $this->assertSame(1, $affected);
        $old = $this->order('O-OLD');
        $this->assertEquals([20, 80], [$old->total_cogs_hpp, $old->net_margin_profit], 'older orders keep the cost they were sold at');
        $new = $this->order('O-NEW');
        $this->assertEquals([60, 40], [$new->total_cogs_hpp, $new->net_margin_profit], '3 x 20 = 60 cost, margin 100 - 60');
        $this->assertEquals(20, DB::table('marketplace_order_items')->where('order_id', 'O-NEW')->value('unit_cogs_hpp'));
        $this->assertEquals(10, DB::table('marketplace_order_items')->where('order_id', 'O-OLD')->value('unit_cogs_hpp'));

        $history = DB::table('marketplace_hpp_history')->orderBy('id')->get();
        $this->assertCount(2, $history, 'the old price stays in the history');
        $this->assertEquals([10, 20], [$history[0]->unit_hpp, $history[1]->unit_hpp]);
        $change = DB::table('marketplace_hpp_changes')->first();
        $this->assertEquals([10, 20, 1], [$change->old_hpp, $change->new_hpp, $change->affected_orders]);
    }

    public function test_explicit_recalculation_rewrites_the_whole_history(): void
    {
        $affected = $this->service()->updateHpp($this->user(), 'A', 'a', 'S1', '15', true, '2026-11-01');

        $this->assertSame(2, $affected);
        $this->assertEquals([30, 70], [$this->order('O-OLD')->total_cogs_hpp, $this->order('O-OLD')->net_margin_profit]);
        $this->assertEquals([45, 55], [$this->order('O-NEW')->total_cogs_hpp, $this->order('O-NEW')->net_margin_profit]);
    }

    public function test_orders_without_that_sku_or_without_a_known_net_are_left_alone(): void
    {
        $this->service()->updateHpp($this->user(), 'A', 'a', 'S1', '15', true);

        $unrelated = $this->order('O-UNKNOWN');
        $this->assertEquals(5, $unrelated->total_cogs_hpp);
        $this->assertNull($unrelated->net_margin_profit, 'an unknown margin stays unknown');
        $this->assertEquals(15, DB::table('marketplace_product_ads')->where('sku', 'S1')->value('unit_cogs_hpp'));
    }

    public function test_invalid_input_and_foreign_or_unknown_skus_are_refused_without_changes(): void
    {
        foreach ([['S1', '-5', InvalidArgumentException::class], ['S1', '1,5', InvalidArgumentException::class], ['', '5', InvalidArgumentException::class]] as [$sku, $amount, $exception]) {
            try {
                $this->service()->updateHpp($this->user(), 'A', 'a', $sku, $amount);
                $this->fail('Expected '.$exception);
            } catch (\Throwable $caught) {
                $this->assertInstanceOf($exception, $caught);
            }
        }

        foreach ([['a', 'TIDAK-ADA'], ['b', 'S1']] as [$store, $sku]) {
            try {
                $this->service()->updateHpp($this->user(), 'A', $store, $sku, '5');
                $this->fail('Expected a refusal for '.$store.'/'.$sku);
            } catch (DomainException) {
            }
        }

        try {
            $this->service()->updateHpp($this->user(), 'A', 'a', 'S1', '5', false, '2026-13-45');
            $this->fail('A bad date must be refused.');
        } catch (InvalidArgumentException) {
        }

        $this->assertSame(1, DB::table('marketplace_hpp_history')->count());
        $this->assertEquals(20, $this->order('O-OLD')->total_cogs_hpp);
    }
}
