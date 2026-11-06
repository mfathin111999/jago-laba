# Migrasi Laravel untuk database Konveksi (tingkat A)

Konveksi awalnya aplikasi Google Apps Script dengan Google Sheets sebagai database. Tiap sheet menjadi satu tabel `jl_data_<kunci>` yang menyimpan seluruh record sebagai JSON (`_data_json`). Skema tabel itu sekarang dikelola oleh migrasi Laravel, bukan lagi oleh `SnapshotImporter`/`SchemaManager`/`bin/konveksi-setup.php`.

## Yang dibuat
| Migrasi | Isi |
|---|---|
| `2026_11_10_000001_create_konveksi_support_tables` | `system_settings`, `system_table_map`, `system_audit_logs`, `backup_log`, `document_files`, `idempotency_keys`, `konveksi_request_receipts` |
| `2026_11_10_000002_create_konveksi_collection_tables` | 39 tabel `jl_data_*` + 38 baris `system_table_map` (sheet → kunci → tabel) |

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
- Belum dikerjakan: `konveksi:import-snapshot` (port `SnapshotImporter` untuk memuat data awal), `konveksi:isolate` (port `bin/konveksi-setup.php`), serta pemakaian `migrate` di `run-local` dan runbook cutover.
