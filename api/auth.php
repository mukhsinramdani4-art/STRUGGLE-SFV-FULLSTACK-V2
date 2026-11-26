<?php
/**
 * /api/auth.php — Login & session admin ERP.
 * POST ?action=login   { email, password }
 * POST ?action=logout
 * GET  ?action=me
 */
require_once __DIR__ . '/bootstrap.php';
$pdo = db();
$action = $_GET['action'] ?? '';

if (method() === 'POST' && $action === 'login') {
    $b = json_input();
    $email = strtolower(trim($b['email'] ?? ''));
    $password = (string)($b['password'] ?? '');
    if (!$email || !$password) fail('email_password_required');

    // Basic brute-force lockout (5x gagal -> tunggu 60 detik)
    $_SESSION['login_attempts'] ??= [];
    $attempt = $_SESSION['login_attempts'][$email] ?? ['count' => 0, 'time' => 0];
    if ($attempt['count'] >= 5 && (time() - $attempt['time']) < 60) {
        fail('too_many_attempts', 429);
    }

    $stmt = $pdo->prepare("SELECT * FROM admin_users WHERE email = ? AND status = 'active'");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        $_SESSION['login_attempts'][$email] = ['count' => $attempt['count'] + 1, 'time' => time()];
        fail('invalid_credentials', 401);
    }

    unset($_SESSION['login_attempts'][$email]);
    session_regenerate_id(true);
    $_SESSION['admin'] = ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'role' => $user['role']];
    log_activity($user['email'], 'login', '');
    ok(['user' => $_SESSION['admin']]);
}

if ($action === 'logout') {
    $email = $_SESSION['admin']['email'] ?? 'unknown';
    log_activity($email, 'logout', '');
    unset($_SESSION['admin']);
    ok([]);
}

if (method() === 'GET' && $action === 'me') {
    if (empty($_SESSION['admin'])) fail('unauthenticated', 401);
    ok(['user' => $_SESSION['admin']]);
}

fail('unknown_action', 400);
