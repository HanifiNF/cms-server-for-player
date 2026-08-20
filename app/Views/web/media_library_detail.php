<?= view('web/_layout_top', get_defined_vars()) ?>
<?php
$currentGenreIds = array_map('intval', array_column($genres, 'id'));
$expiresOn = $asset->expires_on ? $asset->expires_on->format('Y-m-d') : null;
$canEdit = $isAdmin || in_array($asset->status, ['draft', 'rejected'], true);
?>

<a class="back-link" href="<?= site_url('control/library') ?>">← Back to Media Library</a>

<section class="card library-detail-hero">
  <div class="library-detail-poster"><?php if ($asset->poster_storage_key): ?><img src="<?= site_url('control/assets/' . rawurlencode($asset->public_id) . '/poster') ?>?v=<?= rawurlencode((string) $asset->updated_at) ?>" alt="Poster <?= esc($asset->title) ?>"><?php else: ?><span>NO POSTER</span><?php endif ?></div>
  <div class="library-detail-main">
    <div class="catalog-status-group"><span class="badge asset-type <?= esc($asset->asset_type ?: 'featured') ?>"><?= esc(strtoupper($asset->asset_type ?: 'featured')) ?></span><span class="badge asset-status <?= esc($asset->status) ?>"><?= esc(strtoupper($asset->status)) ?></span><span class="badge <?= $asset->encryption_format === 'ldg-v1' ? 'ldg-protected' : 'legacy-media' ?>"><?= $asset->encryption_format === 'ldg-v1' ? 'LDG PROTECTED' : 'LEGACY MEDIA' ?></span></div>
    <h2><?= esc($asset->title) ?></h2><p class="library-file"><?= esc($asset->filename) ?></p>
    <div class="genre-chips"><?php if ($genres === []): ?><span>Uncategorized</span><?php endif ?><?php foreach ($genres as $genre): ?><span><?= esc($genre['name']) ?></span><?php endforeach ?></div>
    <?php if ($asset->synopsis): ?><p class="library-synopsis"><?= nl2br(esc($asset->synopsis)) ?></p><?php endif ?>
    <dl class="library-detail-facts"><div><dt>Duration</dt><dd><?= $asset->duration_ms > 0 ? gmdate('H:i:s', intdiv((int) $asset->duration_ms, 1000)) : 'Unknown' ?></dd></div><div><dt>File size</dt><dd><?= number_format(((int) $asset->size_bytes) / 1048576, 2) ?> MB</dd></div><div><dt>Language</dt><dd><?= esc($asset->language ?: '—') ?></dd></div><div><dt>Subtitles</dt><dd><?= esc($asset->subtitles ?: '—') ?></dd></div><div><dt>Age rating</dt><dd><?= esc($asset->age_rating ?: '—') ?></dd></div><div><dt>Production</dt><dd><?= $asset->production_year ? (int) $asset->production_year : '—' ?></dd></div><div><dt>Release date</dt><dd><?= $asset->release_date ? esc($asset->release_date->format('Y-m-d')) : '—' ?></dd></div><div><dt>Valid until</dt><dd><?= $expiresOn ? esc($expiresOn) : 'No expiry' ?></dd></div><div><dt>Distributor</dt><dd><?= esc($asset->distributor_company ?: '—') ?></dd></div><div><dt>Uploaded by</dt><dd><?= esc($userNames[(int) $asset->created_by] ?? 'Unknown') ?></dd></div></dl>
  </div>
</section>

<?php if ($canEdit): ?>
<details class="card library-management-section metadata-section">
  <summary><span><small>METADATA</small><strong>Edit film information</strong></span><i>Expand</i></summary>
  <form method="post" action="<?= site_url('control/assets/' . rawurlencode($asset->public_id) . '/metadata') ?>" enctype="multipart/form-data" class="metadata-edit-form library-detail-form">
    <?= csrf_field() ?>
    <label>Title<input name="title" maxlength="255" value="<?= esc($asset->title) ?>" required></label>
    <label>Asset type<select name="asset_type" required><?php foreach ($assetTypes as $type): ?><option value="<?= esc($type) ?>" <?= ($asset->asset_type ?: 'featured') === $type ? 'selected' : '' ?>><?= esc(ucfirst($type)) ?></option><?php endforeach ?></select></label>
    <label class="wide">Synopsis<textarea name="synopsis" maxlength="5000" rows="4"><?= esc($asset->synopsis) ?></textarea></label>
    <input type="hidden" name="genres_present" value="1"><div class="film-control-group"><span class="field-label">Genres <span class="muted">(multiple)</span></span><?= view('web/_genre_multiselect', ['activeGenres' => $allGenres, 'selectedGenreIds' => $currentGenreIds, 'genreInputId' => 'detail-genres']) ?></div>
    <label>Language<input name="language" maxlength="80" value="<?= esc($asset->language) ?>"></label>
    <label>Subtitles<input name="subtitles" maxlength="160" value="<?= esc($asset->subtitles) ?>"></label><label>Age rating<select name="age_rating"><option value="">Not specified</option><?php foreach (['SU', '13+', '17+', '21+'] as $rating): ?><option value="<?= $rating ?>" <?= $asset->age_rating === $rating ? 'selected' : '' ?>><?= $rating ?></option><?php endforeach ?></select></label>
    <label>Production year<input type="number" name="production_year" min="1888" max="<?= date('Y') + 2 ?>" value="<?= esc($asset->production_year) ?>"></label><label>Release date<input type="date" name="release_date" value="<?= $asset->release_date ? esc($asset->release_date->format('Y-m-d')) : '' ?>"></label>
    <label>Valid until<input type="date" name="expires_on" min="<?= $asset->status === 'expired' ? '' : esc($catalogToday) ?>" value="<?= $expiresOn ? esc($expiresOn) : '' ?>"></label><label>Distributor company<input name="distributor_company" maxlength="180" value="<?= esc($asset->distributor_company) ?>"></label>
    <label>Replace poster<input type="file" name="poster" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp"></label>
    <div class="wide metadata-edit-action"><button class="btn primary" type="submit">Save metadata</button></div>
  </form>
</details>
<?php endif ?>

<section class="library-detail-columns management-columns">
  <article class="card security-card">
    <div class="card-heading"><div><p>SECURITY &amp; INTEGRITY</p><h2>Protected media details</h2></div><span class="badge <?= $asset->encryption_format === 'ldg-v1' ? 'ldg-protected' : 'legacy-media' ?>"><?= esc(strtoupper($asset->encryption_format ?: 'legacy')) ?></span></div>
    <dl class="security-facts"><div><dt>Encrypted SHA-256</dt><dd><code title="<?= esc($asset->sha256) ?>"><?= esc($asset->sha256 ?: '—') ?></code></dd></div><div><dt>Plaintext SHA-256</dt><dd><code title="<?= esc($asset->plaintext_sha256) ?>"><?= esc($asset->plaintext_sha256 ?: '—') ?></code></dd></div><div><dt>Plaintext size</dt><dd><?= $asset->plaintext_size_bytes ? number_format(((int) $asset->plaintext_size_bytes) / 1048576, 2) . ' MB' : '—' ?></dd></div><div><dt>LDG chunk size</dt><dd><?= $asset->ldg_chunk_size ? number_format((int) $asset->ldg_chunk_size) . ' bytes' : '—' ?></dd></div><div><dt>Key version</dt><dd><?= esc($asset->key_version ?: '—') ?></dd></div><div><dt>Encryption revision</dt><dd><?= (int) ($asset->encryption_revision ?: 0) ?></dd></div><div><dt>Storage key</dt><dd><code><?= esc($asset->storage_key) ?></code></dd></div><div><dt>Manifest</dt><dd><span class="integrity-valid">● Metadata recorded</span></dd></div></dl>
  </article>

  <article class="card review-card">
    <div class="card-heading"><div><p>REVIEW WORKFLOW</p><h2>Approval status</h2></div><span class="badge asset-status <?= esc($asset->status) ?>"><?= esc(strtoupper($asset->status)) ?></span></div>
    <div class="review-audit"><span><small>SUBMITTED BY</small><strong><?= esc($userNames[(int) $asset->created_by] ?? 'Unknown') ?></strong></span><?php if ($asset->reviewed_by): ?><span><small>REVIEWED BY</small><strong><?= esc($userNames[(int) $asset->reviewed_by] ?? 'Administrator') ?></strong></span><?php endif ?><?php if ($asset->reviewed_at): ?><span><small>REVIEWED AT</small><strong><?= esc($asset->reviewed_at->format('Y-m-d H:i')) ?> UTC</strong></span><?php endif ?></div>
    <?php if ($asset->rejection_reason): ?><div class="review-feedback"><strong>Review feedback</strong><p><?= nl2br(esc($asset->rejection_reason)) ?></p></div><?php endif ?>
    <?php if ($isAdmin && $asset->status === 'draft'): ?><div class="detail-review-actions"><form method="post" action="<?= site_url('control/assets/' . rawurlencode($asset->public_id) . '/approve') ?>"><?= csrf_field() ?><button class="btn primary" type="submit" onclick="return confirm('Approve this film for Studio distribution?')">Approve Film</button></form><form method="post" action="<?= site_url('control/assets/' . rawurlencode($asset->public_id) . '/reject') ?>" class="asset-reject-form"><?= csrf_field() ?><label>Rejection reason<textarea name="rejection_reason" maxlength="1000" required placeholder="Explain what the distributor must correct"></textarea></label><button class="btn danger" type="submit">Reject Film</button></form></div>
    <?php elseif (! $isAdmin && $asset->status === 'rejected'): ?><form method="post" action="<?= site_url('control/assets/' . rawurlencode($asset->public_id) . '/resubmit') ?>" enctype="multipart/form-data" class="asset-correction-form detail-resubmit"><?= csrf_field() ?><label>Replacement media <span class="muted">(optional)</span><input type="file" name="media" accept="video/*,.mkv,.ts"></label><button class="btn primary" type="submit" onclick="return confirm('Submit this correction as a new Draft revision?')">Submit Revision <?= max(1, (int) $asset->revision) + 1 ?></button></form>
    <?php elseif ($asset->status === 'draft'): ?><p class="muted detail-state-copy">Waiting for administrator review. This film cannot be distributed yet.</p><?php endif ?>
  </article>
</section>

<section class="card library-management-section">
  <div class="card-heading"><div><p>DISTRIBUTION</p><h2>Assigned Studios</h2></div><span class="count"><?= count($assignments) ?> Studios</span></div>
  <?php if ($isAdmin && $asset->status === 'active'): ?><form method="post" action="<?= site_url('control/assets/' . rawurlencode($asset->public_id) . '/assign') ?>" class="asset-assign-form detail-assign-form"><?= csrf_field() ?><label>Assign to Studio<select name="device_id" required><option value="">Choose an active Studio</option><?php foreach ($devices as $device): $incompatible = $asset->encryption_format === 'ldg-v1' && $device->ldg_version !== 'ldg-v1'; ?><option value="<?= esc($device->public_id) ?>" <?= $incompatible ? 'disabled' : '' ?>><?= $device->location ? esc($device->location) . ' — ' : '' ?><?= esc($device->name) ?><?= $incompatible ? ' — Player update required' : '' ?></option><?php endforeach ?></select></label><button class="btn primary" type="submit" <?= $devices === [] ? 'disabled' : '' ?>>Assign</button></form><?php endif ?>
  <?php if ($assignments === []): ?><div class="empty">This media is not assigned to any Studio.</div><?php else: ?><div class="detail-assignment-list"><?php foreach ($assignments as $row): ?><div><span><strong><?= esc($row['device_name']) ?></strong><small><?= esc($row['location_name'] ?: 'No Location') ?> · <?= esc(strtoupper($row['device_status'])) ?></small></span><span class="badge asset-status <?= esc($row['status']) ?>"><?= esc(strtoupper(str_replace('_', ' ', $row['status']))) ?></span><?php if ($isAdmin): ?><div class="assignment-actions"><form method="post" action="<?= site_url('control/assets/' . rawurlencode($asset->public_id) . '/unassign/' . rawurlencode($row['device_public_id'])) ?>"><?= csrf_field() ?><button class="btn ghost" type="submit" onclick="return confirm('Unassign and retain the downloaded file on this Player?')">Unassign</button></form><form method="post" action="<?= site_url('control/assets/' . rawurlencode($asset->public_id) . '/remove/' . rawurlencode($row['device_public_id'])) ?>"><?= csrf_field() ?><button class="btn ghost danger" type="submit" <?= $row['status'] === 'removal_pending' ? 'disabled' : '' ?> onclick="return confirm('Request deletion of this film from the Player?')">Unassign &amp; Remove</button></form></div><?php endif ?></div><?php endforeach ?></div><?php endif ?>
</section>

<section class="library-detail-columns management-columns">
  <article class="card"><div class="card-heading"><div><p>REVISION HISTORY</p><h2>Submitted versions</h2></div><span class="count"><?= count($versions) ?> Versions</span></div><?php if ($versions === []): ?><div class="empty">No revision history recorded.</div><?php else: ?><div class="detail-version-list"><?php foreach ($versions as $version): ?><article><div><strong>Revision <?= (int) $version->revision ?></strong><span class="badge asset-status <?= esc($version->status) ?>"><?= esc(strtoupper($version->status)) ?></span></div><p><?= esc($version->filename) ?></p><dl><span><dt>Submitted</dt><dd><?= esc($userNames[(int) $version->submitted_by] ?? 'Unknown') ?></dd></span><span><dt>Size</dt><dd><?= number_format(((int) $version->size_bytes) / 1048576, 2) ?> MB</dd></span><span><dt>SHA-256</dt><dd><code title="<?= esc($version->sha256) ?>"><?= esc(substr((string) $version->sha256, 0, 14)) ?>…</code></dd></span></dl><?php if ($version->rejection_reason): ?><small><?= esc($version->rejection_reason) ?></small><?php endif ?></article><?php endforeach ?></div><?php endif ?></article>
  <article class="card"><div class="card-heading"><div><p>SCHEDULE USAGE</p><h2>Schedules using this media</h2></div><span class="count"><?= count($schedules) ?> Schedules</span></div><?php if ($schedules === []): ?><div class="empty">This media is not used in a schedule.</div><?php else: ?><div class="library-detail-list"><?php foreach ($schedules as $row): ?><div><span><strong><?= esc($row['title']) ?></strong><small><?= esc($row['device_name']) ?><?= $row['location_name'] ? ' · ' . esc($row['location_name']) : '' ?> · <?= esc(ucfirst($row['recurrence'])) ?></small></span><span class="badge <?= esc($row['status']) ?>"><?= esc(strtoupper($row['status'])) ?></span></div><?php endforeach ?></div><?php endif ?></article>
</section>

<?php if ($isAdmin): ?>
<section class="card detail-danger-zone"><div><p>DANGER ZONE</p><h2>Delete asset from CMS</h2><span>Permanently removes the encrypted file, poster, revisions, and database record. Assignments and schedule references must be cleared first.</span></div><form method="post" action="<?= site_url('control/assets/' . rawurlencode($asset->public_id) . '/delete') ?>"><?= csrf_field() ?><button class="btn danger" type="submit" <?= $assignments !== [] || $schedules !== [] ? 'disabled title="Clear assignments and schedule references first"' : '' ?> onclick="return confirm('Permanently delete this asset? This cannot be undone.')">Delete Asset</button></form></section>
<?php endif ?>

<?= view('web/_layout_bottom') ?>
