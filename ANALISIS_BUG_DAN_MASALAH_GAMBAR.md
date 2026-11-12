# Laporan Investigasi Bug & Analisis Masalah Gambar OwwCommerce

Dokumen ini berisi hasil analisis menyeluruh dari audit arsitektur dan kode sumber **OwwCommerce**, mencakup investigasi mendalam mengenai penyebab hilangnya file gambar thumbnail fisik di folder `wp-content/uploads/`, serta katalog lengkap seluruh temuan bug kritis, celah keamanan, dan anomali frontend/backend.

---

## 📑 Daftar Isi
1. [Investigasi Khusus: Hilangnya Data Gambar Thumbnail & File Fisik](#1-investigasi-khusus-hilangnya-data-gambar-thumbnail--file-fisik)
   - [Akar Penyebab Utama (Root Cause)](#akar-penyebab-utama-root-cause)
   - [Alur Kejadian Hilangnya File (Diagram)](#alur-kejadian-hilangnya-file-diagram)
   - [Rekomendasi Arsitektur Penanganan Gambar](#rekomendasi-arsitektur-penanganan-gambar)
2. [Katalog Seluruh Temuan Bug di Codebase](#2-katalog-seluruh-temuan-bug-di-codebase)
   - [Kategori Kritis (Critical - Transaksi & Integritas Data Toko)](#kategori-kritis-critical---transaksi--integritas-data-toko)
   - [Kategori Tinggi (High - Keamanan & Stabilitas Sistem)](#kategori-tinggi-high---keamanan--stabilitas-sistem)
   - [Kategori Sedang (Medium - UI / UX & Frontend JS)](#kategori-sedang-medium---ui--ux--frontend-js)
   - [Kategori Rendah (Low - Kebersihan Database & Edge Cases)](#kategori-rendah-low---kebersihan-database--edge-cases)
3. [Rencana Aksi Perbaikan (Action Plan & Next Steps)](#3-rencana-aksi-perbaikan-action-plan--next-steps)

---

## 1. Investigasi Khusus: Hilangnya Data Gambar Thumbnail & File Fisik

### Kondisi yang Dilaporkan:
- Di sisi web client / storefront, gambar thumbnail produk tiba-tiba hilang.
- Pemeriksaan pada folder fisik server (`/wp-content/uploads/`) mengonfirmasi bahwa **file gambar tersebut memang benar-benar hilang/terhapus dari disk**.

---

### Akar Penyebab Utama (Root Cause)

Berdasarkan penelusuran seluruh file PHP dan JavaScript di dalam plugin OwwCommerce, **plugin OwwCommerce sendiri tidak memiliki fungsi yang menjalankan perintah `unlink()` atau `wp_delete_attachment()` pada media produk**. 

Lalu mengapa file fisik di `wp-content/uploads/` bisa hilang? Ditemukan kombinasi dari 3 faktor struktural berikut:

#### 1. Masalah Utama: "Unattached Media" & Automated Cleaning Plugin / WordPress Media Cleanup
* **Mekanisme WordPress**: Ketika gambar diunggah melalui Media Uploader (`wp.media`), WordPress mendaftarkan entri di tabel `wp_posts` dengan `post_type = 'attachment'`. Di sistem standar seperti WooCommerce atau pos blog biasa, attachment tersebut diikat ke pos induk melalui `post_parent` atau meta key `_thumbnail_id` di `wp_postmeta`.
* **Kelemahan OwwCommerce**: OwwCommerce dibangun dengan filosofi *Pure Custom Table* (`wp_oww_products`) dan hanya menyimpan string URL teks di kolom `image_url` (contoh: `https://example.com/wp-content/uploads/2026/11/produk-300x300.jpg`). OwwCommerce **tidak pernah mendaftarkan relasi attachment ID ke postmeta ataupun `post_parent`**.
* **Pemicu Penghapusan Fisik**:
  1. Di dashboard `Media > Library`, seluruh gambar produk OwwCommerce berstatus **"Unattached" (Tidak terhubung ke konten mana pun)**.
  2. Jika situs menggunakan plugin optimasi/pembersih media (seperti *Media Cleaner*, *WP-Optimize*, *Clean Up Optimizer*, *Advanced Database Cleaner*, dll.) atau administrator situs melakukan pembersihan berkala dengan menekan *"Delete Unattached Media"*, tool tersebut memeriksa `wp_posts` dan `wp_postmeta`.
  3. Karena ID media tidak ditemukan di pos WordPress mana pun, tool/admin menganggap file tersebut adalah file sisa/sampah yang tidak terpakai, lalu menjalankan fungsi `wp_delete_attachment()` yang **menghapus file fisik di `wp-content/uploads/` secara permanen**.

---

#### 2. Hardcoded Intermediate Size URL (`attachment.sizes.medium.url`) & Regenerasi Thumbnail
* **Lokasi Kode**: [`assets/js/admin-products.js`](file:///media/rasyiqi/7653717A1C07B131/mamasar/owwcommerce/assets/js/admin-products.js#L705-L709)
  ```javascript
  // assets/js/admin-products.js baris 706-708
  const imgUrl = (attachment.sizes && attachment.sizes.medium)
      ? attachment.sizes.medium.url
      : attachment.url;
  ```
* **Dampak**: 
  * Nilai yang disimpan ke database adalah URL gambar resize intermediate, misalnya: `sepatu-300x300.jpg`.
  * Jika suatu saat pengguna menginstal plugin seperti *Regenerate Thumbnails*, mengubah ukuran thumbnail di `Settings > Media`, mengganti tema, atau mengaktifkan konversi format gambar (misal WebP/AVIF via plugin caching/CDN), WordPress akan **menghapus semua file thumbnail resize intermediate lama** untuk digantikan dengan ukuran/format baru.
  * URL `sepatu-300x300.jpg` yang disimpan secara hardcoded di tabel `wp_oww_products` langsung menjadi `404 Not Found` (file fisik di folder upload hilang/berganti nama).

---

#### 3. Overwrite Nilai Kosong (`NULL`) di Form Edit Produk
* **Lokasi Kode**: [`includes/Api/ProductsController.php`](file:///media/rasyiqi/7653717A1C07B131/mamasar/owwcommerce/includes/Api/ProductsController.php#L257-L260)
  ```php
  if ( isset( $data['image_url'] ) ) {
      $update_data['image_url'] = esc_url_raw( $data['image_url'] ) ?: null;
  }
  ```
* **Dampak**:
  * Saat membuka form edit produk di admin, jika REST request lambat atau field `#prod-image` belum terisi lalu admin menekan tombol "Simpan", payload yang terkirim berisi `image_url: ""`.
  * Server langsung mengubah kolom `image_url` di database menjadi `NULL`, menghilangkan referensi gambar dari produk tersebut.

---

#### 4. Penimpaan oleh WooCommerce Importer (Jika Ada Migrasi Ulang)
* **Lokasi Kode**: [`includes/WooCommerce/ProductImporter.php`](file:///media/rasyiqi/7653717A1C07B131/mamasar/owwcommerce/includes/WooCommerce/ProductImporter.php#L62-L66)
  ```php
  $thumbnail_id = get_post_thumbnail_id( $wc_id );
  $image_url    = $thumbnail_id ? wp_get_attachment_url( $thumbnail_id ) : '';
  ...
  if ( $existing ) {
      $wpdb->update( $wpdb->prefix . 'oww_products', $data, [ 'id' => $existing ] );
  }
  ```
* **Dampak**: Jika proses import/migrasi WooCommerce dijalankan ulang, produk OwwCommerce yang sudah memiliki gambar kustom akan ditimpa menjadi string kosong jika di WooCommerce produk tersebut tidak memiliki thumbnail.

---

### Alur Kejadian Hilangnya File (Diagram)

```
[Admin Mengunggah Gambar via wp.media]
                 │
                 ▼
[File disimpan di wp-content/uploads/ & dibuatkan entri attachment di wp_posts]
                 │
                 ▼
[OwwCommerce hanya menyimpan URL 'image-300x300.jpg' di custom table wp_oww_products]
                 │
  (Tidak ada meta _thumbnail_id & tidak ada post_parent di wp_posts/wp_postmeta)
                 │
                 ▼
[WordPress menandai Attachment sebagai "Unattached"]
                 │
                 ├──► Skenario 1: Admin / Plugin Pembersih (Media Cleaner, WP-Optimize) 
                 │    menghapus "Unattached media" ──► File fisik di uploads/ DIHAPUS PERMANEN
                 │
                 ├──► Skenario 2: Regenerate Thumbnails dijalankan
                 │    ──► File intermediate *-300x300.jpg dihapus & diganti ──► URL 404
                 │
                 └──► Skenario 3: Form edit produk disimpan saat input kosong
                      ──► Nilai image_url di DB ditimpa menjadi NULL
```

---

### Rekomendasi Arsitektur Penanganan Gambar

Agar masalah ini selesai secara permanen dan memenuhi standar WordPress modern:

1. **Simpan `image_id` (Attachment ID) di Database**:
   * Tambahkan kolom `image_id bigint(20) unsigned DEFAULT NULL` pada tabel `wp_oww_products`.
   * Pada saat pemilihan gambar di `admin-products.js`, kirimkan `attachment.id` ke backend.
2. **Gunakan Fungsi Native WordPress untuk URL & Responsif**:
   * Ambil gambar di frontend menggunakan `wp_get_attachment_image( $product->image_id, 'medium' )` atau `wp_get_attachment_image_url( $product->image_id, 'large' )`.
   * Ini secara otomatis mendukung `srcset` (responsive image) dan tetap bekerja meskipun thumbnail di-regenerate.
3. **Proteksi dari Plugin Pembersih Media (Media Cleaner Hook)**:
   * Tambahkan filter WordPress untuk memberitahu plugin pembersih bahwa media tersebut sedang digunakan:
     ```php
     add_filter( 'wpmc_is_used', function( $is_used, $attachment_id ) {
         global $wpdb;
         $exists = $wpdb->get_var( $wpdb->prepare(
             "SELECT id FROM {$wpdb->prefix}oww_products WHERE image_id = %d LIMIT 1",
             $attachment_id
         ) );
         return $exists ? true : $is_used;
     }, 10, 2 );
     ```
4. **Cegah Penghapusan Tidak Sengaja (`wp_delete_attachment`)**:
   * Hook ke `delete_attachment` untuk mengosongkan referensi atau mencegah penghapusan jika masih terikat pada produk aktif.
5. **Simpan URL Asli (Full/Master) sebagai Fallback**:
   * Jika menyimpan URL, selalu simpan URL original/full, bukan file intermediate resize (`-300x300.jpg`).

---

## 2. Katalog Seluruh Temuan Bug di Codebase

Audit menyeluruh terhadap seluruh modul OwwCommerce menemukan beberapa bug dengan tingkat keparahan yang bervariasi:

### Kategori Kritis (Critical - Transaksi & Integritas Data Toko)

#### 🔴 Bug 1: Variasi Produk Diabaikan Total di Proses Checkout
* **Lokasi File**: 
  - [`includes/Api/CheckoutController.php`](file:///media/rasyiqi/7653717A1C07B131/mamasar/owwcommerce/includes/Api/CheckoutController.php#L83-L111) (baris 83–111 & 220)
  - [`includes/Database/Installer.php`](file:///media/rasyiqi/7653717A1C07B131/mamasar/owwcommerce/includes/Database/Installer.php#L87-L97) (skema tabel `wp_oww_order_items`)
  - [`includes/Models/OrderItem.php`](file:///media/rasyiqi/7653717A1C07B131/mamasar/owwcommerce/includes/Models/OrderItem.php#L7-L34)
* **Deskripsi Masalah**:
  * Modul keranjang (`CartController`) mendukung `variation_id`, namun di `CheckoutController::process_checkout()`, kalkulasi harga dan stok hanya mengambil data produk induk (`$product = $this->product_repo->find( $item['product_id'] );`).
  * Harga variasi yang berbeda tidak dihitung (pembeli variasi mahal hanya membayar harga dasar).
  * Pengurangan stok dilakukan pada produk induk, bukan pada tabel `wp_oww_product_variations`.
  * Tabel `wp_oww_order_items` dan entitas `OrderItem` sama sekali tidak memiliki kolom `variation_id`, sehingga pemilik toko tidak mengetahui variasi (warna, ukuran, dsb.) apa yang dibeli oleh pelanggan.

---

#### 🔴 Bug 2: Kalkulasi Ongkos Kirim Rusak Fatal di Backend (Undefined Array Key)
* **Lokasi File**:
  - [`includes/Shipping/Methods/FlatRate.php`](file:///media/rasyiqi/7653717A1C07B131/mamasar/owwcommerce/includes/Shipping/Methods/FlatRate.php#L34-L36) (baris 35)
  - [`includes/Shipping/Methods/FreeShipping.php`](file:///media/rasyiqi/7653717A1C07B131/mamasar/owwcommerce/includes/Shipping/Methods/FreeShipping.php#L36-L38) (baris 37)
* **Deskripsi Masalah**:
  * Di `FlatRate.php`, perhitungan ambang batas gratis ongkir menggunakan `$item['price'] * $item['qty']`.
  * Di `FreeShipping.php`, pengecekan minimum order menggunakan `$item['price'] * $item['quantity']`.
  * **Kenyataan**: Sesi keranjang (`Cart.php`) hanya menyimpan array item: `['product_id' => ..., 'variation_id' => ..., 'qty' => ...]`. **Tidak ada kunci `'price'` dan tidak ada kunci `'quantity'`**.
  * **Akibat**: Pada PHP 8.1+, kode memicu *PHP Warning: Undefined array key "price"*, subtotal kalkulasi selalu bernilai `0`, dan ambang batas Gratis Ongkir (*Free Shipping*) tidak akan pernah tercapai atau salah perhitungan di backend.

---

#### 🔴 Bug 3: Guest Checkout 100% Gagal Mengakses Halaman "Order Received" (Akses Ditolak / 403)
* **Lokasi File**:
  - [`includes/Payment/Gateways/BACS.php`](file:///media/rasyiqi/7653717A1C07B131/mamasar/owwcommerce/includes/Payment/Gateways/BACS.php#L43) (baris 43)
  - [`includes/Payment/Gateways/COD.php`](file:///media/rasyiqi/7653717A1C07B131/mamasar/owwcommerce/includes/Payment/Gateways/COD.php#L43) (baris 43)
  - [`assets/js/checkout.js`](file:///media/rasyiqi/7653717A1C07B131/mamasar/owwcommerce/assets/js/checkout.js#L183-L190) (baris 183–190)
  - [`templates/frontend/order-received.php`](file:///media/rasyiqi/7653717A1C07B131/mamasar/owwcommerce/templates/frontend/order-received.php#L31-L40) (baris 31–40)
* **Deskripsi Masalah**:
  * Gateway BACS dan COD mengembalikan `'redirect_url' => home_url( '/checkout/order-received/' . $order_id )` (tanpa query parameter `?key=`).
  * Di `checkout.js`, script memeriksa `if (data.order && data.order.redirect_url) window.location.href = data.order.redirect_url;`.
  * Di `order-received.php`, terdapat proteksi otorisasi: pengguna harus login atau menyertakan `$_GET['key']` yang valid.
  * **Akibat**: Semua pembeli berstatus Guest (tamu tanpa login) yang bertransaksi dengan Transfer Bank atau COD akan langsung disambut pesan error WordPress: `wp_die( 'Anda tidak diizinkan mengakses halaman pesanan ini.' )` (HTTP 403) segera setelah menyelesaikan checkout!

---

#### 🔴 Bug 4: Manipulasi Kuantitas Negatif di Keranjang Belanja
* **Lokasi File**:
  - [`includes/Api/CartController.php`](file:///media/rasyiqi/7653717A1C07B131/mamasar/owwcommerce/includes/Api/CartController.php#L112) (baris 112)
  - [`includes/Repositories/ProductRepository.php`](file:///media/rasyiqi/7653717A1C07B131/mamasar/owwcommerce/includes/Repositories/ProductRepository.php#L231-L236) (baris 231–236)
* **Deskripsi Masalah**:
  * Method `add_to_cart` menerima nilai parameter `qty` tanpa validasi bilangan bulat positif (`$qty > 0`).
  * Jika penyerang mengirimkan `qty: -5`, nilai total keranjang akan berkurang (bisa dimanfaatkan untuk diskon ilegal atau total negatif).
  * Pada saat checkout dieksekusi, query pengurangan stok: `UPDATE ... SET stock_qty = stock_qty - (-5)` justru akan **menambah stok produk** di database toko.

---

### Kategori Tinggi (High - Keamanan & Stabilitas Sistem)

#### 🟠 Bug 5: Endpoint Unauthenticated untuk Flush Rewrite Rules (Potensi DoS)
* **Lokasi File**: [`includes/Frontend/Router.php`](file:///media/rasyiqi/7653717A1C07B131/mamasar/owwcommerce/includes/Frontend/Router.php#L28-L40) (baris 31–38)
* **Deskripsi Masalah**:
  ```php
  $force_flush = isset($_GET['owwc_debug']) && $_GET['owwc_debug'] === 'flush';
  if ( $current_base !== $stored_base || $force_flush ) {
      flush_rewrite_rules(true);
      ...
      wp_die('OwwCommerce: Rewrite rules flushed successfully...');
  }
  ```
  * Parameter `/?owwc_debug=flush` dapat diakses oleh siapa pun di internet tanpa autentikasi admin dan tanpa verifikasi Nonce.
  * `flush_rewrite_rules()` adalah operasi I/O database yang sangat berat di WordPress. Penyerang dapat melakukan request berulang kali (HTTP Flood) untuk menghabiskan CPU server (Denial of Service).

---

#### 🟠 Bug 6: File Debug Sensitif dengan Eksekusi Shell di Direktori Root Plugin
* **Lokasi File**: 
  - [`debug-404.php`](file:///media/rasyiqi/7653717A1C07B131/mamasar/owwcommerce/debug-404.php#L20) (baris 20)
  - [`debug-db.php`](file:///media/rasyiqi/7653717A1C07B131/mamasar/owwcommerce/debug-db.php)
* **Deskripsi Masalah**:
  * `debug-404.php` berisi perintah sistem:
    ```php
    $wp_load = shell_exec('find /home/rasyiqi/ -name wp-load.php -maxdepth 5 | head -n 1');
    ```
  * File ini mengekspos jalur absolut server, struktur direktori internal, dan memuat core WordPress secara publik jika diakses melalui browser. File ini harus dihapus dari rilis produksi.

---

#### 🟠 Bug 7: Method `to_array()` pada Model Order Tidak Menyertakan `total_amount`
* **Lokasi File**: [`includes/Models/Order.php`](file:///media/rasyiqi/7653717A1C07B131/mamasar/owwcommerce/includes/Models/Order.php#L72-L91) (baris 73–90)
* **Deskripsi Masalah**:
  * Array return dari `Order::to_array()` tidak menyertakan `'total_amount' => $this->total_amount`.
  * Seluruh respons REST API pada [`OrderController.php`](file:///media/rasyiqi/7653717A1C07B131/mamasar/owwcommerce/includes/Api/OrderController.php#L93) dan [`CheckoutController.php`](file:///media/rasyiqi/7653717A1C07B131/mamasar/owwcommerce/includes/Api/CheckoutController.php#L268) yang menggunakan `to_array()` kehilangan field total harga pesanan.

---

### Kategori Sedang (Medium - UI / UX & Frontend JS)

#### 🟡 Bug 8: Tombol "Tambah ke Keranjang" Merusak Ikon SVG
* **Lokasi File**: [`assets/js/cart.js`](file:///media/rasyiqi/7653717A1C07B131/mamasar/owwcommerce/assets/js/cart.js#L209-L227) (baris 209–227)
* **Deskripsi Masalah**:
  * Saat add-to-cart berhasil, script mengeksekusi `addBtn.textContent = '✓ Ditambahkan';` lalu mengembalikan `addBtn.textContent = origText;`.
  * Properti `textContent` menimpa seluruh elemen DOM di dalam tombol, sehingga tag `<svg>` ikon keranjang yang ada di `product-card.php` dan `single-product.php` hilang permanen dan hanya tersisa teks polos setelah tombol diklik.

---

#### 🟡 Bug 9: Penumpukan Event Listener pada Tombol "Load More" Katalog Produk
* **Lokasi File**: [`assets/js/shop-load-more.js`](file:///media/rasyiqi/7653717A1C07B131/mamasar/owwcommerce/assets/js/shop-load-more.js#L49-L53) (baris 49–53)
* **Deskripsi Masalah**:
  * Setiap kali produk baru dimuat, script memanggil `window.owwcCart.init()`.
  * Di dalam `cart.js`, `init()` mendaftarkan event listener baru ke `document.body`.
  * Karena listener menggunakan event delegation pada `document.body`, pemanggilan ulang `init()` melipatgandakan event listener. Jika pengguna menekan "Load More" 3 kali, 1 klik "Tambah ke Keranjang" akan memicu 4 request AJAX bersamaan.

---

#### 🟡 Bug 10: Styling Mock Prototyping Hardcoded pada Kartu Produk
* **Lokasi File**: [`templates/frontend/parts/product-card.php`](file:///media/rasyiqi/7653717A1C07B131/mamasar/owwcommerce/templates/frontend/parts/product-card.php#L8-L10) (baris 8–10)
* **Deskripsi Masalah**:
  ```php
  if ( stripos( $product->title, 'car' ) !== false ) $hover_bg = '#FFF9E3';
  if ( stripos( $product->title, 'glass' ) !== false ) $hover_bg = '#F0F4F8';
  ```
  * Terdapat kode styling mock sisa tahap awal yang mengubah warna latar belakang kartu berdasarkan kata "car" atau "glass" pada judul produk.

---

### Kategori Rendah (Low - Kebersihan Database & Edge Cases)

#### 🟢 Bug 11: Data Yatim (Orphan Records) Saat Menghapus Produk
* **Lokasi File**: [`includes/Repositories/ProductRepository.php`](file:///media/rasyiqi/7653717A1C07B131/mamasar/owwcommerce/includes/Repositories/ProductRepository.php#L212-L217) (baris 212–217)
* **Deskripsi Masalah**:
  * Method `delete( $id )` menghapus produk dan variasi, namun tidak membersihkan ulasan terkait di tabel `wp_oww_reviews` atau relasi kategori `wp_oww_product_categories`.

---

## 3. Rencana Aksi Perbaikan (Action Plan & Next Steps)

| No | Komponen | Tindakan Perbaikan | Tingkat Prioritas |
|---|---|---|---|
| 1 | **Media & Thumbnail** | Tambah kolom `image_id` ke `wp_oww_products`, simpan Attachment ID dari `wp.media`, proteksi file fisik dari penghapusan via filter `wpmc_is_used` dan `delete_attachment`, serta gunakan `wp_get_attachment_image_url()` alih-alih hardcoded medium size. | **Sangat Mendesak** |
| 2 | **Checkout & Gateway** | Tambahkan `?key=` pada redirect URL gateway BACS & COD agar Guest checkout tidak mengalami 403 Forbidden di halaman `order-received`. | **Kritis** |
| 3 | **Kalkulasi Ongkir** | Perbaiki `FlatRate.php` & `FreeShipping.php` agar membaca harga dari repository produk dan membaca kuantitas dari field `qty` sesi keranjang. | **Kritis** |
| 4 | **Variasi Produk** | Tambahkan kolom `variation_id` pada tabel `wp_oww_order_items`, update `CheckoutController` untuk menghitung harga variasi dan memotong stok variasi yang bersangkutan. | **Kritis** |
| 5 | **Validasi Keranjang** | Validasi `qty > 0` pada `CartController::add_to_cart()` untuk mencegah manipulasi harga negatif dan penambahan stok liar. | **Tinggi** |
| 6 | **Keamanan & DoS** | Batasi `/?owwc_debug=flush` hanya untuk user dengan kapabilitas `manage_options` dan hapus file `debug-404.php` & `debug-db.php`. | **Tinggi** |
| 7 | **Model Serialization** | Tambahkan `'total_amount' => $this->total_amount` pada `Order::to_array()`. | **Tinggi** |
| 8 | **Frontend JS & UI** | Perbaiki `cart.js` agar hanya mengubah teks label tanpa menimpa elemen SVG, dan hilangkan re-binding ganda di `shop-load-more.js`. | **Sedang** |

---
*Laporan ini disusun secara otomatis berdasarkan penelaahan statis dan arsitektur repositori OwwCommerce.*
