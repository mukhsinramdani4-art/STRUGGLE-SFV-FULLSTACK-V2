<?php
$active = 'dashboard';
$pageTitle = 'Dashboard';
require __DIR__ . '/config.php';
require __DIR__ . '/partials/header.php';
?>
<div class="page-toolbar">
  <div>
    <p class="subtitle">Ringkasan performa bisnis STRUGGLE / SFV STUDIO hari ini.</p>
  </div>
  <div class="toolbar-actions">
    <a href="products.php" class="btn-primary"><span class="material-symbols-outlined">add</span> Tambah Produk</a>
  </div>
</div>

<div id="stat-grid" class="grid cols-4" style="margin-bottom:22px">
  <div class="stat-card skeleton" style="height:130px"></div>
  <div class="stat-card skeleton" style="height:130px"></div>
  <div class="stat-card skeleton" style="height:130px"></div>
  <div class="stat-card skeleton" style="height:130px"></div>
</div>

<div class="grid cols-3" style="grid-template-columns: 2fr 1fr; margin-bottom:22px">
  <div class="card">
    <h3>Tren Penjualan (7 Hari Terakhir)</h3>
    <p class="subtitle" style="margin-bottom:14px">Total nilai order per hari</p>
    <canvas id="chart-sales" height="110"></canvas>
  </div>
  <div class="card">
    <h3>Produk Terlaris</h3>
    <p class="subtitle" style="margin-bottom:14px">Berdasarkan jumlah terjual</p>
    <div id="top-products"></div>
  </div>
</div>

<div class="grid cols-2" style="grid-template-columns: 2fr 1fr">
  <div class="card">
    <h3>Pesanan Terbaru</h3>
    <p class="subtitle" style="margin-bottom:14px">8 transaksi terakhir dari storefront</p>
    <div id="recent-orders"></div>
  </div>
  <div class="card">
    <h3>Ringkasan ERP</h3>
    <p class="subtitle" style="margin-bottom:14px">Nilai aset & jumlah karyawan aktif</p>
    <div id="erp-summary" style="display:flex;flex-direction:column;gap:16px"></div>
  </div>
</div>

<script>
(async function () {
  const iconMap = { total_revenue: 'payments', total_orders: 'receipt_long', total_customers: 'group', total_products: 'inventory_2' };
  try {
    const res = await Admin.api('dashboard.php');
    const s = res.stats;

    const cards = [
      { key: 'total_revenue', label: 'Total Pendapatan', value: Admin.rupiah(s.total_revenue), color: '#c4a77d', icon: 'payments', delta: s.revenue_growth_pct },
      { key: 'total_orders', label: 'Total Pesanan', value: s.total_orders, color: '#3b6fd1', icon: 'receipt_long', sub: s.pending_orders + ' pending · ' + s.awaiting_verification + ' menunggu verifikasi bayar · ' + s.to_ship + ' siap kirim' },
      { key: 'total_customers', label: 'Total Pelanggan', value: s.total_customers, color: '#1f8a5f', icon: 'group' },
      { key: 'total_products', label: 'Total Produk', value: s.total_products, color: '#b8860b', icon: 'inventory_2', sub: s.low_stock + ' stok menipis' },
    ];
    document.getElementById('stat-grid').innerHTML = cards.map(c => `
      <div class="stat-card" style="--stat-color:${c.color}">
        <div class="icon-wrap"><span class="material-symbols-outlined">${c.icon}</span></div>
        <div class="label">${c.label}</div>
        <div class="value">${c.value}</div>
        ${c.delta !== undefined ? `<span class="delta ${c.delta >= 0 ? 'up' : 'down'}"><span class="material-symbols-outlined" style="font-size:15px">${c.delta >= 0 ? 'trending_up' : 'trending_down'}</span>${c.delta}% vs 30 hari lalu</span>` : `<span class="delta" style="color:var(--text-mute)">${c.sub || ''}</span>`}
      </div>`).join('');

    // Chart
    const ctx = document.getElementById('chart-sales');
    new Chart(ctx, {
      type: 'line',
      data: {
        labels: res.sales_trend.map(d => new Date(d.date).toLocaleDateString('id-ID', { weekday: 'short' })),
        datasets: [{
          label: 'Penjualan',
          data: res.sales_trend.map(d => d.total),
          borderColor: '#2e1c38', backgroundColor: 'rgba(196,167,125,.25)',
          fill: true, tension: .4, pointRadius: 4, pointBackgroundColor: '#c4a77d',
        }]
      },
      options: { plugins: { legend: { display: false } }, scales: { y: { ticks: { callback: (v) => 'Rp' + (v / 1000) + 'rb' } } } }
    });

    // Top products
    document.getElementById('top-products').innerHTML = res.top_products.length ? res.top_products.map(p => `
      <div class="cell-product" style="margin-bottom:14px">
        <img src="../${p.images[0] || 'assets/images/logo.png'}" onerror="this.src='../assets/images/logo.png'">
        <div style="flex:1"><div style="font-weight:700;font-size:13px">${Admin.escape(p.name)}</div><div style="font-size:12px;color:var(--text-mute)">${p.sold} terjual</div></div>
      </div>`).join('') : `<p class="subtitle">Belum ada penjualan.</p>`;

    // Recent orders
    document.getElementById('recent-orders').innerHTML = res.recent_orders.length ? `
      <div class="table-wrap"><table class="data-table"><thead><tr><th>ID</th><th>Pelanggan</th><th>Total</th><th>Pesanan</th><th>Pembayaran</th><th>Pengiriman</th><th>Tanggal</th></tr></thead>
      <tbody>${res.recent_orders.map(o => `<tr><td><b>${o.id}</b></td><td>${Admin.escape(o.customer_name || '-')}</td><td>${Admin.rupiah(o.total)}</td><td>${Admin.badge(o.status)}</td><td>${Admin.badge(o.payment_status)}</td><td>${Admin.badge(o.shipping_status)}</td><td>${Admin.dateFmt(o.created_at)}</td></tr>`).join('')}</tbody></table></div>
    ` : `<div class="empty-state"><span class="material-symbols-outlined">receipt_long</span><p>Belum ada pesanan masuk.</p></div>`;

    document.getElementById('erp-summary').innerHTML = `
      <div style="display:flex;align-items:center;justify-content:space-between"><span style="color:var(--text-mute);font-size:13px">Nilai Aset</span><b>${Admin.rupiah(s.asset_value)}</b></div>
      <div style="display:flex;align-items:center;justify-content:space-between"><span style="color:var(--text-mute);font-size:13px">Karyawan Aktif</span><b>${s.employee_count} orang</b></div>
      <div style="display:flex;align-items:center;justify-content:space-between"><span style="color:var(--text-mute);font-size:13px">Laba Bersih</span><b style="color:${s.net_profit >= 0 ? 'var(--success)' : 'var(--danger)'}">${Admin.rupiah(s.net_profit)}</b></div>
      <a href="keuangan.php" class="btn-secondary full" style="margin-top:6px">Lihat Detail Keuangan</a>
    `;
  } catch (e) {
    Admin.toast('Gagal memuat dashboard: ' + e.message, 'error');
  }
})();
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
