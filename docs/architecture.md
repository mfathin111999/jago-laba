# Arsitektur Jago Laba

Aplikasi adalah satu aplikasi Laravel 13 (PHP 8.4, Inertia + Vue 3) di `laravel-foundation/`. Kode PHP lama sudah dihapus; versi terakhirnya ada di tag git `legacy-php-final` (lihat [penghapusan kode lama](architecture/legacy-removal.md)).

## Modul

| Modul | Tanggung jawab | Kode |
| --- | --- | --- |
| Marketplace | toko, pesanan, settlement, retur, biaya, HPP, stok, harga, pengiriman, sinkronisasi Shopee/TikTok | `app/Services/Marketplace/**`, `MarketplaceAjaxController`, `Marketplace*Controller` |
| Konveksi | dashboard, master, HPP, stok, pembelian, penjualan, keuangan, pengguna; SPA lama disajikan dari `resources/konveksi/views` lewat `KonveksiAppController`, RPC di `/api/rpc.php` (`KonveksiRpcController`) | `app/Services/Konveksi/**` |
| Auth & otorisasi | sesi Laravel, `AuthenticatedUser` (`Authorizable`), ability per peran, middleware alias lama dipertahankan | `app/Auth`, `app/Authorization`, `app/Policies` |
| Audit | event `AuditRecorded` + listener `WriteAuditLog` | `app/Audit` |

Tidak ada pemotongan stok implisit antar modul; transfer harus eksplisit.

## Alur request

```text
Browser -> routes/*.php -> middleware (sesi, CSRF, konteks perusahaan, kontrak AJAX)
        -> Controller / FormRequest (authorize per ability)
        -> Service ber-namespace (constructor injection, MarketplaceScope untuk perusahaan + pelaku)
        -> Connection Laravel (SQL ber-binding) -> JSON {success,message,code,request_id} atau Inertia
```

`/api/rpc.php` dan URL `*.php` lain yang dipakai klien adalah rute Laravel. Kontrak JSON, kode error, nama route dan nama method RPC tidak berubah.

## Database

Seluruh skema dibuat oleh migrasi artisan (`php artisan migrate`): tabel Marketplace/sistem, 33 tabel koleksi Konveksi (30 di antaranya bertipe `kv_*` dengan `_extra_json`), dan 70 trigger penghapusan toko. Kolom generated (`net_margin_profit`, `roas`, `source_order_status`) tidak boleh ditulis (dijaga `GeneratedColumnsTest`). Data demo: `php artisan db:seed --class=MarketplaceDemoSeeder`.

Akses SQL: layanan Marketplace memakai trait `RunsSql` di atas `Connection` Laravel (baris tidak ada = `null`); tidak ada PDO mentah (dijaga `ServicesResolveTest`).

## Integrasi eksternal

`MarketplaceHttp` (di atas `Http::`, timeout 8/25 dtk, tanpa redirect, allow-list host `MarketplaceApiOrigin`), `ShopeeSigner`/`TikTokSigner`, provider OAuth (`LiveOAuthProvider`, `ShopeeOAuthProvider`, `TikTokShopOAuthProvider`, factory live/sandbox), `TokenRefresher`, token terenkripsi `Crypt` (`APP_KEY`). Konfigurasi di `config/services.php`. Sinkronisasi: `Sync\SyncService` lewat `MarketplaceSyncJob`/`MarketplaceLiveSyncJob` dan `routes/console.php`.
