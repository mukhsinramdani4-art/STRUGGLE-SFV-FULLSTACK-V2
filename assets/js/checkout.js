/**
 * STRUGGLE / SFV STUDIO - Alamat, pesanan, ongkir (semua lewat API server)
 */
const Addresses = {
  async list() { return (await Api.get("api/addresses.php")).addresses; },
  async add(a) { return (await Api.post("api/addresses.php", a)).addresses; },
  async update(id, a) { return (await Api.put("api/addresses.php?id=" + id, a)).addresses; },
  async remove(id) { return (await Api.del("api/addresses.php?id=" + id)).addresses; },
  async setDefault(id) { return (await Api.post("api/addresses.php?action=default&id=" + id)).addresses; },
};

const Orders = {
  async list() { return (await Api.get("api/orders.php")).orders; },
  async get(id) { return (await Api.get("api/orders.php?id=" + encodeURIComponent(id))).order; },
  async create(payload) { return (await Api.post("api/orders.php", payload)).order; },
  async cancel(id) { return (await Api.post("api/orders.php?action=cancel&id=" + encodeURIComponent(id))).order; },
  async checkCoupon(code, subtotal, shippingFee) { return await Api.post("api/orders.php?action=coupon_check", { code, subtotal, shippingFee }); },
  async uploadProof(orderId, file, note) {
    const fd = new FormData();
    fd.append("order_id", orderId); fd.append("proof", file); fd.append("note", note || "");
    return (await Api.upload("api/payments.php?action=proof", fd)).order;
  },
};

const Shipping = {
  async provinces() { return (await Api.get("api/shipping.php?action=provinces")).provinces; },
  async quote(items, dest) { return await Api.post("api/shipping.php?action=quote", { items, ...dest }); },
};

const STATUS_STYLE = {
  "Pending": "warn", "Diproses": "info", "Dikemas": "info", "Dikirim": "info", "Selesai": "ok", "Dibatalkan": "bad",
  "Belum Dibayar": "warn", "Menunggu Verifikasi": "info", "Dibayar": "ok", "Gagal": "bad", "Dikembalikan": "muted",
  "Menunggu Pengiriman": "warn", "Diproses Kurir": "info", "Dalam Perjalanan": "info", "Diterima": "ok",
};
function statusPill(s) { return `<span class="pill pill-${STATUS_STYLE[s] || "muted"}">${esc(s || "-")}</span>`; }
function fmtDateTime(iso) {
  if (!iso) return "-";
  const d = new Date(String(iso).replace(" ", "T"));
  return isNaN(d) ? iso : d.toLocaleString("id-ID", { day: "2-digit", month: "long", year: "numeric", hour: "2-digit", minute: "2-digit" });
}
