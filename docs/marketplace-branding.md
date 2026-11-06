> **Catatan arsip (6 Nov 2026):** dokumen ini ditulis saat aplikasi masih PHP murni. Jalur file, skrip, dan kelas yang disebut di bawah (`app/Modules`, `public/*.php`, `bin/workers`, dll.) sudah dihapus dan digantikan Laravel; lihat [penghapusan kode lama](architecture/legacy-removal.md) dan [arsitektur aktif](architecture/../architecture.md). Perilaku bisnis yang dijelaskan tetap berlaku.

# Identitas dan logo Marketplace

Brand navigasi: Jago Laba / MARKETPLACE MARKETING SYSTEM. Satu partial marketplace_brand.php digunakan sidebar toko, pemilih toko, pengaturan dan topbar ponsel. Logo tampil bulat (border-radius 50%), default 32px dan 28px pada ponsel. Ukuran lama 44/52px ditampilkan sebagai 32/36px tanpa mengubah gambar tersimpan. Logo awal JL tetap menjadi fallback sampai pengguna mengunggah gambar.

Menu Pengaturan → Identitas & Logo (peng_sub=identitas) memuat pratinjau, unggah PNG/JPG/WebP maksimal 2 MiB dan dimensi 2048px, ukuran 28/32/36px, contain/cover, dan kembali ke JL. Pratinjau baru diterapkan setelah Simpan Logo berhasil. Tombol dinonaktifkan selama permintaan.

Tabel marketplace_branding menggunakan company_key sebagai primary key. Metadata logo dibaca terpisah dari BLOB agar navigasi tidak mengambil gambar besar dari database. File tidak ditulis ke direktori executable/public. Endpoint gambar memerlukan sesi workspace Marketplace, mengikuti perusahaan aktif, mengirim MIME raster yang telah divalidasi, nosniff, no-store/private dan CSP sandbox. SVG dan format selain raster ditolak. Perubahan hanya OWNER/ADMIN dan wajib CSRF. Versi gambar memakai SHA-256; ukuran dan fit tidak memodifikasi data toko atau transaksi.

Migrasi database/migration_marketplace_branding.php telah diterapkan. Tidak ada logo kustom yang diunggah ke perusahaan produksi selama pengujian.

Verifikasi: marketplace_branding_smoke memakai database terpisah dan memeriksa PNG valid, SVG/ukuran berlebih ditolak, penyimpanan/pembacaan BLOB, metadata tidak membocorkan BLOB, isolasi perusahaan, pembatasan staf, reset dan validasi pilihan ukuran. Render PHP ketat dashboard/pengaturan/identitas berhasil. Browser desktop dan 390px memverifikasi pratinjau ukuran berubah, brand lengkap dan tidak ada overflow horizontal pada halaman. Bukti tampilan menggunakan data pengujian.
