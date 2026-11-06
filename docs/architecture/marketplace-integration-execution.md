> **Catatan arsip (6 Nov 2026):** dokumen ini ditulis saat aplikasi masih PHP murni. Jalur file, skrip, dan kelas yang disebut di bawah (`app/Modules`, `public/*.php`, `bin/workers`, dll.) sudah dihapus dan digantikan Laravel; lihat [penghapusan kode lama](legacy-removal.md) dan [arsitektur aktif](../architecture.md). Perilaku bisnis yang dijelaskan tetap berlaku.

# Hasil implementasi integrasi marketplace

Tanggal: 27 Oktober 2026. Workspace: `D:/xampp/htdocs/jago-laba`.

## Perubahan

- Adapter baca Shopee/TikTok terpisah melalui kontrak internal; OAuth dan signing tetap native masing-masing platform.
- Kebijakan capability dan status per sumber; endpoint status berizin perusahaan/toko, retry admin dengan CSRF.
- Binding konfigurasi pada OAuth dan pemakaian token, perlindungan refresh bersamaan, cooldown dan retry job baca terukur.
- Pengaturan â†’ Tambah Toko menampilkan kartu platform, toko, sumber data, status akses, progres dan tindakan. Dialog otorisasi/konfirmasi responsif, mencegah klik ganda. Ads yang belum tersedia tidak mengarahkan ke otorisasi Shop semu.
- Konfigurasi API tetap untuk administrator; TikTok memiliki pilihan lingkungan eksplisit. Data lama, HPP, konfigurasi dan public compatibility routes dipertahankan.

## Migrasi dan pemulihan

Migrasi additive: `database/migrations/marketplace/migration_marketplace_integration_runtime.php` sudah dijalankan pada database lokal. Menambah binding, capability evidence, dan cooldown. Tidak menghapus tabel/data toko, pesanan atau HPP.

Backup sebelum perubahan: `D:/JAGO_LABA_BACKUP/integration/source-20261027-100455.zip` dan `before-integration-runtime-20261027-101418.sql` (27.983.024 byte). Pada deployment baru, jalankan migrasi eksplisit dan `composer dump-autoload --optimize`, lalu verifikasi scheduler/worker dan konfigurasi callback HTTPS domain resmi. Jangan menyalin secret ke dokumentasi atau webroot.

## Verifikasi

- Fixture database terisolasi: OAuth state/callback, fingerprint berubah, tenant isolation, dua proses refresh bersamaan, retry/backoff/Retry-After, kegagalan sumber independen, pencegahan duplikasi dan HPP.
- Regresi katalog Shopee/TikTok, finance, status pesanan, stok, harga, PDF, fulfillment dan browser auto-sync scope lulus.
- PHP lint pada integrasi, services, API, views dan workers; Composer classmap; pemeriksaan struktur proyek.
- Browser Playwright desktop 1440Ã—1080 dan ponsel 390Ã—844: sembilan status capability, modal/keyboard, tidak ada overflow atau error JS, satu permintaan sync per klik. API browser dimock agar tidak melakukan write/otorisasi Live.
- Pembacaan nyata katalog Shopee berhasil: **2 produk aktif, 5 varian**, termasuk harga dan stok. Kegagalan/izin TikTok tetap ditentukan dari evidence toko masing-masing; tidak diklaim seluruh endpoint TikTok sudah lulus akses Live dalam pengujian ini. Uji baca langsung juga mengonfirmasi respons TikTok tanpa orders saat total_count=0; job pesanan terkait berhasil setelah respons kosong ditangani secara eksplisit.

Artefak QA privat: `storage/temporary/integration-qa/`. Preview memakai fixture, bukan data pelanggan. `scripts/diagnostics/integration-health.php` mencetak ringkasan aman tanpa nama toko/token/customer payload.

## Batas yang tetap terlihat

Lihat [matriks konektor](../integrations/marketplace-integration-matrix.md). Ads, penarikan API, serta beberapa konektor Shopee belum tersedia. Webhook belum aktif. Auto-sync masih memerlukan tab aktif; worker manual dapat berjalan setelah halaman ditutup. Kuota dan izin final mengikuti portal partner, bukan status OAuth.
