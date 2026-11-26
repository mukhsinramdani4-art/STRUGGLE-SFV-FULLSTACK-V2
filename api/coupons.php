<?php
/** /api/coupons.php — Kupon diskon */
require_once __DIR__ . '/bootstrap.php';
$pdo = db();

switch (method()) {
    case 'GET': {
        if (!empty($_GET['public'])) {
            $rows = $pdo->query("SELECT code, description, discount_type AS discountType, discount_value AS discountValue, max_discount AS maxDiscount, min_purchase AS minPurchase, valid_until AS validUntil, usage_limit AS usageLimit
                FROM coupons WHERE status = 'active' AND (valid_until IS NULL OR valid_until = '' OR valid_until >= '" . date('Y-m-d') . "') ORDER BY id ASC")->fetchAll();
            ok(['coupons' => $rows]);
        }
        if (!empty($_GET['code'])) {
            $stmt = $pdo->prepare("SELECT * FROM coupons WHERE code = ? AND status = 'active'");
            $stmt->execute([strtoupper(trim($_GET['code']))]);
            $c = $stmt->fetch();
            if (!$c) fail('not_found', 404);
            ok(['coupon' => $c]);
        }
        require_admin();
        $rows = $pdo->query("SELECT * FROM coupons ORDER BY id DESC")->fetchAll();
        ok(['coupons' => $rows]);
    }
    case 'POST': {
        require_role(['Super Admin', 'Owner', 'Manager', 'Marketing']);
        $b = json_input();
        if (empty($b['code'])) fail('code_required');
        $stmt = $pdo->prepare("INSERT INTO coupons (code, description, discount_type, discount_value, max_discount, min_purchase, valid_until, usage_limit, status) VALUES (?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            strtoupper(trim($b['code'])), $b['description'] ?? '', $b['discount_type'] ?? 'percent',
            (int)($b['discount_value'] ?? 0), (int)($b['max_discount'] ?? 0), (int)($b['min_purchase'] ?? 0),
            $b['valid_until'] ?? null, (int)($b['usage_limit'] ?? 0), $b['status'] ?? 'active',
        ]);
        log_activity($_SESSION['admin']['email'], 'create_coupon', $b['code']);
        ok(['id' => $pdo->lastInsertId()]);
    }
    case 'PUT': {
        require_role(['Super Admin', 'Owner', 'Manager', 'Marketing']);
        $id = (int)($_GET['id'] ?? 0);
        $b = json_input();
        $pdo->prepare("UPDATE coupons SET status = ? WHERE id = ?")->execute([$b['status'] ?? 'active', $id]);
        ok([]);
    }
    case 'DELETE': {
        require_role(['Super Admin', 'Owner', 'Manager', 'Marketing']);
        $id = (int)($_GET['id'] ?? 0);
        $pdo->prepare("DELETE FROM coupons WHERE id = ?")->execute([$id]);
        ok(['deleted' => $id]);
    }
    default: fail('method_not_allowed', 405);
}
