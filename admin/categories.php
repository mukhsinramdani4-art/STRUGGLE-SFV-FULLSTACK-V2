<?php
$active = 'categories';
$pageTitle = 'Kategori';
require __DIR__ . '/config.php';
require __DIR__ . '/partials/header.php';
?>
<div class="page-toolbar">
  <div><p class="subtitle">Kategori digunakan untuk mengelompokkan produk di toko.</p></div>
  <div class="toolbar-actions"><button class="btn-primary" id="btn-add"><span class="material-symbols-outlined">add</span> Tambah Kategori</button></div>
</div>
<div class="grid cols-3" id="cat-grid"></div>

<script>
const ICONS = ['checkroom','styler','dry_cleaning','straighten','content_cut','shopping_bag','category'];
async function load() {
  const res = await Admin.api('categories.php');
  document.getElementById('cat-grid').innerHTML = res.categories.length ? res.categories.map(c => `
    <div class="card">
      <div style="display:flex;align-items:center;gap:14px;margin-bottom:10px">
        <div class="icon-wrap" style="--stat-color:#c4a77d;width:44px;height:44px;border-radius:12px;display:flex;align-items:center;justify-content:center;background:#f5efe6;color:#8b7355"><span class="material-symbols-outlined">${c.icon}</span></div>
        <div><h3>${Admin.escape(c.name)}</h3><span class="subtitle">${c.product_count} produk</span></div>
      </div>
      <div style="display:flex;gap:8px;margin-top:14px">
        <button class="btn-secondary" style="flex:1" onclick="editCat(${c.id},'${Admin.escape(c.name)}','${c.icon}')"><span class="material-symbols-outlined">edit</span> Edit</button>
        <button class="btn-danger" onclick="delCat(${c.id})"><span class="material-symbols-outlined">delete</span></button>
      </div>
    </div>`).join('') : `<div class="empty-state"><span class="material-symbols-outlined">category</span><p>Belum ada kategori.</p></div>`;
}

function formHtml(name='', icon='checkroom', code='') {
  return `<form id="cat-form">
    <div class="form-grid single">
      ${code ? '' : `<div class="field"><label>Kode unik (huruf kecil, tanpa spasi)</label><input name="code" required placeholder="mis: jaket"></div>`}
      <div class="field"><label>Nama Kategori</label><input name="name" required value="${Admin.escape(name)}"></div>
      <div class="field"><label>Ikon</label><select name="icon">${ICONS.map(i => `<option value="${i}" ${i===icon?'selected':''}>${i}</option>`).join('')}</select></div>
    </div>
  </form>`;
}

function openAdd() {
  const { close, overlay } = Admin.modal({ title: 'Tambah Kategori', bodyHtml: formHtml() });
  overlay.querySelector('.modal-body').insertAdjacentHTML('afterend', `<div class="modal-footer"><button class="btn-secondary" data-close>Batal</button><button class="btn-primary" id="save">Simpan</button></div>`);
  overlay.querySelector('#save').addEventListener('click', async () => {
    const f = overlay.querySelector('#cat-form');
    try {
      await Admin.api('categories.php', { method: 'POST', body: { code: f.code.value, name: f.name.value, icon: f.icon.value } });
      Admin.toast('Kategori ditambahkan.'); close(); load();
    } catch (e) { Admin.toast('Gagal: ' + e.message, 'error'); }
  });
}
function editCat(id, name, icon) {
  const { close, overlay } = Admin.modal({ title: 'Edit Kategori', bodyHtml: formHtml(name, icon, id) });
  overlay.querySelector('.modal-body').insertAdjacentHTML('afterend', `<div class="modal-footer"><button class="btn-secondary" data-close>Batal</button><button class="btn-primary" id="save">Simpan</button></div>`);
  overlay.querySelector('#save').addEventListener('click', async () => {
    const f = overlay.querySelector('#cat-form');
    try {
      await Admin.api('categories.php?id=' + id, { method: 'PUT', body: { name: f.name.value, icon: f.icon.value } });
      Admin.toast('Kategori diperbarui.'); close(); load();
    } catch (e) { Admin.toast('Gagal: ' + e.message, 'error'); }
  });
}
async function delCat(id) {
  if (!Admin.confirmDelete('Hapus kategori ini?')) return;
  try { await Admin.api('categories.php?id=' + id, { method: 'DELETE' }); Admin.toast('Kategori dihapus.'); load(); }
  catch (e) { Admin.toast('Gagal: ' + e.message, 'error'); }
}
document.getElementById('btn-add').addEventListener('click', openAdd);
load();
</script>
<?php require __DIR__ . '/partials/footer.php'; ?>
