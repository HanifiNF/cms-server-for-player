<?= view('web/_layout_top', get_defined_vars()) ?>
<section class="card">
  <div class="card-heading"><div><p>NEW ACCOUNT</p><h2>Add a CMS account</h2></div><span class="muted">Operators control assigned Studios; distributors submit films</span></div>
  <form method="post" action="<?= site_url('control/operators') ?>" class="form-grid">
    <?= csrf_field() ?><label>Name<input name="name" value="<?= esc(old('name')) ?>" required></label><label>Email<input type="email" name="email" value="<?= esc(old('email')) ?>" required></label><label>Role<select name="role"><option value="operator">Operator</option><option value="distributor">Distributor</option><option value="admin">Administrator</option></select></label><label>Initial password<input type="password" name="password" minlength="12" required autocomplete="new-password"></label><div class="form-action"><button class="btn primary" type="submit">Create account</button></div>
  </form>
</section>
<section class="card">
  <div class="card-heading"><div><p>ACCESS</p><h2>All accounts</h2></div><span class="count"><?= count($users) ?> accounts</span></div>
  <form method="get" action="<?= site_url('control/operators') ?>" class="filter-row account-filters"><input name="q" value="<?= esc($filters['q']) ?>" placeholder="Search name or email"><select name="role"><option value="">All roles</option><option value="admin" <?= $filters['role'] === 'admin' ? 'selected' : '' ?>>Administrator</option><option value="operator" <?= $filters['role'] === 'operator' ? 'selected' : '' ?>>Operator</option><option value="distributor" <?= $filters['role'] === 'distributor' ? 'selected' : '' ?>>Distributor</option></select><select name="status"><option value="">All statuses</option><option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $filters['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option></select><button class="btn primary" type="submit">Search</button><a class="btn ghost" href="<?= site_url('control/operators') ?>">Reset</a></form>
  <div class="account-list">
  <?php if ($users === []): ?><div class="empty">No accounts match the current filters.</div><?php endif ?>
  <?php foreach ($users as $user): ?>
    <article class="account-row">
      <div class="account-summary"><span class="avatar"><?= esc(mb_strtoupper(mb_substr($user->name, 0, 1))) ?></span><div><strong><?= esc($user->name) ?></strong><small><?= esc($user->email) ?><?= $user->role === 'operator' ? ' · ' . ($studioCounts[(int) $user->id] ?? 0) . ' Studio(s)' : '' ?></small></div><span class="badge <?= esc($user->status) ?>"><?= esc(strtoupper($user->status)) ?></span><span class="role"><?= esc($user->role) ?></span></div>
      <details><summary>Edit account</summary><div class="detail-grid">
        <form method="post" action="<?= site_url('control/operators/' . $user->id . '/update') ?>" class="mini-form"><?= csrf_field() ?><label>Name<input name="name" value="<?= esc($user->name) ?>" required></label><label>Email<input type="email" name="email" value="<?= esc($user->email) ?>" required></label><label>Role<select name="role"><option value="operator" <?= $user->role === 'operator' ? 'selected' : '' ?>>Operator</option><option value="distributor" <?= $user->role === 'distributor' ? 'selected' : '' ?>>Distributor</option><option value="admin" <?= $user->role === 'admin' ? 'selected' : '' ?>>Administrator</option></select></label><button class="btn ghost" type="submit">Save details</button></form>
        <form method="post" action="<?= site_url('control/operators/' . $user->id . '/password') ?>" class="mini-form"><?= csrf_field() ?><label>New password<input type="password" name="password" minlength="12" required autocomplete="new-password"></label><button class="btn ghost" type="submit">Reset password</button></form>
        <form method="post" action="<?= site_url('control/operators/' . $user->id . '/status') ?>" class="mini-form"><?= csrf_field() ?><input type="hidden" name="status" value="<?= $user->status === 'active' ? 'inactive' : 'active' ?>"><button class="btn <?= $user->status === 'active' ? 'danger' : 'primary' ?>" type="submit"><?= $user->status === 'active' ? 'Deactivate account' : 'Activate account' ?></button></form>
      </div></details>
    </article>
  <?php endforeach ?>
  </div>
</section>
<?= view('web/_layout_bottom') ?>
