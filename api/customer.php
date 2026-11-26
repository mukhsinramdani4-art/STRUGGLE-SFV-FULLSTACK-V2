<?php
/**
 * /api/customer.php — Akun pelanggan storefront (sesi server, data di database).
 *
 * POST ?action=login            { login (username/email), password }
 * POST ?action=logout
 * GET  ?action=me
 * POST ?action=register         { name, email, phone, password }   (hanya jika diaktifkan admin)
 * POST ?action=update_profile   { name, phone, avatar? }
 * POST ?action=change_password  { current_password, new_password }
 */
require_once __DIR__ . '/bootstrap.php';
$pdo = db();
$action = $_GET['action'] ?? '';

if ($action === 'me' && method() === 'GET') {
    $c = current_customer();
    ok(['user' => $c ? public_customer($c) : null]);
}

if ($action === 'login' && method() === 'POST') {
    $b = json_input();
    $login = strtolower(str_in($b['login'] ?? $b['email'] ?? '', 191));
    $password = (string)($b['password'] ?? '');
    if ($login === '' || $password === '') fail('fill_all_fields');

    $keyUser = 'c:' . $login;
    $keyIp = 'ip:' . client_ip();
    throttle_check($keyUser);
    throttle_check($keyIp);

    $st = $pdo->prepare("SELECT * FROM customers WHERE LOWER(username) = ? OR LOWER(email) = ?");
    $st->execute([$login, $login]);
    $c = $st->fetch();
    if (!$c || !$c['password_hash'] || !password_verify($password, $c['password_hash'])) {
        throttle_fail($keyUser, 5, 300);
        throttle_fail($keyIp, 30, 300);
        fail('invalid_credentials', 401);
    }
    if (($c['status'] ?? 'active') !== 'active') fail('account_disabled', 403);

    throttle_clear($keyUser);
    session_regenerate_id(true);
    $_SESSION['customer_id'] = (int)$c['id'];
    $pdo->prepare("UPDATE customers SET last_login_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$c['id']]);
    log_activity($c['email'], 'customer_login', '');
    ok(['user' => public_customer($c)]);
}

if ($action === 'logout' && method() === 'POST') {
    unset($_SESSION['customer_id']);
    ok([]);
}

if ($action === 'register' && method() === 'POST') {
    if (setting('allow_registration', '0') !== '1') fail('registration_closed', 403);
    $b = json_input();
    $name = str_in($b['name'] ?? '', 120);
    $email = strtolower(str_in($b['email'] ?? '', 191));
    $password = (string)($b['password'] ?? '');
    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) fail('invalid_input');
    $st = $pdo->prepare("SELECT id FROM customers WHERE LOWER(email) = ?");
    $st->execute([$email]);
    if ($st->fetch()) fail('email_exists');
    $pdo->prepare("INSERT INTO customers (name, email, phone, password_hash, status, updated_at) VALUES (?,?,?,?, 'active', CURRENT_TIMESTAMP)")
        ->execute([$name, $email, str_in($b['phone'] ?? '', 30), password_hash($password, PASSWORD_BCRYPT)]);
    ok(['id' => (int)$pdo->lastInsertId()]);
}

if ($action === 'update_profile' && method() === 'POST') {
    $c = require_customer();
    $b = json_input();
    $name = array_key_exists('name', $b) ? str_in($b['name'], 120) : $c['name'];
    if ($name === '') fail('name_required');
    $phone = array_key_exists('phone', $b) ? str_in($b['phone'], 30) : $c['phone'];
    $avatar = $c['avatar'];
    if (array_key_exists('avatar', $b)) {
        $a = $b['avatar'];
        if ($a === null || $a === '') $avatar = null;
        elseif (is_string($a) && preg_match('#^data:image/(png|jpeg|webp);base64,[A-Za-z0-9+/=]+$#', $a) && strlen($a) <= 400000) $avatar = $a;
        else fail('invalid_avatar');
    }
    $pdo->prepare("UPDATE customers SET name = ?, phone = ?, avatar = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
        ->execute([$name, $phone, $avatar, $c['id']]);
    $st = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
    $st->execute([$c['id']]);
    ok(['user' => public_customer($st->fetch())]);
}

if ($action === 'change_password' && method() === 'POST') {
    $c = require_customer();
    $b = json_input();
    $cur = (string)($b['current_password'] ?? '');
    $new = (string)($b['new_password'] ?? '');
    if (!password_verify($cur, (string)$c['password_hash'])) fail('wrong_current_password', 403);
    if (strlen($new) < 8) fail('password_too_short');
    if ($new === $cur) fail('password_same');
    $pdo->prepare("UPDATE customers SET password_hash = ?, must_change_password = 0, updated_at = CURRENT_TIMESTAMP WHERE id = ?")
        ->execute([password_hash($new, PASSWORD_BCRYPT), $c['id']]);
    // Password awal tidak perlu (dan tidak boleh) disimpan lagi setelah pelanggan menggantinya.
    $pdo->prepare("DELETE FROM customer_credentials WHERE customer_id = ?")->execute([$c['id']]);
    log_activity($c['email'], 'customer_change_password', '');
    ok([]);
}

fail('unknown_action', 400);
