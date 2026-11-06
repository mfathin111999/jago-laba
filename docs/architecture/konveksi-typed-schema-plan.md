# Rencana: Konveksi dari tabel JSON ke tabel MySQL bertipe (tingkat B)

Status: **semua gelombang selesai dan terverifikasi** (30 koleksi bertipe: 21 berisi data + 9 dirancang dari kode, gelombang 5). Tidak ada data produksi sebelumnya (proyek baru dibuat dari PHP murni), jadi tidak ada profiling produksi yang tertunda; desain mengikuti field yang benar-benar ditulis layanan. Lihat bagian "Yang sudah dibuat" di bawah. Melanjutkan [konveksi-migrations.md](konveksi-migrations.md) (tingkat A: skema sekarang dikelola migrasi Laravel, tapi tiap sheet masih satu tabel `jl_data_<kunci>` berisi `_data_json`).

## 1. Tujuan dan batas
- Tiap koleksi menjadi tabel dengan kolom bertipe (`DECIMAL`, `DATE`, `INT`, `VARCHAR`), primary key, indeks, dan akhirnya foreign key.
- **Kontrak ke klien tidak berubah**: RPC Konveksi tetap mengembalikan/menerima array objek dengan nama field yang sama. Perubahan hanya di lapisan penyimpanan.
- Tidak ada data hilang: field yang belum dipetakan disimpan di kolom `_extra_json` sampai dipetakan.
- Logika bisnis tidak diubah (keputusan pemilik 4 Nov 2026).

## 2. Temuan dari snapshot (38 sheet, 226 baris; 17 sheet berisi, 21 kosong)
- **Tipe campur dalam satu kolom**: `tanggal` ada yang angka (serial Google Sheets) dan ada yang teks; `no_wa`, `password`, `id_*` kadang angka kadang teks (`PRD-…`). Kolom `Pengguna.password` bernilai numerik di snapshot: perlu dicek apakah hash atau teks polos.
- **Data bersarang sebagai teks**: kolom `items` (Pembelian, Potong, Jahit), `permissions_json`, `before_json/after_json` berisi JSON dalam string → kandidat tabel anak (`pembelian_items`) atau kolom `JSON`.
- **Skema ganda dalam satu sheet**: `Bahan` memuat kolom lama (`id, jenis, harga_beli`) dan baru (`id_bahan, kode_bahan, harga_satuan…`) berdampingan; `Distribusi` juga.
- **Gaya penamaan campur**: snake_case (sheet lama) vs PascalCase (`PembelianID`, `QtyTarget`) pada sheet baru (semuanya masih kosong).
- 21 sheet kosong (Penjualan 55 kolom, HPP_Model_BOM 27, Bahan_HPP 43, …): skemanya hanya bisa diturunkan dari header dan kode, tanpa contoh data.
- Snapshot hanya 226 baris. **Data produksi nyata lebih besar dan lebih kotor**; desain final wajib divalidasi terhadap salinan produksi.

## 3. Titik sentuh di kode
- Akses terpusat di `KonveksiCollections` (`rows/insert/update/delete`), tetapi ada ±38 pemakaian `_data_json` langsung di 10 berkas: Backup, DocumentAndAi, Hutang, Inventory, PilotRead, PurchaseWrite, SalesWrite, SyncAndHpp, plus importer/isolate. Ini harus dilewatkan satu pintu lebih dulu.
- Sinkron koleksi (versi, penggantian seluruh koleksi) dan backup/snapshot berasumsi dokumen JSON; keduanya perlu adaptor.

## 4. Strategi: per koleksi, dengan bayangan (shadow) dan saklar
Satu koleksi pindah sendiri-sendiri; tidak ada "big bang".

1. **Profiling** — perintah `konveksi:profile` (baca saja): per kolom tipe terdeteksi, persentase null, panjang maksimum, nilai jenis campur, ID yatim. Dijalankan pada salinan produksi. Hasilnya menjadi dasar tipe kolom.
2. **Definisi koleksi** — satu kelas per koleksi (`Collections/ProdukCollection`): nama tabel baru (`kv_produk`), kolom, cast dua arah (baris ⇄ objek JSON klien), aturan normalisasi (tanggal serial → `DATE`, "Rp 1.000" → `DECIMAL`, ID teks/angka), dan `_extra_json` untuk field tak terpetakan.
3. **Satu pintu akses** — `KonveksiCollections` memilih driver per koleksi (`json` atau `typed`) dari konfigurasi; 10 berkas di atas dialihkan ke pintu itu. Test karakterisasi memastikan keluaran identik sebelum dan sesudah.
4. **Backfill + rekonsiliasi** — `konveksi:backfill {koleksi}`: salin dari `jl_data_*` ke tabel bertipe, lalu bandingkan setiap baris dengan membangun ulang objek JSON dan membandingkannya dengan aslinya (setelah normalisasi yang disepakati). Baris yang gagal dilaporkan, bukan diperbaiki diam-diam.
5. **Tulis ganda lalu saklar** — selama masa transisi tulis ke dua tabel, baca dari satu; cek berkala selisih. Setelah stabil, baca dari tabel bertipe.
6. **Pensiun** — tabel JSON dijadikan arsip baca-saja beberapa minggu, lalu dihapus lewat migrasi terpisah.
7. **Constraint terakhir** — FK dan `NOT NULL` ditambah setelah data bersih (yatim dibereskan lebih dulu), bukan di awal.

Rollback tiap koleksi: kembalikan saklar ke `json` (tabel JSON tetap utuh dan terus diperbarui selama tulis ganda).

## 5. Urutan pengerjaan yang disarankan
| Gelombang | Isi | Alasan |
|---|---|---|
| 0 | Satu pintu akses + `konveksi:profile` + test karakterisasi | Tanpa ini semua gelombang berisiko |
| 1 | Master kecil: Rekening, Supplier, Pegawai, Profil | Sederhana; melatih pola |
| 2 | Produk, Bahan, Master_Stok, Mutasi_Stok | Inti stok; skema ganda `Bahan` diputuskan di sini |
| 3 | Pembelian (+`items`), Potong, Jahit, Keuangan, Kasbon, Hutang | Transaksi; kolom `items` jadi tabel anak; uang jadi `DECIMAL` |
| 4 | Pengguna, Hak_Akses, Template_Dokumen | Sensitif; periksa `password` dulu |
| 5 | Sheet kosong (Penjualan, HPP_*, Bahan_HPP, …) | Dirancang dari kode; ditunda sampai fiturnya dipakai |

## 6. Estimasi (dengan asumsi data produksi tersedia)
- Gelombang 0: 2-3 hari. Gelombang 1: 1-2 hari. Gelombang 2: 3-4 hari. Gelombang 3: 4-6 hari. Gelombang 4: 1-2 hari. Gelombang 5: menyusul.
- Total gelombang 0-4: ±2-3 minggu kerja. Tiap gelombang berhenti dalam keadaan hijau dan bisa dijeda.
- Pembanding dari diskusi sebelumnya: tingkat A selesai dalam hitungan hari; B lebih lama karena ada normalisasi data, tulis ganda, dan pengujian dengan data nyata.

## 7. Keputusan yang dibutuhkan dari pemilik
1. **Salinan data produksi** (anonimkan bila perlu) untuk profiling. Tanpa ini desain berisiko.
2. **Tipe uang**: `DECIMAL(18,2)` (saran) atau lebih presisi untuk HPP/kuantitas?
3. **Penamaan kolom**: pertahankan nama Indonesia/snake_case yang ada (saran, agar pemetaan klien mudah) dan samakan gaya PascalCase sheet baru ke snake_case?
4. **Format ID**: pertahankan nilai asli (campur angka/teks) sebagai `VARCHAR` untuk transisi (saran), atau seragamkan ke `BIGINT` + kolom kode?
5. **Aturan data kotor**: baris yang tak lolos normalisasi → ditolak, atau disimpan apa adanya di `_extra_json` dengan peringatan (saran)?
6. **`Pengguna.password`**: apakah hash? Bila teks polos, hash ulang perlu direncanakan terpisah.
7. Apakah FK ketat (menolak data yatim) diinginkan, atau hanya indeks?

## 8. Risiko
- Pembulatan uang/float: `DECIMAL` harus diserialisasi ke JSON sebagai angka yang sama dengan sekarang (uji batas pembulatan).
- Tanggal serial Sheets dan zona waktu: aturan konversi harus eksplisit dan diuji.
- Kunci/idempotensi dan penguncian baris di layanan (`lockForUpdate`) harus tetap berlaku pada tabel baru.
- Backup/snapshot dan impor lama mengasumsikan JSON: ikut diadaptasi, dan hasil pemulihan diuji.

## 9. Yang sudah dibuat
- **Satu pintu akses**: `KonveksiCollections` (rows/documents/insert/update/delete/replace/eachSheet) dipakai semua layanan; penyimpanan di balik `CollectionDriver` (`JsonCollectionDriver`, `TypedCollectionDriver`). Pemetaan koleksi → tabel dibaca setiap panggilan, jadi pengalihan layout berlaku langsung, juga di worker antrean.
- **Definisi bertipe** di `database/schema/konveksi_typed.php` (21 koleksi, tipe mengikuti data nyata: `bigint` untuk ID, `decimal(18,2)` uang, `decimal(18,4)` kuantitas, `date`/`datetime`, `string:n`, `text`, `bool`). Tabel `kv_<koleksi>` dibuat migrasi `2026_11_12_000001`.
- **Tanpa kehilangan data**: `TypedRowMapper` menyimpan field tanpa kolom di `_extra_json.x`, nilai asli yang berubah saat melewati kolom (angka sebagai teks, serial tanggal Sheets, `null` vs `''`) di `.o`, dan field yang memang tidak ada di `.a`. Record yang dibaca = record yang ditulis (sama kunci, nilai, dan tipe).
- **`php artisan konveksi:profile`** (baca saja): profil tipe per field dari data nyata.
- **`php artisan konveksi:typed status|migrate|rollback`**: `migrate` menyalin (row id dipertahankan), membandingkan setiap record dengan JSON, baru mengalihkan `system_table_map`; verifikasi gagal = tidak ada pengalihan. `rollback` menyalin baris bertipe kembali ke tabel JSON (tulisan baru ikut) lalu mengalihkan balik. Tabel JSON tetap sebagai arsip. Memakai kunci tulis yang sama dengan `konveksi:isolate`.
- `konveksi:isolate`, `konveksi:schema-check`, dan `konveksi:import-snapshot` sudah mengenali tabel bertipe (importer tidak mengalihkan koleksi bertipe kembali ke JSON).
- `konveksi:isolate` ikut menyalin tabel arsip JSON (`jl_data_<koleksi>`) dari koleksi yang sudah bertipe (diuji: Produk 17 baris di arsip dan di `kv_produk`).
- Nama indeks tabel pendukung (`system_table_map`, `system_audit_logs`, `backup_log`, `document_files`, `idempotency_keys`) disamakan dengan DDL lama; dibandingkan lewat `information_schema.statistics`, 18 indeks identik.
- Pembuatan skema saat request dihapus dari jalur yang dipakai Laravel: trigger penghapusan toko (`jl_erase_*`, 52 buah) dipasang migrasi `2026_11_13_000001` (diuji pada MariaDB kosong) dan layanan hanya memeriksanya; `MarketplacePartnerSettings` tidak lagi membuat tabel (tabelnya dari migrasi 000003). Sisa `install()` hanya dipanggil skrip installer lama (`public/install.php`, `bin/`, `scripts/`), bukan oleh aplikasi Laravel.
- **Gelombang 5** (tanpa data lama): `penjualan`, `penjualan_item`, `penjualan_koreksi`, `pembelian_item`, `bahan_hpp`, `bahan_hpp_history`, `hpp_model_bom`, `hpp_history`, `distribusi`. Kolom diturunkan dari field yang ditulis `KonveksiSalesWriteService`, `KonveksiPurchaseWriteService`, dan `KonveksiSyncAndHppService`, bukan dari header PascalCase lama (`PembelianID`, `QtyTarget`, … tidak dibaca/ditulis kode mana pun). ID yang dibuat layanan (`SALE-…`, `PBL-…`, `CORR-…`) berupa teks; karena itu `pembelian.id` dan `mutasi_stok.id_referensi` kini `string:60`, bukan `bigint`. Diuji: pembelian + penjualan menghasilkan record yang sama di layout JSON dan bertipe, juga di MariaDB.
- **Tetap JSON (sengaja)**: `potong_item`, `jahit_item`, `quality_control`, `mutasi_stok_konveksi`, `marketplace_product_mapping`, `marketplace_sync_queue`, dan `hutang_supplier` (ejaan ganda `hutangsupplier`). Tidak ada kode atau layar yang membaca/menulisnya selain daftar sheet; tipe baru ditambahkan di `konveksi_typed.php` saat fiturnya ada.
- **Kontrak mapper = layout JSON**: record dinormalkan seperti disimpan JSON sebelum dipetakan (`10.0` kembali sebagai `10`), sehingga dua layout mengembalikan record identik dan `_extra_json` tidak membengkak.
- **Login dan Pengguna**: login Konveksi tetap lewat sesi Laravel (`auth_users_v2`, password ter-hash). Koleksi `pengguna` kini tidak pernah menyimpan atau mengirim password polos: password yang diketik di layar di-hash, dikosongkan di respons, dan dicerminkan ke akun login (hanya Owner yang boleh memberi/mengubah peran Owner; akun yang dihapus dari daftar dinonaktifkan). Snapshot lama dibersihkan oleh importer dan `php artisan konveksi:secure-passwords`.
- Bukti (MariaDB, snapshot nyata): 21 koleksi, semua record identik setelah dipindah dan setelah rollback + migrate ulang; isolasi database menyalin tabel bertipe.

## 10. Temuan data
- `Pengguna.password` bernilai angka enam digit: kemungkinan kata sandi polos pada koleksi Konveksi (bukan login Laravel, yang memakai hash di `auth_users_v2`). Perlu diputuskan terpisah; kolom dibuat `string:255`.
- `Keuangan.tanggal` dll. campuran serial Sheets dan ISO; kini kolom `date` sungguhan, nilai asli tetap dikembalikan ke klien.
- Nomor telepon disimpan sebagai angka di sebagian baris (angka 0 di depan hilang); kolom dibuat teks.
- Kolom yang di snapshot selalu kosong tipenya ditebak dari nama field; periksa lagi dengan data produksi (`konveksi:profile`).
