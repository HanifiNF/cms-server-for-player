<?= view('web/_layout_top', get_defined_vars()) ?>
<section class="stats-grid">
  <article class="stat-card"><span>Operators</span><strong><?= $operatorCount ?></strong><a href="<?= site_url('control/operators') ?>">Manage accounts →</a></article>
  <article class="stat-card"><span>All Players</span><strong><?= $deviceCount ?></strong><a href="<?= site_url('control/devices') ?>">View Players →</a></article>
  <article class="stat-card accent"><span>Waiting to pair</span><strong><?= $pendingCount ?></strong><small>Pending Player records</small></article>
  <article class="stat-card"><span>Claimed</span><strong><?= $activeCount ?></strong><small>Registered installations</small></article>
</section>
<section class="card">
  <div class="card-heading"><div><p>QUICK START</p><h2>Test Player pairing</h2></div></div>
  <ol class="steps"><li>Create an operator account.</li><li>Create a Player and assign that operator.</li><li>Open the Player, sign in with the operator, and select the assigned device.</li><li>After pairing, watch its status update from the Players page.</li></ol>
</section>
<section class="card">
  <div class="card-heading"><div><p>RECENT</p><h2>Latest Players</h2></div><a class="btn ghost" href="<?= site_url('control/devices') ?>">Open Players</a></div>
  <?php if ($devices === []): ?><div class="empty">No Players created yet.</div><?php else: ?><div class="simple-list"><?php foreach ($devices as $device): ?><div><span><strong><?= esc($device->name) ?></strong><small><?= esc($device->location ?: 'No location') ?></small></span><span class="badge <?= esc($device->status) ?>"><?= esc(strtoupper($device->status)) ?></span></div><?php endforeach ?></div><?php endif ?>
</section>
<?= view('web/_layout_bottom') ?>
