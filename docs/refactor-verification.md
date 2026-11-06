> **Catatan arsip (6 Nov 2026):** dokumen ini ditulis saat aplikasi masih PHP murni. Jalur file, skrip, dan kelas yang disebut di bawah (`app/Modules`, `public/*.php`, `bin/workers`, dll.) sudah dihapus dan digantikan Laravel; lihat [penghapusan kode lama](architecture/legacy-removal.md) dan [arsitektur aktif](architecture/../architecture.md). Perilaku bisnis yang dijelaskan tetap berlaku.

# Verifikasi Refaktor

## Pemeriksaan yang wajib dijalankan

```powershell
php -l app/bootstrap.php
php -l app/Core/Database.php
php -l app/Http/JsonResponse.php
php -l app/Http/RpcRequest.php
php -l app/Modules/Konveksi/Module.php
php -l app/Modules/Marketplace/Module.php
php tests/workspace_policy.php
php tests/workspace_migration_smoke.php
php tests/marketplace_ingestion_smoke.php
```

## Kriteria runtime

- `public/index.php` menampilkan workspace Konveksi dan memuat view melalui manifest modul.
- `public/marketplace.php` tetap dapat dibuka dengan URL lama dan memuat service melalui module loader.
- RPC tetap menggunakan bentuk respons `{ok,result}` agar `jl-api.js` dan frontend lama tidak rusak.
- API error tidak mengembalikan stack trace, SQL, credential, atau secret.
- `window.google` tidak diperlukan; `window.jlApi` menjadi API client.
- Scope workspace dan scope toko tetap dipaksa oleh `Workspace`/`MarketplaceScopeService`.
- Tidak ada migration, seed, reset database, atau penghapusan data yang dijalankan oleh refaktor organisasi.

## Catatan baseline

Arsip pembanding `C:\xampp\htdocs\jago-laba.rar` tidak ditemukan saat inventarisasi. Repository aktif belum memiliki commit tracked; seluruh folder proyek berada sebagai file kerja. Karena itu perubahan dilakukan aditif dan kompatibilitas path lama dipertahankan.
