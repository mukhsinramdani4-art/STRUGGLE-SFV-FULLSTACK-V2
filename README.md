# REVISI V2 — Akun Pelanggan, Checkout, Ongkir, Pembayaran, Google Maps

## Menjalankan
```
php -S localhost:8000        # dari folder proyek, lalu buka http://localhost:8000
```
Database SQLite (`data/struggle.sqlite`) dibuat/dimigrasi otomatis saat request pertama. Migrasi v2 **tidak menghapus** data lama
(produk, pelanggan, pesanan, karyawan, aset, keuangan); status lama diterjemahkan (Paid→Dibayar, Completed→Selesai, dst).
MySQL: skema dasar dari `database.sql`; kolom/tabel v2 ditambahkan otomatis oleh `api/schema.php` (belum diuji pada server MySQL nyata).

## Yang perlu Anda isi / ketahui
1. **Nama kelompok** → Admin › Pengaturan › "Nama Kelompok" (sementara tertulis `NAMA KELOMPOK`). Otomatis tampil di sidebar, topbar, profil, login admin, footer toko.
2. **Rekening / e-wallet / QRIS** → Admin › Pembayaran. Data bawaan hanyalah **contoh** (diberi label "contoh" ke pelanggan). COD nonaktif secara default.
3. **Asal pengiriman & tarif** → Admin › Pengaturan (asal) dan Admin › Pengiriman (tarif/zona). Ongkir = **estimasi/simulasi**, bukan API ekspedisi.
4. **Google Maps** → Admin › Pengaturan › API key (atau env `GOOGLE_MAPS_API_KEY`). Aktifkan Maps JavaScript, Places, Geocoding API dan batasi key ke domain Anda.
   Tanpa key peta tidak tampil dan **tidak dianggap aktif**; GPS browser & koordinat manual tetap berfungsi.
5. **Daftar nama mahasiswa** → Admin › Pelanggan › Kredensial Akun Kelas › "Ganti Nama" (tempel 39 nama, satu per baris).

## 39 akun pelanggan
- `pelanggan01` … `pelanggan39` (email `pelangganNN@sfv.studio`), dibuat tepat sekali (kunci: username + nomor urut), aman dijalankan ulang.
- Password awal acak 10 karakter, disimpan sebagai hash bcrypt. Salinan password awal hanya ada di tabel `customer_credentials` **sampai pelanggan menggantinya**,
  dan hanya bisa dilihat/diunduh (CSV) oleh Owner/Super Admin/Manager lewat Admin › Pelanggan › Kredensial Akun Kelas (setiap akses dicatat di log aktivitas).
- Login dibatasi (5 gagal → kunci 5 menit, tersimpan di database). Pelanggan diminta mengganti password pada login pertama. Admin dapat reset password per akun.
- Pendaftaran akun baru dimatikan secara default (Pengaturan › "Izinkan pendaftaran").

## Alur pesanan
Status dipisah: **Pesanan** (Pending/Diproses/Dikemas/Dikirim/Selesai/Dibatalkan), **Pembayaran** (Belum Dibayar/Menunggu Verifikasi/Dibayar/Gagal/Dikembalikan),
**Pengiriman** (Menunggu Pengiriman/Diproses Kurir/Dalam Perjalanan/Diterima). Semua perubahan tercatat di riwayat.
- Harga, stok, ongkir, dan diskon dihitung ulang di server; nilai dari browser diabaikan.
- Pembayaran **tidak pernah otomatis lunas**: pelanggan unggah bukti → Menunggu Verifikasi → admin tandai Dibayar. Pemasukan ke Keuangan baru dicatat saat Dibayar (refund dicatat saat Dikembalikan).
- Pesanan tidak bisa dikirim/diselesaikan sebelum pembayaran terverifikasi (kecuali COD); status "Dalam Perjalanan" mewajibkan nomor resi (kecuali layanan instan).
- Pembatalan mengembalikan stok. Pelanggan hanya bisa melihat pesanan miliknya (ID milik orang lain → 404).
- Siap payment gateway: tabel `payments` punya `provider` & `gateway_ref`; tambahkan endpoint callback yang memverifikasi signature lalu memakai logika yang sama dengan verifikasi manual (lihat komentar di `api/payments.php`).

## Perubahan penting lain
- Data produk/kategori/koleksi/kupon **tidak lagi statis** di `assets/js/products.js`; seluruhnya dimuat dari API. Footer (telepon, WA, IG, link, nama kelompok) dari Pengaturan.
- Keranjang tidak lagi memakai ongkir tetap; ongkir dihitung di checkout. Harga varian lengan kini ikut terhitung.
- Hapus: akun demo pelanggan hardcode, password di JS, petunjuk kredensial publik di login (storefront & admin). Email/password admin **tidak diubah**.
- Semua request non-GET wajib header `X-Requested-With` (perlindungan CSRF). Bukti bayar disimpan di `data/proofs` (tidak publik) dan hanya dapat diakses pemilik/admin.
- Berat produk (gram) ditambahkan di form produk admin untuk perhitungan ongkir.
- Halaman transaksi baru (checkout, pesanan, alamat, akun) berbahasa Indonesia saja; navigasi lain tetap mengikuti sakelar EN/ID.

---
# STRUGGLE / SFV STUDIO — Storefront + Admin ERP (Full-Stack)

Proyek ini adalah gabungan **toko online (storefront)** STRUGGLE/SFV STUDIO
dengan **panel admin ERP** (sebelumnya `sfv-admin`), disatukan lewat satu
lapisan **API PHP + database**. Produk yang ditambahkan lewat admin akan
langsung tampil di halaman user, dan setiap transaksi checkout di toko akan
langsung tercatat di dashboard admin (termasuk otomatis masuk ke modul
Keuangan sebagai pemasukan).

Sistem ini didesain **anti-gagal**: database (SQLite) dibuat & diisi data
otomatis saat pertama kali dijalankan — tidak perlu import apa pun untuk mulai
memakainya. Bagian storefront juga tetap tampil normal memakai data bawaan
walaupun server backend belum aktif, sehingga tidak ada tampilan yang rusak/kosong.

---

## 1. Cara Menjalankan (Paling Cepat — Lokal)

Butuh PHP 8.1+ (dengan ekstensi `pdo_sqlite`, biasanya sudah aktif secara
default). Dari dalam folder proyek ini, jalankan:

```bash
php -S localhost:8000
```

Lalu buka:
- **Toko (user)** → http://localhost:8000/index.html
- **Admin ERP** → http://localhost:8000/admin/login.php

Selesai. Database `data/struggle.sqlite` akan otomatis dibuat dan diisi 24
produk contoh, 5 kategori, kupon, serta data ERP contoh (aset, karyawan,
transaksi keuangan) pada request pertama.

### Menjalankan lewat XAMPP / Laragon / cPanel (tanpa terminal)
Cukup salin **seluruh isi folder ini** ke `htdocs` (XAMPP) atau `public_html`
(cPanel), lalu buka `index.html` / `admin/login.php` lewat browser seperti
biasa. Tidak ada langkah instalasi database tambahan (kecuali Anda memang
ingin memakai MySQL, lihat bagian 5).

> **Penting (keamanan):** pastikan folder `data/` tidak bisa diakses langsung
> dari browser (mis. `namadomain.com/data/struggle.sqlite`). File
> `data/.htaccess` sudah disertakan untuk memblokir ini otomatis di Apache.
> Jika hosting Anda memakai Nginx, tambahkan aturan serupa di konfigurasi
> server (larang akses ke folder `data/` kecuali `data/uploads/`).

---

## 2. Login Admin (Akun Demo)

Buka `/admin/login.php`. Semua akun demo memakai password yang sama:

| Email                  | Role         | Akses Modul                                      |
|-------------------------|--------------|---------------------------------------------------|
| owner@sfv.demo         | Owner        | Semua modul                                        |
| admin@sfv.demo         | Super Admin  | Semua modul                                        |
| manager@sfv.demo       | Manager      | Semua modul kecuali Pengaturan                     |
| gudang@sfv.demo        | Gudang       | Dashboard, Produk+, Pesanan                        |
| kasir@sfv.demo         | Kasir        | Dashboard, Pesanan, Pelanggan                      |
| marketing@sfv.demo     | Marketing    | Dashboard, Produk+, Kategori, Pelanggan, Kupon      |
| finance@sfv.demo       | Finance      | Dashboard, Keuangan                                |

**Password untuk semua akun di atas: `sfv-demo`**

⚠️ **Wajib diganti sebelum dipakai produksi.** Tambah/hapus akun staff lewat
menu **Pengaturan** (hanya bisa diakses Owner/Super Admin).

---

## 3. Struktur Folder

```
├── index.html, product.html, cart.html, ...   → Halaman toko (frontend user)
├── assets/                                     → CSS/JS/gambar toko
│   └── js/products.js  → berisi data produk bawaan + "Catalog" (sinkron ke API)
│
├── admin/                                       → Panel Admin ERP (PHP)
│   ├── login.php, logout.php, config.php        → Autentikasi & RBAC
│   ├── index.php        → Dashboard umum (statistik, grafik, order terbaru)
│   ├── products.php     → Produk+ (tambah/ubah/hapus produk toko)
│   ├── categories.php   → Kategori produk
│   ├── orders.php       → Pesanan masuk dari toko
│   ├── customers.php    → Data pelanggan terdaftar
│   ├── coupons.php      → Kupon & promo
│   ├── assets.php       → Aset perusahaan (peralatan, kendaraan, dst)
│   ├── sdm.php          → SDM / data karyawan
│   ├── keuangan.php     → Keuangan (pemasukan/pengeluaran + grafik P&L)
│   ├── settings.php     → Kelola akun staff
│   └── assets/          → CSS/JS khusus admin (desain selaras brand toko)
│
├── api/                                          → REST API (dipakai admin & toko)
│   ├── db.php            → Koneksi DB + auto-migrasi + auto-seed
│   ├── bootstrap.php      → Helper request/response JSON
│   ├── products.php, categories.php, orders.php, customers.php,
│   │   coupons.php, assets.php, employees.php, finance.php,
│   │   dashboard.php, auth.php, upload.php
│
├── data/                                          → Database & file upload
│   ├── struggle.sqlite    → Dibuat otomatis (JANGAN dihapus setelah berjalan)
│   ├── seed_products.json → Data awal untuk seeding pertama kali
│   └── uploads/products/  → Gambar produk yang diunggah lewat admin
│
└── database.sql                                   → Skema MySQL (opsional, untuk hosting produksi)
```

---

## 4. Alur Kerja Utama

- **Tambah produk baru** → Admin buka **Produk+ → Tambah Produk**, isi nama,
  harga, kategori, ukuran, warna, upload gambar → klik simpan → produk
  langsung muncul di halaman toko (Home, Kategori, Best Seller, dst) tanpa
  perlu deploy ulang.
- **Pelanggan checkout di toko** → order otomatis tersimpan ke database dan
  langsung muncul di **Admin → Pesanan**, stok produk otomatis berkurang, dan
  nilainya otomatis tercatat sebagai pemasukan di **Admin → Keuangan**.
- **Kelola aset kantor/toko** → Admin → Aset (nilai beli, nilai sekarang,
  kondisi, lokasi, penanggung jawab).
- **Kelola karyawan** → Admin → SDM (jabatan, departemen, gaji, status aktif).
- **Pantau keuangan** → Admin → Keuangan menampilkan grafik pemasukan vs
  pengeluaran 6 bulan terakhir serta laba bersih, dan bisa mencatat transaksi
  manual (mis. sewa toko, gaji, produksi, marketing).

---

## 5. (Opsional) Pindah ke MySQL untuk Hosting Produksi

Default sistem memakai **SQLite** (satu file, tanpa server database
terpisah) — cukup untuk kebanyakan penggunaan dan hosting kecil-menengah.
Jika Anda ingin pakai MySQL/MariaDB (misalnya di cPanel dengan banyak
pengunjung):

1. Buat database baru di phpMyAdmin, lalu import file **`database.sql`**.
2. Buka `api/db.php`, ubah baris paling atas:
   ```php
   const DB_DRIVER = 'sqlite';   // ganti jadi:
   const DB_DRIVER = 'mysql';
   ```
   lalu isi `MYSQL_HOST`, `MYSQL_NAME`, `MYSQL_USER`, `MYSQL_PASS` sesuai
   kredensial hosting Anda (tepat di bawah baris `DB_DRIVER`).
3. Selesai — seluruh endpoint di `/api` otomatis berpindah ke MySQL.

`database.sql` sudah berisi skema lengkap (produk, pesanan, kategori,
kupon, aset, SDM, keuangan, akun staff) beserta 7 akun admin demo yang sama
seperti di atas. Data contoh 24 produk **tidak** disertakan di file SQL agar
ringkas — jalankan dulu di SQLite, lalu ekspor tabel `products` (dan
lainnya) ke CSV untuk diimpor ke MySQL, atau cukup input ulang lewat
Admin → Produk+ setelah pindah ke MySQL.

---

## 6. Catatan Perbaikan Saat Penggabungan

Beberapa perbaikan dilakukan saat menggabungkan kedua proyek:
- **Bug alamat pengiriman hilang saat checkout** — pada kode asli, objek
  checkout memiliki dua key `shipping` yang saling menimpa sehingga alamat
  pelanggan tidak pernah tersimpan. Sudah diperbaiki (`shippingAddress`,
  `shippingFee` dipisah jadi nama unik) dan sekarang alamat lengkap
  tersimpan & tampil di detail pesanan admin.
- **Semua path gambar diverifikasi** — tidak ada gambar yang hilang/rusak;
  1 file CSS admin lama yang sudah tidak terpakai (peninggalan desain admin
  statis sebelumnya) dihapus agar tidak membingungkan.
- Modul admin `sfv-admin` sebelumnya baru berupa kerangka (banyak menu
  "belum tersedia"); pada proyek gabungan ini seluruh modul yang diminta
  (Dashboard, Produk+, Aset, SDM, Keuangan, plus Kategori/Pesanan/
  Pelanggan/Kupon/Pengaturan) sudah dibangun **fungsional penuh** dengan
  CRUD nyata ke database, bukan lagi data dummy statis.

---

## 7. Keamanan Sebelum Go-Live

- [ ] Ganti password semua akun demo (`sfv-demo`) lewat menu Pengaturan.
- [ ] Pastikan folder `data/` tidak bisa diakses publik (lihat bagian 1).
- [ ] Jalankan situs lewat HTTPS di hosting produksi.
- [ ] Backup berkala file `data/struggle.sqlite` (atau database MySQL bila
      dipakai).
