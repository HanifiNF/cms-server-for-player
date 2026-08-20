<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= esc($title) ?> · Player CMS</title>
  <link rel="stylesheet" href="<?= base_url('assets/cms.css') ?>?v=<?= (int) (@filemtime(FCPATH . 'assets/cms.css') ?: 1) ?>">
  <link rel="stylesheet" href="<?= base_url('assets/cms-lifecycle.css') ?>?v=<?= (int) (@filemtime(FCPATH . 'assets/cms-lifecycle.css') ?: 1) ?>">
  <link rel="stylesheet" href="<?= base_url('assets/cms-schedules.css') ?>?v=<?= (int) (@filemtime(FCPATH . 'assets/cms-schedules.css') ?: 1) ?>">
  <link rel="stylesheet" href="<?= base_url('assets/cms-locations.css') ?>?v=<?= (int) (@filemtime(FCPATH . 'assets/cms-locations.css') ?: 1) ?>">
</head>
<body>
<div class="app-shell">
  <aside class="sidebar">
    <a class="brand" href="<?= site_url($admin->role === 'distributor' ? 'control/assets' : 'control') ?>"><span class="brand-icon">▶</span><span>Player CMS<small><?= $admin->role === 'distributor' ? 'DISTRIBUTOR PORTAL' : 'CONTROL CENTER' ?></small></span></a>
    <nav>
      <?php if ($admin->role === 'admin'): ?>
      <a class="<?= ($active ?? '') === 'dashboard' ? 'active' : '' ?>" href="<?= site_url('control') ?>">⌂ <span>Dashboard</span></a>
      <a class="<?= ($active ?? '') === 'operators' ? 'active' : '' ?>" href="<?= site_url('control/operators') ?>">◎ <span>Accounts</span></a>
      <a class="<?= ($active ?? '') === 'locations' ? 'active' : '' ?>" href="<?= site_url('control/locations') ?>">⌖ <span>Locations</span></a>
      <?php endif ?>
      <a class="<?= ($active ?? '') === 'assets' ? 'active' : '' ?>" href="<?= site_url('control/assets') ?>">◆ <span>Assets</span></a>
      <?php if ($admin->role === 'admin'): ?>
      <a class="<?= ($active ?? '') === 'schedules' ? 'active' : '' ?>" href="<?= site_url('control/schedules') ?>">◷ <span>Schedules</span></a>
      <?php endif ?>
    </nav>
    <div class="sidebar-footer">
      <div class="signed-in"><strong><?= esc($admin->name) ?></strong><small><?= esc($admin->email) ?> · <?= esc(strtoupper($admin->role)) ?></small></div>
      <form method="post" action="<?= site_url('logout') ?>"><?= csrf_field() ?><button class="btn ghost full" type="submit">Sign out</button></form>
    </div>
  </aside>
  <main class="main">
    <header class="page-header"><div><p><?= $admin->role === 'distributor' ? 'DISTRIBUTOR PORTAL' : 'ADMINISTRATION' ?></p><h1><?= esc($title) ?></h1></div><span class="environment"><?= esc(strtoupper($admin->role)) ?></span></header>
    <?php if (session('success')): ?><div class="alert success"><?= esc(session('success')) ?></div><?php endif ?>
    <?php if (session('error')): ?><div class="alert error"><?= esc(session('error')) ?></div><?php endif ?>
    <?php if ($errors = session('errors')): ?><div class="alert error"><strong>Please correct the following:</strong><ul><?php foreach ($errors as $error): ?><li><?= esc($error) ?></li><?php endforeach ?></ul></div><?php endif ?>
