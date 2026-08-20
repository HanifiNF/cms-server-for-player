<?= view('web/_layout_top', get_defined_vars()) ?>
<?php
$formatBytes = static function (int $bytes): string {
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $power = min((int) floor(log($bytes, 1024)), count($units) - 1);
    return number_format($bytes / (1024 ** $power), $power >= 3 ? 1 : 0) . ' ' . $units[$power];
};
$formatDuration = static function (int $milliseconds): string {
    if ($milliseconds <= 0) return 'Unknown';
    $seconds = (int) floor($milliseconds / 1000);
    return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
};
?>
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
<section class="card">
  <form method="get" class="asset-filters">
    <label>Search<input type="search" name="q" value="<?= esc($filters['q']) ?>" placeholder="Title, filename, path, or media key"></label>
    <label>Status<select name="status"><option value="">All statuses</option><?php foreach (['ready', 'missing', 'corrupt', 'unreadable'] as $option): ?><option value="<?= $option ?>" <?= $filters['status'] === $option ? 'selected' : '' ?>><?= ucfirst($option) ?></option><?php endforeach ?></select></label>
    <label>Source<select name="source"><option value="">All sources</option><option value="local" <?= $filters['source'] === 'local' ? 'selected' : '' ?>>Media Folder</option><option value="managed" <?= $filters['source'] === 'managed' ? 'selected' : '' ?>>CMS Download</option></select></label>
    <div class="asset-filter-actions"><button class="btn primary" type="submit">Apply filters</button><a class="btn ghost" href="<?= current_url() ?>">Reset</a></div>
  </form>
</section>
<section class="card asset-table-card">
  <div class="card-heading"><div><p>AVAILABLE MEDIA</p><h2>Inventory</h2></div><span class="count"><?= (int) $resultCount ?> results</span></div>
  <?php if ($assets === []): ?><div class="empty">No assets match this view. Start the Player and refresh its asset inventory.</div><?php else: ?>
  <div class="asset-table-scroll"><table class="asset-table"><thead><tr><th>Media</th><th>Source</th><th>Duration</th><th>Size</th><th>Status</th><th>Last reported</th></tr></thead><tbody>
    <?php foreach ($assets as $asset): ?><tr>
      <td><strong><?= esc($asset->title) ?></strong><small><?= esc($asset->relative_path ?: $asset->filename) ?></small><code title="<?= esc($asset->media_key) ?>"><?= esc($asset->media_key) ?></code></td>
      <td><?= $asset->source === 'managed' ? 'CMS Download' : 'Media Folder' ?></td>
      <td><?= esc($formatDuration((int) $asset->duration_ms)) ?></td>
      <td><?= esc($formatBytes((int) $asset->size_bytes)) ?></td>
      <td><span class="badge asset-status <?= esc($asset->status) ?>"><?= esc(strtoupper($asset->status)) ?></span></td>
      <td><?= $asset->last_reported_at ? esc($asset->last_reported_at->format('Y-m-d H:i:s')) . ' UTC' : 'Never' ?></td>
    </tr><?php endforeach ?>
  </tbody></table></div>
  <?php if ($resultCount > 1000): ?><p class="muted">Showing the first 1,000 results. Narrow the search to find additional assets.</p><?php endif ?>
  <?php endif ?>
</section>
<?= view('web/_layout_bottom') ?>
