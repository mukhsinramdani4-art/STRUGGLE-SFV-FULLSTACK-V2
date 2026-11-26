/**
 * STRUGGLE / STUDIO - Cart (per-user persistence)
 */
const Cart = {
  getKey() {
    return (typeof Auth !== "undefined" && Auth.cartKey) ? Auth.cartKey() : "struggle_cart";
  },

  get() {
    try {
      return JSON.parse(localStorage.getItem(this.getKey())) || [];
    } catch {
      return [];
    }
  },

  save(items) {
    localStorage.setItem(this.getKey(), JSON.stringify(items));
    this.updateBadge();
    document.dispatchEvent(new CustomEvent("cartUpdated", { detail: items }));
  },

  add(product, options = {}) {
    if (typeof Auth !== "undefined" && !Auth.isLoggedIn()) {
      if (typeof App !== "undefined") App.toast(_("loginRequired"), "error");
      setTimeout(() => location.href = "login.html?redirect=" + encodeURIComponent(location.pathname.split("/").pop() || "index.html"), 500);
      return null;
    }
    const items = this.get();
    const color = options.color || (product.colors && product.colors[0] ? product.colors[0].name : "");
    const size = options.size || (product.sizes && product.sizes[0] ? product.sizes[0] : "");
    const qty = options.qty || 1;
    const sleeve = options.sleeve || (product.sleeves && product.sleeves[0]) || "";
    // Harga tampilan di keranjang; harga final SELALU dihitung ulang server saat checkout.
    const price = (sleeve && product.sleevePrices && product.sleevePrices[sleeve]) ? product.sleevePrices[sleeve] : product.price;

    const existing = items.find(i => i.id === product.id && i.color === color && i.size === size && (i.sleeve || "") === sleeve);
    if (existing) {
      existing.qty += qty;
    } else {
      items.push({
        id: product.id,
        name: product.name,
        price,
        image: product.images[0],
        color,
        size,
        sleeve,
        qty,
        material: product.material
      });
    }
    this.save(items);
    return items;
  },

  remove(index) {
    const items = this.get();
    items.splice(index, 1);
    this.save(items);
    return items;
  },

  updateQty(index, qty) {
    const items = this.get();
    if (items[index]) {
      items[index].qty = Math.max(1, parseInt(qty, 10) || 1);
      this.save(items);
    }
    return items;
  },

  clear() {
    this.save([]);
  },

  count() {
    return this.get().reduce((sum, i) => sum + i.qty, 0);
  },

  subtotal() {
    return this.get().reduce((sum, i) => sum + i.price * i.qty, 0);
  },

  updateBadge() {
    const badges = document.querySelectorAll(".cart-badge, #cart-count");
    const count = this.count();
    badges.forEach(b => {
      b.textContent = count;
      b.style.display = count > 0 ? "flex" : "none";
    });
  }
};
