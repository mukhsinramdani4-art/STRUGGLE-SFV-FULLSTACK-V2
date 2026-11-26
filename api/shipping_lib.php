<?php
/**
 * Perhitungan ongkos kirim (ESTIMASI / SIMULASI yang dapat dikonfigurasi admin).
 *
 * Rumus layanan reguler/express/cargo:
 *    ongkir = max(min_fee, tarif_zona_per_kg × berat_tertagih_kg)
 *    zona   = 0 kota sama | 1 provinsi sama | 2 satu kelompok pulau | 3 antar-pulau
 * Layanan instan (GoSend/GrabExpress):
 *    ongkir = max(min_fee, per_km_fee × jarak_km), hanya jika jarak <= max_distance_km
 *    (wajib ada titik koordinat tujuan; jarak dihitung dengan rumus haversine).
 * Tarif seluruhnya diatur di Admin > Pengiriman. Ini BUKAN tarif resmi/API ekspedisi.
 */
require_once __DIR__ . '/regions.php';

function haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float {
    $r = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $r * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

function shipping_origin(): array {
    return [
        'city' => (string)setting('origin_city', ''), 'province' => (string)setting('origin_province', ''),
        'lat' => is_numeric(setting('origin_lat')) ? (float)setting('origin_lat') : null,
        'lng' => is_numeric(setting('origin_lng')) ? (float)setting('origin_lng') : null,
    ];
}

/** 0 = kota sama, 1 = provinsi sama, 2 = satu pulau, 3 = antar-pulau. */
function shipping_zone(array $origin, string $destProvince, string $destCity): int {
    $op = canonical_province($origin['province']);
    $dp = canonical_province($destProvince);
    if (!$op || !$dp) return 3;
    if ($op === $dp) {
        if (norm_city($origin['city']) !== '' && norm_city($origin['city']) === norm_city($destCity)) return 0;
        return 1;
    }
    return indonesia_provinces()[$op] === indonesia_provinces()[$dp] ? 2 : 3;
}

function zone_label(int $z): string {
    return ['Dalam kota', 'Dalam provinsi', 'Satu pulau', 'Antar pulau'][$z] ?? '';
}

function format_etd(array $s, int $zone): string {
    $step = (int)$s['etd_step'] * ($s['type'] === 'instant' ? 0 : $zone);
    $min = (int)$s['etd_min'] + $step;
    $max = (int)$s['etd_max'] + $step;
    $unit = $s['etd_unit'] ?: 'hari';
    return $min === $max ? "$min $unit" : "$min–$max $unit";
}

/**
 * Hitung opsi ongkir untuk satu tujuan.
 * @return array{options: array, zone: int, zone_label: string, weight_gram: int, billable_kg: int, distance_km: ?float}
 */
function shipping_quote(PDO $pdo, string $province, string $city, ?float $lat, ?float $lng, int $weightGram): array {
    $origin = shipping_origin();
    $zone = shipping_zone($origin, $province, $city);
    $billableKg = max(1, (int)ceil($weightGram / 1000));
    $distance = null;
    if ($lat !== null && $lng !== null && $origin['lat'] !== null && $origin['lng'] !== null) {
        $distance = round(haversine_km($origin['lat'], $origin['lng'], $lat, $lng), 1);
    }

    $rows = $pdo->query("SELECT s.*, c.name AS courier_name, c.description AS courier_desc FROM shipping_services s
        JOIN shipping_couriers c ON c.code = s.courier_code
        WHERE s.enabled = 1 AND c.enabled = 1 ORDER BY c.sort_order ASC, s.sort_order ASC")->fetchAll();

    $options = [];
    foreach ($rows as $s) {
        $opt = [
            'courier' => $s['courier_code'], 'courierName' => $s['courier_name'],
            'service' => $s['code'], 'serviceName' => $s['name'], 'type' => $s['type'],
            'description' => $s['description'], 'etd' => format_etd($s, $zone),
            'available' => true, 'reason' => null, 'fee' => 0,
        ];
        if ($s['type'] === 'instant') {
            if ($distance === null) {
                $opt['available'] = false; $opt['reason'] = 'Tentukan titik lokasi di peta untuk melihat tarif.';
            } elseif ($distance > (int)$s['max_distance_km']) {
                $opt['available'] = false; $opt['reason'] = 'Di luar jangkauan (' . $distance . ' km, maks. ' . (int)$s['max_distance_km'] . ' km).';
            } else {
                $opt['fee'] = max((int)$s['min_fee'], (int)round($distance * (int)$s['per_km_fee']));
            }
        } else {
            $rate = [(int)$s['rate_local'], (int)$s['rate_province'], (int)$s['rate_island'], (int)$s['rate_national']][$zone];
            $opt['fee'] = max((int)$s['min_fee'], $rate * $billableKg);
        }
        $options[] = $opt;
    }
    return [
        'options' => $options, 'zone' => $zone, 'zone_label' => zone_label($zone),
        'weight_gram' => $weightGram, 'billable_kg' => $billableKg, 'distance_km' => $distance,
        'origin' => ['city' => $origin['city'], 'province' => $origin['province']],
        'is_estimate' => true,
    ];
}

/** Total berat (gram) dari daftar item [{id, qty}] berdasarkan data produk di database. */
function items_weight(PDO $pdo, array $items): int {
    $total = 0;
    foreach ($items as $it) {
        $st = $pdo->prepare("SELECT weight_gram FROM products WHERE id = ?");
        $st->execute([(int)($it['id'] ?? 0)]);
        $w = (int)$st->fetchColumn();
        $total += max(100, $w) * max(1, (int)($it['qty'] ?? 1));
    }
    return $total;
}
