> **Catatan arsip (6 Nov 2026):** dokumen ini ditulis saat aplikasi masih PHP murni. Jalur file, skrip, dan kelas yang disebut di bawah (`app/Modules`, `public/*.php`, `bin/workers`, dll.) sudah dihapus dan digantikan Laravel; lihat [penghapusan kode lama](../architecture/legacy-removal.md) dan [arsitektur aktif](../architecture/../architecture.md). Perilaku bisnis yang dijelaskan tetap berlaku.

# Staging release gate — 2026-11-01

## Decision

Pembaruan eksekusi Step 1–4: [laporan terbaru](./laravel-step1-4-execution-20261101.md). Source suite terbaru lulus 59 tests / 539 assertions; rehearsal empat migration pada clone lulus apply/rerun/rollback. Audit menyeluruh menemukan 22 baris orphan (17 stok dan 5 item pesanan), serta 5 produk Konveksi tanpa ID stok. Temuan yang sama masih ada pada source aktif. Backup privat lokal sudah dibuat, tetapi baseline data dan hosting HTTPS belum lulus. Artifact r4 di bawah adalah bukti build sebelumnya dan belum memuat migration sesi baru; **NO-GO tetap berlaku**.

**BLOCKED untuk staging restore dan cutover produksi.** Build/source lokal sudah
terverifikasi dan artifact r4 tersedia. Tidak ada bukti yang mengizinkan
pernyataan restore/live-operation/cutover selesai. Status blocker ini adalah
gate operasional eksternal, bukan kegagalan build lokal.

## Bukti lokal terkini

- Source suite: **58 tests / 532 assertions — PASS**.
- `npm ci`, `npm run build`, dan production Composer install: PASS.
- `php artisan about`: Laravel 13.39.0, PHP 8.4.27, production mode, debug OFF.
- `php artisan route:list --json`: 31 route.
- HTTP GET `/` dari artifact r4: HTTP 200.
- Scan ZIP r4: 6.075 file, 0 file terlarang; `trustHosts` dan
  `JL_DOCUMENT_ROOT_PUBLIC` ada pada bytes artifact.
- Bukti callback terbaru: path document-root dan sandbox sudah dibuktikan lokal;
  tidak ada registrasi callback/provider Live.
- Detail checksum/build: [r4 build evidence](./laravel-foundation-shared-hosting-r4-evidence-20261101.md).
- Step 11 rehearsal lokal: [isolated staging evidence](./staging-rehearsal-step11-20261101.md).
- Step 12 backup/restore lokal: [isolated backup and restore evidence](./staging-backup-restore-step12-20261101.md).
- Step 13 host verification: [staging HTTPS/callback/queue evidence](./staging-host-verification-step13-20261101.md).
- Catatan rilis 30 Oktober tetap tersimpan sebagai historical snapshot di
  [gate lama](./staging-release-gate-20261030.md).

## Matriks gate operasional

| Gate | Status | Yang sudah ada | Bukti yang masih dibutuhkan |
|---|---|---|---|
| Artifact | PASS lokal | ZIP r4, hash, allowlist, exclusion scan, smoke HTTP | Validasi ulang pada mesin/OS target jika berbeda |
| Restore database, private files, key | BLOCKED | Both DB dumps restored and configured legacy encryption key copied to ACL-restricted local temp staging; 17 FK orphans found and detailed in [owner decision packet](./orphan-variant-owner-reconciliation-20261101.md); private-file archive absent | Owner-approved orphan mapping/disposition and ledger reconciliation; authorized private-file backup + checksum and restore; Konveksi staging-only connection config; full post-check/rollback and host security review |
| Scheduler dan outbound write benar-benar mati | PARTIAL local only | No scheduler/worker started; OAuth sandbox mode, blank provider secrets, no outbound call in local rehearsal | Host-level proof that all scheduler, worker, browser trigger and outbound write paths are disabled |
| Migration dependency/registry | PARTIAL | Registry approves no migration for read-only slice; `migrate:status` reports missing migrations table; no schema migration run; old 80-table note corrected to 117 from exact-hash dump and isolated target | Approve exact migration set before any write slice; keep migrations deferred until authorized staging baseline exists |
| Login, workspace, tenant/store, HPP/laba, XLSX/download | PARTIAL | Feature/regression tests pass; SELECT-only DB user reads schema; health endpoint returns 200 | Authorized identity and full data/file smoke on staging; reconcile HPP/nominal; test Konveksi isolated connection |
| HTTPS, cookie, proxy, host allowlist, absolute URL | PARTIAL | Full suite 58/532 and focused hosting contract tests pass locally; no host request made | Actual staging origin, proxy/TLS config, operator access, and observed HTTPS/cookie/host-allowlist evidence |
| OAuth callback | PARTIAL | Sandbox callback/path and boundary tests pass locally; no provider request made | Sandbox app registration for exact staging HTTPS callback, sandbox identity, and callback test evidence on target |
| OAuth Live/seller consent | BLOCKED | Platform boundary sandbox tersedia; token tidak ada dalam paket | Approval seller, app/provider approval, dan task Live terpisah dengan credential disimpan di secret manager host |
| Cron/worker retry/restart/heartbeat | PARTIAL | Queue/scheduler contract, bounded job, retry/checkpoint tests pass on fixtures; no host worker started | Operator access, single cron trigger, worker run/stop, overlap/restart/failure/retry, and heartbeat evidence on staging |
| Production migration/cutover | BLOCKED | Tidak dilakukan | Go/no-go tertulis, backup final, rollback plan, maintenance window, dan smoke setelah cutover |

## Callback contract terkini

Pernyataan 30 Oktober bahwa kode selalu membentuk
`JL_APP_BASE_URL + /public/api/marketplace-oauth.php` sudah tidak berlaku sebagai
deskripsi source terbaru. `JL_DOCUMENT_ROOT_PUBLIC=true` membentuk callback
tanpa segmen `/public`; `false` mempertahankan layout XAMPP lama. Laravel
callback memakai root-public route. Uji pembentukan URL dan sandbox lokal lulus,
tetapi callback provider belum didaftarkan dan HTTPS staging aktual belum ada.

## Urutan membuka gate

1. Bangun ulang/ambil r4 yang hash-nya cocok; jangan gunakan r3 untuk rilis baru.
2. Owner staging menyiapkan host, backup authoritative, file privat, dan key
   melalui kanal penyimpanan rahasia—jangan kirim key/token ke chat.
3. Pulihkan seluruh bahan ke isolasi dan buktikan scheduler/outbound write mati.
4. Cocokkan schema dengan registry, jalankan hanya migration yang disetujui,
   simpan precondition/post-check/rollback evidence.
5. Jalankan checklist login/scope/keuangan/file/HTTPS/callback/queue pada target.
6. Minta approval provider Live dan cutover secara eksplisit hanya sesudah semua
   gate di atas PASS; sampai itu, keputusan tetap **NO-GO**.

Logging pada r4 mengikuti Laravel default: `stack`/`single`, dengan fallback
`LOG_LEVEL=debug`. Rehearsal lokal memakai `LOG_CHANNEL=null`, `LOG_LEVEL=warning`,
dan `APP_DEBUG=false`; sebelum staging sungguhan, pastikan log berada di luar
public, level warning, permission/rotasi benar, dan konteks log bebas secret.
