> **Catatan arsip (6 Nov 2026):** dokumen ini ditulis saat aplikasi masih PHP murni. Jalur file, skrip, dan kelas yang disebut di bawah (`app/Modules`, `public/*.php`, `bin/workers`, dll.) sudah dihapus dan digantikan Laravel; lihat [penghapusan kode lama](legacy-removal.md) dan [arsitektur aktif](../architecture.md). Perilaku bisnis yang dijelaskan tetap berlaku.

# Rencana Lanjutan Migrasi Jago Laba — Agent Execution Plan

Tanggal baseline: 2026-10-30
Status: **foundation dan pilot selesai; migrasi penuh belum selesai**
Sumber aturan: [aturan scope foundation](../../laravel-foundation/.ai/rules/scope.md),
[ringkasan migrasi](./php-modernization-migration-summary.md),
[matriks endpoint](./endpoint-inventory-matrix.md), dan
[staging release gate](../deployment/staging-release-gate-20261030.md).

Dokumen ini adalah backlog kerja untuk agent berikutnya. Agent wajib mengambil
satu `task_id` dalam satu sesi. Jangan mengubah banyak vertical slice sekaligus.

## 1. Tujuan akhir

Migrasikan aplikasi lama ke Laravel secara bertahap dengan kondisi berikut:

1. URL lama tetap berfungsi selama masa transisi.
2. Laravel dan aplikasi lama membaca fixture/data yang sama sampai parity lulus.
3. Login, session, workspace, role, company, store, CSRF, nominal, HPP, status,
   audit, file privat, OAuth state, dan job queue tetap memiliki kontrak yang sama.
4. Hanya satu runtime yang menjadi penulis aktif untuk satu proses bisnis.
5. Production shared hosting hanya memakai PHP 8.4, `vendor`, asset Vite hasil
   build, database queue, dan cron singkat. Tidak ada Node runtime, Redis,
   Horizon, Supervisor, daemon, atau provider Live tanpa checkpoint operasional.
6. Cutover hanya dilakukan setelah restore rehearsal, rollback, smoke test, dan
   rekonsiliasi nominal lulus.

## 2. Status saat ini — jangan dikerjakan ulang

| Area | Bukti saat ini | Status |
|---|---|---|
| Stack | Laravel 13, PHP 8.4, Vue 3, TypeScript, Inertia, Vite, Boost | Selesai |
| Compatibility shell | `JL_*`, MySQL, session bridge, health, error/response adapter, route facade | Selesai untuk foundation |
| Auth/scope | Legacy user hash, Laravel session, workspace, company/store, CSRF, role tests | Selesai untuk pilot |
| Marketplace read | Dashboard Inertia, filter/store scope, same-fixture parity | Pilot selesai |
| Konveksi read | `loadSemuaData`, dynamic table map, metadata parity | Pilot adapter selesai; UI/domain belum lengkap |
| Marketplace expense | Request, policy/scope, transaction, audit, scoped idempotency | Satu mutasi selesai |
| OAuth | Semua platform registry memakai sandbox lokal | Live belum boleh dipakai |
| Queue | Database Job, checkpoint, lease, retry, scheduler, atomic dispatch tests | PASS isolated staging; production/live belum dibuktikan |
| Release | Artifact r3 dan exclusion scan | Build selesai; deployment/restore belum |
| Production | Backup restore, permission, cron, HTTPS, callback registration, cutover | Blocked |

Agent tidak boleh mengulang foundation, expense pilot, atau sandbox OAuth kecuali
ada regression failure yang spesifik dan direproduksi.

## 3. Aturan eksekusi yang tidak boleh ditawar

### 3.1 Satu task, satu tujuan

Satu task boleh menyentuh controller, request, action/service, Vue page, dan test
yang diperlukan untuk satu kontrak. Task tidak boleh sekaligus mengubah schema
umum, provider, queue, dan UI modul lain.

### 3.2 Batas data

- Scope company/store selalu berasal dari server/session, bukan dipercaya dari
  input browser.
- Query read Marketplace wajib mengikat `company_key` dan `store_id`.
- Query Konveksi wajib melalui mapping `system_table_map` dan allowlist nama tabel.
- Jangan menyalin token, password hash, secret, PII, atau payload privat ke log,
  screenshot, fixture commit, atau laporan agent.
- Jangan menyamakan `null`, `unknown`, `estimated`, dan `0` pada nominal/HPP.
- Jangan mengubah ID, status, tanggal berlaku, urutan, precision, atau callback
  tanpa perubahan kontrak yang disetujui.

### 3.3 Batas operasi

Agent boleh menjalankan test, build, route inspection, `about`, dan query read-only.

Agent wajib berhenti sebelum menjalankan:

- `migrate --force`, seed, repair, reset, `db:wipe`, `queue:flush`, `model:prune`;
- restore ke target yang belum ditentukan;
- provider/API Live, OAuth seller nyata, webhook production, atau rotasi key;
- penghapusan toko/data, cutover URL, atau perubahan scheduler production.

### 3.4 Satu penulis

Selama pilot, aplikasi lama tetap pemilik traffic. Laravel hanya boleh menulis
ketika task secara eksplisit menetapkan endpoint, tabel, transaction boundary,
idempotency, audit, dan rollback. Jangan mengaktifkan penulis lama dan baru untuk
proses yang sama secara bersamaan.

## 4. Gerbang dependency

```text
G0 Contract baseline
 ├─> G1 Staging restore + outbound disabled
 │    ├─> G2 Approved migration registry/schema
 │    │    └─> G6 Queue/worker staging
 │    └─> G5 HTTPS/session/proxy/host/callback checks
 ├─> R1 Marketplace order read
 │    ├─> R2 Marketplace finance/analytics read
 │    ├─> R3 XLSX/private download read
 │    └─> W1 Next local mutation
 ├─> K1 Konveksi read UI/domain slice
 │    └─> K2 Konveksi write slice per approval
 └─> O1 OAuth sandbox evidence
      └─> O2 Provider registration/live rehearsal (operations approval only)

R1 + K1 + G2 + G5 + G6 + W1 + O2
 └─> G7 Full parity/reconciliation
      └─> C1 Canary/cutover
           └─> C2 Remove compatibility facade after observation
```

`G1`, `G2`, `G5`, `G6`, dan `O2` tidak boleh dianggap selesai hanya karena kode
atau unit test tersedia. Masing-masing membutuhkan bukti target staging/hosting.

## 5. Backlog task yang dapat langsung diberikan ke agent

### OPS-RESTORE-001 — restore rehearsal staging

```yaml
task_id: OPS-RESTORE-001
type: operational-gate
status: blocked-until-inputs
goal: Restore DB, file privat, dan key ke target staging yang benar-benar terisolasi.
depends_on: [G0]
allowed_changes:
  - staging-only database and private storage
  - staging-only environment/configuration
forbidden_changes:
  - production database
  - production scheduler/provider/token
  - application schema without approved migration list
required_inputs:
  - authoritative backup path and checksum
  - isolated database name/user/host
  - isolated application root
  - key delivery through local secret file or secret manager
acceptance:
  - backup checksum recorded
  - restore succeeds into isolated target
  - entity IDs, row counts, relationships, money, HPP, files, and access scope match
  - scheduler and outbound write are disabled by an application-readable control
  - rollback target and evidence are recorded
stop_if:
  - target is production or ambiguous
  - key must be pasted into chat/log
  - restore cannot be verified
```

### DB-REGISTRY-001 — migration registry dan precondition

```yaml
task_id: DB-REGISTRY-001
type: schema-safety
status: completed-read-only-precondition
depends_on: [OPS-RESTORE-001]
goal: Determine the exact Laravel/legacy migrations required by the next slice.
do_not: Run every SQL file, seed, repair, or migration against the legacy DB.
required_output:
  - migration IDs in dependency order
  - precondition query for each migration
  - post-check query for each migration
  - rollback behavior and collision policy
  - owner and checksum
acceptance:
  - staging registry exists and is inspectable
  - dry-run/precondition result is recorded
  - no production command is executed
stop_if:
  - migration ownership or target tables are ambiguous
  - rollback can lose data or recreate global idempotency collisions
```

### MKT-READ-001 — Marketplace orders list dan detail

```yaml
task_id: MKT-READ-001
type: read-only-vertical-slice
status: completed
goal: Migrate one Marketplace order list/detail flow to Laravel/Inertia.
baseline:
  urls:
    - /marketplace.php?tab=transaksi
    - /api/marketplace-orders.php
    - /api/marketplace-ajax.php?action=get_order_detail
  js:
    - public/assets/js/marketplace-orders.js
    - public/assets/js/order-transaction-detail.js
  tests:
    - tests/Integration/marketplace_orders_smoke.php
    - tests/Integration/marketplace_transaction_detail_smoke.php
  table_groups: [M-STORE, M-ORDER, M-CATALOG]
allowed_scope:
  - laravel-foundation/app/Http/Controllers/MarketplaceOrderController.php
  - laravel-foundation/app/Http/Requests/MarketplaceOrderRequest.php
  - laravel-foundation/app/Pilots/Marketplace/OrderReadService.php
  - laravel-foundation/resources/js/Pages/Pilot/MarketplaceOrders.vue
  - laravel-foundation/routes/web.php
  - laravel-foundation/tests/Feature/PilotReadOnlyTest.php
must_preserve:
  - date half-open range and current filters
  - store selection and foreign-store rejection
  - order status and source_kind semantics
  - item, fee, HPP, return, and finance nullability
  - pagination/order and HTTP/JSON/Inertia response shape
must_not:
  - provider refresh
  - fulfillment mutation
  - shipping document write
acceptance:
  - same fixture produces same status, rows, totals, scope, query filters, and props
  - legacy and Laravel tests both pass
  - no provider call and no application DB write
rollback: Remove only new Laravel route/page/service; keep legacy route active.
evidence: docs/deployment/marketplace-order-read-evidence-20261030.md
```

### KNV-READ-001 — Konveksi dashboard/read surface

```yaml
task_id: KNV-READ-001
type: read-only-vertical-slice
status: verification-complete-isolated-staging-pass-with-operational-risk
goal: Render one real Konveksi dashboard/read surface from legacy data through Laravel.
baseline:
  urls: [/index.php?tab=dashboard, /api/rpc.php method=loadSemuaData]
  source:
    - app/Modules/Konveksi/Controllers/RpcService.php
    - app/Shared/Support/SnapshotImporter.php
  tests:
    - tests/Integration/konveksi_integrity_test.php
    - laravel-foundation/tests/Feature/PilotReadOnlyTest.php
  table_groups: [AUTH, K-MAP]
precondition:
  - decide whether the target uses jago_laba or isolated Konveksi DB
  - do not claim isolation while the service uses the default connection
must_preserve:
  - collection allowlist and dynamic table validation
  - _row_order/_row_id ordering
  - __meta versions, reset_epoch, health, and company_bound
  - empty/missing collection behavior
acceptance:
  - same fixture and same company produce same collections/checksums/props
  - real restored staging data is read-only and company-scoped
  - no write and no schema change
evidence: laravel-foundation/docs/pilot-marketplace-konveksi.md
stop_if:
  - isolated DB ownership is unresolved
  - any collection reads a table outside the mapping allowlist
```

### MKT-READ-002 — analytics, finance, and HPP read

```yaml
task_id: MKT-READ-002
type: read-only-finance-slice
status: verification-complete-isolated-fixture-pass
goal: Migrate one analytics/finance read panel without inventing missing numbers.
baseline:
  urls:
    - /api/marketplace-analytics.php
    - /marketplace.php?tab=analitik
  tests:
    - tests/Integration/marketplace_finance_smoke.php
    - tests/Integration/marketplace_price_smoke.php
  table_groups: [M-ORDER, M-FIN, M-CATALOG]
must_preserve:
  - gross sales, fees, discounts, COGS/HPP, profit, and coverage flags
  - estimated/unknown/null versus zero
  - period boundary, store scope, and reconciliation labels
acceptance:
  - totals reconcile line-by-line with legacy fixture
  - HPP effective date and currency precision are unchanged
  - response includes source/coverage metadata where legacy does
stop_if:
  - a missing value can only be guessed
  - rounding or nominal difference is unexplained
evidence: docs/deployment/marketplace-analytics-read-evidence-20261101.md
implementation:
  route: /pilot/marketplace/analytics
  legacy_rollback: /marketplace.php?tab=analitik and /api/marketplace-analytics.php
  provider_calls: none
  writes: none
  parity_fixture: PilotReadOnlyTest SQLite fixture plus legacy finance/analytics smoke fixtures
remaining_risk:
  - provider Live, production MySQL permissions, and production cutover remain operational gates outside this local fixture task.
```

### MKT-FILE-001 — XLSX export/import preview dan download berizin

```yaml
task_id: MKT-FILE-001
type: file-boundary-slice
status: verification-complete-isolated-fixture-pass
goal: Migrate one authorized export or import-preview flow without final write.
baseline:
  files:
    - public/api/marketplace-analytics.php
    - public/api/marketplace-withdrawals.php
    - app/Shared/Support/DocumentService.php
  tests:
    - tests/Integration/marketplace_finance_smoke.php
    - tests/Integration/marketplace_shipping_documents_smoke.php
  table_groups: [K-DOC, M-FIN, AUTH]
must_preserve:
  - private storage location and file authorization
  - MIME, size, filename, expiry, and download response headers
  - import duplicate detection and preview-not-write rule
acceptance:
  - unauthorized company/store cannot preview an upload
  - XLSX fixture round-trips without nominal/date corruption
  - preview creates no final marketplace_withdrawals row
  - preview state is session-bound, expires, and does not persist the upload
  - artifact scan contains no private file or secret
stop_if:
  - document path is public or authorization is derived only from URL input
implementation:
  route: /pilot/marketplace/withdrawals/preview
  legacy_rollback: /api/marketplace-withdrawals.php?action=preview
  provider_calls: none
  writes: Laravel session preview only; no final financial row
  parser: legacy MarketplaceWithdrawalXlsx and MarketplaceWithdrawalService::parseCsv
evidence: docs/deployment/marketplace-file-preview-evidence-20261101.md
```

### MKT-WRITE-002 — next local mutation after expense

```yaml
task_id: MKT-WRITE-002
type: write-vertical-slice
status: verification-complete-isolated-staging-pass-with-operational-risk
goal: Migrate exactly one additional low-risk local Marketplace mutation.
candidate: Select one from store metadata or a non-provider local setting only after owner approval.
required:
  - Form Request
  - Policy/role/workspace/company/store scope
  - CSRF browser protection
  - transaction
  - scoped idempotency/unique constraint
  - audit row
  - legacy adapter
  - success, validation, unauthorized, duplicate, DB failure, rollback, refresh tests
forbidden:
  - HPP/price/stock/order/fulfillment mutation unless a separate task names it
  - provider write
  - schema change hidden inside application code
acceptance:
  - same request cannot produce two effects
  - failure leaves no partial row/audit/receipt
  - old caller and new Inertia caller receive their own stable response shape
stop_if:
  - operation changes financial/HPP truth without reconciliation plan
```

Evidence: [marketplace-expense-write-evidence-20261101.md](../deployment/marketplace-expense-write-evidence-20261101.md)

### OAUTH-SBX-001 — maintain sandbox boundary

```yaml
task_id: OAUTH-SBX-001
type: regression
status: existing-evidence
goal: Keep all provider tests inside local sandbox and prevent accidental Live calls.
must_verify:
  - invalid/expired/replayed state
  - origin mismatch and missing session
  - exchange failure, timeout, rate limit, retry
  - refresh failure
  - multi-store selection and foreign-company store rejection
  - encrypted token not present in response/log/report
forbidden: Provider Live request or seller reauthorization.
```

### QUEUE-STG-001 — queue dan scheduler staging

```yaml
task_id: QUEUE-STG-001
type: staging-integration
status: verification-complete-isolated-staging-pass-with-operational-risk
goal: Prove the bounded database queue on restored staging.
depends_on: [OPS-RESTORE-001, DB-REGISTRY-001]
required_tables: [jobs, failed_jobs, marketplace_sync_jobs, marketplace_sync_checkpoints]
scenarios:
  - initial row and queue insert atomicity
  - next-page enqueue atomicity
  - duplicate dispatch
  - overlapping lease
  - timeout and restart from checkpoint
  - retry limit and failed_jobs
  - one scheduler only
  - manual/browser fallback when cron unavailable
acceptance:
  - no queued domain row without corresponding queue job
  - no duplicate order effect
  - heartbeat/last_success/last_error visible
  - cron command and PHP CLI use the same environment/database
forbidden: Live provider, long-running daemon, Redis, Horizon, Supervisor.
```

Evidence: [marketplace-queue-staging-evidence-20261101.md](../deployment/marketplace-queue-staging-evidence-20261101.md)

### OPS-CALLBACK-001 — callback and hosting contract

```yaml
task_id: OPS-CALLBACK-001
type: operational-integration
goal: Prove the canonical HTTPS callback for the selected staging host.
depends_on: [OPS-RESTORE-001]
must_check:
  - document root points to public
  - callback does not accidentally contain /public when document root already is public
  - APP_URL/JL_APP_BASE_URL and generated absolute URLs agree
  - Secure/SameSite cookie, session path, trusted proxy, host allowlist
  - provider portal callback registration matches byte-for-byte
  - webhook remains disabled until signature/anti-replay/durable inbox gate passes
acceptance: Browser callback returns to the same company/session and wrong-origin/state is rejected.
stop_if: Any URL differs from provider registration or token appears in logs.

Evidence: [marketplace-oauth-callback-evidence-20261101.md](../deployment/marketplace-oauth-callback-evidence-20261101.md)
```

### CUTOVER-001 — go/no-go and canary

```yaml
task_id: CUTOVER-001
type: operations-only
status: human-approval-required
goal: Move one approved slice to Laravel after full rehearsal.
depends_on: [G1, G2, G5, G6, MKT-READ-001, KNV-READ-001, MKT-FILE-001]
required_evidence:
  - final backup and successful restore
  - URL/HTTP/props parity
  - auth/CSRF/role/company/store isolation
  - nominal/HPP reconciliation
  - one scheduler and one writer
  - rollback command and tested recovery target
actions_not_allowed_by_default:
  - global URL switch
  - deleting legacy facade
  - provider Live activation
  - destructive migration
decision: GO or NO-GO must be written by the operational owner.
```

## 6. Urutan kerja yang disarankan

1. Selesaikan input dan approval untuk `OPS-RESTORE-001`; jangan memakai backup
   atau target yang hanya disebut secara lisan.
2. Jalankan `DB-REGISTRY-001` di staging terisolasi dan tetapkan migration IDs.
3. Implementasikan `MKT-READ-001` order list/detail menggunakan fixture yang
   sama dengan legacy. Tetap read-only.
4. Implementasikan `KNV-READ-001` dan selesaikan keputusan koneksi database
   Konveksi sebelum menambah UI/data domain.
5. Implementasikan `MKT-READ-002` untuk finance/HPP dengan rekonsiliasi nominal.
6. Implementasikan `MKT-FILE-001` untuk satu alur file yang dipilih; jangan
   sekaligus memindahkan semua import/export.
7. Jalankan `QUEUE-STG-001` pada schema staging yang sudah disetujui.
8. Pilih `MKT-WRITE-002` hanya setelah read slice, scope, dan rollback lulus.
9. Jalankan `OPS-CALLBACK-001` dengan sandbox/provider approval yang eksplisit.
10. Tulis keputusan `GO/NO-GO` untuk satu slice. Jangan melakukan cutover global.
11. Setelah observasi dan rollback lulus, ulangi pola yang sama untuk slice berikutnya.
12. Hapus compatibility facade hanya pada fase terakhir, setelah seluruh caller
    dan callback lama tidak lagi aktif.

## 7. Template prompt yang wajib dipakai agent

```text
Anda mengerjakan task_id: [TASK-ID]

Baca sebelum coding:
- docs/architecture/php-modernization-next-phase-agent-plan.md
- docs/architecture/endpoint-inventory-matrix.md
- docs/architecture/php-modernization-migration-summary.md
- laravel-foundation/.ai/rules/scope.md
- [baseline file/test yang disebut task]

Tujuan tunggal:
[salin field goal task]

Batas:
- File yang boleh diubah: [daftar eksplisit]
- Tabel yang boleh dibaca: [daftar/group]
- Tabel yang boleh ditulis: [daftar atau NONE]
- Provider: [NONE/SANDBOX saja]
- URL legacy yang harus tetap aktif: [daftar]

Kontrak wajib dipertahankan:
- method/input/output/status/header/redirect
- auth/session/workspace/role/company/store/CSRF
- ID/status/tanggal/nominal/HPP/nullability/order

Sebelum coding:
1. Laporkan baseline dan dependency.
2. Laporkan precondition dan risiko.
3. Tulis test yang akan membuktikan parity.
4. Berhenti bila task memerlukan secret, target DB ambigu, migration tak disetujui,
   provider Live, atau perubahan kontrak.

Sesudah coding:
1. Jalankan test legacy dan Laravel pada fixture yang sama.
2. Jalankan Pint pada file tersentuh dan static check bila tersedia.
3. Jalankan build bila asset/UI berubah.
4. Laporkan diff, query/scope, response, nominal/HPP, waktu, dan rollback.
5. Tandai PASS, PASS WITH RISKS, atau BLOCKED. Jangan menandai PASS hanya karena
   route/controller berhasil dibuat.
```

## 8. Format laporan akhir agent

```yaml
task_id: MKT-READ-001
status: PASS | PASS_WITH_RISKS | BLOCKED
objective: "..."
baseline:
  legacy_routes: []
  legacy_files: []
  legacy_tests: []
changed_files: []
read_tables: []
written_tables: []
provider_calls: none | sandbox | live
contract:
  method: ""
  input: []
  output: []
  status_codes: []
  redirects: []
  headers: []
scope_checks: []
parity:
  fixture_id: ""
  legacy_status: 0
  laravel_status: 0
  data_match: true
  query_match: true
  scope_match: true
  nominal_match: true
  latency_ms: {legacy: 0, laravel: 0}
verification:
  commands: []
  results: []
risks: []
rollback: "..."
next_task: "..."
```

## 9. Definition of Done global

Migrasi satu slice baru hanya dianggap selesai jika semua kondisi berikut benar:

- kontrak legacy dan Laravel lulus pada fixture yang sama;
- test unauthorized, expired session, wrong role, foreign company, foreign store,
  CSRF, duplicate, dan database failure sesuai jenis slice;
- tidak ada perubahan nominal/HPP/status/ID yang tidak direkonsiliasi;
- route lama masih aman atau adapter removal memiliki approval terpisah;
- provider tidak dipanggil Live tanpa task/checkpoint;
- migration memiliki precondition, post-check, checksum, owner, dan rollback;
- queue memiliki checkpoint, lease, retry terbatas, failed job, dan duplicate prevention;
- log/artefak tidak membawa secret, token, password, atau PII;
- Pint/test/build yang relevan lulus;
- laporan agent menggunakan format YAML di atas.

## 10. Kondisi wajib berhenti

Agent harus mengembalikan `BLOCKED` dan tidak memperbaiki sendiri jika:

- backup tidak dapat direstore atau target restore ambigu;
- database/schema/migration owner tidak jelas;
- query memberi akses lintas company/store;
- legacy dan Laravel menghasilkan nominal/HPP/status berbeda tanpa penjelasan;
- callback URL berbeda dari registrasi provider;
- ada dua scheduler/worker yang dapat menulis proses sama;
- job dapat berstatus queued tanpa queue row;
- private file dapat diakses tanpa authorization;
- dibutuhkan password, key, token, approval seller, permission hosting, atau
  provider Live yang belum diberikan;
- rollback hanya berupa `DELETE`, `DROP`, atau tebakan terhadap data produksi.

## 11. Next action paling tepat

Sebelum agent mengerjakan slice tambahan pada database nyata, pemilik operasi perlu
menyediakan input untuk `OPS-RESTORE-001`: path backup authoritative dan checksum,
target DB/folder staging terisolasi, metode key privat, daftar migration yang boleh
dijalankan, serta approval restore rehearsal. Sambil menunggu input tersebut,
agent boleh mengerjakan `MKT-READ-001` hanya pada fixture/test terisolasi dan tanpa
provider atau write ke database aplikasi.
