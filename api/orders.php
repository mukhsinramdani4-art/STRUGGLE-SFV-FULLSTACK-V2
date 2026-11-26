<?php
/**
 * /api/orders.php
 *
 * Pelanggan (wajib login, hanya melihat pesanan MILIKNYA sendiri):
 *   GET                       daftar pesanan sendiri
 *   GET  ?id=                 detail pesanan sendiri
 *   POST                      buat pesanan (harga, ongkir, diskon dihitung ulang di server)
 *   POST ?action=coupon_check {code, subtotal, shippingFee}
 *   POST ?action=cancel&id=   batalkan pesanan (hanya jika belum dibayar & masih Pending)
 * Admin:
 *   GET  [?q=&status=&customer_id=]   semua pesanan
 *   GET  ?id=                          detail lengkap
 *   PUT  ?id=   {status?, paymentStatus?, shippingStatus?, trackingNumber?, adminNotes?, note?, rejectReason?}
 */
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/orders_lib.php';
require_once __DIR__ . '/shipping_lib.php';
$pdo = db();
$action = $_GET['action'] ?? '';
$isAdmin = !empty($_SESSION['admin']);

const ORDER_STAFF = ['Super Admin', 'Owner', 'Manager', 'Kasir', 'Gudang'];
const PAYMENT_STAFF = ['Super Admin', 'Owner', 'Manager', 'Kasir'];

/* ------------------------------------------------------------------ GET */
if (method() === 'GET') {
    $id = $_GET['id'] ?? '';
    if ($id !== '') {
        if ($isAdmin) {
            require_role(ORDER_STAFF);
            $o = order_full($pdo, (string)$id, true);
            if (!$o) fail('not_found', 404);
            $st = $pdo->prepare("SELECT id, name, email, username FROM customers WHERE id = ?");
            $st->execute([(int)$o['customer_id']]);
            $o['customer'] = $st->fetch() ?: null;
            ok(['order' => $o]);
        }
        $c = require_customer();
        $o = order_full($pdo, (string)$id, false);
        if (!$o || (int)$o['customer_id'] !== (int)$c['id']) fail('not_found', 404); // sama untuk "bukan milik Anda" agar ID tidak bisa ditebak
        ok(['order' => $o]);
    }

    if ($isAdmin) {
        require_role(ORDER_STAFF);
        $where = []; $params = [];
        if (!empty($_GET['status'])) { $where[] = 'o.status = ?'; $params[] = $_GET['status']; }
        if (!empty($_GET['customer_id'])) { $where[] = 'o.customer_id = ?'; $params[] = (int)$_GET['customer_id']; }
        if (!empty($_GET['q'])) {
            $where[] = '(o.id LIKE ? OR o.customer_name LIKE ? OR o.recipient_name LIKE ? OR o.tracking_number LIKE ?)';
            $like = '%' . $_GET['q'] . '%';
            array_push($params, $like, $like, $like, $like);
        }
        $limit = max(1, min(1000, (int)($_GET['limit'] ?? 300)));
        $sql = "SELECT o.*, c.username AS customer_username FROM orders o LEFT JOIN customers c ON c.id = o.customer_id"
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY o.created_at DESC LIMIT $limit";
        $st = $pdo->prepare($sql);
        $st->execute($params);
        ok(['orders' => $st->fetchAll()]);
    }

    $c = require_customer();
    $st = $pdo->prepare("SELECT id, total, subtotal, discount, shipping_fee, status, payment_status, shipping_status, payment_method, payment_method_label,
        courier_name, service_name, tracking_number, created_at FROM orders WHERE customer_id = ? ORDER BY created_at DESC");
    $st->execute([$c['id']]);
    $orders = $st->fetchAll();
    foreach ($orders as &$o) {
        $it = $pdo->prepare("SELECT product_name, qty, image FROM order_items WHERE order_id = ?");
        $it->execute([$o['id']]);
        $o['items'] = $it->fetchAll();
    }
    ok(['orders' => $orders]);
}

/* ------------------------------------------------- POST: cek kupon / batal / buat */
if (method() === 'POST' && $action === 'coupon_check') {
    $c = require_customer();
    $b = json_input();
    $r = coupon_evaluate($pdo, $b['code'] ?? '', (int)($b['subtotal'] ?? 0), (int)($b['shippingFee'] ?? 0), (int)$c['id']);
    if ($r['error']) fail($r['error'], 422);
    ok(['discount' => $r['discount'], 'description' => $r['coupon']['description'] ?? '']);
}

if (method() === 'POST' && $action === 'cancel') {
    $c = require_customer();
    $id = (string)($_GET['id'] ?? '');
    $st = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND customer_id = ?");
    $st->execute([$id, $c['id']]);
    $o = $st->fetch();
    if (!$o) fail('not_found', 404);
    if ($o['status'] !== 'Pending' || $o['payment_status'] === 'Dibayar') fail('cannot_cancel', 409);
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE orders SET status = 'Dibatalkan' WHERE id = ?")->execute([$id]);
        restore_stock($pdo, $id);
        $pdo->prepare("UPDATE payments SET status = 'Gagal', reject_reason = 'Pesanan dibatalkan pelanggan', updated_at = CURRENT_TIMESTAMP WHERE order_id = ? AND status != 'Dibayar'")->execute([$id]);
        $pdo->prepare("UPDATE orders SET payment_status = 'Gagal' WHERE id = ? AND payment_status != 'Dibayar'")->execute([$id]);
        order_event($id, 'order', $o['status'], 'Dibatalkan', 'Dibatalkan oleh pelanggan', $c['email']);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    ok(['order' => order_full($pdo, $id, false)]);
}

if (method() === 'POST') {
    $c = require_customer();
    $b = json_input();
    $rawItems = is_array($b['items'] ?? null) ? $b['items'] : [];
    if (!$rawItems || count($rawItems) > 50) fail('items_required');

    // 1) Alamat tujuan (divalidasi sama seperti buku alamat)
    $addr = address_from_input(is_array($b['address'] ?? null) ? $b['address'] : []);
    $contactEmail = strtolower(str_in($b['customerEmail'] ?? $c['email'], 191));
    if (!filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) fail('invalid_email');

    // 2) Item: harga, varian & stok selalu dibaca dari database — nilai dari browser diabaikan.
    $lines = []; $subtotal = 0; $qtyPerProduct = [];
    foreach ($rawItems as $it) {
        $pid = (int)($it['id'] ?? 0);
        $qty = (int)($it['qty'] ?? 0);
        if ($pid < 1 || $qty < 1 || $qty > 99) fail('invalid_item');
        $st = $pdo->prepare("SELECT * FROM products WHERE id = ? AND status = 'active'");
        $st->execute([$pid]);
        $p = $st->fetch();
        if (!$p) fail('product_unavailable', 422);
        $sizes = json_decode($p['sizes'] ?? '[]', true) ?: [];
        $colors = array_map(fn($x) => $x['name'] ?? '', json_decode($p['colors'] ?? '[]', true) ?: []);
        $sleeves = json_decode($p['sleeves'] ?? '[]', true) ?: [];
        $sleevePrices = json_decode($p['sleeve_prices'] ?? '{}', true) ?: [];
        $size = str_in($it['size'] ?? '', 20);
        $color = str_in($it['color'] ?? '', 40);
        $sleeve = str_in($it['sleeve'] ?? '', 20);
        if ($sizes && !in_array($size, $sizes, true)) fail('invalid_variant', 422);
        if ($colors && !in_array($color, $colors, true)) fail('invalid_variant', 422);
        if ($sleeves && !in_array($sleeve, $sleeves, true)) $sleeve = $sleeves[0];
        if (!$sleeves) $sleeve = '';
        $price = (int)$p['price'];
        if ($sleeve !== '' && isset($sleevePrices[$sleeve])) $price = (int)$sleevePrices[$sleeve];
        $qtyPerProduct[$pid] = ($qtyPerProduct[$pid] ?? 0) + $qty;
        if ($qtyPerProduct[$pid] > (int)$p['stock']) fail('out_of_stock', 422);
        $imgs = json_decode($p['images'] ?? '[]', true) ?: [];
        $lines[] = ['id' => $pid, 'name' => $p['name'], 'color' => $color, 'size' => $size, 'sleeve' => $sleeve, 'qty' => $qty,
            'price' => $price, 'image' => $imgs[0] ?? '', 'weight' => max(100, (int)$p['weight_gram'])];
        $subtotal += $price * $qty;
    }
    $weight = array_sum(array_map(fn($l) => $l['weight'] * $l['qty'], $lines));

    // 3) Ongkir dihitung ulang di server untuk ekspedisi/layanan terpilih
    $courier = str_in($b['courier'] ?? '', 40);
    $service = str_in($b['service'] ?? '', 40);
    $quote = shipping_quote($pdo, $addr['province'], $addr['city'], $addr['lat'], $addr['lng'], $weight);
    $chosen = null;
    foreach ($quote['options'] as $opt) if ($opt['courier'] === $courier && $opt['service'] === $service) { $chosen = $opt; break; }
    if (!$chosen) fail('shipping_required', 422);
    if (!$chosen['available']) fail('shipping_unavailable', 422);
    $shippingFee = (int)$chosen['fee'];

    // 4) Kupon
    $cp = coupon_evaluate($pdo, $b['couponCode'] ?? '', $subtotal, $shippingFee, (int)$c['id']);
    if ($cp['error']) fail($cp['error'], 422);
    $discount = $cp['discount'];
    $total = $subtotal + $shippingFee - $discount;

    // 5) Metode pembayaran harus aktif
    $st = $pdo->prepare("SELECT * FROM payment_methods WHERE code = ? AND enabled = 1");
    $st->execute([str_in($b['paymentMethod'] ?? '', 30)]);
    $pm = $st->fetch();
    if (!$pm) fail('payment_method_invalid', 422);

    // 6) Simpan semuanya dalam satu transaksi
    $pdo->beginTransaction();
    try {
        do {
            $id = 'SFV-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5));
            $st = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE id = ?");
            $st->execute([$id]);
        } while ((int)$st->fetchColumn() > 0);

        $pdo->prepare("INSERT INTO orders
            (id, customer_id, customer_name, customer_email, customer_phone, recipient_name, shipping_address, shipping_city, shipping_province, shipping_postal,
             shipping_district, shipping_village, shipping_notes, shipping_lat, shipping_lng, shipping_formatted_address,
             payment_method, payment_method_label, coupon_code, subtotal, discount, shipping_fee, total,
             status, payment_status, shipping_status, courier_code, courier_name, service_code, service_name, shipping_etd, shipping_is_estimate, weight_gram, source)
            VALUES (?,?,?,?,?,?,?,?,?,?, ?,?,?,?,?,?, ?,?,?,?,?,?,?, 'Pending','Belum Dibayar','Menunggu Pengiriman', ?,?,?,?,?,1,?, 'storefront')")
            ->execute([
                $id, $c['id'], $c['name'], $contactEmail, $addr['phone'], $addr['recipient'], $addr['address'], $addr['city'], $addr['province'], $addr['postal_code'],
                $addr['district'], $addr['village'], $addr['notes'], $addr['lat'], $addr['lng'], $addr['formatted_address'],
                $pm['code'], $pm['name'], $cp['coupon']['code'] ?? null, $subtotal, $discount, $shippingFee, $total,
                $chosen['courier'], $chosen['courierName'], $chosen['service'], $chosen['serviceName'], $chosen['etd'], $weight,
            ]);

        $itemStmt = $pdo->prepare("INSERT INTO order_items (order_id, product_id, product_name, color, size, sleeve, image, qty, price) VALUES (?,?,?,?,?,?,?,?,?)");
        foreach ($lines as $l) $itemStmt->execute([$id, $l['id'], $l['name'], $l['color'], $l['size'], $l['sleeve'], $l['image'], $l['qty'], $l['price']]);

        foreach ($qtyPerProduct as $pid => $q) { // pengurangan stok atomik
            $st = $pdo->prepare("UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?");
            $st->execute([$q, $pid, $q]);
            if ($st->rowCount() < 1) throw new RuntimeException('out_of_stock');
        }
        if ($cp['coupon']) $pdo->prepare("UPDATE coupons SET used_count = used_count + 1 WHERE id = ?")->execute([$cp['coupon']['id']]);

        $pdo->prepare("INSERT INTO payments (order_id, method_code, method_group, amount, status, provider) VALUES (?,?,?,?, 'Belum Dibayar', 'manual')")
            ->execute([$id, $pm['code'], $pm['grp'], $total]);

        // Simpan ke buku alamat bila diminta
        if (!empty($b['saveAddress'])) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM customer_addresses WHERE customer_id = ?");
            $st->execute([$c['id']]);
            $n = (int)$st->fetchColumn();
            if ($n < 10) {
                $cols = ['label','recipient','phone','address','province','city','district','village','postal_code','notes','lat','lng','place_id','formatted_address'];
                $pdo->prepare("INSERT INTO customer_addresses (customer_id, " . implode(',', $cols) . ", is_default) VALUES (?," . implode(',', array_fill(0, count($cols), '?')) . ",?)")
                    ->execute(array_merge([$c['id']], array_map(fn($k) => $addr[$k], $cols), [$n === 0 ? 1 : 0]));
            }
        }
        // Isi profil bila telepon masih kosong
        if (empty($c['phone'])) $pdo->prepare("UPDATE customers SET phone = ? WHERE id = ?")->execute([$addr['phone'], $c['id']]);

        order_event($id, 'order', null, 'Pending', 'Pesanan dibuat', $c['email']);
        order_event($id, 'payment', null, 'Belum Dibayar', 'Menunggu pembayaran via ' . $pm['name'], $c['email']);
        order_event($id, 'shipping', null, 'Menunggu Pengiriman', $chosen['courierName'] . ' ' . $chosen['serviceName'] . ' (estimasi ' . $chosen['etd'] . ')', $c['email']);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        if ($e->getMessage() === 'out_of_stock') fail('out_of_stock', 422);
        throw $e;
    }
    log_activity($c['email'], 'create_order', $id);
    ok(['order' => order_full($pdo, $id, false)]);
}

/* ---------------------------------------------------------- PUT: admin update */
if (method() === 'PUT') {
    $u = require_role(ORDER_STAFF);
    $id = (string)($_GET['id'] ?? '');
    $st = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
    $st->execute([$id]);
    $o = $st->fetch();
    if (!$o) fail('not_found', 404);
    $b = json_input();
    $actor = $u['email'];
    $note = str_in($b['note'] ?? '', 300);

    $newStatus = $b['status'] ?? null;
    $newPay = $b['paymentStatus'] ?? null;
    $newShip = $b['shippingStatus'] ?? null;
    if ($newStatus !== null && !in_array($newStatus, ORDER_STATUSES, true)) fail('invalid_status');
    if ($newPay !== null && !in_array($newPay, PAYMENT_STATUSES, true)) fail('invalid_payment_status');
    if ($newShip !== null && !in_array($newShip, SHIPPING_STATUSES, true)) fail('invalid_shipping_status');
    if ($newPay !== null && !admin_role_allowed($u, PAYMENT_STAFF)) fail('forbidden', 403);
    if ($o['status'] === 'Dibatalkan' && ($newStatus !== null && $newStatus !== 'Dibatalkan' || $newShip !== null && $newShip !== $o['shipping_status'])) fail('order_cancelled', 409);

    $payAfter = $newPay ?? $o['payment_status'];
    $paid = $payAfter === 'Dibayar' || $o['payment_method'] === 'cod';
    $tracking = array_key_exists('trackingNumber', $b) ? str_in($b['trackingNumber'], 60) : (string)$o['tracking_number'];

    // Aturan konsistensi agar status tidak saling bertentangan
    if (in_array($newStatus, ['Dikirim', 'Selesai'], true) && !$paid) fail('payment_not_verified', 409);
    if (in_array($newShip, ['Dalam Perjalanan', 'Diterima'], true) && !$paid) fail('payment_not_verified', 409);
    if ($newShip === 'Dalam Perjalanan') {
        $st = $pdo->prepare("SELECT type FROM shipping_services WHERE courier_code = ? AND code = ?");
        $st->execute([$o['courier_code'], $o['service_code']]);
        $type = $st->fetchColumn();
        if ($type !== 'instant' && $tracking === '') fail('tracking_required', 409);
    }

    $pdo->beginTransaction();
    try {
        $set = []; $params = [];
        if ($newStatus !== null && $newStatus !== $o['status']) {
            $set[] = 'status = ?'; $params[] = $newStatus;
            order_event($id, 'order', $o['status'], $newStatus, $note, $actor);
            if ($newStatus === 'Dibatalkan') {
                restore_stock($pdo, $id);
                if ($o['payment_status'] !== 'Dibayar' && $newPay === null) {
                    $set[] = "payment_status = 'Gagal'";
                    $pdo->prepare("UPDATE payments SET status = 'Gagal', updated_at = CURRENT_TIMESTAMP WHERE order_id = ? AND status != 'Dibayar'")->execute([$id]);
                    order_event($id, 'payment', $o['payment_status'], 'Gagal', 'Pesanan dibatalkan', $actor);
                }
            }
        }
        if ($newPay !== null && $newPay !== $o['payment_status']) {
            $set[] = 'payment_status = ?'; $params[] = $newPay;
            if ($newPay === 'Dibayar') { $set[] = 'paid_at = ?'; $params[] = date('Y-m-d H:i:s'); }
            $pdo->prepare("UPDATE payments SET status = ?, verified_by = ?, verified_at = ?, reject_reason = ?, updated_at = CURRENT_TIMESTAMP WHERE order_id = ?")
                ->execute([$newPay, $actor, date('Y-m-d H:i:s'), $newPay === 'Gagal' ? str_in($b['rejectReason'] ?? $note, 300) : null, $id]);
            order_event($id, 'payment', $o['payment_status'], $newPay, $note ?: ($newPay === 'Gagal' ? str_in($b['rejectReason'] ?? '', 300) : ''), $actor);
        }
        if ($newShip !== null && $newShip !== $o['shipping_status']) {
            $set[] = 'shipping_status = ?'; $params[] = $newShip;
            if ($newShip === 'Dalam Perjalanan') { $set[] = 'shipped_at = ?'; $params[] = date('Y-m-d H:i:s'); }
            if ($newShip === 'Diterima') { $set[] = 'delivered_at = ?'; $params[] = date('Y-m-d H:i:s'); }
            order_event($id, 'shipping', $o['shipping_status'], $newShip, $note, $actor);
        }
        if ($tracking !== (string)$o['tracking_number']) {
            $set[] = 'tracking_number = ?'; $params[] = $tracking;
            order_event($id, 'shipping', $o['shipping_status'], $newShip ?? $o['shipping_status'], 'Nomor resi: ' . ($tracking ?: '(dihapus)'), $actor);
        }
        if (array_key_exists('adminNotes', $b)) { $set[] = 'admin_notes = ?'; $params[] = str_in($b['adminNotes'], 1000); }
        if (!$set) { $pdo->rollBack(); fail('nothing_to_update'); }
        $params[] = $id;
        $pdo->prepare("UPDATE orders SET " . implode(', ', $set) . " WHERE id = ?")->execute($params);
        sync_finance($pdo, $id, $actor);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    log_activity($actor, 'update_order', "$id -> " . json_encode($b, JSON_UNESCAPED_UNICODE));
    ok(['order' => order_full($pdo, $id, true)]);
}

fail('method_not_allowed', 405);
