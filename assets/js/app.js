/**
 * STRUGGLE / STUDIO - Global App
 */
const App = {
  init() {
    I18n.init();
    this.initSidebar();
    this.initSearch();
    this.initToast();
    this.initLanguageSwitcher();
    Cart.updateBadge();
    Wishlist.updateBadge();
    this.updateUserUI();
    this.bindAuthGuards();
    // Sesi diverifikasi ke server; UI diperbarui setelah hasilnya datang.
    Auth.refresh().then(() => { this.updateUserUI(); Cart.updateBadge(); Wishlist.updateBadge(); });
  },

  initSidebar() {
    const toggle = document.getElementById("sidebar-toggle");
    const sidebar = document.getElementById("sidebar");
    let overlay = document.getElementById("sidebar-overlay");
    if (!overlay) {
      overlay = document.createElement("div");
      overlay.id = "sidebar-overlay";
      overlay.className = "sidebar-overlay";
      document.body.appendChild(overlay);
    }
    const isMobile = () => window.innerWidth <= 992;

    // Restore desktop collapsed preference
    if (!isMobile() && localStorage.getItem("sfv_sidebar_collapsed") === "1") {
      document.body.classList.add("sidebar-collapsed");
    }

    if (toggle && sidebar) {
      toggle.addEventListener("click", () => {
        if (isMobile()) {
          sidebar.classList.toggle("open");
          overlay.classList.toggle("show");
        } else {
          document.body.classList.toggle("sidebar-collapsed");
          localStorage.setItem(
            "sfv_sidebar_collapsed",
            document.body.classList.contains("sidebar-collapsed") ? "1" : "0"
          );
        }
      });
    }
    overlay.addEventListener("click", () => {
      sidebar.classList.remove("open");
      overlay.classList.remove("show");
    });
    document.querySelectorAll(".sidebar .nav-link").forEach(link => {
      link.addEventListener("click", () => {
        if (isMobile()) {
          sidebar.classList.remove("open");
          overlay.classList.remove("show");
        }
      });
    });
    const path = window.location.pathname.split("/").pop() || "index.html";
    document.querySelectorAll(".nav-link").forEach(link => {
      const href = link.getAttribute("href");
      if (href === path || (path === "" && href === "index.html")) {
        link.classList.add("active");
      }
    });
  },

  initSearch() {
    const input = document.getElementById("search-input");
    if (input) {
      input.addEventListener("keydown", e => {
        if (e.key === "Enter") {
          const q = input.value.trim();
          if (q) window.location.href = `categories.html?q=${encodeURIComponent(q)}`;
        }
      });
    }
  },

  initToast() {
    if (!document.getElementById("toast-container")) {
      const div = document.createElement("div");
      div.id = "toast-container";
      document.body.appendChild(div);
    }
  },

  initLanguageSwitcher() {
    document.querySelectorAll("[data-lang]").forEach(btn => {
      btn.addEventListener("click", () => {
        const lang = btn.getAttribute("data-lang");
        I18n.setLang(lang);
        document.querySelectorAll("[data-lang]").forEach(b => b.classList.toggle("active", b.getAttribute("data-lang") === lang));
        // Re-render dynamic parts if needed
        if (typeof renderCategories === "function") renderCategories();
        if (typeof renderHomeProducts === "function") renderHomeProducts();
        location.reload(); // simplest reliable way for full i18n
      });
    });
    // Highlight current
    const lang = I18n.getLang();
    document.querySelectorAll("[data-lang]").forEach(b => b.classList.toggle("active", b.getAttribute("data-lang") === lang));
  },

  toast(message, type = "success") {
    const container = document.getElementById("toast-container");
    if (!container) return;
    const el = document.createElement("div");
    el.className = `toast toast-${type}`;
    el.innerHTML = `
      <span class="material-symbols-outlined">${type === "success" ? "check_circle" : type === "error" ? "error" : "info"}</span>
      <span>${message}</span>
    `;
    container.appendChild(el);
    requestAnimationFrame(() => el.classList.add("show"));
    setTimeout(() => {
      el.classList.remove("show");
      setTimeout(() => el.remove(), 300);
    }, 2800);
  },

  updateUserUI() {
    const user = Auth.currentUser();
    const nameEl = document.querySelector(".user-name");
    const roleEl = document.querySelector(".user-role");
    const avatarEl = document.querySelector(".user-avatar img, .user-avatar");
    if (nameEl) nameEl.textContent = user ? user.name : _("guest");
    if (roleEl) roleEl.textContent = user ? (user.username || "Pelanggan") : _("signIn");
    if (avatarEl && user && user.avatar) {
      if (avatarEl.tagName === "IMG") avatarEl.src = user.avatar;
      else {
        avatarEl.innerHTML = `<img src="${user.avatar}" alt="${user.name}">`;
      }
    }
    // Login/Logout buttons
    document.querySelectorAll("[data-auth-show]").forEach(el => {
      const show = el.getAttribute("data-auth-show");
      if (show === "logged") el.style.display = user ? "" : "none";
      if (show === "guest") el.style.display = user ? "none" : "";
    });
  },

  bindAuthGuards() {
    // Intercept cart / wishlist links when not logged in
    document.querySelectorAll("[data-require-auth]").forEach(el => {
      el.addEventListener("click", e => {
        if (!Auth.isLoggedIn()) {
          e.preventDefault();
          this.toast(_("loginRequired"), "error");
          setTimeout(() => location.href = "login.html?redirect=" + encodeURIComponent(el.getAttribute("href") || "index.html"), 500);
        }
      });
    });
  },

  renderProductCard(product, options = {}) {
    const inWishlist = Wishlist.has(product.id);
    const discount = product.originalPrice
      ? Math.round((1 - product.price / product.originalPrice) * 100)
      : 0;
    return `
      <article class="product-card" data-id="${product.id}">
        <div class="product-card-image">
          <a href="product.html?id=${product.id}">
            <img src="${product.images[0]}" alt="${product.name}" loading="lazy" onerror="this.src='assets/images/products/tee-white.jpg'">
          </a>
          ${product.badge ? `<span class="product-badge">${product.badge}</span>` : ""}
          ${discount > 0 ? `<span class="product-badge badge-sale">-${discount}%</span>` : ""}
          <button class="btn-icon wishlist-btn ${inWishlist ? "active" : ""}" onclick="App.toggleWishlist(${product.id}, this)" aria-label="Wishlist">
            <span class="material-symbols-outlined">${inWishlist ? "favorite" : "favorite_border"}</span>
          </button>
          <button class="btn-quick-add" onclick="App.quickAdd(${product.id})">
            <span class="material-symbols-outlined">add_shopping_cart</span>
            <span data-i18n="quickAdd">${_("quickAdd")}</span>
          </button>
        </div>
        <div class="product-card-body">
          <a href="product.html?id=${product.id}" class="product-name">${product.name}</a>
          <div class="product-meta">
            <span class="product-material">${product.material}</span>
          </div>
          <div class="product-price-row">
            <span class="product-price">${formatPrice(product.price)}</span>
            ${product.originalPrice ? `<span class="product-price-old">${formatPrice(product.originalPrice)}</span>` : ""}
          </div>
          <div class="color-swatches">
            ${(product.colors || []).slice(0, 4).map(c =>
              `<span class="swatch" style="background:${c.hex}" title="${c.name}"></span>`
            ).join("")}
          </div>
        </div>
      </article>
    `;
  },

  toggleWishlist(id, btn) {
    if (!Auth.isLoggedIn()) {
      this.toast(_("loginRequired"), "error");
      setTimeout(() => location.href = "login.html", 500);
      return;
    }
    const added = Wishlist.toggle(id);
    if (btn) {
      btn.classList.toggle("active", added);
      const icon = btn.querySelector(".material-symbols-outlined");
      if (icon) icon.textContent = added ? "favorite" : "favorite_border";
    }
    this.toast(added ? _("addedToWishlist") : _("removedFromWishlist"));
  },

  quickAdd(id) {
    if (!Auth.isLoggedIn()) {
      this.toast(_("loginRequired"), "error");
      setTimeout(() => location.href = "login.html", 500);
      return;
    }
    const product = getProductById(id);
    if (!product) return;
    Cart.add(product);
    this.toast(_("addedToCart"));
  },

  logout() {
    if (confirm(_("confirmLogout"))) {
      Auth.logout().then(() => {
        this.toast("Logged out");
        setTimeout(() => location.href = "index.html", 400);
      });
    }
  }
};

document.addEventListener("DOMContentLoaded", () => App.init());
