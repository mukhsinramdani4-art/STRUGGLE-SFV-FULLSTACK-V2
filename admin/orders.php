<?php
$active = 'orders';
$pageTitle = 'Pesanan';
require __DIR__ . '/config.php';
$__me = require_page_access('orders');
$canPay = in_array($__me['role'], ['Super Admin', 'Owner', 'Manager', 'Kasir'], true);
require __DIR__ . '/partials/header.php';
?>
<div class="page-toolbar">
  <div><p class="subtitle">Pesanan masuk dari storefront. Status <b>pesanan</b>, <b>pembayaran</b>, dan <b>pengiriman</b> dikelola terpisah.</p></div>
  <div class="toolbar-actions">
    <input id="search-box" placeholder="Cari ID / pelanggan / resi..." style="padding:11px 14px;border:1px solid var(--border);border-radius:10px;min-width:240px">
  </div>
</div>
<div class="tab-bar" id="status-tabs"></div>
<div class="table-wrap">
  <table class="data-table">
    <thead><tr><th>ID Pesanan</th><th>Pelanggan</th><th>Ekspedisi</th><th>Total</th><th>Pembayaran</th><th>Pesanan</th><th>Pengiriman</th><th>Tanggal</th><th>Aksi</th></tr></thead>
    <tbody id="tbl-body"><tr><td colspan="9"><div class="skeleton" style="height:50px"></div></td></tr></tbody>
  </table>
</div>

<script>
const CAN_PAY = <?= $canPay ? 'true' : 'false' ?>;
const ORDER_STATUSES = ['Pending','Diproses','Dikemas','Dikirim','Selesai','Dibatalkan'];
const PAY_STATUSES = ['Belum Dibayar','Menunggu Verifikasi','Dibayar','Gagal','Dikembalikan'];
const SHIP_STATUSES = ['Menunggu Pengiriman','Diproses Kurir','Dalam Perjalanan','Diterima'];
const ERR = {
  payment_not_verified: 'Pembayaran belum diverifikasi — verifikasi pembayaran dulu sebelum mengirim/menyelesaikan pesanan.',
  tracking_required: 'Isi nomor resi terlebih dahulu sebelum status Dalam Perjalanan.',
  order_cancelled: 'Pesanan sudah dibatalkan dan tidak dapat diubah.',
  forbidden: 'Role Anda tidak memiliki izin untuk aksi ini.',
};
let ORDERS = [];
let statusFilter = 'all';

function renderTabs() {
  document.getElementById('status-tabs').innerHTML = ['all', ...ORDER_STATUSES].map(s =>
    `<button data-s="${s}" class="${statusFilter === s ? 'active' : ''}">${s === 'all' ? 'Semua (' + ORDERS.length + ')' : s + ' (' + ORDERS.filter(o => o.status === s).length + ')'}</button>`).join('')
    + `<button data-s="__verify" class="${statusFilter === '__verify' ? 'active' : ''}">Perlu verifikasi bayar (${ORDERS.filter(o => o.payment_status === 'Menunggu Verifikasi' && o.status !== 'Dibatalkan').length})</button>`;
  document.querySelectorAll('#status-tabs button').forEach(b => b.addEventListener('click', () => { statusFilter = b.dataset.s; renderTabs(); renderTable(); }));
}
async function load() {
  const res = await Admin.api('orders.php?limit=1000');
  ORDERS = res.orders;
  renderTabs(); renderTable();
  const open = new URLSearchParams(location.search).get('open');
  if (open) { history.replaceState(null, '', 'orders.php'); viewOrder(open); }
}
function renderTable() {
  const q = (document.getElementById('search-box').value || '').toLowerCase();
  const rows = ORDERS.filter(o => {
    const st = statusFilter === 'all' || (statusFilter === '__verify' ? o.payment_status === 'Menunggu Verifikasi' && o.status !== 'Dibatalkan' : o.status === statusFilter);
    return st && (!q || [o.id, o.customer_name, o.recipient_name, o.tracking_number, o.customer_username].some(v => (v || '').toLowerCase().includes(q)));
  });
  document.getElementById('tbl-body').innerHTML = rows.length ? rows.map(o => `
    <tr>
      <td><b>${Admin.escape(o.id)}</b></td>
      <td>${Admin.escape(o.customer_name || '-')}<div style="font-size:11px;color:var(--text-mute)">${Admin.escape(o.customer_username || o.customer_email || '')}</div></td>
      <td>${Admin.escape(o.courier_name || '-')}<div style="font-size:11px;color:var(--text-mute)">${Admin.escape(o.service_name || '')}${o.tracking_number ? ' · ' + Admin.escape(o.tracking_number) : ''}</div></td>
      <td>${Admin.rupiah(o.total)}<div style="font-size:11px;color:var(--text-mute)">${Admin.escape(o.payment_method_label || o.payment_method || '')}</div></td>
      <td>${Admin.badge(o.payment_status)}</td><td>${Admin.badge(o.status)}</td><td>${Admin.badge(o.shipping_status)}</td>
      <td>${Admin.dateFmt(o.created_at)}</td>
      <td><button class="btn-ghost" title="Detail & kelola" onclick="viewOrder('${o.id}')"><span class="material-symbols-outlined">visibility</span></button></td>
    </tr>`).join('') : `<tr><td colspan="9"><div class="empty-state"><span class="material-symbols-outlined">receipt_long</span><p>Tidak ada pesanan.</p></div></td></tr>`;
}
document.getElementById('search-box').addEventListener('input', renderTable);

async function updateOrder(id, payload, okMsg, modal) {
  try {
    await Admin.api('orders.php?id=' + encodeURIComponent(id), { method: 'PUT', body: payload });
    Admin.toast(okMsg || 'Pesanan diperbarui.');
    if (modal) modal.close();
    await load();
    viewOrder(id);
  } catch (e) { Admin.toast(ERR[e.message] || ('Gagal: ' + e.message), 'error'); }
}

const sel = (name, list, cur, disabled) => `<select name="${name}" ${disabled ? 'disabled' : ''} style="border:1px solid var(--border);border-radius:8px;padding:8px 10px;font-weight:700;width:100%">${list.map(s => `<option ${s === cur ? 'selected' : ''}>${s}</option>`).join('')}</select>`;
let currentModal = null;

async function viewOrder(id) {
  let o;
  try { o = (await Admin.api('orders.php?id=' + encodeURIComponent(id))).order; } catch (e) { return Admin.toast('Gagal memuat: ' + e.message, 'error'); }
  if (currentModal) currentModal.close();
  const pay = o.payment || {};
  const hasCoord = o.shipping_lat != null && o.shipping_lng != null;
  const cancelled = o.status === 'Dibatalkan';
  const body = `
  <div class="grid cols-2" style="gap:14px;align-items:start">
    <div class="card" style="padding:16px"><h4 style="margin-bottom:8px">Pelanggan &amp; Penerima</h4>
      <div style="font-size:13px;line-height:1.7">
        <b>Akun:</b> ${Admin.escape(o.customer ? o.customer.name + ' (' + (o.customer.username || o.customer.email) + ')' : o.customer_name || '-')}<br>
        <b>Penerima:</b> ${Admin.escape(o.recipient_name || o.customer_name || '-')}<br>
        <b>Email:</b> ${Admin.escape(o.customer_email || '-')} · <b>Telp/WA:</b> ${Admin.escape(o.customer_phone || '-')}
      </div></div>
    <div class="card" style="padding:16px"><h4 style="margin-bottom:8px">Alamat Pengiriman</h4>
      <div style="font-size:13px;line-height:1.7">
        ${Admin.escape(o.shipping_address || '-')}<br>
        ${Admin.escape([o.shipping_village, o.shipping_district, o.shipping_city, o.shipping_province, o.shipping_postal].filter(Boolean).join(', '))}<br>
        ${o.shipping_notes ? '<b>Catatan kurir:</b> ' + Admin.escape(o.shipping_notes) + '<br>' : ''}
        ${hasCoord ? `<b>Koordinat:</b> ${Number(o.shipping_lat).toFixed(6)}, ${Number(o.shipping_lng).toFixed(6)} · <a target="_blank" rel="noopener" href="https://www.google.com/maps?q=${o.shipping_lat},${o.shipping_lng}">Buka di Google Maps ↗</a>` : '<span style="color:var(--text-mute)">Titik peta tidak ditentukan.</span>'}
        ${o.shipping_formatted_address ? '<br><span style="color:var(--text-mute)">' + Admin.escape(o.shipping_formatted_address) + '</span>' : ''}
      </div></div>
  </div>

  <div class="table-wrap" style="margin-top:14px"><table class="data-table"><thead><tr><th>Produk</th><th>Varian</th><th>Qty</th><th>Harga</th></tr></thead>
    <tbody>${(o.items || []).map(it => `<tr><td>${Admin.escape(it.product_name)}</td><td>${Admin.escape([it.color, it.size, it.sleeve].filter(Boolean).join(' / ') || '-')}</td><td>${it.qty}</td><td>${Admin.rupiah(it.price)}</td></tr>`).join('')}</tbody></table></div>
  <div style="text-align:right;margin:10px 0 16px;font-size:13.5px;line-height:1.7">
    Subtotal: ${Admin.rupiah(o.subtotal)}<br>Ongkir (${Admin.escape(o.courier_name || '')} ${Admin.escape(o.service_name || '')}, est. ${Admin.escape(o.shipping_etd || '-')}): ${Admin.rupiah(o.shipping_fee)}<br>
    Diskon${o.coupon_code ? ' (' + Admin.escape(o.coupon_code) + ')' : ''}: −${Admin.rupiah(o.discount)}<br><b style="font-size:16px">Total: ${Admin.rupiah(o.total)}</b>
    <br><span style="color:var(--text-mute)">Berat ${(o.weight_gram / 1000).toFixed(2)} kg · ongkir = estimasi/simulasi</span>
  </div>

  <div class="grid cols-3" style="gap:14px;align-items:start">
    <div class="card" style="padding:16px"><h4 style="margin-bottom:8px">Pembayaran ${Admin.badge(o.payment_status)}</h4>
      <div style="font-size:13px;line-height:1.7;margin-bottom:10px">
        Metode: <b>${Admin.escape(o.payment_method_label || o.payment_method)}</b><br>
        ${pay.has_proof == 1 ? `Bukti: <a target="_blank" href="../api/payments.php?action=proof&order_id=${encodeURIComponent(o.id)}">Lihat bukti ↗</a> <span style="color:var(--text-mute)">(${Admin.dateTimeFmt(pay.proof_uploaded_at)})</span><br>` : (o.payment_method === 'cod' ? 'COD — dibayar saat diterima.<br>' : '<span style="color:var(--text-mute)">Belum ada bukti pembayaran.</span><br>')}
        ${pay.payer_note ? 'Catatan pelanggan: ' + Admin.escape(pay.payer_note) + '<br>' : ''}
        ${pay.verified_by ? `<span style="color:var(--text-mute)">Diverifikasi oleh ${Admin.escape(pay.verified_by)}, ${Admin.dateTimeFmt(pay.verified_at)}</span>` : ''}
      </div>
      ${CAN_PAY && !cancelled ? `
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:8px">
          ${o.payment_status !== 'Dibayar' ? `<button class="btn-primary" id="btn-verify"><span class="material-symbols-outlined">verified</span> Tandai Dibayar</button>` : ''}
          ${o.payment_status === 'Menunggu Verifikasi' ? `<button class="btn-secondary" id="btn-reject">Tolak</button>` : ''}
          ${o.payment_status === 'Dibayar' ? `<button class="btn-secondary" id="btn-refund">Tandai Dikembalikan</button>` : ''}
        </div>` : ''}
    </div>

    <div class="card" style="padding:16px"><h4 style="margin-bottom:8px">Status Pesanan ${Admin.badge(o.status)}</h4>
      ${sel('status', ORDER_STATUSES, o.status, cancelled)}
      <button class="btn-primary full" id="btn-status" style="margin-top:10px" ${cancelled ? 'disabled' : ''}>Perbarui Status Pesanan</button>
      <div class="field" style="margin-top:10px"><label>Catatan internal</label><textarea id="admin-notes" rows="2" style="width:100%;padding:8px;border:1px solid var(--border);border-radius:8px">${Admin.escape(o.admin_notes || '')}</textarea>
      <button class="btn-ghost" id="btn-notes" style="margin-top:4px">Simpan catatan</button></div>
    </div>

    <div class="card" style="padding:16px"><h4 style="margin-bottom:8px">Pengiriman ${Admin.badge(o.shipping_status)}</h4>
      <div style="font-size:12.5px;color:var(--text-mute);margin-bottom:8px">${Admin.escape(o.courier_name || '-')} · ${Admin.escape(o.service_name || '-')}</div>
      <div class="field"><label>Nomor resi</label><input id="resi" value="${Admin.escape(o.tracking_number || '')}" ${cancelled ? 'disabled' : ''} placeholder="mis. JNE123456789"></div>
      <div class="field" style="margin-top:8px"><label>Status pengiriman</label>${sel('shipping', SHIP_STATUSES, o.shipping_status, cancelled)}</div>
      <button class="btn-primary full" id="btn-ship" style="margin-top:10px" ${cancelled ? 'disabled' : ''}>Perbarui Pengiriman</button>
    </div>
  </div>

  <h4 style="margin:18px 0 8px">Riwayat Perubahan Status</h4>
  <div class="table-wrap"><table class="data-table"><thead><tr><th>Waktu</th><th>Jenis</th><th>Perubahan</th><th>Catatan</th><th>Oleh</th></tr></thead><tbody>
    ${(o.events || []).slice().reverse().map(e => `<tr><td>${Admin.dateTimeFmt(e.created_at)}</td><td>${{ order: 'Pesanan', payment: 'Pembayaran', shipping: 'Pengiriman' }[e.kind] || e.kind}</td><td>${e.from_status ? Admin.escape(e.from_status) + ' → ' : ''}<b>${Admin.escape(e.to_status || '')}</b></td><td>${Admin.escape(e.note || '')}</td><td>${Admin.escape(e.actor || '')}</td></tr>`).join('')}
  </tbody></table></div>`;
  currentModal = Admin.modal({ title: 'Pesanan ' + o.id, bodyHtml: body, wide: true, onMount: (ov) => {
    const q = (s) => ov.querySelector(s);
    const m = { close: () => { ov.remove(); currentModal = null; } };
    if (q('#btn-verify')) q('#btn-verify').onclick = () => { if (confirm('Pastikan dana sudah masuk. Tandai pesanan ini DIBAYAR?')) updateOrder(o.id, { paymentStatus: 'Dibayar' }, 'Pembayaran diverifikasi.', m); };
    if (q('#btn-reject')) q('#btn-reject').onclick = () => { const r = prompt('Alasan penolakan (akan tercatat):'); if (r !== null) updateOrder(o.id, { paymentStatus: 'Gagal', rejectReason: r, note: r }, 'Pembayaran ditolak.', m); };
    if (q('#btn-refund')) q('#btn-refund').onclick = () => { if (confirm('Tandai pembayaran sebagai DIKEMBALIKAN? Refund akan tercatat di Keuangan.')) updateOrder(o.id, { paymentStatus: 'Dikembalikan' }, 'Pembayaran dikembalikan.', m); };
    q('#btn-status').onclick = () => {
      const v = q('select[name=status]').value;
      if (v === 'Dibatalkan' && !confirm('Batalkan pesanan? Stok akan dikembalikan.')) return;
      updateOrder(o.id, { status: v }, 'Status pesanan diperbarui.', m);
    };
    q('#btn-notes').onclick = () => updateOrder(o.id, { adminNotes: q('#admin-notes').value }, 'Catatan disimpan.', m);
    q('#btn-ship').onclick = () => updateOrder(o.id, { shippingStatus: q('select[name=shipping]').value, trackingNumber: q('#resi').value.trim() }, 'Data pengiriman diperbarui.', m);
  }});
}
load();
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
