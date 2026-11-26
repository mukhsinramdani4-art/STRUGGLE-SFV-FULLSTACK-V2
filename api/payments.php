<?php
/**
 * /api/payments.php — Bukti pembayaran & konfigurasi metode pembayaran.
 *
 * Pelanggan:
 *   POST ?action=proof            multipart: order_id, proof (jpg/png/webp/pdf, maks 4MB), note?
 *   GET  ?action=proof&order_id=  unduh bukti (pemilik pesanan atau admin)
 * Admin (Super Admin/Owner/Manager):
 *   GET  ?action=methods          semua metode (termasuk nonaktif)
 *   PUT  ?action=method&id=       ubah rekening/instruksi/aktif
 *   POST ?action=qris_image&id=   unggah gambar QRIS (multipart: image)
 *
 * Integrasi payment gateway: tabel `payments` sudah memiliki kolom provider & gateway_ref.
 * Saat gateway dipasang, buat endpoint baru (mis. /api/payment_callback.php) yang memverifikasi
 * signature gateway lalu memanggil logika yang sama dengan verifikasi manual (status Dibayar +
 * sync_finance()). Selama belum ada, SEMUA pembayaran non-COD wajib diverifikasi admin.
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/orders_lib.php';
$pdo = db();
$action = $_GET['action'] ?? '';

function save_upload(array $file, string $dir, array $allowed, int $maxBytes, string $prefix): string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) fail('upload_error');
    if ($file['size'] > $maxBytes) fail('file_too_large');
    $mime = mime_content_type($file['tmp_name']);
    if (!isset($allowed[$mime])) fail('invalid_file_type');
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $name = $prefix . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '.' . $allowed[$mime];
    if (!move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) fail('save_failed', 500);
    return $name;
}

if ($action === 'proof' && method() === 'POST') {
    $c = require_customer();
    $orderId = (string)($_POST['order_id'] ?? '');
    $st = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND customer_id = ?");
    $st->execute([$orderId, $c['id']]);
    $o = $st->fetch();
    if (!$o) fail('not_found', 404);
    if ($o['payment_method'] === 'cod') fail('cod_no_proof', 409);
    if ($o['status'] === 'Dibatalkan') fail('order_cancelled', 409);
    if ($o['payment_status'] === 'Dibayar') fail('already_paid', 409);
    if (empty($_FILES['proof'])) fail('file_required');
    $name = save_upload($_FILES['proof'], PROOFS_DIR, ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'], 4 * 1024 * 1024, 'proof');
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE payments SET proof_path = ?, proof_uploaded_at = ?, payer_note = ?, status = 'Menunggu Verifikasi', reject_reason = NULL, updated_at = CURRENT_TIMESTAMP WHERE order_id = ?")
            ->execute([$name, date('Y-m-d H:i:s'), str_in($_POST['note'] ?? '', 300), $orderId]);
        $pdo->prepare("UPDATE orders SET payment_status = 'Menunggu Verifikasi' WHERE id = ?")->execute([$orderId]);
        order_event($orderId, 'payment', $o['payment_status'], 'Menunggu Verifikasi', 'Bukti pembayaran diunggah pelanggan', $c['email']);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    ok(['order' => order_full($pdo, $orderId, false)]);
}

if ($action === 'proof' && method() === 'GET') {
    $orderId = (string)($_GET['order_id'] ?? '');
    $st = $pdo->prepare("SELECT o.customer_id, p.proof_path FROM orders o JOIN payments p ON p.order_id = o.id WHERE o.id = ? ORDER BY p.id DESC LIMIT 1");
    $st->execute([$orderId]);
    $row = $st->fetch();
    if (!$row || !$row['proof_path']) fail('not_found', 404);
    $c = current_customer();
    $allowed = !empty($_SESSION['admin']) && admin_role_allowed($_SESSION['admin'], ['Super Admin', 'Owner', 'Manager', 'Kasir', 'Finance'])
        || ($c && (int)$c['id'] === (int)$row['customer_id']);
    if (!$allowed) fail('not_found', 404);
    $path = PROOFS_DIR . '/' . basename($row['proof_path']);
    if (!is_file($path)) fail('not_found', 404);
    header_remove('Content-Type');
    header('Content-Type: ' . mime_content_type($path));
    header('Content-Disposition: inline; filename="bukti-' . preg_replace('/[^A-Za-z0-9_-]/', '', $orderId) . '.' . pathinfo($path, PATHINFO_EXTENSION) . '"');
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

/* ---------------------------------------------------------------- admin */
const PAY_ADMINS = ['Super Admin', 'Owner', 'Manager'];

if ($action === 'methods' && method() === 'GET') {
    require_role(PAY_ADMINS);
    ok(['methods' => $pdo->query("SELECT * FROM payment_methods ORDER BY sort_order, id")->fetchAll()]);
}

if ($action === 'method' && method() === 'PUT') {
    $u = require_role(PAY_ADMINS);
    $id = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare("SELECT * FROM payment_methods WHERE id = ?");
    $st->execute([$id]);
    $m = $st->fetch();
    if (!$m) fail('not_found', 404);
    $b = json_input();
    $acc = array_key_exists('account_number', $b) ? str_in($b['account_number'], 60) : $m['account_number'];
    $sample = $acc !== $m['account_number'] || ($b['confirm_real'] ?? false) ? 0 : (int)$m['is_sample'];
    $pdo->prepare("UPDATE payment_methods SET name=?, account_name=?, account_number=?, instructions=?, enabled=?, sort_order=?, is_sample=? WHERE id=?")->execute([
        str_in($b['name'] ?? $m['name'], 80), str_in($b['account_name'] ?? $m['account_name'], 120), $acc,
        str_in($b['instructions'] ?? $m['instructions'], 2000),
        isset($b['enabled']) ? (int)!!$b['enabled'] : (int)$m['enabled'], (int)($b['sort_order'] ?? $m['sort_order']), $sample, $id,
    ]);
    log_activity($u['email'], 'update_payment_method', $m['code']);
    ok([]);
}

if ($action === 'qris_image' && method() === 'POST') {
    $u = require_role(PAY_ADMINS);
    $id = (int)($_GET['id'] ?? 0);
    $st = $pdo->prepare("SELECT * FROM payment_methods WHERE id = ? AND grp = 'qris'");
    $st->execute([$id]);
    if (!$st->fetch()) fail('not_found', 404);
    if (empty($_FILES['image'])) fail('file_required');
    $dir = DATA_DIR . '/uploads/payments';
    $name = save_upload($_FILES['image'], $dir, ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'], 3 * 1024 * 1024, 'qris');
    $pdo->prepare("UPDATE payment_methods SET qr_image = ?, is_sample = 0 WHERE id = ?")->execute(['data/uploads/payments/' . $name, $id]);
    log_activity($u['email'], 'upload_qris', $name);
    ok(['path' => 'data/uploads/payments/' . $name]);
}

fail('unknown_action', 400);
