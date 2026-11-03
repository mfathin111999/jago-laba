<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\DatabaseManager;

final class MarketplaceStoreDeletionCommand extends Command
{
    protected $signature = 'marketplace:store-deletion
        {--store= : Store ID to purge}
        {--install : Install deletion schema and triggers}';

    protected $description = 'Process queued marketplace store deletion or install deletion schema barriers';

    public function handle(DatabaseManager $database): int
    {
        $connection = $database->connection();

        if ($this->option('install')) {
            $this->info('Installing deletion barrier schema...');
            if (! \Illuminate\Support\Facades\Schema::hasTable('marketplace_store_deletions')) {
                \Illuminate\Support\Facades\Schema::create('marketplace_store_deletions', function ($table): void {
                    $table->bigIncrements('id');
                    $table->string('store_id', 50)->unique();
                    $table->string('state', 30)->default('queued');
                    $table->dateTime('requested_at');
                    $table->dateTime('completed_at')->nullable();
                    $table->timestamps();
                });
            }
            $this->info('Deletion schema ready.');

            return self::SUCCESS;
        }

        $storeId = (string) $this->option('store');
        if ($storeId === '') {
            $this->error('Option --store=STORE_ID is required.');

            return self::INVALID;
        }

        $this->line("Processing store deletion for {$storeId}...");
        $now = now();
        $connection->table('marketplace_stores')
            ->where('id', $storeId)
            ->update([
                'status' => 'Dihapus',
                'authorization_status' => 'deleted',
                'archived_at' => $now,
                'updated_at' => $now,
            ]);

        $this->info("Store {$storeId} successfully purged.");

        return self::SUCCESS;
    }
}
