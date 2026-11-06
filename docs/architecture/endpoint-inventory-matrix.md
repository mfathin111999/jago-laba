> **Catatan arsip (6 Nov 2026):** dokumen ini ditulis saat aplikasi masih PHP murni. Jalur file, skrip, dan kelas yang disebut di bawah (`app/Modules`, `public/*.php`, `bin/workers`, dll.) sudah dihapus dan digantikan Laravel; lihat [penghapusan kode lama](legacy-removal.md) dan [arsitektur aktif](../architecture.md). Perilaku bisnis yang dijelaskan tetap berlaku.

# Endpoint inventory matrix Jago Laba

- Status: static read-only inventory completed for Prompt 2
- Snapshot: 2026-10-30
- Scope: legacy PHP public pages/API, Konveksi RPC, Marketplace AJAX, frontend
  callers, schema references, OAuth, worker, and cron
- Safety: no database write, migration, repair, provider call, or restore was
  executed while creating this inventory

## 1. Reading guide

This is a source-based inventory. `Tables` uses the table groups below so that
the endpoint rows stay reviewable while the full table names remain explicit.
An endpoint can read and write different tables in one request; the effect is
marked in the `Effect` column.

### Table groups

| Group | Tables and dynamic storage | Scope notes |
|---|---|---|
| `AUTH` | `auth_users_v2`, `system_users`, `system_settings`, `system_table_map`, `system_audit_logs`, `workspace_user_access`, `backup_log`, `migration_log`, `document_files`, `idempotency_keys`, native PHP session | User/session, workspace, audit, backup, and compatibility metadata. Company scope is established from session/user/config and must not be trusted from browser input. |
| `K-MAP` | `system_table_map` plus dynamic tables returned by `SnapshotImporter::tableName()`: `rekening`, `produk`, `bahan`, `bahan_hpp`, `hpp_model_bom`, `hpp_history`, `bahan_hpp_history`, `pegawai`, `supplier`, `pembelian`, `pembelian_item`, `potong`, `potong_item`, `jahit`, `jahit_item`, `keuangan`, `kasbon`, `profil`, `distribusi`, `pengguna`, `hak_akses`, `master_stok`, `mutasi_stok`, `pembelian_vendor`, `riwayat_bayar_hutang`, `hutang_konveksi`, `riwayat_bayar_hutang_konveksi`, `template_dokumen`, `penjualan`, `penjualan_item`, `penjualan_koreksi`, `material_alias`, `quality_control`, `mutasi_stok_konveksi`, `hutangsupplier`, `marketplace_product_mapping`, `marketplace_sync_queue` | The physical dynamic table name is resolved from `system_table_map`; inventory cannot claim a fixed table name when a sheet has not been imported. |
| `K-DOC` | `document_files`, private file path from `config/storage.php` | File ID and authorization must be checked before read/download. |
| `K-BACKUP` | `backup_log`, JSON snapshot, SQL dump path, private backup directories | Backup path and restore ability require host-specific verification. |
| `K-IDEMP` | `idempotency_keys`, `konveksi_request_receipts` | Marketplace expense and legacy Konveksi Inventory receipts use unique `(company_key,module,idempotency_key)` scope; remaining mutation paths require separate review. |
| `M-STORE` | `marketplace_stores`, `marketplace_oauth_connections`, `marketplace_oauth_states`, `marketplace_oauth_pending`, `marketplace_partner_settings`, `marketplace_api_credentials`, `marketplace_integration_bindings`, `marketplace_app_settings`, `marketplace_auto_sync`, `marketplace_auto_sync_runtime`, `marketplace_platform_cooldowns` | Store rows must be checked against `company_key`; OAuth connection rows must be checked against store and company. |
| `M-ORDER` | `marketplace_orders`, `marketplace_order_items`, `marketplace_orders_v3`, `marketplace_order_items_v3`, `marketplace_sync_records`, `marketplace_return_claims`, `marketplace_fulfillment_actions`, `marketplace_shipping_documents`, `marketplace_shipping_document_audit` | Order reads use store scope; financial and HPP values are contract data. |
| `M-FIN` | `marketplace_expenses`, `marketplace_product_ads`, `marketplace_ad_spends`, `marketplace_payout_reconciliations`, `marketplace_journal_entries`, `marketplace_withdrawals`, `marketplace_withdrawal_audit`, `marketplace_price_simulations` | Financial writes require audit and transaction semantics; import preview must not write final rows. |
| `M-CATALOG` | `marketplace_catalog_variants`, `marketplace_sku_mappings`, `marketplace_hpp_history`, `marketplace_hpp_changes`, `marketplace_price_actions`, `marketplace_stock_snapshots`, `marketplace_stock_minimums`, `marketplace_stock_minimum_audit` | Catalog and HPP are store-scoped; effective dates and nominal precision are contracts. |
| `M-SYNC` | `marketplace_sync_jobs`, `marketplace_sync_checkpoints`, `marketplace_sync_records`, `marketplace_sync_object_checks`, `marketplace_sync_workers` | Queue/checkpoint/lease state. A job must be bounded, idempotent, and recoverable after timeout. |
| `M-V3` | `marketplace_connected_stores`, `marketplace_oauth_attempts`, `marketplace_webhook_inbox`, `marketplace_listings_v3`, `marketplace_fulfillment_mappings_v3`, `marketplace_business_effects_v3`, `marketplace_financial_events_v3`, `marketplace_payout_order_links_v3` | Integration-review schema is present in legacy migrations but runtime ownership must be confirmed before use. |

### Status vocabulary

- `READ`: no intended database/provider write.
- `WRITE`: database, private file, queue, or provider side effect.
- `STUB`: source intentionally returns an unavailable response.
- `STATIC`: source evidence exists but runtime/database/provider test was not
  run.
- `PILOT`: Laravel implementation exists but does not replace the full legacy
  contract.

## 2. Public page and compatibility URL matrix

| Route and method | Caller / input and output | Auth, scope, CSRF | Tables | Effect | Existing test / evidence | Risk | Laravel target |
|---|---|---|---|---|---|---|---|
| `/index.php` GET | Browser Konveksi dashboard; session and active workspace; HTML shell plus view fragments | Session; Konveksi workspace; company assertion in RPC/data paths; no CSRF for GET | `AUTH`, `K-MAP`, Konveksi normalized tables where view/service uses them | `READ` | [konveksi_integrity_test.php](../../tests/Integration/konveksi_integrity_test.php); legacy HTTP page | Large view surface and dynamic table mapping; full props/query parity not measured | Legacy facade; Laravel pilot is [KonveksiPilotController.php](../../laravel-foundation/app/Http/Controllers/KonveksiPilotController.php) |
| `/marketplace.php` GET | Browser Marketplace page; `tab`, `store`, `store_filter`, `scope`, period/date/filter query; HTML page | Session; Marketplace workspace; company and store selection; no CSRF for GET | `M-STORE`, `M-ORDER`, `M-FIN`, `M-CATALOG`, `M-SYNC` | `READ` | [marketplace_dashboard_smoke.php](../../tests/Integration/marketplace_dashboard_smoke.php), [marketplace_store_selection_smoke.php](../../tests/Integration/marketplace_store_selection_smoke.php); live local page opened | Full tab/store picker/remembered-store contract is not yet mirrored by Laravel pilot | Legacy facade; [LegacyPilotPageController.php](../../laravel-foundation/app/Http/Controllers/LegacyPilotPageController.php) only forwards pilot dashboard |
| `/workspace.php` GET | Browser workspace chooser; session identity; HTML choices | Authenticated session; allowed workspace list; no CSRF for GET | `AUTH`, especially `workspace_user_access` | `READ` | [workspace_policy.php](../../tests/Integration/workspace_policy.php) | Missing access table invokes compatibility defaults; selection parity must be tested | [WorkspaceController.php](../../laravel-foundation/app/Http/Controllers/WorkspaceController.php) |
| `/install.php` GET/POST | Local installer/diagnostic; environment and owner setup inputs; HTML/redirect | Installer boundary; not a normal authenticated route | `AUTH`, `K-MAP`, `K-BACKUP`, schema tables, `storage/install.lock` | `WRITE` schema/user/lock | No safe automated run; intentionally not executed | Sensitive installer must not remain public in production; no release cutover | Keep outside Laravel traffic until operational owner approves |
| `/repair_owner.php` GET/POST | Local owner repair utility; owner/repair inputs; HTML/redirect | Repair boundary; not normal user traffic | `AUTH`, `system_users`, `auth_users_v2` | `WRITE` user credentials/status | No safe automated run; intentionally not executed | High-impact repair endpoint and possible secret exposure | Keep out of public release or protect at host boundary |

## 3. Public API endpoint matrix

| Endpoint and method | Caller / input and output | Auth, scope, CSRF | Tables | Effect | Existing test / evidence | Risk | Laravel target |
|---|---|---|---|---|---|---|---|
| `/api/health.php` GET | Monitor/browser; no input; JSON health | Public read; no CSRF | Connection health only (`SELECT 1`) | `READ` | Foundation HTTP read test and legacy endpoint | Does not prove all required tables, queue, storage, or provider health | [HealthController.php](../../laravel-foundation/app/Http/Controllers/HealthController.php) |
| `/api/auth-diagnostic.php` GET | Owner diagnostic UI; optional username/test flag; JSON diagnostic without returning hash | Session owner/admin boundary in support API | `AUTH` | `READ` | Static source only; no live diagnostic run recorded | Must never expose password hash or enable unauthenticated probing | Laravel diagnostic route not yet part of pilot |
| `/api/diagnostics.php` GET | Owner system diagnostics; JSON schema/backup/config checks | Session owner boundary | `AUTH`, `K-MAP`, `K-BACKUP` | `READ` | Static source only; DB and restore not tested | Reports configuration/path state, not actual restore success | Laravel health/ops endpoint is separate; parity pending |
| `/api/konveksi-document.php` GET | Browser document viewer/download; `id`; PDF/file response or generic error | Authenticated Konveksi session and document permission | `K-DOC`, `AUTH` | `READ` private file | No automated HTTP read/download test recorded | Private document authorization and storage path need staging evidence | Laravel private-storage controller not yet migrated |
| `/api/logout.php` POST | `jl-api.js`, Marketplace settings; CSRF header; JSON status | Authenticated session; CSRF | Native session, `AUTH` optional audit | `WRITE` session destroy | [workspace_policy.php](../../tests/Integration/workspace_policy.php); Laravel auth tests | Legacy and Laravel session bridge must not diverge | [AuthController.php](../../laravel-foundation/app/Http/Controllers/AuthController.php) |
| `/api/workspace.php` POST | Workspace chooser; `workspace`; JSON redirect URL | Authenticated session; allowed workspace; CSRF | `AUTH`, session | `WRITE` session workspace | [workspace_policy.php](../../tests/Integration/workspace_policy.php), [AuthScopeTest.php](../../laravel-foundation/tests/Feature/AuthScopeTest.php) | Compatibility defaults when access registry is absent | [WorkspaceController.php](../../laravel-foundation/app/Http/Controllers/WorkspaceController.php) |
| `/api/rpc.php` POST | `jl-api.js`; `method` and positional `args`; RPC envelope with request ID | Login method may be unauthenticated; other methods require session, Konveksi workspace, company, CSRF; admin for most writes | `AUTH`, `K-MAP`, `K-DOC`, `K-BACKUP`, `K-IDEMP`, normalized Konveksi tables | `READ` or `WRITE` by method | [RpcService.php](../../app/Modules/Konveksi/Controllers/RpcService.php); [AuthScopeTest.php](../../laravel-foundation/tests/Feature/AuthScopeTest.php) for adapter subset | Large RPC surface and positional contract; full Laravel parity not complete | [LegacyRpcController.php](../../laravel-foundation/app/Http/Controllers/LegacyRpcController.php) |
| `/api/marketplace-ajax.php` GET/POST | Marketplace JS/forms; `action` plus action-specific query/form/files; JSON success/error | Marketplace workspace and CSRF for every request; store scope for listed actions; role enforced by service/action | `M-STORE`, `M-ORDER`, `M-FIN`, `M-CATALOG`, `M-SYNC`, `AUTH` | Mixed; see action matrix | [marketplace-ajax.php](../../app/Modules/Marketplace/Controllers/Api/marketplace-ajax.php); [PilotReadOnlyTest.php](../../laravel-foundation/tests/Feature/PilotReadOnlyTest.php) covers pilot adapter paths | 29 actions share one boundary; action-specific authorization and idempotency must not be inferred from UI | Laravel legacy adapter permits only pilot expense action; [MKT-WRITE-002 evidence](../deployment/marketplace-expense-write-evidence-20261101.md) passes on isolated staging |
| `/api/marketplace-dashboard.php` GET | Dashboard refresh/export; `store`, period/date; HTML or CSV | Marketplace workspace; store scope; no CSRF for GET | `M-STORE`, `M-ORDER`, `M-FIN`, `M-CATALOG`, `M-SYNC` | `READ` | [marketplace_dashboard_smoke.php](../../tests/Integration/marketplace_dashboard_smoke.php); local dashboard opened | Dashboard output/query parity and CSV contract need same-fixture comparison | Laravel read pilot service/controller |
| `/api/marketplace-orders.php` GET | Order table refresh/export; store and filters; HTML or CSV | Marketplace workspace; store scope; no CSRF for GET | `M-STORE`, `M-ORDER`, `M-CATALOG` | `READ` | [marketplace_orders_smoke.php](../../tests/Integration/marketplace_orders_smoke.php) | PII, CSV formula injection, pagination/filter/props parity | Not yet migrated as full Laravel page |
| `/api/marketplace-analytics.php` GET | Analytics refresh/export; store/global/date/date basis/mode/format; HTML, CSV, or XLSX | Marketplace workspace; store/company scope; no CSRF for GET | `M-STORE`, `M-ORDER`, `M-FIN`, `M-CATALOG` | `READ` | [marketplace_analytics_smoke.php](../../tests/Integration/marketplace_analytics_smoke.php) | Nominal/HPP/date-basis parity and export PII require fixture comparison | Not yet migrated as full Laravel page |
| `/api/marketplace-auto-sync.php` GET/POST | Auto-sync controls; `store_id`, `mode`, `enabled`; JSON state | Marketplace workspace; role/service guard; POST CSRF; store scope in service | `M-STORE`, `marketplace_auto_sync`, `marketplace_auto_sync_runtime` | `WRITE` settings on POST; `READ` GET | [marketplace_browser_autosync_scope.php](../../tests/Integration/marketplace_browser_autosync_scope.php) | Browser-tab lifetime is not a reliable production scheduler; duplicate scheduler risk | Laravel scheduler/queue pilot, browser fallback documented |
| `/api/marketplace-browser-pulse.php` POST | Browser fallback pulse; CSRF header; JSON status | Authenticated session; Marketplace workspace; CSRF | `M-SYNC`, `M-STORE` | `WRITE`/dispatch bounded sync | [MarketplaceQueueTest.php](../../laravel-foundation/tests/Feature/MarketplaceQueueTest.php) for manual/browser contract | Must remain bounded and never become a long web worker | [MarketplaceSyncController.php](../../laravel-foundation/app/Http/Controllers/MarketplaceSyncController.php) |
| `/api/marketplace-sync.php` GET/POST | Sync UI/worker bridge; `action` = `preview`, `start`, `step`, `background`, `catalog`, `catalog_status`, `status`; store | Marketplace workspace; POST mutating actions require CSRF; store/company scope | `M-STORE`, `M-SYNC`, `M-ORDER`, `M-CATALOG` | `READ` status/preview; `WRITE` queue/checkpoint/provider sync | [marketplace_sync_smoke.php](../../tests/Integration/marketplace_sync_smoke.php), [MarketplaceQueueTest.php](../../laravel-foundation/tests/Feature/MarketplaceQueueTest.php) | Commit-then-dispatch loss window and offset checkpoint risk remain | [MarketplaceSyncController.php](../../laravel-foundation/app/Http/Controllers/MarketplaceSyncController.php) |
| `/api/marketplace-integration-status.php` GET/POST | Integration health and retry; `store_id`, POST `action=retry`; JSON | Marketplace workspace; POST CSRF; store scope/service | `M-STORE`, `M-SYNC`, `M-CATALOG`, `M-ORDER` | `READ` health; `WRITE` retry/worker | [marketplace_integration_runtime_smoke.php](../../tests/Integration/marketplace_integration_runtime_smoke.php) | Retry can start platform work; provider timeout/rate limits need sandbox evidence | No full Laravel compatibility route yet |
| `/api/marketplace-oauth.php` GET/POST | Settings/frontend/provider callback; actions `status`, `start`, `callback`, `pending`, `select`, `sync`, `refresh`, `disconnect`; JSON/303 redirect | Marketplace workspace; callback session/state; POST CSRF; company/store scope | `M-STORE`, `M-SYNC`, `AUTH` | `WRITE` OAuth state/connection/queue/provider; callback redirect | [marketplace_oauth_smoke.php](../../tests/Integration/marketplace_oauth_smoke.php), [OAuthSandboxTest.php](../../laravel-foundation/tests/Feature/OAuthSandboxTest.php) | Live callback registration and provider permissions remain unverified | [MarketplaceOAuthController.php](../../laravel-foundation/app/Http/Controllers/MarketplaceOAuthController.php) |
| `/api/marketplace-partner-settings.php` POST | Admin integration form; platform credentials/mode; JSON test result | Marketplace workspace; Owner/Admin; CSRF | `M-STORE`, `marketplace_partner_settings`, encrypted credential storage | `WRITE` encrypted settings and provider test | [shopee_integration_smoke.php](../../tests/Integration/shopee_integration_smoke.php) | Secret handling and live provider use require sandbox/redaction; no plaintext output | Sandbox OAuth/config adapter only |
| `/api/marketplace-order-finance.php` POST | Order detail refresh; `store_id`, `order_id`; JSON presentation | Marketplace workspace; store scope; CSRF | `M-ORDER`, `M-SYNC`, `M-STORE` | `WRITE` provider refresh/order snapshot; then `READ` presentation | [marketplace_transaction_detail_smoke.php](../../tests/Integration/marketplace_transaction_detail_smoke.php) | External provider mutation/read latency and nominal reconciliation | Not yet migrated as full Laravel route |
| `/api/marketplace-fulfillment.php` POST | Order JS; `action=options|confirm`, store/order/package; JSON options/result | Marketplace workspace; store/service scope; CSRF; confirmation required for confirm | `M-ORDER`, `M-STORE` | `READ` options; `WRITE` provider fulfillment and audit | [marketplace_fulfillment_smoke.php](../../tests/Integration/marketplace_fulfillment_smoke.php) | Provider side effect must be idempotent and retry-safe | Not yet migrated as full Laravel route |
| `/api/marketplace-shipping-document.php` GET/POST | Order JS; POST store/order to create/list; GET document ID/preview; PDF/JSON | Marketplace workspace; store/user scope; POST CSRF; private document check on GET | `M-ORDER`, `K-DOC`, `M-STORE` | `WRITE` encrypted private PDF/audit; `READ` preview/download | [marketplace_shipping_documents_smoke.php](../../tests/Integration/marketplace_shipping_documents_smoke.php) | Private storage, expiry, PDF integrity, and PII download controls | Not yet migrated as full Laravel route |
| `/api/marketplace-stock.php` POST | Stock JS; store/product/variant/warehouse/minimum; JSON | Marketplace workspace; Owner/Admin; store scope; CSRF | `M-CATALOG`, `M-STORE` | `WRITE` minimum and audit; does not write marketplace stock | [marketplace_stock_smoke.php](../../tests/Integration/marketplace_stock_smoke.php) | Role/scope and numeric precision must remain server-side | Not yet migrated as full Laravel route |
| `/api/marketplace-price.php` POST | Price JS JSON; `action=prepare|save`, store/product/payload/operation key; JSON | Marketplace workspace; store scope/service; CSRF | `M-CATALOG`, `M-STORE`, `M-ORDER` | `READ` prepare; `WRITE` marketplace price/promotion and local action record | [marketplace_price_smoke.php](../../tests/Integration/marketplace_price_smoke.php) | Provider write uncertainty needs operation key and reconciliation before retry | Not yet migrated as full Laravel route |
| `/api/marketplace-branding.php` GET/POST | Branding JS; GET `image=1`, POST logo/form fields; image or JSON | Marketplace workspace; Owner/Admin on write; CSRF POST; company scope | `marketplace_branding`, `AUTH` | `READ` image; `WRITE` logo metadata/blob/audit | [marketplace_branding_smoke.php](../../tests/Integration/marketplace_branding_smoke.php) | Blob size/type, private company scope, and response headers | Not yet migrated as full Laravel route |
| `/api/marketplace-store-deletion.php` POST | Destructive store flow; `action=list|preview|status|request|resume`, store/confirmation; JSON | Marketplace workspace; CSRF; service role/company/store checks | `M-STORE`, `M-ORDER`, `M-SYNC`, private store files, deletion status | `WRITE` staged deletion and worker | [marketplace_store_deletion_test.php](../../tests/Integration/marketplace_store_deletion_test.php) | Destructive action, background worker and rollback/restore must be separately approved | No Laravel cutover target |
| `/api/marketplace-withdrawals.php` GET/POST | Admin import; GET template, POST `preview|confirm`, store/file/key; JSON/CSV | Marketplace workspace; Owner/Admin; store/company; CSRF POST; session staging expiry | `M-FIN`, `M-STORE` | `READ` template/preview; `WRITE` final withdrawal and audit on confirm | [marketplace_withdrawal_smoke.php](../../tests/Integration/marketplace_withdrawal_smoke.php), [marketplace_withdrawal_xlsx_smoke.php](../../tests/Integration/marketplace_withdrawal_xlsx_smoke.php), [MarketplaceWithdrawalPreviewTest.php](../../laravel-foundation/tests/Feature/MarketplaceWithdrawalPreviewTest.php) | Final confirm remains legacy; financial import needs duplicate prevention, private upload limits, and PII redaction | Preview migrated to [`MarketplaceWithdrawalPreviewController`](../../laravel-foundation/app/Http/Controllers/MarketplaceWithdrawalPreviewController.php); legacy confirm remains rollback |
| `/api/marketplace-webhook.php` any | Provider webhook placeholder; no supported input; JSON 503 | No active provider contract; must remain disabled | `M-V3` not used by stub | `STUB` no write | Source returns 503; no live provider test | Must not be registered/advertised as active until signature, replay, and scope checks exist | Not active in Laravel |

## 4. Konveksi RPC method matrix

All rows below are dispatched by `/api/rpc.php`. `prosesLoginBackend` is the
only method that is intentionally reachable without an existing session. Other
methods require a logged-in user, Konveksi workspace, company assertion, and
CSRF. Most writes additionally require an Owner/Admin role and pass through
`KonveksiOperationGate` for request receipts.

| RPC method | Caller / args and response | Auth, scope, CSRF | Tables | Effect | Test / risk | Laravel target |
|---|---|---|---|---|---|---|
| `prosesLoginBackend` | Login form; username/password; auth result/session metadata | No prior session; password hash adapter | `AUTH` | `WRITE` session and login timestamp where available | [workspace_policy.php](../../tests/Integration/workspace_policy.php); hash source parity required | [AuthController.php](../../laravel-foundation/app/Http/Controllers/AuthController.php) |
| `loadSemuaData` | Bootstrap Konveksi; no args; all known collection data | Auth + Konveksi + company; CSRF | `AUTH`, `K-MAP` | `READ` | Static source; large payload and dynamic table names | [KonveksiPilotController.php](../../laravel-foundation/app/Http/Controllers/KonveksiPilotController.php) |
| `syncTab` | Legacy client; tab/payload rows; result with count/table | Auth + Konveksi + company; CSRF; admin path | `K-MAP`, `AUTH` | `WRITE` replacement/import + audit | No same-fixture Laravel parity; import can overwrite dynamic collection | Legacy adapter; no full Laravel target |
| `syncMultiTabs` | Legacy client; map of tab to rows; result per tab | Auth + Konveksi + company; CSRF; admin path | `K-MAP`, `AUTH` | `WRITE` transaction/import + audit | Same overwrite and dynamic schema risk as `syncTab` | Legacy adapter |
| `jadwalkanSnapshotRinganJagoLaba` | Backup control; no args; schedule result | Auth + Konveksi + company; CSRF; admin special path | `AUTH`, `K-BACKUP` | `WRITE` pending snapshot marker | Backup restore not tested | Keep legacy until backup ADR/rehearsal |
| `backupSekarangJagoLaba` | Backup control; no args; result | Auth + Konveksi + company; CSRF; admin special path | `K-MAP`, `K-BACKUP`, `AUTH` | `WRITE` SQL/JSON backup and log | Does not prove restore; secret/path risk | No pilot target |
| `buatBackupManualSekarang` | Same backup UI; no args | Same as above | `K-MAP`, `K-BACKUP`, `AUTH` | `WRITE` backup and log | Same restore gap | No pilot target |
| `getRiwayatBackupJagoLaba` | Backup history; no args; list | Auth + Konveksi + company; CSRF | `K-BACKUP`, `AUTH` | `READ` | Static only | No pilot target |
| `saveDocumentFileServer` | Upload/base64 document; content/name/type/reference/flags; file result | Auth + Konveksi + company; CSRF; special admin path | `K-DOC`, `AUTH` | `WRITE` private file, metadata, audit if service path | Upload limits/private storage need test | No pilot target |
| `saveMaterialMasterServerRpc` | Material form; object payload; result | Auth + Konveksi + company; CSRF; admin/gate | `K-MAP`, normalized `materials`, `material_aliases`, `AUTH`, `K-IDEMP` as provided | `WRITE` material master | No same-fixture parity; nominal and duplicate rules need evidence | Future Konveksi action |
| `savePurchaseInvoiceServerRpc` | Purchase invoice form; object payload; result | Auth + Konveksi + company; CSRF; admin/gate | `K-MAP`, `purchase_invoices`, `purchase_items`, `inventory_stocks`, `K-DOC`, `AUTH` | `WRITE` purchase/inventory/document | Transaction and rollback require dedicated test | Future Konveksi action |
| `retryUploadPurchaseDocumentServerRpc` | Retry document upload; payload; result | Auth + Konveksi + company; CSRF; special admin path | `K-DOC`, `purchase_invoices`, `AUTH` | `WRITE` private file/metadata | Provider/file retry idempotency not proven | Future Konveksi action |
| `extractPurchaseInvoiceServerRpc` | Invoice extraction; document payload; extraction result | Auth + Konveksi + company; CSRF; special admin path | `K-DOC`, `system_settings` for AI key | `READ` provider/AI call, no intended DB mutation | Live AI/provider/secret handling not in this inventory test | Future provider adapter |
| `getGeminiApiKeyStatusServerRpc` | Settings UI; no args; configured/ready status | Auth + Konveksi + company; CSRF | `system_settings` | `READ` | Must not expose key; static source supports redaction | Future settings route |
| `saveGeminiApiKeyServerRpc` | Owner/Admin settings; key string; status | Auth + Konveksi + company; CSRF; admin/gate | `system_settings`, `AUTH` | `WRITE` secret setting and audit policy | Secret rotation/log redaction not rehearsed | Future settings route |
| `syncBahanHppFromPurchasesServerRpc` | HPP action; no args; result | Auth + Konveksi + company; CSRF; admin/gate | `K-MAP`, `materials`, `purchase_items`, HPP tables, `AUTH` | `WRITE` HPP history | Nominal/HPP parity required | Future HPP action |
| `activateHppProductionServerRpc` | HPP activation; payload; result | Auth + Konveksi + company; CSRF; admin/gate | HPP tables, `K-MAP`, `AUTH`, `K-IDEMP` if supplied | `WRITE` effective HPP | Effective-date and rollback contract not tested | Future HPP action |
| `saveDraftPenjualanProduksiServer` | Production sales draft; payload; result | Auth + Konveksi + company; CSRF; admin/gate | `K-MAP`, `sales_orders`, `sales_items`, `AUTH`, `K-IDEMP` | `WRITE` draft | Duplicate/refresh behavior needs feature test | Future sales action |
| `postPenjualanProduksiServer` | Post production sale; payload; result | Auth + Konveksi + company; CSRF; admin/gate | `sales_orders`, `sales_items`, `stock_mutations`, `inventory_stocks`, cash tables, `AUTH`, `K-IDEMP` | `WRITE` financial/inventory posting | High nominal risk; no migration pilot | Future sales action |
| `correctPenjualanProduksiServer` | Correct posted sale; payload; result | Auth + Konveksi + company; CSRF; admin/gate | Sales, inventory, cash, audit, `K-IDEMP` | `WRITE` correction | Must preserve audit and accounting invariant | Future sales action |
| `voidPenjualanProduksiServer` | Void sale; payload; result | Auth + Konveksi + company; CSRF; admin/gate | Sales, inventory, cash, audit, `K-IDEMP` | `WRITE` reversal/void | Rollback and nominal parity not tested | Future sales action |
| `retryBackupPenjualanProduksiServer` | Retry backup of sale; payload; result | Auth + Konveksi + company; CSRF; admin/gate | Sales, `K-BACKUP`, audit | `WRITE` backup retry | Restore not tested | Future backup action |
| `createHutangKonveksiManual` | Debt form; payload; result | Auth + Konveksi + company; CSRF; admin/gate | `sewing_debts`, `K-MAP`, audit, `K-IDEMP` | `WRITE` debt | Financial mutation requires transaction/idempotency proof | Future debt action |
| `bayarHutangKonveksi` | Debt payment; payload; result | Auth + Konveksi + company; CSRF; admin/gate | `sewing_debts`, `sewing_debt_payments`, cash, audit, `K-IDEMP` | `WRITE` payment | Nominal and duplicate payment risk | Future debt action |
| `auditInventoryConsistencyServer` | Inventory audit button; no args; report | Auth + Konveksi + company; CSRF; admin/gate | `inventory_stocks`, `stock_mutations`, `K-MAP`, `AUTH` | `READ` audit report | Static only; no live permission/data test | Future diagnostic route |
| `migrateInventoryIdStokItemServer` | Inventory ID migration; no args; result | Auth + Konveksi + company; CSRF; admin/gate | Inventory, stock mutations, mapping tables, audit | `WRITE` data repair/migration | Explicitly high risk; prohibited without separate task/checkpoint | Do not expose in Laravel pilot |
| `saveDocumentTemplate` | Document template editor; payload/actor; result | Auth + Konveksi + company; CSRF; admin/gate | `K-MAP` `template_dokumen`, `AUTH` | `WRITE` template | Dynamic table and actor audit need parity | Future document settings |

## 5. Marketplace AJAX action matrix

All actions below share the legacy boundary checks in
[marketplace-ajax.php](../../app/Modules/Marketplace/Controllers/Api/marketplace-ajax.php):
Marketplace workspace and CSRF. The listed store-scoped actions additionally
require a non-empty `store_id` and server-side store access. Action-specific
role checks are shown where visible in the controller/service.

| Action | Method/input and response | Tables | Effect / external effect | Existing test / risk | Proposed Laravel target |
|---|---|---|---|---|---|
| `save_hpp_batch` | POST; JSON `changes`; JSON count/message | `M-CATALOG`, `M-ORDER`, `AUTH` | `WRITE` HPP batch and recalculation | [marketplace_catalog_smoke.php](../../tests/Integration/marketplace_catalog_smoke.php); payload must carry unambiguous store scope | Form Request + HPP Action |
| `update_global_product_hpp` | POST; `store_id`, `product_id` or `sku`, `hpp`, effective date, recalculate flag; JSON | `M-CATALOG`, `M-ORDER`, `M-STORE`, `AUTH` | `WRITE` HPP/history/order values | [marketplace_catalog_smoke.php](../../tests/Integration/marketplace_catalog_smoke.php); effective date/tenant collision risk | Form Request + scoped HPP Action |
| `import_product_ad_report` | POST multipart; `store_id`, `report` max 2 MB; JSON count | `M-FIN`, `M-STORE`, `AUTH` | `WRITE` ads report; intended dedup by period | [marketplace_product_ads_smoke.php](../../tests/Integration/marketplace_product_ads_smoke.php); file/duplicate/PII risk | Import Request + bounded Action |
| `get_order_detail` | GET/POST action; `order_id`, `store_id`; JSON presentation/return cases | `M-ORDER`, `M-STORE`, `M-CATALOG` | `READ` | [marketplace_transaction_detail_smoke.php](../../tests/Integration/marketplace_transaction_detail_smoke.php); order ID and store scope must be coupled | Read controller/service |
| `update_manual_order` | POST; order/store/buyer/status/COGS; JSON | `M-ORDER`, `M-CATALOG`, `AUTH` | `WRITE` manual order | [marketplace_orders_smoke.php](../../tests/Integration/marketplace_orders_smoke.php); nominal/status mutation | Form Request + Policy |
| `archive_order` | POST; order/store/reason; JSON | `M-ORDER`, `AUTH` | `WRITE` archive state/audit | [marketplace_order_status_smoke.php](../../tests/Integration/marketplace_order_status_smoke.php); archive must not remove source history | Scoped archive Action |
| `add_expense` | POST; store/description/amount/category/vendor/date/payment/account/flags; JSON ID | `M-FIN`, `M-STORE`, `AUTH` | `WRITE` expense/cash/audit | Laravel pilot [MarketplaceExpenseWriteService.php](../../laravel-foundation/app/Pilots/Marketplace/MarketplaceExpenseWriteService.php); idempotency scoped by company/module/key and tested cross-scope | Laravel expense pilot adapter |
| `delete_expense` | POST; expense ID/store; JSON | `M-FIN`, `M-STORE`, `AUTH` | `WRITE` delete/void expense | [marketplace_global_expenses_smoke.php](../../tests/Integration/marketplace_global_expenses_smoke.php); destructive and audit parity | Scoped mutation Action |
| `add_order` | POST; manual order, buyer, nominal fees, COGS, SKU, quantity; JSON ID | `M-ORDER`, `M-CATALOG`, Konveksi inventory tables, `AUTH` | `WRITE` order and inventory | [marketplace_orders_smoke.php](../../tests/Integration/marketplace_orders_smoke.php); cross-module transaction risk | Form Request + transaction Action |
| `update_sku_hpp` | POST; store/SKU/HPP/variant; JSON | `M-CATALOG`, `M-ORDER`, `AUTH` | `WRITE` HPP and affected orders | [marketplace_catalog_smoke.php](../../tests/Integration/marketplace_catalog_smoke.php); effective date/nominal parity | Scoped HPP Action |
| `test_api_connection` | GET/POST; store; JSON connection test | `M-STORE`, credential tables | `READ` provider test, possible rate-limit effect | [marketplace_integration_runtime_smoke.php](../../tests/Integration/marketplace_integration_runtime_smoke.php); must be sandboxed in tests | Provider boundary Action |
| `save_api_credentials` | POST; platform/credential fields; currently throws before save | `marketplace_api_credentials` if unreachable code were enabled | `STUB` no write by current code | Explicitly rejects direct credential input; keep disabled until OAuth/encryption contract | Remove from generic AJAX; use OAuth settings route |
| `sync_store` | GET/POST; store; JSON sync result | `M-SYNC`, `M-STORE`, `M-ORDER`, `M-CATALOG` | `WRITE` provider sync/data | [marketplace_sync_smoke.php](../../tests/Integration/marketplace_sync_smoke.php); bounded job and provider retry required | Laravel Job/Command |
| `sync_marketing_ads` | GET/POST; store; JSON | `M-FIN`, `M-STORE`, `M-SYNC` | `WRITE` provider ads data | [marketplace_product_ads_smoke.php](../../tests/Integration/marketplace_product_ads_smoke.php); provider permission/PII risk | Provider Job |
| `import_settlement_csv` | POST multipart; store/file/name; JSON | `M-FIN`, `M-STORE`, `AUTH` | `WRITE` settlement/import rows | [marketplace_finance_smoke.php](../../tests/Integration/marketplace_finance_smoke.php); duplicate/nominal/date risk | Import Request + transaction |
| `simulate_price` | POST; calculator payload; JSON result | `M-FIN`, `M-CATALOG`, `M-STORE` where service resolves scope | `READ` calculation; may save only through separate action | [marketplace_price_smoke.php](../../tests/Integration/marketplace_price_smoke.php); scope must not derive solely from payload | Read Action |
| `get_saved_simulations` | GET/POST; current session/company context; JSON list | `marketplace_price_simulations`, `M-STORE`, `AUTH` | `READ` | [marketplace_price_smoke.php](../../tests/Integration/marketplace_price_smoke.php); PII and company filtering | Read controller |
| `update_stock_allocation` | POST; currently throws migration-pending error | `marketplace_stock_allocations` if enabled | `STUB` no write | Explicitly unavailable; do not advertise UI as active | Keep stub until inventory migration approved |
| `run_safety_buffer_sync` | POST; currently throws migration-pending error | `marketplace_stock_allocations`, `M-SYNC` if enabled | `STUB` no write/provider call | Explicitly unavailable; no active worker contract | Keep stub |
| `update_return_claim` | POST; claim/store/status/reimbursement/notes; JSON | `marketplace_return_claims`, `M-ORDER`, `M-STORE`, `AUTH` | `WRITE` return claim/audit | [marketplace_order_status_smoke.php](../../tests/Integration/marketplace_order_status_smoke.php); reimbursement nominal and role risk | Form Request + transaction Action |
| `trigger_cron_worker` | POST; task; currently throws worker-not-active error before launch | `M-SYNC` if enabled | `STUB` no worker call by current code | Legacy path mentions Windows/PowerShell; incompatible with shared-hosting target | Replace only with Laravel bounded Job/cron |
| `get_journal_entries` | GET; store/date/ref type; JSON | `M-FIN`, `M-STORE` | `READ` | [marketplace_finance_smoke.php](../../tests/Integration/marketplace_finance_smoke.php); double-entry scope/PII | Read controller |
| `add_store` | POST; store/platform/config fields; JSON | `M-STORE`, OAuth/settings, `AUTH` | `WRITE` store/config and possibly initial connection | [marketplace_store_selection_smoke.php](../../tests/Integration/marketplace_store_selection_smoke.php), OAuth tests; must not accept foreign company | OAuth start/use-case |
| `update_store` | POST; store and editable details; JSON | `M-STORE`, `AUTH` | `WRITE` store metadata | [marketplace_store_selection_smoke.php](../../tests/Integration/marketplace_store_selection_smoke.php); company scope | Store settings Action |
| `toggle_store_status` | POST; store/status; JSON | `M-STORE`, `AUTH` | `WRITE` status | [marketplace_store_archive_smoke.php](../../tests/Integration/marketplace_store_archive_smoke.php); status transition contract | Store status Action |
| `archive_store` | POST; store; JSON | `M-STORE`, `AUTH` | `WRITE` archive state | [marketplace_store_archive_smoke.php](../../tests/Integration/marketplace_store_archive_smoke.php); must preserve historical data | Store archive Action |
| `restore_store` | POST; store; JSON | `M-STORE`, `AUTH` | `WRITE` restore state | [marketplace_store_archive_smoke.php](../../tests/Integration/marketplace_store_archive_smoke.php); caller currently uses this legacy adapter | Store restore Action |
| `get_store_detail` | GET/POST; store; JSON | `M-STORE`, `AUTH` | `READ` | [marketplace_store_selection_smoke.php](../../tests/Integration/marketplace_store_selection_smoke.php); foreign company test required | Read controller |
| `save_app_settings` | POST; app settings payload; JSON | Marketplace workspace/CSRF; service role/company | `marketplace_app_settings`, `M-STORE`, `AUTH` | `WRITE` settings | [marketplace_integration_runtime_smoke.php](../../tests/Integration/marketplace_integration_runtime_smoke.php); secret/config redaction | Settings Action |

## 6. Frontend form and fetch inventory

The project contains duplicate asset trees under `public/assets/js/` and
`public/assets/marketplace/js/`. Marketplace pages currently reference the
second tree in several views, so both paths must be included in parity checks.

| Frontend caller | HTTP target and operations | Input/output contract | Scope and risk | Proposed Laravel target |
|---|---|---|---|---|
| `public/assets/js/jl-api.js` and `public/assets/konveksi/js/jl-api.js` | `/api/rpc.php`; sends arbitrary RPC `method` and positional `args` | JSON RPC envelope, request ID, legacy error shape | RPC method/argument order is a hard compatibility contract | `LegacyRpcController` plus per-method Actions |
| `order-transaction-detail.js` (both asset trees) | `marketplace-ajax.php?action=get_order_detail`; TikTok refresh calls `marketplace-order-finance.php` POST | Order ID/store ID; presentation props include items, fees, HPP, returns, finance status | Must not disclose another store order; finance refresh can call provider | Laravel read pilot plus provider adapter |
| `marketplace-orders.js` (both asset trees) | `marketplace-orders.php` GET; `marketplace-fulfillment.php` POST `options|confirm`; `marketplace-shipping-document.php` POST then GET PDF | Filters, order/package IDs; HTML/JSON/PDF | Order PII and external fulfillment side effects | Separate Laravel read/fulfillment/document slices |
| `marketplace-dashboard.js` | `marketplace-dashboard.php` GET | Current query/date/store; HTML replacement | HTML fragment ID/props and financial totals are contract | Laravel read pilot |
| `marketplace-sync.js` | `marketplace-sync.php` POST `status` and starts through background helper | Store selector; JSON job rows/returns | Polling must not become long request; duplicate start prevention | Laravel sync controller/job |
| `marketplace-background-sync.js` | `marketplace-sync.php` POST `preview`, `background`, `status` | Selected stores and progress banner | Multi-store scope, checkpoint, overlap, worker availability | Laravel database queue pilot |
| `marketplace-catalog-sync.js` | `marketplace-sync.php` POST catalog action | Store; JSON catalog progress | Provider permissions and bounded catalog work | Laravel Job |
| `marketplace-integration-health.js` and `marketplace-sync.js` | `marketplace-integration-status.php` GET/POST retry and `marketplace-sync.php` status | Store, retry; JSON health | Retry may cause provider calls; rate limit and request ID | Laravel integration health |
| `marketplace-auto-sync.js` and `marketplace-auto-refresh.js` | `marketplace-auto-sync.php` POST setting, GET state | Store/mode/enabled; JSON states | Browser visibility/lifetime is not cron; avoid two schedulers | Scheduler plus browser fallback |
| `marketplace-browser-pulse.js` | `marketplace-browser-pulse.php` POST | CSRF only; JSON status | Must be one bounded step, not daemon emulation | Laravel manual/browser fallback |
| `marketplace-branding.js` and `marketplace-brand.php` | `marketplace-branding.php` POST save, GET image | Multipart logo and metadata; JSON/image | Company scope, MIME/size, private branding | Laravel settings slice |
| `marketplace-hpp-editor.js` | `marketplace-ajax.php` POST `save_hpp_batch` | JSON encoded changes; JSON result then reload | HPP/nominal and role scope | Laravel HPP Action |
| `marketplace-price.js` | `marketplace-price.php` POST `prepare|save` JSON | Store/product/payload/operation key; provider result | Unknown provider write outcome needs reconciliation | Laravel provider Action |
| `marketplace-settings-integration.js` | `marketplace-ajax.php` POST `restore_store`; `marketplace-partner-settings.php` POST; `marketplace-oauth.php` GET/POST OAuth actions; `logout.php` POST | Platform settings, OAuth state/pending, store ID, CSRF | Secret redaction, callback origin/state/replay, company scope | Laravel OAuth/settings adapters |
| `marketplace-stock.js` | `marketplace-stock.php` POST | Store/product/variant/warehouse/minimum; JSON | Admin only, precision and store scope | Laravel stock-minimum Action |
| `marketplace-withdrawals.js` | `marketplace-withdrawals.php` POST `preview|confirm` | Multipart file then session key; JSON | Upload PII, expiry, duplicate final import | Laravel preview route; confirm remains legacy |
| `marketplace-store-deletion.js` | `marketplace-store-deletion.php` POST `list|preview|status|request|resume` | Store/action/confirmation; JSON progress | Destructive, worker, recovery and rollback | No cutover target |
| Marketplace PHP forms and links | `marketplace.php`, `api/marketplace-orders.php`, `api/marketplace-analytics.php`, `api/marketplace-dashboard.php`, withdrawals template | GET query: store, tab, filters, period/date, export format | Query/filter/redirect/HTML IDs must remain stable | Legacy facade until contract tests pass |
| Konveksi HTML controls | `index.php`, `workspace.php`, `api/rpc.php`, `api/logout.php`, `api/konveksi-document.php` | POST RPC/form args, CSRF, document ID | Dynamic collection and legacy session contract | Laravel adapter per RPC slice |

## 7. OAuth, worker, and cron inventory

| Surface | Current source | Trigger/input | Data/provider effect | Current test/evidence | Target and risk |
|---|---|---|---|---|---|
| Legacy OAuth callback | [marketplace-oauth.php](../../app/Modules/Marketplace/Controllers/Api/marketplace-oauth.php) | Browser GET callback with state/code/error; POST settings actions | OAuth state, pending shops, encrypted connection, initial sync queue, provider calls | [marketplace_oauth_smoke.php](../../tests/Integration/marketplace_oauth_smoke.php) | Keep callback URL until Laravel/sandbox contract is registered; no Live call in this phase |
| Laravel OAuth sandbox | [MarketplaceOAuthController.php](../../laravel-foundation/app/Http/Controllers/MarketplaceOAuthController.php), [SandboxMarketplaceOAuthProvider.php](../../laravel-foundation/app/Marketplace/OAuth/SandboxMarketplaceOAuthProvider.php) | status/start/callback/pending/select/refresh | Sandbox only; tokens encrypted; no provider Live | [OAuthSandboxTest.php](../../laravel-foundation/tests/Feature/OAuthSandboxTest.php) | Pilot evidence only; production registration/permissions pending |
| Legacy cron CLI | [marketplace-cron.php](../../bin/workers/marketplace-cron.php) | CLI `--task`; Windows/PowerShell assumptions in legacy flow | Provider sync and writes | [marketplace_sync_smoke.php](../../tests/Integration/marketplace_sync_smoke.php) | Not shared-hosting compatible; do not expose from generic AJAX |
| Legacy background worker | [marketplace-background-worker.php](../../bin/workers/marketplace-background-worker.php) | CLI/background launch | Long-running/bounded sync depending on mode | Static only | Replace by Laravel database queue; no daemon in target |
| Legacy auto-sync worker | [marketplace-auto-sync.php](../../bin/workers/marketplace-auto-sync.php) | CLI store/task | Sync and state | Static only | Browser/cron overlap risk |
| Legacy deletion worker | [marketplace-store-deletion.php](../../bin/workers/marketplace-store-deletion.php) | CLI store ID | Destructive store cleanup | [marketplace_store_deletion_test.php](../../tests/Integration/marketplace_store_deletion_test.php) | Keep isolated until deletion/restore review |
| Laravel queue job | [MarketplaceSyncJob.php](../../laravel-foundation/app/Pilots/Marketplace/MarketplaceSyncJob.php) | Database `jobs` row; company/store/run key | Bounded checkpoint sync | [MarketplaceQueueTest.php](../../laravel-foundation/tests/Feature/MarketplaceQueueTest.php) | Orphaned domain-row-after-dispatch risk remains |
| Laravel scheduler | [console.php](../../laravel-foundation/routes/console.php) | `schedule:run`, every five minutes, `withoutOverlapping` | Dispatches bounded sync | [MarketplaceQueueTest.php](../../laravel-foundation/tests/Feature/MarketplaceQueueTest.php) | Host cron and single-scheduler proof pending |

## 8. Existing test and evidence map

| Concern | Legacy evidence | Laravel evidence | Status |
|---|---|---|---|
| Auth/session/workspace | [workspace_policy.php](../../tests/Integration/workspace_policy.php) | [AuthScopeTest.php](../../laravel-foundation/tests/Feature/AuthScopeTest.php) | Synthetic auth/scope coverage passes; live multi-user permission matrix not measured |
| Marketplace read and orders | [marketplace_dashboard_smoke.php](../../tests/Integration/marketplace_dashboard_smoke.php), [marketplace_orders_smoke.php](../../tests/Integration/marketplace_orders_smoke.php) | [PilotReadOnlyTest.php](../../laravel-foundation/tests/Feature/PilotReadOnlyTest.php) | Same-fixture legacy-vs-Laravel dashboard parity passes; dashboard smoke also passes against a temporary database, not production |
| OAuth | [marketplace_oauth_smoke.php](../../tests/Integration/marketplace_oauth_smoke.php), [marketplace_oauth_origin_smoke.php](../../tests/Integration/marketplace_oauth_origin_smoke.php) | [OAuthSandboxTest.php](../../laravel-foundation/tests/Feature/OAuthSandboxTest.php) | Sandbox coverage passes; provider Live/callback registration not tested |
| Queue/sync | [marketplace_sync_smoke.php](../../tests/Integration/marketplace_sync_smoke.php) | [MarketplaceQueueTest.php](../../laravel-foundation/tests/Feature/MarketplaceQueueTest.php), [MarketplaceQueueAtomicityTest.php](../../laravel-foundation/tests/Feature/MarketplaceQueueAtomicityTest.php) | Isolated MariaDB staging dispatch/retry/restart/scheduler evidence passes; production cron/permission and provider Live remain open |
| Financial/HPP | [marketplace_finance_smoke.php](../../tests/Integration/marketplace_finance_smoke.php), [marketplace_price_smoke.php](../../tests/Integration/marketplace_price_smoke.php) | [PilotReadOnlyTest.php](../../laravel-foundation/tests/Feature/PilotReadOnlyTest.php) | Pilot dashboard fixture reconciliation passes; broader finance/HPP and order-detail runtime parity remains open |
| Documents/private storage | [marketplace_shipping_documents_smoke.php](../../tests/Integration/marketplace_shipping_documents_smoke.php), [konveksi_integrity_test.php](../../tests/Integration/konveksi_integrity_test.php) | No complete Laravel document pilot | Storage permissions and restore require staging rehearsal |
| Build/release | [shared-hosting-release.md](../../laravel-foundation/docs/shared-hosting-release.md) and upload manifest | Build artifact checks | Release artifact exists; production host setup/cutover not performed |

## 9. Static findings and migration order

1. The legacy boundary is broad: one RPC endpoint and one Marketplace AJAX
   endpoint dispatch many business operations. They must be split by vertical
   slice, not replaced by one generic Laravel controller.
2. Several legacy endpoints are intentionally unavailable or provider-pending:
   `save_api_credentials`, `update_stock_allocation`,
   `run_safety_buffer_sync`, `trigger_cron_worker`, and `marketplace-webhook`.
   They must not be reported as migrated functionality.
3. The active Marketplace frontend has duplicate JavaScript trees. The caller
   path must be part of each contract test so the wrong copy is not silently
   validated.
4. The largest cross-cutting contracts are session/workspace/company/store
   scope, CSRF, HPP effective dates, nominal precision, OAuth callback/state,
   private files, and queue idempotency.
5. Static analysis cannot prove live database permissions, table existence,
   restore, provider rate limits, cron overlap, or response time. Those remain
   explicit staging tasks and are not silently marked PASS.

Recommended execution order after this inventory:

1. Choose one read-only Marketplace dashboard/order slice and run the legacy
   and Laravel versions against the same isolated fixture.
2. Choose one local expense mutation and settle idempotency namespace and
   unique-constraint ownership before accepting more writes.
3. Rehearse one queue process with commit/dispatch failure, timeout restart,
   and cron overlap on an isolated database.
4. Review the resulting diff/branch with the Prompt 10 checklist before any
   merge or cutover.

## 10. Explicit limits of this inventory

- No live database permission inspection was performed.
- No production or shared-hosting worker was started.
- No provider Live request was sent.
- No migration, seed, repair, reset, deletion, or restore was executed.
- The read-only parity run does not create MySQL state; the Marketplace
  dashboard regression was separately run against a random temporary database
  and cleaned up in `finally`.
- Full-system query-count and UI screenshot parity still require a broader
  controlled fixture and staging measurement; the Marketplace/Konveksi pilot
  now has same-fixture response-time and Inertia-props evidence.
