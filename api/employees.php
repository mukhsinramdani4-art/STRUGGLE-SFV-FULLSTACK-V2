<?php
/** /api/employees.php — Modul ERP: SDM (karyawan) */
require_once __DIR__ . '/bootstrap.php';
$pdo = db();

switch (method()) {
    case 'GET': {
        require_admin();
        $rows = $pdo->query("SELECT * FROM employees ORDER BY id DESC")->fetchAll();
        $summary = [
            'total' => count($rows),
            'aktif' => count(array_filter($rows, fn($r) => $r['status'] === 'Aktif')),
            'total_payroll' => array_sum(array_column($rows, 'salary')),
        ];
        ok(['employees' => $rows, 'summary' => $summary]);
    }
    case 'POST': {
        require_role(['Super Admin', 'Owner', 'Manager']);
        $b = json_input();
        if (empty($b['name'])) fail('name_required');
        $stmt = $pdo->prepare("INSERT INTO employees (name, position, department, email, phone, join_date, salary, status, address, notes) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            $b['name'], $b['position'] ?? '', $b['department'] ?? '', $b['email'] ?? '', $b['phone'] ?? '',
            $b['join_date'] ?? date('Y-m-d'), (int)($b['salary'] ?? 0), $b['status'] ?? 'Aktif', $b['address'] ?? '', $b['notes'] ?? '',
        ]);
        log_activity($_SESSION['admin']['email'], 'create_employee', $b['name']);
        ok(['id' => $pdo->lastInsertId()]);
    }
    case 'PUT': {
        require_role(['Super Admin', 'Owner', 'Manager']);
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) fail('id_required');
        $b = json_input();
        $stmt = $pdo->prepare("SELECT * FROM employees WHERE id = ?");
        $stmt->execute([$id]);
        $ex = $stmt->fetch();
        if (!$ex) fail('not_found', 404);
        $merge = fn($k, $def = null) => array_key_exists($k, $b) ? $b[$k] : $ex[$k] ?? $def;
        $pdo->prepare("UPDATE employees SET name=?, position=?, department=?, email=?, phone=?, join_date=?, salary=?, status=?, address=?, notes=?, updated_at=CURRENT_TIMESTAMP WHERE id=?")
            ->execute([
                $merge('name'), $merge('position'), $merge('department'), $merge('email'), $merge('phone'),
                $merge('join_date'), (int)$merge('salary', 0), $merge('status'), $merge('address'), $merge('notes'), $id,
            ]);
        log_activity($_SESSION['admin']['email'], 'update_employee', "#$id");
        ok([]);
    }
    case 'DELETE': {
        require_role(['Super Admin', 'Owner', 'Manager']);
        $id = (int)($_GET['id'] ?? 0);
        $pdo->prepare("DELETE FROM employees WHERE id = ?")->execute([$id]);
        ok(['deleted' => $id]);
    }
    default: fail('method_not_allowed', 405);
}
