<?php
$active = 'payments';
$pageTitle = 'Metode Pembayaran';
require __DIR__ . '/config.php';
require __DIR__ . '/partials/header.php';
?>
<div class="page-toolbar"><div><p class="subtitle">Atur rekening, e-wallet, QRIS, dan COD yang tampil di checkout. Perubahan langsung berlaku di toko.</p></div></div>
<div class="card" style="border-color:var(--gold);background:var(--gold-soft);margin-bottom:18px">
  <b>Mode pembayaran: verifikasi manual.</b> Sistem belum terhubung ke payment gateway, jadi pembayaran <u>tidak pernah otomatis berstatus Dibayar</u>.
  Pelanggan mengunggah bukti transfer → status <i>Menunggu Verifikasi</i> → admin memeriksa mutasi lalu menandai <i>Dibayar</i> di menu Pesanan.
  Data bertanda <span class="badge warning">Contoh</span> adalah placeholder — ganti dengan rekening/nomor asli sebelum dipakai.
</div>
<div class="table-wrap"><table class="data-table">
  <thead><tr><th>Metode</th><th>Jenis</th><th>Atas Nama</th><th>No. Rekening / Akun</th><th>Status</th><th>Aksi</th></tr></thead>
  <tbody id="tbl-body"><tr><td colspan="6"><div class="skeleton" style="height:50px"></div></td></tr></tbody>
</table></div>
<script>
let METHODS = [];
const GRP = { bank: 'Transfer Bank', ewallet: 'E-Wallet', qris: 'QRIS', cod: 'COD' };
async function load() {
  METHODS = (await Admin.api('payments.php?action=methods')).methods;
  document.getElementById('tbl-body').innerHTML = METHODS.map(m => `
    <tr><td><b>${Admin.escape(m.name)}</b></td><td>${GRP[m.grp] || m.grp}</td><td>${Admin.escape(m.account_name || '-')}</td>
    <td>${m.grp === 'qris' ? (m.qr_image ? 'Gambar QRIS terunggah' : '<span class="badge warning">Belum ada gambar QRIS</span>') : Admin.escape(m.account_number || '-')} ${m.is_sample == 1 && m.grp !== 'cod' ? '<span class="badge warning">Contoh</span>' : ''}</td>
    <td><span class="badge ${m.enabled == 1 ? 'success' : 'neutral'}">${m.enabled == 1 ? 'Aktif' : 'Nonaktif'}</span></td>
    <td><button class="btn-ghost" onclick="editMethod(${m.id})"><span class="material-symbols-outlined">edit</span></button></td></tr>`).join('');
}
function editMethod(id) {
  const m = METHODS.find(x => x.id == id);
  Admin.modal({ title: 'Ubah ' + m.name, onMount: (ov, close) => {
    ov.querySelector('#m-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = new FormData(e.target);
      try {
        await Admin.api('payments.php?action=method&id=' + id, { method: 'PUT', body: {
          name: fd.get('name'), account_name: fd.get('account_name'), account_number: fd.get('account_number'),
          instructions: fd.get('instructions'), enabled: fd.get('enabled') ? 1 : 0, confirm_real: m.grp === 'cod' ? 1 : 0 } });
        const f = ov.querySelector('#qris-file');
        if (f && f.files[0]) { const form = new FormData(); form.append('image', f.files[0]); await Admin.api('payments.php?action=qris_image&id=' + id, { method: 'POST', body: form, isForm: true }); }
        Admin.toast('Metode pembayaran disimpan.'); close(); load();
      } catch (err) { Admin.toast('Gagal: ' + err.message, 'error'); }
    });
  }, bodyHtml: `<form id="m-form"><div class="form-grid">
    <div class="field span-2"><label>Nama tampilan</label><input name="name" required value="${Admin.escape(m.name)}"></div>
    ${m.grp === 'bank' || m.grp === 'ewallet' ? `
      <div class="field"><label>Atas nama</label><input name="account_name" value="${Admin.escape(m.account_name || '')}"></div>
      <div class="field"><label>${m.grp === 'bank' ? 'Nomor rekening / Virtual Account' : 'Nomor akun e-wallet'}</label><input name="account_number" value="${Admin.escape(m.account_number || '')}"></div>` : `<input type="hidden" name="account_name" value=""><input type="hidden" name="account_number" value="">`}
    ${m.grp === 'qris' ? `<div class="field span-2"><label>Gambar kode QRIS (jpg/png, maks 3MB)</label><input type="file" id="qris-file" accept="image/*">
      ${m.qr_image ? `<img src="../${Admin.escape(m.qr_image)}" style="max-width:180px;margin-top:8px;border:1px solid var(--border);border-radius:8px">` : '<p class="subtitle">Belum ada gambar. Tanpa gambar, pelanggan hanya melihat instruksi tanpa kode QR.</p>'}</div>` : ''}
    <div class="field span-2"><label>Instruksi pembayaran</label><textarea name="instructions" rows="5">${Admin.escape(m.instructions || '')}</textarea></div>
    <div class="field span-2"><label class="checkbox-row"><input type="checkbox" name="enabled" ${m.enabled == 1 ? 'checked' : ''}> Aktif (tampil di checkout)</label></div>
  </div><div class="modal-footer" style="margin-top:16px"><button class="btn-primary" type="submit">Simpan</button></div></form>` });
}
load();
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
