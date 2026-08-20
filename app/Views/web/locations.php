<?= view('web/_layout_top', get_defined_vars()) ?>
<section class="card">
  <div class="card-heading"><div><p>ORGANIZATION</p><h2>Add a Location</h2></div><span class="muted">One Location can contain multiple Studios</span></div>
  <form method="post" action="<?= site_url('control/locations') ?>" class="form-grid location-form">
    <?= csrf_field() ?>
    <label>Location name<input name="name" value="<?= esc(old('name')) ?>" maxlength="160" placeholder="Bogor" required></label>
    <label>Code<input name="code" value="<?= esc(old('code')) ?>" maxlength="24" placeholder="BGR" pattern="[A-Za-z0-9][A-Za-z0-9-]{0,23}" required></label>
    <label>Timezone<input name="timezone" value="<?= esc(old('timezone') ?: 'Asia/Jakarta') ?>" required></label>
    <label>Address (optional)<input name="address" value="<?= esc(old('address')) ?>" maxlength="1000" placeholder="Mall, street, city"></label>
    <div class="form-action"><button class="btn primary" type="submit">Create Location</button></div>
  </form>
</section>
<section class="card">
  <div class="card-heading"><div><p>LOCATION DIRECTORY</p><h2>Locations and Studios</h2></div><span class="count"><?= count($locations) ?> Locations</span></div>
  <form method="get" action="<?= site_url('control/locations') ?>" class="filter-row">
    <input name="q" value="<?= esc($filters['q']) ?>" placeholder="Search name, code, or address">
    <select name="status"><option value="">All statuses</option><option value="active" <?= $filters['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $filters['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option></select>
    <button class="btn primary" type="submit">Apply filters</button><a class="btn ghost" href="<?= site_url('control/locations') ?>">Reset</a>
  </form>
  <?php if ($locations === []): ?><div class="empty">No Locations match the current filters.</div><?php else: ?><div class="location-grid">
    <?php foreach ($locations as $item): $location = $item['entity']; ?><article class="location-card">
      <div class="location-head"><div><span class="location-code"><?= esc($location->code) ?></span><h3><?= esc($location->name) ?></h3><p><?= esc($location->address ?: $location->timezone) ?></p></div><span class="badge <?= esc($location->status) ?>"><?= esc(strtoupper($location->status)) ?></span></div>
      <div class="location-stats"><span><strong><?= $item['total'] ?></strong><small>Studios</small></span><span><strong><?= $item['online'] ?></strong><small>Online</small></span><span><strong><?= $item['playing'] ?></strong><small>Playing</small></span><span class="<?= $item['errors'] > 0 ? 'has-error' : '' ?>"><strong><?= $item['errors'] ?></strong><small>Errors</small></span></div>
      <div class="studio-list"><?php if ($item['studios'] === []): ?><p class="muted">No Studios assigned.</p><?php endif ?><?php foreach (array_slice($item['studios'], 0, 4) as $studio): $device = $studio['entity']; ?><a href="<?= site_url('control/locations/' . rawurlencode($location->public_id)) ?>"><span><strong><?= esc($device->name) ?></strong><small><?= esc(ucfirst($device->playback_state ?: 'unknown')) ?></small></span><span class="badge <?= esc($studio['connection']) ?>"><?= esc(strtoupper($studio['connection'])) ?></span></a><?php endforeach ?><?php if (count($item['studios']) > 4): ?><p class="muted">+ <?= count($item['studios']) - 4 ?> more Studios</p><?php endif ?></div>
      <div class="location-card-actions"><a class="btn primary" href="<?= site_url('control/locations/' . rawurlencode($location->public_id)) ?>">Manage Studios</a><a class="btn ghost" href="<?= site_url('control/locations/' . rawurlencode($location->public_id) . '#add-studio') ?>">Add Studio</a></div>
      <details><summary>Manage Location</summary>
        <form method="post" action="<?= site_url('control/locations/' . rawurlencode($location->public_id) . '/update') ?>" class="location-edit-form"><?= csrf_field() ?><label>Name<input name="name" value="<?= esc($location->name) ?>" maxlength="160" required></label><label>Code<input name="code" value="<?= esc($location->code) ?>" maxlength="24" required></label><label>Timezone<input name="timezone" value="<?= esc($location->timezone) ?>" required></label><label>Address<input name="address" value="<?= esc($location->address) ?>" maxlength="1000"></label><input type="hidden" name="status" value="<?= esc($location->status) ?>"><button class="btn ghost" type="submit">Save changes</button></form>
        <div class="location-actions"><form method="post" action="<?= site_url('control/locations/' . rawurlencode($location->public_id) . '/status') ?>"><?= csrf_field() ?><input type="hidden" name="status" value="<?= $location->status === 'active' ? 'inactive' : 'active' ?>"><button class="btn ghost" type="submit"><?= $location->status === 'active' ? 'Deactivate' : 'Activate' ?></button></form><?php if ($location->status === 'inactive' && $item['total'] === 0): ?><form method="post" action="<?= site_url('control/locations/' . rawurlencode($location->public_id) . '/delete') ?>" onsubmit="return confirm('Permanently delete this unused Location?')"><?= csrf_field() ?><button class="btn danger" type="submit">Delete Location</button></form><?php endif ?></div>
      </details>
    </article><?php endforeach ?>
  </div><?php endif ?>
</section>
<?= view('web/_layout_bottom') ?>
