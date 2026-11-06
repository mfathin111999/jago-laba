> **Catatan arsip (6 Nov 2026):** dokumen ini ditulis saat aplikasi masih PHP murni. Jalur file, skrip, dan kelas yang disebut di bawah (`app/Modules`, `public/*.php`, `bin/workers`, dll.) sudah dihapus dan digantikan Laravel; lihat [penghapusan kode lama](architecture/legacy-removal.md) dan [arsitektur aktif](architecture/../architecture.md). Perilaku bisnis yang dijelaskan tetap berlaku.

# Inventori & Stok per toko

Route: marketplace.php?store=<id>&tab=produk&product_section=stok. Legacy tab=inventori redirects (303) to this route. Shared product tabs retain HPP and price workflows.

Source: active API catalog variants, latest product inventory payload and exact external variant IDs. Warehouse quantities remain separate; duplicate warehouse IDs or missing facts make aggregate unknown. Never derive current stock from opening stock minus sales plus returns. Read-only source integration; no marketplace stock writes or Konveksi stock mutations.

Terjual: unit quantities in saved API orders within order-date period for paid fulfillment states. Cancelled/unpaid/on-hold states excluded. SKU text is not a join key. Where a variant ID occurs under multiple active products, sales remains unavailable rather than ambiguously matched. Per-warehouse sales unavailable without source allocation.

Awal: most recent observed snapshot at/before period start. No backfilled history. API product ingestion captures daily/changed observations for warehouse and aggregate scopes, within existing sync transaction. Same-day unchanged snapshots are skipped. Retur remains unknown until confirmed returned-unit matching is supported.

Minimum: scoped by store/product/variant/warehouse (empty warehouse = all-warehouse aggregate). Owner/admin + CSRF, identity/warehouse validation, transaction and audit. Setting a minimum updates stock warnings only; does not change HPP or source quantities. Status: unknown, out, minimum unconfigured, low, safe.

Migration: database/migration_marketplace_stock.php. Tests: tests/marketplace_stock_smoke.php; core marketplace_sync_smoke.php. Visual QA covers per-SKU table and minimum dialog with disposable synthetic data. Production JAGOIN catalog-only sync completed without failures and captured 56 scope snapshots for 28 variants.
