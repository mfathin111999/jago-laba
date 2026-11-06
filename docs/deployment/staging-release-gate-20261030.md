> **Catatan arsip (6 Nov 2026):** dokumen ini ditulis saat aplikasi masih PHP murni. Jalur file, skrip, dan kelas yang disebut di bawah (`app/Modules`, `public/*.php`, `bin/workers`, dll.) sudah dihapus dan digantikan Laravel; lihat [penghapusan kode lama](../architecture/legacy-removal.md) dan [arsitektur aktif](../architecture/../architecture.md). Perilaku bisnis yang dijelaskan tetap berlaku.

# Staging release gate — 2026-10-30 (historical snapshot)

> Snapshot ini mencatat kondisi per 30 Oktober 2026 dan telah disupersede oleh
> [staging release gate terbaru](./staging-release-gate-20261101.md). Pernyataan
> callback dan angka build di bawah tidak menggambarkan source/artifact terbaru.

## Keputusan

**BLOCKED untuk restore/cutover produksi.** Foundation dan artifact build lokal
lulus verifikasi teknis, tetapi bukti lingkungan staging terisolasi belum lengkap.
Status ini bukan kegagalan build; artinya operasi berikutnya membutuhkan target,
backup, dan approval yang belum ditentukan.

## Bukti yang sudah tersedia

- [Shared-hosting artifact r3](../../release-artifacts/laravel-foundation-shared-hosting-20261030-r3-upload-manifest.md)
  dibuat ulang setelah asset Vite terbaru dan exclusion scan menghasilkan
  `forbidden_count=0`.
- `npm run build`: PASS.
- `php artisan about`: PASS untuk Laravel 13.39.0 dan PHP 8.4.27.
- `php artisan route:list`: PASS, 26 route termasuk compatibility route dan pilot.
- `php artisan test`: PASS, 46 test dan 415 assertion.
- `git diff --check`: PASS.
- OAuth foundation memakai sandbox lokal dan test tidak memanggil provider Live;
  webhook tetap nonaktif.
- Kandidat backup lokal tersedia di `storage/backups/`, tetapi belum dianggap
  verified backup sampai checksum, isi, key, dan restore ke target terisolasi
  berhasil.

## Matriks gate

| Item | Status | Bukti / gap |
|---|---|---|
| 1. Artifact + restore DB/file/key | PARTIAL / BLOCKED | Artifact r3 terverifikasi. Restore DB, file privat, dan key belum dilakukan. |
| 2. Scheduler + outbound write disabled | PARTIAL | Provider Live tidak dipanggil oleh test dan webhook nonaktif. Tidak ada bukti operational stop pada target staging. |
| 3. Migration dependency/registry | BLOCKED | `php artisan migrate:status` mengembalikan `Migration table not found`; tidak ada migration dijalankan. |
| 4. Login/workspace/scope/HPP/XLSX/download | PARTIAL | Fixture dan feature tests lokal lulus; user, file, dan koneksi database hasil restore belum diuji pada staging. |
| 5. HTTPS/session/proxy/host/URL | NOT PROVEN | Konfigurasi Secure/SameSite dapat diatur; uji HTTPS, trusted proxy, host allowlist, dan URL publik belum ada. |
| 6. Callback document root/provider | BLOCKED | Legacy config masih membentuk `JL_APP_BASE_URL + /public/api/marketplace-oauth.php`; callback provider Laravel baru belum diregistrasikan dan diuji. |
| 7. OAuth state/multi-store/scope/dedupe | PARTIAL | Sandbox test mencakup skenario failure, replay, multi-store, dan foreign store; provider Live/seller approval belum diuji. |
| 8. Queue/retry/restart/heartbeat | PARTIAL / BLOCKED | Test queue dan scheduler ada, tetapi DB lokal belum memiliki `failed_jobs`; live worker restart/heartbeat belum dibuktikan. |
| 9. Restore rehearsal + go/no-go | BLOCKED | Belum ada restore rehearsal dan catatan checksum/post-check/rollback pada target terisolasi. |

## Verifikasi yang sengaja tidak dilakukan

Tidak dijalankan: `migrate --force`, migration terhadap DB legacy, restore ke
target yang belum disetujui, `queue:retry`, `queue:flush`, `db:wipe`, repair,
provider Live, pemasukan token/key melalui chat, atau cutover URL.

## Input yang diperlukan untuk membuka gate

1. Konfirmasi backup DB/file privat yang authoritative, termasuk checksum dan
   lokasi key privat. Jangan kirim key/token ke chat.
2. Nama DB/user target staging terisolasi dan folder staging yang tidak dapat
   menulis ke production.
3. Daftar migration ID yang disetujui setelah dependency/registry diperiksa;
   jangan gunakan seluruh folder migration secara otomatis.
4. Metode pematian scheduler dan outbound write yang benar-benar dibaca aplikasi,
   plus konfirmasi provider Live tetap disabled selama rehearsal.
5. Approval eksplisit untuk restore rehearsal read/write pada target terisolasi,
   dengan acceptance criteria checksum, jumlah/ID entitas, nominal/HPP, scope,
   login, file, dan rollback.

Setelah input tersebut tersedia, urutannya adalah: restore ke target isolasi,
matikan scheduler/outbound, verifikasi schema dan registry, jalankan hanya
migration yang disetujui, lakukan smoke/regression/security checks, lalu tulis
keputusan **GO** atau **NO-GO** berdasarkan bukti.
