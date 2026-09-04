<?= view('web/_layout_top', get_defined_vars()) ?>
<section class="asset-player-head">
  <div><a class="back-link" href="<?= $locationPublicId ? site_url('control/locations/' . rawurlencode($locationPublicId)) : site_url('control/locations') ?>">← Back to Location</a><p class="eyebrow">STUDIO INVENTORY</p><h2><?= esc($device->name) ?></h2><span><?= esc($device->location ?: 'No Location') ?> · <?= esc($device->public_id) ?></span></div>
  <div class="inventory-meta"><strong>Revision <?= (int) $device->inventory_revision ?></strong><small>Last sync: <?= $lastSyncedAt ? esc($lastSyncedAt->format('Y-m-d H:i:s')) . ' UTC' : 'Never' ?></small></div>
</section>
<section class="asset-summary-grid">
  <article class="stat-card"><span>Total assets</span><strong><?= (int) $summary['total'] ?></strong></article>
  <article class="stat-card"><span>Ready</span><strong><?= (int) $summary['ready'] ?></strong></article>
  <article class="stat-card"><span>Missing</span><strong><?= (int) $summary['missing'] ?></strong></article>
  <article class="stat-card"><span>Problems</span><strong><?= (int) $summary['problems'] ?></strong></article>
</section>
<div data-cms-directory data-endpoint="<?= site_url('control/devices/' . rawurlencode($device->public_id) . '/assets/collection') ?>" data-skeleton-variant="row">
<section class="card">
  <form method="get" class="asset-filters" data-cms-directory-filter>
    <label>Search<input type="search" name="q" value="<?= esc($filters['q']) ?>" placeholder="Title, filename, path, or media key"></label>
    <label>Status<select name="status"><option value="">All statuses</option><?php foreach (['ready', 'missing', 'corrupt', 'unreadable'] as $option): ?><option value="<?= $option ?>" <?= $filters['status'] === $option ? 'selected' : '' ?>><?= ucfirst($option) ?></option><?php endforeach ?></select></label>
    <label>Source<select name="source"><option value="">All sources</option><option value="local" <?= $filters['source'] === 'local' ? 'selected' : '' ?>>Media Folder</option><option value="managed" <?= $filters['source'] === 'managed' ? 'selected' : '' ?>>CMS Download</option></select></label>
    <div class="asset-filter-actions"><button class="btn primary" type="submit">Apply filters</button><a class="btn ghost" href="<?= current_url() ?>">Reset</a></div>
  </form>
</section>
<section class="card asset-table-card">
  <div class="card-heading"><div><p>AVAILABLE MEDIA</p><h2>Inventory</h2></div><span class="count" data-cms-directory-count><?= (int) $resultCount ?> results</span></div>
  <div data-cms-directory-collection>
    <div class="asset-inventory-head"><span>Media</span><span>Source</span><span>Duration</span><span>Size</span><span>Status</span><span>Last reported</span></div>
    <div class="asset-inventory-list" data-cms-async-items></div>
    <nav class="cms-async-pagination" data-cms-async-pagination aria-label="Inventory pages"></nav>
  </div>
</section>
</div>
<?= view('web/_layout_bottom') ?>
