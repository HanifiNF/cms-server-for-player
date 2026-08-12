<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title><?= esc($title) ?> · Player CMS</title>
  <link rel="stylesheet" href="<?= base_url('assets/cms.css') ?>">
  <link rel="stylesheet" href="<?= base_url('assets/cms-lifecycle.css') ?>">
  <link rel="stylesheet" href="<?= base_url('assets/cms-schedules.css') ?>">
</head>
<body>
<div class="app-shell">
  <aside class="sidebar">
    <a class="brand" href="<?= site_url('control') ?>"><span class="brand-icon">▶</span><span>Player CMS<small>CONTROL CENTER</small></span></a>
    <nav>
      <a class="<?= ($active ?? '') === 'dashboard' ? 'active' : '' ?>" href="<?= site_url('control') ?>">⌂ <span>Dashboard</span></a>
      <a class="<?= ($active ?? '') === 'operators' ? 'active' : '' ?>" href="<?= site_url('control/operators') ?>">◎ <span>Operators</span></a>
      <a class="<?= ($active ?? '') === 'devices' ? 'active' : '' ?>" href="<?= site_url('control/devices') ?>">▣ <span>Players</span></a>
      <a class="<?= ($active ?? '') === 'assets' ? 'active' : '' ?>" href="<?= site_url('control/assets') ?>">◆ <span>Assets</span></a>
      <a class="<?= ($active ?? '') === 'schedules' ? 'active' : '' ?>" href="<?= site_url('control/schedules') ?>">◷ <span>Schedules</span></a>
    </nav>
    <div class="sidebar-footer">
      <div class="signed-in"><strong><?= esc($admin->name) ?></strong><small><?= esc($admin->email) ?></small></div>
      <form method="post" action="<?= site_url('logout') ?>"><?= csrf_field() ?><button class="btn ghost full" type="submit">Sign out</button></form>
    </div>
  </aside>
  <main class="main">
    <header class="page-header"><div><p>ADMINISTRATION</p><h1><?= esc($title) ?></h1></div><span class="environment">LOCAL CMS</span></header>
    <?php if (session('success')): ?><div class="alert success"><?= esc(session('success')) ?></div><?php endif ?>
    <?php if (session('error')): ?><div class="alert error"><?= esc(session('error')) ?></div><?php endif ?>
    <?php if ($errors = session('errors')): ?><div class="alert error"><strong>Please correct the following:</strong><ul><?php foreach ($errors as $error): ?><li><?= esc($error) ?></li><?php endforeach ?></ul></div><?php endif ?>
