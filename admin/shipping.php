<?php
$active = 'shipping';
$pageTitle = 'Ekspedisi & Ongkir';
require __DIR__ . '/config.php';
require __DIR__ . '/partials/header.php';
?>
<div class="page-toolbar"><div><p class="subtitle">Atur ekspedisi, layanan, tarif per kg per zona, dan estimasi tiba. Asal pengiriman diatur di <a href="settings.php" style="text-decoration:underline">Pengaturan</a>.</p></div>
  <div class="toolbar-actions"><button class="btn-primary" id="btn-add"><span class="material-symbols-outlined">add</span> Tambah Layanan</button></div></div>
<div class="card" style="border-color:var(--gold);background:var(--gold-soft);margin-bottom:18px">
  <b>Ongkir adalah ESTIMASI / SIMULASI</b> berdasarkan konfigurasi di halaman ini, bukan tarif resmi atau API ekspedisi. Rumus reguler/express:
  <code>max(tarif minimum, tarif zona × berat tertagih (kg))</code> — zona: dalam kota, dalam provinsi, satu pulau, antar pulau. Layanan instan (GoSend/Grab):
  <code>max(minimum, tarif/km × jarak)</code> dan hanya tersedia dalam jangkauan jarak (butuh titik peta pelanggan). Struktur siap diganti API ekspedisi (mis. RajaOngkir) nanti.
</div>
<div id="origin-box" class="subtitle" style="margin-bottom:12px"></div>
<div id="courier-list"></div>
<script>
let COURIERS = [], SERVICES = [];
const TYPES = { regular: 'Reguler', express: 'Express', instant: 'Instan', cargo: 'Kargo' };
async function load() {
  const r = await Admin.api('shipping.php?action=admin');
  COURIERS = r.couriers; SERVICES = r.services;
  document.getElementById('origin-box').innerHTML = `Asal pengiriman saat ini: <b>${Admin.escape(r.origin.city)}, ${Admin.escape(r.origin.province)}</b> (${r.origin.lat}, ${r.origin.lng})`;
  document.getElementById('courier-list').innerHTML = COURIERS.map(c => `
    <div class="card" style="margin-bottom:16px">
      <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
        <div><h3>${Admin.escape(c.name)} <span class="badge ${c.enabled == 1 ? 'success' : 'neutral'}">${c.enabled == 1 ? 'Aktif' : 'Nonaktif'}</span></h3><p class="subtitle">${Admin.escape(c.description || '')}</p></div>
        <button class="btn-secondary" onclick="toggleCourier(${c.id})">${c.enabled == 1 ? 'Nonaktifkan' : 'Aktifkan'}</button>
      </div>
      <div class="table-wrap" style="margin-top:12px"><table class="data-table"><thead><tr><th>Layanan</th><th>Jenis</th><th>Tarif/kg (kota · prov · pulau · nasional)</th><th>Min.</th><th>Estimasi</th><th>Status</th><th></th></tr></thead><tbody>
      ${SERVICES.filter(s => s.courier_code === c.code).map(s => `<tr>
        <td><b>${Admin.escape(s.name)}</b><div style="font-size:11px;color:var(--text-mute)">${Admin.escape(s.description || '')}</div></td><td>${TYPES[s.type] || s.type}</td>
        <td>${s.type === 'instant' ? `${Admin.rupiah(s.per_km_fee)}/km · maks ${s.max_distance_km} km` : [s.rate_local, s.rate_province, s.rate_island, s.rate_national].map(v => Number(v).toLocaleString('id-ID')).join(' · ')}</td>
        <td>${Admin.rupiah(s.min_fee)}</td><td>${s.etd_min}–${s.etd_max} ${s.etd_unit}${s.type !== 'instant' ? ' (+' + s.etd_step + '/zona)' : ''}</td>
        <td><span class="badge ${s.enabled == 1 ? 'success' : 'neutral'}">${s.enabled == 1 ? 'Aktif' : 'Nonaktif'}</span></td>
        <td><button class="btn-ghost" onclick="editService(${s.id})"><span class="material-symbols-outlined">edit</span></button><button class="btn-ghost" onclick="delService(${s.id})"><span class="material-symbols-outlined">delete</span></button></td></tr>`).join('') || '<tr><td colspan="7" class="subtitle">Belum ada layanan.</td></tr>'}
      </tbody></table></div></div>`).join('');
}
async function toggleCourier(id) {
  const c = COURIERS.find(x => x.id == id);
  await Admin.api('shipping.php?action=courier&id=' + id, { method: 'PUT', body: { enabled: c.enabled == 1 ? 0 : 1 } });
  load();
}
async function delService(id) {
  if (!confirm('Hapus layanan ini? (Pesanan lama tidak terpengaruh)')) return;
  await Admin.api('shipping.php?action=service&id=' + id, { method: 'DELETE' }); load();
}
function serviceForm(s) {
  const n = (k, label, v) => `<div class="field"><label>${label}</label><input name="${k}" type="number" min="0" value="${s ? s[k] : v}"></div>`;
  return `<form id="s-form"><div class="form-grid">
    ${s ? '' : `<div class="field"><label>Ekspedisi</label><select name="courier_code">${COURIERS.map(c => `<option value="${c.code}">${Admin.escape(c.name)}</option>`).join('')}</select></div>
    <div class="field"><label>Kode layanan (mis. REG)</label><input name="code" required></div>`}
    <div class="field ${s ? '' : ''}"><label>Nama layanan</label><input name="name" required value="${s ? Admin.escape(s.name) : ''}"></div>
    <div class="field"><label>Jenis</label><select name="type">${Object.entries(TYPES).map(([k, v]) => `<option value="${k}" ${s && s.type === k ? 'selected' : ''}>${v}</option>`).join('')}</select></div>
    <div class="field span-2"><label>Keterangan layanan</label><input name="description" value="${s ? Admin.escape(s.description || '') : ''}"></div>
    ${n('rate_local', 'Tarif/kg — dalam kota', 9000)}${n('rate_province', 'Tarif/kg — dalam provinsi', 11000)}${n('rate_island', 'Tarif/kg — satu pulau', 15000)}${n('rate_national', 'Tarif/kg — antar pulau', 28000)}
    ${n('min_fee', 'Ongkir minimum (Rp)', 9000)}${n('per_km_fee', 'Tarif per km (khusus instan)', 0)}${n('max_distance_km', 'Jarak maks km (khusus instan)', 0)}
    ${n('etd_min', 'Estimasi min', 2)}${n('etd_max', 'Estimasi maks', 3)}${n('etd_step', 'Tambahan estimasi per zona', 1)}
    <div class="field"><label>Satuan estimasi</label><select name="etd_unit"><option ${!s || s.etd_unit === 'hari' ? 'selected' : ''}>hari</option><option ${s && s.etd_unit === 'jam' ? 'selected' : ''}>jam</option></select></div>
    ${n('sort_order', 'Urutan tampil', 1)}
    <div class="field span-2"><label class="checkbox-row"><input type="checkbox" name="enabled" ${!s || s.enabled == 1 ? 'checked' : ''}> Aktif</label></div>
  </div><div class="modal-footer" style="margin-top:16px"><button class="btn-primary" type="submit">Simpan</button></div></form>`;
}
function openForm(s) {
  Admin.modal({ title: s ? 'Ubah Layanan' : 'Tambah Layanan', wide: true, bodyHtml: serviceForm(s), onMount: (ov, close) => {
    ov.querySelector('#s-form').addEventListener('submit', async (e) => {
      e.preventDefault();
      const fd = Object.fromEntries(new FormData(e.target));
      fd.enabled = e.target.enabled.checked ? 1 : 0;
      try {
        await Admin.api('shipping.php?action=service' + (s ? '&id=' + s.id : ''), { method: s ? 'PUT' : 'POST', body: fd });
        Admin.toast('Layanan disimpan.'); close(); load();
      } catch (err) { Admin.toast('Gagal: ' + err.message, 'error'); }
    });
  }});
}
function editService(id) { openForm(SERVICES.find(x => x.id == id)); }
document.getElementById('btn-add').addEventListener('click', () => openForm(null));
load();
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
