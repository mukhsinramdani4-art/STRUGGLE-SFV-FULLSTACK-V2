<?php
$active = 'products';
$pageTitle = 'Produk+';
require __DIR__ . '/config.php';
require __DIR__ . '/partials/header.php';
?>
<div class="page-toolbar">
  <div><p class="subtitle">Kelola produk yang tampil di toko (tambah, ubah, hapus) — perubahan langsung tampil di halaman user.</p></div>
  <div class="toolbar-actions">
    <input id="search-box" placeholder="Cari produk..." style="padding:11px 14px;border:1px solid var(--border);border-radius:10px;min-width:220px">
    <button class="btn-primary" id="btn-add"><span class="material-symbols-outlined">add</span> Tambah Produk</button>
  </div>
</div>

<div class="chip-list" id="cat-filter" style="margin-bottom:16px"></div>

<div class="table-wrap">
  <table class="data-table">
    <thead><tr><th>Produk</th><th>Kategori</th><th>Harga</th><th>Stok</th><th>Status</th><th>Label</th><th>Aksi</th></tr></thead>
    <tbody id="tbl-body"><tr><td colspan="7"><div class="skeleton" style="height:50px"></div></td></tr></tbody>
  </table>
</div>

<script>
let ALL_PRODUCTS = [];
let CATEGORIES = [];
let activeCat = 'all';

const SIZE_OPTIONS = ['XS','S','M','L','XL','XXL'];
const SLEEVE_OPTIONS = ['Short','Long'];

async function loadCategories() {
  const res = await Admin.api('categories.php');
  CATEGORIES = res.categories;
  document.getElementById('cat-filter').innerHTML = `<div class="chip active" data-c="all">Semua (${ALL_PRODUCTS.length})</div>` +
    CATEGORIES.map(c => `<div class="chip" data-c="${c.code}">${Admin.escape(c.name)} (${c.product_count})</div>`).join('');
  document.querySelectorAll('#cat-filter .chip').forEach(chip => chip.addEventListener('click', () => {
    activeCat = chip.dataset.c;
    document.querySelectorAll('#cat-filter .chip').forEach(c => c.classList.remove('active'));
    chip.classList.add('active');
    renderTable();
  }));
}

async function loadProducts() {
  const res = await Admin.api('products.php?status=all');
  ALL_PRODUCTS = res.products;
  renderTable();
}

function renderTable() {
  const q = (document.getElementById('search-box').value || '').toLowerCase();
  const rows = ALL_PRODUCTS.filter(p =>
    (activeCat === 'all' || p.category === activeCat) &&
    (!q || p.name.toLowerCase().includes(q))
  );
  document.getElementById('tbl-body').innerHTML = rows.length ? rows.map(p => `
    <tr>
      <td><div class="cell-product"><img src="../${p.images[0] || 'assets/images/logo.png'}" onerror="this.src='../assets/images/logo.png'"><div><div style="font-weight:700">${Admin.escape(p.name)}</div><div style="font-size:12px;color:var(--text-mute)">${Admin.escape(p.material || '')}</div></div></div></td>
      <td>${Admin.escape(p.category)}</td>
      <td>${Admin.rupiah(p.price)}${p.originalPrice ? `<div style="font-size:11px;color:var(--text-mute);text-decoration:line-through">${Admin.rupiah(p.originalPrice)}</div>` : ''}</td>
      <td><span class="badge ${p.stock <= 10 ? 'danger' : p.stock <= 30 ? 'warning' : 'success'}">${p.stock}</span></td>
      <td><span class="badge ${p.status === 'active' ? 'success' : 'neutral'}">${p.status === 'active' ? 'Aktif' : 'Draft'}</span></td>
      <td>${p.badge ? `<span class="badge info">${Admin.escape(p.badge)}</span>` : '-'}</td>
      <td style="white-space:nowrap">
        <button class="btn-ghost" onclick="openForm(${p.id})"><span class="material-symbols-outlined">edit</span></button>
        <button class="btn-ghost" onclick="deleteProduct(${p.id})"><span class="material-symbols-outlined">delete</span></button>
      </td>
    </tr>`).join('') : `<tr><td colspan="7"><div class="empty-state"><span class="material-symbols-outlined">inventory_2</span><p>Tidak ada produk ditemukan.</p></div></td></tr>`;
}

document.getElementById('search-box').addEventListener('input', renderTable);

function colorRowHtml(name = '', hex = '#1a1a1a') {
  return `<div class="color-row" style="display:flex;gap:8px;margin-bottom:8px;align-items:center">
    <input type="text" placeholder="Nama warna" value="${Admin.escape(name)}" class="c-name" style="flex:1;padding:9px 11px;border:1px solid var(--border);border-radius:8px">
    <input type="color" value="${hex}" class="c-hex" style="width:44px;height:38px;border:1px solid var(--border);border-radius:8px">
    <button type="button" class="btn-ghost" onclick="this.closest('.color-row').remove()"><span class="material-symbols-outlined">close</span></button>
  </div>`;
}

function openForm(id) {
  const p = id ? ALL_PRODUCTS.find(x => x.id === id) : null;
  const isEdit = !!p;
  const images = p ? [...p.images] : [];

  const body = `
    <form id="product-form">
      <div class="form-grid">
        <div class="field span-2"><label>Nama Produk</label><input name="name" required value="${p ? Admin.escape(p.name) : ''}"></div>
        <div class="field"><label>Harga (Rp)</label><input name="price" type="number" required value="${p ? p.price : ''}"></div>
        <div class="field"><label>Harga Coret (opsional)</label><input name="originalPrice" type="number" value="${p && p.originalPrice ? p.originalPrice : ''}"></div>
        <div class="field"><label>Kategori</label>
          <select name="category">${CATEGORIES.map(c => `<option value="${c.code}" ${p && p.category === c.code ? 'selected' : ''}>${Admin.escape(c.name)}</option>`).join('')}</select>
        </div>
        <div class="field"><label>Stok</label><input name="stock" type="number" required value="${p ? p.stock : 0}"></div>
        <div class="field"><label>Berat (gram) — untuk ongkir</label><input name="weightGram" type="number" min="50" required value="${p ? (p.weightGram || 300) : 300}"></div>
        <div class="field"><label>Material</label><input name="material" value="${p ? Admin.escape(p.material || '') : ''}"></div>
        <div class="field"><label>Label Badge</label>
          <select name="badge"><option value="">Tidak ada</option>${['New','Best Seller','Sale','Essential'].map(b => `<option ${p && p.badge === b ? 'selected' : ''}>${b}</option>`).join('')}</select>
        </div>
        <div class="field span-2"><label>Deskripsi</label><textarea name="description">${p ? Admin.escape(p.description || '') : ''}</textarea></div>

        <div class="field span-2"><label>Gambar Produk</label>
          <div class="thumb-picker" id="thumb-picker">
            ${images.map((img, i) => `<div class="thumb" data-src="${img}"><img src="../${img}"><button type="button" onclick="this.closest('.thumb').remove()">×</button></div>`).join('')}
            <label class="thumb-add" style="cursor:pointer">
              <span class="material-symbols-outlined">add_photo_alternate</span>
              <input type="file" id="img-upload" accept="image/*" style="display:none" multiple>
            </label>
          </div>
          <p class="subtitle" id="upload-status" style="margin-top:6px"></p>
        </div>

        <div class="field span-2"><label>Ukuran Tersedia</label>
          <div class="chip-list">${SIZE_OPTIONS.map(s => `<label class="chip size-chip ${p && p.sizes.includes(s) ? 'active' : ''}" data-size="${s}">${s}</label>`).join('')}</div>
        </div>
        <div class="field span-2"><label>Varian Lengan (opsional, untuk kaos/jersey)</label>
          <div class="chip-list">${SLEEVE_OPTIONS.map(s => `<label class="chip sleeve-chip ${p && p.sleeves && p.sleeves.includes(s) ? 'active' : ''}" data-sleeve="${s}">${s}</label>`).join('')}</div>
        </div>
        <div class="field span-2"><label>Varian Warna</label>
          <div id="color-rows">${(p && p.colors.length ? p.colors : [{name:'',hex:'#1a1a1a'}]).map(c => colorRowHtml(c.name, c.hex)).join('')}</div>
          <button type="button" class="btn-secondary" style="width:fit-content" onclick="document.getElementById('color-rows').insertAdjacentHTML('beforeend', colorRowHtml())"><span class="material-symbols-outlined">add</span> Tambah Warna</button>
        </div>

        <div class="field span-2" style="flex-direction:row;gap:20px">
          <label class="checkbox-row"><input type="checkbox" name="isNew" ${p && p.isNew ? 'checked' : ''}> Produk Baru</label>
          <label class="checkbox-row"><input type="checkbox" name="isBestSeller" ${p && p.isBestSeller ? 'checked' : ''}> Best Seller</label>
          <label class="checkbox-row"><input type="checkbox" name="isDeal" ${p && p.isDeal ? 'checked' : ''}> Tampilkan di Deals</label>
          <label class="checkbox-row"><input type="checkbox" name="status" ${!p || p.status === 'active' ? 'checked' : ''}> Aktif (tampil di toko)</label>
        </div>
      </div>
    </form>`;

  const { close, overlay } = Admin.modal({
    title: isEdit ? 'Edit Produk' : 'Tambah Produk',
    bodyHtml: body,
    wide: true,
  });
  overlay.querySelector('.modal-body').insertAdjacentHTML('afterend', `
    <div class="modal-footer">
      <button class="btn-secondary" data-close>Batal</button>
      <button class="btn-primary" id="btn-save-product">${isEdit ? 'Simpan Perubahan' : 'Tambah Produk'}</button>
    </div>`);

  overlay.querySelectorAll('.size-chip').forEach(c => c.addEventListener('click', () => c.classList.toggle('active')));
  overlay.querySelectorAll('.sleeve-chip').forEach(c => c.addEventListener('click', () => c.classList.toggle('active')));

  overlay.querySelector('#img-upload').addEventListener('change', async (e) => {
    const files = Array.from(e.target.files);
    const status = overlay.querySelector('#upload-status');
    for (const file of files) {
      status.textContent = `Mengunggah ${file.name}...`;
      const fd = new FormData(); fd.append('image', file);
      try {
        const up = await Admin.api('upload.php', { method: 'POST', body: fd, isForm: true });
        overlay.querySelector('.thumb-add').insertAdjacentHTML('beforebegin', `<div class="thumb" data-src="${up.path}"><img src="../${up.path}"><button type="button" onclick="this.closest('.thumb').remove()">×</button></div>`);
      } catch (err) { Admin.toast('Upload gagal: ' + err.message, 'error'); }
    }
    status.textContent = files.length ? 'Selesai mengunggah.' : '';
  });

  overlay.querySelector('#btn-save-product').addEventListener('click', async () => {
    const form = overlay.querySelector('#product-form');
    const fd = new FormData(form);
    const btn = overlay.querySelector('#btn-save-product');
    btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> Menyimpan...';

    const payload = {
      name: fd.get('name'), price: Number(fd.get('price')), originalPrice: fd.get('originalPrice') || null,
      category: fd.get('category'), stock: Number(fd.get('stock')), weightGram: Number(fd.get('weightGram')), material: fd.get('material'),
      badge: fd.get('badge') || null, description: fd.get('description'),
      images: Array.from(overlay.querySelectorAll('.thumb')).map(t => t.dataset.src),
      sizes: Array.from(overlay.querySelectorAll('.size-chip.active')).map(c => c.dataset.size),
      sleeves: Array.from(overlay.querySelectorAll('.sleeve-chip.active')).map(c => c.dataset.sleeve),
      colors: Array.from(overlay.querySelectorAll('.color-row')).map(r => ({ name: r.querySelector('.c-name').value, hex: r.querySelector('.c-hex').value })).filter(c => c.name),
      isNew: form.isNew.checked, isBestSeller: form.isBestSeller.checked, isDeal: form.isDeal.checked,
      status: form.status.checked ? 'active' : 'draft',
    };

    try {
      if (isEdit) await Admin.api('products.php?id=' + p.id, { method: 'PUT', body: payload });
      else await Admin.api('products.php', { method: 'POST', body: payload });
      Admin.toast(isEdit ? 'Produk berhasil diperbarui.' : 'Produk berhasil ditambahkan.');
      close();
      loadProducts();
    } catch (err) {
      Admin.toast('Gagal menyimpan: ' + err.message, 'error');
      btn.disabled = false; btn.textContent = isEdit ? 'Simpan Perubahan' : 'Tambah Produk';
    }
  });
}

async function deleteProduct(id) {
  if (!Admin.confirmDelete('Hapus produk ini? Tindakan tidak bisa dibatalkan.')) return;
  try {
    await Admin.api('products.php?id=' + id, { method: 'DELETE' });
    Admin.toast('Produk dihapus.');
    loadProducts();
  } catch (err) { Admin.toast('Gagal menghapus: ' + err.message, 'error'); }
}

document.getElementById('btn-add').addEventListener('click', () => openForm(null));
(async () => { await loadProducts(); await loadCategories(); })();
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
