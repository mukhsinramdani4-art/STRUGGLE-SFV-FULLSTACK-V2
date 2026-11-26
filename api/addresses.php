<?php
/**
 * /api/addresses.php — Buku alamat pelanggan (hanya milik pelanggan yang login).
 * GET                    daftar alamat
 * POST                   tambah alamat
 * PUT    ?id=            ubah alamat
 * POST   ?action=default&id=   jadikan alamat utama
 * DELETE ?id=            hapus alamat
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/regions.php';
$pdo = db();
$c = require_customer();
$cid = (int)$c['id'];

function address_row_out(array $r): array {
    return [
        'id' => (int)$r['id'], 'label' => $r['label'], 'recipient' => $r['recipient'], 'phone' => $r['phone'],
        'address' => $r['address'], 'province' => $r['province'], 'city' => $r['city'], 'district' => $r['district'],
        'village' => $r['village'], 'postal' => $r['postal_code'], 'notes' => $r['notes'],
        'lat' => $r['lat'] !== null ? (float)$r['lat'] : null, 'lng' => $r['lng'] !== null ? (float)$r['lng'] : null,
        'placeId' => $r['place_id'], 'formattedAddress' => $r['formatted_address'], 'isDefault' => (bool)$r['is_default'],
    ];
}

function list_addresses(PDO $pdo, int $cid): array {
    $st = $pdo->prepare("SELECT * FROM customer_addresses WHERE customer_id = ? ORDER BY is_default DESC, id ASC");
    $st->execute([$cid]);
    return array_map('address_row_out', $st->fetchAll());
}

$cols = ['label','recipient','phone','address','province','city','district','village','postal_code','notes','lat','lng','place_id','formatted_address'];

switch (method()) {
    case 'GET':
        ok(['addresses' => list_addresses($pdo, $cid)]);

    case 'POST': {
        if (($_GET['action'] ?? '') === 'default') {
            $id = (int)($_GET['id'] ?? 0);
            $st = $pdo->prepare("SELECT id FROM customer_addresses WHERE id = ? AND customer_id = ?");
            $st->execute([$id, $cid]);
            if (!$st->fetch()) fail('not_found', 404);
            $pdo->prepare("UPDATE customer_addresses SET is_default = 0 WHERE customer_id = ?")->execute([$cid]);
            $pdo->prepare("UPDATE customer_addresses SET is_default = 1 WHERE id = ?")->execute([$id]);
            ok(['addresses' => list_addresses($pdo, $cid)]);
        }
        $a = address_from_input(json_input());
        $st = $pdo->prepare("SELECT COUNT(*) FROM customer_addresses WHERE customer_id = ?");
        $st->execute([$cid]);
        $count = (int)$st->fetchColumn();
        if ($count >= 10) fail('address_limit_reached');
        $pdo->prepare("INSERT INTO customer_addresses (customer_id, " . implode(',', $cols) . ", is_default) VALUES (?," . implode(',', array_fill(0, count($cols), '?')) . ",?)")
            ->execute(array_merge([$cid], array_map(fn($k) => $a[$k], $cols), [$count === 0 ? 1 : 0]));
        ok(['id' => (int)$pdo->lastInsertId(), 'addresses' => list_addresses($pdo, $cid)]);
    }

    case 'PUT': {
        $id = (int)($_GET['id'] ?? 0);
        $st = $pdo->prepare("SELECT id FROM customer_addresses WHERE id = ? AND customer_id = ?");
        $st->execute([$id, $cid]);
        if (!$st->fetch()) fail('not_found', 404);
        $a = address_from_input(json_input());
        $set = implode(',', array_map(fn($k) => "$k = ?", $cols));
        $pdo->prepare("UPDATE customer_addresses SET $set, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND customer_id = ?")
            ->execute(array_merge(array_map(fn($k) => $a[$k], $cols), [$id, $cid]));
        ok(['addresses' => list_addresses($pdo, $cid)]);
    }

    case 'DELETE': {
        $id = (int)($_GET['id'] ?? 0);
        $pdo->prepare("DELETE FROM customer_addresses WHERE id = ? AND customer_id = ?")->execute([$id, $cid]);
        $st = $pdo->prepare("SELECT COUNT(*) FROM customer_addresses WHERE customer_id = ? AND is_default = 1");
        $st->execute([$cid]);
        if ((int)$st->fetchColumn() === 0) {
            $st = $pdo->prepare("SELECT id FROM customer_addresses WHERE customer_id = ? ORDER BY id ASC LIMIT 1");
            $st->execute([$cid]);
            if ($first = $st->fetch()) $pdo->prepare("UPDATE customer_addresses SET is_default = 1 WHERE id = ?")->execute([$first['id']]);
        }
        ok(['addresses' => list_addresses($pdo, $cid)]);
    }

    default: fail('method_not_allowed', 405);
}
