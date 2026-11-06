> **Catatan arsip (6 Nov 2026):** dokumen ini ditulis saat aplikasi masih PHP murni. Jalur file, skrip, dan kelas yang disebut di bawah (`app/Modules`, `public/*.php`, `bin/workers`, dll.) sudah dihapus dan digantikan Laravel; lihat [penghapusan kode lama](../architecture/legacy-removal.md) dan [arsitektur aktif](../architecture/../architecture.md). Perilaku bisnis yang dijelaskan tetap berlaku.

# Bukti Step 9 — callback OAuth dan hosting contract

Tanggal: 2026-11-01
Task: OPS-CALLBACK-001
Lingkungan: lokal terisolasi/staging-shaped; tidak ada provider Live dan tidak ada
token Live.

## Perubahan yang diverifikasi

- Konfigurasi legacy tidak lagi menambahkan /public secara unconditional.
  JL_DOCUMENT_ROOT_PUBLIC=true membentuk
  https://staging.example.test/api/marketplace-oauth.php; mode XAMPP lama
  false tetap membentuk
  https://staging.example.test/public/api/marketplace-oauth.php.
- URL Laravel memakai APP_URL, dengan fallback ke JL_APP_BASE_URL, dan route
  callback Laravel tetap berada di /pilot/marketplace/oauth/callback.
- Foundation menyediakan konfigurasi exact host allowlist melalui
  JL_ALLOWED_HOSTS dan trusted reverse proxies melalui JL_TRUSTED_PROXIES.
  Proxy hanya dipercaya bila IP/CIDR diisi eksplisit.
- Contoh environment staging menetapkan SESSION_PATH=/,
  SESSION_SECURE_COOKIE=true, SESSION_HTTP_ONLY=true, dan
  SESSION_SAME_SITE=lax.
- Webhook tetap tidak aktif; route webhook tidak didaftarkan pada Laravel
  foundation dan provider Live tidak dipanggil.

## Bukti test

| Pemeriksaan | Hasil |
|---|---|
| PHP lint pada config/bootstrap yang berubah | PASS |
| HostingContractTest + OAuthSandboxTest | PASS — 7 test, 127 assertion |
| Full Laravel PHPUnit suite | PASS — 58 test, 532 assertion |
| Legacy OAuth smoke dengan MariaDB terisolasi port 3307 | PASS |
| Legacy OAuth origin smoke | PASS |
| Shopee integration-shaped smoke | PASS |
| artisan about dengan APP_ENV=staging, APP_DEBUG=false, URL HTTPS-shaped | PASS; queue database, debug OFF |
| artisan route:list | PASS; 30 route, compatibility callback tersedia |
| Token marker pada Laravel runtime logs | PASS; tidak ditemukan |
| Provider Live / webhook | NOT CALLED / DISABLED |

Scenario sandbox yang tetap tercakup: state invalid/expired/replay,
origin berbeda, sesi hilang, exchange failure, timeout/retry, rate limit/retry,
refresh failure, multi-store selection, dan foreign-company store.

## Status gate

**PASS WITH RISKS — technical staging-shaped contract.**

Kontrak aplikasi, URL, cookie setting, host/proxy configuration, state isolation,
redaksi token, dan sandbox callback telah terbukti. Namun status belum menjadi
GO operasional karena dua bukti eksternal memang belum boleh dibuat oleh agent
lokal:

1. hostname HTTPS staging yang sebenarnya belum diberikan/diuji melalui browser;
2. callback per platform belum diverifikasi byte-for-byte terhadap portal provider
   dan approval seller belum dilakukan.

Dengan demikian provider Live, token Live, webhook, dan cutover URL tetap
terblokir. Sebelum Step 10, owner operasi perlu menyediakan URL staging
authoritative dan mencatat callback registration/provider approval tanpa
menaruh secret di chat atau repository.
