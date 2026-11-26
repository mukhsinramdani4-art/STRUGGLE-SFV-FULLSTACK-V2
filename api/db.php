<?php
/**
 * SFV / STRUGGLE — Database Layer
 * Menggunakan SQLite (file tunggal, tanpa instalasi server database) agar sistem
 * langsung bisa dijalankan di mana saja (php -S, cPanel, dsb) tanpa konfigurasi.
 * Untuk deployment produksi dengan MySQL, import database.sql lalu ganti
 * DB_DRIVER di bawah ini menjadi 'mysql' dan isi kredensialnya.
 */

const DB_DRIVER = 'sqlite'; // 'sqlite' atau 'mysql'

// --- Kredensial MySQL (hanya dipakai jika DB_DRIVER = 'mysql') ---
const MYSQL_HOST = '127.0.0.1';
const MYSQL_NAME = 'struggle_studio';
const MYSQL_USER = 'root';
const MYSQL_PASS = '';

define('DATA_DIR', __DIR__ . '/../data');
define('SQLITE_PATH', DATA_DIR . '/struggle.sqlite');
define('UPLOADS_DIR', DATA_DIR . '/uploads/products');
define('PROOFS_DIR', DATA_DIR . '/proofs');
define('AVATARS_DIR', DATA_DIR . '/uploads/avatars');

require_once __DIR__ . '/schema.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    if (!is_dir(DATA_DIR)) @mkdir(DATA_DIR, 0775, true);
    if (!is_dir(UPLOADS_DIR)) @mkdir(UPLOADS_DIR, 0775, true);

    if (DB_DRIVER === 'mysql') {
        $dsn = 'mysql:host=' . MYSQL_HOST . ';dbname=' . MYSQL_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, MYSQL_USER, MYSQL_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        migrate_v2($pdo); // MySQL: skema dasar dari database.sql, v2 dari database_migration_v2.sql / auto
    } else {
        $isNew = !file_exists(SQLITE_PATH);
        $pdo = new PDO('sqlite:' . SQLITE_PATH, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA journal_mode = WAL');
        migrate($pdo);
        if ($isNew) seed($pdo);
        ensure_seed_admin($pdo);
        migrate_v2($pdo);
    }
    return $pdo;
}

function migrate(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT UNIQUE NOT NULL,
        name TEXT NOT NULL,
        icon TEXT DEFAULT 'checkroom',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        price INTEGER NOT NULL DEFAULT 0,
        original_price INTEGER,
        category TEXT,
        material TEXT,
        description TEXT,
        images TEXT DEFAULT '[]',
        colors TEXT DEFAULT '[]',
        sizes TEXT DEFAULT '[]',
        sleeves TEXT DEFAULT '[]',
        sleeve_prices TEXT DEFAULT '{}',
        badge TEXT,
        stock INTEGER DEFAULT 0,
        is_new INTEGER DEFAULT 0,
        is_best_seller INTEGER DEFAULT 0,
        is_deal INTEGER DEFAULT 0,
        collection TEXT,
        status TEXT DEFAULT 'active',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS collections (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT UNIQUE NOT NULL,
        name TEXT NOT NULL,
        description TEXT,
        image TEXT
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS customers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT UNIQUE NOT NULL,
        phone TEXT,
        password_hash TEXT,
        status TEXT DEFAULT 'active',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
        id TEXT PRIMARY KEY,
        customer_id INTEGER,
        customer_name TEXT,
        customer_email TEXT,
        customer_phone TEXT,
        shipping_address TEXT,
        shipping_city TEXT,
        shipping_province TEXT,
        shipping_postal TEXT,
        payment_method TEXT,
        coupon_code TEXT,
        subtotal INTEGER DEFAULT 0,
        discount INTEGER DEFAULT 0,
        shipping_fee INTEGER DEFAULT 0,
        total INTEGER DEFAULT 0,
        status TEXT DEFAULT 'Pending',
        payment_status TEXT DEFAULT 'Unpaid',
        shipping_status TEXT DEFAULT 'Pending',
        source TEXT DEFAULT 'storefront',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS order_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id TEXT NOT NULL,
        product_id INTEGER,
        product_name TEXT,
        color TEXT,
        size TEXT,
        qty INTEGER DEFAULT 1,
        price INTEGER DEFAULT 0,
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS coupons (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        code TEXT UNIQUE NOT NULL,
        description TEXT,
        discount_type TEXT DEFAULT 'percent',
        discount_value INTEGER DEFAULT 0,
        max_discount INTEGER DEFAULT 0,
        min_purchase INTEGER DEFAULT 0,
        valid_until TEXT,
        usage_limit INTEGER DEFAULT 0,
        used_count INTEGER DEFAULT 0,
        status TEXT DEFAULT 'active'
    )");

    // --- ERP: Admin users (role-based) ---
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        email TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT 'Staff',
        avatar TEXT,
        status TEXT DEFAULT 'active',
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    // --- ERP: Aset (company assets / equipment) ---
    $pdo->exec("CREATE TABLE IF NOT EXISTS assets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        asset_code TEXT UNIQUE,
        name TEXT NOT NULL,
        category TEXT DEFAULT 'Peralatan',
        location TEXT,
        assigned_to TEXT,
        purchase_date TEXT,
        purchase_value INTEGER DEFAULT 0,
        current_value INTEGER DEFAULT 0,
        condition_status TEXT DEFAULT 'Baik',
        status TEXT DEFAULT 'Aktif',
        notes TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    // --- ERP: SDM (employees) ---
    $pdo->exec("CREATE TABLE IF NOT EXISTS employees (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        position TEXT,
        department TEXT,
        email TEXT,
        phone TEXT,
        join_date TEXT,
        salary INTEGER DEFAULT 0,
        status TEXT DEFAULT 'Aktif',
        address TEXT,
        notes TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    // --- ERP: Keuangan (finance transactions) ---
    $pdo->exec("CREATE TABLE IF NOT EXISTS finance_transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        type TEXT NOT NULL, -- 'income' | 'expense'
        category TEXT,
        amount INTEGER DEFAULT 0,
        description TEXT,
        trx_date TEXT DEFAULT CURRENT_TIMESTAMP,
        related_order_id TEXT,
        created_by TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    // --- ERP: Activity log ---
    $pdo->exec("CREATE TABLE IF NOT EXISTS activity_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        actor TEXT,
        action TEXT,
        detail TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");
}

function seed(PDO $pdo): void {
    $jsonPath = DATA_DIR . '/seed_products.json';
    if (!file_exists($jsonPath)) return;
    $data = json_decode(file_get_contents($jsonPath), true);
    if (!$data) return;

    $pdo->beginTransaction();
    try {
        // Categories
        $catNames = [
            'kaos' => ['name' => 'Kaos', 'icon' => 'checkroom'],
            'kemeja' => ['name' => 'Kemeja', 'icon' => 'styler'],
            'outer' => ['name' => 'Outer', 'icon' => 'dry_cleaning'],
            'celana_panjang' => ['name' => 'Celana Panjang', 'icon' => 'straighten'],
            'celana_pendek' => ['name' => 'Celana Pendek', 'icon' => 'content_cut'],
        ];
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO categories (code, name, icon) VALUES (?,?,?)");
        foreach ($catNames as $code => $c) $stmt->execute([$code, $c['name'], $c['icon']]);

        // Collections
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO collections (code, name, description, image) VALUES (?,?,?,?)");
        foreach (($data['COLLECTIONS'] ?? []) as $c) {
            $stmt->execute([$c['id'], $c['name'], $c['description'], $c['image']]);
        }

        // Products
        $stmt = $pdo->prepare("INSERT INTO products
            (id, name, price, original_price, category, material, description, images, colors, sizes, sleeves, sleeve_prices, badge, stock, is_new, is_best_seller, is_deal, collection, status)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?, 'active')");
        foreach (($data['PRODUCTS'] ?? []) as $p) {
            $stmt->execute([
                $p['id'], $p['name'], $p['price'], $p['originalPrice'] ?? null,
                $p['category'], $p['material'] ?? '', $p['description'] ?? '',
                json_encode($p['images'] ?? [], JSON_UNESCAPED_UNICODE),
                json_encode($p['colors'] ?? [], JSON_UNESCAPED_UNICODE),
                json_encode($p['sizes'] ?? [], JSON_UNESCAPED_UNICODE),
                json_encode($p['sleeves'] ?? [], JSON_UNESCAPED_UNICODE),
                json_encode($p['sleevePrices'] ?? new stdClass(), JSON_UNESCAPED_UNICODE),
                $p['badge'] ?? null, $p['stock'] ?? 0,
                !empty($p['isNew']) ? 1 : 0, !empty($p['isBestSeller']) ? 1 : 0, !empty($p['isDeal']) ? 1 : 0,
                $p['collection'] ?? null,
            ]);
        }
        // Keep autoincrement above the manually inserted ids
        $maxId = (int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM products")->fetchColumn();
        $pdo->exec("UPDATE sqlite_sequence SET seq = $maxId WHERE name = 'products'");

        // Coupons
        $stmt = $pdo->prepare("INSERT OR IGNORE INTO coupons (code, description, discount_type, discount_value, max_discount, min_purchase, valid_until, usage_limit, used_count, status) VALUES (?,?,?,?,?,?,?,?,?,?)");
        foreach (($data['COUPONS'] ?? []) as $c) {
            $stmt->execute([
                $c['code'], $c['description'], $c['discountType'], $c['discountValue'],
                $c['maxDiscount'] ?? 0, $c['minPurchase'] ?? 0, $c['validUntil'] ?? null,
                $c['usageLimit'] ?? 0, $c['used'] ?? 0, $c['status'] ?? 'active',
            ]);
        }

        // Demo finance & assets & employees so dashboard/ERP isn't empty on first run
        $pdo->exec("INSERT INTO assets (asset_code, name, category, location, purchase_date, purchase_value, current_value, condition_status, status) VALUES
            ('AST-0001','Rak Display Toko','Furnitur','Toko Utama','2024-01-10',3500000,2800000,'Baik','Aktif'),
            ('AST-0002','Mesin Kasir POS','Elektronik','Toko Utama','2024-02-15',6200000,5000000,'Baik','Aktif'),
            ('AST-0003','Laptop Admin (Editing Katalog)','Elektronik','Kantor','2023-11-01',9500000,6500000,'Baik','Aktif'),
            ('AST-0004','Mesin Jahit Sample','Peralatan','Gudang Produksi','2023-06-20',4200000,2900000,'Perlu Servis','Aktif'),
            ('AST-0005','Motor Box Pengiriman','Kendaraan','Gudang','2022-09-05',21000000,14000000,'Baik','Aktif')");

        $pdo->exec("INSERT INTO employees (name, position, department, email, phone, join_date, salary, status) VALUES
            ('Rania Putri','Owner','Manajemen','owner@sfv.demo','081200000001','2022-01-01',0,'Aktif'),
            ('Bayu Saputra','Store Manager','Operasional','manager@sfv.demo','081200000002','2022-03-15',6500000,'Aktif'),
            ('Doni Pratama','Staff Gudang','Gudang','gudang@sfv.demo','081200000003','2023-01-10',4200000,'Aktif'),
            ('Sinta Amelia','Kasir / Sales','Penjualan','kasir@sfv.demo','081200000004','2023-05-20',4000000,'Aktif'),
            ('Mira Anggraini','Marketing','Marketing','marketing@sfv.demo','081200000005','2023-07-01',4500000,'Aktif'),
            ('Fikri Ramadhan','Staff Keuangan','Keuangan','finance@sfv.demo','081200000006','2023-02-18',4800000,'Aktif')");

        $pdo->exec("INSERT INTO finance_transactions (type, category, amount, description, trx_date, created_by) VALUES
            ('expense','Sewa Toko',5000000,'Sewa toko bulan berjalan', date('now','-20 day'),'system'),
            ('expense','Gaji Karyawan',24000000,'Gaji seluruh staff', date('now','-15 day'),'system'),
            ('expense','Produksi',18000000,'Produksi batch kaos & hoodie', date('now','-10 day'),'system'),
            ('income','Penjualan Offline',9500000,'Penjualan toko fisik', date('now','-5 day'),'system'),
            ('expense','Marketing',3200000,'Iklan media sosial', date('now','-3 day'),'system')");

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Membuat akun admin default jika tabel admin_users masih kosong. */
function ensure_seed_admin(PDO $pdo): void {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM admin_users")->fetchColumn();
    if ($count > 0) return;
    $accounts = [
        ['Rania', 'owner@sfv.demo', 'Owner'],
        ['Admin Utama', 'admin@sfv.demo', 'Super Admin'],
        ['Bayu', 'manager@sfv.demo', 'Manager'],
        ['Doni', 'gudang@sfv.demo', 'Gudang'],
        ['Sinta', 'kasir@sfv.demo', 'Kasir'],
        ['Mira', 'marketing@sfv.demo', 'Marketing'],
        ['Fikri', 'finance@sfv.demo', 'Finance'],
    ];
    $hash = password_hash('sfv-demo', PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("INSERT INTO admin_users (name, email, password_hash, role) VALUES (?,?,?,?)");
    foreach ($accounts as [$name, $email, $role]) $stmt->execute([$name, $email, $hash, $role]);
}

function log_activity(string $actor, string $action, string $detail = ''): void {
    try {
        $stmt = db()->prepare("INSERT INTO activity_log (actor, action, detail) VALUES (?,?,?)");
        $stmt->execute([$actor, $action, $detail]);
    } catch (Throwable $e) { /* non-fatal */ }
}
