<?php

namespace Tests\Feature;

use App\Jobs\MarketplaceLiveSyncJob;
use App\Jobs\MarketplaceSyncJob;
use Tests\TestCase;

final class QueuedJobCompatibilityTest extends TestCase
{
    public function test_jobs_queued_under_their_old_class_names_still_resolve_to_the_moved_classes(): void
    {
        $this->assertTrue(is_a('App\Pilots\Marketplace\MarketplaceSyncJob', MarketplaceSyncJob::class, true));
        $this->assertTrue(is_a('App\Pilots\Marketplace\MarketplaceLiveSyncJob', MarketplaceLiveSyncJob::class, true));
    }

    public function test_a_payload_serialized_with_the_old_class_name_unserializes_into_the_new_class(): void
    {
        $job = new MarketplaceSyncJob('LOCAL-COMPANY', 'STORE-001', 1, 1, 'compat-001', 'request-compat-001');
        $oldPayload = str_replace('O:'.strlen(MarketplaceSyncJob::class).':"'.MarketplaceSyncJob::class.'"', 'O:'.strlen('App\Pilots\Marketplace\MarketplaceSyncJob').':"App\Pilots\Marketplace\MarketplaceSyncJob"', serialize($job));

        $this->assertInstanceOf(MarketplaceSyncJob::class, unserialize($oldPayload));
    }
}
