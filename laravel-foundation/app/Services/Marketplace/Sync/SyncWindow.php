<?php

namespace App\Services\Marketplace\Sync;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use Illuminate\Database\Connection;

final class SyncWindow
{
    public static function checkpoint(?int $now = null): array
    {
        $end = $now ?? time();
        $start = (new DateTimeImmutable('@'.$end))->setTimezone(new DateTimeZone('Asia/Jakarta'))->modify('first day of this month')->setTime(0, 0)->getTimestamp();

        return ['policy' => 'calendar_month', 'window_start' => $start, 'window_end' => $end, 'filter' => ['create_time_ge' => $start, 'create_time_lt' => $end + 1]];
    }

    public static function forStore(Connection $db, string $storeId): array
    {
        $window = self::checkpoint();
        $db->statement('UPDATE marketplace_stores SET sync_history_start_at=COALESCE(sync_history_start_at,?) WHERE id=?', [date('Y-m-d H:i:s', $window['window_start']), $storeId]);
        $start = $db->selectOne('SELECT sync_history_start_at FROM marketplace_stores WHERE id=?', [$storeId])?->sync_history_start_at;
        if (! $start) {
            throw new DomainException('Toko sinkronisasi tidak ditemukan.');
        }
        $window['window_start'] = (new DateTimeImmutable((string) $start, new DateTimeZone('Asia/Jakarta')))->getTimestamp();
        $window['filter']['create_time_ge'] = $window['window_start'];

        return $window;
    }

    public static function normalize(array $checkpoint, ?array $window = null): array
    {
        $window ??= self::checkpoint();
        $checkpoint['policy'] = 'calendar_month';
        $checkpoint['window_start'] = max((int) ($checkpoint['window_start'] ?? 0), $window['window_start']);
        $checkpoint['window_end'] = (int) ($checkpoint['window_end'] ?? $window['window_end']);
        $checkpoint['filter'] = $checkpoint['filter'] ?? [];
        $checkpoint['filter']['create_time_ge'] = max((int) ($checkpoint['filter']['create_time_ge'] ?? 0), $checkpoint['window_start']);
        $checkpoint['filter']['create_time_lt'] = $checkpoint['window_end'] + 1;

        return $checkpoint;
    }
}
