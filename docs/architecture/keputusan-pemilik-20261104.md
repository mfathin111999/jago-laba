# Lembar keputusan pemilik — 4 November 2026

Tujuan: semua keputusan yang menahan penutupan migrasi, dalam satu halaman. Setiap baris punya **rekomendasi** dan alasannya.
Cukup beri tanda pada kolom "Keputusan Anda" (setuju / ganti dengan: …). Rekomendasi bukan persetujuan sampai Anda memberi tanda.

## A. Data (butuh Anda, tidak boleh diputuskan otomatis)

| # | Masalah | Rekomendasi | Keputusan Anda |
|---|---|---|---|
| A1 | **17 referensi stok tanpa varian** (`STK-*` tidak ada di `product_variants`): 1.720 unit, nilai tersirat Rp62.494.000. Satu benturan nyata: stok ID 4 punya 418 unit berharga Rp45.000, sedangkan master kandidatnya 0 unit. | Jangan dipetakan otomatis. Anda mengisi pemetaan per ID memakai berkas rujukan `docs/deployment/orphan-variant-owner-reconciliation-20261101.md`. Sampai selesai, baris tetap disimpan dan ditandai "belum terpetakan". Tidak ada yang dihapus. | |
| A2 | **5 item pesanan tanpa induk** (7 unit, harga Rp630.000, HPP Rp320.000). | Pertahankan sebagai riwayat, kecualikan dari laporan, sampai pesanan induknya Anda tunjuk. | |
| A3 | **5 produk Konveksi tanpa `id_stok_item`.** Kandidat tersedia lewat kode SKU yang sama persis di Master Stok (contoh: `JAG-MONO-ABUGERY`). | Setujui pemetaan hanya untuk kecocokan kode SKU yang persis sama; sisanya Anda periksa manual. | |
| A4 | **HPP yang belum diketahui tersimpan sebagai 0** di tiga kolom `NOT NULL` (`total_cogs_hpp`, `net_settlement_amount`, `unit_cogs_hpp`). Tampilan Laravel membaca snapshot JSON (jadi benar), tetapi laporan SQL langsung bisa menganggapnya biaya nol. | Biarkan skema; larang laporan SQL langsung memakai kolom itu tanpa memeriksa snapshot. Ubah skema ke `NULL` hanya setelah cutover. | |

## B. Fitur dan kode lama

| # | Pertanyaan | Rekomendasi | Keputusan Anda |
|---|---|---|---|
| B1 | **4 stub lama:** `save_api_credentials`, `update_stock_allocation`, `run_safety_buffer_sync`, `marketplace-webhook`. | Buang tiga yang pertama (kredensial lewat OAuth/`.env`; dua lainnya butuh konektor Live yang belum ada). `marketplace-webhook` tetap nonaktif sampai Fase E; ganti dulu `webhook_global_secret` yang berupa teks tetap di repo. | |
| B2 | **`install.php` dan `repair_owner.php`** masih ada di folder web. | Pindahkan ke CLI (`bin/`) dan hapus dari web root saat cutover. Keduanya bisa mengatur ulang login Owner. | |
| B3 | **Kunci API partner (Shopee/TikTok):** form web atau `.env`? | `.env`/berkas rahasia di server, tanpa form web. Kunci tidak tersimpan di database atau log audit. Halaman Integrasi cukup menampilkan status "terkonfigurasi". | |
| B4 | **Log audit Konveksi** disimpan di database mana? | Satu jejak audit yang sama dengan Marketplace (`system_audit_logs`), kecuali Anda wajib memisahkan database Konveksi. | |
| B5 | **Tabel akses ruang kerja** (`workspace_user_access`): draf DDL legacy memakai `workspace_code` + `is_allowed`. | Sudah ditangani: Laravel kini mendukung kedua bentuk, termasuk pencabutan akses. Anda hanya menentukan apakah memasang tabelnya. Tanpa tabel, Owner/Admin dapat dua ruang kerja dan peran lain hanya Konveksi. | |
| B6 | **File SQL berlabel "REVIEW ONLY"** (`migration_workspace_review.sql`, `migration_marketplace_integration_review.sql`, `migration_normalized_3nf.sql`). | Jangan dijalankan di data operasional. Jika sudah pernah dijalankan di produksi, tabel `_v2`/`_v3` yang terbentuk harus dihapus atau diklasifikasi, karena penghapusan toko menolak berjalan saat ada tabel ber-`store_id` yang belum dikenal. | |

## C. Arah produk

| # | Pertanyaan | Rekomendasi | Keputusan Anda |
|---|---|---|---|
| C1 | **UI Konveksi:** tulis ulang ke Vue/Inertia atau tetap UI lama? | Tetap UI lama (sudah disajikan lewat Laravel dan lolos uji tulis nyata). Nilai ulang setelah sebulan pemakaian; tulis ulang hanya menu yang bermasalah. | |
| C2 | **Adapter sinkron provider nyata + webhook (E2):** bangun atau tetap stub? | Bangun hanya setelah ada akun sandbox (E1). Mulai dari TikTok Shop, satu sumber data (pesanan) dulu. Sampai itu, aplikasi tetap menyatakan terus terang bahwa sinkron tidak mengambil data provider. | |
| C3 | **Pengeluaran lintas toko dan produk lintas toko** (belum ada halamannya). | Tunda sampai ada permintaan nyata dari pemakai. | |

## D. Yang saya butuhkan dari Anda untuk bisa lanjut ke UAT

1. **Dump database asli terbaru** (Marketplace + Konveksi), atau akses ke salinannya. Folder lama di drive D: sudah tidak bisa diakses.
2. **Pemilik akun provider** (Shopee, TikTok Shop) bila Fase E dijalankan, atau pernyataan bahwa E ditunda.
3. **Operator hosting** untuk HTTPS, cron + worker, dan izin file (Fase D). Prasyarat lengkap ada di rencana.

Catatan: lembar ini ditulis untuk Anda sebagai pemilik. Jika ada orang lain yang berwenang atas data (misalnya pemilik inventori), kirimkan bagian A kepadanya.
