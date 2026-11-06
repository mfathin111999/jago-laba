# Audit "Laravel way" hasil migrasi — 5 November 2026

Ruang lingkup: `laravel-foundation/` (backend `app/`, `routes/`, `bootstrap/`, `config/`, `tests/`, dan frontend `resources/js`).
Basis kode: commit `49bb777` (cabang `codex/prompt-10-migration-review`) beserta perubahan UI yang belum di-commit.
Metode: pembacaan kode dan pencacahan `grep` pada `app/`, `routes/`, `tests/`, `resources/js`. Angka adalah indikasi, bukan hitungan formal.
Batasan: keputusan pemilik 4 Nov 2026 melarang perubahan logika bisnis. Semua rekomendasi di sini **mempertahankan perilaku**; 272 test PHP menjadi jaring pengaman.

## 1. Ringkasan eksekutif

Aplikasi sudah memakai kerangka Laravel secara benar di lapisan dasar (routing, FormRequest, Query Builder, queue, scheduler, config). Namun bagian terbesarnya masih ditulis seperti PHP prosedural yang dibungkus Laravel. Penyebab utamanya: migrasi dilakukan dengan menjaga kontrak legacy (URL `*.php`, sesi PHP, payload array, penanganan error per endpoint).

| Area | Penilaian | Catatan singkat |
|---|---|---|
| Routing & middleware | Cukup | Banyak duplikasi, belum memakai group dan route model binding |
| Validasi | Cukup | 18 FormRequest ada, tetapi `authorize()` selalu `true`; validasi ulang di service |
| Otorisasi | Lemah | Middleware string + `RolePolicy` manual; Gate/Policy bawaan hampir tak dipakai |
| Data/Model | **Lemah** | Hanya 1 model (`User`, tidak dipakai); semua baris berupa array/`stdClass` |
| Penanganan error & respons | **Lemah** | 6 helper `error()` salinan, 35 `catch (Throwable)` di controller, JSON ditulis tangan |
| Struktur & penamaan | Lemah | Namespace `Pilots` (sisa fase uji), dua rumah service, controller datar |
| Konfigurasi | Cukup | `config/jago.php` baik; 2 file memanggil `env()` di luar config |
| Queue/Scheduler | **Baik** | Job punya `tries`, `backoff`, `WithoutOverlapping`, `failed()` |
| Storage/berkas | Lemah | Tidak ada `Storage::`; 22 operasi berkas manual |
| Frontend ↔ backend | Lemah | URL di-hardcode, `fetch` kustom, tanpa `useForm`/helper `route()` |
| Test | Cukup | Banyak dan bermakna, tetapi skema dibuat manual di tiap test |

Bagian yang **sengaja** tidak "Laravel way" demi kompatibilitas legacy (URL `*.php`, jembatan sesi PHP, database yang dipakai bersama) ditandai "dibenarkan sementara" di bawah dan jangan diperbaiki sebelum cutover selesai.

## 2. Yang sudah baik (pertahankan)

- **Query Builder dipakai luas** (±246 pemanggilan `->table()`), tidak ada PDO mentah di lapisan aplikasi.
- **Queue yang benar**: [MarketplaceSyncJob.php](../../laravel-foundation/app/Pilots/Marketplace/MarketplaceSyncJob.php) dan [MarketplaceLiveSyncJob.php](../../laravel-foundation/app/Pilots/Marketplace/MarketplaceLiveSyncJob.php) memakai `ShouldQueue`, `tries`, `timeout`, `backoff()`, middleware `WithoutOverlapping`, dan `failed()`.
- **Scheduler**: [routes/console.php](../../laravel-foundation/routes/console.php) memakai `Schedule::command(...)->withoutOverlapping()`.
- **Konfigurasi terpusat** di `config/jago.php`; `trustProxies` dan `trustHosts` diatur lewat `bootstrap/app.php`.
- **Class `final` dan dependency injection** lewat constructor di hampir semua controller dan service.
- **Request ID di header respons** dan idempotensi tulis (konsep tepat; implementasinya yang perlu dirapikan, lihat B2).

## 3. Temuan, urut dari dampak terbesar

Tiap temuan: kondisi, bukti, padanan di Laravel, risiko perbaikan, dan usaha (R = rendah, S = sedang, T = tinggi).

### A. Data & domain

**A1. Tidak ada Eloquent; semua baris berupa array/`stdClass`** — dampak tinggi, usaha T
- Bukti: `app/Models/` hanya berisi `User.php`, dan kelas itu tidak direferensikan di `app/`, `config/`, `database/`, maupun `tests/`. Filter `company_key` dan `store_id` ditulis ulang manual di setiap query, mis. [MarketplaceExpenseWriteService.php](../../laravel-foundation/app/Pilots/Marketplace/MarketplaceExpenseWriteService.php). 65 pemanggilan `$database->connection()` dan sekitar 8.900 baris service memakai bentuk ini.
- Laravel: model Eloquent (`MarketplaceStore`, `MarketplaceOrder`, `MarketplaceExpense`, dan seterusnya) dengan `$table`, `$connection`, `casts`, relasi, dan **global scope** per perusahaan.
- Manfaat: risiko lupa filter perusahaan (kebocoran lintas perusahaan, pernah ditemukan audit A9) turun karena scope otomatis. Model Konveksi cukup memakai `protected $connection`.
- Catatan: database dipakai bersama legacy, jadi model memetakan skema yang ada (tanpa migration baru untuk tabel legacy).

**A2. Status dan konstanta berupa string** — dampak sedang, usaha R
- Bukti: tidak ada satu pun `enum` PHP. Status `final/estimated/unknown`, peran, tab, dan status klaim tersebar sebagai string; [MarketplaceSettlementAndClaimService.php:32](../../laravel-foundation/app/Pilots/Marketplace/MarketplaceSettlementAndClaimService.php#L32) menolak nilai enum database secara manual.
- Laravel: `enum` PHP (backed) plus `casts` pada model dan aturan validasi `Rule::enum()`.

**A3. Pengecekan skema di runtime** — dampak sedang, usaha S
- Bukti: 64 pemanggilan `Schema::hasTable/hasColumn`, terbanyak di [MarketplaceStoreDeletionService.php](../../laravel-foundation/app/Pilots/Marketplace/MarketplaceStoreDeletionService.php) (16), [OrderReadService.php](../../laravel-foundation/app/Pilots/Marketplace/OrderReadService.php) (7), [AuditLogger.php](../../laravel-foundation/app/Audit/AuditLogger.php). Pengecekan dijalankan pada tiap permintaan.
- Laravel: migration sebagai satu-satunya sumber skema; pengecekan ketersediaan cukup di health check atau saat boot.
- Dibenarkan sementara untuk tabel milik legacy; tabel milik Laravel (`idempotency_keys`, `sessions`, `jobs`) tidak perlu dicek per request.

### B. HTTP: controller, error, respons

**B1. Penanganan exception ditulis ulang di setiap controller** — dampak tinggi, usaha S
- Bukti: 35 blok `catch (Throwable)` di controller (terbanyak [MarketplaceStoreWriteController.php](../../laravel-foundation/app/Http/Controllers/MarketplaceStoreWriteController.php) 5, `MarketplaceHppWriteController` 3). Helper `error()` identik tersalin di 6 controller: `MarketplaceExpenseController:58`, `MarketplaceOAuthController:174`, `MarketplacePriceController:76`, `MarketplaceSyncController:53`, `MarketplaceWithdrawalPreviewController:68`, dan `LegacyRpcController:668`. Pemetaan exception ke kode HTTP memakai `DomainException` dengan kode numerik (28 tempat) dan `getCode()` (18 tempat).
- Laravel: `->withExceptions()` dengan `render()`/`renderable` per tipe exception, exception domain bertipe sendiri (`StoreForbidden`, `NoIntegratedStore`) yang punya `render()`, serta `report()`/`dontReport`. [bootstrap/app.php](../../laravel-foundation/bootstrap/app.php) kini hanya berisi `shouldRenderJsonWhen`.
- Manfaat: controller menyusut menjadi alur utama saja; bentuk error konsisten di satu tempat.

**B2. Respons JSON dan request ID ditulis tangan** — dampak sedang, usaha S
- Bukti: ±165 `response()->json(...)` (30 di `LegacyRpcController`, 17 di `MarketplaceStoreWriteController`, 12 di `MarketplaceHppWriteController`). `bin2hex(random_bytes(8))` untuk request ID muncul di 9 file dan header `X-Request-Id` diset di 52 tempat.
- Laravel: middleware `AssignRequestId` yang memakai `Context::add('request_id', ...)` (otomatis ikut ke log) dan menambah header; satu `ApiResponse`/`JsonResource` untuk bentuk `{success, message, code, request_id}`.

**B3. `LegacyRpcController` 681 baris dengan rantai `if ($method === ...)`** — dampak sedang, usaha S–T
- Bukti: [LegacyRpcController.php:105](../../laravel-foundation/app/Http/Controllers/LegacyRpcController.php#L105) berisi puluhan `if` berurutan, ditambah gate (`GATED_METHODS`), pengecekan peran, dan pembentukan respons di kelas yang sama.
- Laravel: peta `method => handler` (class invokable per method) yang di-resolve lewat container; gate (kunci, idempotensi, versi koleksi) sebagai middleware/`Pipeline`.
- Dibenarkan sementara: nama method RPC adalah kontrak klien Konveksi lama. Yang diubah hanya cara dispatch, bukan nama method.

**B4. Blok "belum ada toko / pilih toko" diulang** — dampak rendah, usaha R
- Bukti: penanganan `NO_INTEGRATED_STORE` dan `STORE_SELECTION_REQUIRED` diulang di 8 titik controller (mis. [MarketplacePilotController.php](../../laravel-foundation/app/Http/Controllers/MarketplacePilotController.php), `MarketplaceOrderController`), masing-masing merakit props kosong yang hampir sama untuk `Inertia::render` (21 pemanggilan).
- Laravel: exception bertipe yang di-render sekali, atau helper/`Responsable` yang membangun halaman kosong.

**B5. Input diambil dan dikonversi manual** — dampak rendah, usaha R
- Bukti: 54 pola `(string) $request->input(...)`; `storeId()`/`filters()` di FormRequest memakai `is_scalar` dan `trim` manual ([MarketplacePilotRequest.php](../../laravel-foundation/app/Http/Requests/MarketplacePilotRequest.php)).
- Laravel: `prepareForValidation()`, `$request->string()`, `$request->date()`, `$request->enum()`, `validated()` dengan tipe; `safe()->only()`.

### C. Routing & middleware

**C1. Route file berulang** — dampak sedang, usaha R
- Bukti: [routes/web.php](../../laravel-foundation/routes/web.php) 254 baris, 76 route. Array `['workspace.context:marketplace', 'role.action:...', 'store.context:store_id', 'browser.csrf']` disalin ke puluhan route. 27 route masih berakhiran `.php`.
- Laravel: `Route::middleware([...])->prefix()->name()->group()`, `Route::controller(...)->group()`, `Route::resource`/`apiResource` untuk toko dan pengeluaran, serta route model binding (`{store}` menjadi `MarketplaceStore`).
- Dibenarkan sementara: URL `*.php` untuk kompatibilitas klien legacy. Pertahankan sebagai route alias, tetapi route baru sebaiknya memakai URL bersih.

**C2. Alias middleware ganda** — dampak rendah, usaha R
- Bukti: [bootstrap/app.php](../../laravel-foundation/bootstrap/app.php) mendaftarkan 12 alias untuk 6 middleware (`company` dan `company.context`, `store` dan `store.context`, `workspace` dan `workspace.context`, `role` dan `role.action`).

**C3. CSRF dan konteks diteruskan lewat `attributes`** — dampak sedang, usaha S
- Bukti: [EnsureBrowserCsrf](../../laravel-foundation/app/Http/Middleware/EnsureBrowserCsrf.php) dan `CsrfTokenValidator` menduplikasi perlindungan CSRF Laravel (`preventRequestForgery` sudah aktif, dengan pengecualian `api/rpc.php`). Konteks perusahaan/toko/ruang kerja diteruskan lewat `$request->attributes` dan dibaca ulang di 56 tempat (`attributes->get('company_key')`).
- Laravel: satu sumber CSRF bawaan (dengan header `X-CSRF-TOKEN`), dan konteks permintaan sebagai singleton/`Context`/macro `$request->companyKey()` sehingga service tidak bergantung pada string atribut.
- Dibenarkan sementara: token CSRF dari sesi legacy masih dipakai klien Konveksi.

### D. Autentikasi & otorisasi

**D1. Otorisasi lewat middleware string, bukan Gate/Policy** — dampak tinggi, usaha S
- Bukti: [EnsureRoleAction.php](../../laravel-foundation/app/Http/Middleware/EnsureRoleAction.php) memanggil `RolePolicy::allows()` langsung. `Gate::define('role-action', ...)` ada di `AppServiceProvider` tetapi hanya ±7 pemanggilan `Gate/authorize/can` di seluruh kode. Seluruh 18 FormRequest mengembalikan `authorize(): true`.
- Laravel: `Gate::define`/Policy per model, route `->can('hpp.edit')`, `authorize()` di FormRequest, `$user->can()` dan prop Inertia `can` untuk menyembunyikan tombol.
- Manfaat: izin satu pintu, bisa dites dengan `Gate::allows`, dan UI tidak perlu menebak peran.

**D2. Sesi legacy disalin ke `$_SESSION`** — dibenarkan sementara
- Bukti: [LegacySessionBridge.php](../../laravel-foundation/app/Http/Middleware/LegacySessionBridge.php) memanggil `session_start()`/`session_name()`; [LegacySessionStore.php](../../laravel-foundation/app/Auth/LegacySessionStore.php) menulis `$_SESSION[...]` manual.
- Laravel: `Auth::guard()`, `session()`, dan guard kustom/`Auth::viaRemember`. Jembatan ini dibutuhkan selama legacy dan Laravel hidup berdampingan; hapus setelah cutover. Rencanakan penghapusannya sebagai bagian F4.

### E. Struktur, penamaan, loader legacy

**E1. Namespace `Pilots` dan dua rumah service** — dampak sedang, usaha S (pemindahan berkas)
- Bukti: `app/Pilots/Marketplace` (26 berkas) dan `app/Pilots/Konveksi` (11) berisi seluruh service tulis/baca, sedangkan `app/Marketplace` (12) berisi service lain dengan peran serupa. 47 controller datar dalam satu folder. Dua Job berada di `Pilots/Marketplace`.
- Laravel: konvensi `app/Jobs`, folder domain (`app/Domain/Marketplace`, `app/Domain/Konveksi`) atau `app/Services`, controller dalam sub-folder (`Marketplace/`, `Konveksi/`, `Api/`).
- Saran: kerjakan paling akhir; diff besar dan mengganggu refactor lain.

**E2. Kode legacy dimuat dengan `require_once` di controller/service** — dampak sedang, usaha S
- Bukti: 19 pemanggilan `require_once ... Modules/...` di 13 berkas (mis. [MarketplaceAnalyticsController.php:30](../../laravel-foundation/app/Http/Controllers/MarketplaceAnalyticsController.php#L30), `MarketplaceFulfillmentController.php:85`, `CatalogHppWriteService.php:20`).
- Laravel: autoload via `composer.json` (`classmap`/`files`) atau satu `ServiceProvider` yang memuat legacy sekali dan mengikatnya ke container.

**E3. Konfigurasi dibaca lewat `env()` di luar `config/`** — dampak sedang (bug potensial), usaha R
- Bukti: [LegacyRuntime.php:25](../../laravel-foundation/app/Marketplace/LegacyRuntime.php#L25) dan [TokenVault.php:59](../../laravel-foundation/app/Marketplace/TokenVault.php#L59) memanggil `env()` langsung.
- Risiko: setelah `php artisan config:cache` (praktik standar produksi), `env()` di luar config mengembalikan `null`. Jembatan `putenv` ke kode legacy bisa diam-diam kosong dan kunci enkripsi token tidak terbaca.
- Laravel: pindahkan ke `config/jago.php`, baca lewat `config()`.

### F. Berkas, waktu, cache, event

**F1. Berkas dikelola manual** — dampak sedang, usaha S
- Bukti: 22 pemanggilan `file_put_contents/fopen/unlink` (backup di [KonveksiBackupService.php](../../laravel-foundation/app/Pilots/Konveksi/KonveksiBackupService.php), dokumen di `KonveksiDocumentAndAiService.php:58`), dan 0 pemakaian `Storage::`. CSV dibuat di 4 tempat berbeda (`CsvDownload`, `MarketplaceAnalyticsController`, `MarketplaceCatalogController`, `MarketplaceReportResponse`) dengan `fopen('php://output')`.
- Laravel: `Storage::disk('private')` (mudah di-`fake()` di test, bisa pindah ke S3), `response()->streamDownload()`, dan satu eksportir CSV (`LazyCollection`/`cursor()` untuk data besar).

**F2. Waktu campuran `date()` dan Carbon** — dampak rendah, usaha R
- Bukti: 126 pemanggilan `date()/strtotime()/gmdate()` berbanding 97 `now()/Carbon`. Zona waktu `Asia/Jakarta` sudah diset di config.
- Laravel: `now()`, `Carbon`, dan `Carbon::setTestNow()` di test.

**F3. Tidak ada cache, event, listener, atau rate limit memadai** — dampak sedang, usaha S
- Bukti: 0 pemakaian `Cache::`; tidak ada folder `Events/Listeners/Notifications`; hanya ±6 sebutan `throttle`.
- Laravel: `Cache::remember` (dengan tag per perusahaan) untuk dashboard dan analitik yang berat; event `StoreUpdated`/`HppChanged` dengan listener untuk audit; `RateLimiter::for()` pada login, OAuth start, dan impor berkas.

**F4. Audit log buatan sendiri** — dampak rendah, usaha S
- Bukti: [AuditLogger.php](../../laravel-foundation/app/Audit/AuditLogger.php) menulis ke `system_audit_logs` dengan pengecekan skema pada tiap panggilan.
- Laravel: model + observer atau listener event; `Schema::hasTable` tidak perlu per panggilan.

### G. Frontend (Inertia/Vue)

**G1. URL di-hardcode dan `fetch` kustom** — dampak sedang, usaha S–T
- Bukti: ±83 string URL (`'/pilot/marketplace/...'`, `'/api/...'`) di `resources/js`; 30 pemanggilan `apiRequest(` kustom ([api.ts](../../laravel-foundation/resources/js/lib/api.ts)); 0 pemakaian `useForm`, `router.post/put/delete`, dan helper `route()`. Token CSRF dan header idempotensi dirakit manual (7 dan 14 tempat).
- Laravel/Inertia: `useForm()`/`router.post()` (CSRF otomatis, error validasi menjadi `form.errors`), nama route lewat Ziggy atau Laravel Wayfinder, dan `redirect()->back()->with('flash')` + prop bersama untuk pesan sukses.
- Dibenarkan sementara: banyak endpoint adalah kontrak JSON legacy yang juga dipakai klien lama; `apiRequest` masuk akal selama endpoint itu dipertahankan. Endpoint baru sebaiknya memakai alur Inertia.

### H. Test

**H1. Skema dibuat manual di tiap test** — dampak sedang, usaha S
- Bukti: 185 `Schema::create` di 39 berkas test, hanya 1 pemakaian trait `RefreshDatabase`-sejenis, tidak ada factory selain bawaan `UserFactory`.
- Laravel: satu helper/trait (`CreatesLegacySchema`) atau berkas skema bersama, factory untuk entitas utama, dan `RefreshDatabase`/`LazilyRefreshDatabase`.
- Manfaat: test lebih pendek dan tidak memerlukan perubahan di 39 berkas saat skema berubah.

## 4. Usulan urutan perbaikan

Aturan umum: satu langkah per commit, jalankan suite penuh (`C:\xampp\php8_4\php.exe vendor/bin/phpunit`, ±1,5 menit) dan `npm run build` tiap kali.

| Urutan | Perubahan | Rujukan | Risiko | Usaha | Nilai |
|---|---|---|---|---|---|
| 1 | `env()` ke `config()` | E3 | sangat rendah | R | cegah bug saat `config:cache` |
| 2 | Route group, hapus alias ganda | C1, C2 | rendah | R | route file jauh lebih pendek |
| 3 | Middleware `AssignRequestId` + `Context` | B2 | rendah | S | hapus 9 salinan pembuat ID |
| 4 | Exception bertipe + `withExceptions` render | B1, B4 | sedang | S | controller menyusut |
| 5 | `Storage` dan satu eksportir CSV | F1 | rendah | S | aman, mudah di-fake |
| 6 | Gate/Policy + `authorize()` di FormRequest | D1 | sedang | S | otorisasi satu pintu |
| 7 | Enum PHP untuk status/peran/tab | A2 | rendah | R | validasi tipe |
| 8 | Eloquent + global scope, mulai `MarketplaceStore` dan `MarketplaceExpense` | A1 | sedang–tinggi | T | tutup risiko lintas perusahaan |
| 9 | RPC per handler | B3 | sedang | S–T | mudah dirawat |
| 10 | Loader legacy ke `ServiceProvider` | E2 | sedang | S | controller bersih dari path legacy |
| 11 | Helper skema test + factory | H1 | rendah | S | test lebih ringkas |
| 12 | Cache, event, rate limit | F3 | rendah | S | performa dan jejak audit |
| 13 | Frontend: nama route + `useForm` untuk fitur baru | G1 | rendah | S–T | konsistensi Inertia |
| 14 | Penamaan/struktur folder | E1 | rendah (diff besar) | S | rapi, kerjakan terakhir |
| Pasca cutover | Hapus jembatan sesi legacy dan CSRF ganda | D2, C3 | sedang | S | hapus kode kompatibilitas |

## 5. Catatan metodologi dan batas klaim

- Angka berasal dari `grep` pada pola teks. Satu pola bisa menghitung komentar atau string; tujuannya memberi skala, bukan angka akhir.
- Penilaian "Baik/Cukup/Lemah" bersifat penilaian reviewer terhadap kesesuaian dengan praktik umum Laravel 13 (versi yang terpasang, `^13.17`), bukan standar mutlak.
- Dokumen ini tidak mengevaluasi kebenaran logika bisnis dan tidak mengubah kode. Kesetaraan angka dengan legacy (A9) masih terbuka di `laravel-completion-plan-20261104.md`.

## 6. Status penerapan (cabang `refactor/laravel-way`)

Diperbarui setelah pengerjaan fase 0–5. Setiap langkah dikerjakan dengan test kontrak lebih dulu; suite terakhir: PHPUnit 301 test, 14 test Node, smoke visual 11 halaman × 2 viewport, semuanya hijau.

| Temuan | Status | Commit / catatan |
|---|---|---|
| A1 Eloquent | **Sebagian** | `2f8ebd6`, `fb3f7e4`: model `MarketplaceStore` + `CompanyScope`; dipakai pembacaan toko, service tulis toko, `StoreContext`. Model pengeluaran/pesanan belum (tabel pengeluaran tidak punya `company_key`) |
| A2 Enum | Ditunda (tidak sepadan) | Diukur ulang: status keuangan `final/estimated/unknown` hanya muncul di satu berkas Laravel (`MarketplacePilotReadService`, ±7 kemunculan); logika statusnya selebihnya ada di service legacy yang tidak boleh diubah. Enum hanya akan mengganti beberapa string. Layak dibuat bila status itu mulai dipakai di banyak tempat |
| A3 Cek skema runtime | Belum | Mayoritas untuk tabel milik legacy, sengaja dipertahankan |
| B1 Exception per controller | **Sebagian** | `5526f2b`, `cf052b5`, `944687a`, `eb42ac4`: `ApiException`, `ApiResponse::guard`; controller pengeluaran, toko, HPP, sinkronisasi. Price, WithdrawalPreview, OrderWrite sengaja tidak diubah (bentuk error legacy berbeda) |
| B2 Request ID | Selesai | `a671089` |
| B3 `LegacyRpcController` | **Sebagian** | `604a2fe`: 682 → 581 baris; 10 method seragam lewat satu peta handler. Handler berkondisi khusus belum dipecah |
| B4 Blok "belum ada toko" berulang | Belum | |
| B5 Input manual / FormRequest | Ditunda | `authorize()` masih `true` di 18 kelas, tetapi setiap route sudah dijaga `can:<aksi>` (D1); menduplikasinya di FormRequest menambah risiko ketidakcocokan karena sebagian kelas dipakai oleh lebih dari satu route (pilot dan legacy ajax) |
| C1/C2 Route berulang, alias ganda | Selesai | `7390dad`: 254 → 221 baris, 4 alias dihapus |
| C3 Konteks via `attributes` | **Sebagian** | `b593a9c`: `RequestContext` + `$request->companyKey()`; CSRF ganda dibiarkan sampai pasca-cutover |
| D1 Gate/Policy | Selesai (rute) | `c6957ea`: `can:<aksi>`, `EnsureRoleAction` dihapus. `authorize()` di FormRequest belum |
| D2 Sesi legacy | Dibiarkan | Dibutuhkan sampai cutover |
| E1 Penamaan/struktur | **Sebagian** | `bfa0830`: `Pilots` → `Services`, Job → `Jobs` (dengan alias untuk job yang sudah antre). Controller tetap satu folder; `App\Marketplace` tetap |
| E2 `require_once` legacy | Selesai | `0746510`: `LegacyRuntime::service()/shared()` |
| E3 `env()` di luar config | Selesai | `f221b21` |
| F1 Berkas / CSV | **Sebagian** | `45167e9` dokumen via `Storage`; `2d32b06` CSV via `CsvDownload`. Backup tidak dipindah (gzip/mysqldump butuh path nyata) |
| F2 Waktu | Belum | |
| F3 Cache / event / rate limit | **Sebagian** | Rate limit: login sudah dibatasi sejak awal (`LegacyAuthenticationService`, 5 percobaan/60 dtk per username+IP; audit awal keliru menyebutnya belum ada). Ditambah `throttle:imports` (10/menit) dan `throttle:oauth` (20/menit) per pengguna pada 5 route impor dan OAuth. Cache dan event belum |
| F4 Audit via event | Belum | |
| G1 Frontend URL | Selesai (path statis) | `7fe39c4`: `lib/routes.ts` + test terhadap snapshot route. `useForm` belum dipakai |
| H1 Skema test | **Sebagian** | `e450b96`: dua helper menggantikan 39 blok; tabel yang berbeda per test tetap lokal |
| Pengaman | Baru | `62b3501` snapshot kontrak route (73 route), test karakterisasi per endpoint yang diubah |
