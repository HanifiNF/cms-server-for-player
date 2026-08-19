<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= esc($title) ?> · Player CMS</title><link rel="stylesheet" href="<?= base_url('assets/cms.css') ?>?v=<?= (int) (@filemtime(FCPATH . 'assets/cms.css') ?: 1) ?>"></head>
<body class="auth-body"><main class="auth-card">
  <div class="auth-brand"><span class="brand-icon">▶</span><div><strong>Player CMS</strong><small>CONTROL CENTER</small></div></div>
  <p class="eyebrow">CMS ACCESS</p><h1>Welcome back</h1><p class="muted">Administrators manage cinema operations. Distributors upload films for review.</p>
  <?php if (session('error')): ?><div class="alert error"><?= esc(session('error')) ?></div><?php endif ?>
  <form method="post" action="<?= site_url('login') ?>" class="stack-form">
    <?= csrf_field() ?>
    <label>Email address<input type="email" name="email" value="<?= esc(old('email')) ?>" required autocomplete="username" autofocus></label>
    <label>Password<input type="password" name="password" required autocomplete="current-password"></label>
    <button class="btn primary full" type="submit">Sign in</button>
  </form>
</main></body></html>
