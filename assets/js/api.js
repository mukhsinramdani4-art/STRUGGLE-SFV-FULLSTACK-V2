/**
 * STRUGGLE / SFV STUDIO - API client (storefront)
 * Semua data pelanggan, alamat, pesanan, dan pembayaran dibaca/ditulis lewat server.
 */
const Api = {
  MESSAGES: {
    unauthenticated: "Silakan login terlebih dahulu.",
    invalid_credentials: "Username/email atau password salah.",
    too_many_attempts: "Terlalu banyak percobaan gagal. Coba lagi beberapa menit lagi.",
    account_disabled: "Akun Anda dinonaktifkan. Hubungi admin.",
    fill_all_fields: "Lengkapi semua kolom yang wajib diisi.",
    registration_closed: "Pendaftaran akun baru ditutup. Gunakan akun yang dibagikan admin.",
    email_exists: "Email sudah dipakai akun lain.",
    invalid_input: "Data yang dimasukkan tidak valid.",
    wrong_current_password: "Password saat ini salah.",
    password_too_short: "Password baru minimal 8 karakter.",
    password_same: "Password baru harus berbeda dari password lama.",
    address_incomplete: "Lengkapi nama penerima, telepon, alamat, provinsi, dan kota.",
    invalid_province: "Pilih provinsi dari daftar.",
    invalid_postal: "Kode pos harus 5 digit angka.",
    invalid_coordinates: "Koordinat lokasi tidak valid.",
    address_limit_reached: "Maksimal 10 alamat tersimpan.",
    invalid_email: "Format email tidak valid.",
    items_required: "Keranjang kosong.",
    product_unavailable: "Ada produk di keranjang yang sudah tidak tersedia.",
    invalid_variant: "Varian produk (ukuran/warna) tidak valid. Hapus lalu tambahkan ulang item tersebut.",
    out_of_stock: "Stok salah satu produk tidak mencukupi. Kurangi jumlah atau perbarui keranjang.",
    shipping_required: "Pilih ekspedisi dan layanan pengiriman.",
    shipping_unavailable: "Layanan pengiriman yang dipilih tidak tersedia untuk tujuan ini.",
    payment_method_invalid: "Metode pembayaran tidak tersedia.",
    coupon_invalid: "Kode kupon tidak ditemukan atau tidak aktif.",
    coupon_expired: "Kupon sudah kedaluwarsa.",
    coupon_min_purchase: "Belum memenuhi minimal belanja untuk kupon ini.",
    coupon_limit: "Batas pemakaian kupon Anda sudah tercapai.",
    cannot_cancel: "Pesanan ini tidak dapat dibatalkan lagi.",
    file_required: "Pilih file terlebih dahulu.",
    file_too_large: "Ukuran file terlalu besar (maks. 4 MB).",
    invalid_file_type: "Format file harus JPG, PNG, WEBP, atau PDF.",
    already_paid: "Pesanan ini sudah dibayar.",
    order_cancelled: "Pesanan sudah dibatalkan.",
    cod_no_proof: "Pesanan COD tidak memerlukan bukti pembayaran.",
    csrf_header_missing: "Permintaan ditolak. Muat ulang halaman.",
  },

  msg(code) { return this.MESSAGES[code] || ("Terjadi kesalahan (" + code + ")."); },

  async request(method, url, body, isForm = false) {
    const opts = { method, credentials: "same-origin", headers: { "X-Requested-With": "XMLHttpRequest" } };
    if (body !== undefined && !isForm) { opts.headers["Content-Type"] = "application/json"; opts.body = JSON.stringify(body); }
    if (isForm) opts.body = body;
    let res, data;
    try { res = await fetch(url, opts); } catch (e) { throw Object.assign(new Error("Tidak dapat terhubung ke server."), { code: "network" }); }
    try { data = await res.json(); } catch { data = { ok: false, error: "invalid_response" }; }
    if (!res.ok || data.ok === false) {
      const code = data.error || "request_failed";
      throw Object.assign(new Error(this.msg(code)), { code, status: res.status });
    }
    return data;
  },
  get(url) { return this.request("GET", url); },
  post(url, body = {}) { return this.request("POST", url, body); },
  put(url, body = {}) { return this.request("PUT", url, body); },
  del(url) { return this.request("DELETE", url); },
  upload(url, formData) { return this.request("POST", url, formData, true); },
};

/** Escape HTML untuk data dari server/pengguna sebelum disisipkan ke innerHTML. */
function esc(s) {
  return String(s ?? "").replace(/[&<>"']/g, m => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[m]));
}
