> **Catatan arsip (6 Nov 2026):** dokumen ini ditulis saat aplikasi masih PHP murni. Jalur file, skrip, dan kelas yang disebut di bawah (`app/Modules`, `public/*.php`, `bin/workers`, dll.) sudah dihapus dan digantikan Laravel; lihat [penghapusan kode lama](architecture/legacy-removal.md) dan [arsitektur aktif](architecture/../architecture.md). Perilaku bisnis yang dijelaskan tetap berlaku.

# Auto sync pesanan

Pengaturan berada di Pengaturan Global → Tambah Toko, per toko. Nilai awal seluruhnya nonaktif. Mengaktifkan pengaturan menyimpan pilihan dan meminta Task Scheduler menjalankan worker segera. Browser hanya membaca progres; penarikan API tetap berlangsung setelah halaman ditutup.

- Status: setiap 5 menit, berdasarkan waktu pembaruan order.
- Transaksi baru: polling 1 menit berdasarkan waktu pembuatan, dengan overlap 5 menit.
- Belum dibayar: polling 1 menit dengan status UNPAID.

Penarikan awal dimulai pukul 00.00 WIB tanggal 1 bulan toko pertama kali diintegrasikan, sampai waktu penarikan. Batas awal disimpan di sync_history_start_at dan tidak bergeser saat bulan atau tahun berganti. Sinkronisasi berlanjut dari penarikan terakhir dengan overlap 5 menit. Shopee membagi permintaan ke rentang maksimal 7 hari dan mengikuti pagination tiap rentang. Upsert dan penjagaan identitas order mencegah duplikasi; HPP historis dan override manual dipertahankan. Auto sync hanya menarik order, bukan mengarang settlement atau biaya marketplace. Sinkronisasi keuangan tetap menggunakan jalur keuangan tersendiri.

## Instalasi Windows

Jalankan migration_marketplace_auto_sync.php dengan PHP CLI. Jalankan isi scripts/install-marketplace-auto-sync.ps1 melalui PowerShell untuk mendaftarkan task JagoLaba-Marketplace-AutoSync pada pengguna Windows aktif. Mesin dan MySQL harus menyala; task menggunakan sesi pengguna Windows yang login. Task berhasil terpasang pada mesin pengembangan ini.

Worker: scripts/marketplace-auto-sync.php. Task berjalan setiap menit dengan jendela tersembunyi. Lock database mencegah dua scheduler berjalan bersamaan. Semua query dibatasi toko/perusahaan dan pengaturan hanya dapat diubah OWNER/ADMIN dengan CSRF. Token dibaca melalui vault dan diperbarui melalui layanan OAuth.

Halaman menunjukkan heartbeat penjadwal, terakhir berhasil per mode, dan kode kegagalan tanpa token. Gagal API dijadwalkan ulang 5 menit kemudian. Menonaktifkan menghentikan batch berikutnya; respons API yang sudah dalam proses dapat menyelesaikan batch tersebut. Status otorisasi diperiksa lagi sebelum data ditulis. Tab transaksi memperbarui tampilan setelah penarikan berhasil saat pengguna tidak mengedit formulir.

Adapter order TikTok dan Shopee diuji menggunakan fixture. Akses produksi tetap bergantung pada otorisasi dan izin order toko. Pengujian otomatis tidak mengaktifkan mode pada toko produksi.

## Finance TikTok

Mode finance aktif untuk toko TikTok terotorisasi, berjalan setiap 5 menit. GET /finance/202507/orders/unsettled mengambil estimasi resmi secara paginasi. Pesanan DELIVERED/COMPLETED kemudian diperiksa dengan GET /finance/202501/orders/{order_id}/statement_transactions. Data final tidak ditimpa oleh estimasi; pencatatan statement tidak dianggap bukti pencairan bank. HTTP 429 mengikuti jeda ulang scheduler, bukan pengulangan tanpa batas.

API dapat mengembalikan total biaya lebih lengkap daripada rincian komponennya. Selisih dipertahankan sebagai biaya/pajak belum terinci. Nominal tidak diambil dari screenshot. HPP historis dan override manual dipakai untuk laba; bila HPP belum tersedia, laba tetap kosong. Laba estimasi diberi penanda estimasi.
