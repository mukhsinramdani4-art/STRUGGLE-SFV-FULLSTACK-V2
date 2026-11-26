<?php
/**
 * Header bersama untuk semua halaman admin.
 * Wajib set $active (key nav) dan $pageTitle sebelum include file ini.
 */
$__u = require_page_access($active ?? 'dashboard');
$__display = admin_display($__u);
$__group = group_name();
?><!doctype html>
<html lang="id">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?= e($pageTitle ?? 'Dashboard') ?> — SFV Business OS</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@24,400,0,0" rel="stylesheet">
<link rel="stylesheet" href="assets/css/admin.css">
<link rel="icon" href="../assets/images/logo.png">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.4/chart.umd.min.js" defer></script>
<script>window.SFV_USER = <?= json_encode(['id' => $__u['id'], 'role' => $__u['role'], 'name' => $__display], JSON_UNESCAPED_UNICODE) ?>; window.SFV_GROUP = <?= json_encode($__group, JSON_UNESCAPED_UNICODE) ?>;</script>
<script src="assets/js/admin.js"></script>
</head>
<body class="admin-body">
<div class="admin-shell">
  <aside class="admin-sidebar" id="sidebar">
    <div class="sidebar-brand">
      <img src="../assets/images/logo.png" alt="SFV">
      <div><b>SFV</b><span>Business OS · <?= e($__group) ?></span></div>
    </div>
    <nav class="sidebar-nav">
      <?php foreach (nav_items() as $item): if (!nav_allowed($item, $__u['role'])) continue; ?>
        <a href="<?= e($item[3]) ?>" class="<?= $active === $item[0] ? 'active' : '' ?>">
          <span class="material-symbols-outlined"><?= e($item[2]) ?></span><span><?= e($item[1]) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <a href="../index.html" class="sidebar-store-link" target="_blank">
      <span class="material-symbols-outlined">storefront</span><span>Lihat Toko</span>
    </a>
  </aside>

  <div class="admin-main">
    <header class="admin-topbar">
      <button class="icon-btn only-mobile" id="btn-burger"><span class="material-symbols-outlined">menu</span></button>
      <div class="topbar-title">
        <h1><?= e($pageTitle ?? 'Dashboard') ?></h1>
      </div>
      <div class="topbar-actions">
        <div class="user-chip">
          <a href="profile.php" class="user-chip-link" title="Profil admin" style="display:flex;align-items:center;gap:10px;text-decoration:none;color:inherit">
            <div class="avatar"><?= e(mb_strtoupper(mb_substr($__display, 0, 1))) ?></div>
            <div class="user-meta"><b><?= e($__display) ?></b><span><?= e($__u['role']) ?></span></div>
          </a>
        </div>
        <form method="post" action="logout.php"><button class="icon-btn" title="Keluar"><span class="material-symbols-outlined">logout</span></button></form>
      </div>
    </header>
    <main class="admin-content">
