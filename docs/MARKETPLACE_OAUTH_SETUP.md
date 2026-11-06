> **Catatan arsip (6 Nov 2026):** dokumen ini ditulis saat aplikasi masih PHP murni. Jalur file, skrip, dan kelas yang disebut di bawah (`app/Modules`, `public/*.php`, `bin/workers`, dll.) sudah dihapus dan digantikan Laravel; lihat [penghapusan kode lama](architecture/legacy-removal.md) dan [arsitektur aktif](architecture/../architecture.md). Perilaku bisnis yang dijelaskan tetap berlaku.

# Setup OAuth Marketplace Jago Laba

Status implementasi: administrator dapat memasukkan dan menguji kredensial partner dari **Marketplace → Integrasi API**. Rahasia disimpan terenkripsi di database, atau tetap dapat disediakan lewat environment server. Token dan refresh token seller disimpan terenkripsi. Koneksi seller baru hanya berstatus terverifikasi setelah pertukaran token dan panggilan identitas resmi berhasil.

## Uji API langsung dari aplikasi

1. Pastikan `JL_MARKETPLACE_TOKEN_ENCRYPTION_KEY` tersedia di server, lalu login sebagai OWNER/ADMIN.
2. Buka **Marketplace → Integrasi API**.
3. **Shopee:** isi Partner ID dan Partner Key, pilih Sandbox atau Live, lalu klik **Simpan & uji API**. Uji memakai endpoint partner Shopee tanpa memilih toko.
4. **TikTok Shop:** isi App Key, App Secret, dan access token uji dari Development Shop, lalu klik **Simpan & uji API**. Token uji dipakai sekali dan tidak disimpan.
5. Status **Uji berhasil** membuktikan endpoint partner merespons. Untuk mengakses data seller, lanjutkan pengaturan callback, izin, dan otorisasi seller. TikTok Shop masih memerlukan Service ID pada environment untuk alur otorisasi seller.

Nilai rahasia yang telah disimpan tidak ditampilkan kembali di browser. Kosongkan kolom rahasia saat mengubah ID tanpa mengganti key. Hasil uji dan waktunya ditampilkan pada kartu platform.

## Konfigurasi server

Isi konfigurasi umum berikut pada environment PHP/Apache atau service manager. Kredensial platform dapat diisi lewat form administrator di atas atau environment. Jangan menaruh nilainya di Git, file frontend, query string, screenshot, atau log.

Wajib umum:

- `JL_APP_BASE_URL` — base URL canonical yang dipercaya. Untuk document root public, misalnya `https://staging.example.com`; untuk XAMPP legacy dengan document root parent, misalnya `http://localhost/jago-laba`.
- `JL_DOCUMENT_ROOT_PUBLIC` — gunakan true bila document root web-server sudah menunjuk ke public; gunakan false hanya untuk layout legacy lokal yang memang membutuhkan /public.
- `JL_MARKETPLACE_TOKEN_ENCRYPTION_KEY` — base64 dari 32 byte acak untuk enkripsi autentikasi. Buat satu key per environment dan simpan di secret manager.

Pada XAMPP Windows, bila `SetEnv` Apache belum terbaca oleh PHP, aplikasi juga dapat membaca kunci yang sama dari `C:/xampp/apache/conf/extra/jago-laba-marketplace.key` (di luar `htdocs`). Simpan hanya nilai base64 32 byte di file tersebut. Untuk lokasi lain, set `JL_MARKETPLACE_TOKEN_ENCRYPTION_KEY_FILE` ke path absolut. Jangan membuat kunci baru jika sudah ada token terenkripsi dengan kunci lama.

Shopee (opsional bila sudah diisi melalui form):

- `JL_SHOPEE_PARTNER_ID`
- `JL_SHOPEE_PARTNER_KEY`
- `JL_SHOPEE_REGION` — `live` atau `sandbox` sesuai Partner Center.
- `JL_SHOPEE_AUTH_URL` — opsional; default live menggunakan `https://open.shopee.com/auth`.
- `JL_SHOPEE_API_BASE_URL` — opsional; default `https://partner.shopeemobile.com`.

TikTok Shop (`SERVICE_ID` masih diperlukan untuk otorisasi seller; App Key dan App Secret dapat diisi lewat form):

- `JL_TIKTOK_SHOP_APP_KEY`
- `JL_TIKTOK_SHOP_APP_SECRET`
- `JL_TIKTOK_SHOP_SERVICE_ID`
- `JL_TIKTOK_SHOP_MARKET`
- `JL_TIKTOK_SHOP_REGION` — `row` atau `us`.
- `JL_TIKTOK_SHOP_REQUIRED_SCOPES` — opsional, dipisahkan koma; default `seller.authorization.info`.
- `JL_TIKTOK_SHOP_AUTH_URL` — opsional; default mengikuti region seller authorization.
- `JL_TIKTOK_SHOP_API_BASE_URL` — opsional; default `https://open-api.tiktokglobalshop.com`.

Webhook opsional:

- `JL_MARKETPLACE_WEBHOOK_URL` — alamat HTTPS yang direncanakan untuk webhook setelah signature dan topic platform selesai dikonfigurasi. Endpoint legacy saat ini tidak mengakui event secara sukses, sehingga jangan mendaftarkannya sebagai webhook operasional sebelum adapter webhook resmi diaktifkan.

## URL yang didaftarkan

Untuk Laravel/shared hosting dengan document root public dan base URL `https://staging.example.com`, daftarkan callback persis berikut:

- Shopee: `https://staging.example.com/api/marketplace-oauth.php?platform=Shopee`
- TikTok Shop Seller: `https://staging.example.com/api/marketplace-oauth.php?platform=TikTok%20Shop`

Untuk contoh XAMPP legacy dengan JL_DOCUMENT_ROOT_PUBLIC=false, bentuknya tetap `http://localhost/jago-laba/public/api/marketplace-oauth.php?platform=Shopee` dan callback TikTok dengan `platform=TikTok%20Shop`. Platform dapat menolak localhost atau HTTP; gunakan HTTPS staging/tunnel yang dikonfigurasi manual dan didaftarkan di console. Aplikasi tidak membuka tunnel otomatis.

Webhook harus memakai URL HTTPS yang sama-sama sudah didaftarkan pada Partner Center/console platform. Karena handler webhook lama belum mengimplementasikan verifikasi signature per platform, statusnya tetap non-operasional dan tidak boleh dipakai untuk mengklaim event berhasil.

## Shopee Open Platform

1. Buat atau buka aplikasi di Open Platform Console.
2. Aktifkan seller authorization, isi domain redirect test/live sesuai environment, dan masukkan callback Shopee di atas.
3. Catat `partner_id` dan `partner_key` hanya ke secret manager.
4. Aktifkan izin minimum untuk identitas toko, produk/varian, dan pesanan sesuai endpoint yang akan dipakai; tunggu persetujuan aplikasi jika console memerlukannya.
5. Uji login seller. Jago Laba mengirim seller ke fixed authorization URL, menukar `code` + `shop_id` pada endpoint token, menandatangani request dengan HMAC-SHA256, lalu memanggil `shop/get_shop_info` sebelum menyimpan koneksi.

## TikTok Shop Partner Center

1. Buka `App & Service` dan buat aplikasi Public atau Custom sesuai distribusi. Untuk integrasi ERP/OMS, pilih kategori Connector bila tersedia.
2. Tentukan market dan seller type dengan benar; aktifkan API.
3. Konfigurasi Redirect URL TikTok di atas, service ID, dan webhook hanya setelah endpoint HTTPS serta signature siap.
4. Aktifkan scope seller yang diperlukan. Minimal `seller.authorization.info` diperlukan untuk Get Authorized Shops; produk, order, fulfillment, dan webhook memerlukan scope tambahan sesuai API reference.
5. Simpan App Key, App Secret, dan Service ID di secret manager. Jangan meminta penjual memasukkan App Secret atau access token.
6. Jago Laba memakai seller authorization `services.tiktokshop.com/open/authorize` atau domain US sesuai region, menukar code melalui token API, memastikan `user_type=0`, lalu memverifikasi toko lewat `/authorization/202309/shops` dengan signature dan header seller token.
7. Gunakan Development Shop/API Testing Tool untuk uji sandbox. Persetujuan/review Connector atau batas authorisasi seller tetap harus diselesaikan di Partner Center.

## Perilaku aplikasi

- State acak disimpan sebagai hash, terikat user/perusahaan/platform/return path, memiliki TTL, dan dikonsumsi sekali.
- Token disimpan terenkripsi di `marketplace_oauth_connections`; tabel legacy `marketplace_api_credentials` tidak dipakai untuk alur baru.
- Koneksi terverifikasi dipisahkan dari sinkronisasi. Setelah berhasil, job `products`, `variants`, dan `orders` masuk antrean; UI tidak mengklaim data sudah masuk sebelum worker resource selesai.
- Re-authorization memperbarui koneksi toko yang sama. Kunci unik `(platform, external_store_id)` mencegah toko diklaim dua perusahaan.
- Disconnect hanya memutus koneksi internal dan menghentikan pekerjaan baru; histori transaksi tidak dihapus dan pencabutan izin di platform belum diklaim otomatis.
