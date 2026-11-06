> **Catatan arsip (6 Nov 2026):** dokumen ini ditulis saat aplikasi masih PHP murni. Jalur file, skrip, dan kelas yang disebut di bawah (`app/Modules`, `public/*.php`, `bin/workers`, dll.) sudah dihapus dan digantikan Laravel; lihat [penghapusan kode lama](../architecture/legacy-removal.md) dan [arsitektur aktif](../architecture/../architecture.md). Perilaku bisnis yang dijelaskan tetap berlaku.

# Sinkronisasi katalog Shopee

Implementasi dan verifikasi: 27 Oktober 2026.

## Alur

Produk Toko → Sinkronisasi Produk → job katalog toko terotorisasi → Shopee Product API → normalisasi produk/model → marketplace_catalog_variants dan snapshot stok → tampilan Produk/HPP/Inventori toko.

Sinkronisasi Data lengkap Shopee sekarang memasukkan products dan orders. Alur otorisasi yang sudah ada mengantrekan products/variants/orders; keduanya memakai pembaca katalog yang sama. Sinkronisasi produk tidak memfilter tanggal pembuatan produk berdasarkan bulan pesanan: seluruh katalog aktif dibaca melalui pagination.

Endpoint baca yang dipakai dan sudah menerima respons dari toko terhubung:

- GET /api/v2/product/get_item_list: item_status NORMAL, pagination offset dengan next atau next_offset.
- GET /api/v2/product/get_item_base_info: identitas, nama, status, gambar dan produk tanpa model.
- GET /api/v2/product/get_model_list: varian, SKU, harga dan stok model.

Implementasi berada di app/Modules/Marketplace/Integrations/Shopee/ShopeeCatalogDataClient.php. Path /product/202309/... di kontrak internal pipeline diterjemahkan ke endpoint Shopee di atas; bukan dikirim sebagai endpoint TikTok ke Shopee.

## Pemetaan

- Produk: item_id. Varian: item_id:model_id, sama dengan adapter pesanan; produk tanpa model memakai model_id 0.
- Varian aktif menerima NORMAL dan MODEL_NORMAL sesuai respons sumber. Produk nonaktif/deleted tidak dimuat sebagai produk aktif.
- SKU model digunakan; bila kosong, fallback SKU induk konsisten dengan adapter pesanan. SKU yang tidak tersedia tidak diganti ID sintetis.
- Harga sekarang dan harga asli berasal dari price_info. Angka nol dipertahankan. Data tidak tersedia tetap null.
- Stok menggunakan total_available_stock dari summary_info; seller stock tidak dijumlahkan lagi ke summary sehingga tidak dihitung ganda. Payload lama mendukung stock_info bila tersedia.
- Nama model berasal dari model_name atau tier_index/tier_variation. HPP tetap berasal dari pengaturan HPP lokal; penarikan katalog tidak menulis ulang HPP atau laba transaksi.

Produk yang hilang disembunyikan setelah seluruh halaman berhasil dipindai. Varian yang tidak ada lagi disembunyikan setelah detail produk lengkap diterima. Jika izin/pagination/detail gagal, job gagal dan katalog lama yang belum terlihat pada scan tidak dihapus. Pengaturan HPP dan riwayat tetap disimpan.

## Keamanan dan batas

Memakai token terenkripsi dan konfigurasi aplikasi Shopee yang sudah tersimpan, signature per shop, host resmi yang diizinkan, company/store scope, serta lease job yang sudah tersedia. Tidak mengirim update harga/stok/promo ke Shopee. Izin Product API masih mengikuti aplikasi dan otorisasi seller; keberhasilan order API tidak dipakai sebagai bukti katalog berhasil.

Auto sync pesanan tidak diubah menjadi polling seluruh katalog per menit. Pembaruan katalog tersedia melalui tombol Sinkronisasi Produk, sinkronisasi lengkap, dan job awal otorisasi sesuai runner yang sudah ada.

## Verifikasi

Pengujian fixture: signature, GET-only, pagination, MODEL_NORMAL, single/multiple variant, harga nol, stok tidak diketahui, parent SKU, kegagalan di tengah scan, deduplikasi job, isolasi perusahaan, tampilan katalog dan HPP tidak ditimpa. Regresi sinkronisasi TikTok/Shopee order serta katalog/HPP juga lulus.

Penarikan aktual pada satu toko Shopee terverifikasi berhasil: **2 produk, 5 varian**, seluruh 5 varian memiliki harga dan stok. listingForStore juga mengembalikan 2 produk/5 varian; hasil ini bukan data seed/fixture. Jumlah tersebut adalah snapshot verifikasi, dapat berubah mengikuti toko.

Diagnostik CLI (administrator server): `php scripts/diagnostics/sync-shopee-catalog.php` hanya menghitung toko terverifikasi. Tambahkan `--start --store=ID_TOKO --steps=30` untuk menjalankan job baca katalog; token/secret tidak ditampilkan. Bila hanya ada satu toko terverifikasi, pemilihan store dapat dihilangkan. Untuk banyak toko, tentukan store secara eksplisit.
