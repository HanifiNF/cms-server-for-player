<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= esc($title) ?> · Player CMS</title><link rel="stylesheet" href="<?= base_url('assets/cms.css') ?>?v=<?= (int) (@filemtime(FCPATH . 'assets/cms.css') ?: 1) ?>"></head>
<body class="auth-body"><main class="auth-card wide">
  <div class="auth-brand"><span class="brand-icon">▶</span><div><strong>Player CMS</strong><small>FIRST-RUN SETUP</small></div></div>
  <p class="eyebrow">ONE-TIME SETUP</p><h1>Create the administrator</h1><p class="muted">This page becomes unavailable as soon as the first administrator exists.</p>
  <?php if (session('error')): ?><div class="alert error"><?= esc(session('error')) ?></div><?php endif ?>
  <?php if ($errors = session('errors')): ?><div class="alert error"><ul><?php foreach ($errors as $error): ?><li><?= esc($error) ?></li><?php endforeach ?></ul></div><?php endif ?>
  <form method="post" action="<?= site_url('setup') ?>" class="stack-form">
    <?= csrf_field() ?>
    <label>Full name<input name="name" value="<?= esc(old('name')) ?>" required autofocus></label>
    <label>Email address<input type="email" name="email" value="<?= esc(old('email')) ?>" required autocomplete="username"></label>
    <div class="two-col"><label>Password<input type="password" name="password" minlength="12" required autocomplete="new-password"></label><label>Confirm password<input type="password" name="password_confirmation" minlength="12" required autocomplete="new-password"></label></div>
    <p class="hint">Use at least 12 characters.</p><button class="btn primary full" type="submit">Create administrator</button>
  </form>
</main></body></html>
