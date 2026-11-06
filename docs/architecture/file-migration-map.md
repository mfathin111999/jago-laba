> **Catatan arsip (6 Nov 2026):** dokumen ini ditulis saat aplikasi masih PHP murni. Jalur file, skrip, dan kelas yang disebut di bawah (`app/Modules`, `public/*.php`, `bin/workers`, dll.) sudah dihapus dan digantikan Laravel; lihat [penghapusan kode lama](legacy-removal.md) dan [arsitektur aktif](../architecture.md). Perilaku bisnis yang dijelaskan tetap berlaku.

# Peta dan tahap migrasi

Peta lengkap: [file-migration-map.csv](file-migration-map.csv), satu baris untuk setiap file dalam inventaris. owner_proposed/target_proposed adalah usulan, bukan hasil pemindahan. SHA-256 merekam versi awal. Jangan menjalankan pemindahan massal dari CSV; kepemilikan bertanda review belum final.

[Include dependencies](include-dependencies.csv) berisi ekspresi statis, belum semuanya path terurai. Periksa juga class reference, JS fetch, form action, menu, stylesheet, launcher dan callback.

| Tahap | Pekerjaan | Syarat lanjut |
|---|---|---|
| P0 | Backup DB/key/storage konsisten dan restore terisolasi; catat baseline | Restore teruji dan baseline ID/count/nominal tersedia. |
| P1 | Tutup installer/alat mutasi publik; tetapkan docroot, environment dan storage privat | Endpoint sensitif tertutup; dokumen terlindungi. |
| P2 | Bootstrap minimal, namespace/autoload bertahap, layanan modul dengan adapter | Login, workspace, scope dan alur Konveksi tetap lulus. |
| P3 | Pecah marketplace.php per fitur dan rapikan view/aset | URL/callback kompatibel; desktop/mobile/error/loading terverifikasi. |
| P4 | Registry migration, repositori, runner worker portabel, backup dan penjadwal | Tidak ada job ganda; heartbeat dan urutan migration benar. |
| P5 | Staging, pengujian isolasi/OAuth/keuangan/dokumen; cutover | Kriteria produksi dan rollback terpenuhi. |

## Contoh penting

| Sumber | Tujuan awal | Perlakuan |
|---|---|---|
| app/Services/MarketplaceOrdersService.php | app/Modules/Marketplace/Services/MarketplaceOrdersService.php | Adapter sementara, lalu ekstrak Application/Orders. |
| app/Services/HppService.php | app/Modules/Konveksi/Services/HppService.php | Jangan digabung dengan HPP SKU Marketplace. |
| app/Services/BackupService.php | app/Shared/Services/BackupService.php | Review pemanggil bersama. |
| app/RpcService.php | app/Modules/Konveksi/Application/RpcService.php | Pertahankan dispatch/izin dan endpoint rpc. |
| public/marketplace.php | Tetap facade | Isi dipindah per fitur. |
| public/api/marketplace-oauth.php | URL lama sementara | Sesuaikan registrasi provider sebelum cutover. |
| public/storage/documents | storage/private/documents | Migrasi runtime terpisah dengan checksum dan metadata; di luar CSV sumber. |
| database/Seed* | database/seeds/development | Tidak dijalankan pada produksi. |
| scripts/marketplace-background-worker.php | scripts/marketplace/marketplace-background-worker.php | Launcher/task harus diperbarui bersama. |

Jangan membawa .venv, runtime JDK dalam docs, dump, sumber XLSX, key atau unggahan privat ke public. Jangan hapus data runtime untuk merapikan folder. Penamaan numerik view Konveksi dipertahankan sementara sampai registri menu dan pemanggil siap diubah.

Tiap kelompok: periksa perubahan pekerjaan lain → pindah bersama pemanggil → syntax check PHP dan contract test relevan → uji user sah/lintas perusahaan/lintas toko → bandingkan ID/jumlah/nominal/HPP → hapus adapter hanya setelah tidak dipakai dan rollback siap.

Tahapan ini belum dijalankan dalam audit.
