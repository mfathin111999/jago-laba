# Penghapusan kode PHP lama

Tanggal: 6 November 2026. Aplikasi PHP murni (`app/`, `bin/`, `config/`, `database/`, `public/`, `routes/`, `resources/`, `storage/`, `tests/`, dan skrip pendukung) sudah dihapus dari repositori. **Versi terakhir sebelum penghapusan ada di tag git `legacy-php-final`** (`git checkout legacy-php-final`).

## Apa yang menggantikan

| Dulu (PHP lama) | Sekarang (`laravel-foundation/`) |
|---|---|
| `LegacyRuntime`, `LiveGateway` memuat kelas lama lewat `require_once` | Service ber-namespace di `app/Services/Marketplace/**`, di-autoload dan di-inject |
| `$_SESSION['company_key' / 'user_id' / 'role']` | `MarketplaceScope` (perusahaan dari `RequestContext`, pengguna dari sesi Laravel atau diteruskan eksplisit untuk job) |
| `getenv()` / `putenv()` | `config()` (`config/services.php`, `config/jago.php`) |
| `MarketplaceHttpClient` (curl) | `MarketplaceHttp` di atas `Http::` Laravel, dengan pemeriksaan host (`MarketplaceApiOrigin`) |
| Penandatangan permintaan di dalam klien | `ShopeeSigner`, `TikTokSigner` |
| `MarketplaceTokenVault` (libsodium) | `TokenVault` memakai `Crypt` (kunci `APP_KEY`) |
| Klien OAuth/data Shopee dan TikTok | `ShopeeOAuthProvider`, `TikTokShopOAuthProvider`, `ShopeeCatalogDataClient`, `ShopeeOrderDataClient`, `TikTokDataClient` |
| `MarketplaceSyncService` | `Sync\SyncService` (opsi lewat `with()`), antrean lewat `MarketplaceSyncJob` / `MarketplaceLiveSyncJob` |
| Pembuatan tabel saat runtime (`install()`, `SchemaManager`) | Migrasi Laravel (skema Marketplace, Konveksi, tabel bertipe, trigger penghapusan toko) |
| Tampilan SPA Konveksi di `app/Modules/Konveksi/Views` | `laravel-foundation/resources/konveksi/views` |
| Snapshot data awal `database/legacy` | `laravel-foundation/database/snapshots` |
| Sesi PHP asli yang disalin ke Laravel (`LegacySessionStore`, `LegacySessionBridge`) | Sesi Laravel saja |

## Tes

Perbandingan lama-vs-baru yang dulu menjalankan kelas lama kini membaca keluaran yang ditangkap sebelum penghapusan: `tests/Feature/fixtures/legacy/` (dasbor, analitik, CSV/XLSX, Konveksi, katalog) dan `tests/Unit/fixtures/role-policy-matrix.json`.

## Yang berubah bagi operator

- Token marketplace lama (terenkripsi dengan kunci libsodium) tidak terbaca; sambungkan ulang toko. Tidak ada data produksi sebelum ini.
- Kunci `JL_MARKETPLACE_TOKEN_ENCRYPTION_KEY(_FILE)` tidak dipakai lagi; semua rahasia dienkripsi dengan `APP_KEY`.
- Cron/worker: gunakan `php artisan schedule:run` dan `php artisan queue:work` (lihat `routes/console.php`); skrip `bin/workers/*.php` sudah tidak ada.
- Titik masuk `public/*.php` lama tidak ada; semua URL `*.php` yang masih dipakai klien (mis. `/api/rpc.php`) adalah rute Laravel.
