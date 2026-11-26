/**
 * STRUGGLE / SFV STUDIO - Catalog (data dinamis dari database)
 * Seluruh produk, kategori, koleksi, dan kupon dimuat dari API (/api) — tidak ada data produk
 * statis di frontend. Data dikelola lewat Dashboard Admin.
 */
const PRODUCTS = [];

// Kategori diisi dari database; hanya "Semua" yang bersifat tetap.
const CATEGORIES = [{ id: "all", nameKey: "catAll", icon: "grid_view" }];

const COLLECTIONS = [];

const COUPONS = [];

const BANNERS = [
  { image: "assets/images/banners/manip1_struggle.png", titleKey: "banner1Title", subKey: "banner1Sub", link: "collections.html" },
  { image: "assets/images/banners/manip2_seringal.png", titleKey: "banner2Title", subKey: "banner2Sub", link: "categories.html?cat=kaos" },
  { image: "assets/images/banners/manip3_zenithral.png", titleKey: "banner3Title", subKey: "banner3Sub", link: "new-arrivals.html" },
  { image: "assets/images/banners/manip4_atlantik.png", titleKey: "banner4Title", subKey: "banner4Sub", link: "deals.html" },
  { image: "assets/images/banners/manip5_chemistry.png", titleKey: "banner5Title", subKey: "banner5Sub", link: "collections.html" }
];

function formatPrice(price) {
  return "Rp " + Number(price).toLocaleString("id-ID");
}

function getProductById(id) {
  return PRODUCTS.find(p => p.id === parseInt(id, 10));
}

function getProductsByCategory(category) {
  if (!category || category === "all") return PRODUCTS;
  if (category === "celana") {
    return PRODUCTS.filter(p => p.category === "celana_panjang" || p.category === "celana_pendek");
  }
  return PRODUCTS.filter(p => p.category === category);
}

function getNewArrivals() {
  return PRODUCTS.filter(p => p.isNew);
}

function getBestSellers() {
  return PRODUCTS.filter(p => p.isBestSeller);
}

function getDeals() {
  return PRODUCTS.filter(p => p.isDeal);
}

function getCollectionProducts(collectionId) {
  return PRODUCTS.filter(p => p.collection === collectionId);
}

function getCategoryCount(catId) {
  if (catId === "all") return PRODUCTS.length;
  if (catId === "celana") return PRODUCTS.filter(p => p.category.startsWith("celana")).length;
  return PRODUCTS.filter(p => p.category === catId).length;
}


const Catalog = {
  ready: false,
  failed: false,
  config: null,
  _callbacks: [],

  async _get(url) {
    const res = await fetch(url, { cache: "no-store", credentials: "same-origin" });
    const data = await res.json();
    if (!res.ok || !data.ok) throw new Error(data.error || "api_error");
    return data;
  },

  async init() {
    try {
      const [prod, cat, col, cpn, cfg] = await Promise.all([
        this._get("api/products.php"),
        this._get("api/categories.php"),
        this._get("api/collections.php"),
        this._get("api/coupons.php?public=1"),
        this._get("api/config.php"),
      ]);
      PRODUCTS.length = 0; PRODUCTS.push(...(prod.products || []));
      CATEGORIES.length = 0;
      CATEGORIES.push({ id: "all", nameKey: "catAll", icon: "grid_view" },
        ...(cat.categories || []).map(c => ({ id: c.code, name: c.name, icon: c.icon })));
      COLLECTIONS.length = 0; COLLECTIONS.push(...(col.collections || []));
      COUPONS.length = 0; COUPONS.push(...(cpn.coupons || []));
      this.config = cfg;
      this.ready = true;
      this.applyStoreInfo();
    } catch (err) {
      this.failed = true;
      console.error("Catalog: gagal memuat data dari server —", err.message);
      this.showOfflineBanner();
    }
    document.dispatchEvent(new CustomEvent("catalogUpdated"));
    this._callbacks.forEach(fn => { try { fn(); } catch (e) { console.error(e); } });
    this._callbacks = [];
  },

  /** Jalankan fn setelah data katalog dari server siap (atau gagal dimuat, agar halaman tetap menampilkan status). */
  onReady(fn) {
    if (this.ready || this.failed) { fn(); return; }
    this._callbacks.push(fn);
  },

  showOfflineBanner() {
    if (document.getElementById("offline-banner")) return;
    const b = document.createElement("div");
    b.id = "offline-banner";
    b.style.cssText = "position:fixed;top:0;left:0;right:0;z-index:9999;background:#7a2a2a;color:#fff;padding:10px 16px;font-size:13px;text-align:center";
    b.textContent = "Tidak dapat terhubung ke server. Pastikan backend PHP berjalan, lalu muat ulang halaman.";
    document.body.appendChild(b);
  },

  /** Isi footer/kontak toko dari pengaturan di dashboard admin. */
  applyStoreInfo() {
    const s = this.config && this.config.store;
    if (!s) return;
    document.querySelectorAll("[data-store]").forEach(el => {
      const k = el.getAttribute("data-store");
      const v = s[k];
      if (el.tagName === "A") {
        if (k === "whatsapp") el.href = v ? "https://wa.me/" + v : "#";
        else if (k === "instagram") el.href = v ? "https://www.instagram.com/" + v : "#";
        else if (k === "link") el.href = v || "#";
        else if (k === "email") el.href = v ? "mailto:" + v : "#";
        if (!v) el.closest(".footer-contact-item")?.remove();
      } else {
        if (v) el.textContent = k === "instagram" ? "@" + v : (k === "groupName" ? v : v);
        else el.closest(".footer-contact-item")?.remove();
      }
    });
  },
};

document.addEventListener("DOMContentLoaded", () => Catalog.init());
