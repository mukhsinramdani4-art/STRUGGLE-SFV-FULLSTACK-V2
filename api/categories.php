<?php
/** /api/categories.php — kategori produk (GET publik, POST/PUT/DELETE admin) */
require_once __DIR__ . '/bootstrap.php';
$pdo = db();

switch (method()) {
    case 'GET': {
        $rows = $pdo->query("SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category = c.code AND p.status='active') as product_count FROM categories c ORDER BY c.id ASC")->fetchAll();
        ok(['categories' => $rows]);
    }
    case 'POST': {
        require_role(['Super Admin', 'Owner', 'Manager', 'Marketing']);
        $b = json_input();
        if (empty($b['code']) || empty($b['name'])) fail('code_and_name_required');
        $stmt = $pdo->prepare("INSERT INTO categories (code, name, icon) VALUES (?,?,?)");
        $stmt->execute([strtolower(trim($b['code'])), trim($b['name']), $b['icon'] ?? 'checkroom']);
        log_activity($_SESSION['admin']['email'], 'create_category', $b['name']);
        ok(['id' => $pdo->lastInsertId()]);
    }
    case 'PUT': {
        require_role(['Super Admin', 'Owner', 'Manager', 'Marketing']);
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) fail('id_required');
        $b = json_input();
        $stmt = $pdo->prepare("UPDATE categories SET name=?, icon=? WHERE id=?");
        $stmt->execute([$b['name'] ?? '', $b['icon'] ?? 'checkroom', $id]);
        ok([]);
    }
    case 'DELETE': {
        require_role(['Super Admin', 'Owner', 'Manager']);
        $id = (int)($_GET['id'] ?? 0);
        $pdo->prepare("DELETE FROM categories WHERE id = ?")->execute([$id]);
        ok(['deleted' => $id]);
    }
    default: fail('method_not_allowed', 405);
}
