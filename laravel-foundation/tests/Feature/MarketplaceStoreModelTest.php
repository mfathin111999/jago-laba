<?php

namespace Tests\Feature;

use App\Authorization\RequestContext;
use App\Models\Marketplace\MarketplaceStore;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class MarketplaceStoreModelTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('marketplace_stores', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('company_key');
            $table->string('platform');
            $table->string('store_name');
            $table->timestamp('archived_at')->nullable();
            $table->decimal('default_admin_fee_pct', 8, 2)->nullable();
        });
        foreach ([
            ['id' => 'a1', 'company_key' => 'A', 'platform' => 'Shopee', 'store_name' => 'Toko A1', 'default_admin_fee_pct' => 11],
            ['id' => 'a2', 'company_key' => 'A', 'platform' => 'Lazada', 'store_name' => 'Toko A2 lama', 'archived_at' => '2026-10-01 00:00:00'],
            ['id' => 'b1', 'company_key' => 'B', 'platform' => 'Shopee', 'store_name' => 'Toko B1'],
        ] as $store) {
            DB::table('marketplace_stores')->insert($store);
        }
    }

    public function test_for_company_limits_the_query_to_that_company(): void
    {
        $this->assertSame(['a1', 'a2'], MarketplaceStore::query()->forCompany('A')->orderBy('id')->pluck('id')->all());
        $this->assertSame(['b1'], MarketplaceStore::query()->forCompany('B')->pluck('id')->all());
    }

    public function test_active_and_archived_scopes_split_by_archived_at(): void
    {
        $this->assertSame(['a1'], MarketplaceStore::query()->forCompany('A')->active()->pluck('id')->all());
        $this->assertSame(['a2'], MarketplaceStore::query()->forCompany('A')->archived()->pluck('id')->all());
    }

    public function test_the_active_company_is_enforced_automatically_inside_a_request(): void
    {
        app(RequestContext::class)->setCompanyKey('A');

        $this->assertSame(['a1', 'a2'], MarketplaceStore::query()->orderBy('id')->pluck('id')->all(), 'no explicit filter still only sees company A');
        $this->assertSame([], MarketplaceStore::query()->forCompany('B')->pluck('id')->all(), 'asking for another company finds nothing instead of leaking');
        $this->assertSame(['b1'], MarketplaceStore::withoutGlobalScopes()->forCompany('B')->pluck('id')->all(), 'only an explicit opt-out reaches another company');
    }

    public function test_without_an_active_company_only_the_explicit_filter_applies(): void
    {
        app(RequestContext::class)->reset();

        $this->assertCount(3, MarketplaceStore::query()->get());
        $this->assertSame(['b1'], MarketplaceStore::query()->forCompany('B')->pluck('id')->all());
    }

    public function test_attributes_keep_the_raw_database_values(): void
    {
        $store = MarketplaceStore::query()->forCompany('A')->find('a1');

        $this->assertSame('a1', $store->getKey());
        $this->assertSame(['id', 'company_key', 'platform', 'store_name', 'archived_at', 'default_admin_fee_pct'], array_keys($store->getAttributes()));
        $this->assertNull($store->getAttributes()['archived_at']);
    }
}
