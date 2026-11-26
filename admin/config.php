<?php
/**
 * SFV Business OS — Admin config
 * Session, RBAC, helper umum untuk seluruh halaman /admin.
 */
declare(strict_types=1);
session_set_cookie_params(['httponly' => true, 'samesite' => 'Lax', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
date_default_timezone_set('Asia/Jakarta');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');

require_once __DIR__ . '/../api/db.php';

/** Semua role yang tersedia + siapa yang boleh akses modul apa. */
const ROLE_ALL = ['Super Admin', 'Owner', 'Manager', 'Gudang', 'Kasir', 'Marketing', 'Finance'];
const ROLE_MANAGEMENT = ['Super Admin', 'Owner', 'Manager'];

/** [key, label, icon, url, roles yang diizinkan (null = semua)] */
function nav_items(): array {
    return [
        ['dashboard', 'Dashboard', 'space_dashboard', 'index.php', null],
        ['products', 'Produk+', 'inventory_2', 'products.php', [...ROLE_MANAGEMENT, 'Marketing', 'Gudang']],
        ['categories', 'Kategori', 'category', 'categories.php', [...ROLE_MANAGEMENT, 'Marketing']],
        ['orders', 'Pesanan', 'receipt_long', 'orders.php', [...ROLE_MANAGEMENT, 'Kasir', 'Gudang']],
        ['customers', 'Pelanggan', 'group', 'customers.php', [...ROLE_MANAGEMENT, 'Kasir', 'Marketing']],
        ['payments', 'Pembayaran', 'credit_card', 'payments.php', ROLE_MANAGEMENT],
        ['shipping', 'Pengiriman', 'local_shipping', 'shipping.php', ROLE_MANAGEMENT],
        ['coupons', 'Kupon & Promo', 'sell', 'coupons.php', [...ROLE_MANAGEMENT, 'Marketing']],
        ['assets', 'Aset', 'inventory', 'assets.php', ROLE_MANAGEMENT],
        ['sdm', 'SDM', 'badge', 'sdm.php', ROLE_MANAGEMENT],
        ['keuangan', 'Keuangan', 'account_balance_wallet', 'keuangan.php', [...ROLE_MANAGEMENT, 'Finance']],
        ['settings', 'Pengaturan', 'settings', 'settings.php', ['Super Admin', 'Owner']],
    ];
}

function current_admin(): ?array {
    return $_SESSION['admin'] ?? null;
}

/** Nama tampilan admin (akun demo memakai identitas kelompok). Dibaca dari DB agar selalu terbaru. */
function admin_display(array $u): string {
    $st = db()->prepare("SELECT name, role, identity_managed FROM admin_users WHERE id = ?");
    $st->execute([(int)$u['id']]);
    $row = $st->fetch();
    return $row ? admin_display_name($row) : (string)$u['name'];
}

function group_name(): string { return (string)setting('group_name', 'NAMA KELOMPOK'); }

function require_login(): array {
    $u = current_admin();
    if (!$u) { header('Location: login.php'); exit; }
    return $u;
}

function nav_allowed(array $item, string $role): bool {
    return $item[4] === null || in_array($role, $item[4], true) || $role === 'Super Admin';
}

function require_page_access(string $key): array {
    $u = require_login();
    foreach (nav_items() as $item) {
        if ($item[0] === $key) {
            if (!nav_allowed($item, $u['role'])) {
                http_response_code(403);
                render_forbidden($u);
                exit;
            }
            return $u;
        }
    }
    return $u;
}

function render_forbidden(array $u): void {
    echo '<!doctype html><html lang="id"><head><meta charset="utf-8"><title>Akses Ditolak</title>
    <link rel="stylesheet" href="assets/css/admin.css"></head><body class="admin-body">
    <div class="denied-box"><span class="material-symbols-outlined">lock</span>
    <h1>Akses Ditolak</h1><p>Role <b>' . e($u['role']) . '</b> tidak memiliki izin untuk membuka halaman ini.</p>
    <a class="btn-primary" href="index.php">Kembali ke Dashboard</a></div></body></html>';
}

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function rupiah($n): string { return 'Rp ' . number_format((float)$n, 0, ',', '.'); }
function csrf_token(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(16)); }
