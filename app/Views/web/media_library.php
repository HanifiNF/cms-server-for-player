<?= view('web/_layout_top', get_defined_vars()) ?>

<section class="library-hero">
  <div><p>CATALOG INTELLIGENCE</p><h2>Media Library</h2><span><?= $isAdmin ? 'Review, organize, and distribute every submitted film.' : 'Upload films and follow their review and distribution status.' ?></span></div>
  <div class="cms-toolbar-actions"><?php if ($isAdmin): ?><button class="btn ghost" type="button" data-cms-modal-open="genre-manager-modal">Manage genre options</button><?php endif ?><button class="btn primary" type="button" data-open-asset-upload>+ Add Asset</button></div>
</section>

<section class="library-status-grid" aria-label="Asset status summary">
  <?php foreach (['total' => 'Total', 'draft' => 'Draft', 'active' => 'Active', 'rejected' => 'Rejected', 'expired' => 'Expired'] as $value => $label): ?>
    <a class="library-status-card <?= ($filters['status'] === $value || ($value === 'total' && $filters['status'] === '')) ? 'selected' : '' ?>" href="<?= site_url('control/library' . ($value === 'total' ? '' : '?status=' . $value)) ?>"><span><?= esc(strtoupper($label)) ?></span><strong><?= (int) $statusCounts[$value] ?></strong></a>
  <?php endforeach ?>
</section>

<section class="card library-filter-card">
  <form method="get" action="<?= site_url('control/library') ?>" class="library-filters <?= $isAdmin ? 'with-distributor' : '' ?>">
    <label>Search<input type="search" name="q" value="<?= esc($filters['search']) ?>" placeholder="Title, file, distributor, or genre"></label>
    <label>Type<select name="type"><option value="">All types</option><?php foreach ($assetTypes as $type): ?><option value="<?= esc($type) ?>" <?= $filters['type'] === $type ? 'selected' : '' ?>><?= esc(ucfirst($type)) ?></option><?php endforeach ?></select></label>
    <label>Genre<select name="genre"><option value="">All genres</option><?php foreach ($genres as $genre): ?><option value="<?= (int) $genre->id ?>" <?= $filters['genre'] === (string) $genre->id ? 'selected' : '' ?>><?= esc($genre->name) ?></option><?php endforeach ?></select></label>
    <label>Status<select name="status"><option value="">All statuses</option><?php foreach (['draft','active','rejected','expired'] as $value): ?><option value="<?= $value ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= esc(ucfirst($value)) ?></option><?php endforeach ?></select></label>
    <label>Availability<select name="availability"><option value="">Any availability</option><option value="available" <?= $filters['availability'] === 'available' ? 'selected' : '' ?>>Available on Studio</option><option value="unassigned" <?= $filters['availability'] === 'unassigned' ? 'selected' : '' ?>>Not assigned</option></select></label>
    <?php if ($isAdmin): ?><label>Distributor<select name="distributor"><option value="0">All distributors</option><?php foreach ($distributors as $distributor): ?><option value="<?= (int) $distributor->id ?>" <?= (int) $filters['distributor'] === (int) $distributor->id ? 'selected' : '' ?>><?= esc($distributor->name) ?></option><?php endforeach ?></select></label><?php endif ?>
    <div class="library-filter-actions"><button class="btn primary" type="submit">Apply</button><a class="btn ghost" href="<?= site_url('control/library') ?>">Reset</a></div>
  </form>
</section>

<div class="section-heading"><div><p>ALL MEDIA</p><h2>Catalog</h2></div><span class="badge"><?= count($assets) ?> results</span></div>
<?php if ($assets === []): ?>
  <article class="card empty-state"><strong>No matching media</strong><p>Change the filters or add a new asset to the library.</p></article>
<?php else: ?>
<section class="library-layered-list">
<?php foreach ($assets as $asset):
  $assetGenres = $genreMap[(int) $asset->id] ?? [];
  $studioCount = $assignmentCounts[(int) $asset->id] ?? 0;
  $locationCount = $locationCounts[(int) $asset->id] ?? 0;
  $scheduleCount = $scheduleCounts[(int) $asset->id] ?? 0;
  $seconds = max(0, intdiv((int) $asset->duration_ms, 1000));
  $runtimeParts = [];
  if ($seconds >= 3600) $runtimeParts[] = intdiv($seconds, 3600) . 'h';
  if ($seconds >= 60) $runtimeParts[] = intdiv($seconds % 3600, 60) . 'm';
  $runtimeParts[] = ($seconds % 60) . 's';
  $availabilityText = $studioCount === 0 ? 'Not distributed yet' : 'Available in ' . $locationCount . ' of ' . $totalActiveLocations . ' Locations';
?>
<article class="library-layered-card">
  <div class="library-card-layer identity-layer">
    <a class="layered-poster" href="<?= site_url('control/library/' . rawurlencode($asset->public_id)) ?>"><?php if ($asset->poster_storage_key): ?><img src="<?= site_url('control/assets/' . rawurlencode($asset->public_id) . '/poster') ?>?v=<?= rawurlencode((string) $asset->updated_at) ?>" alt="Poster <?= esc($asset->title) ?>"><?php else: ?><span>NO<br>POSTER</span><?php endif ?></a>
    <div class="layered-identity"><div class="layered-badges"><span class="layered-chip type <?= esc($asset->asset_type ?: 'featured') ?>"><?= esc(strtoupper($asset->asset_type ?: 'featured')) ?></span><span class="layered-chip status <?= esc($asset->status) ?>"><?= esc(strtoupper($asset->status)) ?></span></div><h3><?= esc($asset->title) ?></h3><p title="<?= esc($asset->filename) ?>"><?= esc($asset->filename) ?></p><div class="layered-genres"><?php if ($assetGenres === []): ?><span>Uncategorized</span><?php endif ?><?php foreach (array_slice($assetGenres, 0, 3) as $genre): ?><span><?= esc($genre['name']) ?></span><?php endforeach ?><?php if (count($assetGenres) > 3): ?><span>+<?= count($assetGenres) - 3 ?></span><?php endif ?></div></div>
    <div class="layered-side-actions"><span class="layered-security <?= $asset->encryption_format === 'ldg-v1' ? 'protected' : 'legacy' ?>"><?= $asset->encryption_format === 'ldg-v1' ? '◆ LDG PROTECTED' : '◇ LEGACY MEDIA' ?></span><a href="<?= site_url('control/library/' . rawurlencode($asset->public_id)) ?>">Manage</a></div>
  </div>
  <dl class="library-card-layer data-layer"><div><dt>Runtime</dt><dd><?= esc(implode(' ', $runtimeParts)) ?></dd></div><div><dt>File size</dt><dd><?= number_format(((int) $asset->size_bytes) / 1048576, 2) ?> MB</dd></div><div><dt>Studios</dt><dd><?= $studioCount ?></dd></div><div><dt>Schedules</dt><dd><?= $scheduleCount ?></dd></div><div><dt>Expires</dt><dd><?= $asset->expires_on ? esc($asset->expires_on->format('Y-m-d')) : 'Never' ?></dd></div></dl>
  <div class="library-card-layer action-layer"><span class="layered-availability"><i></i><span><strong><?= esc($availabilityText) ?></strong><small><?= $locationCount ?> Location<?= $locationCount === 1 ? '' : 's' ?> · <?= $studioCount ?> Studio<?= $studioCount === 1 ? '' : 's' ?></small></span></span><a class="btn layered-detail-button" href="<?= site_url('control/library/' . rawurlencode($asset->public_id)) ?>">View details <span aria-hidden="true">→</span></a></div>
</article>
<?php endforeach ?>
</section>
<?php endif ?>

<div class="library-modal" data-asset-upload-modal hidden>
  <div class="library-modal-backdrop" data-close-asset-upload></div>
  <section class="library-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="asset-upload-title">
    <header><div><p>MEDIA CATALOG</p><h2 id="asset-upload-title">Add Asset</h2><span><?= $isAdmin ? 'Administrator uploads are immediately active.' : 'Distributor uploads remain Draft until reviewed.' ?></span></div><button type="button" class="library-modal-close" aria-label="Close upload form" data-close-asset-upload>×</button></header>
    <div class="library-modal-body"><?= view('web/_asset_upload_form', get_defined_vars()) ?></div>
  </section>
</div>

<?php if ($isAdmin): ?>
<dialog class="cms-action-modal" id="genre-manager-modal" data-cms-modal <?= session('modal') === 'genre-manager-modal' ? 'data-auto-open="true"' : '' ?>>
  <div class="cms-modal-shell"><header class="cms-modal-header"><div><p>TAXONOMY</p><h2>Manage Genres</h2><span>Create catalog filters and control which options appear in film forms.</span></div><button class="cms-modal-x" type="button" data-cms-modal-close>×</button></header><div class="cms-modal-body"><form method="post" action="<?= site_url('control/genres') ?>" class="genre-create-form"><?= csrf_field() ?><input type="hidden" name="_modal_context" value="genre-manager-modal"><input name="name" maxlength="80" placeholder="New genre name" required autofocus><button class="btn primary" type="submit">Add Genre</button></form><div class="genre-admin-list"><?php foreach ($genres as $genre): ?><div><span><strong><?= esc($genre->name) ?></strong><small><?= esc(strtoupper($genre->status)) ?></small></span><form method="post" action="<?= site_url('control/genres/' . rawurlencode($genre->public_id) . '/status') ?>"><?= csrf_field() ?><input type="hidden" name="status" value="<?= $genre->status === 'active' ? 'inactive' : 'active' ?>"><button class="btn ghost" type="submit"><?= $genre->status === 'active' ? 'Disable' : 'Enable' ?></button></form></div><?php endforeach ?></div></div><footer class="cms-modal-footer"><span>Disabled genres remain attached to existing films.</span><div><button class="btn primary" type="button" data-cms-modal-close>Done</button></div></footer></div>
</dialog>
<?php endif ?>

<?= view('web/_layout_bottom') ?>
