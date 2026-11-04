# Runbook cutover dan rollback (4 November 2026)

Setiap langkah di bawah sudah dijalankan pada staging lokal; bagian "Bukti" menyebut hasilnya. Yang **belum** bisa dibuktikan di sini
ditandai **[host]**: itu hanya bisa diverifikasi di server sebenarnya (HTTPS, cron yang berjalan terus, izin file).

Prinsip: cutover tidak mengubah logika bisnis dan tidak mengubah data. Laravel memakai database yang sama dengan aplikasi lama.
Rollback berarti mengembalikan web root ke aplikasi lama; data tidak perlu dipulihkan kecuali terjadi kerusakan data.

## 0. Sebelum hari cutover

- [ ] Dump database asli terbaru ada, dan UAT (F2) dengan data itu sudah selesai dan ditandatangani.
- [ ] Keputusan pemilik pada `docs/architecture/keputusan-pemilik-20261104.md` terisi (bagian A boleh menyusul, tidak menghalangi).
- [ ] **[host]** HTTPS aktif, `JL_ALLOWED_HOSTS` dan `JL_TRUSTED_PROXIES` terisi, cookie sesi `secure`.
- [ ] **[host]** Isi `.env` server (tidak di repo): `APP_KEY`, kredensial DB, `JL_COMPANY_KEY`, `JL_BACKUP_DIR` (di luar web root),
      `JL_BACKUP_MIRROR_DIR`, `JL_MYSQLDUMP`, `JL_DOCUMENT_STORAGE_ROOT` (arahkan ke storage legacy agar dokumen lama terbaca),
      `JL_DELETION_LEDGER_DIR`, `JL_MARKETPLACE_TOKEN_ENCRYPTION_KEY(_FILE)`, `JL_MARKETPLACE_OAUTH_MODE=sandbox`.
      Kunci partner Shopee/TikTok hanya lewat `.env` (keputusan B3); tidak ada form web untuk itu.
- [ ] Pastikan tabel `konveksi_request_receipts` ada di database Konveksi (tanpa itu semua tulisan Konveksi ditolak, sengaja).
- [ ] Pastikan tidak ada SQL berlabel **REVIEW ONLY** pernah dijalankan di database produksi (tabel `*_v2`/`*_v3`); jika ada, tabel itu
      membuat penghapusan toko menolak berjalan sampai dibersihkan.

## 1. Bangun artifact rilis

```bash
cd laravel-foundation && npm ci && npm run build
cd .. && php scripts/deployment/build-release.php /path/ke/release --composer=/path/ke/composer.phar
```

Skrip: menyalin hanya `app/` (modul legacy yang masih dimuat) dan `laravel-foundation/`, **tanpa** `public/` legacy
(`install.php`, `repair_owner.php`), `.env`, tes, `node_modules`, dan `storage/`; memasang dependensi produksi tanpa paket dev; memindai secret;
menulis `RELEASE-MANIFEST.json` (SHA-256 setiap file). Keluar dengan kode 1 bila ada secret, file terlarang, atau file wajib hilang.

Bukti: 6.392 file, pindai secret bersih, `install.php`, `repair_owner.php`, dan `.env` menjawab 404 dari server rilis, `build/manifest.json` 200.
Pindai itu sempat menemukan nilai bawaan `webhook_global_secret` di controller UI legacy; controller dan view legacy kini tidak ikut dikirim.

## 2. Rehearsal artifact (wajib lulus sebelum dipasang)

Jalankan aplikasi dari folder rilis terhadap database staging, lalu jalankan ketiga uji E2E ke server itu:

```bash
. scripts/development/staging-env.sh
cd /path/ke/release/laravel-foundation && php artisan serve --host=127.0.0.1 --port=8801 &
cd <repo>/laravel-foundation
E2E_BASE_URL=http://127.0.0.1:8801 node tests/e2e/staging-e2e.mjs
E2E_BASE_URL=http://127.0.0.1:8801 node tests/e2e/konveksi-write-e2e.mjs
E2E_BASE_URL=http://127.0.0.1:8801 PHP_BIN=... MYSQL_BIN=... MYSQLDUMP_BIN=... node tests/e2e/staging-ops-e2e.mjs
```

Bukti (artifact dibangun dari commit ini): 84/84, 42/42, 13/13 lolos.

## 3. Cutover

1. Beri tahu pemakai; hentikan pekerjaan tulis di aplikasi lama (halaman pemeliharaan atau jam sepi).
2. **Backup penuh dan catat checksum:** `mysqldump --routines --triggers --single-transaction <db> > backup-pra-cutover.sql`, simpan di luar web root,
   salin ke lokasi kedua, catat SHA-256.
3. Catat jumlah baris per tabel (`SELECT COUNT(*)` tiap tabel) sebagai baseline.
4. Pasang rilis ke folder baru di sebelah aplikasi lama, isi `.env`, set izin: `storage/` dan `bootstrap/cache/` hanya dapat ditulis akun web; `.env` tidak
   terbaca publik.
5. Jalankan sekali: `php artisan marketplace:store-deletion --install` (tabel dan trigger penghapusan toko; hanya MySQL).
6. **Pasang tabel antrean saja:** `php artisan migrate --path=database/migrations/0001_01_01_000002_create_jobs_table.php --force`.
   Jangan jalankan `php artisan migrate` penuh: migrasi bawaan Laravel (tabel `users`, dst.) bentrok dengan skema legacy.
7. **[host]** Pasang cron `* * * * * php artisan schedule:run` dan worker antrean (`php artisan queue:work --queue=marketplace,default`) di bawah
   supervisor; bukti run, stop, restart.
8. Arahkan web root ke `laravel-foundation/public` (tidak ada penulisan ulang URL lain yang perlu). `/index.php` bagi pengguna yang sudah masuk
   dialihkan ke `/konveksi`.
9. Smoke dengan akun owner: login, buka ruang kerja Marketplace dan Konveksi, buka satu halaman tiap menu, catat satu pengeluaran lalu hapus, jalankan backup manual.
10. Bandingkan jumlah baris dengan baseline langkah 3 (hanya boleh berubah oleh aksi smoke).
11. Observasi: pantau `storage/logs/laravel.log`, antrean `jobs`/`failed_jobs`, dan keluhan pemakai selama periode yang disepakati.

## 4. Rollback

Pemicu: galat yang tidak bisa diatasi cepat, atau data yang salah.

**A. Aplikasi bermasalah, data baik (kasus umum).** Kembalikan web root ke aplikasi lama. Data tidak berubah bentuk: Laravel tidak mengubah tabel
yang dipakai legacy, hanya menambah tabel `jobs` dan (bila `--install` dijalankan) tabel dan trigger penghapusan toko. Trigger itu hanya
menolak tulis untuk toko yang sudah diminta dihapus, jadi aman bagi aplikasi lama.

**B. Data rusak.** Pulihkan dari backup langkah 3.2 ke skema baru, bandingkan, lalu tukar nama atau arahkan koneksi:

```bash
mysql -e "CREATE DATABASE <db>_pulih CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
mysql <db>_pulih < backup-pra-cutover.sql
# bandingkan jumlah baris semua tabel (skrip: ulangi query count per tabel di kedua skema) lalu arahkan DB_DATABASE ke <db>_pulih
```

Bukti (latihan pada staging): dump 1,3 MB, dipulihkan ke skema baru; **96 tabel dengan jumlah baris identik (628 baris) dan 70 trigger ikut pulih**.
Catatan teknis: klien `mysql` di Windows menambahkan `\r` pada keluaran; hapus sebelum dipakai sebagai nama tabel.

## 5. Setelah cutover stabil

- Arsipkan aplikasi lama (folder dan dump terakhir) di luar web root; jangan hapus sampai periode observasi berakhir.
- Hapus `install.php` dan `repair_owner.php` dari arsip yang dapat diakses web; simpan versi CLI (`bin/`) bila perlu.
- Kerjakan urusan logika bisnis yang ditunda (A1–A4, C2, C3 pada lembar keputusan) sebagai pekerjaan terpisah dengan UAT sendiri.
