> **Catatan arsip (6 Nov 2026):** dokumen ini ditulis saat aplikasi masih PHP murni. Jalur file, skrip, dan kelas yang disebut di bawah (`app/Modules`, `public/*.php`, `bin/workers`, dll.) sudah dihapus dan digantikan Laravel; lihat [penghapusan kode lama](legacy-removal.md) dan [arsitektur aktif](../architecture.md). Perilaku bisnis yang dijelaskan tetap berlaku.

# PRD Jago Laba — Manajemen Konveksi

Versi 1.0 • 28 Oktober 2026 • Status: rancangan pengembangan berdasarkan audit sumber lokal.

Dokumen ini menetapkan perilaku target; keberadaan menu atau kelas pada aplikasi lama tidak membuktikan fitur target telah berfungsi. Penyusunan PRD tidak menghapus data atau mengubah database operasional.

## 1. Tujuan produk

Menjadikan Jago Laba sebagai sistem operasional konveksi yang menghubungkan pembelian bahan, gudang, perencanaan, potong, jahit, QC, barang jadi, penjualan, upah, dan keuangan melalui dokumen yang dapat ditelusuri. Setiap kejadian bisnis dicatat satu kali dan dipakai oleh modul terkait. Frontend menampilkan hasil server; database menjadi sumber kebenaran.

Hasil yang diharapkan:
- Owner mengetahui posisi produksi, bahan, barang jadi, kewajiban, dan keuntungan berdasarkan data lengkap.
- Admin tidak memasukkan transaksi sama pada pembelian, stok, utang, dan keuangan secara terpisah.
- Operator memahami pekerjaan berikutnya, penanggung jawab, tenggat, dan alasan pekerjaan tertahan.
- Marketplace memiliki database, kredensial database, proses, laporan, dan penyimpanan operasional terpisah.
- Operasional tidak memerlukan Google Apps Script, Google Sheets, atau browser yang terus terbuka.

## 2. Kondisi aktual dan pekerjaan yang diperlukan

| Temuan terverifikasi dari sumber | Implikasi | Target |
|---|---|---|
| `app/Modules/Konveksi` berisi Services, Controllers, Views; menu SPK, pembelian, penjualan, HPP dan keuangan tersedia | Struktur awal dapat dipertahankan | Refaktor layanan dan halaman bertahap; validasi fungsi setiap menu |
| `Database::pdo()` memakai satu konfigurasi koneksi | Pemisahan folder belum berarti database terpisah | Koneksi shared, konveksi, marketplace terpisah |
| `CollectionStore::get/put` membaca JSON per tabel dan mengimpor ulang kumpulan baris | Risiko perubahan saling menimpa, beban data besar, kontrol per entitas sulit | Repository relasional, perubahan per dokumen dan optimistic locking |
| `RpcService` menyediakan `syncTab`, `syncMultiTabs`, `loadSemuaData` | Browser masih memiliki akses penyimpanan berbentuk kumpulan data | Endpoint use case dengan validasi izin, tipe data, perusahaan, dan status |
| `InventoryService` menyelaraskan produk/master stok; mempunyai penguncian dan idempotency awal | Kontrol awal berguna tetapi sumber saldo dan batas penguncian perlu dirapikan | Ledger mutasi tunggal dan saldo proyeksi per item/gudang/perusahaan |
| `HppService::activate` menulis produk, BOM, riwayat melalui beberapa operasi terpisah | Kegagalan parsial dapat meninggalkan versi tidak konsisten | Aktivasi versi HPP dalam transaksi database |
| `CollectionStore::allKnown` mencantumkan nama mapping/antrean Marketplace | Batas data antar modul belum bersih | Hapus akses implisit; gunakan kontrak transfer eksplisit |
| Bootstrap memuat modul Konveksi sebelum penentuan domain rute | Request Marketplace membawa dependensi Konveksi | Bootstrap minimal dan pemuatan modul sesuai rute |
| Pencarian runtime tidak menemukan `google.script.run`, `SpreadsheetApp`, `DriveApp`, URL `script.google` | Jangan menyatakan koneksi Apps Script masih aktif tanpa bukti | Uji network dan scan konfigurasi tersimpan sebelum penutupan migrasi |
| Alias `savePDFToDrive`, `syncMultipleTabsToSheets` masih ada di dispatcher | Nama lama tidak membuktikan data dikirim ke Google | Ganti pemanggil dan hapus alias setelah tidak terpakai |

Audit ini belum membuktikan kelengkapan data produksi, rekonsiliasi keuangan, atau hak akses seluruh pengguna melalui pengujian runtime.

## 3. Pengguna dan otorisasi

Peran awal: owner, administrator, pembelian, gudang bahan, perencana produksi, operator potong, admin jahit/vendor, QC, gudang barang jadi, penjualan, keuangan, dan pembaca laporan. Satu pengguna dapat memiliki beberapa peran.

Setiap aksi memiliki izin terpisah: melihat, membuat draft, mengubah draft, menyetujui, memposting, membatalkan dengan reversal, mengekspor, dan mengelola konfigurasi. Cek izin dilakukan di server pada tiap request, termasuk unduhan dokumen dan job. Menyembunyikan tombol tidak cukup. Semua entitas operasional memiliki `company_id`; gudang/cabang dibatasi bila digunakan. Aksi pembayaran dan penyesuaian stok memerlukan alasan serta pelaksana yang tercatat.

## 4. Navigasi tanpa fungsi ganda

| Grup | Menu | Tanggung jawab tunggal |
|---|---|---|
| Ringkasan | Dashboard Operasional | Antrian pekerjaan, hambatan, tenggat, ringkasan sumber |
| Master | Produk & Varian; Bahan & Aksesori; BOM & Standar HPP; Mitra; Pegawai; Gudang & Satuan | Definisi dan aturan, bukan jurnal transaksi |
| Pengadaan | Permintaan Pembelian; Pesanan Pembelian; Penerimaan; Tagihan & Retur | Dokumen pengadaan saling terhubung |
| Produksi | Rencana/Order Produksi; SPK Potong; SPK Jahit; QC & Rework; Penyelesaian Produksi | Tahap satu alur produksi |
| Inventori | Bahan; WIP; Barang Jadi; Transfer; Stock Opname; Riwayat Mutasi | Persediaan satu ledger, beberapa tampilan |
| Penjualan | Pesanan Pelanggan; Pengiriman; Invoice; Retur | Penjualan langsung Konveksi |
| SDM & Upah | Upah Produksi; Pembayaran Upah; Kasbon Pegawai | Hak upah dan pembayaran yang berbeda |
| Keuangan | Kas & Bank; Piutang; Utang; Jurnal; Rekonsiliasi | Penerimaan/pembayaran dan pencatatan keuangan |
| Analitik & Laporan | Produksi, bahan, HPP, stok, penjualan, upah, keuangan | Membaca sumber yang sama tanpa input ulang |
| Pengaturan | Identitas; pengguna; izin; nomor dokumen; persetujuan; periode; backup | Konfigurasi domain Konveksi |

Supplier, vendor jahit, dan pelanggan adalah peran dari master Mitra; tidak membuat identitas orang/perusahaan berulang. Utang usaha dan utang konveksi menjadi filter jenis kewajiban pada satu daftar utang, dengan rincian sumber berbeda. Distribusi adalah transfer/pengiriman yang ditentukan jenisnya, bukan sumber saldo baru. Dashboard eksekutif menjadi tampilan owner pada data yang sama.

## 5. Alur utama operasional

### 5.1 Pengadaan bahan

Permintaan → persetujuan → pesanan pembelian → penerimaan sebagian/penuh → pemeriksaan penerimaan → tagihan supplier → pembayaran sebagian/penuh → selesai.

- Draft pembelian dan PO tidak menambah stok tersedia.
- Stok bertambah dari penerimaan barang yang diposting dan disetujui sesuai kebijakan.
- Tagihan mencatat utang; pembayaran mengurangi utang dan kas/bank sekali.
- Penerimaan belum ditagih mempunyai status tersendiri dan akun penampung bila sistem akrual diaktifkan.
- Kuantitas PO, diterima, diterima QC, diretur, ditagih, dan dibayar dapat ditelusuri terpisah.
- Konversi roll/meter, lusin/pcs, pack/unit harus eksplisit dan mengikuti nilai konversi dokumen. Perubahan master tidak mengubah transaksi historis.
- Harga dan ongkos pembelian mengikuti kebijakan biaya perolehan yang disetujui; pajak tidak diasumsikan tarif tetap.

### 5.2 Perencanaan dan bahan produksi

Order produksi mencatat produk/varian, ukuran/warna, jumlah target, tenggat, BOM versi, perkiraan HPP, gudang, dan penanggung jawab. Order dapat berasal dari pesanan pelanggan atau produksi stok.

Persetujuan menghasilkan kebutuhan bahan dan reservasi. Reservasi tidak memindahkan stok fisik. Pengeluaran bahan ke produksi menghasilkan mutasi keluar bahan dan masuk WIP sesuai metode biaya. Kekurangan bahan ditampilkan sebelum penerbitan SPK. Pengeluaran tambahan, sisa bahan kembali, dan scrap menggunakan dokumen tersendiri yang terhubung ke order.

### 5.3 SPK Potong

Draft → disetujui → bahan diserahkan → dikerjakan → hasil disetor sebagian/penuh → diverifikasi → selesai.

Catat bahan aktual, bundle, ukuran/warna, hasil baik, cacat, sisa, waste dan operator. Setoran tidak otomatis menjadi barang jadi. Selisih pemakaian/hasil membutuhkan alasan dan persetujuan sesuai ambang yang dikonfigurasi.

### 5.4 SPK Jahit internal/vendor

Bundle hasil potong → penugasan → serah terima → dikerjakan → setoran sebagian/penuh → QC.

Catat vendor/pegawai, kuantitas diterima, lokasi WIP, target, tarif versi, tambahan pekerjaan yang disetujui, dan sisa pekerjaan. Serah terima tidak berarti produksi selesai. Retur pekerjaan ke penjahit tetap berada dalam alur WIP. Tidak membuat SPK baru untuk bundle yang sama tanpa relasi pembagian dan saldo kuantitas.

### 5.5 QC, rework dan barang jadi

Setoran jahit → QC → lolos / rework / reject → penerimaan barang jadi.

Hanya hasil lolos dan penerimaan terposting menambah stok tersedia barang jadi. Rework tidak menambah kuantitas produksi baru; biaya tambahan ditelusuri. Reject memiliki keputusan disposisi dan nilai biaya yang jelas. Barang jadi per batch memiliki asal produksi dan HPP aktual/sementara.

### 5.6 Penjualan Konveksi

Pesanan pelanggan → reservasi → pengiriman → invoice → penerimaan pembayaran → selesai. Dukung pengiriman/pembayaran sebagian.

Tentukan kebijakan saat pengakuan penjualan/HPP dan dokumentasikan konsisten. Pengiriman mengurangi stok fisik; invoice tidak menguranginya lagi. Uang muka adalah kewajiban sebelum memenuhi syarat pengakuan penjualan. Retur dipisahkan dari pembatalan; barang kembali memerlukan penerimaan/QC sebelum menjadi stok tersedia. Dokumen terposting dikoreksi melalui reversal atau dokumen koreksi yang dapat ditelusuri.

### 5.7 Upah, kasbon dan kewajiban

Hak upah berasal dari hasil kerja yang diverifikasi dan tarif yang berlaku, bukan jumlah SPK yang diterbitkan. Pembayaran mengurangi utang upah dan kas/bank. Kasbon tidak otomatis menjadi biaya upah; pemotongannya dicatat sebagai pelunasan kasbon pada pembayaran upah. Tampilkan jumlah bruto, potongan sah, kasbon dipotong, bersih dibayar, dan sisa kewajiban. Kendalikan penagihan vendor dan hak upah agar hasil yang sama tidak dibayar dua kali.

## 6. Aturan anti-duplikasi dan konsistensi

- ID internal stabil menjadi relasi utama; nomor dokumen hanya identitas tampilan yang unik dalam perusahaan/jenis/periode.
- Setiap posting memiliki idempotency key dengan ruang lingkup perusahaan, aksi, dokumen dan versi. Simpan hash request; key sama dengan isi berbeda ditolak.
- Gunakan unique constraint pada sumber mutasi/jurnal/pembayaran dan inbox event, bukan sekadar pengecekan frontend.
- Posting memakai transaksi database untuk header, detail, ledger stok, kewajiban dan jurnal dalam domain Konveksi. Gagal satu bagian membatalkan perubahan database terkait.
- Gunakan row lock terarah dan `version` untuk optimistic locking. Dua pengguna yang mengubah versi berbeda menerima konflik yang jelas.
- Tidak memakai nomor urut hasil COUNT+1 tanpa penguncian.
- Browser tidak boleh mengganti seluruh tabel melalui syncTab atau mengirim saldo akhir sebagai fakta utama.
- Saldo bahan/barang, kas, utang dan piutang merupakan proyeksi ledger; tidak diedit langsung dari beberapa menu.
- Tombol yang sedang diproses dinonaktifkan, tetapi server tetap melindungi klik ulang, retry jaringan, dan refresh halaman.
- Dokumen final memiliki jejak siapa/kapan/alasan. Perubahan tanggal mundur mengikuti izin dan penutupan periode.

## 7. Inventori dan HPP yang dapat dibuktikan

Pisahkan bahan, WIP, barang jadi, reserved, quarantine, reject dan available. Lokasi fisik, pemilik barang, dan status stok adalah atribut berbeda; barang titipan/vendor tidak otomatis diakui sebagai stok milik perusahaan.

Saldo fisik = saldo awal + mutasi masuk − mutasi keluar. Tersedia = saldo fisik yang layak − reservasi aktif. Jangan mengurangi saldo tersedia dua kali pada perpindahan reservasi menjadi pengeluaran aktual. Stok negatif ditolak kecuali kebijakan khusus yang eksplisit dan diaudit.

HPP standar = bahan BOM + upah standar + packaging + overhead standar yang didefinisikan. HPP aktual batch = biaya aktual yang dialokasikan dan disetujui ÷ unit hasil baik dengan aturan waste, reject, dan produk samping yang terdokumentasi.

- Standar adalah estimasi perencanaan; tidak diberi label aktual.
- Pilih dan konsistenkan metode valuasi bahan, misalnya rata-rata bergerak; FIFO hanya bila benar-benar diterapkan.
- Tarif upah, konversi satuan, BOM, dan kebijakan overhead memiliki versi/tanggal berlaku.
- Overhead yang dikapitalisasi ke persediaan tidak dikurangkan lagi sebagai biaya periode ketika menghitung laba yang sama.
- Produksi belum lengkap memiliki biaya sementara; hasil tidak lengkap tidak dianggap nol.
- Rekalkulasi historis memerlukan pratinjau, cakupan, hak akses dan jurnal koreksi; tidak menimpa HPP transaksi lama secara diam-diam.

## 8. Keuangan dan laporan

Gunakan jurnal berpasangan untuk dokumen terposting dengan total debit = kredit. Kas/bank memiliki mutasi tersendiri yang terhubung ke pembayaran dan jurnal. Penjualan kredit menambah piutang, bukan kas. Pembelian kredit menambah utang, bukan langsung mengurangi kas. Transfer antar rekening bukan pendapatan atau beban.

Laporan minimum:
- Posisi bahan, mutasi, opname, bahan kurang dan pemakaian versus BOM.
- WIP per order/tahap/vendor, keterlambatan, hasil baik/reject, rework dan produktivitas.
- HPP standar versus aktual per batch/produk/varian; selisih bahan, upah dan overhead.
- Stok barang jadi, umur stok, reservasi dan pergerakan gudang.
- Penjualan, retur, margin setelah HPP, piutang dan umur piutang.
- Kewajiban supplier/vendor/upah, umur utang, kasbon dan pembayaran.
- Buku kas/bank, jurnal, neraca saldo, laba rugi dan arus kas sesuai kebijakan yang diterapkan.

Setiap angka memiliki periode, dasar tanggal, sumber, kelengkapan, waktu pembaruan dan drill-down. Laba kotor = penjualan bersih − HPP terjual. Laba operasional = laba kotor − beban periode yang belum termasuk HPP. Perbedaan antara laba dan kas dijelaskan pada dashboard. Laporan ditandai parsial bila HPP/penerimaan/biaya belum selesai. Ekspor XLSX dan PDF mengikuti filter dan nilai halaman.

## 9. UI/UX operasional nyata

Identitas: navy/oranye Jago Laba, latar terang, hierarki judul jelas, kartu ringkas dan tabel yang terbaca. Gunakan satu pustaka ikon konsisten: dashboard, paket, gunting, mesin jahit, perisai QC, gudang, invoice, dompet, grafik, riwayat, pengaturan. Ikon selalu memiliki label/tooltip yang sesuai.

Halaman daftar: judul, konteks gudang/perusahaan, tombol utama, filter periode/status/mitra, pencarian, jumlah hasil, pagination server, ringkasan relevan. Jangan memuat seluruh data operasional ke browser.

Detail dokumen: nomor/status → identitas dan tanggal → item → biaya/kuantitas → lampiran → timeline → aksi berikutnya. Timeline membedakan dibuat, disetujui, diserahkan, diterima dan diposting. Tombol menyesuaikan status dan izin; aksi belum didukung tidak dibuat seolah aktif.

Ponsel: filter ringkas, aksi utama mudah dijangkau, kartu kerja per SPK, detail item expandable, tabel bergulir di dalam kontainer, modal tidak melewati layar. Dukungan 360/390/768/1440px, navigasi keyboard, fokus terlihat, label input, kontras dan pengurangan animasi.

Notifikasi:
- Berhasil hanya setelah server menyatakan commit berhasil; contoh: “Penerimaan diterapkan: 20 roll pada Gudang Bahan. Nomor GR-…”.
- Draft tersimpan, menunggu persetujuan dan terposting adalah status berbeda.
- Gagal menampilkan alasan, field bermasalah, tindakan koreksi dan reference ID tanpa rahasia.
- Timeout menampilkan “Status belum diketahui — periksa hasil”, bukan menyarankan membuat transaksi baru.
- Konflik versi meminta muat versi terbaru; data yang diketik tetap dapat ditinjau.
- Job mempunyai antrean, berjalan, sebagian gagal, selesai dan percobaan ulang dengan progres aktual. Tidak memberi durasi atau persentase rekaan.

## 10. Arsitektur frontend dan backend

Pertahankan PHP modular terlebih dahulu; pergantian framework bukan syarat refaktor. Pecah file Core/view besar per fitur tanpa memutus route lama yang masih dibutuhkan.

Alur: browser → controller → request validator + authorization → application service → domain/repository → database. Controller tidak berisi perhitungan HPP atau mutasi stok. Frontend tidak memposting jurnal, membuat nomor final, menentukan saldo, atau memutuskan akses.

Struktur target:
```text
app/Modules/Konveksi/
  Controllers/   Requests/   Services/   Repositories/
  Models/        Views/      Jobs/
  Inventory/     Production/ Purchasing/ Sales/ Finance/
app/Modules/Marketplace/          # domain terpisah
app/Shared/                       # identitas dan dukungan umum
app/Bridges/KonveksiMarketplace/  # kontrak transfer eksplisit
public/assets/konveksi/{css,js,images}/
public/assets/marketplace/{css,js,images}/
database/migrations/{shared,konveksi,marketplace}/
storage/private/{konveksi,marketplace}/
```

Endpoint use case contoh: membuat PO, menyetujui PO, memposting penerimaan, mengeluarkan bahan, menyetor hasil potong, menerima QC, mengirim barang dan membayar invoice. Daftar menggunakan filter/pagination. Endpoint perubahan memakai POST/PATCH sesuai kontrak, CSRF untuk session browser dan izin server. Response konsisten mempunyai code, message, data, errors dan request_id. Kode kegagalan membedakan validasi, izin, konflik, dependensi dan internal error.

## 11. Pemisahan database secara fisik/logis

Target tiga database pada instance yang sama boleh digunakan untuk tahap awal, dengan user database berbeda:

| Database | Isi | Akses |
|---|---|---|
| `jago_laba_shared` | Identitas, keanggotaan perusahaan, izin, branding umum | Layanan identitas sesuai kebutuhan |
| `jago_laba_konveksi` | Seluruh dokumen produksi, stok, penjualan, keuangan Konveksi | Hanya koneksi domain Konveksi |
| `jago_laba_marketplace` | Koneksi toko/token, katalog, pesanan, finance/ads dan sinkronisasi | Hanya koneksi domain Marketplace |

Gunakan variabel konfigurasi `JL_SHARED_DB_*`, `JL_KONVEKSI_DB_*`, `JL_MARKETPLACE_DB_*`. Pemisahan nama koneksi saja belum cukup; uji bahwa kredensial Konveksi ditolak ketika mencoba membaca tabel Marketplace dan sebaliknya. Jangan mengandalkan akun root aplikasi.

Skema Konveksi minimum:
- products, product_variants, materials, units, unit_conversions, warehouses, partners, employees.
- bom_versions, bom_lines, cost_versions, production_orders, production_order_lines.
- purchase_orders/lines, goods_receipts/lines, supplier_invoices/lines, purchase_returns/lines.
- cutting_orders/lines, cutting_outputs, sewing_orders/lines, work_handoffs, sewing_outputs.
- qc_inspections/lines, rework_orders, production_completions/lines.
- inventory_movements, inventory_balances, inventory_reservations, stocktakes/lines.
- sales_orders/lines, shipments/lines, sales_invoices/lines, sales_returns/lines.
- wage_accruals, wage_payments, employee_advances, advance_repayments.
- accounts, journal_entries/lines, payments, payment_allocations, bank_transactions, reconciliations, accounting_periods.
- attachments, audit_events, idempotency_keys, outbox_events, inbox_events, jobs.

Seluruh tabel bisnis memiliki company_id dan indeks sesuai pola akses. Gunakan FK di dalam domain, DECIMAL untuk nominal/kuantitas relevan, currency, version, timestamp server dan referensi sumber. Baris detail tidak disimpan sebagai satu-satunya JSON yang bisa diganti massal. Snapshot JSON boleh dipakai sebagai bukti terbatas, bukan pengganti seluruh relasi.

Pemisahan database tetap membutuhkan otorisasi perusahaan. User yang sama tidak otomatis dapat mengakses semua perusahaan atau gudang.

## 12. Hubungan dengan Marketplace

Tidak ada pemotongan stok Konveksi ketika toko melakukan sinkronisasi pesanan. Tidak ada pembacaan tabel Konveksi oleh laporan Marketplace. HPP produksi tidak otomatis menimpa HPP marketplace tanpa pemetaan, versi dan tindakan/aturan yang disetujui.

Transfer barang ke kanal Marketplace menggunakan dokumen bridge: sumber, tujuan, varian internal, mapping varian eksternal, jumlah, biaya, versi dan reference ID. Gunakan outbox pada database sumber dan inbox pada tujuan, deduplication, retry serta status pending/accepted/failed/compensated.

Transaksi dua database tidak dianggap atomik. Jangan menampilkan transfer selesai sebelum kedua sisi mengakui hasilnya. Jangan sekaligus mengakui transfer internal sebagai penjualan pelanggan tanpa kebijakan entitas/harga transfer yang jelas. Perubahan stok di marketplace hanya dilakukan jika diotorisasi dan fitur API tersedia.

## 13. Penghapusan sisa Apps Script dan data lama

Definisi “hapus Apps Script” adalah menghilangkan runtime, dependensi, jalur sinkronisasi, konfigurasi, credentials, antrian serta cache khusus Apps Script yang tidak dipakai. Ini tidak berarti menghapus transaksi bisnis sah yang dahulu diimpor dari Sheets.

Langkah wajib:
1. Inventaris source, konfigurasi tersimpan, database mapping, jobs, browser storage, dokumen dan deploy artifact. Tandai aktif, kompatibilitas, hasil migrasi, contoh/test, duplikat dan orphan.
2. Buktikan penggunaan tiap alias. Pindahkan `savePDFToDrive` ke layanan dokumen privat dan `syncMultipleTabsToSheets` ke endpoint domain baru; ganti seluruh pemanggil sebelum mencabut alias.
3. Migrasikan collection JSON ke tabel relasional dengan `legacy_source` dan `legacy_id`; mapping identitas tidak memakai nama item saja.
4. Rekonsiliasi dokumen, jumlah baris, stok per item/gudang, nilai persediaan, kas, piutang, utang, dan HPP. Record gagal masuk daftar koreksi, tidak diabaikan.
5. Pisahkan baris demonstrasi/test dari data nyata menggunakan bukti provenance. Jangan menghapus berdasarkan kata “sheet”, prefix tabel, nama field atau kemiripan nominal.
6. Tampilkan pratinjau pembersihan, dependensi dan hasil rekonsiliasi. Penghapusan data operasional nyata mengikuti persetujuan owner atas daftar konkret dan kebijakan retensi.
7. Setelah cutover terverifikasi, hentikan endpoint/jadwal lama dan hapus dependency/credential Apps Script. Jangan meminta atau menampilkan secret pada laporan audit.
8. Bersihkan sisa aktif secara terukur; backup lama mengikuti retensi tersendiri. Restore harus mempertahankan penonaktifan jalur lama.

Kriteria akhir: aplikasi berfungsi ketika domain Google Apps Script diblokir; scan runtime dan network tidak menemukan panggilan yang dibutuhkan; tidak ada fallback tersembunyi; data bisnis valid tetap utuh.

## 14. Keamanan, pekerjaan otomatis dan penyimpanan

- TLS, session aman, CSRF, prepared statements, upload tervalidasi, unduhan berdasarkan izin dan penyimpanan privat di luar public.
- Namespace file, cache, job, log dan backup dipisah per domain/perusahaan. Tidak ada path dari browser yang langsung dipercaya.
- Worker berjalan di server melalui service/scheduler yang dapat dipantau, bukan timer tab browser. Dukung Windows saat pengembangan dan Linux saat deployment.
- Atomic claim job, lease/heartbeat, timeout, retry terbatas dengan backoff, dead-letter dan idempotency. Status “Aktif” membutuhkan bukti heartbeat terbaru.
- Backup, pembuatan PDF, impor besar dan ekspor besar asinkron; pembuatan dokumen tidak menentukan apakah transaksi bisnis berhasil diposting.
- Stok dan keuangan berubah langsung setelah posting berhasil; pembaruan layar lain mengikuti version/event/poll terbatas. Cache tidak menjadi tempat penyimpanan transaksi yang belum sah.
- Audit tidak menyimpan password, token, atau dokumen pelanggan penuh. Log memuat reference ID dan konteks minimum yang diperlukan.
- Backup terenkripsi dan uji restore per domain. Drive D dapat menjadi lokasi lokal melalui konfigurasi; backup di disk yang sama dengan database bukan satu-satunya salinan pemulihan.

## 15. Tahapan migrasi dan prioritas

### P0 — integritas sebelum perombakan tampilan
Audit data/relasi, baseline rekonsiliasi, matriks izin, sumber saldo tunggal, endpoint domain, idempotency/locking, posting atomik dan reversal. Tutup kemampuan frontend mengganti kumpulan tabel operasional setelah pemanggil bermigrasi.

### P1 — alur operasional dan UI
Master identitas/satuan, pembelian–penerimaan–tagihan, bahan–WIP–SPK–QC–barang jadi, penjualan–pengiriman–invoice, upah/kasbon; tampilan dan notifikasi konsisten. Migrasikan per use case, bukan memindahkan seluruh file sekaligus.

### P2 — pemisahan database dan cutover
Siapkan schema/kredensial baru, migrasi berulang yang idempotent, validasi, latihan cutover. Saat cutover: hentikan penulisan domain yang dipindah, salin delta final, rekonsiliasi, ganti koneksi, uji smoke, lalu buka kembali. Tetapkan sumber kebenaran tunggal; jangan dual-write tanpa strategi konsistensi.

Rollback sebelum pembukaan penulisan dapat mengembalikan konfigurasi lama. Setelah penulisan baru dibuka, rollback wajib memperhitungkan transaksi delta; tidak boleh sekadar mengganti nama database. Data lama tidak dihapus sebelum periode validasi dan keputusan pembersihan yang disetujui.

### P3 — otomatisasi dan laporan
Dashboard tugas, notifikasi tenggat, biaya aktual, laporan keuangan, ekspor, bridge transfer, health worker, uji restore dan deployment domain resmi. Fitur AI/OCR bersifat bantuan input; hasil harus diverifikasi sebelum posting.

## 16. Pengujian penerimaan

| Kasus | Hasil wajib |
|---|---|
| Klik posting/retry 3 kali dengan key sama | Satu dokumen final, satu efek stok, satu jurnal |
| Key sama tetapi payload berubah | Ditolak sebagai konflik |
| Dua pengguna memakai stok terakhir bersamaan | Tidak menghasilkan stok negatif atau saldo hilang |
| Dua pengguna mengedit draft versi sama | Perubahan kedua menerima konflik versi |
| PO/penerimaan/tagihan/pembayaran sebagian | Saldo tiap tahap sesuai dan tidak dihitung ganda |
| 100 target, 90 lolos, 5 rework, 5 reject | Saldo WIP/barang jadi dan biaya dapat direkonsiliasi |
| Upah dari hasil produksi lalu potongan kasbon | Tidak ada upah/biaya atau pembayaran ganda |
| Invoice kredit lalu pelunasan | Piutang dan kas berubah sesuai kejadian masing-masing |
| Void/retur dokumen terposting | Reversal tercatat; dokumen asal tidak hilang |
| Aktivasi HPP gagal di tengah | Versi produk/BOM/history tidak setengah berubah |
| Pengguna perusahaan/gudang lain | Akses lihat, ubah dan ekspor ditolak |
| Akun DB Konveksi mengakses Marketplace | Database menolak |
| Transfer lintas domain gagal sesudah sumber commit | Pending/retry/kompensasi nyata, tanpa duplikasi |
| Domain Apps Script tidak dapat diakses | Seluruh operasi inti tetap berjalan |
| Migrasi dua kali | Tidak menggandakan record atau efek stok/jurnal |
| Restore backup uji | Saldo/dokumen valid dan jalur lama tetap nonaktif |
| Desktop/tablet/ponsel/keyboard | Operasi inti dapat diselesaikan tanpa kontrol tertutup |

Target performa awal, diukur pada dataset dan perangkat yang disepakati: halaman daftar p95 <2 detik, filter/pagination server, posting ringan p95 <3 detik tanpa pembuatan dokumen eksternal. Uji beban mencantumkan volume data, konkurensi dan lingkungan; angka target bukan klaim hasil saat ini.

## 17. Definition of Done

Satu use case selesai hanya bila frontend, backend, perubahan schema, izin, validasi, status, efek stok/keuangan, kegagalan parsial, audit dan pengujian tersedia. UI tidak menyatakan keberhasilan untuk endpoint placeholder. Tidak ada data contoh dalam ruang operasional produksi. Total laporan dapat ditelusuri ke ledger/dokumen. Konveksi dapat dijalankan dan dipulihkan independen dari Marketplace. Sisa Apps Script dibuktikan tidak diperlukan, bukan sekadar diganti namanya.

## 18. Berkas sumber audit

- `app/Modules/Konveksi/Module.php`: menu dan pemuatan layanan.
- `app/Modules/Konveksi/Controllers/RpcService.php`: dispatcher dan jalur kompatibilitas.
- `app/Modules/Konveksi/Controllers/Api/rpc.php`: request, CSRF dan response.
- `app/Modules/Konveksi/Services/InventoryService.php`: saldo/mutasi dan kontrol awal.
- `app/Modules/Konveksi/Services/HppService.php`: aktivasi biaya, BOM dan history.
- `app/Modules/Konveksi/Services/PurchaseService.php`, `SalesService.php`: alur transaksi lama.
- `app/Shared/Support/CollectionStore.php`: penyimpanan collection dan nama domain yang bercampur.
- `app/Core/Database/Database.php`, `config/database.php`: koneksi database bersama saat audit.
- `app/Bootstrap/bootstrap.php`, `config/storage.php`: boot modul dan batas penyimpanan.
- `app/Modules/Konveksi/Views/28_Core.html`: state dan pemanggilan penyimpanan browser.

Langkah implementasi berikutnya: baseline rekonsiliasi read-only dan matriks pemetaan tabel/record, lalu satu alur pilot pembelian–penerimaan–tagihan–pembayaran sebelum memigrasikan seluruh modul. PRD ini belum memberi izin otomatis menghapus riwayat usaha atau mereset database.
