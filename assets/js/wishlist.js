/**
 * STRUGGLE / STUDIO - Wishlist (per-user)
 */
const Wishlist = {
  getKey() {
    return (typeof Auth !== "undefined" && Auth.wishlistKey) ? Auth.wishlistKey() : "struggle_wishlist";
  },

  get() {
    try {
      return JSON.parse(localStorage.getItem(this.getKey())) || [];
    } catch {
      return [];
    }
  },

  save(ids) {
    localStorage.setItem(this.getKey(), JSON.stringify(ids));
    this.updateBadge();
  },

  has(id) {
    return this.get().includes(parseInt(id, 10));
  },

  toggle(id) {
    if (typeof Auth !== "undefined" && !Auth.isLoggedIn()) {
      if (typeof App !== "undefined") App.toast(_("loginRequired"), "error");
      setTimeout(() => location.href = "login.html?redirect=" + encodeURIComponent(location.pathname.split("/").pop() || "index.html"), 500);
      return false;
    }
    id = parseInt(id, 10);
    let ids = this.get();
    const idx = ids.indexOf(id);
    if (idx >= 0) {
      ids.splice(idx, 1);
      this.save(ids);
      return false;
    }
    ids.push(id);
    this.save(ids);
    return true;
  },

  remove(id) {
    id = parseInt(id, 10);
    this.save(this.get().filter(i => i !== id));
  },

  count() {
    return this.get().length;
  },

  updateBadge() {
    const badges = document.querySelectorAll(".wishlist-badge, #wishlist-count");
    const count = this.count();
    badges.forEach(b => {
      b.textContent = count;
      b.style.display = count > 0 ? "flex" : "none";
    });
  },

  getProducts() {
    return this.get().map(id => getProductById(id)).filter(Boolean);
  }
};
