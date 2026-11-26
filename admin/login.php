<?php
require __DIR__ . '/config.php';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
        $error = 'Sesi kedaluwarsa, silakan coba lagi.';
    } else {
        $email = strtolower(trim($_POST['email'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $_SESSION['login_attempts'] ??= [];
        $attempt = $_SESSION['login_attempts'][$email] ?? ['count' => 0, 'time' => 0];
        if ($attempt['count'] >= 5 && (time() - $attempt['time']) < 60) {
            $error = 'Terlalu banyak percobaan gagal. Coba lagi dalam 1 menit.';
        } else {
            $stmt = db()->prepare("SELECT * FROM admin_users WHERE email = ? AND status='active'");
            $stmt->execute([$email]);
            $user = $stmt->fetch();
            if ($user && password_verify($password, $user['password_hash'])) {
                unset($_SESSION['login_attempts'][$email]);
                session_regenerate_id(true);
                $_SESSION['admin'] = ['id' => $user['id'], 'name' => $user['name'], 'email' => $user['email'], 'role' => $user['role']];
                log_activity($user['email'], 'login', '');
                header('Location: index.php');
                exit;
            }
            $_SESSION['login_attempts'][$email] = ['count' => $attempt['count'] + 1, 'time' => time()];
            $error = 'Email atau password salah.';
        }
    }
}
if (current_admin()) { header('Location: index.php'); exit; }
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Masuk — SFV Business OS</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" rel="stylesheet">
<link rel="stylesheet" href="assets/css/admin.css">
</head>
<body class="login-body">
  <div class="login-bg-fx"></div>
  <div class="login-card">
    <div class="login-brand">
      <img src="../assets/images/logo.png" alt="SFV">
      <div><b>SFV Business OS</b><span>Struggle For Victory · <?= e(group_name()) ?></span></div>
    </div>
    <?php if ($error): ?><div class="login-error"><span class="material-symbols-outlined">error</span><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="login-form">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <label>Email<input type="email" name="email" required autofocus placeholder="email staff" value="<?= e($_POST['email'] ?? '') ?>"></label>
      <label>Password<input type="password" name="password" required placeholder="••••••••"></label>
      <button type="submit" class="btn-primary full">Masuk <span class="material-symbols-outlined">arrow_forward</span></button>
    </form>
    <div class="login-demo"><p>Dikelola oleh <b><?= e(group_name()) ?></b></p><p style="margin-top:4px">Gunakan akun staff yang diberikan oleh Owner / Super Admin.</p></div>
    <a class="back-store" href="../index.html"><span class="material-symbols-outlined">storefront</span> Kembali ke toko</a>
  </div>
</body>
</html>
