<?php
/**
 * Data wilayah statis: 38 provinsi Indonesia + kelompok pulau (dipakai untuk zona ongkir).
 * Kota/kabupaten, kecamatan, dan kelurahan diisi bebas oleh pelanggan (atau otomatis dari Google Maps).
 */

function indonesia_provinces(): array {
    return [
        'Aceh' => 'Sumatera', 'Sumatera Utara' => 'Sumatera', 'Sumatera Barat' => 'Sumatera', 'Riau' => 'Sumatera',
        'Kepulauan Riau' => 'Sumatera', 'Jambi' => 'Sumatera', 'Sumatera Selatan' => 'Sumatera',
        'Kepulauan Bangka Belitung' => 'Sumatera', 'Bengkulu' => 'Sumatera', 'Lampung' => 'Sumatera',
        'DKI Jakarta' => 'Jawa', 'Banten' => 'Jawa', 'Jawa Barat' => 'Jawa', 'Jawa Tengah' => 'Jawa',
        'DI Yogyakarta' => 'Jawa', 'Jawa Timur' => 'Jawa',
        'Bali' => 'Bali & Nusa Tenggara', 'Nusa Tenggara Barat' => 'Bali & Nusa Tenggara', 'Nusa Tenggara Timur' => 'Bali & Nusa Tenggara',
        'Kalimantan Barat' => 'Kalimantan', 'Kalimantan Tengah' => 'Kalimantan', 'Kalimantan Selatan' => 'Kalimantan',
        'Kalimantan Timur' => 'Kalimantan', 'Kalimantan Utara' => 'Kalimantan',
        'Sulawesi Utara' => 'Sulawesi', 'Gorontalo' => 'Sulawesi', 'Sulawesi Tengah' => 'Sulawesi',
        'Sulawesi Barat' => 'Sulawesi', 'Sulawesi Selatan' => 'Sulawesi', 'Sulawesi Tenggara' => 'Sulawesi',
        'Maluku' => 'Maluku & Papua', 'Maluku Utara' => 'Maluku & Papua', 'Papua' => 'Maluku & Papua',
        'Papua Barat' => 'Maluku & Papua', 'Papua Barat Daya' => 'Maluku & Papua', 'Papua Selatan' => 'Maluku & Papua',
        'Papua Tengah' => 'Maluku & Papua', 'Papua Pegunungan' => 'Maluku & Papua',
    ];
}

function norm_region(string $s): string {
    $s = mb_strtolower(trim($s));
    $s = preg_replace('/\b(provinsi|province|prov\.?)\b/u', '', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
}

/** Nama provinsi kanonik (sesuai daftar) atau null bila tidak dikenali. */
function canonical_province(string $name): ?string {
    $n = norm_region($name);
    if ($n === '') return null;
    $alias = [
        'daerah khusus ibukota jakarta' => 'DKI Jakarta', 'daerah khusus ibu kota jakarta' => 'DKI Jakarta', 'jakarta' => 'DKI Jakarta',
        'special capital region of jakarta' => 'DKI Jakarta', 'dki' => 'DKI Jakarta',
        'daerah istimewa yogyakarta' => 'DI Yogyakarta', 'special region of yogyakarta' => 'DI Yogyakarta', 'yogyakarta' => 'DI Yogyakarta', 'diy' => 'DI Yogyakarta',
        'bangka belitung' => 'Kepulauan Bangka Belitung', 'babel' => 'Kepulauan Bangka Belitung',
        'west java' => 'Jawa Barat', 'central java' => 'Jawa Tengah', 'east java' => 'Jawa Timur',
    ];
    if (isset($alias[$n])) return $alias[$n];
    foreach (indonesia_provinces() as $p => $_) if (mb_strtolower($p) === $n) return $p;
    return null;
}

function province_island(string $name): ?string {
    $p = canonical_province($name);
    return $p ? indonesia_provinces()[$p] : null;
}

function norm_city(string $s): string {
    $s = mb_strtolower(trim($s));
    $s = preg_replace('/\b(kota administrasi|kota|kabupaten|kab\.?|city|regency)\b/u', '', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return trim($s);
}

/** Validasi & normalisasi input alamat (dipakai buku alamat dan checkout). */
function address_from_input(array $b): array {
    $lat = isset($b['lat']) && $b['lat'] !== '' && $b['lat'] !== null ? (float)$b['lat'] : null;
    $lng = isset($b['lng']) && $b['lng'] !== '' && $b['lng'] !== null ? (float)$b['lng'] : null;
    if ($lat !== null && ($lat < -90 || $lat > 90)) fail('invalid_coordinates');
    if ($lng !== null && ($lng < -180 || $lng > 180)) fail('invalid_coordinates');
    if (($lat === null) !== ($lng === null)) fail('invalid_coordinates');
    $a = [
        'label' => str_in($b['label'] ?? 'Alamat', 60),
        'recipient' => str_in($b['recipient'] ?? '', 120),
        'phone' => str_in($b['phone'] ?? '', 30),
        'address' => str_in($b['address'] ?? '', 500),
        'province' => str_in($b['province'] ?? '', 80),
        'city' => str_in($b['city'] ?? '', 100),
        'district' => str_in($b['district'] ?? '', 100),
        'village' => str_in($b['village'] ?? '', 100),
        'postal_code' => str_in($b['postal'] ?? $b['postal_code'] ?? '', 10),
        'notes' => str_in($b['notes'] ?? '', 300),
        'lat' => $lat, 'lng' => $lng,
        'place_id' => str_in($b['placeId'] ?? $b['place_id'] ?? '', 191),
        'formatted_address' => str_in($b['formattedAddress'] ?? $b['formatted_address'] ?? '', 500),
    ];
    if ($a['recipient'] === '' || $a['phone'] === '' || $a['address'] === '' || $a['province'] === '' || $a['city'] === '') fail('address_incomplete');
    $canon = canonical_province($a['province']);
    if (!$canon) fail('invalid_province');
    $a['province'] = $canon;
    if ($a['postal_code'] !== '' && !preg_match('/^\d{5}$/', $a['postal_code'])) fail('invalid_postal');
    return $a;
}
