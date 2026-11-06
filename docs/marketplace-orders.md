> **Catatan arsip (6 Nov 2026):** dokumen ini ditulis saat aplikasi masih PHP murni. Jalur file, skrip, dan kelas yang disebut di bawah (`app/Modules`, `public/*.php`, `bin/workers`, dll.) sudah dihapus dan digantikan Laravel; lihat [penghapusan kode lama](architecture/legacy-removal.md) dan [arsitektur aktif](architecture/../architecture.md). Perilaku bisnis yang dijelaskan tetap berlaku.

# Modul Pesanan per toko

Menu Pesanan berada di bawah Dashboard Toko. Tab: Semua, Pesanan Baru, Menunggu Pickup, Dikirim, Dibatalkan, Dikomplain. Sumber adalah marketplace_orders source_kind=api dan raw marketplace_sync_records untuk toko aktif yang sudah divalidasi terhadap perusahaan. Tidak ada salinan database order baru.

Pengelompokan berbeda per platform: TikTok AWAITING_SHIPMENT → baru, AWAITING_COLLECTION → pickup; Shopee READY_TO_SHIP → baru, PROCESSED → pickup. Status pengiriman dan pembatalan yang dikonfirmasi masuk kelompok masing-masing. Pengajuan pembatalan tidak dianggap batal. Belum bayar, selesai, diterima, atau status belum dipetakan tetap terlihat di Semua. Badge operasional dan status sumber tersedia terpisah. Komplain hanya diperlakukan aktif jika ada fakta eksplisit complaint_active=true; flag has_return saja tidak menyatakan kasus aktif. Karena konektor belum mengisi cakupan kasus lengkap, UI tidak menampilkan angka nol seolah semua komplain sudah diketahui.

Fitur: filter periode/search order/SKU/produk/resi/penerima, kurir, pembayaran terkonfirmasi dan kondisi tambahan; pagination 25/50/100; detail produk/paket/timeline yang tersedia; salin nomor; tautan transaksi; ekspor CSV server sesuai filter maksimal 5.000 order. Detail mempertahankan penyamaran nama/telepon sumber. Nilai pesanan memakai payment.total_amount/total_amount sumber jika tersedia, bukan net settlement. Paket tidak dihasilkan dari perkiraan jumlah unit.

Pembaruan membaca database setiap 60 detik saat halaman terlihat dan setelah perubahan job sinkronisasi. Filter URL, checkbox pesanan yang masih ada dan posisi gulir tabel dipertahankan. Tidak ada pemanggilan API per baris. Counts dan halaman dibaca dalam transaksi konsisten. Summary-only digunakan kartu dashboard agar tidak memuat semua detail pesanan. Indeks status sumber tersimpan/generated pada marketplace_orders telah diterapkan melalui migration_marketplace_order_status_index.php, mempercepat pemetaan status tanpa parsing JSON berulang. Tidak mengubah payload historis.

Dokumen resi TikTok Shipping: tombol Cetak Resi mengambil PDF A6 asli melalui GET `/fulfillment/202309/packages/{package_id}/shipping_documents` dengan shop_cipher koneksi toko. ID paket berasal dari raw order toko; bila belum tersedia dibaca ulang melalui GET detail pesanan. Tidak mengatur pengiriman/pickup atau mengubah status. Setiap paket mendapat tautan Unduh PDF dan Pratinjau PDF terautentikasi. Maksimal 20 pesanan per aksi UI, 10 paket per pesanan. Dokumen disimpan terenkripsi selama 15 menit, dibatasi perusahaan/toko/pengguna, hash integritas dan audit metadata tanpa token atau URL CDN bertanda tangan. Unduhan server memvalidasi HTTPS, DNS publik setiap redirect, TLS, ukuran maksimum 8 MiB dan format PDF. Resi belum diatur, sudah pickup, batal, Seller Shipping, atau izin belum tersedia diberi alasan terpisah. Shopee belum memiliki driver PDF terverifikasi sehingga diarahkan ke Seller Center.

Referensi resmi: https://partner.tiktokshop.com/docv2/page/get-package-shipping-document-202309. Izin fulfillment harus tersedia pada token toko. Pengambilan PDF tidak membuktikan bahwa label sudah dicetak fisik.

Verifikasi unduhan 26 Oktober 2026: satu paket JAGOIN berhasil menghasilkan PDF asli 62.772 byte, satu halaman 298 � 420 poin, barcode terlihat dan identitas pesanan pada teks PDF cocok dengan permintaan. PDF dibaca pypdf dan dirender Poppler. Fixture pengujian memverifikasi cache, enkripsi, isolasi perusahaan/pengguna, pembatalan, kedaluwarsa dan penolakan alamat unduhan internal.

Perbaikan adapter: pertahankan total_amount, data penerima/kurir/paket/waktu jika memang diberikan Shopee; tidak menambahkan permintaan optional-field yang belum terverifikasi. COD yang tidak diberikan tetap tidak diketahui. IN_CANCEL tidak lagi disimpan sebagai pembatalan terkonfirmasi untuk pembaruan berikutnya. Guard stale-update dan worker yang sudah ada tetap digunakan.

Verifikasi: marketplace_orders_smoke mencakup pemetaan dua platform, jumlah tab lintas halaman, search/filter, source API, pembatalan belum selesai, beberapa data paket/penerima/nilai pembayaran, status asing dan isolasi perusahaan. marketplace_sync_smoke, marketplace_order_status_smoke dan marketplace_dashboard_smoke berhasil. Render PHP strict halaman Pesanan, endpoint HTML/CSV dan dashboard berhasil. Browser menguji detail, pemilihan pesanan, penjelasan label, desktop dan 390px tanpa overflow halaman (tabel memakai gulir internal). Bukti visual menggunakan data pengujian.

Keterbatasan yang terlihat di UI: data komplain aktif belum lengkap; label bergantung pada kesiapan paket dan izin fulfillment; data penerima, resi atau timeline yang tidak diberikan sumber ditandai Belum tersedia. Tidak ada scraping atau permintaan cookie akun.


Pembaruan daftar pengemasan (26 Oktober 2026): jenis dokumen default SHIPPING_LABEL_AND_PACKING_SLIP menghasilkan PDF resmi gabungan resi dan daftar produk/varian/Seller SKU/jumlah per paket. Cache dibedakan menurut document_type agar resi lama tanpa packing slip tidak terpakai. Migrasi: database/migration_marketplace_shipping_packing_slip.php. Uji unduhan JAGOIN menghasilkan PDF satu halaman 87.098 byte; kolom Product Name, SKU, Seller SKU, Qty dan Qty Total tampil, identitas pesanan cocok. Tidak menyusun ulang atau menimpa barcode.


## Atur Pengiriman dari Jago Laba

Migrasi: `database/migration_marketplace_fulfillment.php`. Konektor ini mendukung TikTok Shipping. Shopee, Seller Shipping, FBT dan opsi khusus tidak diklaim didukung.

Alur: Cetak Resi -> jika error 21023035/21042104, Atur Pengiriman -> baca detail pesanan/paket dan pilihan handover -> Owner/Admin memilih metode/jadwal dan menyetujui -> POST ship -> unduh PDF SHIPPING_LABEL_AND_PACKING_SLIP. Pilihan berasal dari GET handover_time_slots (`can_pickup`, `can_drop_off`, `pickup_slots[].avaliable`). Pickup hanya tampil dengan slot tersedia yang belum lewat. Alamat dan layanan berasal dari paket toko, tanpa pilihan kurir rekaan. Paket gabungan menampilkan semua order yang terdampak.

API resmi yang diverifikasi:
- https://partner.tiktokshop.com/docv2/page/ship-package-202309
- https://partner.tiktokshop.com/docv2/page/get-package-handover-time-slots-202309
- https://partner.tiktokshop.com/docv2/page/get-package-detail-202309

Keamanan dan ketahanan: CSRF, perusahaan/toko aktif, role Owner/Admin, offer token acak terikat pengguna/order/paket selama 10 menit, pengecekan ulang status dan slot tepat sebelum menulis, MySQL advisory lock per paket, serta state persisten marketplace_fulfillment_actions. Klik ganda dan timeout tidak otomatis mengirim ulang POST. State requested/uncertain dipastikan kembali melalui status order API pada Periksa Status. Jika status tetap belum pasti, permintaan tetap ditahan. Dokumen yang masih dibuat dicoba ulang maksimal 3 kali (2/4/6 detik), tanpa pengaturan ulang pengiriman.

Endpoint konfirmasi melanjutkan eksekusi PHP di server setelah browser ditutup (`ignore_user_abort`, session dilepas), dengan batas 120 detik; status permintaan sudah tersimpan sebelum POST. Jika proses server berhenti mendadak, state tetap requested dan tidak otomatis diulang. Buka dialog dan Periksa Status untuk rekonsiliasi. Tidak ada antrean fulfillment terjadwal terpisah. Pembaruan tabel pesanan selanjutnya mengikuti sinkronisasi API toko yang sudah ada; aplikasi tidak mengarang status Dikirim.

Uji: marketplace_fulfillment_smoke memverifikasi offered-only tidak menulis API, token/jadwal palsu, kelayakan berubah, pembatalan, keanggotaan paket, role/perusahaan, Seller Shipping, satu POST pada konfirmasi, timeout tanpa resend, dan rekonsiliasi. Probe toko nyata JAGOIN berhasil membaca pickup/drop-off dan 3 slot dari marketplace melalui service. Tidak ada POST ship ke pesanan nyata selama pengujian. Browser dengan mock berlabel data pengujian memverifikasi pilihan pickup, drop-off, persetujuan, konfirmasi, lalu tautan PDF; lebar 390px tidak meluber dari dialog.
