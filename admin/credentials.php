<?php
/**
 * Daftar kredensial awal 39 akun kelas — KHUSUS Owner / Super Admin / Manager.
 * Password awal hanya tersimpan selama pelanggan belum menggantinya sendiri.
 * Setiap akses dicatat di activity_log. Halaman ini tidak pernah tampil publik.
 */
$active = 'customers';
$pageTitle = 'Kredensial Akun Kelas';
require __DIR__ . '/config.php';
$u = require_login();
if (!in_array($u['role'], ['Super Admin', 'Owner', 'Manager'], true)) { http_response_code(403); render_forbidden($u); exit; }
$pdo = db();

$rows = $pdo->query("SELECT c.seq_no, c.name, c.username, c.email, cc.initial_password, c.must_change_password, c.last_login_at, c.status
    FROM customers c LEFT JOIN customer_credentials cc ON cc.customer_id = c.id
    WHERE c.is_class_account = 1 ORDER BY c.seq_no ASC")->fetchAll();

if (($_GET['format'] ?? '') === 'csv') {
    log_activity($u['email'], 'export_customer_credentials', count($rows) . ' akun');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="kredensial-akun-kelas.csv"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['No', 'Nama', 'Username', 'Email', 'Password Awal', 'Status']);
    foreach ($rows as $r) {
        fputcsv($out, [$r['seq_no'], $r['name'], $r['username'], $r['email'],
            $r['initial_password'] ?? '(sudah diganti pelanggan)', $r['status'] === 'active' ? 'Aktif' : 'Nonaktif']);
    }
    exit;
}
log_activity($u['email'], 'view_customer_credentials', count($rows) . ' akun');
require __DIR__ . '/partials/header.php';
?>
<div class="page-toolbar">
  <div><p class="subtitle">Bagikan kredensial ini hanya kepada masing-masing anggota kelas. Password awal otomatis terhapus dari sini begitu pelanggan menggantinya.</p></div>
  <div class="toolbar-actions">
    <button class="btn-secondary" id="btn-reveal"><span class="material-symbols-outlined">visibility</span> Tampilkan Password</button>
    <a class="btn-primary" href="credentials.php?format=csv"><span class="material-symbols-outlined">download</span> Unduh CSV</a>
  </div>
</div>
<div class="table-wrap">
  <table class="data-table">
    <thead><tr><th>No</th><th>Nama</th><th>Username</th><th>Email</th><th>Password Awal</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><?= (int)$r['seq_no'] ?></td><td><?= e($r['name']) ?></td><td><code><?= e($r['username']) ?></code></td><td><?= e($r['email']) ?></td>
        <td><?php if ($r['initial_password']): ?><code class="pw" data-pw="<?= e($r['initial_password']) ?>">••••••••••</code>
            <?php else: ?><span class="badge success">Sudah diganti pelanggan</span><?php endif; ?></td>
        <td><?= $r['last_login_at'] ? '<span class="badge success">Pernah login</span>' : '<span class="badge neutral">Belum login</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div class="card" style="margin-top:22px">
  <h3>Ganti Nama Sesuai Daftar Mahasiswa</h3>
  <p class="subtitle" style="margin:6px 0 12px">Tempel nama mahasiswa, satu nama per baris, berurutan mulai dari akun #1. Hanya <b>nama</b> yang berubah — username, email, dan password tetap.</p>
  <textarea id="names" rows="8" style="width:100%;padding:12px;border:1px solid var(--border);border-radius:10px" placeholder="Nama mahasiswa 1&#10;Nama mahasiswa 2&#10;..."></textarea>
  <button class="btn-primary" id="btn-rename" style="margin-top:12px">Terapkan Nama</button>
</div>
<script>
let shown = false;
document.getElementById('btn-reveal').addEventListener('click', (e) => {
  shown = !shown;
  document.querySelectorAll('.pw').forEach(el => el.textContent = shown ? el.dataset.pw : '••••••••••');
  e.currentTarget.innerHTML = `<span class="material-symbols-outlined">${shown ? 'visibility_off' : 'visibility'}</span> ${shown ? 'Sembunyikan' : 'Tampilkan'} Password`;
});
document.getElementById('btn-rename').addEventListener('click', async () => {
  const lines = document.getElementById('names').value.split('\n').map(s => s.trim()).filter(Boolean);
  if (!lines.length) return Admin.toast('Isi daftar nama dulu.', 'error');
  if (lines.length > 39) return Admin.toast('Maksimal 39 nama.', 'error');
  if (!confirm('Ganti nama ' + lines.length + ' akun pertama?')) return;
  try {
    const r = await Admin.api('customers.php?action=bulk_rename', { method: 'POST', body: { names: lines.map((name, i) => ({ seq: i + 1, name })) } });
    Admin.toast(r.updated + ' nama diperbarui.'); setTimeout(() => location.reload(), 700);
  } catch (e) { Admin.toast('Gagal: ' + e.message, 'error'); }
});
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
