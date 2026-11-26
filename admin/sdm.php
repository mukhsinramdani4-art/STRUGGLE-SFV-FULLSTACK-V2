<?php
$active = 'sdm';
$pageTitle = 'SDM';
require __DIR__ . '/config.php';
require __DIR__ . '/partials/header.php';
?>
<div class="page-toolbar">
  <div><p class="subtitle">Data karyawan, jabatan, dan status kepegawaian.</p></div>
  <div class="toolbar-actions"><button class="btn-primary" id="btn-add"><span class="material-symbols-outlined">person_add</span> Tambah Karyawan</button></div>
</div>

<div class="grid cols-3" id="summary-grid" style="margin-bottom:22px"></div>

<div class="table-wrap">
  <table class="data-table">
    <thead><tr><th>Nama</th><th>Jabatan</th><th>Departemen</th><th>Kontak</th><th>Bergabung</th><th>Gaji</th><th>Status</th><th>Aksi</th></tr></thead>
    <tbody id="tbl-body"><tr><td colspan="8"><div class="skeleton" style="height:50px"></div></td></tr></tbody>
  </table>
</div>

<script>
const DEPTS = ['Manajemen','Operasional','Gudang','Penjualan','Marketing','Keuangan','Produksi'];
let ROWS = [];
async function load() {
  const res = await Admin.api('employees.php');
  ROWS = res.employees;
  document.getElementById('summary-grid').innerHTML = `
    <div class="stat-card" style="--stat-color:#3b6fd1"><div class="icon-wrap"><span class="material-symbols-outlined">groups</span></div><div class="label">Total Karyawan</div><div class="value">${res.summary.total}</div></div>
    <div class="stat-card" style="--stat-color:#1f8a5f"><div class="icon-wrap"><span class="material-symbols-outlined">badge</span></div><div class="label">Karyawan Aktif</div><div class="value">${res.summary.aktif}</div></div>
    <div class="stat-card" style="--stat-color:#c4a77d"><div class="icon-wrap"><span class="material-symbols-outlined">payments</span></div><div class="label">Total Payroll / Bulan</div><div class="value">${Admin.rupiah(res.summary.total_payroll)}</div></div>
  `;
  render();
}
function render() {
  document.getElementById('tbl-body').innerHTML = ROWS.length ? ROWS.map(e => `
    <tr>
      <td><b>${Admin.escape(e.name)}</b></td><td>${Admin.escape(e.position||'-')}</td><td>${Admin.escape(e.department||'-')}</td>
      <td>${Admin.escape(e.phone||'-')}<div style="font-size:11px;color:var(--text-mute)">${Admin.escape(e.email||'')}</div></td>
      <td>${e.join_date||'-'}</td><td>${Admin.rupiah(e.salary)}</td>
      <td><span class="badge ${e.status==='Aktif'?'success':'neutral'}">${e.status}</span></td>
      <td style="white-space:nowrap"><button class="btn-ghost" onclick='openForm(${JSON.stringify(e)})'><span class="material-symbols-outlined">edit</span></button><button class="btn-ghost" onclick="del(${e.id})"><span class="material-symbols-outlined">delete</span></button></td>
    </tr>`).join('') : `<tr><td colspan="8"><div class="empty-state"><span class="material-symbols-outlined">badge</span><p>Belum ada data karyawan.</p></div></td></tr>`;
}
function openForm(e) {
  const body = `<form id="e-form"><div class="form-grid">
    <div class="field span-2"><label>Nama Lengkap</label><input name="name" required value="${e?Admin.escape(e.name):''}"></div>
    <div class="field"><label>Jabatan</label><input name="position" value="${e?Admin.escape(e.position||''):''}"></div>
    <div class="field"><label>Departemen</label><select name="department">${DEPTS.map(d=>`<option ${e&&e.department===d?'selected':''}>${d}</option>`).join('')}</select></div>
    <div class="field"><label>Email</label><input name="email" type="email" value="${e?Admin.escape(e.email||''):''}"></div>
    <div class="field"><label>Telepon</label><input name="phone" value="${e?Admin.escape(e.phone||''):''}"></div>
    <div class="field"><label>Tanggal Bergabung</label><input name="join_date" type="date" value="${e?e.join_date:''}"></div>
    <div class="field"><label>Gaji / Bulan (Rp)</label><input name="salary" type="number" value="${e?e.salary:0}"></div>
    <div class="field"><label>Status</label><select name="status"><option ${!e||e.status==='Aktif'?'selected':''}>Aktif</option><option ${e&&e.status==='Nonaktif'?'selected':''}>Nonaktif</option><option ${e&&e.status==='Cuti'?'selected':''}>Cuti</option></select></div>
    <div class="field span-2"><label>Alamat</label><input name="address" value="${e?Admin.escape(e.address||''):''}"></div>
    <div class="field span-2"><label>Catatan</label><textarea name="notes">${e?Admin.escape(e.notes||''):''}</textarea></div>
  </div></form>`;
  const { close, overlay } = Admin.modal({ title: e ? 'Edit Karyawan' : 'Tambah Karyawan', bodyHtml: body, wide: true });
  overlay.querySelector('.modal-body').insertAdjacentHTML('afterend', `<div class="modal-footer"><button class="btn-secondary" data-close>Batal</button><button class="btn-primary" id="save">Simpan</button></div>`);
  overlay.querySelector('#save').addEventListener('click', async () => {
    const f = overlay.querySelector('#e-form');
    const body = Object.fromEntries(new FormData(f).entries());
    try {
      if (e) await Admin.api('employees.php?id=' + e.id, { method: 'PUT', body });
      else await Admin.api('employees.php', { method: 'POST', body });
      Admin.toast('Data karyawan disimpan.'); close(); load();
    } catch (err) { Admin.toast('Gagal: ' + err.message, 'error'); }
  });
}
async function del(id) {
  if (!Admin.confirmDelete('Hapus data karyawan ini?')) return;
  await Admin.api('employees.php?id=' + id, { method: 'DELETE' });
  Admin.toast('Karyawan dihapus.'); load();
}
document.getElementById('btn-add').addEventListener('click', () => openForm(null));
load();
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
