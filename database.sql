-- CATATAN V2: tabel/kolom baru (settings, customer_addresses, payments, order_events,
-- payment_methods, shipping_*, kolom tambahan customers/orders/products) dibuat & di-seed
-- otomatis oleh api/schema.php saat request pertama, termasuk pada MySQL.
-- =============================================================================
-- STRUGGLE / SFV STUDIO — Skema Database MySQL (untuk deployment produksi)
-- =============================================================================
-- Sistem ini SECARA DEFAULT berjalan memakai SQLite (file tunggal, otomatis
-- dibuat & di-migrasi sendiri oleh api/db.php) — cocok untuk development atau
-- hosting kecil, TANPA perlu menjalankan file ini sama sekali.
--
-- Import file ini HANYA jika Anda ingin deploy ke hosting dengan MySQL/MariaDB
-- (mis. cPanel). Langkah:
--   1. Buat database baru di phpMyAdmin / cPanel, lalu import file ini.
--   2. Buka api/db.php, ubah baris:
--        const DB_DRIVER = 'sqlite';   ->   const DB_DRIVER = 'mysql';
--      lalu isi MYSQL_HOST / MYSQL_NAME / MYSQL_USER / MYSQL_PASS sesuai
--      kredensial hosting Anda.
--   3. Selesai — seluruh endpoint di /api otomatis memakai MySQL.
-- =============================================================================

CREATE DATABASE IF NOT EXISTS struggle_studio CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE struggle_studio;

-- ---------------------------------------------------------------------------
-- E-COMMERCE (storefront)
-- ---------------------------------------------------------------------------

CREATE TABLE categories (
  id INT PRIMARY KEY AUTO_INCREMENT,
  code VARCHAR(50) UNIQUE NOT NULL,
  name VARCHAR(150) NOT NULL,
  icon VARCHAR(50) DEFAULT 'checkroom',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE collections (
  id INT PRIMARY KEY AUTO_INCREMENT,
  code VARCHAR(50) UNIQUE NOT NULL,
  name VARCHAR(150) NOT NULL,
  description TEXT,
  image VARCHAR(255)
);

CREATE TABLE products (
  id INT PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(255) NOT NULL,
  price INT NOT NULL DEFAULT 0,
  original_price INT NULL,
  category VARCHAR(50),
  material VARCHAR(150),
  description TEXT,
  images JSON,
  colors JSON,
  sizes JSON,
  sleeves JSON,
  sleeve_prices JSON,
  badge VARCHAR(50),
  stock INT DEFAULT 0,
  is_new TINYINT(1) DEFAULT 0,
  is_best_seller TINYINT(1) DEFAULT 0,
  is_deal TINYINT(1) DEFAULT 0,
  collection VARCHAR(50),
  status VARCHAR(20) DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX (category), INDEX (status)
);

CREATE TABLE customers (
  id INT PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(150) UNIQUE NOT NULL,
  phone VARCHAR(30),
  password_hash VARCHAR(255),
  status VARCHAR(20) DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE addresses (
  id INT PRIMARY KEY AUTO_INCREMENT,
  customer_id INT,
  label VARCHAR(50),
  recipient_name VARCHAR(150),
  address TEXT,
  city VARCHAR(100),
  postal_code VARCHAR(20),
  is_default TINYINT(1) DEFAULT 0,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
);

CREATE TABLE orders (
  id VARCHAR(50) PRIMARY KEY,
  customer_id INT NULL,
  customer_name VARCHAR(150),
  customer_email VARCHAR(150),
  customer_phone VARCHAR(30),
  shipping_address TEXT,
  shipping_city VARCHAR(100),
  shipping_province VARCHAR(100),
  shipping_postal VARCHAR(20),
  payment_method VARCHAR(50),
  coupon_code VARCHAR(50),
  subtotal INT DEFAULT 0,
  discount INT DEFAULT 0,
  shipping_fee INT DEFAULT 0,
  total INT DEFAULT 0,
  status VARCHAR(30) DEFAULT 'Pending',
  payment_status VARCHAR(30) DEFAULT 'Unpaid',
  shipping_status VARCHAR(30) DEFAULT 'Pending',
  source VARCHAR(30) DEFAULT 'storefront',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL,
  INDEX (status), INDEX (created_at)
);

CREATE TABLE order_items (
  id INT PRIMARY KEY AUTO_INCREMENT,
  order_id VARCHAR(50) NOT NULL,
  product_id INT,
  product_name VARCHAR(255),
  color VARCHAR(50),
  size VARCHAR(20),
  qty INT DEFAULT 1,
  price INT DEFAULT 0,
  FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

CREATE TABLE coupons (
  id INT PRIMARY KEY AUTO_INCREMENT,
  code VARCHAR(50) UNIQUE NOT NULL,
  description VARCHAR(255),
  discount_type ENUM('percent','fixed','shipping') DEFAULT 'percent',
  discount_value INT DEFAULT 0,
  max_discount INT DEFAULT 0,
  min_purchase INT DEFAULT 0,
  valid_until DATE NULL,
  usage_limit INT DEFAULT 0,
  used_count INT DEFAULT 0,
  status VARCHAR(20) DEFAULT 'active'
);

-- ---------------------------------------------------------------------------
-- ERP / ADMIN (dipakai panel /admin)
-- ---------------------------------------------------------------------------

CREATE TABLE admin_users (
  id INT PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  email VARCHAR(150) UNIQUE NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  role VARCHAR(30) NOT NULL DEFAULT 'Staff',
  avatar VARCHAR(255),
  status VARCHAR(20) DEFAULT 'active',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE assets (
  id INT PRIMARY KEY AUTO_INCREMENT,
  asset_code VARCHAR(30) UNIQUE,
  name VARCHAR(255) NOT NULL,
  category VARCHAR(50) DEFAULT 'Peralatan',
  location VARCHAR(150),
  assigned_to VARCHAR(150),
  purchase_date DATE,
  purchase_value INT DEFAULT 0,
  current_value INT DEFAULT 0,
  condition_status VARCHAR(30) DEFAULT 'Baik',
  status VARCHAR(20) DEFAULT 'Aktif',
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE employees (
  id INT PRIMARY KEY AUTO_INCREMENT,
  name VARCHAR(150) NOT NULL,
  position VARCHAR(100),
  department VARCHAR(100),
  email VARCHAR(150),
  phone VARCHAR(30),
  join_date DATE,
  salary INT DEFAULT 0,
  status VARCHAR(20) DEFAULT 'Aktif',
  address TEXT,
  notes TEXT,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE finance_transactions (
  id INT PRIMARY KEY AUTO_INCREMENT,
  type ENUM('income','expense') NOT NULL,
  category VARCHAR(100),
  amount INT DEFAULT 0,
  description VARCHAR(255),
  trx_date DATE DEFAULT (CURRENT_DATE),
  related_order_id VARCHAR(50),
  created_by VARCHAR(150),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX (type), INDEX (trx_date)
);

CREATE TABLE activity_log (
  id INT PRIMARY KEY AUTO_INCREMENT,
  actor VARCHAR(150),
  action VARCHAR(100),
  detail VARCHAR(255),
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- ---------------------------------------------------------------------------
-- SEED DATA MINIMAL — akun admin default.
-- Password untuk SEMUA akun di bawah: sfv-demo
-- (hash bcrypt berikut dibuat dari password_hash('sfv-demo', PASSWORD_BCRYPT))
-- ---------------------------------------------------------------------------
INSERT INTO admin_users (name, email, password_hash, role) VALUES
('Rania', 'owner@sfv.demo', '$2y$10$PNufSzBpF/ECxffsE27ATu0wQ.kxr1frfjcBmTAPSYaMG48RRZVZi', 'Owner'),
('Admin Utama', 'admin@sfv.demo', '$2y$10$PNufSzBpF/ECxffsE27ATu0wQ.kxr1frfjcBmTAPSYaMG48RRZVZi', 'Super Admin'),
('Bayu', 'manager@sfv.demo', '$2y$10$PNufSzBpF/ECxffsE27ATu0wQ.kxr1frfjcBmTAPSYaMG48RRZVZi', 'Manager'),
('Doni', 'gudang@sfv.demo', '$2y$10$PNufSzBpF/ECxffsE27ATu0wQ.kxr1frfjcBmTAPSYaMG48RRZVZi', 'Gudang'),
('Sinta', 'kasir@sfv.demo', '$2y$10$PNufSzBpF/ECxffsE27ATu0wQ.kxr1frfjcBmTAPSYaMG48RRZVZi', 'Kasir'),
('Mira', 'marketing@sfv.demo', '$2y$10$PNufSzBpF/ECxffsE27ATu0wQ.kxr1frfjcBmTAPSYaMG48RRZVZi', 'Marketing'),
('Fikri', 'finance@sfv.demo', '$2y$10$PNufSzBpF/ECxffsE27ATu0wQ.kxr1frfjcBmTAPSYaMG48RRZVZi', 'Finance');

INSERT INTO categories (code, name, icon) VALUES
('kaos','Kaos','checkroom'),
('kemeja','Kemeja','styler'),
('outer','Outer','dry_cleaning'),
('celana_panjang','Celana Panjang','straighten'),
('celana_pendek','Celana Pendek','content_cut');

-- CATATAN: data produk (24 item) & data ERP contoh (aset/karyawan/keuangan)
-- sengaja tidak disertakan di sini agar file ini ringkas dan mudah dibaca.
-- Cara termudah memindahkan data contoh tersebut ke MySQL:
--   1) Jalankan sistem dulu memakai SQLite (default, tanpa perlu file ini).
--   2) Buka data/struggle.sqlite dengan tool seperti "DB Browser for SQLite",
--      lalu export tabel products/assets/employees/finance_transactions
--      sebagai CSV, kemudian import CSV tersebut ke tabel MySQL yang sesuai
--      lewat phpMyAdmin ("Import" -> pilih format CSV).
--   3) Atau, paling sederhana: tambahkan data secara langsung lewat halaman
--      Admin (Produk+, Aset, SDM, Keuangan) setelah DB_DRIVER diarahkan ke
--      'mysql' — semua form di admin akan langsung menulis ke MySQL.
