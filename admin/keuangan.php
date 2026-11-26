<?php
$active = 'keuangan';
$pageTitle = 'Keuangan';
require __DIR__ . '/config.php';
require __DIR__ . '/partials/header.php';
?>
<div class="page-toolbar">
  <div><p class="subtitle">Pemasukan & pengeluaran perusahaan, termasuk otomatis dari penjualan online.</p></div>
  <div class="toolbar-actions">
    <button class="btn-secondary" id="btn-add-expense"><span class="material-symbols-outlined">remove_circle</span> Catat Pengeluaran</button>
    <button class="btn-primary" id="btn-add-income"><span class="material-symbols-outlined">add_circle</span> Catat Pemasukan</button>
  </div>
</div>

<div class="grid cols-3" id="summary-grid" style="margin-bottom:22px"></div>

<div class="card" style="margin-bottom:22px">
  <h3>Pemasukan vs Pengeluaran (6 Bulan Terakhir)</h3>
  <canvas id="chart-pl" height="90" style="margin-top:14px"></canvas>
</div>

<div class="table-wrap">
  <table class="data-table">
    <thead><tr><th>Tanggal</th><th>Tipe</th><th>Kategori</th><th>Deskripsi</th><th>Jumlah</th><th>Aksi</th></tr></thead>
    <tbody id="tbl-body"><tr><td colspan="6"><div class="skeleton" style="height:50px"></div></td></tr></tbody>
  </table>
</div>

<script>
const INCOME_CATS = ['Penjualan Online','Penjualan Offline','Investasi','Lainnya'];
const EXPENSE_CATS = ['Sewa Toko','Gaji Karyawan','Produksi','Marketing','Operasional','Utilitas','Lainnya'];

async function loadSummary() {
  const res = await Admin.api('finance.php?view=summary');
  document.getElementById('summary-grid').innerHTML = `
    <div class="stat-card" style="--stat-color:#1f8a5f"><div class="icon-wrap"><span class="material-symbols-outlined">trending_up</span></div><div class="label">Total Pemasukan</div><div class="value">${Admin.rupiah(res.total_income)}</div></div>
    <div class="stat-card" style="--stat-color:#c8384a"><div class="icon-wrap"><span class="material-symbols-outlined">trending_down</span></div><div class="label">Total Pengeluaran</div><div class="value">${Admin.rupiah(res.total_expense)}</div></div>
    <div class="stat-card" style="--stat-color:#c4a77d"><div class="icon-wrap"><span class="material-symbols-outlined">account_balance_wallet</span></div><div class="label">Laba Bersih</div><div class="value">${Admin.rupiah(res.net_profit)}</div></div>
  `;
  new Chart(document.getElementById('chart-pl'), {
    type: 'bar',
    data: {
      labels: res.months.map(m => m.month),
      datasets: [
        { label: 'Pemasukan', data: res.months.map(m => m.income), backgroundColor: '#1f8a5f', borderRadius: 6 },
        { label: 'Pengeluaran', data: res.months.map(m => m.expense), backgroundColor: '#c8384a', borderRadius: 6 },
      ]
    },
    options: { plugins: { legend: { position: 'bottom' } } }
  });
}

async function loadTable() {
  const res = await Admin.api('finance.php');
  document.getElementById('tbl-body').innerHTML = res.transactions.length ? res.transactions.map(t => `
    <tr>
      <td>${t.trx_date}</td>
      <td><span class="badge ${t.type==='income'?'success':'danger'}">${t.type==='income'?'Pemasukan':'Pengeluaran'}</span></td>
      <td>${Admin.escape(t.category)}</td><td>${Admin.escape(t.description||'-')}</td>
      <td style="font-weight:700;color:${t.type==='income'?'var(--success)':'var(--danger)'}">${t.type==='income'?'+':'-'}${Admin.rupiah(t.amount)}</td>
      <td><button class="btn-ghost" onclick="del(${t.id})"><span class="material-symbols-outlined">delete</span></button></td>
    </tr>`).join('') : `<tr><td colspan="6"><div class="empty-state"><span class="material-symbols-outlined">account_balance_wallet</span><p>Belum ada transaksi.</p></div></td></tr>`;
}

function openForm(type) {
  const cats = type === 'income' ? INCOME_CATS : EXPENSE_CATS;
  const body = `<form id="f-form"><div class="form-grid">
    <div class="field"><label>Kategori</label><select name="category">${cats.map(c=>`<option>${c}</option>`).join('')}</select></div>
    <div class="field"><label>Jumlah (Rp)</label><input name="amount" type="number" required></div>
    <div class="field"><label>Tanggal</label><input name="date" type="date" value="${new Date().toISOString().slice(0,10)}"></div>
    <div class="field span-2"><label>Deskripsi</label><input name="description"></div>
  </div></form>`;
  const { close, overlay } = Admin.modal({ title: type === 'income' ? 'Catat Pemasukan' : 'Catat Pengeluaran', bodyHtml: body, wide: true });
  overlay.querySelector('.modal-body').insertAdjacentHTML('afterend', `<div class="modal-footer"><button class="btn-secondary" data-close>Batal</button><button class="btn-primary" id="save">Simpan</button></div>`);
  overlay.querySelector('#save').addEventListener('click', async () => {
    const f = overlay.querySelector('#f-form');
    const body = Object.fromEntries(new FormData(f).entries());
    body.type = type;
    try {
      await Admin.api('finance.php', { method: 'POST', body });
      Admin.toast('Transaksi dicatat.'); close(); loadSummary(); loadTable();
    } catch (e) { Admin.toast('Gagal: ' + e.message, 'error'); }
  });
}
async function del(id) {
  if (!Admin.confirmDelete('Hapus transaksi ini?')) return;
  await Admin.api('finance.php?id=' + id, { method: 'DELETE' });
  Admin.toast('Transaksi dihapus.'); loadSummary(); loadTable();
}
document.getElementById('btn-add-income').addEventListener('click', () => openForm('income'));
document.getElementById('btn-add-expense').addEventListener('click', () => openForm('expense'));
loadSummary(); loadTable();
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
