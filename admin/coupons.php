<?php
$active = 'coupons';
$pageTitle = 'Kupon & Promo';
require __DIR__ . '/config.php';
require __DIR__ . '/partials/header.php';
?>
<div class="page-toolbar">
  <div><p class="subtitle">Kode diskon yang dapat dipakai pelanggan saat checkout.</p></div>
  <div class="toolbar-actions"><button class="btn-primary" id="btn-add"><span class="material-symbols-outlined">add</span> Tambah Kupon</button></div>
</div>
<div class="table-wrap">
  <table class="data-table">
    <thead><tr><th>Kode</th><th>Deskripsi</th><th>Diskon</th><th>Min. Belanja</th><th>Berlaku s/d</th><th>Terpakai</th><th>Status</th><th>Aksi</th></tr></thead>
    <tbody id="tbl-body"><tr><td colspan="8"><div class="skeleton" style="height:50px"></div></td></tr></tbody>
  </table>
</div>
<script>
async function load() {
  const res = await Admin.api('coupons.php');
  document.getElementById('tbl-body').innerHTML = res.coupons.length ? res.coupons.map(c => `
    <tr>
      <td><b>${c.code}</b></td><td>${Admin.escape(c.description||'-')}</td>
      <td>${c.discount_type==='percent' ? c.discount_value+'%' : c.discount_type==='fixed' ? Admin.rupiah(c.discount_value) : 'Gratis Ongkir'}</td>
      <td>${Admin.rupiah(c.min_purchase)}</td><td>${c.valid_until||'-'}</td>
      <td>${c.used_count}${c.usage_limit ? ' / '+c.usage_limit : ''}</td>
      <td><span class="badge ${c.status==='active'?'success':'neutral'}">${c.status==='active'?'Aktif':'Nonaktif'}</span></td>
      <td style="white-space:nowrap">
        <button class="btn-ghost" onclick="toggleStatus(${c.id}, '${c.status==='active'?'inactive':'active'}')"><span class="material-symbols-outlined">${c.status==='active'?'toggle_on':'toggle_off'}</span></button>
        <button class="btn-ghost" onclick="delCoupon(${c.id})"><span class="material-symbols-outlined">delete</span></button>
      </td>
    </tr>`).join('') : `<tr><td colspan="8"><div class="empty-state"><span class="material-symbols-outlined">sell</span><p>Belum ada kupon.</p></div></td></tr>`;
}

function openAdd() {
  const body = `<form id="c-form"><div class="form-grid">
    <div class="field"><label>Kode Kupon</label><input name="code" required placeholder="DISKON10"></div>
    <div class="field"><label>Tipe Diskon</label><select name="discount_type"><option value="percent">Persen (%)</option><option value="fixed">Nominal (Rp)</option><option value="shipping">Gratis Ongkir</option></select></div>
    <div class="field"><label>Nilai Diskon</label><input name="discount_value" type="number" required></div>
    <div class="field"><label>Maks. Potongan (Rp)</label><input name="max_discount" type="number" value="0"></div>
    <div class="field"><label>Min. Pembelian (Rp)</label><input name="min_purchase" type="number" value="0"></div>
    <div class="field"><label>Berlaku Sampai</label><input name="valid_until" type="date"></div>
    <div class="field"><label>Batas Pemakaian</label><input name="usage_limit" type="number" value="0"></div>
    <div class="field span-2"><label>Deskripsi</label><input name="description"></div>
  </div></form>`;
  const { close, overlay } = Admin.modal({ title: 'Tambah Kupon', bodyHtml: body, wide: true });
  overlay.querySelector('.modal-body').insertAdjacentHTML('afterend', `<div class="modal-footer"><button class="btn-secondary" data-close>Batal</button><button class="btn-primary" id="save">Simpan</button></div>`);
  overlay.querySelector('#save').addEventListener('click', async () => {
    const f = overlay.querySelector('#c-form');
    const body = Object.fromEntries(new FormData(f).entries());
    try { await Admin.api('coupons.php', { method: 'POST', body }); Admin.toast('Kupon ditambahkan.'); close(); load(); }
    catch (e) { Admin.toast('Gagal: ' + e.message, 'error'); }
  });
}
async function toggleStatus(id, status) {
  await Admin.api('coupons.php?id=' + id, { method: 'PUT', body: { status } });
  Admin.toast('Status kupon diperbarui.'); load();
}
async function delCoupon(id) {
  if (!Admin.confirmDelete('Hapus kupon ini?')) return;
  await Admin.api('coupons.php?id=' + id, { method: 'DELETE' });
  Admin.toast('Kupon dihapus.'); load();
}
document.getElementById('btn-add').addEventListener('click', openAdd);
load();
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
