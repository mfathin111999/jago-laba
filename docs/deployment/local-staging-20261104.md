# Staging lokal sekali-pakai (4 November 2026)

Tujuan: menjalankan aplikasi Laravel terhadap MySQL/MariaDB sungguhan di mesin sendiri, memakai data **sintetis**,
supaya alur tulis, antrean, dan UI Konveksi bisa dibuktikan sebelum UAT dengan data asli. Tidak menyentuh database
XAMPP yang sudah ada: server MariaDB-nya terpisah (port 3320, folder data sendiri).

Yang **tidak** dibuktikan staging ini: angka dari data asli (paritas dengan aplikasi lama), performa, dan hosting
(HTTPS, cron, izin file). Itu tetap tugas UAT dan Fase D.

## 1. Server database terpisah

PowerShell, dari folder proyek. Ganti `$data` ke folder yang Anda mau (di luar repo):

```powershell
$data = "C:\jago-staging\data"
& C:\xampp\mysql\bin\mysql_install_db.exe --datadir=$data --password=
Start-Process C:\xampp\mysql\bin\mysqld.exe -ArgumentList "--defaults-file=$data\my.ini","--port=3320","--bind-address=127.0.0.1","--skip-name-resolve","--console"
```

Berhenti: `& C:\xampp\mysql\bin\mysqladmin.exe -h127.0.0.1 -P3320 -uroot shutdown`.

## 2. Skema dan data

Dengan Git Bash dari folder `jago/`; PHP harus 8.3 atau lebih baru (`C:\xampp\php8_4\php.exe`):

```bash
. scripts/development/staging-env.sh
php scripts/development/local-staging-setup.php     # skema inti, snapshot Konveksi, semua migrasi Marketplace, login owner
php scripts/development/local-staging-seed.php      # data sintetis Marketplace (2 toko, 28 pesanan, stok, iklan, penarikan)
cd laravel-foundation
php artisan migrate --path=database/migrations/0001_01_01_000002_create_jobs_table.php --force   # tabel antrean saja
```

Jangan menjalankan `php artisan migrate` penuh: migrasi bawaan Laravel (tabel `users`, dst.) bentrok dengan skema legacy.
Kedua skrip menolak berjalan kecuali nama database diawali `jl_staging` dan host-nya loopback.

Login: `owner` / `owner123` (ubah dengan `--owner-password=...` pada skrip setup).

## 3. Menjalankan aplikasi dan uji

```bash
cd laravel-foundation && npm run build
. ../scripts/development/staging-env.sh && php artisan serve --host=127.0.0.1 --port=8800    # terminal 1
node tests/e2e/staging-e2e.mjs                                                                # terminal 2
```

Uji E2E (`tests/e2e/staging-e2e.mjs`) login lewat layar login asli, membuka 11 halaman Marketplace dengan data
MySQL, menjalankan aksi tulis (catat dan hapus pengeluaran, ubah HPP, atur minimum stok, antrean sinkron) dan memuat klien
Konveksi. Untuk ikut membuktikan worker antrean:

```bash
E2E_WORKER="php artisan queue:work --queue=marketplace,default --stop-when-empty" node tests/e2e/staging-e2e.mjs
```

Hasil terakhir: 85 dari 85 pemeriksaan lolos.

### Uji tulis Konveksi (`tests/e2e/konveksi-write-e2e.mjs`)

```bash
node tests/e2e/konveksi-write-e2e.mjs
```

Memakai pustaka API klien lama (`window.jlApi`) di browser yang sudah login, jadi setiap panggilan membawa token CSRF,
kunci idempotensi, versi koleksi, dan reset epoch yang asli, melewati gerbang tulis di MySQL sungguhan. Data yang dipakai
adalah snapshot Konveksi yang diimpor (31 baris stok, 17 produk, 3 rekening). Hasil: 26 dari 26 lolos.

| Alur | Yang dibuktikan |
|---|---|
| Jual produk | stok turun sebesar qty, satu penjualan + satu mutasi stok + satu kas masuk senilai tagihan; laba dihitung server dari HPP tersimpan |
| Kirim ulang dengan kunci yang sama | hanya terposting sekali |
| Jual melebihi stok | ditolak dengan pesan terbaca, tidak ada perubahan |
| Tulis tanpa kunci | ditolak 422 |
| Batalkan penjualan (void) | stok kembali ke semula, penjualan bertanda VOID |
| Versi koleksi basi | syncTab ditolak 409, buku kas tidak berubah |
| Hutang pegawai | dibuat, dibayar sebagian (kas keluar senilai bayar), bayar melebihi saldo ditolak |
| Backup | snapshot nyata dibuat; balasan hanya berisi nama berkas |

Skema gerbang tulis (`konveksi_request_receipts`) dipasang oleh skrip setup lewat installer legacy. Tanpa tabel itu semua
tulisan Konveksi sengaja ditolak.

## Temuan saat menyiapkan staging

0. Balasan backup membocorkan path absolut server (misalnya `C:/xampp/...`) ke browser lewat `snapshot_file`, `manifest_file`, `url`,
   dan salinan eksternal. Kini hanya nama berkas yang dikirim; path lengkap tetap di tabel `backup_log` untuk operator.
   Baris baru di `loadSemuaData` tidak selalu berada di ujung daftar (urutan baris tabel bukan urutan tambah); uji
   mencocokkan berdasarkan ID, dan klien lama memang bekerja seperti itu.
1. `SchemaManager::repairIdempotencySchema` selalu mencoba menambah indeks unik yang sudah ada (membandingkan kunci array
   1..n dengan 0..n-1) sehingga instalasi pada database kosong gagal. Diperbaiki.
2. Migrasi Marketplace saling bergantung secara implisit: lima di antaranya baru berhasil pada putaran kedua. Skrip setup
   mengulang sampai tidak ada kemajuan. Urutan resmi untuk produksi perlu ditetapkan (sudah berjalan di produksi, jadi
   hanya relevan untuk instalasi baru).
3. `migration_marketing_ads.php` menyisipkan 42 baris iklan karangan untuk toko `STR-*-JAGOIN`. Pada staging baris itu
   dihapus. Cek apakah baris serupa ada di database produksi.
4. Rute Konveksi tidak bisa berada di `/index.php`: front controller membaca `/index.php` sebagai `/`. Aplikasi Konveksi
   kini di `/konveksi`; `/index.php` bagi pengguna yang sudah login dialihkan ke sana. Tes PHPUnit tidak bisa
   menangkap ini karena tidak melewati SAPI, hanya uji E2E yang menangkapnya.
5. Kolom `marketplace_orders.total_cogs_hpp`, `net_settlement_amount`, dan `marketplace_order_items.unit_cogs_hpp`
   berstatus NOT NULL, jadi HPP yang belum diketahui tersimpan sebagai 0 di kolom; hanya snapshot JSON yang membedakannya
   dengan `null`. Tampilan membaca snapshot lebih dulu, tetapi laporan SQL langsung bisa salah menganggap 0 sebagai biaya nol.
