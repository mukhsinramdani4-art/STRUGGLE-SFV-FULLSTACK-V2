<?php
/**
 * SFV / STRUGGLE — Migrasi skema v2 + seeder idempotent.
 *
 * Dipanggil otomatis dari db(). Seluruh langkah AMAN dijalankan berulang kali:
 *  - tabel dibuat dengan IF NOT EXISTS,
 *  - kolom baru ditambahkan hanya jika belum ada (tidak ada data yang dihapus),
 *  - seeder selalu memeriksa dulu apakah datanya sudah ada.
 *
 * Versi skema disimpan di tabel `settings` (key = schema_version) sehingga setelah
 * migrasi selesai, request berikutnya hanya membaca satu baris.
 */

const SCHEMA_VERSION = 2;

/* ------------------------------------------------------------------ helpers */

function db_is_mysql(): bool { return defined('DB_DRIVER') && DB_DRIVER === 'mysql'; }

function column_exists(PDO $pdo, string $table, string $column): bool {
    if (db_is_mysql()) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $st->execute([$table, $column]);
        return (int)$st->fetchColumn() > 0;
    }
    foreach ($pdo->query("PRAGMA table_info($table)")->fetchAll() as $c) {
        if (strcasecmp($c['name'], $column) === 0) return true;
    }
    return false;
}

function add_column(PDO $pdo, string $table, string $column, string $ddl): void {
    if (!column_exists($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE $table ADD COLUMN $column $ddl");
    }
}

function index_exists_safe(PDO $pdo, string $sql): void {
    try { $pdo->exec($sql); } catch (Throwable $e) { /* index sudah ada / tidak didukung */ }
}

/* ---------------------------------------------------------------- settings */

function setting(string $key, $default = null) {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query("SELECT setting_key, setting_value FROM settings")->fetchAll() as $r) {
                $cache[$r['setting_key']] = $r['setting_value'];
            }
        } catch (Throwable $e) { /* tabel belum ada */ }
    }
    return array_key_exists($key, $cache) && $cache[$key] !== null ? $cache[$key] : $default;
}

function set_setting(PDO $pdo, string $key, ?string $value): void {
    $st = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = ?");
    $st->execute([$key]);
    if ((int)$st->fetchColumn() > 0) {
        $pdo->prepare("UPDATE settings SET setting_value = ? WHERE setting_key = ?")->execute([$value, $key]);
    } else {
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)")->execute([$key, $value]);
    }
}

function seed_setting_default(PDO $pdo, string $key, string $value): void {
    $st = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = ?");
    $st->execute([$key]);
    if ((int)$st->fetchColumn() === 0) {
        $pdo->prepare("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)")->execute([$key, $value]);
    }
}

/* -------------------------------------------------------------- migrations */

function migrate_v2(PDO $pdo): void {
    $my = db_is_mysql();
    $PK  = $my ? 'INT AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $S   = $my ? 'VARCHAR(191)' : 'TEXT';          // string pendek / kunci
    $ENG = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';

    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        setting_key $S PRIMARY KEY,
        setting_value TEXT
    )$ENG");

    $cur = 0;
    try {
        $st = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'schema_version'");
        $st->execute();
        $cur = (int)$st->fetchColumn();
    } catch (Throwable $e) {}
    if ($cur >= SCHEMA_VERSION) return;

    /* --- customers: username, avatar, flag ganti password, dst --- */
    add_column($pdo, 'customers', 'username', "$S");
    add_column($pdo, 'customers', 'avatar', 'MEDIUMTEXT');
    add_column($pdo, 'customers', 'must_change_password', 'INTEGER DEFAULT 0');
    add_column($pdo, 'customers', 'last_login_at', $my ? 'DATETIME NULL' : 'TEXT');
    add_column($pdo, 'customers', 'seq_no', 'INTEGER');
    add_column($pdo, 'customers', 'is_class_account', 'INTEGER DEFAULT 0');
    add_column($pdo, 'customers', 'updated_at', $my ? 'DATETIME NULL' : 'TEXT');
    index_exists_safe($pdo, "CREATE UNIQUE INDEX idx_customers_username ON customers(username)");
    index_exists_safe($pdo, "CREATE UNIQUE INDEX idx_customers_seq ON customers(seq_no)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_credentials (
        customer_id INTEGER PRIMARY KEY,
        initial_password $S NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )$ENG");

    $pdo->exec("CREATE TABLE IF NOT EXISTS customer_addresses (
        id $PK,
        customer_id INTEGER NOT NULL,
        label $S,
        recipient $S,
        phone $S,
        address TEXT,
        province $S,
        city $S,
        district $S,
        village $S,
        postal_code $S,
        notes TEXT,
        lat REAL,
        lng REAL,
        place_id $S,
        formatted_address TEXT,
        is_default INTEGER DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )$ENG");
    index_exists_safe($pdo, "CREATE INDEX idx_addr_customer ON customer_addresses(customer_id)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS login_throttle (
        ident $S PRIMARY KEY,
        fails INTEGER DEFAULT 0,
        locked_until INTEGER DEFAULT 0,
        updated_at INTEGER DEFAULT 0
    )$ENG");

    /* --- products: berat (gram) untuk ongkir --- */
    add_column($pdo, 'products', 'weight_gram', 'INTEGER DEFAULT 0');

    /* --- orders: alamat lengkap, koordinat, ekspedisi, resi, jejak status --- */
    foreach ([
        'recipient_name' => $S, 'shipping_district' => $S, 'shipping_village' => $S,
        'shipping_notes' => 'TEXT', 'shipping_lat' => 'REAL', 'shipping_lng' => 'REAL',
        'shipping_formatted_address' => 'TEXT', 'payment_method_label' => $S,
        'courier_code' => $S, 'courier_name' => $S, 'service_code' => $S, 'service_name' => $S,
        'shipping_etd' => $S, 'shipping_is_estimate' => 'INTEGER DEFAULT 1',
        'weight_gram' => 'INTEGER DEFAULT 0', 'tracking_number' => $S,
        'paid_at' => $my ? 'DATETIME NULL' : 'TEXT', 'shipped_at' => $my ? 'DATETIME NULL' : 'TEXT',
        'delivered_at' => $my ? 'DATETIME NULL' : 'TEXT', 'stock_restored' => 'INTEGER DEFAULT 0',
        'admin_notes' => 'TEXT',
    ] as $col => $ddl) add_column($pdo, 'orders', $col, $ddl);
    index_exists_safe($pdo, "CREATE INDEX idx_orders_customer ON orders(customer_id)");

    add_column($pdo, 'order_items', 'sleeve', $S);
    add_column($pdo, 'order_items', 'image', $S);

    $pdo->exec("CREATE TABLE IF NOT EXISTS order_events (
        id $PK,
        order_id $S NOT NULL,
        kind $S NOT NULL,
        from_status $S,
        to_status $S,
        note TEXT,
        actor $S,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )$ENG");
    index_exists_safe($pdo, "CREATE INDEX idx_events_order ON order_events(order_id)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS payment_methods (
        id $PK,
        code $S NOT NULL UNIQUE,
        grp $S NOT NULL,
        name $S NOT NULL,
        account_name $S,
        account_number $S,
        instructions TEXT,
        qr_image $S,
        is_sample INTEGER DEFAULT 1,
        enabled INTEGER DEFAULT 1,
        sort_order INTEGER DEFAULT 0
    )$ENG");

    $pdo->exec("CREATE TABLE IF NOT EXISTS payments (
        id $PK,
        order_id $S NOT NULL,
        method_code $S,
        method_group $S,
        amount INTEGER DEFAULT 0,
        status $S DEFAULT 'Belum Dibayar',
        provider $S DEFAULT 'manual',
        gateway_ref $S,
        proof_path $S,
        proof_uploaded_at TEXT,
        payer_note TEXT,
        reject_reason TEXT,
        verified_by $S,
        verified_at TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )$ENG");
    index_exists_safe($pdo, "CREATE INDEX idx_payments_order ON payments(order_id)");

    $pdo->exec("CREATE TABLE IF NOT EXISTS shipping_couriers (
        id $PK,
        code $S NOT NULL UNIQUE,
        name $S NOT NULL,
        description TEXT,
        enabled INTEGER DEFAULT 1,
        sort_order INTEGER DEFAULT 0
    )$ENG");

    $pdo->exec("CREATE TABLE IF NOT EXISTS shipping_services (
        id $PK,
        courier_code $S NOT NULL,
        code $S NOT NULL,
        name $S NOT NULL,
        type $S DEFAULT 'regular',
        description TEXT,
        rate_local INTEGER DEFAULT 0,
        rate_province INTEGER DEFAULT 0,
        rate_island INTEGER DEFAULT 0,
        rate_national INTEGER DEFAULT 0,
        min_fee INTEGER DEFAULT 0,
        per_km_fee INTEGER DEFAULT 0,
        max_distance_km INTEGER DEFAULT 0,
        etd_min INTEGER DEFAULT 1,
        etd_max INTEGER DEFAULT 2,
        etd_step INTEGER DEFAULT 1,
        etd_unit $S DEFAULT 'hari',
        enabled INTEGER DEFAULT 1,
        sort_order INTEGER DEFAULT 0
    )$ENG");
    index_exists_safe($pdo, "CREATE UNIQUE INDEX idx_service_unique ON shipping_services(courier_code, code)");

    /* --- normalisasi status lama (Inggris) -> Indonesia. Idempotent. --- */
    $map = [
        'status' => ['Pending' => 'Pending', 'Processing' => 'Diproses', 'Packed' => 'Dikemas', 'Shipped' => 'Dikirim', 'Completed' => 'Selesai', 'Cancelled' => 'Dibatalkan'],
        'payment_status' => ['Unpaid' => 'Belum Dibayar', 'Paid' => 'Dibayar', 'Failed' => 'Gagal', 'Refunded' => 'Dikembalikan'],
        'shipping_status' => ['Pending' => 'Menunggu Pengiriman', 'Processing' => 'Diproses Kurir', 'Shipped' => 'Dalam Perjalanan', 'Delivered' => 'Diterima'],
    ];
    foreach ($map as $col => $pairs) {
        foreach ($pairs as $old => $new) {
            if ($old !== $new) $pdo->prepare("UPDATE orders SET $col = ? WHERE $col = ?")->execute([$new, $old]);
        }
    }
    // Pesanan lama yang tidak punya record payments: buat agar konsisten.
    $legacy = $pdo->query("SELECT o.id, o.payment_method, o.total, o.payment_status FROM orders o
        WHERE NOT EXISTS (SELECT 1 FROM payments p WHERE p.order_id = o.id)")->fetchAll();
    foreach ($legacy as $o) {
        $pdo->prepare("INSERT INTO payments (order_id, method_code, amount, status) VALUES (?,?,?,?)")
            ->execute([$o['id'], $o['payment_method'], (int)$o['total'], $o['payment_status'] ?: 'Belum Dibayar']);
    }

    seed_settings($pdo);
    seed_payment_methods($pdo);
    seed_shipping($pdo);
    seed_product_weights($pdo);
    seed_admin_identity($pdo);
    seed_class_customers($pdo);

    set_setting($pdo, 'schema_version', (string)SCHEMA_VERSION);
}

/* ----------------------------------------------------------------- seeders */

function seed_settings(PDO $pdo): void {
    $defaults = [
        'group_name'          => 'NAMA KELOMPOK',   // ganti lewat Admin > Pengaturan
        'store_name'          => 'STRUGGLE / SFV STUDIO',
        'store_phone'         => '+62 812-3456-7890',
        'store_whatsapp'      => '6281234567890',
        'store_email'         => '',
        'store_instagram'     => 'sfvclub.co',
        'store_link'          => 'https://linktr.ee/strugglestore2024',
        'store_address'       => '',
        'origin_city'         => 'Kota Jakarta Selatan',
        'origin_province'     => 'DKI Jakarta',
        'origin_lat'          => '-6.2615',
        'origin_lng'          => '106.8106',
        'allow_registration'  => '0',
        'payment_due_hours'   => '24',
        'maps_api_key'        => '',
    ];
    foreach ($defaults as $k => $v) seed_setting_default($pdo, $k, $v);
}

function seed_payment_methods(PDO $pdo): void {
    $bank = "1. Transfer sesuai total pembayaran ke rekening di atas.\n2. Simpan bukti transfer.\n3. Unggah bukti pembayaran pada halaman pesanan.\n4. Admin akan memverifikasi; status berubah menjadi Dibayar setelah dikonfirmasi.";
    $ew   = "1. Buka aplikasi e-wallet dan pilih kirim/transfer ke nomor di atas.\n2. Masukkan nominal sesuai total pembayaran.\n3. Unggah bukti pembayaran pada halaman pesanan.\n4. Admin akan memverifikasi pembayaran Anda.";
    $rows = [
        ['bca',     'bank',    'Transfer Bank BCA',     '0000000000', $bank, 1, 1],
        ['bri',     'bank',    'Transfer Bank BRI',     '0000000000', $bank, 1, 2],
        ['bni',     'bank',    'Transfer Bank BNI',     '0000000000', $bank, 1, 3],
        ['mandiri', 'bank',    'Transfer Bank Mandiri', '0000000000', $bank, 1, 4],
        ['gopay',   'ewallet', 'GoPay',                 '081200000000', $ew, 1, 5],
        ['ovo',     'ewallet', 'OVO',                   '081200000000', $ew, 1, 6],
        ['dana',    'ewallet', 'DANA',                  '081200000000', $ew, 1, 7],
        ['qris',    'qris',    'QRIS',                  '', "1. Buka aplikasi bank/e-wallet apa pun yang mendukung QRIS.\n2. Pindai kode QR yang tampil.\n3. Pastikan nominal sesuai total pembayaran.\n4. Unggah bukti pembayaran pada halaman pesanan.", 1, 8],
        ['cod',     'cod',     'Bayar di Tempat (COD)', '', "Bayar tunai kepada kurir saat pesanan diterima. Siapkan uang pas sesuai total pembayaran. Pesanan COD baru dapat dikirim setelah dikonfirmasi admin.", 0, 9],
    ];
    foreach ($rows as [$code, $grp, $name, $acc, $ins, $enabled, $sort]) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM payment_methods WHERE code = ?");
        $st->execute([$code]);
        if ((int)$st->fetchColumn() > 0) continue;
        $pdo->prepare("INSERT INTO payment_methods (code, grp, name, account_name, account_number, instructions, is_sample, enabled, sort_order) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$code, $grp, $name, in_array($grp, ['bank','ewallet']) ? 'SFV Studio' : '', $acc, $ins, 1, $enabled, $sort]);
    }
}

function seed_shipping(PDO $pdo): void {
    $couriers = [
        ['jne', 'JNE', 'Jalur Nugraha Ekakurir', 1],
        ['jnt', 'J&T Express', 'J&T Express Indonesia', 2],
        ['sicepat', 'SiCepat', 'SiCepat Ekspres', 3],
        ['anteraja', 'AnterAja', 'AnterAja Logistik', 4],
        ['pos', 'Pos Indonesia', 'Pos Indonesia', 5],
        ['gosend', 'GoSend', 'Kurir instan lokal (hanya dalam jangkauan jarak)', 6],
        ['grab', 'GrabExpress', 'Kurir instan lokal (hanya dalam jangkauan jarak)', 7],
    ];
    foreach ($couriers as [$code, $name, $desc, $sort]) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM shipping_couriers WHERE code = ?");
        $st->execute([$code]);
        if ((int)$st->fetchColumn() > 0) continue;
        $pdo->prepare("INSERT INTO shipping_couriers (code, name, description, enabled, sort_order) VALUES (?,?,?,1,?)")
            ->execute([$code, $name, $desc, $sort]);
    }
    // [courier, code, name, type, desc, local, province, island, national, min_fee, per_km, max_km, etd_min, etd_max, step, unit, sort]
    $services = [
        ['jne','REG','JNE Reguler','regular','Layanan reguler ke seluruh Indonesia.', 9000,11000,15000,28000, 9000,0,0, 2,3,1,'hari',1],
        ['jne','YES','JNE YES (Express)','express','Pengiriman keesokan hari untuk wilayah tertentu.', 18000,22000,30000,48000, 18000,0,0, 1,1,1,'hari',2],
        ['jnt','EZ','J&T EZ','regular','Layanan reguler J&T Express.', 8000,10500,14500,27000, 8000,0,0, 2,3,1,'hari',1],
        ['sicepat','REG','SiCepat Reguler','regular','Layanan reguler SiCepat.', 8500,10500,14000,26000, 8500,0,0, 2,3,1,'hari',1],
        ['sicepat','BEST','SiCepat BEST (Express)','express','Layanan prioritas, tiba lebih cepat.', 16000,20000,28000,45000, 16000,0,0, 1,1,1,'hari',2],
        ['anteraja','REG','AnterAja Reguler','regular','Layanan reguler AnterAja.', 8500,10500,14500,26500, 8500,0,0, 2,3,1,'hari',1],
        ['anteraja','ND','AnterAja Next Day','express','Pengiriman keesokan hari.', 15000,19000,27000,44000, 15000,0,0, 1,1,1,'hari',2],
        ['pos','REG','Pos Reguler','regular','Layanan reguler Pos Indonesia.', 9500,11500,15500,27500, 9500,0,0, 3,4,1,'hari',1],
        ['pos','NEXT','Pos Nextday','express','Pengiriman keesokan hari.', 17000,21000,29000,46000, 17000,0,0, 1,2,1,'hari',2],
        ['gosend','INSTANT','GoSend Instant','instant','Kurir motor, tiba dalam hitungan jam. Wajib menentukan titik di peta.', 0,0,0,0, 10000,2500,25, 1,3,0,'jam',1],
        ['gosend','SAMEDAY','GoSend Same Day','instant','Tiba di hari yang sama. Wajib menentukan titik di peta.', 0,0,0,0, 12000,1800,40, 3,8,0,'jam',2],
        ['grab','INSTANT','GrabExpress Instant','instant','Kurir motor, tiba dalam hitungan jam. Wajib menentukan titik di peta.', 0,0,0,0, 10000,2600,25, 1,3,0,'jam',1],
    ];
    foreach ($services as $s) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM shipping_services WHERE courier_code = ? AND code = ?");
        $st->execute([$s[0], $s[1]]);
        if ((int)$st->fetchColumn() > 0) continue;
        $pdo->prepare("INSERT INTO shipping_services (courier_code, code, name, type, description, rate_local, rate_province, rate_island, rate_national, min_fee, per_km_fee, max_distance_km, etd_min, etd_max, etd_step, etd_unit, enabled, sort_order)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?)")->execute($s);
    }
}

function seed_product_weights(PDO $pdo): void {
    $w = ['kaos' => 250, 'kemeja' => 350, 'outer' => 700, 'celana_panjang' => 500, 'celana_pendek' => 300];
    foreach ($w as $cat => $g) {
        $pdo->prepare("UPDATE products SET weight_gram = ? WHERE category = ? AND (weight_gram IS NULL OR weight_gram = 0)")->execute([$g, $cat]);
    }
    $pdo->exec("UPDATE products SET weight_gram = 400 WHERE weight_gram IS NULL OR weight_gram = 0");
}

/** Tandai akun admin demo sebagai "identitas dikelola kelompok" — email & password TIDAK diubah. */
function seed_admin_identity(PDO $pdo): void {
    add_column($pdo, 'admin_users', 'identity_managed', 'INTEGER DEFAULT 0');
    $pdo->exec("UPDATE admin_users SET identity_managed = 1 WHERE email LIKE '%@sfv.demo'");
}

/** Nama tampilan admin: akun demo memakai identitas kelompok, akun lain memakai nama aslinya. */
function admin_display_name(array $u): string {
    if (!empty($u['identity_managed'])) {
        return trim(($u['role'] ?? 'Admin') . ' · ' . (string)setting('group_name', 'NAMA KELOMPOK'));
    }
    return (string)($u['name'] ?? '');
}

function generate_password(int $len = 10): string {
    $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // tanpa karakter yang mirip (0/O, 1/l/I)
    $out = '';
    for ($i = 0; $i < $len; $i++) $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return $out;
}

/**
 * Seed tepat 39 akun pelanggan kelas. Kunci idempotensi = seq_no (1..39) / username.
 * Menjalankan fungsi ini berkali-kali TIDAK akan membuat akun duplikat ataupun
 * menimpa nama/password yang sudah diubah.
 */
function seed_class_customers(PDO $pdo, int $total = 39): void {
    for ($i = 1; $i <= $total; $i++) {
        $username = sprintf('pelanggan%02d', $i);
        $st = $pdo->prepare("SELECT id FROM customers WHERE username = ? OR seq_no = ?");
        $st->execute([$username, $i]);
        if ($st->fetch()) continue;

        $email = $username . '@sfv.studio';
        $st = $pdo->prepare("SELECT id FROM customers WHERE email = ?");
        $st->execute([$email]);
        if ($row = $st->fetch()) { // email sudah dipakai akun lama: ambil alih sebagai akun kelas
            $pdo->prepare("UPDATE customers SET username = ?, seq_no = ?, is_class_account = 1 WHERE id = ?")->execute([$username, $i, $row['id']]);
            continue;
        }
        $plain = generate_password(10);
        $pdo->prepare("INSERT INTO customers (name, email, phone, password_hash, status, username, must_change_password, seq_no, is_class_account, updated_at)
            VALUES (?,?,?,?, 'active', ?, 1, ?, 1, CURRENT_TIMESTAMP)")
            ->execute([sprintf('Pelanggan %02d', $i), $email, '', password_hash($plain, PASSWORD_BCRYPT), $username, $i]);
        $id = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO customer_credentials (customer_id, initial_password) VALUES (?, ?)")->execute([$id, $plain]);
    }
}
