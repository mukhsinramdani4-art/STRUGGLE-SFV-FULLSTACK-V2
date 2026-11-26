<?php
$active = 'settings';
$pageTitle = 'Pengaturan';
require __DIR__ . '/config.php';
$u = require_page_access('settings');
$pdo = db();

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($_SESSION['csrf'] ?? '', (string)($_POST['csrf'] ?? ''))) {
    if (($_POST['action'] ?? '') === 'save_settings') {
        $keys = ['group_name', 'store_name', 'store_phone', 'store_whatsapp', 'store_email', 'store_instagram', 'store_link', 'store_address',
                 'origin_city', 'origin_province', 'origin_lat', 'origin_lng', 'payment_due_hours'];
        foreach ($keys as $k) if (isset($_POST[$k])) set_setting($pdo, $k, trim((string)$_POST[$k]));
        set_setting($pdo, 'allow_registration', !empty($_POST['allow_registration']) ? '1' : '0');
        if (!empty($_POST['maps_api_key_clear'])) set_setting($pdo, 'maps_api_key', '');
        elseif (trim((string)($_POST['maps_api_key'] ?? '')) !== '') set_setting($pdo, 'maps_api_key', trim((string)$_POST['maps_api_key']));
        log_activity($u['email'], 'save_settings', '');
        $msg = 'Pengaturan disimpan.';
    } elseif (($_POST['action'] ?? '') === 'add_user') {
        $name = trim($_POST['name'] ?? '');
        $email = strtolower(trim($_POST['email'] ?? ''));
        $role = $_POST['role'] ?? 'Kasir';
        $pass = $_POST['password'] ?? '';
        if ($name && $email && strlen($pass) >= 6) {
            try {
                $pdo->prepare("INSERT INTO admin_users (name, email, password_hash, role) VALUES (?,?,?,?)")
                    ->execute([$name, $email, password_hash($pass, PASSWORD_BCRYPT), $role]);
                $msg = 'Akun staff berhasil ditambahkan.';
            } catch (Throwable $e) { $msg = 'Gagal: email mungkin sudah dipakai.'; }
        } else { $msg = 'Lengkapi nama, email, dan password (min. 6 karakter).'; }
    } elseif (($_POST['action'] ?? '') === 'delete_user') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id !== (int)$u['id']) {
            $pdo->prepare("DELETE FROM admin_users WHERE id = ?")->execute([$id]);
            $msg = 'Akun staff dihapus.';
        }
    }
}
$staff = $pdo->query("SELECT id,name,email,role,status,identity_managed,created_at FROM admin_users ORDER BY id ASC")->fetchAll();
require_once __DIR__ . '/../api/regions.php';
$mapsKeySet = trim((string)setting('maps_api_key', '')) !== '' || getenv('GOOGLE_MAPS_API_KEY');
require __DIR__ . '/partials/header.php';
?>
<div class="page-toolbar"><div><p class="subtitle">Kelola akun staff dan informasi toko.</p></div></div>

<?php if ($msg): ?><div class="card" style="border-color:var(--gold);margin-bottom:18px;background:var(--gold-soft)"><?= e($msg) ?></div><?php endif; ?>

<div class="grid cols-2" style="grid-template-columns: 1.3fr 1fr">
  <div class="card">
    <h3>Akun Staff</h3>
    <p class="subtitle" style="margin-bottom:14px">Setiap staff punya akses modul sesuai role masing-masing.</p>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Nama</th><th>Email</th><th>Role</th><th>Aksi</th></tr></thead>
        <tbody>
        <?php foreach ($staff as $s): ?>
          <tr>
            <td><?= e(admin_display_name($s)) ?></td><td><?= e($s['email']) ?></td>
            <td><span class="badge info"><?= e($s['role']) ?></span></td>
            <td>
              <?php if ((int)$s['id'] !== (int)$u['id']): ?>
              <form method="post" onsubmit="return confirm('Hapus akun ini?')">
                <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                <button class="btn-ghost"><span class="material-symbols-outlined">delete</span></button>
              </form>
              <?php else: ?><span class="subtitle">Anda</span><?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <h3>Tambah Akun Staff</h3>
    <form method="post" class="form-grid single" style="margin-top:14px">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="add_user">
      <div class="field"><label>Nama</label><input name="name" required></div>
      <div class="field"><label>Email</label><input name="email" type="email" required></div>
      <div class="field"><label>Role</label>
        <select name="role">
          <option>Manager</option><option>Gudang</option><option>Kasir</option><option>Marketing</option><option>Finance</option><option>Super Admin</option>
        </select>
      </div>
      <div class="field"><label>Password</label><input name="password" type="password" required minlength="6"></div>
      <button class="btn-primary full" type="submit"><span class="material-symbols-outlined">person_add</span> Tambah Staff</button>
    </form>
  </div>
</div>

<form method="post" class="card" style="margin-top:22px">
  <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="action" value="save_settings">
  <h3>Identitas Kelompok &amp; Toko</h3>
  <p class="subtitle" style="margin:6px 0 14px">Nama kelompok tampil di dashboard, profil admin, dan informasi pengelola. Data toko tampil di footer storefront.</p>
  <div class="form-grid">
    <div class="field"><label>Nama Kelompok</label><input name="group_name" value="<?= e(setting('group_name')) ?>" required></div>
    <div class="field"><label>Nama Toko</label><input name="store_name" value="<?= e(setting('store_name')) ?>"></div>
    <div class="field"><label>Telepon</label><input name="store_phone" value="<?= e(setting('store_phone')) ?>"></div>
    <div class="field"><label>WhatsApp (format 62812…)</label><input name="store_whatsapp" value="<?= e(setting('store_whatsapp')) ?>"></div>
    <div class="field"><label>Email Toko</label><input name="store_email" value="<?= e(setting('store_email')) ?>"></div>
    <div class="field"><label>Instagram (tanpa @)</label><input name="store_instagram" value="<?= e(setting('store_instagram')) ?>"></div>
    <div class="field"><label>Link (Linktree/website)</label><input name="store_link" value="<?= e(setting('store_link')) ?>"></div>
    <div class="field"><label>Alamat Toko</label><input name="store_address" value="<?= e(setting('store_address')) ?>"></div>
  </div>
  <h3 style="margin-top:22px">Asal Pengiriman (untuk estimasi ongkir)</h3>
  <div class="form-grid">
    <div class="field"><label>Kota / Kabupaten asal</label><input name="origin_city" value="<?= e(setting('origin_city')) ?>"></div>
    <div class="field"><label>Provinsi asal</label>
      <select name="origin_province"><?php foreach (array_keys(indonesia_provinces()) as $pv): ?><option <?= canonical_province((string)setting('origin_province')) === $pv ? 'selected' : '' ?>><?= e($pv) ?></option><?php endforeach; ?></select></div>
    <div class="field"><label>Latitude asal</label><input name="origin_lat" value="<?= e(setting('origin_lat')) ?>"></div>
    <div class="field"><label>Longitude asal</label><input name="origin_lng" value="<?= e(setting('origin_lng')) ?>"></div>
  </div>
  <h3 style="margin-top:22px">Google Maps &amp; Pembayaran</h3>
  <div class="form-grid">
    <div class="field span-2"><label>Google Maps API Key <?= $mapsKeySet ? '(sudah terisi — kosongkan untuk tidak mengubah)' : '(belum terisi — peta Google nonaktif)' ?></label>
      <input name="maps_api_key" type="password" autocomplete="off" placeholder="<?= $mapsKeySet ? '••••••••••••' : 'AIza…' ?>">
      <p class="subtitle" style="margin-top:6px">Aktifkan Maps JavaScript API, Places API &amp; Geocoding API, lalu batasi key dengan HTTP referrer domain toko. Alternatif: set environment variable <code>GOOGLE_MAPS_API_KEY</code> di server.</p>
      <?php if ($mapsKeySet): ?><label class="checkbox-row" style="margin-top:6px"><input type="checkbox" name="maps_api_key_clear" value="1"> Hapus API key tersimpan</label><?php endif; ?></div>
    <div class="field"><label>Batas waktu bayar (jam)</label><input name="payment_due_hours" type="number" min="1" value="<?= e(setting('payment_due_hours')) ?>"></div>
    <div class="field"><label class="checkbox-row" style="margin-top:26px"><input type="checkbox" name="allow_registration" value="1" <?= setting('allow_registration') === '1' ? 'checked' : '' ?>> Izinkan pendaftaran akun pelanggan baru (di luar 39 akun kelas)</label></div>
  </div>
  <button class="btn-primary" type="submit" style="margin-top:18px"><span class="material-symbols-outlined">save</span> Simpan Pengaturan</button>
</form>

<div class="card" style="margin-top:22px">
  <h3>Tentang Sistem</h3>
  <p class="subtitle" style="margin-top:6px">SFV Business OS — panel admin terintegrasi untuk toko STRUGGLE / SFV STUDIO. Semua data tersimpan di database SQLite lokal (<code>data/struggle.sqlite</code>), bisa dipindahkan ke MySQL untuk hosting produksi (lihat <code>database.sql</code> &amp; README).</p>
</div>
<?php require __DIR__ . '/partials/footer.php'; ?>
