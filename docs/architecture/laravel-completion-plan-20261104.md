# Rencana & checklist penuntasan migrasi Laravel — 4 November 2026

Menggantikan urutan kerja di [laravel-completion-plan-20261101.md](laravel-completion-plan-20261101.md) (isi langkah 1–14 di sana tetap berlaku sebagai definisi "selesai"). Dokumen ini hanya mengatur **urutan kerja yang efisien** berdasarkan kondisi kode nyata per 4 November.

## Posisi saat ini (hasil audit 4 Nov)

- Checklist: 178 item → 66 pilot, 5 sebagian, 6 stub/utilitas (butuh keputusan pemilik), 101 belum.
- Backend Marketplace lokal (AJAX/OAuth/fulfillment/worker) sudah jauh; **UI hampir nol**: 4 halaman Vue Marketplace, 1 halaman Konveksi.
- Tes: 80 tes (PHP 8.4), 2 gagal. Perubahan ±46 file belum di-commit.
- PHP default terminal 8.1 → selalu pakai `C:\xampp\php8_4\php.exe vendor/bin/phpunit`.

## Prinsip efisiensi

1. **Satu komponen bersama, banyak halaman.** 36 view Marketplace dan 24 menu Konveksi dibangun dari beberapa komponen generik (tabel+filter+paging, form modal, kartu KPI, sidebar), bukan 60 halaman ditulis satu-satu. [ReadReport.vue](../../laravel-foundation/resources/js/Pages/Marketplace/ReadReport.vue) jadi dasar.
2. **Kelompokkan per domain**, bukan per file: satu controller + service + request + halaman + tes per kelompok.
3. **Tes bertarget per langkah**, suite penuh hanya di akhir fase. Commit per langkah.
4. **Backend sebelum UI**: UI hanya dibangun di atas endpoint yang sudah hijau.
5. **Pekerjaan yang butuh pemilik/operator** (orphan, HTTPS staging, sandbox provider, Live) berjalan paralel dan tidak memblokir fase A–D.
6. Setelah tiap langkah: centang di sini, ubah status di CSV/JSON checklist.

## Checklist kerja

### Fase 0 — Stabilkan (hari ini)
- [x] 0.1 Perbaiki `MarketplaceCatalogReadTest::test_hpp_write_updates_sku_global_and_batch...` (HPP 60000 vs 65000) — penyebab: asersi tes memakai urutan id, bukan tanggal efektif
- [x] 0.2 Perbaiki `OAuthSandboxTest::test_all_supported_platforms_report_sandbox_only...` (400 vs 503, QueryException) — penyebab: fixture tanpa kolom status/updated_at; disconnect kini sudah diimplementasi
- [x] 0.3 Suite penuh hijau (80 tes, 811 assertion) dengan PHP 8.4
- [x] 0.4 Commit pekerjaan yang belum tersimpan
- [x] 0.5 Sinkronkan checklist .md/.csv/.json dengan status nyata

### Fase A — Sisa backend (tanpa UI)
- [x] A1 AJAX `sync_marketing_ads`, `test_api_connection` (sandbox/stub) — kontrak legacy dipertahankan (success:false, tanpa panggilan provider) + validasi toko/peran
- [x] A2 `marketplace-store-deletion` (list, preview, request, resume, status) — command worker sudah ada — service dan command nyata menggantikan stub; hanya owner (store.delete); install lewat `--install`, bukan dari request web. Sisa: pasang `guardAuthorization` di alur OAuth (A2b)
- [ ] A2b Pasang `MarketplaceStoreDeletionService::guardAuthorization` di callback/select OAuth agar toko yang sedang dihapus tidak bisa dihubungkan ulang
- [x] A3 `marketplace-sync` (start, step, status, preview, background, catalog, catalog_status) — start/background/step/status/preview memakai antrian DB yang ada; catalog/catalog_status masih sebagian (butuh konektor provider, mengembalikan pesan eksplisit)
- [x] A4 `marketplace-withdrawals` (preview sudah ada → confirm, template) + `marketplace-price` (prepare, save) — withdrawals confirm+template selesai (+perbaikan bug staging 10 baris); price hanya kontrak (503, tanpa panggilan provider) → menunggu Fase E
- [x] A5 Endpoint publik Marketplace (dipecah karena sifatnya berbeda):
  - [x] A5a `marketplace-stock` (minimum stok) + `marketplace-auto-sync` + `marketplace-browser-pulse` — termasuk menggantikan command auto-sync yang ternyata stub
  - [x] A5b `marketplace-branding` (logo) — validasi gambar (MIME+dimensi), CSP sandbox saat disajikan, hanya Owner/Admin yang menyimpan
  - [x] A5e `marketplace-integration-status` (GET kesehatan integrasi + retry lewat antrian) — bergantung pada port IntegrationRuntime/IntegrationPolicy/PartnerSettings, jadi dikerjakan terpisah — kesehatan integrasi + retry lewat antrian; pengecekan "konteks berubah" ditunda ke port binding OAuth (Fase E) agar tidak salah menandai reauthorize
  - [x] A5c Ekspor CSV: orders, dashboard (halaman HTML-nya masuk Fase B) — export saja; checklist PUBLIC orders/dashboard tetap "sebagian" sampai halamannya dibangun (B1/B3)
  - [x] A5d Menyentuh provider/rahasia → kontrak saja, tanpa panggilan provider: `marketplace-order-finance`, `marketplace-partner-settings` (butuh keputusan pemilik, sama seperti `save_api_credentials`) — order-finance menjawab dari data lokal (synced:false); partner-settings tidak menerima rahasia dari web (503) → keputusan pemilik
- [x] A6 RPC Konveksi sisa (dipecah setelah audit menemukan masalah yang lebih besar):
  - [x] A6a Satu resolver koneksi Konveksi (`KonveksiConnection`) untuk read dan semua service tulis — sebelumnya tulisan Konveksi memakai koneksi default, bukan database Konveksi terpisah
  - [x] A6b Backup nyata menggantikan backup palsu (`backup_log` "Berhasil" tanpa file) — snapshot JSON gzip semua tabel terpetakan, mysqldump opsional (password via env, bukan argumen), manifest checksum, salinan eksternal, kegagalan dicatat "Gagal" tanpa file parsial
  - [x] A6d Otorisasi RPC Konveksi: workspace Konveksi harus aktif dan semua selain 3 method baca butuh administrator (sebelumnya hanya cek login+CSRF; role Pembaca bisa menulis)
  - [x] A6e Dokumen yang diunggah benar-benar ditulis ke disk privat dan dicatat (sebelumnya bytes dibuang tapi respons "tersimpan")
  - [x] A6c RPC: `jadwalkanSnapshotRinganJagoLaba`, `retryBackupPenjualanProduksiServer`, `retryUploadPurchaseDocumentServerRpc` — snapshot dijadwalkan lewat marker + scheduler tiap menit (permintaan beruntun tidak menunda); retry backup penjualan; retry unggah faktur
- [x] A7 Dokumen: `konveksi-document`, `marketplace-shipping-document`, diagnostics/auth-diagnostic, `workspace.php` — unduh dokumen (cek akar penyimpanan), diagnostik hanya Owner tanpa path/hash, resi: GET kedaluwarsa + POST kontrak tanpa provider, workspace.php sudah dilayani Foundation.vue
- [ ] A9 **Audit kode "pilot" buatan agent sebelumnya** (kriteria: menulis nyata, cek akses, tidak mengarang data, ada tes yang membuktikan)
  - [x] Sudah diaudit dan diperbaiki: store-deletion, auto-sync, backup, dokumen, otorisasi RPC, fulfillment (mengarang kurir + status Terkirim), impor settlement (menebak angka, tanpa scope), gate operasi Konveksi (lock/idempotensi/versi)
  - [x] Sudah diaudit, sesuai legacy: klaim retur, impor laporan iklan
  - [x] Sales (post/void/correct) diaudit dan diport penuh: stok, mutasi, kas, total dihitung server, penomoran berurutan, kontrol koreksi
  - [ ] Belum diaudit: `KonveksiPurchaseWriteService`, `KonveksiInventoryService`, `KonveksiSyncAndHppService` (activateHpp, syncFromPurchases), template/Gemini di `KonveksiDocumentAndAiService`, `MarketplaceOrderWriteService`, `MarketplaceStoreWriteService`, `MarketplaceSettingsService`, `MarketplaceHppService`, `MarketplaceExpenseWriteService`, `MarketplaceOAuthService`, `MarketplaceSyncCommand`/job queue, `bin/workers/*`
  - Catatan minor: parsing nominal klaim `str_replace('.', '')` mengubah `"1500.50"` jadi `150050` (sama dengan legacy, kontrak format Indonesia); status klaim tidak divalidasi di controller (enum MySQL menolak → 500, bukan 422)
- [ ] A8 Suite penuh + commit

### Fase B — UI Marketplace (36 view → ±8 halaman)
- [ ] B0 Komponen bersama: layout, sidebar global/toko, store picker, date range, tabel+paging, modal form, KPI card, ikon/logo platform
- [ ] B1 Dashboard toko + analytics toko + global analytics/charts
- [ ] B2 Produk (global & toko) + HPP editor + stok
- [ ] B3 Orders (lengkapi detail, return case) + income + expenses global
- [ ] B4 Toko: tambah, arsip, picker, deletion
- [ ] B5 Integrasi + OAuth + settings tabs + branding
- [ ] B6 Marketing/iklan + withdrawals
- [ ] B7 Sync, auto-sync controls, background sync, price editor
- [ ] B8 Suite + smoke visual desktop/mobile + commit

### Fase C — UI Konveksi (24 menu → ±7 halaman)
- [ ] C0 Layout Konveksi + komponen bersama (pakai komponen B0)
- [ ] C1 Dashboard + master (produk, bahan, bahan_hpp, supplier, pegawai, rekening)
- [ ] C2 Stok + pembelian (bahan, vendor) + distribusi
- [ ] C3 Produksi: potong, jahit, hpp_produksi, penjualan_produksi
- [ ] C4 Keuangan: keuangan, kasbon, piutang, hutang, hutang_konveksi
- [ ] C5 Pengaturan: profil, pengguna, hak_akses, backup
- [ ] C6 Suite + commit

### Fase D — Hosting & operasi (butuh operator, paralel)
- [ ] D1 Staging HTTPS + trusted proxy + secure cookie (langkah 5)
- [ ] D2 Cron scheduler + queue worker di host, bukti run/stop/restart (langkah 10)
- [ ] D3 Izin file privat & checksum dokumen (langkah 9)

### Fase E — Provider
- [ ] E1 Sandbox per platform (callback terdaftar) — butuh pemilik akun
- [ ] E2 Adapter sync nyata + webhook (keputusan pemilik: stub vs bangun)
- [ ] E3 Live terbatas — hanya dengan persetujuan tertulis

### Fase F — Penutupan
- [ ] F1 Keputusan pemilik: 17 orphan, 4 stub, `install.php`/`repair_owner.php`
- [ ] F2 UAT per peran, bandingkan data dengan baseline
- [ ] F3 Artifact baru + scan secret + rehearsal rollback
- [ ] F4 Cutover + observasi + arsip legacy

## Urutan eksekusi

0 → A → B → C selesai tanpa menunggu siapa pun. D, E, F1 diminta ke pemilik/operator sekarang agar siap ketika B/C selesai. F2–F4 menyusul.
