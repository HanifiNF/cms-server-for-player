<?php
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
