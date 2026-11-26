<?php
/**
 * SFV / STRUGGLE — API Bootstrap
 * Semua endpoint di /api memanggil file ini di baris pertama.
 */
declare(strict_types=1);
error_reporting(E_ALL);
ini_set('display_errors', '0'); // jangan bocorkan error mentah ke response JSON

session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
date_default_timezone_set('Asia/Jakarta');

require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

// Perlindungan CSRF: request yang mengubah data wajib membawa header kustom.
// Browser tidak mengizinkan situs lain menambahkan header ini pada form/img biasa.
if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD', 'OPTIONS'], true)
    && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'csrf_header_missing']);
    exit;
}

function json_input(): array {
    $raw = file_get_contents('php://input');
    if (!$raw) return $_POST ?: [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : ($_POST ?: []);
}

function respond($data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function fail(string $message, int $code = 400): void {
    respond(['ok' => false, 'error' => $message], $code);
}

function ok($data = []): void {
    if (is_array($data)) {
        respond(array_merge(['ok' => true], $data));
    } else {
        respond(['ok' => true, 'data' => $data]);
    }
}

/** Wajib login sebagai admin (dipakai endpoint CRUD ERP). */
function require_admin(): array {
    $u = $_SESSION['admin'] ?? null;
    if (!$u) fail('unauthenticated', 401);
    return $u;
}

function admin_role_allowed(array $u, array $roles): bool {
    return in_array($u['role'], $roles, true) || $u['role'] === 'Super Admin';
}

function require_role(array $roles): array {
    $u = require_admin();
    if (!admin_role_allowed($u, $roles)) fail('forbidden', 403);
    return $u;
}

function body_json_field(array $body, string $key, $default = null) {
    return array_key_exists($key, $body) ? $body[$key] : $default;
}

/** Decode kolom JSON dari SQLite (images/colors/sizes/dst) jadi array PHP asosiatif. */
function decode_product(array $row): array {
    $row['images'] = json_decode($row['images'] ?? '[]', true) ?: [];
    $row['colors'] = json_decode($row['colors'] ?? '[]', true) ?: [];
    $row['sizes'] = json_decode($row['sizes'] ?? '[]', true) ?: [];
    $row['sleeves'] = json_decode($row['sleeves'] ?? '[]', true) ?: [];
    $row['sleevePrices'] = json_decode($row['sleeve_prices'] ?? '{}', true) ?: new stdClass();
    $row['originalPrice'] = $row['original_price'];
    $row['isNew'] = (bool)$row['is_new'];
    $row['isBestSeller'] = (bool)$row['is_best_seller'];
    $row['isDeal'] = (bool)$row['is_deal'];
    $row['id'] = (int)$row['id'];
    $row['price'] = (int)$row['price'];
    $row['stock'] = (int)$row['stock'];
    $row['weightGram'] = (int)($row['weight_gram'] ?? 0);
    return $row;
}

function method(): string {
    return $_SERVER['REQUEST_METHOD'];
}


/* ------------------------------------------------------------ customer auth */

/** Pelanggan yang sedang login (dibaca ulang dari DB agar status nonaktif langsung berlaku). */
function current_customer(): ?array {
    $id = (int)($_SESSION['customer_id'] ?? 0);
    if (!$id) return null;
    $st = db()->prepare("SELECT * FROM customers WHERE id = ?");
    $st->execute([$id]);
    $c = $st->fetch();
    if (!$c || ($c['status'] ?? 'active') !== 'active') { unset($_SESSION['customer_id']); return null; }
    return $c;
}

function require_customer(): array {
    $c = current_customer();
    if (!$c) fail('unauthenticated', 401);
    return $c;
}

/** Data pelanggan yang aman dikirim ke browser (tanpa hash password). */
function public_customer(array $c): array {
    return [
        'id' => (int)$c['id'], 'name' => $c['name'], 'email' => $c['email'], 'username' => $c['username'] ?? null,
        'phone' => $c['phone'] ?? '', 'avatar' => $c['avatar'] ?? null, 'status' => $c['status'],
        'mustChangePassword' => !empty($c['must_change_password']), 'memberSince' => substr((string)$c['created_at'], 0, 4),
        'createdAt' => $c['created_at'],
    ];
}

function client_ip(): string { return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'); }

/** Throttle login berbasis database (tidak bisa dihindari dengan mengganti session/cookie). */
function throttle_check(string $ident): void {
    $st = db()->prepare("SELECT locked_until FROM login_throttle WHERE ident = ?");
    $st->execute([$ident]);
    $row = $st->fetch();
    if ($row && (int)$row['locked_until'] > time()) fail('too_many_attempts', 429);
}
function throttle_fail(string $ident, int $max = 5, int $lockSeconds = 300): void {
    $pdo = db();
    $st = $pdo->prepare("SELECT fails, updated_at FROM login_throttle WHERE ident = ?");
    $st->execute([$ident]);
    $row = $st->fetch();
    $fails = ($row && time() - (int)$row['updated_at'] < 900) ? (int)$row['fails'] + 1 : 1;
    $locked = $fails >= $max ? time() + $lockSeconds : 0;
    if ($row) $pdo->prepare("UPDATE login_throttle SET fails=?, locked_until=?, updated_at=? WHERE ident=?")->execute([$fails, $locked, time(), $ident]);
    else $pdo->prepare("INSERT INTO login_throttle (ident, fails, locked_until, updated_at) VALUES (?,?,?,?)")->execute([$ident, $fails, $locked, time()]);
}
function throttle_clear(string $ident): void {
    db()->prepare("DELETE FROM login_throttle WHERE ident = ?")->execute([$ident]);
}

/** Sanitasi string input singkat. */
function str_in($v, int $max = 255): string {
    $v = trim((string)($v ?? ''));
    return mb_substr($v, 0, $max);
}

/** Catat perubahan status pesanan/pembayaran/pengiriman. */
function order_event(string $orderId, string $kind, ?string $from, ?string $to, string $note, string $actor): void {
    db()->prepare("INSERT INTO order_events (order_id, kind, from_status, to_status, note, actor) VALUES (?,?,?,?,?,?)")
        ->execute([$orderId, $kind, $from, $to, $note, $actor]);
}
