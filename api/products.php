<?php
/**
 * /api/products.php
 * GET     -> daftar produk (publik, dipakai storefront) atau satu produk (?id=)
 * POST    -> buat produk baru (admin)
 * PUT     -> update produk (admin, ?id=)
 * DELETE  -> hapus produk (admin, ?id=)
 */
require_once __DIR__ . '/bootstrap.php';

$pdo = db();

switch (method()) {
    case 'GET': {
        if (!empty($_GET['id'])) {
            $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
            $stmt->execute([(int)$_GET['id']]);
            $row = $stmt->fetch();
            if (!$row) fail('not_found', 404);
            ok(['product' => decode_product($row)]);
        }
        $status = $_GET['status'] ?? 'active'; // admin bisa kirim status=all
        $sql = "SELECT * FROM products";
        $params = [];
        if ($status !== 'all') { $sql .= " WHERE status = ?"; $params[] = $status; }
        $sql .= " ORDER BY id ASC";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = array_map('decode_product', $stmt->fetchAll());
        ok(['products' => $rows, 'count' => count($rows)]);
    }

    case 'POST': {
        require_role(['Super Admin', 'Owner', 'Manager', 'Marketing']);
        $b = json_input();
        if (empty($b['name']) || !isset($b['price'])) fail('name_and_price_required');
        $stmt = $pdo->prepare("INSERT INTO products
            (name, price, original_price, category, material, description, images, colors, sizes, sleeves, sleeve_prices, badge, stock, is_new, is_best_seller, is_deal, collection, status, weight_gram)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([
            trim($b['name']), (int)$b['price'], !empty($b['originalPrice']) ? (int)$b['originalPrice'] : null,
            $b['category'] ?? 'kaos', $b['material'] ?? '', $b['description'] ?? '',
            json_encode($b['images'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($b['colors'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($b['sizes'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($b['sleeves'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($b['sleevePrices'] ?? new stdClass(), JSON_UNESCAPED_UNICODE),
            $b['badge'] ?? null, (int)($b['stock'] ?? 0),
            !empty($b['isNew']) ? 1 : 0, !empty($b['isBestSeller']) ? 1 : 0, !empty($b['isDeal']) ? 1 : 0,
            $b['collection'] ?? null, $b['status'] ?? 'active', max(0, (int)($b['weightGram'] ?? 300)),
        ]);
        $id = (int)$pdo->lastInsertId();
        log_activity($_SESSION['admin']['email'], 'create_product', "Produk #$id: " . trim($b['name']));
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$id]);
        ok(['product' => decode_product($stmt->fetch())]);
    }

    case 'PUT': {
        require_role(['Super Admin', 'Owner', 'Manager', 'Marketing']);
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) fail('id_required');
        $b = json_input();
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$id]);
        $existing = $stmt->fetch();
        if (!$existing) fail('not_found', 404);

        $merged = [
            'name' => $b['name'] ?? $existing['name'],
            'price' => isset($b['price']) ? (int)$b['price'] : $existing['price'],
            'original_price' => array_key_exists('originalPrice', $b) ? ($b['originalPrice'] !== '' && $b['originalPrice'] !== null ? (int)$b['originalPrice'] : null) : $existing['original_price'],
            'category' => $b['category'] ?? $existing['category'],
            'material' => $b['material'] ?? $existing['material'],
            'description' => $b['description'] ?? $existing['description'],
            'images' => isset($b['images']) ? json_encode($b['images'], JSON_UNESCAPED_UNICODE) : $existing['images'],
            'colors' => isset($b['colors']) ? json_encode($b['colors'], JSON_UNESCAPED_UNICODE) : $existing['colors'],
            'sizes' => isset($b['sizes']) ? json_encode($b['sizes'], JSON_UNESCAPED_UNICODE) : $existing['sizes'],
            'sleeves' => isset($b['sleeves']) ? json_encode($b['sleeves'], JSON_UNESCAPED_UNICODE) : $existing['sleeves'],
            'sleeve_prices' => isset($b['sleevePrices']) ? json_encode($b['sleevePrices'], JSON_UNESCAPED_UNICODE) : $existing['sleeve_prices'],
            'badge' => array_key_exists('badge', $b) ? $b['badge'] : $existing['badge'],
            'stock' => isset($b['stock']) ? (int)$b['stock'] : $existing['stock'],
            'is_new' => isset($b['isNew']) ? (int)!!$b['isNew'] : $existing['is_new'],
            'is_best_seller' => isset($b['isBestSeller']) ? (int)!!$b['isBestSeller'] : $existing['is_best_seller'],
            'is_deal' => isset($b['isDeal']) ? (int)!!$b['isDeal'] : $existing['is_deal'],
            'collection' => array_key_exists('collection', $b) ? $b['collection'] : $existing['collection'],
            'status' => $b['status'] ?? $existing['status'],
            'weight_gram' => isset($b['weightGram']) ? max(0, (int)$b['weightGram']) : $existing['weight_gram'],
        ];

        $stmt = $pdo->prepare("UPDATE products SET name=?, price=?, original_price=?, category=?, material=?, description=?, images=?, colors=?, sizes=?, sleeves=?, sleeve_prices=?, badge=?, stock=?, is_new=?, is_best_seller=?, is_deal=?, collection=?, status=?, weight_gram=?, updated_at=CURRENT_TIMESTAMP WHERE id=?");
        $stmt->execute([
            $merged['name'], $merged['price'], $merged['original_price'], $merged['category'], $merged['material'],
            $merged['description'], $merged['images'], $merged['colors'], $merged['sizes'], $merged['sleeves'],
            $merged['sleeve_prices'], $merged['badge'], $merged['stock'], $merged['is_new'], $merged['is_best_seller'],
            $merged['is_deal'], $merged['collection'], $merged['status'], $merged['weight_gram'], $id,
        ]);
        log_activity($_SESSION['admin']['email'], 'update_product', "Produk #$id: {$merged['name']}");
        $stmt = $pdo->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$id]);
        ok(['product' => decode_product($stmt->fetch())]);
    }

    case 'DELETE': {
        require_role(['Super Admin', 'Owner', 'Manager']);
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) fail('id_required');
        $pdo->prepare("DELETE FROM products WHERE id = ?")->execute([$id]);
        log_activity($_SESSION['admin']['email'], 'delete_product', "Produk #$id");
        ok(['deleted' => $id]);
    }

    default:
        fail('method_not_allowed', 405);
}
