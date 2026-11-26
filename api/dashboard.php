<?php
/** /api/dashboard.php — Statistik ringkasan untuk Dashboard admin */
require_once __DIR__ . '/bootstrap.php';
$u = require_admin();
$pdo = db();

$totalRevenue = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE payment_status='Dibayar'")->fetchColumn();
$totalOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$pendingOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status='Pending'")->fetchColumn();
$totalCustomers = (int)$pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();
$awaitingVerify = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE payment_status='Menunggu Verifikasi' AND status != 'Dibatalkan'")->fetchColumn();
$toShip = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status IN ('Diproses','Dikemas') AND shipping_status = 'Menunggu Pengiriman'")->fetchColumn();
$totalProducts = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE status='active'")->fetchColumn();
$lowStock = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE stock <= 10 AND status='active'")->fetchColumn();

$revenue30 = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status != 'Dibatalkan' AND created_at >= date('now','-30 day')")->fetchColumn();
$revenuePrev30 = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status != 'Dibatalkan' AND created_at >= date('now','-60 day') AND created_at < date('now','-30 day')")->fetchColumn();
$growth = $revenuePrev30 > 0 ? round((($revenue30 - $revenuePrev30) / $revenuePrev30) * 100, 1) : ($revenue30 > 0 ? 100 : 0);

$salesTrend = [];
for ($i = 6; $i >= 0; $i--) {
    $day = date('Y-m-d', strtotime("-$i day"));
    $total = (float)$pdo->query("SELECT COALESCE(SUM(total),0) FROM orders WHERE status != 'Dibatalkan' AND date(created_at) = '$day'")->fetchColumn();
    $salesTrend[] = ['date' => $day, 'total' => $total];
}

$recentOrders = $pdo->query("SELECT id, customer_name, total, status, payment_status, shipping_status, created_at FROM orders ORDER BY created_at DESC LIMIT 8")->fetchAll();

$topProducts = $pdo->query("SELECT p.id, p.name, p.images, COALESCE(SUM(oi.qty),0) as sold
    FROM products p LEFT JOIN order_items oi ON oi.product_id = p.id
    GROUP BY p.id ORDER BY sold DESC LIMIT 5")->fetchAll();
foreach ($topProducts as &$tp) { $tp['images'] = json_decode($tp['images'] ?? '[]', true) ?: []; }

$assetValue = (float)$pdo->query("SELECT COALESCE(SUM(current_value),0) FROM assets")->fetchColumn();
$employeeCount = (int)$pdo->query("SELECT COUNT(*) FROM employees WHERE status='Aktif'")->fetchColumn();
$netProfit = (float)$pdo->query("SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount ELSE -amount END),0) FROM finance_transactions")->fetchColumn();

ok([
    'stats' => [
        'total_revenue' => $totalRevenue,
        'total_orders' => $totalOrders,
        'pending_orders' => $pendingOrders,
        'awaiting_verification' => $awaitingVerify,
        'to_ship' => $toShip,
        'total_customers' => $totalCustomers,
        'total_products' => $totalProducts,
        'low_stock' => $lowStock,
        'revenue_growth_pct' => $growth,
        'asset_value' => $assetValue,
        'employee_count' => $employeeCount,
        'net_profit' => $netProfit,
    ],
    'sales_trend' => $salesTrend,
    'recent_orders' => $recentOrders,
    'top_products' => $topProducts,
]);
