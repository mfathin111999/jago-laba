> **Catatan arsip (6 Nov 2026):** dokumen ini ditulis saat aplikasi masih PHP murni. Jalur file, skrip, dan kelas yang disebut di bawah (`app/Modules`, `public/*.php`, `bin/workers`, dll.) sudah dihapus dan digantikan Laravel; lihat [penghapusan kode lama](../architecture/legacy-removal.md) dan [arsitektur aktif](../architecture/../architecture.md). Perilaku bisnis yang dijelaskan tetap berlaku.

# Persiapan produksi domain .com

Rancangan operasional; domain, hosting dan OS target belum dipilih. Ini bukan pernyataan aplikasi sudah siap produksi.

## Prasyarat

- PHP mengikuti minimum installer saat ini, >=8.2; pilih versi final setelah uji kompatibilitas. Verifikasi pdo_mysql, JSON, OpenSSL/sodium, cURL, mbstring serta ZIP/XML sesuai kebutuhan XLSX/dokumen.
- Web server dengan document root public, MySQL kompatibel, HTTPS, storage privat writable dan backup terpantau.
- Akun DB aplikasi bukan root; akun migration terpisah bila perlu DDL.
- Keluarkan installer, seed, snapshot, alat repair/diagnostik yang tidak diperlukan, .venv, docs/oracleJdk-27 dan backup dari artefak publik.
- Siapkan routing/rewrite dan pembatasan akses secara eksplisit; jangan menganggap .htaccess sudah tersedia.

| Target | Persiapan operasi |
|---|---|
| Windows | Atur PHP CLI, mysqldump, service account, ACL, Task Scheduler dan launcher tersembunyi; uji reboot. |
| Linux/VPS | Ganti launcher PowerShell dengan runner portabel; pilih systemd/Supervisor/cron; uji case-sensitive path. |
| Shared hosting | Verifikasi cron, durasi proses, queue dan executable; bila tidak mendukung, gunakan worker terpisah atau revisi rancangan. |

Browser pulse sekarang berbeda dari manual background worker. Jangan memasang scheduler baru bersamaan tanpa analisis lock/idempotensi. Perubahan ke layanan selalu aktif merupakan keputusan implementasi tersendiri, belum dilakukan audit ini.

## Staging dan pengujian

1. Bangun artefak sumber terverifikasi; restore DB/file/key ke lingkungan terisolasi.
2. Matikan penjadwal dan outbound API tulis secara efektif sebelum memasukkan token Live. Nama APP_ENV saja belum tentu dibaca aplikasi.
3. Jalankan migration yang diperlukan berdasarkan dependency dan registry; jangan semua script atau seed referensi.
4. Uji login, workspace Konveksi, toko Marketplace, isolasi perusahaan/toko, HPP/laba, impor XLSX dan unduhan berizin.
5. Uji HTTPS, session path, Secure/SameSite cookie, trusted proxy, host allowlist dan URL absolut.
6. Sesuaikan pembentuk callback: config/marketplace.php saat ini menambahkan /public/api/marketplace-oauth.php. Document root public membutuhkan penyesuaian kode/config dan registrasi provider; mengganti JL_APP_BASE_URL saja tidak cukup.
7. Uji state salah/kedaluwarsa/replay, callback lintas perusahaan, pemilihan beberapa toko dan deduplikasi. Otorisasi seller tetap melalui persetujuan seller.
8. Uji refresh token, rate limit/backoff, job gagal/retry, restart dan heartbeat. Webhook tetap nonaktif sampai verifikasi signature/anti-replay/durable inbox tersedia.
9. Lakukan restore rehearsal; catat bukti go/no-go.

## Cutover

Jadwalkan pemeliharaan, hentikan penulis/worker lama, buat backup final konsisten, restore DB/storage/key, jalankan migration terkontrol, sesuaikan domain/callback, lakukan smoke test lalu aktifkan satu penjadwal terverifikasi. Selesaikan atau mulai ulang sesi OAuth yang masih berjalan.

Pantau error, antrean, heartbeat, last-success, token expired, ketepatan nominal dan ruang disk. Log tidak boleh memuat token/secret, signed URL atau data pribadi pelanggan.

Tunda cutover bila restore belum lulus, installer terbuka, dokumen privat dapat diakses langsung, callback salah, worker belum sesuai host atau isolasi belum diuji. Domain .com sendiri tidak menjamin keamanan maupun kecepatan.
