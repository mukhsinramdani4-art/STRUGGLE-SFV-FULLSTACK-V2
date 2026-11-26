<?php
$active = 'profile';
$pageTitle = 'Profil Admin';
require __DIR__ . '/config.php';
$u = require_login();
$pdo = db();
$msg = ''; $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
    $cur = (string)($_POST['current'] ?? '');
    $new = (string)($_POST['new'] ?? '');
    $st = $pdo->prepare("SELECT password_hash FROM admin_users WHERE id = ?");
    $st->execute([(int)$u['id']]);
    $hash = (string)$st->fetchColumn();
    if (!password_verify($cur, $hash)) $err = 'Password saat ini salah.';
    elseif (strlen($new) < 8) $err = 'Password baru minimal 8 karakter.';
    elseif ($new !== ($_POST['confirm'] ?? '')) $err = 'Konfirmasi password tidak sama.';
    else {
        $pdo->prepare("UPDATE admin_users SET password_hash = ? WHERE id = ?")->execute([password_hash($new, PASSWORD_BCRYPT), (int)$u['id']]);
        log_activity($u['email'], 'admin_change_password', '');
        $msg = 'Password berhasil diganti.';
    }
}
require __DIR__ . '/partials/header.php';
$st = $pdo->prepare("SELECT * FROM admin_users WHERE id = ?");
$st->execute([(int)$u['id']]);
$row = $st->fetch();
?>
<div class="page-toolbar"><div><p class="subtitle">Informasi akun pengelola dashboard.</p></div></div>
<?php if ($msg): ?><div class="card" style="border-color:var(--success);margin-bottom:18px"><?= e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="card" style="border-color:var(--danger);margin-bottom:18px"><?= e($err) ?></div><?php endif; ?>
<div class="grid cols-2">
  <div class="card">
    <h3>Identitas Pengelola</h3>
    <div style="display:grid;gap:10px;margin-top:14px;font-size:14px">
      <div><span class="subtitle">Kelompok</span><br><b><?= e(group_name()) ?></b></div>
      <div><span class="subtitle">Nama tampilan</span><br><b><?= e(admin_display_name($row)) ?></b></div>
      <div><span class="subtitle">Role</span><br><span class="badge info"><?= e($row['role']) ?></span></div>
      <div><span class="subtitle">Email login</span><br><b><?= e($row['email']) ?></b></div>
    </div>
    <?php if (in_array($u['role'], ['Owner', 'Super Admin'], true)): ?>
      <p class="subtitle" style="margin-top:14px">Nama kelompok dapat diubah di <a href="settings.php" style="color:var(--gold-dark,inherit);text-decoration:underline">Pengaturan</a>.</p>
    <?php endif; ?>
  </div>
  <div class="card">
    <h3>Ganti Password</h3>
    <form method="post" class="form-grid single" style="margin-top:14px">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <div class="field"><label>Password saat ini</label><input type="password" name="current" required autocomplete="current-password"></div>
      <div class="field"><label>Password baru (min. 8 karakter)</label><input type="password" name="new" required minlength="8" autocomplete="new-password"></div>
      <div class="field"><label>Ulangi password baru</label><input type="password" name="confirm" required minlength="8" autocomplete="new-password"></div>
      <button class="btn-primary full" type="submit">Simpan Password</button>
    </form>
  </div>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
