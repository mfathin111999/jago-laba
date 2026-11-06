> **Catatan arsip (6 Nov 2026):** dokumen ini ditulis saat aplikasi masih PHP murni. Jalur file, skrip, dan kelas yang disebut di bawah (`app/Modules`, `public/*.php`, `bin/workers`, dll.) sudah dihapus dan digantikan Laravel; lihat [penghapusan kode lama](architecture/legacy-removal.md) dan [arsitektur aktif](architecture/../architecture.md). Perilaku bisnis yang dijelaskan tetap berlaku.

# Produk Global

Menu `marketplace.php?scope=global&tab=produk` memiliki submenu `product_section=hpp`, `harga`, dan `stok`. Produk dikelompokkan menurut toko dan ID produk marketplace; baris anak memakai ID varian/SKU sumber. Penjualan default meliputi hari ini dan 13 hari sebelumnya, mengecualikan pesanan batal, retur, dan arsip. Filter tanggal menggantikan rentang default.

Jalankan `database/migration_marketplace_catalog.php` untuk tabel katalog dan audit HPP. Migrasi additive dan dapat dijalankan ulang. Migrasi rincian transaksi dari pekerjaan sebelumnya juga diperlukan.

`MarketplaceCatalogService::saveVariant($storeId, $variant)` adalah kontrak internal untuk konektor yang telah memverifikasi respons resmi. Data normalisasi: `external_product_id`, `external_variant_id`, `product_name`, `parent_sku`, `sku`, `variant_name`, `image_url`, `price`, `original_price`, `stock_qty`, `source_status`, `source_updated_at` (`Y-m-d H:i:s`). Timestamp lebih lama tidak menimpa katalog terbaru. Ini bukan pemetaan nama field API Shopee/TikTok. Transport pengambilan produk resmi masih perlu dihubungkan ke kontrak tersebut.

Tanpa katalog, daftar memakai observasi transaksi/mapping SKU dengan penanda sumber. Harga observasi adalah harga transaksi terakhir; stok dan status tidak ditebak. Observasi yang memiliki nama produk sama dikelompokkan sebagai tampilan sementara sampai ID produk resmi tersedia.

Edit pensil memanggil `update_global_product_hpp` dengan POST dan CSRF. Nilai desimal memakai titik tanpa pemisah ribuan. Aksi ini secara eksplisit menghitung ulang seluruh histori SKU pada toko terpilih, berbeda dari edit HPP biasa yang hanya mengisi HPP kosong. Perubahan memperbarui item, total HPP dan laba pesanan, snapshot detail, serta HPP marketing. Riwayat menyimpan pelaku dan jumlah pesanan terdampak. Rekening, pendapatan sumber, jurnal, stok, dan master Konveksi tidak diubah.

Konektor pesanan berikutnya harus menerapkan HPP aktif dari `marketplace_hpp_history` saat membentuk snapshot HPP; jangan menimpa pilihan HPP internal dari angka default API. Harga/stock pada tab terkait ditampilkan sebagai fakta sumber, tidak dikirim balik sebagai perubahan ke marketplace.

Pengujian `tests/marketplace_catalog_smoke.php` memakai database sementara tersendiri, memeriksa pengelompokan, penjualan, propagasi HPP, histori audit, isolasi toko, dan render aman.
