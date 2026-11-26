<?php
/**
 * /api/config.php — Konfigurasi publik storefront (data toko, fitur, metode bayar aktif, status Maps).
 * API key Google Maps dibaca dari environment (GOOGLE_MAPS_API_KEY) atau pengaturan server
 * (Admin > Pengaturan). Key TIDAK pernah ditulis di file frontend; hanya dikirim ke browser
 * bila sudah diisi — batasi key tersebut dengan "HTTP referrer restriction" di Google Cloud.
 */
require_once __DIR__ . '/bootstrap.php';
$pdo = db();
if (method() !== 'GET') fail('method_not_allowed', 405);

function maps_api_key(): string {
    $env = getenv('GOOGLE_MAPS_API_KEY');
    if ($env) return trim($env);
    return trim((string)setting('maps_api_key', ''));
}

$methods = $pdo->query("SELECT code, grp, name, account_name, account_number, instructions, qr_image, is_sample FROM payment_methods WHERE enabled = 1 ORDER BY sort_order, id")->fetchAll();
foreach ($methods as &$m) { $m['is_sample'] = (bool)$m['is_sample']; }
unset($m);

$key = maps_api_key();
ok([
    'store' => [
        'name' => setting('store_name'), 'groupName' => setting('group_name'), 'phone' => setting('store_phone'),
        'whatsapp' => setting('store_whatsapp'), 'email' => setting('store_email'), 'instagram' => setting('store_instagram'),
        'link' => setting('store_link'), 'address' => setting('store_address'),
    ],
    'features' => ['allowRegistration' => setting('allow_registration', '0') === '1'],
    'maps' => ['enabled' => $key !== '', 'key' => $key !== '' ? $key : null],
    'paymentMethods' => $methods,
    'paymentMode' => 'manual',
]);
