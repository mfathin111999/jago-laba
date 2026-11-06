> **Catatan arsip (6 Nov 2026):** dokumen ini ditulis saat aplikasi masih PHP murni. Jalur file, skrip, dan kelas yang disebut di bawah (`app/Modules`, `public/*.php`, `bin/workers`, dll.) sudah dihapus dan digantikan Laravel; lihat [penghapusan kode lama](legacy-removal.md) dan [arsitektur aktif](../architecture.md). Perilaku bisnis yang dijelaskan tetap berlaku.

# Hasil penerapan struktur proyek

27 Oktober 2026. Proyek aktif: `D:/xampp/htdocs/jago-laba`.

## Yang diterapkan

276 file sumber ditempatkan pada lokasi fungsional; daftar sumber, tujuan dan checksum awal ada di [manifest](applied-structure.json). Angka ini mencakup tambahan pemindahan konfigurasi dan SharedRuntime setelah migrasi awal 274 file.

- Bootstrap dan agregator konfigurasi: app/Bootstrap.
- Database/HTTP generik: app/Core/Database dan app/Core/Http.
- Workspace/izin, token, scope perusahaan dan branding: app/Shared sesuai tanggung jawabnya.
- Layanan, controller/API dan view Konveksi: app/Modules/Konveksi.
- Layanan, controller/API, view, jobs dan integrasi Marketplace: app/Modules/Marketplace.
- Worker CLI: bin/workers. Operasi dipisahkan ke scripts/development, deployment, backup dan diagnostics.
- Migrasi dipisahkan ke database/migrations; seed ke database/seeders. Snapshot/workbook/provenance lama berada di database/legacy dengan isi sumber tetap dipertahankan.
- Tests dipisahkan ke Unit, Integration dan Feature; aset ke shared, konveksi dan marketplace.
- config/storage.php, queue.php, integrations.php dan katalog routes tersedia. config/marketplace.php menjadi alias konfigurasi integrasi.
- Composer tersedia dengan classmap kode yang ada, lock file sah, serta autoload yang sudah dibuat. Tidak menambahkan paket pihak ketiga runtime.
- Folder privat diberi .htaccess penolak akses langsung untuk Apache. Konfigurasi ini tidak menggantikan pengaturan server pada Nginx/host lain.

## Kompatibilitas yang dipertahankan

public/index.php, marketplace.php, workspace.php dan public/api tetap menjadi alamat masuk aplikasi. marketplace.php dan API mengarah ke implementasi dalam modul; pemeriksaan login/izin/CSRF tetap berada pada implementasinya.

File PHP lama dalam app, scripts dan database dipertahankan sebagai penghubung. Alias aset statis tetap ada untuk halaman/cache yang memakai alamat lama. Karena itu public masih memiliki api dan file penghubung tambahan selain index.php; penghapusan semuanya sekaligus akan memutus URL yang sudah dipakai.

Folder Models/Repositories/Requests yang belum memiliki implementasi tersendiri disiapkan tanpa membuat kelas palsu. Logika yang sudah ada tetap pada service masing-masing. resources/templates disiapkan untuk template bersama; template halaman tetap dimiliki modul.

Tidak mengubah nama tabel, ID toko/pesanan, SKU, nilai HPP, konfigurasi kredensial tersimpan, callback provider, atau jadwal auto sync. Folder storage privat baru belum berarti seluruh unggahan lama sudah dimigrasikan; lokasi data runtime lama dipertahankan agar fungsi dokumen tidak terputus.

## Backup dan pemulihan

Cadangan sumber sebelum pemindahan: `D:/JAGO_LABA_BACKUP/restructure/source-20261027-084511.zip`.

Ini backup sumber, bukan dump database atau backup seluruh unggahan. Manifest mencatat path dan checksum awal. Untuk rollback sumber: jeda proses penulis/worker, bandingkan perubahan setelah restrukturisasi, pulihkan sumber dari arsip ke lingkungan pemeriksaan, lalu rekonsiliasi file baru sebelum mengganti release. Jangan menimpa database dengan backup sumber ini.

## Verifikasi

- 420 file PHP diperiksa saat validasi akhir: tidak ada syntax error; tidak ditemukan path PHP statis rusak pada pola yang diperiksa. Bukti: [structure-validation.json](structure-validation.json).
- Composer validate strict, optimized autoload (58 kelas) dan structure-check lulus.
- Regression test struktur: 276 tujuan file tersedia, kelas modul dapat dimuat, alias config dan katalog route konsisten.
- Test kalender sinkronisasi, rincian transaksi, normalisasi keuangan, OAuth origin, OAuth/kredensial, pesanan, katalog/HPP, resi PDF, pemasukan, harga/promo, stok, scope browser auto sync dan kebijakan workspace lulus.
- Test yang membutuhkan DB memakai database uji terpisah dan membersihkannya; tidak mengirim perubahan ke marketplace Live.
- Driver browser auto sync diuji dengan fixture Node; parser PowerShell tidak menemukan kesalahan sintaks.
- HTTP lokal: index 200, Marketplace tanpa login 302, health 200, aset canonical 200. Isi aset canonical cocok byte-per-byte dengan file lokal dan alias lama. Akses HTTP langsung ke jalur privat yang dicoba tidak mengembalikan isi sumber.

Pengujian tersebut tidak menggantikan uji visual seluruh layar atau uji API Live. Pengaturan domain .com, runner Linux dan migrasi file privat lama masih mengikuti [panduan produksi](../deployment/production-guide.md). Temuan installer pada audit sebelumnya belum diubah dalam pekerjaan restrukturisasi ini; jangan membuka installer pada deployment publik.
