> **Catatan arsip (6 Nov 2026):** dokumen ini ditulis saat aplikasi masih PHP murni. Jalur file, skrip, dan kelas yang disebut di bawah (`app/Modules`, `public/*.php`, `bin/workers`, dll.) sudah dihapus dan digantikan Laravel; lihat [penghapusan kode lama](architecture/legacy-removal.md) dan [arsitektur aktif](architecture/../architecture.md). Perilaku bisnis yang dijelaskan tetap berlaku.

# Rincian transaksi dari konektor resmi

Jalankan migrasi additive `database/migration_marketplace_transaction_detail.php` sebelum memakai filter COD. Migrasi menambahkan kolom nullable; transaksi lama dipertahankan.

Setelah konektor memverifikasi respons resmi dan menyimpan pesanan API di `marketplace_orders`, panggil `MarketplaceService::saveVerifiedOrderDetails($orderId, $storeId, $details)`. Ini kontrak internal data normalisasi, bukan nama field endpoint Shopee dan bukan endpoint unggah dari browser. Transport/API resmi belum diaktifkan oleh perubahan ini.

`$details` memuat nomor resi (`tracking_number`), metode pembayaran (`payment_method`), boolean sumber (`is_cod`, `is_estimated`), status mentah (`source_status`), waktu pembaruan sumber (`source_updated_at`, format `Y-m-d H:i:s`, waktu aplikasi Asia/Jakarta), waktu pelepasan dana bila tersedia (`released_at`), `gross_amount`, `total_fees`, `net_income`, `total_cogs`, dan `adjustment_amount`.

`components` memuat nominal `admin_fee`, `service_fee`, `actual_shipping`, `withholding_tax`, `seller_voucher`, `shipping_subsidy`, `processing_fee`, `shipping_adjustment`, `other_deductions`, `affiliate_commission`, dan `live_commission` sejauh tersedia. Subsidi mengurangi biaya. Jangan memasukkan komponen yang sama dua kali atau menebak nilai yang tidak dikembalikan sumber.

`items` memuat `name`, `variant`, `sku`, `image_url`, `quantity`, `returned_quantity`, `unit_price`, `total_price`, `unit_cogs`, dan `total_cogs`. HPP harus berasal dari snapshot HPP Marketplace yang sudah dipetakan; jangan mengambil HPP workspace lain secara implisit. Nilai belum diketahui tetap null atau tidak disertakan.

Respons `get_order_detail` menyertakan `presentation` untuk tabel dan detail. Resi/pembayaran ditampilkan dari fakta sumber. `READY_TO_SHIP`/`PROCESSED` ditampilkan Perlu Dikirim, `SHIPPED` Sedang Dikirim. Status sumber tetap tersimpan. Status pesanan tidak otomatis membuktikan dana sudah cair. Hanya tandai pendapatan final atau tanggal pelepasan bila sumber keuangan resmi mendukungnya.

Pengiriman ulang dengan timestamp lebih lama diabaikan. Penulisan dibatasi toko perusahaan aktif dan pesanan API, tanpa perubahan rekening, stok, atau jurnal. Total pendapatan dari sumber dipertahankan; jika tidak cocok dengan komponen, UI menampilkan selisih. Detail dibuka di bawah baris transaksi.

Contoh uji terdapat pada `tests/marketplace_transaction_detail_smoke.php`; tidak di-seed ke database aplikasi.
