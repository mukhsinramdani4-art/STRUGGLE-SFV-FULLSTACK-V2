/**
 * SFV Business OS — Admin shared JS
 * Helper umum: toast, modal, fetch wrapper ke /api, format angka, sidebar mobile.
 */
const Admin = {
  init() {
    this.initSidebar();
  },
  initSidebar() {
    const btn = document.getElementById('btn-burger');
    const sidebar = document.getElementById('sidebar');
    if (!btn || !sidebar) return;
    btn.addEventListener('click', () => sidebar.classList.toggle('open'));
    document.addEventListener('click', (e) => {
      if (sidebar.classList.contains('open') && !sidebar.contains(e.target) && e.target !== btn && !btn.contains(e.target)) {
        sidebar.classList.remove('open');
      }
    });
  },
  toast(message, type = 'success') {
    const root = document.getElementById('toast-root');
    if (!root) return;
    const el = document.createElement('div');
    el.className = `toast ${type}`;
    const icon = type === 'success' ? 'check_circle' : type === 'error' ? 'error' : 'info';
    el.innerHTML = `<span class="material-symbols-outlined">${icon}</span><span>${message}</span>`;
    root.appendChild(el);
    setTimeout(() => { el.style.opacity = '0'; el.style.transform = 'translateX(30px)'; el.style.transition = 'all .25s'; setTimeout(() => el.remove(), 260); }, 3200);
  },
  rupiah(n) {
    return 'Rp ' + Number(n || 0).toLocaleString('id-ID');
  },
  dateFmt(iso) {
    if (!iso) return '-';
    const d = new Date(iso.replace(' ', 'T'));
    if (isNaN(d)) return iso;
    return d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
  },
  async api(path, { method = 'GET', body, isForm = false } = {}) {
    const opts = { method, headers: { 'X-Requested-With': 'XMLHttpRequest' } };
    if (body && !isForm) {
      opts.headers['Content-Type'] = 'application/json';
      opts.body = JSON.stringify(body);
    } else if (body && isForm) {
      opts.body = body;
    }
    const res = await fetch('../api/' + path, opts);
    let data;
    try { data = await res.json(); } catch { data = { ok: false, error: 'invalid_response' }; }
    if (!res.ok || data.ok === false) {
      const err = data.error || 'request_failed';
      if (res.status === 401) { location.href = 'login.php'; }
      throw new Error(err);
    }
    return data;
  },
  modal({ title, bodyHtml, onMount, wide = false }) {
    const overlay = document.createElement('div');
    overlay.className = 'modal-overlay';
    overlay.innerHTML = `
      <div class="modal-box" style="${wide ? 'max-width:820px' : ''}">
        <div class="modal-header"><h3>${title}</h3><button class="icon-btn" data-close><span class="material-symbols-outlined">close</span></button></div>
        <div class="modal-body">${bodyHtml}</div>
      </div>`;
    document.body.appendChild(overlay);
    const close = () => overlay.remove();
    overlay.addEventListener('click', (e) => { if (e.target === overlay || e.target.closest('[data-close]')) close(); });
    if (onMount) onMount(overlay, close);
    return { overlay, close };
  },
  badgeClass(status) {
    return {
      'Pending': 'warning', 'Diproses': 'info', 'Dikemas': 'info', 'Dikirim': 'info', 'Selesai': 'success', 'Dibatalkan': 'danger',
      'Belum Dibayar': 'warning', 'Menunggu Verifikasi': 'info', 'Dibayar': 'success', 'Gagal': 'danger', 'Dikembalikan': 'neutral',
      'Menunggu Pengiriman': 'warning', 'Diproses Kurir': 'info', 'Dalam Perjalanan': 'info', 'Diterima': 'success',
    }[status] || 'neutral';
  },
  badge(status) { return `<span class="badge ${this.badgeClass(status)}">${this.escape(status || '-')}</span>`; },
  dateTimeFmt(iso) {
    if (!iso) return '-';
    const d = new Date(String(iso).replace(' ', 'T'));
    if (isNaN(d)) return iso;
    return d.toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
  },
  confirmDelete(message) {
    return window.confirm(message || 'Yakin ingin menghapus data ini?');
  },
  escape(str) {
    return String(str ?? '').replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]));
  },
};
document.addEventListener('DOMContentLoaded', () => Admin.init());
