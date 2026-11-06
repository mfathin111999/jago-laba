> **Catatan arsip (6 Nov 2026):** dokumen ini ditulis saat aplikasi masih PHP murni. Jalur file, skrip, dan kelas yang disebut di bawah (`app/Modules`, `public/*.php`, `bin/workers`, dll.) sudah dihapus dan digantikan Laravel; lihat [penghapusan kode lama](architecture/legacy-removal.md) dan [arsitektur aktif](architecture/../architecture.md). Perilaku bisnis yang dijelaskan tetap berlaku.

# Sinkronisasi Sandbox TikTok

Menu: `marketplace.php?scope=global&tab=sinkronisasi`. Hanya administrator dalam workspace Marketplace aktif yang dapat memulai atau memproses antrean. Dukungan pengembangan dibatasi ke koneksi TikTok terverifikasi dengan nama toko sumber berawalan SANDBOX. Shopee dan toko produksi tidak dijalankan oleh worker ini.

Migrasi: `database/migration_marketplace_sync_worker.php`, setelah migrasi OAuth, katalog, HPP, dan rincian transaksi. Menambahkan lease job dan tabel sumber `marketplace_sync_records`; tidak menghapus transaksi lama.

Dataset run penuh:
- Produk: Search Products 202309 lalu Get Product per ID. Varian, harga dan stok sumber disimpan di katalog yang dibaca Produk Global.
- Orders: Get Order List 202309 lalu Get Order Detail per ID. Snapshot status mentah, item dan pembayaran dipertahankan. Order terhubung ke halaman transaksi/pemasukan yang sama. HPP memakai histori internal; nominal tidak diketahui disimpan sebagai null pada snapshot.
- Transactions: Get Transactions by Order 202501 per order. Pendapatan sumber diperbarui tanpa menyatakan uang sudah masuk bank. Rincian keuangan lengkap diarsipkan di tabel sumber; total potongan ditampilkan sebagai potongan gabungan sampai pemetaan komponen finance lebih detail diselesaikan.
- Returns: Search Returns 202602. `return_orders` disimpan sebagai sumber tersendiri; respons Sandbox total_count=0 boleh tidak menyertakan list. Ringkasan ID retur tersedia pada konsol. Tidak ada perubahan otomatis ke status order atau stok berdasarkan retur yang belum ditinjau.

Referensi resmi:
- https://partner.tiktokshop.com/docv2/page/search-products-202309
- https://partner.tiktokshop.com/docv2/page/get-order-list-202309
- https://partner.tiktokshop.com/docv2/page/get-transactions-by-order
- https://partner.tiktokshop.com/docv2/page/search-returns-202602

Setiap POST ditandatangani dengan query terurut dan JSON body yang sama dengan request. Header Content-Type hanya dikirim sekali. Token terenkripsi dibuka hanya untuk request, tidak dikembalikan ke browser. Kode error platform disimpan tanpa body/pesan yang berisiko memuat kredensial. Source ID dan mata uang diperiksa; hanya IDR dinormalisasi ke laporan Rupiah.

Satu langkah worker mengambil satu halaman atau satu rincian. Checkpoint dan sumber tersimpan atomik dengan lease job; token halaman berulang ditolak. Penulisan ulang menggunakan ID sumber sehingga tidak menggandakan order/item. Lease berjalan 5 menit dan dapat diambil kembali setelah timeout. Koneksi/otorisasi diperiksa ulang setelah request dan sebelum penulisan. Job finance menunggu orders. Run dinyatakan berhasil hanya jika semua job pada run berhasil. Kegagalan tetap terlihat; klik Sinkronisasi Seluruhnya memulai run baru setelah run aktif selesai. Iklan tidak termasuk API toko dan memerlukan konektor iklan terpisah.

Browser memanggil endpoint `public/api/marketplace-sync.php` dengan POST+CSRF action background. Launcher Windows memulai PHP CLI tersembunyi, dengan log file dan heartbeat di marketplace_sync_workers; migrasinya ada di database/migration_marketplace_background_sync.php. Proses tetap hidup setelah browser ditutup. Browser hanya memantau status tiap empat detik. Perkiraan awal ~1 menit diperbarui berdasarkan waktu berjalan, record tersimpan dan sisa checkpoint. Worker maksimum satu jam per proses dan memakai advisory lock per toko agar tidak berjalan ganda. Jika heartbeat lewat 90 detik, status menjadi stalled dan pengguna dapat memulai kembali. Alternatif CLI memproses antrean yang sama:

```text
C:\xampp\php\php.exe scripts/marketplace-cron.php --company=LOCAL-COMPANY --store=STORE_ID --steps=20
```

Tambahkan `--enqueue` untuk run penuh baru, atau `--list` untuk melihat identifier Sandbox yang terhubung tanpa mencetak token. Penjadwalan Windows Task Scheduler belum dipasang otomatis. Setelah membuka kembali halaman gunakan Lanjutkan Antrean.

Tes `tests/marketplace_sync_smoke.php` memakai database sementara dan respons API fixture untuk signing body, pagination, idempotensi, HPP, pembatasan Sandbox, isolasi perusahaan, serta kegagalan scope. Uji nyata pada toko Sandbox terhubung telah mengembalikan sukses untuk produk, order dan finance, serta respons retur kosong. Data ini berasal dari Sandbox, bukan transaksi toko produksi.

## Cara menjalankan sekarang (Laravel)

- Worker: `php artisan queue:work` (job `MarketplaceSyncJob` / `MarketplaceLiveSyncJob`, `WithoutOverlapping` per toko).
- Penjadwalan: `php artisan schedule:run` tiap menit (lihat `routes/console.php`).
- Manual: `php artisan marketplace:sync` (atau tombol Sinkronisasi di UI).
- Skema dibuat oleh `php artisan migrate`; `scripts/marketplace-cron.php` dan `database/migration_marketplace_*.php` sudah tidak ada.
