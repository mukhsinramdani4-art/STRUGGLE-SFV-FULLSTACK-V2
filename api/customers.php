<?php
/**
 * /api/customers.php — Pengelolaan akun pelanggan (KHUSUS ADMIN).
 * (Login/registrasi/profil pelanggan ada di /api/customer.php.)
 *
 * GET                       daftar pelanggan + statistik pesanan
 * GET  ?id=                 detail pelanggan + alamat + riwayat pesanan
 * GET  ?action=credentials  daftar kredensial awal akun kelas (Owner/Super Admin/Manager)
 * PUT  ?id=                 { name?, email?, phone?, status? }
 * POST ?action=reset_password&id=   buat password baru (ditampilkan SEKALI ke admin)
 * POST ?action=bulk_rename          { names: [{seq, name}, ...] } ganti nama sesuai urutan akun
 */
require_once __DIR__ . '/bootstrap.php';
$pdo = db();
$action = $_GET['action'] ?? '';
const CUSTOMER_MANAGERS = ['Super Admin', 'Owner', 'Manager'];

if (method() === 'GET' && $action === 'credentials') {
    $u = require_role(CUSTOMER_MANAGERS);
    $rows = $pdo->query("SELECT c.id, c.seq_no, c.name, c.username, c.email, cc.initial_password, c.must_change_password, c.last_login_at, c.status
        FROM customers c LEFT JOIN customer_credentials cc ON cc.customer_id = c.id
        WHERE c.is_class_account = 1 ORDER BY c.seq_no ASC")->fetchAll();
    log_activity($u['email'], 'view_customer_credentials', count($rows) . ' akun');
    ok(['credentials' => $rows]);
}

if (method() === 'GET') {
    require_admin();
    if (!empty($_GET['id'])) {
        $id = (int)$_GET['id'];
        $st = $pdo->prepare("SELECT id,name,email,username,phone,status,avatar,seq_no,is_class_account,must_change_password,last_login_at,created_at FROM customers WHERE id = ?");
        $st->execute([$id]);
        $c = $st->fetch();
        if (!$c) fail('not_found', 404);
        $st = $pdo->prepare("SELECT * FROM customer_addresses WHERE customer_id = ? ORDER BY is_default DESC, id ASC");
        $st->execute([$id]);
        $addresses = $st->fetchAll();
        $st = $pdo->prepare("SELECT id, total, status, payment_status, shipping_status, payment_method_label, courier_name, service_name, tracking_number, created_at
            FROM orders WHERE customer_id = ? ORDER BY created_at DESC");
        $st->execute([$id]);
        ok(['customer' => $c, 'addresses' => $addresses, 'orders' => $st->fetchAll()]);
    }
    $rows = $pdo->query("SELECT c.id, c.seq_no, c.name, c.username, c.email, c.phone, c.status, c.is_class_account, c.must_change_password, c.last_login_at, c.created_at,
        (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.id) AS order_count,
        (SELECT COALESCE(SUM(total),0) FROM orders o WHERE o.customer_id = c.id AND o.status != 'Dibatalkan') AS total_spend
        FROM customers c ORDER BY CASE WHEN c.seq_no IS NULL THEN 1 ELSE 0 END, c.seq_no ASC, c.id ASC")->fetchAll();
    ok(['customers' => $rows]);
}

if (method() === 'PUT') {
    $u = require_role(array_merge(CUSTOMER_MANAGERS, ['Kasir']));
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) fail('id_required');
    $st = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $st->execute([$id]);
    $c = $st->fetch();
    if (!$c) fail('not_found', 404);
    $b = json_input();
    $name = array_key_exists('name', $b) ? str_in($b['name'], 120) : $c['name'];
    $email = array_key_exists('email', $b) ? strtolower(str_in($b['email'], 191)) : $c['email'];
    $phone = array_key_exists('phone', $b) ? str_in($b['phone'], 30) : $c['phone'];
    $status = $b['status'] ?? $c['status'];
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) fail('invalid_input');
    if (!in_array($status, ['active', 'inactive'], true)) fail('invalid_status');
    $st = $pdo->prepare("SELECT id FROM customers WHERE LOWER(email) = ? AND id != ?");
    $st->execute([$email, $id]);
    if ($st->fetch()) fail('email_exists');
    $pdo->prepare("UPDATE customers SET name=?, email=?, phone=?, status=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")
        ->execute([$name, $email, $phone, $status, $id]);
    log_activity($u['email'], 'update_customer', "#$id $name");
    ok([]);
}

if (method() === 'POST' && $action === 'reset_password') {
    $u = require_role(CUSTOMER_MANAGERS);
    $id = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare("SELECT id, email FROM customers WHERE id = ?");
    $st->execute([$id]);
    $c = $st->fetch();
    if (!$c) fail('not_found', 404);
    $plain = generate_password(10);
    $pdo->prepare("UPDATE customers SET password_hash = ?, must_change_password = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
        ->execute([password_hash($plain, PASSWORD_BCRYPT), $id]);
    $pdo->prepare("DELETE FROM customer_credentials WHERE customer_id = ?")->execute([$id]);
    $pdo->prepare("INSERT INTO customer_credentials (customer_id, initial_password) VALUES (?, ?)")->execute([$id, $plain]);
    throttle_clear('c:' . strtolower($c['email']));
    log_activity($u['email'], 'reset_customer_password', "#$id");
    ok(['password' => $plain]);
}

if (method() === 'POST' && $action === 'bulk_rename') {
    $u = require_role(CUSTOMER_MANAGERS);
    $b = json_input();
    $names = $b['names'] ?? [];
    if (!is_array($names) || !$names) fail('names_required');
    $n = 0;
    $pdo->beginTransaction();
    try {
        foreach ($names as $row) {
            $seq = (int)($row['seq'] ?? 0);
            $name = str_in($row['name'] ?? '', 120);
            if ($seq < 1 || $name === '') continue;
            $st = $pdo->prepare("UPDATE customers SET name = ?, updated_at = CURRENT_TIMESTAMP WHERE seq_no = ? AND is_class_account = 1");
            $st->execute([$name, $seq]);
            $n += $st->rowCount();
        }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    log_activity($u['email'], 'bulk_rename_customers', "$n akun");
    ok(['updated' => $n]);
}

fail('method_not_allowed', 405);
