# Migrasi Laravel untuk database Konveksi (tingkat A)

Konveksi awalnya aplikasi Google Apps Script dengan Google Sheets sebagai database. Tiap sheet menjadi satu tabel `jl_data_<kunci>` yang menyimpan seluruh record sebagai JSON (`_data_json`). Skema tabel itu sekarang dikelola oleh migrasi Laravel, bukan lagi oleh `SnapshotImporter`/`SchemaManager`/`bin/konveksi-setup.php`.

## Yang dibuat
| Migrasi | Isi |
|---|---|
| `2026_11_10_000001_create_konveksi_support_tables` | `system_settings`, `system_table_map`, `system_audit_logs`, `backup_log`, `document_files`, `idempotency_keys`, `konveksi_request_receipts` |
| `2026_11_10_000002_create_konveksi_collection_tables` | 33 tabel `jl_data_*` + 38 baris `system_table_map` (sheet → kunci → tabel) |

- Logika skema ada di `app/Database/KonveksiSchema.php`; daftar koleksi di `database/schema/konveksi.php`.
- Tabel dibuat di database tempat Konveksi berada (`KonveksiConnection`): database terpisah bila diisolasi, atau database bawaan.

## Aturan adopsi (aman di database yang sudah ada)
- Tabel yang sudah ada **tidak pernah diubah atau dihapus**. Menjalankan migrasi di database bangunan legacy tidak mengubah apa pun.
- Baris `system_table_map` yang sudah ada dipertahankan (`insertOrIgnore`).
- Tabel baru hanya punya kolom tetap: `_row_id`, `_record_key`, `_row_order`, `_data_json`, `_created_at`, `_updated_at`. Kolom per-field (`kategori`, `stok`, ...) yang dulu dicerminkan dari header spreadsheet tidak dibuat: aplikasi hanya membaca/menulis `_data_json`, `_row_order`, `_row_id`.
- `down()` hanya jalan di `local`/`testing`; di lingkungan lain melempar exception supaya data operasional tidak hilang.

## Perintah
```
php artisan migrate --force           # membuat tabel yang belum ada
php artisan konveksi:schema-check     # hanya membaca; exit 1 bila ada tabel/kolom/peta yang kurang
```

## Bukti (MariaDB nyata)
- Database kosong → `schema-check` melaporkan tabel hilang; setelah `migrate` → sesuai.
- Database bangunan legacy (`jago_laba`) → `schema-check` sesuai tanpa mengubah apa pun.
- Perbandingan struktur hasil migrasi vs bangunan legacy: 292 kolom, 137 indeks, 46 tabel identik. Satu-satunya selisih: collation `jl_data_hutangsupplier` dan `konveksi_request_receipts` (legacy: default server `general_ci`; migrasi: `utf8mb4_unicode_ci` seperti tabel lain).

## Catatan
- `jl_data_hutangsupplier` adalah tabel "yatim" (ejaan lain dari `hutang_supplier`, tanpa baris peta) yang tetap dibuat agar sama dengan hasil installer legacy.
- `php artisan konveksi:import-snapshot` memuat data awal spreadsheet (`database/snapshots/current_database_snapshot.json`) ke tabel hasil migrasi. Menolak menimpa tabel yang sudah berisi data kecuali `--replace` (di production butuh `--force`); `--only=produk,bahan` membatasi koleksi. Terbukti identik dengan hasil `SnapshotImporter` legacy pada 39 tabel (38 sheet, 226 baris).
- `scripts/development/run-local.ps1` menjalankan `php artisan migrate --force` dan `konveksi:schema-check` pada setiap peluncuran.
- `php artisan jago:create-owner` membuat/mereset login Owner (pengganti `SchemaManager::ensureOwner`). `run-local.ps1` pada database kosong kini hanya memakai artisan: `migrate`, `konveksi:import-snapshot`, `jago:create-owner` (terverifikasi di MariaDB kosong: kedua schema-check sesuai).
- `php artisan konveksi:isolate --prepare [--activate]` memisahkan database Konveksi (menggantikan skrip `bin/konveksi-setup.php` lama); lihat `database-cutover-runbook.md`.

## Tabel Marketplace dan sistem (migrasi 2026_11_10_000003)
- 46 tabel (`marketplace_*`, `system_users`, `auth_users_v2`, `migration_log`) dibuat dari DDL emas `laravel-foundation/database/schema/marketplace.sql`, diambil dari database hasil installer legacy (termasuk kolom generated `net_margin_profit`, `roas`, `source_order_status`). `CREATE TABLE IF NOT EXISTS`: tabel yang ada tidak diubah. Hanya MySQL/MariaDB; test SQLite membuat tabelnya sendiri.
- FK `fk_store_account`, `fk_payout_acc`, `fk_map_variant` menunjuk tabel `accounts`/`product_variants` yang tidak ada di installer legacy; dipertahankan apa adanya dan dibuat dengan `FOREIGN_KEY_CHECKS=0`.
- Trigger `jl_erase_*` (hapus toko) tetap dipasang oleh `marketplace:store-deletion --install`, bukan migrasi.
- Bukti (MariaDB): database kosong + `migrate` → 0 selisih kolom (tipe, null, default, generated) dan 0 tabel hilang dibanding `jago_laba`; `createMissing` di `jago_laba` = 0 tabel baru.
- `php artisan marketplace:schema-check` (read-only) memeriksa tabel dan kolom.
