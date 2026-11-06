# Runbook database: install baru dan cutover (Laravel artisan)

Semua skema database kini dikelola migrasi Laravel. Perintah di bawah dijalankan dari `laravel-foundation/`.

## A. Install baru (database kosong)
1. Buat database (`utf8mb4_unicode_ci`) dan isi kredensial `DB_*` di `.env`.
2. `php artisan migrate --force` — tabel Laravel, Konveksi (39 `jl_data_*` + pendukung), Marketplace/sistem (46 tabel).
3. `php artisan konveksi:import-snapshot` — data awal spreadsheet (menolak menimpa tabel berisi).
4. `php artisan jago:create-owner --username=owner --password=...` — login Owner.
5. Periksa: `php artisan konveksi:schema-check` dan `php artisan marketplace:schema-check` (exit 0).
6. Pasang trigger hapus toko: `php artisan marketplace:store-deletion --install`.

`scripts/development/run-local.ps1` melakukan langkah 1-5 otomatis untuk database lokal kosong.

## B. Database yang sudah berjalan (bangunan legacy) — adopsi
1. Cadangkan database.
2. `php artisan konveksi:schema-check` dan `php artisan marketplace:schema-check` (read-only) untuk melihat selisih.
3. `php artisan migrate --force` — hanya membuat yang belum ada; tabel yang ada tidak diubah.
4. Ulangi kedua schema-check sampai exit 0.

## C. Memisahkan database Konveksi (opsional)
1. Pastikan user MySQL aplikasi boleh `CREATE DATABASE`, `CREATE USER`, `GRANT` (hanya saat langkah ini).
2. `php artisan konveksi:isolate --prepare` — menyalin data ke `jago_laba_konveksi` (`--target=` mengganti nama), membandingkan setiap baris, membuat user khusus (hanya SELECT/INSERT/UPDATE/DELETE di database itu) dan membuktikan user itu tidak bisa membaca tabel Marketplace. Sumber tidak diubah; target yang sudah berisi tabel tidak ditimpa.
3. Tambahkan `--activate` untuk menulis `storage/private/konveksi/database.php`.
4. Pastikan `JL_KONVEKSI_DATABASE_ISOLATED` di `.env` tidak bernilai `false` (nilai `.env` mengalahkan file konfigurasi); set `true` atau hapus barisnya.
5. Rollback: hapus `storage/private/konveksi/database.php` (dan set flag di atas kembali); aplikasi kembali memakai database utama, yang tidak pernah diubah.

## Catatan
- `down()` migrasi hanya jalan di `local`/`testing`; di lingkungan lain melempar exception.
- Migrasi Marketplace hanya untuk MySQL/MariaDB (DDL memakai kolom generated).
- Jika `CREATE USER`/`GRANT` gagal dengan error Aria "wrong checksum", tabel `mysql.db` server rusak: cadangkan `data/mysql/db.*` lalu `REPAIR TABLE mysql.db`.
