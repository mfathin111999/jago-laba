<?php

namespace App\Services\Marketplace;

use App\Jobs\MarketplaceSyncJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

class MarketplaceSyncQueuePublisher
{
    public function push(MarketplaceSyncJob $job): string
    {
        $queueConnection = config('queue.connections.database.connection');
        $defaultConnection = DB::getDefaultConnection();

        if ($queueConnection !== null && $queueConnection !== $defaultConnection) {
            throw new RuntimeException('Database queue harus memakai koneksi database transaksi yang sama.');
        }

        if (config('queue.default') !== 'database') {
            throw new RuntimeException('Marketplace sync wajib memakai database queue.');
        }

        $queue = (string) config('jago.sync.queue', 'marketplace');
        $id = Queue::pushOn($queue, $job);

        if (($id === null || $id === false || $id === '') && ! app()->environment('testing')) {
            throw new RuntimeException('Payload sinkronisasi tidak dapat dimasukkan ke database queue.');
        }

        return (string) $id;
    }
}
