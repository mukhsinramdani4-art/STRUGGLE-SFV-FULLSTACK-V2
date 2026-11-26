<?php
/** /api/assets.php — Modul ERP: Aset perusahaan (peralatan, kendaraan, dsb) */
require_once __DIR__ . '/bootstrap.php';
$pdo = db();

switch (method()) {
    case 'GET': {
        require_admin();
        $rows = $pdo->query("SELECT * FROM assets ORDER BY id DESC")->fetchAll();
        $summary = [
            'total' => count($rows),
            'total_value' => array_sum(array_column($rows, 'current_value')),
            'perlu_servis' => count(array_filter($rows, fn($r) => $r['condition_status'] !== 'Baik')),
        ];
        ok(['assets' => $rows, 'summary' => $summary]);
    }
    case 'POST': {
        require_role(['Super Admin', 'Owner', 'Manager']);
        $b = json_input();
        if (empty($b['name'])) fail('name_required');
        $code = $b['asset_code'] ?? ('AST-' . str_pad((string)((int)$pdo->query("SELECT COALESCE(MAX(id),0) FROM assets")->fetchColumn() + 1), 4, '0', STR_PAD_LEFT));
        $stmt = $pdo->prepare("INSERT INTO assets (asset_code, name, category, location, assigned_to, purchase_date, purchase_value, current_value, condition_status, status, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $code, $b['name'], $b['category'] ?? 'Peralatan', $b['location'] ?? '', $b['assigned_to'] ?? '',
            $b['purchase_date'] ?? date('Y-m-d'), (int)($b['purchase_value'] ?? 0), (int)($b['current_value'] ?? $b['purchase_value'] ?? 0),
            $b['condition_status'] ?? 'Baik', $b['status'] ?? 'Aktif', $b['notes'] ?? '',
        ]);
        log_activity($_SESSION['admin']['email'], 'create_asset', $b['name']);
        ok(['id' => $pdo->lastInsertId(), 'asset_code' => $code]);
    }
    case 'PUT': {
        require_role(['Super Admin', 'Owner', 'Manager']);
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) fail('id_required');
        $b = json_input();
        $stmt = $pdo->prepare("SELECT * FROM assets WHERE id = ?");
        $stmt->execute([$id]);
        $ex = $stmt->fetch();
        if (!$ex) fail('not_found', 404);
        $merge = fn($k, $def = null) => array_key_exists($k, $b) ? $b[$k] : $ex[$k] ?? $def;
        $pdo->prepare("UPDATE assets SET name=?, category=?, location=?, assigned_to=?, purchase_date=?, purchase_value=?, current_value=?, condition_status=?, status=?, notes=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([
                $merge('name'), $merge('category'), $merge('location'), $merge('assigned_to'),
                $merge('purchase_date'), (int)$merge('purchase_value', 0), (int)$merge('current_value', 0),
                $merge('condition_status'), $merge('status'), $merge('notes'), $id,
            ]);
        log_activity($_SESSION['admin']['email'], 'update_asset', "#$id");
        ok([]);
    }
    case 'DELETE': {
        require_role(['Super Admin', 'Owner', 'Manager']);
        $id = (int)($_GET['id'] ?? 0);
        $pdo->prepare("DELETE FROM assets WHERE id = ?")->execute([$id]);
        ok(['deleted' => $id]);
    }
    default: fail('method_not_allowed', 405);
}
