/**
 * STRUGGLE / SFV STUDIO - Auth (sesi di server + database)
 * Akun pelanggan disimpan di database dan diverifikasi server (sesi PHP, cookie HttpOnly).
 * localStorage HANYA menyimpan salinan profil non-sensitif (nama, email) agar UI tidak berkedip;
 * tidak pernah menyimpan password, dan server tetap memeriksa sesi untuk setiap data pelanggan.
 */
const Auth = {
  SESSION_KEY: "struggle_session_hint",
  CART_PREFIX: "struggle_cart_",
  WISHLIST_PREFIX: "struggle_wishlist_",
  _ready: false,
  _waiters: [],

  getSession() {
    try { return JSON.parse(localStorage.getItem(this.SESSION_KEY)); } catch { return null; }
  },
  setSession(user) {
    try {
      if (!user) localStorage.removeItem(this.SESSION_KEY);
      else localStorage.setItem(this.SESSION_KEY, JSON.stringify(user));
    } catch { /* storage tidak tersedia */ }
  },
  currentUser() { return this.getSession(); },
  isLoggedIn() { return !!this.getSession(); },

  /** Verifikasi sesi ke server (sumber kebenaran). */
  async refresh() {
    try {
      const r = await Api.get("api/customer.php?action=me");
      this.setSession(r.user || null);
    } catch (e) {
      if (e.code !== "network") this.setSession(null);
    }
    this._ready = true;
    const w = this._waiters; this._waiters = [];
    w.forEach(fn => { try { fn(this.currentUser()); } catch (err) { console.error(err); } });
    return this.currentUser();
  },
  /** Jalankan fn setelah status login terverifikasi server. */
  whenReady(fn) { if (this._ready) fn(this.currentUser()); else this._waiters.push(fn); },

  async login(login, password) {
    try {
      const r = await Api.post("api/customer.php?action=login", { login, password });
      this.setSession(r.user);
      return { ok: true, user: r.user };
    } catch (e) { return { ok: false, error: e.message }; }
  },

  async register({ name, email, phone, password }) {
    try { await Api.post("api/customer.php?action=register", { name, email, phone, password }); return { ok: true }; }
    catch (e) { return { ok: false, error: e.message }; }
  },

  async logout() {
    try { await Api.post("api/customer.php?action=logout"); } catch { /* tetap hapus sesi lokal */ }
    this.setSession(null);
  },

  async updateProfile(updates) {
    try {
      const r = await Api.post("api/customer.php?action=update_profile", updates);
      this.setSession(r.user);
      return { ok: true, user: r.user };
    } catch (e) { return { ok: false, error: e.message }; }
  },

  async changePassword(current, next) {
    try {
      await Api.post("api/customer.php?action=change_password", { current_password: current, new_password: next });
      const u = this.currentUser(); if (u) { u.mustChangePassword = false; this.setSession(u); }
      return { ok: true };
    } catch (e) { return { ok: false, error: e.message }; }
  },

  cartKey() { const u = this.currentUser(); return u ? this.CART_PREFIX + u.id : "struggle_cart_guest"; },
  wishlistKey() { const u = this.currentUser(); return u ? this.WISHLIST_PREFIX + u.id : "struggle_wishlist_guest"; },

  /** Untuk halaman yang wajib login: tunggu verifikasi server, lalu arahkan ke login bila perlu. */
  requireLogin(redirect = "login.html") {
    return new Promise(resolve => {
      this.whenReady(user => {
        if (user) return resolve(true);
        const back = encodeURIComponent(location.pathname.split("/").pop() + location.search || "index.html");
        location.href = redirect + "?redirect=" + back;
        resolve(false);
      });
    });
  },
};

const User = { get: () => Auth.currentUser(), isLoggedIn: () => Auth.isLoggedIn() };
