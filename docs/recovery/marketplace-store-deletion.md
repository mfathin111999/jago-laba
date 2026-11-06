> **Catatan arsip (6 Nov 2026):** dokumen ini ditulis saat aplikasi masih PHP murni. Jalur file, skrip, dan kelas yang disebut di bawah (`app/Modules`, `public/*.php`, `bin/workers`, dll.) sudah dihapus dan digantikan Laravel; lihat [penghapusan kode lama](../architecture/legacy-removal.md) dan [arsitektur aktif](../architecture/../architecture.md). Perilaku bisnis yang dijelaskan tetap berlaku.

# Penghapusan permanen toko Marketplace

Penghapusan hanya mencakup penyimpanan aktif Jago Laba untuk toko terpilih. Data toko asli pada marketplace, konfigurasi aplikasi bersama, dan data Konveksi tetap dipertahankan.

## Instalasi

Jalankan dari direktori proyek menggunakan PHP CLI:

```powershell
& D:/xampp/php/php.exe bin/workers/marketplace-store-deletion.php --install
```

Installer menambahkan tabel penghapusan, registrasi berkas, dan penghalang penulisan database. Installer tidak menghapus toko. Pratinjau dan konfirmasi nama toko dilakukan melalui Pengaturan → Manajemen Toko → Toko diarsipkan.

## Verifikasi

Pengujian integrasi menggunakan database uji terpisah. Jangan menggunakan toko nyata untuk menguji penghapusan. Penghapusan selesai hanya setelah pemeriksaan data, berkas terdaftar, dan antrean terkait. Kegagalan pembersihan dapat dicoba kembali melalui alur penghapusan.

## Backup dan pemulihan

Penghapusan penyimpanan aktif tidak menghapus salinan pada backup lama dan tidak membuat backup tambahan. Konfigurasi lokasi backup ada di `config/backup.php`: default `D:/JAGO_LABA_BACKUP/database`, dapat diganti dengan `JL_BACKUP_DIR`; mirror melalui `JL_BACKUP_MIRROR_DIR`. Nilai retensi konfigurasi adalah 30 hari; administrator tetap perlu memastikan pekerjaan retensi dijalankan.

Ledger minimal penghapusan disimpan terpisah melalui `JL_DELETION_LEDGER_DIR`, dengan default Windows `D:/JAGO_LABA_RECOVERY/deletions`. Lindungi ledger dari penimpaan ketika memulihkan backup lama.

Pemulihan backup wajib dilakukan dengan aplikasi, worker, scheduler, dan callback dinonaktifkan. Cocokkan hasil pemulihan terhadap ledger penghapusan, pulihkan penghalang penghapusan, dan bersihkan kembali data toko yang sudah dihapus sebelum membuka akses atau menjalankan sinkronisasi. Jangan mengaktifkan kembali koneksi dari backup untuk toko tersebut.

Penerapan ulang ledger ke backup yang dipulihkan belum otomatis. Administrator harus menyelesaikan dan memverifikasi langkah tersebut; memulihkan backup lama saja dapat mengembalikan data serta token lama. Jangan menghapus backup bersama secara keseluruhan karena berisi data toko lain.
