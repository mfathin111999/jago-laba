<?php

use App\Services\Marketplace\MarketplaceStoreDeletionService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Installs the write-barrier triggers (jl_erase_*) that stop writes to a store while it is being permanently deleted.
 * Until now they were installed lazily on the first deletion request; the schema now belongs to migrations, and the
 * service only verifies them. Adopt-only: triggers that already exist are left untouched. MySQL/MariaDB only.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        app(MarketplaceStoreDeletionService::class)->install();
    }

    public function down(): void
    {
        // Triggers are kept: dropping them would silently allow writes to a store that is being erased.
    }
};
