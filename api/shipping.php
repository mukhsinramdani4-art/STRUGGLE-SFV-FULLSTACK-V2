<?php
/**
 * /api/shipping.php — Ekspedisi & estimasi ongkir.
 *
 * Publik/pelanggan:
 *   GET  ?action=provinces                         daftar provinsi
 *   POST ?action=quote  {items, province, city, lat?, lng?}   opsi ongkir (ESTIMASI)
 * Admin (Super Admin/Owner/Manager):
 *   GET  ?action=admin                              kurir + layanan
 *   PUT  ?action=courier&id=   {name?, description?, enabled?, sort_order?}
 *   POST ?action=service        buat layanan baru
 *   PUT  ?action=service&id=    ubah layanan
 *   DELETE ?action=service&id=  hapus layanan
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/shipping_lib.php';
$pdo = db();
$action = $_GET['action'] ?? '';
const SHIP_ADMINS = ['Super Admin', 'Owner', 'Manager'];

if (method() === 'GET' && $action === 'provinces') {
    ok(['provinces' => array_keys(indonesia_provinces())]);
}

if (method() === 'POST' && $action === 'quote') {
    $b = json_input();
    $items = is_array($b['items'] ?? null) ? $b['items'] : [];
    if (!$items) fail('items_required');
    $province = str_in($b['province'] ?? '', 80);
    $city = str_in($b['city'] ?? '', 100);
    if (!canonical_province($province) || $city === '') fail('destination_required');
    $lat = isset($b['lat']) && $b['lat'] !== '' && $b['lat'] !== null ? (float)$b['lat'] : null;
    $lng = isset($b['lng']) && $b['lng'] !== '' && $b['lng'] !== null ? (float)$b['lng'] : null;
    ok(shipping_quote($pdo, $province, $city, $lat, $lng, items_weight($pdo, $items)));
}

if (method() === 'GET' && $action === 'admin') {
    require_role(SHIP_ADMINS);
    ok([
        'couriers' => $pdo->query("SELECT * FROM shipping_couriers ORDER BY sort_order, id")->fetchAll(),
        'services' => $pdo->query("SELECT * FROM shipping_services ORDER BY courier_code, sort_order, id")->fetchAll(),
        'origin' => shipping_origin(),
    ]);
}

$serviceFields = ['name' => 's', 'type' => 's', 'description' => 's', 'rate_local' => 'i', 'rate_province' => 'i', 'rate_island' => 'i',
    'rate_national' => 'i', 'min_fee' => 'i', 'per_km_fee' => 'i', 'max_distance_km' => 'i', 'etd_min' => 'i', 'etd_max' => 'i',
    'etd_step' => 'i', 'etd_unit' => 's', 'enabled' => 'i', 'sort_order' => 'i'];

if ($action === 'courier' && method() === 'PUT') {
    $u = require_role(SHIP_ADMINS);
    $id = (int)($_GET['id'] ?? 0);
    $b = json_input();
    $st = $pdo->prepare("SELECT * FROM shipping_couriers WHERE id = ?");
    $st->execute([$id]);
    $c = $st->fetch();
    if (!$c) fail('not_found', 404);
    $pdo->prepare("UPDATE shipping_couriers SET name=?, description=?, enabled=?, sort_order=? WHERE id=?")->execute([
        str_in($b['name'] ?? $c['name'], 80), str_in($b['description'] ?? $c['description'], 255),
        isset($b['enabled']) ? (int)!!$b['enabled'] : $c['enabled'], (int)($b['sort_order'] ?? $c['sort_order']), $id,
    ]);
    log_activity($u['email'], 'update_courier', $c['code']);
    ok([]);
}

if ($action === 'service') {
    $u = require_role(SHIP_ADMINS);
    if (method() === 'DELETE') {
        $pdo->prepare("DELETE FROM shipping_services WHERE id = ?")->execute([(int)($_GET['id'] ?? 0)]);
        ok([]);
    }
    if (in_array(method(), ['POST', 'PUT'], true)) {
        $b = json_input();
        $vals = [];
        foreach ($serviceFields as $f => $t) {
            $v = $b[$f] ?? null;
            if ($t === 'i') $v = max(0, (int)$v);
            else $v = str_in($v ?? '', $f === 'description' ? 255 : 80);
            $vals[$f] = $v;
        }
        if (!in_array($vals['type'], ['regular', 'express', 'instant', 'cargo'], true)) $vals['type'] = 'regular';
        if ($vals['etd_unit'] === '') $vals['etd_unit'] = 'hari';
        if ($vals['name'] === '') fail('name_required');
        if (method() === 'POST') {
            $courier = str_in($b['courier_code'] ?? '', 40);
            $code = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', (string)($b['code'] ?? '')));
            $st = $pdo->prepare("SELECT COUNT(*) FROM shipping_couriers WHERE code = ?");
            $st->execute([$courier]);
            if (!(int)$st->fetchColumn() || $code === '') fail('invalid_courier_or_code');
            $st = $pdo->prepare("SELECT COUNT(*) FROM shipping_services WHERE courier_code = ? AND code = ?");
            $st->execute([$courier, $code]);
            if ((int)$st->fetchColumn()) fail('service_exists');
            $cols = array_keys($serviceFields);
            $pdo->prepare("INSERT INTO shipping_services (courier_code, code, " . implode(',', $cols) . ") VALUES (?,?," . implode(',', array_fill(0, count($cols), '?')) . ")")
                ->execute(array_merge([$courier, $code], array_values($vals)));
            log_activity($u['email'], 'create_service', "$courier/$code");
            ok(['id' => (int)$pdo->lastInsertId()]);
        }
        $id = (int)($_GET['id'] ?? 0);
        $set = implode(',', array_map(fn($f) => "$f = ?", array_keys($serviceFields)));
        $pdo->prepare("UPDATE shipping_services SET $set WHERE id = ?")->execute(array_merge(array_values($vals), [$id]));
        log_activity($u['email'], 'update_service', "#$id");
        ok([]);
    }
}

fail('unknown_action', 400);
