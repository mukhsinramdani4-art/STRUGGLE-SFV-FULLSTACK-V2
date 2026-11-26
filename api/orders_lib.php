<?php
/** Pustaka pesanan: konstanta status, kupon, sinkronisasi keuangan, stok. */

const ORDER_STATUSES = ['Pending', 'Diproses', 'Dikemas', 'Dikirim', 'Selesai', 'Dibatalkan'];
const PAYMENT_STATUSES = ['Belum Dibayar', 'Menunggu Verifikasi', 'Dibayar', 'Gagal', 'Dikembalikan'];
const SHIPPING_STATUSES = ['Menunggu Pengiriman', 'Diproses Kurir', 'Dalam Perjalanan', 'Diterima'];

/**
 * Hitung diskon kupon di sisi server.
 * @return array{discount:int, error:?string, coupon:?array}
 */
function coupon_evaluate(PDO $pdo, ?string $code, int $subtotal, int $shippingFee, int $customerId): array {
    $code = strtoupper(trim((string)$code));
    if ($code === '') return ['discount' => 0, 'error' => null, 'coupon' => null];
    $st = $pdo->prepare("SELECT * FROM coupons WHERE code = ? AND status = 'active'");
    $st->execute([$code]);
    $c = $st->fetch();
    if (!$c) return ['discount' => 0, 'error' => 'coupon_invalid', 'coupon' => null];
    if (!empty($c['valid_until']) && $c['valid_until'] < date('Y-m-d')) return ['discount' => 0, 'error' => 'coupon_expired', 'coupon' => $c];
    if ($subtotal < (int)$c['min_purchase']) return ['discount' => 0, 'error' => 'coupon_min_purchase', 'coupon' => $c];
    if ((int)$c['usage_limit'] > 0) { // batas pemakaian PER PELANGGAN
        $st = $pdo->prepare("SELECT COUNT(*) FROM orders WHERE customer_id = ? AND coupon_code = ? AND status != 'Dibatalkan'");
        $st->execute([$customerId, $code]);
        if ((int)$st->fetchColumn() >= (int)$c['usage_limit']) return ['discount' => 0, 'error' => 'coupon_limit', 'coupon' => $c];
    }
    $max = (int)$c['max_discount'];
    switch ($c['discount_type']) {
        case 'percent': $d = (int)floor($subtotal * (int)$c['discount_value'] / 100); if ($max > 0) $d = min($d, $max); break;
        case 'fixed':   $d = (int)$c['discount_value']; break;
        case 'shipping': $d = $shippingFee; if ($max > 0) $d = min($d, $max); break;
        default: $d = 0;
    }
    $d = max(0, min($d, $subtotal + $shippingFee));
    return ['discount' => $d, 'error' => null, 'coupon' => $c];
}

/** Catat pemasukan/refund di modul Keuangan sesuai status pembayaran. Idempotent. */
function sync_finance(PDO $pdo, string $orderId, string $actor): void {
    $st = $pdo->prepare("SELECT total, payment_status FROM orders WHERE id = ?");
    $st->execute([$orderId]);
    $o = $st->fetch();
    if (!$o) return;
    $has = function (string $type, string $cat) use ($pdo, $orderId): bool {
        $q = $pdo->prepare("SELECT COUNT(*) FROM finance_transactions WHERE related_order_id = ? AND type = ? AND category = ?");
        $q->execute([$orderId, $type, $cat]);
        return (int)$q->fetchColumn() > 0;
    };
    if ($o['payment_status'] === 'Dibayar' && !$has('income', 'Penjualan Online')) {
        $pdo->prepare("INSERT INTO finance_transactions (type, category, amount, description, trx_date, related_order_id, created_by) VALUES ('income','Penjualan Online',?,?,?,?,?)")
            ->execute([(int)$o['total'], "Pembayaran pesanan $orderId terverifikasi", date('Y-m-d H:i:s'), $orderId, $actor]);
    }
    if ($o['payment_status'] === 'Dikembalikan' && $has('income', 'Penjualan Online') && !$has('expense', 'Refund Pesanan')) {
        $pdo->prepare("INSERT INTO finance_transactions (type, category, amount, description, trx_date, related_order_id, created_by) VALUES ('expense','Refund Pesanan',?,?,?,?,?)")
            ->execute([(int)$o['total'], "Refund pesanan $orderId", date('Y-m-d H:i:s'), $orderId, $actor]);
    }
}

/** Kembalikan stok produk dari pesanan yang dibatalkan (sekali saja). */
function restore_stock(PDO $pdo, string $orderId): void {
    $st = $pdo->prepare("SELECT stock_restored FROM orders WHERE id = ?");
    $st->execute([$orderId]);
    if ((int)$st->fetchColumn() === 1) return;
    $items = $pdo->prepare("SELECT product_id, qty FROM order_items WHERE order_id = ?");
    $items->execute([$orderId]);
    foreach ($items->fetchAll() as $it) {
        if ($it['product_id']) $pdo->prepare("UPDATE products SET stock = stock + ? WHERE id = ?")->execute([(int)$it['qty'], (int)$it['product_id']]);
    }
    $pdo->prepare("UPDATE orders SET stock_restored = 1 WHERE id = ?")->execute([$orderId]);
}

/** Susun detail pesanan lengkap (item, pembayaran, riwayat). $forAdmin menambah data internal. */
function order_full(PDO $pdo, string $id, bool $forAdmin): ?array {
    $st = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
    $st->execute([$id]);
    $o = $st->fetch();
    if (!$o) return null;
    if (!$forAdmin) unset($o['admin_notes']);
    $st = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ? ORDER BY id ASC");
    $st->execute([$id]);
    $o['items'] = $st->fetchAll();
    $st = $pdo->prepare("SELECT id, method_code, method_group, amount, status, provider, gateway_ref, proof_uploaded_at, payer_note, reject_reason, verified_by, verified_at, created_at,
        CASE WHEN proof_path IS NULL OR proof_path = '' THEN 0 ELSE 1 END AS has_proof FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1");
    $st->execute([$id]);
    $o['payment'] = $st->fetch() ?: null;
    $st = $pdo->prepare("SELECT kind, from_status, to_status, note, actor, created_at FROM order_events WHERE order_id = ? ORDER BY id ASC");
    $st->execute([$id]);
    $events = $st->fetchAll();
    if (!$forAdmin) foreach ($events as &$e) { $e['actor'] = ($e['actor'] === '' || strpos((string)$e['actor'], '@') === false) ? $e['actor'] : 'Admin'; }
    unset($e);
    $o['events'] = $events;
    $st = $pdo->prepare("SELECT code, grp, name, account_name, account_number, instructions, qr_image, is_sample FROM payment_methods WHERE code = ?");
    $st->execute([$o['payment_method']]);
    $o['payment_info'] = $st->fetch() ?: null;
    $hours = (int)setting('payment_due_hours', '24');
    $o['payment_due_at'] = date('Y-m-d H:i:s', strtotime($o['created_at']) + $hours * 3600);
    $o['is_simulation'] = true; // ongkir = estimasi/simulasi; pembayaran = verifikasi manual
    return $o;
}
