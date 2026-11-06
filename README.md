# Jago Laba — Konveksi & Marketplace

Aplikasi Laravel 13 (Inertia + Vue 3) di [`laravel-foundation/`](laravel-foundation/). Ruang kerja **Konveksi** (produksi, stok, pembelian, penjualan, keuangan) dan **Marketplace** (Shopee, TikTok Shop: toko, pesanan, HPP, keuangan, sinkronisasi).

Kode PHP lama (pra-Laravel) sudah dihapus dari repositori. Versi terakhirnya ada di tag git `legacy-php-final`.

## Menjalankan di komputer sendiri

```
run-local.cmd
```

Skrip itu membuat database kosong, menjalankan `php artisan migrate`, memuat data contoh Konveksi, memindahkan koleksi ke tabel bertipe, dan membuat akun Owner. Semua skema database berasal dari migrasi Laravel.

## Perintah yang sering dipakai (di `laravel-foundation/`)

| Perintah | Fungsi |
|---|---|
| `php artisan migrate` | Membuat/menambah skema (tabel yang sudah ada tidak diubah) |
| `php artisan jago:create-owner` | Membuat atau mereset akun Owner |
| `php artisan konveksi:schema-check`, `marketplace:schema-check` | Memeriksa database terhadap migrasi (baca saja) |
| `php artisan konveksi:typed status\|migrate\|rollback` | Memindahkan koleksi Konveksi antara tabel JSON dan tabel bertipe |
| `php artisan konveksi:isolate --prepare` | Menyalin Konveksi ke database sendiri dengan user MySQL terbatas |
| `php artisan konveksi:secure-passwords` | Mengosongkan password polos lama di daftar pengguna Konveksi |
| `php artisan marketplace:sync` | Menjalankan sinkronisasi marketplace (dijadwalkan lewat `routes/console.php`) |
| `php vendor/bin/phpunit` | Seluruh tes PHP |
| `npm run build`, `npm run test:visual` | Frontend dan uji visual |

## Dokumentasi

- [Cutover database dan runbook](docs/architecture/database-cutover-runbook.md)
- [Migrasi skema Konveksi dan tabel bertipe](docs/architecture/konveksi-migrations.md), [rencana tabel bertipe](docs/architecture/konveksi-typed-schema-plan.md)
- [Hasil audit "Laravel way"](docs/architecture/laravel-way-audit-20261105.md)
- [Persiapan deployment](docs/deployment/production-guide.md), [backup dan rollback](docs/recovery/backup-restore-rollback.md)

Dokumen lain di `docs/` adalah catatan historis dari masa aplikasi PHP lama.
