<?= view('web/_layout_top', get_defined_vars()) ?>
<section class="stats-grid">
  <article class="stat-card"><span>Locations</span><strong><?= $locationCount ?></strong><a href="<?= site_url('control/locations') ?>">Manage Locations →</a></article>
  <article class="stat-card"><span>All Studios</span><strong><?= $deviceCount ?></strong><a href="<?= site_url('control/locations') ?>">View by Location →</a></article>
  <article class="stat-card accent"><span>Online Studios</span><strong><?= $onlineCount ?></strong><small><?= $playingCount ?> currently playing</small></article>
  <article class="stat-card"><span>Needs attention</span><strong><?= $offlineCount + $errorCount ?></strong><small><?= $offlineCount ?> offline · <?= $errorCount ?> playback errors</small></article>
</section>
<section class="stats-grid">
  <article class="stat-card"><span>Operators</span><strong><?= $operatorCount ?></strong><a href="<?= site_url('control/operators') ?>">Manage accounts →</a></article>
  <article class="stat-card"><span>Waiting to pair</span><strong><?= $pendingCount ?></strong><small>Pending Studio records</small></article>
  <article class="stat-card"><span>Playing</span><strong><?= $playingCount ?></strong><small>Reported by Player heartbeat</small></article>
  <article class="stat-card"><span>Playback errors</span><strong><?= $errorCount ?></strong><small>Reported by Player heartbeat</small></article>
</section>
<section class="card"><div class="card-heading"><div><p>QUICK START</p><h2>Connect a Studio Player</h2></div></div><ol class="steps"><li>Create a Location.</li><li>Create an operator account.</li><li>Create a Studio in that Location and assign the operator.</li><li>Open the Player, sign in, and select the assigned Studio.</li><li>After pairing, monitor connection and playback status from Studios or Locations.</li></ol></section>
<section class="card"><div class="card-heading"><div><p>RECENT</p><h2>Latest Studios</h2></div><a class="btn ghost" href="<?= site_url('control/locations') ?>">Open Locations</a></div><?php if ($devices === []): ?><div class="empty">No Studios created yet.</div><?php else: ?><div class="simple-list"><?php foreach ($devices as $device): ?><div><span><strong><?= esc($device->name) ?></strong><small><?= esc($device->location ?: 'No Location') ?> · <?= esc(ucfirst($device->playback_state ?: 'unknown')) ?></small></span><span class="badge <?= esc($device->status) ?>"><?= esc(strtoupper($device->status)) ?></span></div><?php endforeach ?></div><?php endif ?></section>
<?= view('web/_layout_bottom') ?>
