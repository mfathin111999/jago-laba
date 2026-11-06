> **Catatan arsip (6 Nov 2026):** dokumen ini ditulis saat aplikasi masih PHP murni. Jalur file, skrip, dan kelas yang disebut di bawah (`app/Modules`, `public/*.php`, `bin/workers`, dll.) sudah dihapus dan digantikan Laravel; lihat [penghapusan kode lama](legacy-removal.md) dan [arsitektur aktif](../architecture.md). Perilaku bisnis yang dijelaskan tetap berlaku.

# Struktur aktual dan temuan

Tanggal: 27 Oktober 2026. Pemeriksaan statis sumber lokal, bukan pengujian penetrasi atau sertifikasi produksi.

## Cakupan

292 file: app 122, config 5, database 45, public 61, scripts 24, tests 35. Ditemukan 304 ekspresi include dan 18 referensi jalur/domain yang perlu diperiksa untuk portabilitas. Tidak ditemukan benturan path akibat perbedaan huruf besar-kecil dalam inventaris.

public/storage dan storage runtime tidak dipindai isinya. Ekspresi include adalah kandidat dependensi statis, bukan call graph lengkap. Inventaris tidak mencakup .venv atau runtime vendor di docs.

```text
app/
  Core/Database.php
  Http/                         # request/response
  Modules/Konveksi/Module.php
  Modules/Marketplace/           # boot dan sebagian integrasi
  Bridges/KonveksiMarketplace/
  Shared/Runtime.php
  Security/
  Services/                     # layanan dua domain masih bercampur
  views/                        # template dan komponen bersama
  bootstrap.php
  config.php                    # adapter konfigurasi lama
  RpcService.php
config/                         # app, database, backup, security, marketplace
public/
  index.php
  workspace.php
  marketplace.php
  api/
  assets/
  storage/
database/                       # migrasi, seed, snapshot dan alat inspeksi
scripts/                        # worker, operasi, diagnosis dan backup
storage/
tests/
docs/
```

## Alur pemuatan

app/bootstrap.php memuat konfigurasi, Database, SharedRuntime, boot Konveksi, definisi modul Marketplace, lalu session. Konveksi masih di-boot awal meskipun permintaan nantinya untuk Marketplace. Shared/Runtime.php memuat SchemaManager, SnapshotImporter, CollectionStore, AuditService dan Workspace melalui require manual.

Modules/Konveksi/Module.php masih menunjuk layanan app/Services dan RpcService di root. Modules/Marketplace/Module.php memuat integrasi di modul sekaligus layanan root. Belum ditemukan composer.json/package.json pada pemeriksaan root. Migrasi autoload harus bertahap, dengan adapter untuk pemanggil lama.

ExplicitStockTransfer di app/Bridges/KonveksiMarketplace merupakan objek kontrak yang memvalidasi referensi, varian, kuantitas positif, gudang asal dan toko tujuan. Ini belum membuktikan proses transfer stok lintas domain sudah berjalan.

## Temuan prioritas

| Prioritas | Bukti sumber | Tindak lanjut |
|---|---|---|
| P0 sebelum produksi | public/install.php: jalur POST memanggil createDatabase, ensureOwner dan import; tidak ditemukan pemeriksaan login/CSRF atau penolakan POST berdasarkan install.lock pada berkas | Keluarkan installer dari deployment publik atau ubah menjadi CLI terkontrol. Lock tampilan bukan perlindungan handler POST. Keterjangkauan dari internet/config Apache belum diuji. |
| P0 sebelum produksi | DocumentService.php menyimpan ke public_path/storage/documents dan mengembalikan URL langsung | LOCAL_PRIVATE bukan bukti privasi. Simpan di luar public dan gunakan unduhan berizin; migrasikan metadata dan file secara terkontrol. |
| P1 | config/marketplace.php menambahkan /public/api/marketplace-oauth.php pada JL_APP_BASE_URL | Document root public memerlukan penyesuaian pembentuk callback dan registrasi provider; tidak cukup mengganti domain. |
| P1 | MarketplaceBackgroundSync.php memerlukan Windows, powershell.exe, proc_open dan fallback PHP D:/xampp | Sediakan runner portabel sebelum deploy Linux; verifikasi kemampuan hosting. |
| P1 | MarketplaceAutoSync::available() selalu true; browserPulse memanggil tick | Nilai true bukan bukti penjadwal sehat. Bedakan browser pulse, manual background worker dan layanan terjadwal. |
| P1 | MarketplaceTokenVault/config backup memiliki fallback Windows | Konfigurasi key, PHP dan mysqldump harus eksplisit; key lama wajib tetap tersedia saat restore. |
| P2 | public/marketplace.php sekitar 269 KB saat pemeriksaan awal | Pecah controller, use case, view dan JS per fitur melalui facade kompatibel. |
| P2 | database mencampur SQL/PHP migration, seed, snapshot dan repair | Registry migrasi dan urutan berdasarkan dependensi; jangan eksekusi semua file alfabetis. |
| P2 | bootstrap selalu boot Konveksi | Bootstrap bersama minimal dan boot domain sesuai rute setelah dependensi diverifikasi. |

## Fakta operasional dan batas pembuktian

- Workspace dan scope memiliki kontrol session, izin dan CSRF. Keberadaan kontrol belum berarti isolasi lintas perusahaan/toko sudah lulus pengujian.
- API rpc memverifikasi CSRF kecuali proses login. Pertahankan kontrak dispatch dan seluruh pemeriksaan otorisasi saat refaktor.
- Endpoint webhook marketplace saat ini mengembalikan 503; jangan menyatakan webhook sudah aktif.
- Interval auto sync: status 3 jam, transaksi baru 60 detik, belum dibayar 60 detik, keuangan 300 detik. Ini bukan jaminan ketersediaan data provider pada interval tersebut.
- Auto sync browser bergantung tab aktif; manual background sync memakai jalur worker Windows. File installer task/cron tidak membuktikan task sedang berjalan. Audit tidak menyalakan/menghentikan proses.
- Health SELECT 1 tidak membuktikan izin provider atau kelengkapan keuangan.
- diagnostics/auth-diagnostic memiliki pemeriksaan owner; repair_owner memiliki pembatasan tambahan. Tetap batasi alat administrasi saat deployment.
- PDF resi terenkripsi tersimpan dalam database; restore memerlukan database dan key, bukan folder file saja.
- DocumentService membangun URL melalui HTTP_HOST/HTTPS dan asumsi /public; perlu helper URL serta trusted proxy saat migrasi.

Database tidak dihubungi. Kepemilikan tabel, foreign key, volume data, kondisi worker, izin Live dan konsistensi restore belum diverifikasi. Tidak ada klaim aplikasi siap produksi.
