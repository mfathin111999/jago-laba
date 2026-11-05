# Pilot Marketplace dan Konveksi

Pilot ini mengalihkan dua entry overview secara terpisah ke Laravel dan menambahkan satu mutasi lokal berisiko rendah: pencatatan pengeluaran Marketplace. Provider, schema lama, dan operasi sinkronisasi tidak dipindahkan.

## Scope

- `GET /pilot/marketplace` membaca store yang sudah terhubung, memilih `store`/`store_id`, mempertahankan filter `period`, `date_start`, `date_end`, dan `finance_mode`, lalu mengembalikan halaman Inertia `Pilot/Marketplace`.
- `GET /pilot/konveksi` membaca kontrak lama `loadSemuaData` untuk collection Konveksi yang di-allowlist, termasuk `__meta`, lalu mengembalikan halaman Inertia `Pilot/Konveksi`.
- `GET /marketplace.php?tab=dashboard` dan `GET /index.php?tab=dashboard` mempertahankan pintu masuk lama dengan redirect `303` ke halaman pilot dan meneruskan query dashboard yang relevan.
- `POST /api/rpc.php` dengan `method=loadSemuaData` mempertahankan envelope `{ok,result}` lama dan membaca adapter Konveksi baru; method RPC lain tetap reservation-only.
- `POST /api/marketplace-ajax.php?action=add_expense` mempertahankan caller AJAX lama, dengan validasi Form Request, CSRF browser, company/store/workspace/role scope, transaction, audit, dan idempotency.
- `POST /pilot/marketplace/expenses` adalah endpoint Inertia baru untuk form pilot dan mengembalikan redirect `303` ke dashboard setelah berhasil.
- Route mutation hanya memasukkan baris lokal ke `marketplace_expenses`; `SnapshotImporter`, OAuth provider, queue, dan service write provider tidak dipanggil.

## Baseline dan parity contract

Marketplace baseline berasal dari `app/Modules/Marketplace/Controllers/marketplace.php`, `app/Modules/Marketplace/Services/MarketplaceDashboardService.php`, `app/Modules/Marketplace/Services/MarketplaceOrderPresentation.php`, dan `tests/Integration/marketplace_dashboard_smoke.php` pada aplikasi legacy.

Adapter Laravel mempertahankan:

- filter orders: store aktif, `source_kind=api`, `internal_state IS NULL OR active`, rentang tanggal half-open, urutan `order_date,id`;
- filter expenses: rentang tanggal, `affects_profit=1`, `is_cashflow_only=0`, urutan `expense_date,id`;
- state `final`, `estimated`, `unknown`, perhatian `cancelled`/`unpaid`, nilai null untuk finance/HPP yang belum terverifikasi, dan `cost_coverage_complete=false`;
- tenant scope melalui `company_key` dan store yang belum diarsipkan serta memiliki OAuth authorization.

Konveksi baseline berasal dari `app/Modules/Konveksi/Controllers/RpcService.php`, `app/Modules/Konveksi/Repositories/KonveksiCollectionStore.php`, `app/Shared/Support/CollectionStore.php`, dan `app/Shared/Support/SnapshotImporter.php`.

Adapter Laravel mempertahankan:

- nama collection domain Konveksi dan payload `loadSemuaData`;
- pembacaan `system_table_map` lalu `_data_json` dengan urutan `_row_order,_row_id`;
- `__meta.versions`, `domain`, `reset_epoch`, `health`, dan `company_bound`;
- pembacaan tabel dinamis hanya setelah nama tabel lolos allowlist `jl_data_[a-z0-9_]+`.

## Keputusan koneksi Konveksi

Target Konveksi diputuskan memakai database terisolasi sesuai konfigurasi deployment legacy (`isolated=true` dan company binding `LOCAL-COMPANY`). Adapter Laravel tidak lagi menganggap koneksi default sebagai database Konveksi: pada runtime non-testing adapter membaca konfigurasi private Konveksi, membangun koneksi read-only dinamis, memeriksa company binding, dan berhenti jika konfigurasi isolated tidak valid. Nilai credential tidak dikirim ke props, log, atau response.

Fixture test sengaja memakai koneksi SQLite in-memory agar parity tidak menyentuh database aplikasi. Verifikasi database aktual dilakukan pada instance MariaDB staging sementara di port 3307 menggunakan dump Konveksi yang sama; adapter tidak melakukan fallback diam-diam ke database default.

## Bukti verifikasi

`tests/Feature/PilotReadOnlyTest.php` sekarang menjalankan parity runtime read-only pada satu database SQLite in-memory dan fixture yang sama:

- `test_marketplace_legacy_and_laravel_runtime_match_on_the_same_fixture` memanggil `MarketplaceDashboardService::report()` legacy dan GET `/pilot/marketplace` Laravel/Inertia dengan store, filter, dan PDO yang sama. Test membandingkan status `200`, dashboard data, period half-open, filter/source/order query, props yang dipakai halaman Vue, company/store scope, penolakan store asing (`DomainException` legacy vs `403` Laravel), dan waktu baca masing-masing di bawah 2 detik.
- `test_konveksi_legacy_and_laravel_runtime_match_on_the_same_fixture` memanggil `KonveksiCollectionStore::allKnown()` legacy dan GET `/pilot/konveksi` Laravel/Inertia dengan tabel dynamic fixture yang sama. Test membandingkan seluruh collection, checksum `__meta.versions`, reset epoch, health, company binding, tab/query contract, props `data`/`collections`, status `200`, dan waktu baca masing-masing di bawah 2 detik. `loaded_at` dikecualikan karena memang dibuat ulang oleh setiap runtime; tipe numerik agregat dinormalisasi hanya untuk membedakan integer-vs-float JSON yang semantically sama.

HTTP/Inertia bukan lagi diwakili oleh halaman legacy yang sedang terbuka: kedua test di atas membaca response Laravel aktual, sementara service legacy dipanggil langsung pada PDO fixture yang sama untuk baseline. Regression test legacy pada `tests/Integration/marketplace_dashboard_smoke.php` juga sudah dijalankan terpisah; test tersebut membuat database MySQL sementara dengan nama acak dan selalu menghapusnya pada `finally`, tanpa menyentuh database aplikasi.

Perintah verifikasi fokus dari direktori `laravel-foundation`:

```text
vendor/bin/phpunit --filter PilotReadOnlyTest
```

Hasil terakhir: `15 tests, 172 assertions, OK` pada PHP `8.4.27`.

Validasi tambahan adapter Konveksi mencakup penolakan company asing sebelum query dan penolakan mapping `system_table_map` di luar allowlist. Regression legacy pada dump staging lulus, dan read Laravel pada database yang sama menghasilkan 36 collection domain, 5 produk, 19 master stok, `duplicate_stock_ids=0`, dan `negative_stock=0`. Instance XAMPP lama pada port 3306 tetap memiliki risiko metadata replication/master-info; tidak ada file data, relay log, atau schema yang diperbaiki/dihapus.

Fixture parity tidak menjalankan provider Marketplace/OAuth dan tidak melakukan write ke database aplikasi; parity memakai SQLite in-memory. Regression smoke legacy terpisah hanya menulis ke database MySQL sementara yang dihapus setelah test.

## Pilot mutasi lokal: Marketplace expense

Baseline mutasi adalah action `add_expense` pada `app/Modules/Marketplace/Controllers/Api/marketplace-ajax.php` dan `MarketplaceService::recordExpense`. Adapter Laravel mempertahankan nama field, normalisasi nominal `Rp`, default category/vendor/date/payment method, flag `affects_profit` dan `is_cashflow_only`, serta response success legacy.

- [MarketplaceExpenseRequest](../app/Http/Requests/MarketplaceExpenseRequest.php) mempertahankan input lama dan mengembalikan JSON `422` untuk caller AJAX; form Inertia mendapat redirect kembali dengan validation errors.
- [MarketplaceExpenseWriteService](../app/Services/Marketplace/MarketplaceExpenseWriteService.php) memeriksa store dalam company aktif, menulis expense, audit `CREATE_EXPENSE`, dan idempotency record dalam satu transaction.
- [MarketplaceExpenseController](../app/Http/Controllers/MarketplaceExpenseController.php) menyediakan response JSON lama dan redirect Inertia. Header `X-Idempotency-Key` wajib untuk route baru; caller lama mendapat fallback key agar tetap kompatibel.
- Receipt idempotency expense sekarang scoped eksplisit oleh `(company_key, module, idempotency_key)`, dengan module tetap `marketplace.expense`. Key caller tidak dinamespace di response, sehingga kontrak lama tetap sama, tetapi key yang sama pada company atau module berbeda tidak saling collision.
- Migration `database/migrations/2026_10_30_000003_scope_idempotency_keys.php` menghapus unique global, menambah composite unique, dan menandai receipt lama tanpa tenant sebagai `LEGACY-GLOBAL`/`legacy`; rollback berhenti bila data lintas scope akan kembali collision. Installer legacy juga memperbaiki schema melalui `SchemaManager::repairIdempotencySchema`.
- Consumer legacy Konveksi `InventoryService` juga membaca/menulis receipt dengan scope company dan module `konveksi.inventory`, sehingga key Inventory tidak dapat mengambil response expense secara lintas module.
- Service fail-closed bila kolom scope belum tersedia. Key yang sama dan payload sama dalam scope yang sama mengembalikan hasil tersimpan tanpa insert kedua; payload berbeda dalam scope yang sama mengembalikan `409 IDEMPOTENCY_CONFLICT`.
- Error validation, role, CSRF, foreign store, duplicate request, duplicate key lintas company/module, database failure/rollback, dan refresh dashboard diuji pada `tests/Feature/PilotReadOnlyTest.php`.

Migration scope idempotency ini tidak mengubah provider, data bisnis, atau endpoint lama; migration production belum dijalankan sebagai bagian task ini. Tabel `idempotency_keys` dan `system_audit_logs` tetap dipakai melalui adapter; bila migration scope belum diterapkan, write berhenti dengan error tanpa fallback write.

Evidence staging untuk mutasi ini ada di
[marketplace-expense-write-evidence-20261101.md](../../docs/deployment/marketplace-expense-write-evidence-20261101.md).
Restore MariaDB membuktikan write, replay idempotent, conflict, company/store
scope, audit, dan rollback tanpa provider Live. Fixture staging dibersihkan
setelah uji.

## Boundary OAuth/provider sandbox

Boundary OAuth semua platform Marketplace dipisahkan pada [pilot-marketplace-oauth-sandbox.md](./pilot-marketplace-oauth-sandbox.md). Callback lama tetap dipakai selama callback Laravel baru belum diregistrasikan dan diuji pada portal provider. Tidak ada provider Live yang dipanggil pada foundation.

## Di luar scope

Tab Marketplace selain dashboard dan expense lokal, pengaturan/OAuth, sinkronisasi, export CSV, order mutation, seluruh operasi tulis Konveksi, cutover URL lama, dan penghapusan endpoint lama tetap belum dimigrasikan.
