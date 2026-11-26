<?php
/** /api/upload.php — Upload gambar produk dari admin (Produk+) */
require_once __DIR__ . '/bootstrap.php';
require_role(['Super Admin', 'Owner', 'Manager', 'Marketing']);

if (method() !== 'POST') fail('method_not_allowed', 405);
if (empty($_FILES['image'])) fail('file_required');

$file = $_FILES['image'];
if ($file['error'] !== UPLOAD_ERR_OK) fail('upload_error');
if ($file['size'] > 5 * 1024 * 1024) fail('file_too_large');

$allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
$mime = mime_content_type($file['tmp_name']);
if (!isset($allowed[$mime])) fail('invalid_file_type');

$ext = $allowed[$mime];
$name = 'prod_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$dest = UPLOADS_DIR . '/' . $name;

if (!move_uploaded_file($file['tmp_name'], $dest)) fail('save_failed', 500);

// Path relatif yang dipakai storefront (root project -> data/uploads/products/xxx)
ok(['path' => 'data/uploads/products/' . $name]);
