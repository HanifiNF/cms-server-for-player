<?= view('web/_layout_top', get_defined_vars()) ?>
<section class="card">
  <div class="card-heading"><div><p>REGISTRATION</p><h2>Add a Player</h2></div><span class="muted">The assigned operator will see it during first setup</span></div>
  <?php if ($operators === []): ?><div class="alert error">Create an active operator before assigning a Player.</div><?php endif ?>
  <form method="post" action="<?= site_url('control/devices') ?>" class="form-grid">
    <?= csrf_field() ?><label>Player name<input name="name" value="<?= esc(old('name')) ?>" placeholder="Player Lobby" required></label><label>Location<input name="location" value="<?= esc(old('location')) ?>" placeholder="Lobby lantai 1"></label><label>Timezone<input name="timezone" value="<?= esc(old('timezone') ?: 'Asia/Jakarta') ?>" required></label><label>Assigned operator<select name="assigned_user_id"><option value="">Unassigned</option><?php foreach ($operators as $operator): ?><option value="<?= $operator->id ?>" <?= (string) old('assigned_user_id') === (string) $operator->id ? 'selected' : '' ?>><?= esc($operator->name) ?> — <?= esc($operator->email) ?></option><?php endforeach ?></select></label><div class="form-action"><button class="btn primary" type="submit">Create Player</button></div>
  </form>
</section>
<section class="card">
  <div class="card-heading"><div><p>DEVICES</p><h2>Registered Players</h2></div><span class="count"><?= count($devices) ?> Players</span></div>
  <?php if ($devices === []): ?><div class="empty">No Player records yet.</div><?php else: ?><div class="device-grid"><?php foreach ($devices as $item): $device = $item['entity']; ?>
    <article class="device-card"><div class="device-title"><span class="device-icon">▶</span><div><strong><?= esc($device->name) ?></strong><small><?= esc($device->location ?: 'No location') ?></small></div><span class="badge <?= esc($item['connection']) ?>"><?= esc(strtoupper($item['connection'])) ?></span></div>
      <dl><div><dt>Device ID</dt><dd title="<?= esc($device->public_id) ?>"><?= esc($device->public_id) ?></dd></div><div><dt>Assigned to</dt><dd><?= esc($item['assignedName']) ?></dd></div><div><dt>Version</dt><dd><?= esc($device->app_version ?: 'Not paired') ?></dd></div><div><dt>Last seen</dt><dd><?= $device->last_seen_at ? esc($device->last_seen_at->format('Y-m-d H:i:s')) . ' UTC' : 'Never' ?></dd></div></dl>
      <?php if (in_array($device->status, ['pending', 'active'], true)): ?><form method="post" action="<?= site_url('control/devices/' . $device->public_id . '/assignment') ?>" class="assignment-form"><?= csrf_field() ?><select name="assigned_user_id"><option value="">Unassigned</option><?php foreach ($operators as $operator): ?><option value="<?= $operator->id ?>" <?= (int) $device->assigned_user_id === (int) $operator->id ? 'selected' : '' ?>><?= esc($operator->name) ?></option><?php endforeach ?></select><button class="btn ghost" type="submit">Update assignment</button></form><?php endif ?>
      <?php if ($device->status === 'active'): ?><form method="post" action="<?= site_url('control/devices/' . $device->public_id . '/revoke') ?>" class="lifecycle-form" onsubmit="return confirm('Revoke this Player? It will stop playback and return to pairing.')"><?= csrf_field() ?><button class="btn danger" type="submit">Revoke Player</button></form><?php endif ?>
    </article>
  <?php endforeach ?></div><?php endif ?>
</section>
<section class="card revoked-section">
  <div class="card-heading"><div><p>REVOKED</p><h2>Revoked Players</h2></div><span class="count"><?= count($revokedDevices) ?> Players</span></div>
  <p class="muted">Revoked Players cannot connect or send heartbeat. Permanent deletion is available only here.</p>
  <?php if ($revokedDevices === []): ?><div class="empty">No revoked Players.</div><?php else: ?><div class="device-grid"><?php foreach ($revokedDevices as $item): $device = $item['entity']; ?>
    <article class="device-card revoked"><div class="device-title"><span class="device-icon">×</span><div><strong><?= esc($device->name) ?></strong><small><?= esc($device->location ?: 'No location') ?></small></div><span class="badge revoked">REVOKED</span></div>
      <dl><div><dt>Device ID</dt><dd title="<?= esc($device->public_id) ?>"><?= esc($device->public_id) ?></dd></div><div><dt>Previously assigned</dt><dd><?= esc($item['assignedName']) ?></dd></div><div><dt>Version</dt><dd><?= esc($device->app_version ?: 'Unknown') ?></dd></div><div><dt>Last seen</dt><dd><?= $device->last_seen_at ? esc($device->last_seen_at->format('Y-m-d H:i:s')) . ' UTC' : 'Never' ?></dd></div></dl>
      <form method="post" action="<?= site_url('control/devices/' . $device->public_id . '/delete') ?>" class="lifecycle-form" onsubmit="return confirm('Permanently delete this revoked Player? This action cannot be undone.')"><?= csrf_field() ?><button class="btn danger" type="submit">Delete Permanently</button></form>
    </article>
  <?php endforeach ?></div><?php endif ?>
</section>
<?= view('web/_layout_bottom') ?>
