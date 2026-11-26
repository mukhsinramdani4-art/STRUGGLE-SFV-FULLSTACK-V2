<?php
$active = 'customers';
$pageTitle = 'Pelanggan';
require __DIR__ . '/config.php';
$__me = require_page_access('customers');
$canManage = in_array($__me['role'], ['Super Admin', 'Owner', 'Manager'], true);
require __DIR__ . '/partials/header.php';
?>
<div class="page-toolbar">
  <div><p class="subtitle">39 akun pelanggan kelas + akun lain yang terdaftar. Data transaksi tiap pelanggan terpisah dan hanya dapat dilihat oleh admin.</p></div>
  <div class="toolbar-actions">
    <input id="search-box" placeholder="Cari nama / username / email..." style="padding:11px 14px;border:1px solid var(--border);border-radius:10px;min-width:240px">
    <?php if ($canManage): ?><a class="btn-primary" href="credentials.php"><span class="material-symbols-outlined">key</span> Kredensial Akun Kelas</a><?php endif; ?>
  </div>
</div>
<div class="grid cols-4" id="cust-stats" style="margin-bottom:18px"></div>
<div class="table-wrap">
  <table class="data-table">
    <thead><tr><th>#</th><th>Nama</th><th>Username / Email</th><th>Telepon</th><th>Order</th><th>Total Belanja</th><th>Login Terakhir</th><th>Status</th><th>Aksi</th></tr></thead>
    <tbody id="tbl-body"><tr><td colspan="9"><div class="skeleton" style="height:50px"></div></td></tr></tbody>
  </table>
</div>
<script>
const CAN_MANAGE = <?= $canManage ? 'true' : 'false' ?>;
let ROWS = [];
async function load() {
  const res = await Admin.api('customers.php');
  ROWS = res.customers;
  const cls = ROWS.filter(c => c.is_class_account == 1);
  document.getElementById('cust-stats').innerHTML = [
    ['Akun kelas', cls.length + ' / 39', 'group'], ['Sudah login', cls.filter(c => c.last_login_at).length, 'login'],
    ['Belum ganti password awal', cls.filter(c => c.must_change_password == 1).length, 'lock_reset'], ['Total pesanan', ROWS.reduce((a, c) => a + Number(c.order_count), 0), 'receipt_long'],
  ].map(([l, v, i]) => `<div class="stat-card"><div class="icon-wrap"><span class="material-symbols-outlined">${i}</span></div><div class="label">${l}</div><div class="value">${v}</div></div>`).join('');
  render();
}
function render() {
  const q = (document.getElementById('search-box').value || '').toLowerCase();
  const rows = ROWS.filter(c => !q || [c.name, c.username, c.email].some(v => (v || '').toLowerCase().includes(q)));
  document.getElementById('tbl-body').innerHTML = rows.length ? rows.map(c => `
    <tr>
      <td>${c.seq_no || '–'}</td>
      <td><b>${Admin.escape(c.name)}</b></td>
      <td>${Admin.escape(c.username || '')}<div style="font-size:11px;color:var(--text-mute)">${Admin.escape(c.email)}</div></td>
      <td>${Admin.escape(c.phone || '-')}</td>
      <td>${c.order_count}</td><td>${Admin.rupiah(c.total_spend)}</td>
      <td>${c.last_login_at ? Admin.dateTimeFmt(c.last_login_at) : '<span style="color:var(--text-mute)">belum pernah</span>'}</td>
      <td><span class="badge ${c.status === 'active' ? 'success' : 'neutral'}">${c.status === 'active' ? 'Aktif' : 'Nonaktif'}</span></td>
      <td><button class="btn-ghost" title="Detail" onclick="viewCustomer(${c.id})"><span class="material-symbols-outlined">visibility</span></button>
          ${CAN_MANAGE || true ? `<button class="btn-ghost" title="Ubah" onclick="editCustomer(${c.id})"><span class="material-symbols-outlined">edit</span></button>` : ''}</td>
    </tr>`).join('') : `<tr><td colspan="9"><div class="empty-state"><span class="material-symbols-outlined">group</span><p>Tidak ada pelanggan.</p></div></td></tr>`;
}
document.getElementById('search-box').addEventListener('input', render);

async function viewCustomer(id) {
  const r = await Admin.api('customers.php?id=' + id);
  const c = r.customer;
  const body = `
    <div style="display:grid;gap:6px;font-size:13.5px;margin-bottom:16px">
      <div><b>Nama:</b> ${Admin.escape(c.name)} ${c.seq_no ? `<span class="badge neutral">Akun kelas #${c.seq_no}</span>` : ''}</div>
      <div><b>Username:</b> ${Admin.escape(c.username || '-')} · <b>Email:</b> ${Admin.escape(c.email)}</div>
      <div><b>Telepon:</b> ${Admin.escape(c.phone || '-')} · <b>Bergabung:</b> ${Admin.dateFmt(c.created_at)}</div>
      <div><b>Login terakhir:</b> ${c.last_login_at ? Admin.dateTimeFmt(c.last_login_at) : 'belum pernah'}</div>
    </div>
    <h4 style="margin:14px 0 8px">Alamat Tersimpan (${r.addresses.length})</h4>
    ${r.addresses.map(a => `<div class="card" style="padding:12px;margin-bottom:8px;font-size:13px"><b>${Admin.escape(a.label || 'Alamat')}</b>${a.is_default == 1 ? ' <span class="badge info">Utama</span>' : ''}<br>
      ${Admin.escape(a.recipient)} · ${Admin.escape(a.phone)}<br>${Admin.escape(a.address)}, ${Admin.escape([a.village, a.district, a.city, a.province, a.postal_code].filter(Boolean).join(', '))}
      ${a.lat != null ? `<br><a target="_blank" rel="noopener" href="https://www.google.com/maps?q=${a.lat},${a.lng}">📍 ${Number(a.lat).toFixed(6)}, ${Number(a.lng).toFixed(6)}</a>` : ''}</div>`).join('') || '<p class="subtitle">Belum ada alamat.</p>'}
    <h4 style="margin:14px 0 8px">Riwayat Transaksi (${r.orders.length})</h4>
    <div class="table-wrap"><table class="data-table"><thead><tr><th>ID</th><th>Total</th><th>Pesanan</th><th>Bayar</th><th>Kirim</th><th>Tanggal</th></tr></thead><tbody>
    ${r.orders.map(o => `<tr><td><a href="orders.php?open=${encodeURIComponent(o.id)}"><b>${o.id}</b></a></td><td>${Admin.rupiah(o.total)}</td><td>${Admin.badge(o.status)}</td><td>${Admin.badge(o.payment_status)}</td><td>${Admin.badge(o.shipping_status)}</td><td>${Admin.dateFmt(o.created_at)}</td></tr>`).join('') || '<tr><td colspan="6" class="subtitle">Belum ada transaksi.</td></tr>'}
    </tbody></table></div>`;
  Admin.modal({ title: 'Pelanggan: ' + Admin.escape(c.name), bodyHtml: body, wide: true });
}

function editCustomer(id) {
  const c = ROWS.find(x => x.id == id);
  const m = Admin.modal({ title: 'Ubah Pelanggan', onMount: (ov, close) => {
    ov.querySelector('#cust-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      try {
        await Admin.api('customers.php?id=' + id, { method: 'PUT', body: { name: fd.get('name'), email: fd.get('email'), phone: fd.get('phone'), status: fd.get('status') } });
        Admin.toast('Data pelanggan disimpan.'); close(); load();
      } catch (err) { Admin.toast('Gagal: ' + err.message, 'error'); }
    });
    const rp = ov.querySelector('#btn-reset');
    if (rp) rp.addEventListener('click', async () => {
      if (!confirm('Buat password baru untuk ' + c.name + '? Password lama tidak berlaku lagi.')) return;
      try {
        const r = await Admin.api('customers.php?action=reset_password&id=' + id, { method: 'POST', body: {} });
        ov.querySelector('#reset-result').innerHTML = `<div class="card" style="border-color:var(--gold);background:var(--gold-soft);margin-top:12px">Password baru (tampil sekali, salin sekarang):<br><code style="font-size:18px;user-select:all">${Admin.escape(r.password)}</code><br><span class="subtitle">Pelanggan diminta menggantinya saat login berikutnya.</span></div>`;
      } catch (err) { Admin.toast('Gagal: ' + err.message, 'error'); }
    });
  }, bodyHtml: `
    <form id="cust-form"><div class="form-grid">
      <div class="field span-2"><label>Nama Pelanggan</label><input name="name" required value="${Admin.escape(c.name)}"></div>
      <div class="field"><label>Email</label><input name="email" type="email" required value="${Admin.escape(c.email)}"></div>
      <div class="field"><label>Telepon</label><input name="phone" value="${Admin.escape(c.phone || '')}"></div>
      <div class="field"><label>Status Akun</label><select name="status"><option value="active" ${c.status === 'active' ? 'selected' : ''}>Aktif</option><option value="inactive" ${c.status !== 'active' ? 'selected' : ''}>Nonaktif (tidak bisa login)</option></select></div>
      <div class="field"><label>Username (tetap)</label><input value="${Admin.escape(c.username || '-')}" disabled></div>
    </div>
    <div class="modal-footer" style="display:flex;gap:10px;justify-content:space-between;margin-top:18px">
      ${CAN_MANAGE ? '<button type="button" id="btn-reset" class="btn-secondary"><span class="material-symbols-outlined">lock_reset</span> Reset Password</button>' : '<span></span>'}
      <button class="btn-primary" type="submit">Simpan</button>
    </div><div id="reset-result"></div></form>` });
}
load();
// buka detail langsung dari link ?open=
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
