<?php
/** /api/collections.php — Koleksi (publik, hanya baca). */
require_once __DIR__ . '/bootstrap.php';
if (method() !== 'GET') fail('method_not_allowed', 405);
$rows = db()->query("SELECT code AS id, name, description, image FROM collections ORDER BY id ASC")->fetchAll();
ok(['collections' => $rows]);
