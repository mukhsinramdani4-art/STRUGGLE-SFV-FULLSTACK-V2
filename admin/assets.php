<?php
$active = 'assets';
$pageTitle = 'Aset';
require __DIR__ . '/config.php';
require __DIR__ . '/partials/header.php';
?>
<div class="page-toolbar">
  <div><p class="subtitle">Daftar aset perusahaan: peralatan, kendaraan, elektronik, dan furnitur.</p></div>
  <div class="toolbar-actions"><button class="btn-primary" id="btn-add"><span class="material-symbols-outlined">add</span> Tambah Aset</button></div>
</div>

<div class="grid cols-3" id="summary-grid" style="margin-bottom:22px"></div>

<div class="table-wrap">
  <table class="data-table">
    <thead><tr><th>Kode</th><th>Nama Aset</th><th>Kategori</th><th>Lokasi</th><th>Nilai Beli</th><th>Nilai Sekarang</th><th>Kondisi</th><th>Status</th><th>Aksi</th></tr></thead>
    <tbody id="tbl-body"><tr><td colspan="9"><div class="skeleton" style="height:50px"></div></td></tr></tbody>
  </table>
</div>

<script>
const CATS = ['Peralatan','Elektronik','Furnitur','Kendaraan','Bangunan','Lainnya'];
const CONDITIONS = ['Baik','Perlu Servis','Rusak'];
let ROWS = [];

async function load() {
  const res = await Admin.api('assets.php');
  ROWS = res.assets;
  document.getElementById('summary-grid').innerHTML = `
    <div class="stat-card" style="--stat-color:#c4a77d"><div class="icon-wrap"><span class="material-symbols-outlined">inventory</span></div><div class="label">Total Aset</div><div class="value">${res.summary.total}</div></div>
    <div class="stat-card" style="--stat-color:#1f8a5f"><div class="icon-wrap"><span class="material-symbols-outlined">payments</span></div><div class="label">Total Nilai Aset</div><div class="value">${Admin.rupiah(res.summary.total_value)}</div></div>
    <div class="stat-card" style="--stat-color:#c8384a"><div class="icon-wrap"><span class="material-symbols-outlined">build</span></div><div class="label">Perlu Perhatian</div><div class="value">${res.summary.perlu_servis}</div></div>
  `;
  render();
}
function render() {
  document.getElementById('tbl-body').innerHTML = ROWS.length ? ROWS.map(a => `
    <tr>
      <td><b>${a.asset_code}</b></td><td>${Admin.escape(a.name)}</td><td>${Admin.escape(a.category)}</td><td>${Admin.escape(a.location||'-')}</td>
      <td>${Admin.rupiah(a.purchase_value)}</td><td>${Admin.rupiah(a.current_value)}</td>
      <td><span class="badge ${a.condition_status==='Baik'?'success':a.condition_status==='Rusak'?'danger':'warning'}">${a.condition_status}</span></td>
      <td><span class="badge ${a.status==='Aktif'?'success':'neutral'}">${a.status}</span></td>
      <td style="white-space:nowrap"><button class="btn-ghost" onclick='openForm(${JSON.stringify(a)})'><span class="material-symbols-outlined">edit</span></button><button class="btn-ghost" onclick="del(${a.id})"><span class="material-symbols-outlined">delete</span></button></td>
    </tr>`).join('') : `<tr><td colspan="9"><div class="empty-state"><span class="material-symbols-outlined">inventory</span><p>Belum ada aset tercatat.</p></div></td></tr>`;
}

function openForm(a) {
  const body = `<form id="a-form"><div class="form-grid">
    <div class="field span-2"><label>Nama Aset</label><input name="name" required value="${a ? Admin.escape(a.name) : ''}"></div>
    <div class="field"><label>Kategori</label><select name="category">${CATS.map(c=>`<option ${a&&a.category===c?'selected':''}>${c}</option>`).join('')}</select></div>
    <div class="field"><label>Lokasi</label><input name="location" value="${a ? Admin.escape(a.location||'') : ''}"></div>
    <div class="field"><label>Dipegang Oleh</label><input name="assigned_to" value="${a ? Admin.escape(a.assigned_to||'') : ''}"></div>
    <div class="field"><label>Tanggal Beli</label><input name="purchase_date" type="date" value="${a ? a.purchase_date : ''}"></div>
    <div class="field"><label>Nilai Beli (Rp)</label><input name="purchase_value" type="number" value="${a ? a.purchase_value : 0}"></div>
    <div class="field"><label>Nilai Sekarang (Rp)</label><input name="current_value" type="number" value="${a ? a.current_value : 0}"></div>
    <div class="field"><label>Kondisi</label><select name="condition_status">${CONDITIONS.map(c=>`<option ${a&&a.condition_status===c?'selected':''}>${c}</option>`).join('')}</select></div>
    <div class="field"><label>Status</label><select name="status"><option ${!a||a.status==='Aktif'?'selected':''}>Aktif</option><option ${a&&a.status==='Nonaktif'?'selected':''}>Nonaktif</option></select></div>
    <div class="field span-2"><label>Catatan</label><textarea name="notes">${a ? Admin.escape(a.notes||'') : ''}</textarea></div>
  </div></form>`;
  const { close, overlay } = Admin.modal({ title: a ? 'Edit Aset' : 'Tambah Aset', bodyHtml: body, wide: true });
  overlay.querySelector('.modal-body').insertAdjacentHTML('afterend', `<div class="modal-footer"><button class="btn-secondary" data-close>Batal</button><button class="btn-primary" id="save">Simpan</button></div>`);
  overlay.querySelector('#save').addEventListener('click', async () => {
    const f = overlay.querySelector('#a-form');
    const body = Object.fromEntries(new FormData(f).entries());
    try {
      if (a) await Admin.api('assets.php?id=' + a.id, { method: 'PUT', body });
      else await Admin.api('assets.php', { method: 'POST', body });
      Admin.toast('Aset disimpan.'); close(); load();
    } catch (e) { Admin.toast('Gagal: ' + e.message, 'error'); }
  });
}
async function del(id) {
  if (!Admin.confirmDelete('Hapus aset ini?')) return;
  await Admin.api('assets.php?id=' + id, { method: 'DELETE' });
  Admin.toast('Aset dihapus.'); load();
}
document.getElementById('btn-add').addEventListener('click', () => openForm(null));
load();
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
