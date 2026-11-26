/**
 * STRUGGLE / SFV STUDIO — Pemilih lokasi Google Maps
 *
 * - Memuat Google Maps JavaScript API hanya jika API key tersedia di server (api/config.php).
 *   Tanpa key: peta TIDAK ditampilkan dan tidak dianggap aktif; pelanggan tetap bisa memakai
 *   GPS browser atau mengisi koordinat manual.
 * - Fitur: pencarian alamat (Places Autocomplete → fallback Geocoding), "Gunakan Lokasi Saya",
 *   marker yang bisa digeser, reverse geocoding, tampilan latitude/longitude, input manual.
 */
const MapPicker = {
  _loading: null,
  PROVINCE_ALIASES: {
    "daerah khusus ibukota jakarta": "DKI Jakarta", "daerah khusus ibu kota jakarta": "DKI Jakarta", "jakarta": "DKI Jakarta",
    "daerah istimewa yogyakarta": "DI Yogyakarta", "yogyakarta": "DI Yogyakarta", "special region of yogyakarta": "DI Yogyakarta",
    "special capital region of jakarta": "DKI Jakarta", "bangka belitung": "Kepulauan Bangka Belitung",
  },

  mapsKey() { return (Catalog.config && Catalog.config.maps && Catalog.config.maps.enabled) ? Catalog.config.maps.key : null; },

  loadGoogle() {
    if (window.google && google.maps) return Promise.resolve();
    if (this._loading) return this._loading;
    const key = this.mapsKey();
    if (!key) return Promise.reject(new Error("no_key"));
    this._loading = new Promise((resolve, reject) => {
      window.__sfvMapsReady = () => resolve();
      const s = document.createElement("script");
      s.src = "https://maps.googleapis.com/maps/api/js?key=" + encodeURIComponent(key) + "&libraries=places&language=id&region=ID&callback=__sfvMapsReady";
      s.async = true; s.onerror = () => reject(new Error("load_failed"));
      document.head.appendChild(s);
      setTimeout(() => reject(new Error("timeout")), 15000);
    });
    return this._loading;
  },

  canonicalProvince(name, list) {
    const n = String(name || "").toLowerCase().replace(/\b(provinsi|province)\b/g, "").trim();
    if (this.PROVINCE_ALIASES[n]) return this.PROVINCE_ALIASES[n];
    return (list || []).find(p => p.toLowerCase() === n) || "";
  },

  /**
   * @param {HTMLElement} el   wadah
   * @param {{onChange:Function, lat?:number, lng?:number}} opts
   * @returns controller {setPosition(lat,lng,{reverse}), getPosition()}
   */
  mount(el, opts = {}) {
    el.innerHTML = `
      <div class="map-box">
        <div class="map-tools">
          <input type="search" class="form-input" data-role="search" placeholder="Cari alamat / nama tempat…" autocomplete="off">
          <button type="button" class="btn btn-secondary btn-sm" data-role="search-btn"><span class="material-symbols-outlined">search</span></button>
          <button type="button" class="btn btn-secondary btn-sm" data-role="locate"><span class="material-symbols-outlined">my_location</span> Gunakan Lokasi Saya</button>
        </div>
        <div data-role="canvas" class="map-canvas" hidden></div>
        <div data-role="off" class="map-off"></div>
        <div class="map-coords">
          <span><b>Titik pengiriman:</b></span>
          <label>Lat <input class="form-input" data-role="lat" inputmode="decimal" placeholder="-6.2000"></label>
          <label>Lng <input class="form-input" data-role="lng" inputmode="decimal" placeholder="106.8166"></label>
          <button type="button" class="btn btn-secondary btn-sm" data-role="apply">Terapkan</button>
          <button type="button" class="btn-ghost btn-sm" data-role="clear">Hapus titik</button>
        </div>
      </div>
      <div data-role="status" class="notice info" style="display:none"></div>`;
    const q = r => el.querySelector(`[data-role="${r}"]`);
    const state = { lat: null, lng: null, map: null, marker: null, geocoder: null, google: false };
    const status = (msg, kind = "info") => { const s = q("status"); s.className = "notice " + kind; s.textContent = msg; s.style.display = msg ? "" : "none"; };

    const emit = (extra = {}) => opts.onChange && opts.onChange({ lat: state.lat, lng: state.lng, ...extra });
    const showCoords = () => { q("lat").value = state.lat != null ? state.lat.toFixed(6) : ""; q("lng").value = state.lng != null ? state.lng.toFixed(6) : ""; };

    const place = (lat, lng, { reverse = true, pan = true, extra = {} } = {}) => {
      state.lat = lat; state.lng = lng; showCoords();
      if (state.google && state.map) {
        const pos = { lat, lng };
        if (!state.marker) {
          state.marker = new google.maps.Marker({ position: pos, map: state.map, draggable: true, title: "Geser untuk menyesuaikan titik" });
          state.marker.addListener("dragend", () => { const p = state.marker.getPosition(); place(p.lat(), p.lng(), { pan: false }); });
        } else state.marker.setPosition(pos);
        if (pan) { state.map.panTo(pos); if (state.map.getZoom() < 16) state.map.setZoom(17); }
      }
      if (reverse && state.google && state.geocoder) {
        state.geocoder.geocode({ location: { lat, lng }, language: "id" }, (results, st) => {
          if (st === "OK" && results && results[0]) emit(MapPicker.parseResult(results[0]));
          else { emit(extra); status("Alamat otomatis tidak ditemukan untuk titik ini. Isi alamat secara manual.", "warn"); }
        });
      } else emit(extra);
    };

    const geocodeText = (text) => {
      if (!text) return;
      if (!state.google) return status("Pencarian alamat memerlukan Google Maps aktif. Isi alamat manual atau gunakan Lokasi Saya.", "warn");
      state.geocoder.geocode({ address: text, region: "ID", language: "id" }, (results, st) => {
        if (st === "OK" && results[0]) {
          const loc = results[0].geometry.location;
          place(loc.lat(), loc.lng(), { reverse: false, extra: MapPicker.parseResult(results[0]) });
          status("");
        } else status("Alamat tidak ditemukan. Coba kata kunci lain atau geser marker di peta.", "warn");
      });
    };

    q("search-btn").onclick = () => geocodeText(q("search").value.trim());
    q("search").addEventListener("keydown", e => { if (e.key === "Enter") { e.preventDefault(); geocodeText(q("search").value.trim()); } });

    q("locate").onclick = () => {
      if (!navigator.geolocation) return status("Browser Anda tidak mendukung GPS. Tentukan titik di peta atau isi koordinat manual.", "warn");
      status("Meminta izin lokasi…");
      navigator.geolocation.getCurrentPosition(
        p => { status(""); place(p.coords.latitude, p.coords.longitude); },
        err => status(err.code === 1 ? "Izin lokasi ditolak. Anda tetap dapat mencari alamat, menggeser marker, atau mengisi koordinat manual." : "Lokasi tidak dapat ditentukan. Coba lagi atau tentukan manual.", "warn"),
        { enableHighAccuracy: true, timeout: 12000 });
    };
    q("apply").onclick = () => {
      const lat = parseFloat(q("lat").value.replace(",", ".")), lng = parseFloat(q("lng").value.replace(",", "."));
      if (!isFinite(lat) || !isFinite(lng) || Math.abs(lat) > 90 || Math.abs(lng) > 180) return status("Koordinat tidak valid.", "bad");
      status(""); place(lat, lng);
    };
    q("clear").onclick = () => { state.lat = state.lng = null; showCoords(); if (state.marker) { state.marker.setMap(null); state.marker = null; } emit(); };

    const off = q("off");
    const key = this.mapsKey();
    if (!key) {
      off.innerHTML = "<b>Google Maps belum aktif</b> (API key belum dikonfigurasi admin). Anda tetap bisa memakai <i>Gunakan Lokasi Saya</i> (GPS) atau mengisi koordinat secara manual. Alamat di formulir tetap dipakai untuk pengiriman.";
    } else {
      off.textContent = "Memuat peta…";
      this.loadGoogle().then(() => {
        state.google = true;
        off.hidden = true; q("canvas").hidden = false;
        const start = (opts.lat != null && opts.lng != null) ? { lat: opts.lat, lng: opts.lng } : { lat: -2.5, lng: 118 };
        state.map = new google.maps.Map(q("canvas"), { center: start, zoom: opts.lat != null ? 17 : 5, streetViewControl: false, mapTypeControl: false, fullscreenControl: true });
        state.geocoder = new google.maps.Geocoder();
        state.map.addListener("click", e => place(e.latLng.lat(), e.latLng.lng()));
        if (google.maps.places && google.maps.places.Autocomplete) {
          try {
            const ac = new google.maps.places.Autocomplete(q("search"), { componentRestrictions: { country: "id" }, fields: ["geometry", "address_components", "formatted_address", "place_id"] });
            ac.addListener("place_changed", () => {
              const pl = ac.getPlace();
              if (pl && pl.geometry) place(pl.geometry.location.lat(), pl.geometry.location.lng(), { reverse: false, extra: MapPicker.parseResult(pl) });
            });
          } catch { /* Places tidak aktif untuk key ini: pencarian memakai Geocoding */ }
        }
        if (opts.lat != null && opts.lng != null) place(opts.lat, opts.lng, { reverse: false });
      }).catch(() => {
        off.innerHTML = "<b>Peta Google gagal dimuat</b> — periksa API key/pembatasan domain. Gunakan GPS atau koordinat manual.";
      });
    }
    if (opts.lat != null && opts.lng != null) { state.lat = opts.lat; state.lng = opts.lng; showCoords(); }

    return {
      setPosition: (lat, lng, o = {}) => { if (lat == null || lng == null) { q("clear").onclick(); return; } place(+lat, +lng, { reverse: !!o.reverse, extra: o.extra || {} }); },
      getPosition: () => ({ lat: state.lat, lng: state.lng }),
    };
  },

  /** Ekstrak komponen alamat Indonesia dari hasil Google (geocoder / place). */
  parseResult(r) {
    const get = (...types) => { const c = (r.address_components || []).find(x => types.some(t => x.types.includes(t))); return c ? c.long_name : ""; };
    return {
      placeId: r.place_id || "",
      formattedAddress: r.formatted_address || "",
      province: get("administrative_area_level_1"),
      city: get("administrative_area_level_2", "locality"),
      district: get("administrative_area_level_3", "sublocality_level_1"),
      village: get("administrative_area_level_4", "sublocality_level_2", "neighborhood"),
      postal: get("postal_code"),
      street: [get("route"), get("street_number")].filter(Boolean).join(" "),
    };
  },
};
