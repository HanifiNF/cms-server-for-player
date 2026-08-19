<?= view('web/_layout_top', compact('title', 'active', 'admin')) ?>

<section class="asset-catalog-grid">
  <article class="card asset-upload-card">
    <div class="section-heading"><div><p>MEDIA CATALOG</p><h2>Upload a film</h2></div></div>
    <p class="muted"><?= $isAdmin ? 'Files uploaded by an administrator are immediately active and can be assigned to a Player.' : 'Your upload is stored privately as Draft until an administrator reviews it.' ?></p>
    <form id="assetUploadForm" method="post" action="<?= site_url('control/assets/upload') ?>" enctype="multipart/form-data" class="form-stack">
      <?= csrf_field() ?>
      <label>Media file<input id="assetUploadFile" type="file" name="media" accept="video/*,.mkv,.ts" required></label>
      <label>Title <span class="muted">(optional)</span><input type="text" name="title" maxlength="255" value="<?= esc(old('title')) ?>" placeholder="Uses the filename when empty"></label>
      <label>Poster <span class="muted">(optional, max 10 MB)</span><input type="file" name="poster" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp"></label>
      <label>Synopsis <span class="muted">(optional)</span><textarea name="synopsis" maxlength="5000" rows="4" placeholder="Short film synopsis"><?= esc(old('synopsis')) ?></textarea></label>
      <div class="film-field-pair"><label>Genre<input name="genre" maxlength="120" value="<?= esc(old('genre')) ?>" placeholder="Drama, Action"></label><label>Language<input name="language" maxlength="80" value="<?= esc(old('language')) ?>" placeholder="Indonesian"></label></div>
      <div class="film-field-pair"><label>Subtitles<input name="subtitles" maxlength="160" value="<?= esc(old('subtitles')) ?>" placeholder="English, Indonesian"></label><label>Age rating<select name="age_rating"><option value="">Not specified</option><?php foreach (['SU', '13+', '17+', '21+'] as $rating): ?><option value="<?= $rating ?>" <?= old('age_rating') === $rating ? 'selected' : '' ?>><?= $rating ?></option><?php endforeach ?></select></label></div>
      <div class="film-field-pair"><label>Production year<input type="number" name="production_year" min="1888" max="<?= date('Y') + 2 ?>" value="<?= esc(old('production_year')) ?>" placeholder="<?= date('Y') ?>"></label><label>Release date<input type="date" name="release_date" value="<?= esc(old('release_date')) ?>"></label></div>
      <label>Valid until <span class="muted">(optional, Asia/Jakarta)</span><input type="date" name="expires_on" min="<?= esc($catalogToday) ?>" value="<?= esc(old('expires_on')) ?>"><small class="muted">The film expires after 23:59:59 on this date.</small></label>
      <label>Distributor company<input name="distributor_company" maxlength="180" value="<?= esc(old('distributor_company')) ?>" placeholder="Company or studio name"></label>
      <button id="assetUploadButton" class="btn primary" type="submit">Upload asset</button>
    </form>
    <div id="assetUploadProgress" class="asset-upload-progress" hidden aria-live="polite">
      <div class="upload-progress-head"><strong id="assetUploadStatus">Preparing upload…</strong><span id="assetUploadPercent">0%</span></div>
      <div class="upload-progress-track"><div id="assetUploadFill" class="upload-progress-fill"></div></div>
      <div class="upload-progress-metrics"><span id="assetUploadTransferred">0 B / 0 B</span><span id="assetUploadSpeed">—</span><span id="assetUploadEta">Calculating…</span></div>
      <p id="assetUploadError" class="upload-progress-error"></p>
      <button id="assetUploadCancel" class="btn ghost" type="button">Cancel upload</button>
    </div>
    <small class="upload-note">For large films, raise PHP <code>upload_max_filesize</code> and <code>post_max_size</code> above the largest expected file, and ensure <code>max_input_time</code> allows the full transfer.</small>
  </article>

  <section>
    <div class="asset-catalog-stats">
      <article><span>TOTAL</span><strong><?= (int) $statusCounts['total'] ?></strong></article>
      <article><span>DRAFT</span><strong><?= (int) $statusCounts['draft'] ?></strong></article>
      <article><span>ACTIVE</span><strong><?= (int) $statusCounts['active'] ?></strong></article>
      <article><span>REJECTED</span><strong><?= (int) $statusCounts['rejected'] ?></strong></article>
      <article><span>EXPIRED</span><strong><?= (int) $statusCounts['expired'] ?></strong></article>
    </div>
    <form method="get" action="<?= site_url('control/assets') ?>" class="catalog-filters">
      <label>Search<input type="search" name="q" value="<?= esc($filters['q']) ?>" placeholder="Title, filename, genre, or company"></label>
      <label>Status<select name="status"><option value="">All statuses</option><?php foreach (['draft' => 'Draft', 'active' => 'Active', 'rejected' => 'Rejected', 'expired' => 'Expired'] as $value => $label): ?><option value="<?= $value ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= $label ?></option><?php endforeach ?></select></label>
      <label>Genre<select name="genre"><option value="">All genres</option><?php foreach ($genres as $genre): ?><option value="<?= esc($genre) ?>" <?= $filters['genre'] === $genre ? 'selected' : '' ?>><?= esc($genre) ?></option><?php endforeach ?></select></label>
      <?php if ($isAdmin): ?><label>Distributor<select name="distributor"><option value="0">All distributors</option><?php foreach ($distributors as $distributor): ?><option value="<?= (int) $distributor->id ?>" <?= (int) $filters['distributor'] === (int) $distributor->id ? 'selected' : '' ?>><?= esc($distributor->name) ?></option><?php endforeach ?></select></label><?php endif ?>
      <div class="catalog-filter-actions"><button class="btn primary" type="submit">Apply filters</button><a class="btn ghost" href="<?= site_url('control/assets') ?>">Reset</a></div>
    </form>
    <div class="section-heading"><div><p><?= $isAdmin ? 'REMOTE DISTRIBUTION' : 'DISTRIBUTOR CATALOG' ?></p><h2><?= $isAdmin ? 'All submitted assets' : 'Your submitted films' ?></h2></div><span class="badge"><?= count($assets) ?> assets</span></div>
    <?php if ($assets === []): ?>
      <article class="card empty-state"><strong>No media assets yet</strong><p><?= $isAdmin ? 'Upload the first film to begin distributing it.' : 'Upload your first film for administrator review.' ?></p></article>
    <?php endif ?>
    <div class="catalog-list">
      <?php foreach ($assets as $asset): $assetAssignments = $assignments[(int) $asset->id] ?? []; $assetVersions = $versionHistory[(int) $asset->id] ?? []; $expiresOn = $asset->expires_on ? $asset->expires_on->format('Y-m-d') : null; $expiryDays = $expiresOn ? (int) ((strtotime($expiresOn) - strtotime($catalogToday)) / 86400) : null; ?>
        <article class="card catalog-card">
          <div class="catalog-head">
            <div class="catalog-title-group">
              <?php if ($asset->poster_storage_key): ?><img class="asset-poster" src="<?= site_url('control/assets/' . rawurlencode($asset->public_id) . '/poster') ?>?v=<?= rawurlencode((string) $asset->updated_at) ?>" alt="Poster <?= esc($asset->title) ?>"><?php else: ?><span class="asset-poster placeholder">NO POSTER</span><?php endif ?>
              <div><p>ASSET · REVISION <?= max(1, (int) $asset->revision) ?></p><h3><?= esc($asset->title) ?></h3><small><?= esc($asset->filename) ?></small><?php if ($asset->distributor_company): ?><em><?= esc($asset->distributor_company) ?></em><?php endif ?></div>
            </div>
            <div class="catalog-status-group"><?php if ($asset->encryption_format === 'ldg-v1'): ?><span class="badge ldg-protected">LDG PROTECTED</span><?php else: ?><span class="badge legacy-media">LEGACY MEDIA</span><?php endif ?><span class="badge asset-status <?= esc($asset->status) ?>"><?= esc(strtoupper($asset->status)) ?></span></div>
          </div>
          <dl class="asset-facts">
            <div><dt>Size</dt><dd><?= number_format(((int) $asset->size_bytes) / 1048576, 2) ?> MB</dd></div>
            <div><dt>Duration</dt><dd><?= (int) $asset->duration_ms > 0 ? gmdate('H:i:s', (int) floor(((int) $asset->duration_ms) / 1000)) : 'Detecting…' ?></dd></div>
            <div><dt>SHA-256</dt><dd><code title="<?= esc($asset->sha256) ?>"><?= esc(substr($asset->sha256, 0, 16)) ?>…</code></dd></div>
          </dl>
          <div class="film-metadata-grid">
            <span><small>GENRE</small><strong><?= esc($asset->genre ?: '—') ?></strong></span>
            <span><small>LANGUAGE</small><strong><?= esc($asset->language ?: '—') ?></strong></span>
            <span><small>SUBTITLES</small><strong><?= esc($asset->subtitles ?: '—') ?></strong></span>
            <span><small>AGE RATING</small><strong><?= esc($asset->age_rating ?: '—') ?></strong></span>
            <span><small>PRODUCTION</small><strong><?= $asset->production_year ? (int) $asset->production_year : '—' ?></strong></span>
            <span><small>RELEASE DATE</small><strong><?= $asset->release_date ? esc($asset->release_date->format('Y-m-d')) : '—' ?></strong></span>
            <span><small>VALID UNTIL</small><strong><?= $expiresOn ? esc($expiresOn) : 'No expiry' ?></strong></span>
          </div>
          <?php if ($expiresOn): ?><div class="asset-expiry-notice <?= $asset->status === 'expired' ? 'expired' : ($expiryDays !== null && $expiryDays <= 7 ? 'warning' : '') ?>"><strong><?= $asset->status === 'expired' ? 'Expired on ' . esc($expiresOn) : ($expiryDays === 0 ? 'Valid through today' : ($expiryDays === 1 ? 'Expires tomorrow' : 'Expires in ' . (int) $expiryDays . ' days')) ?></strong><span><?= $asset->status === 'expired' ? 'Player assignments are being removed automatically.' : 'Expiration follows Asia/Jakarta time.' ?></span></div><?php endif ?>
          <?php if ($asset->synopsis): ?><div class="film-synopsis"><small>SYNOPSIS</small><p><?= nl2br(esc($asset->synopsis)) ?></p></div><?php endif ?>
          <div class="asset-review-section">
            <p class="asset-section-label">SUBMISSION DETAILS</p>
            <div class="asset-review-meta">
              <span><small>UPLOADED BY</small><strong><?= esc($userNames[(int) $asset->created_by] ?? 'Unknown account') ?></strong></span>
              <?php if ($asset->reviewed_by !== null): ?><span><small>REVIEWED BY</small><strong><?= esc($userNames[(int) $asset->reviewed_by] ?? 'Administrator') ?></strong></span><?php endif ?>
              <?php if ($asset->reviewed_at !== null): ?><span><small>REVIEWED AT</small><strong><?= esc($asset->reviewed_at->format('Y-m-d H:i')) ?> UTC</strong></span><?php endif ?>
            </div>
          </div>
          <?php if ($assetVersions !== []): ?>
            <details class="asset-version-history"><summary>Revision history <span><?= count($assetVersions) ?> version<?= count($assetVersions) === 1 ? '' : 's' ?></span></summary>
              <div class="version-history-list">
                <?php foreach ($assetVersions as $version): ?>
                  <article>
                    <div class="version-history-head"><strong>Revision <?= (int) $version->revision ?></strong><span class="badge asset-status <?= esc($version->status) ?>"><?= esc(strtoupper($version->status)) ?></span></div>
                    <p><?= esc($version->filename) ?></p>
                    <dl><div><dt>Submitted by</dt><dd><?= esc($userNames[(int) $version->submitted_by] ?? 'Unknown account') ?></dd></div><div><dt>Submitted at</dt><dd><?= esc($version->created_at->format('Y-m-d H:i')) ?> UTC</dd></div><div><dt>Size</dt><dd><?= number_format(((int) $version->size_bytes) / 1048576, 2) ?> MB</dd></div><div><dt>SHA-256</dt><dd><code><?= esc(substr($version->sha256, 0, 12)) ?>…</code></dd></div></dl>
                    <?php if ($version->rejection_reason): ?><div class="version-feedback"><strong>Review feedback</strong><p><?= nl2br(esc($version->rejection_reason)) ?></p></div><?php endif ?>
                  </article>
                <?php endforeach ?>
              </div>
            </details>
          <?php endif ?>
          <?php if ($isAdmin || in_array($asset->status, ['draft', 'rejected'], true)): ?>
            <details class="asset-metadata-editor"><summary>Edit film metadata</summary>
              <form method="post" action="<?= site_url('control/assets/' . rawurlencode($asset->public_id) . '/metadata') ?>" enctype="multipart/form-data" class="metadata-edit-form">
                <?= csrf_field() ?>
                <label>Title<input name="title" maxlength="255" value="<?= esc($asset->title) ?>" required></label>
                <label class="wide">Synopsis<textarea name="synopsis" maxlength="5000" rows="4"><?= esc($asset->synopsis) ?></textarea></label>
                <label>Genre<input name="genre" maxlength="120" value="<?= esc($asset->genre) ?>"></label><label>Language<input name="language" maxlength="80" value="<?= esc($asset->language) ?>"></label>
                <label>Subtitles<input name="subtitles" maxlength="160" value="<?= esc($asset->subtitles) ?>"></label><label>Age rating<select name="age_rating"><option value="">Not specified</option><?php foreach (['SU', '13+', '17+', '21+'] as $rating): ?><option value="<?= $rating ?>" <?= $asset->age_rating === $rating ? 'selected' : '' ?>><?= $rating ?></option><?php endforeach ?></select></label>
                <label>Production year<input type="number" name="production_year" min="1888" max="<?= date('Y') + 2 ?>" value="<?= esc($asset->production_year) ?>"></label><label>Release date<input type="date" name="release_date" value="<?= $asset->release_date ? esc($asset->release_date->format('Y-m-d')) : '' ?>"></label>
                <label>Valid until<input type="date" name="expires_on" min="<?= $asset->status === 'expired' ? '' : esc($catalogToday) ?>" value="<?= $expiresOn ? esc($expiresOn) : '' ?>"></label>
                <label>Distributor company<input name="distributor_company" maxlength="180" value="<?= esc($asset->distributor_company) ?>"></label><label>Replace poster<input type="file" name="poster" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp"></label>
                <div class="wide metadata-edit-action"><button class="btn primary" type="submit">Save metadata</button></div>
              </form>
            </details>
          <?php endif ?>
          <?php if ($asset->status === 'rejected' && $asset->rejection_reason): ?><div class="asset-review-note rejected"><strong>Review feedback</strong><p><?= nl2br(esc($asset->rejection_reason)) ?></p></div><?php endif ?>
          <?php if (! $isAdmin && $asset->status === 'rejected'): ?>
            <div class="asset-correction-zone">
              <div><strong>Submit a corrected revision</strong><p>Update metadata above if needed. Choose a replacement film only when the media file itself changed.</p></div>
              <form method="post" action="<?= site_url('control/assets/' . rawurlencode($asset->public_id) . '/resubmit') ?>" enctype="multipart/form-data" class="asset-correction-form">
                <?= csrf_field() ?>
                <label>Replacement media <span class="muted">(optional)</span><input type="file" name="media" accept="video/*,.mkv,.ts"></label>
                <button class="btn primary" type="submit" onclick="return confirm('Submit this correction as a new Draft revision?')">Submit Revision <?= max(1, (int) $asset->revision) + 1 ?></button>
              </form>
            </div>
          <?php endif ?>
          <?php if ($isAdmin && $asset->status === 'draft'): ?>
            <div class="asset-approval-zone">
              <form method="post" action="<?= site_url('control/assets/' . rawurlencode($asset->public_id) . '/approve') ?>"><?= csrf_field() ?><button class="btn primary" type="submit" onclick="return confirm('Approve this film for Player distribution?')">Approve Film</button></form>
              <form method="post" action="<?= site_url('control/assets/' . rawurlencode($asset->public_id) . '/reject') ?>" class="asset-reject-form"><?= csrf_field() ?><label>Rejection reason<textarea name="rejection_reason" maxlength="1000" required placeholder="Explain what the distributor must correct"></textarea></label><button class="btn danger" type="submit">Reject Film</button></form>
            </div>
          <?php elseif ($isAdmin && $asset->status === 'rejected'): ?><div class="asset-review-note rejected"><strong>Waiting for distributor correction</strong><p>The rejected revision cannot be approved again until the distributor submits a new Draft revision.</p></div>
          <?php elseif (!$isAdmin && $asset->status === 'draft'): ?><div class="asset-review-note"><strong>Waiting for administrator review</strong><p>This film cannot be assigned or downloaded by a Player yet.</p></div><?php endif ?>
          <?php if ($isAdmin && $asset->status === 'active'): ?>
          <form method="post" action="<?= site_url('control/assets/' . rawurlencode($asset->public_id) . '/assign') ?>" class="asset-assign-form">
            <?= csrf_field() ?>
            <label>Assign to Player<select name="device_id" required><option value="">Choose an active Player</option><?php foreach ($devices as $device): $incompatible = $asset->encryption_format === 'ldg-v1' && $device->ldg_version !== 'ldg-v1'; ?><option value="<?= esc($device->public_id) ?>" <?= $incompatible ? 'disabled' : '' ?>><?= esc($device->name) ?><?= $device->location ? ' — ' . esc($device->location) : '' ?><?= $incompatible ? ' — Player update required' : '' ?></option><?php endforeach ?></select></label>
            <button class="btn primary" type="submit" <?= $devices === [] ? 'disabled' : '' ?>>Assign</button>
          </form>
          <?php endif ?>
          <?php if ($isAdmin): ?>
          <div class="assignment-list">
            <?php if ($assetAssignments === []): ?><p class="muted">Not assigned to a Player.</p><?php endif ?>
            <?php foreach ($assetAssignments as $assignment): ?>
              <div><span><strong><?= esc($assignment['device_name']) ?></strong><small class="badge asset-status <?= esc($assignment['status']) ?>"><?= esc(strtoupper(str_replace('_', ' ', $assignment['status']))) ?></small></span>
                <?php if ($assignment['device_public_id'] !== ''): ?><div class="assignment-actions">
                  <form method="post" action="<?= site_url('control/assets/' . rawurlencode($asset->public_id) . '/unassign/' . rawurlencode($assignment['device_public_id'])) ?>"><?= csrf_field() ?><button class="btn ghost" type="submit" onclick="return confirm('Unassign and retain the downloaded file on this Player?')">Unassign</button></form>
                  <form method="post" action="<?= site_url('control/assets/' . rawurlencode($asset->public_id) . '/remove/' . rawurlencode($assignment['device_public_id'])) ?>"><?= csrf_field() ?><button class="btn ghost danger" type="submit" <?= $assignment['status'] === 'removal_pending' ? 'disabled' : '' ?> onclick="return confirm('Request deletion of this film from the Player? It will be removed after playback is safe.')">Unassign &amp; Remove</button></form>
                </div><?php endif ?>
              </div>
            <?php endforeach ?>
          </div>
          <div class="catalog-delete-zone">
            <p><strong>Delete from CMS</strong><small>Permanently removes the uploaded file and database record. All Player assignments must be cleared first.</small></p>
            <form method="post" action="<?= site_url('control/assets/' . rawurlencode($asset->public_id) . '/delete') ?>"><?= csrf_field() ?><button class="btn danger" type="submit" <?= $assetAssignments !== [] ? 'disabled title="Clear all Player assignments first"' : '' ?> onclick="return confirm('Permanently delete this uploaded file and its database record? This cannot be undone.')">Delete Asset</button></form>
          </div>
          <?php endif ?>
        </article>
      <?php endforeach ?>
    </div>
  </section>
</section>

<script>
(() => {
  const form = document.getElementById('assetUploadForm');
  if (!form || !window.XMLHttpRequest || !window.FormData) return;
  const fileInput = document.getElementById('assetUploadFile');
  const submitButton = document.getElementById('assetUploadButton');
  const panel = document.getElementById('assetUploadProgress');
  const status = document.getElementById('assetUploadStatus');
  const percent = document.getElementById('assetUploadPercent');
  const fill = document.getElementById('assetUploadFill');
  const transferred = document.getElementById('assetUploadTransferred');
  const speed = document.getElementById('assetUploadSpeed');
  const eta = document.getElementById('assetUploadEta');
  const error = document.getElementById('assetUploadError');
  const cancel = document.getElementById('assetUploadCancel');
  let request = null;

  const formatBytes = bytes => {
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = Math.max(0, Number(bytes) || 0); let unit = 0;
    while (value >= 1024 && unit < units.length - 1) { value /= 1024; unit += 1; }
    return `${value.toFixed(unit >= 3 ? 2 : unit > 0 ? 1 : 0)} ${units[unit]}`;
  };
  const formatEta = seconds => {
    if (!Number.isFinite(seconds) || seconds < 0) return 'Calculating…';
    const rounded = Math.ceil(seconds);
    if (rounded < 60) return `${rounded}s remaining`;
    const minutes = Math.floor(rounded / 60); const rest = rounded % 60;
    return `${minutes}m ${rest}s remaining`;
  };
  const setControlsDisabled = disabled => {
    form.querySelectorAll('input, button').forEach(control => { control.disabled = disabled; });
    cancel.disabled = !disabled;
  };
  const restoreCsrf = payload => {
    if (!payload || !payload.csrf) return;
    const token = form.querySelector('input[name="' + CSS.escape(payload.csrf.name) + '"]');
    if (token) token.value = payload.csrf.hash;
  };
  const fail = message => {
    status.textContent = 'Upload failed'; error.textContent = message;
    fill.classList.remove('processing'); cancel.hidden = true;
    setControlsDisabled(false); cancel.disabled = true; submitButton.textContent = 'Try upload again';
    request = null;
  };

  form.addEventListener('submit', event => {
    event.preventDefault();
    if (request || !fileInput.files.length) return;
    const startedAt = performance.now();
    const data = new FormData(form);
    request = new XMLHttpRequest();
    panel.hidden = false; cancel.hidden = false; error.textContent = '';
    status.textContent = 'Uploading film…'; percent.textContent = '0%'; fill.style.width = '0%';
    fill.classList.remove('processing'); transferred.textContent = `0 B / ${formatBytes(fileInput.files[0].size)}`;
    speed.textContent = 'Calculating speed…'; eta.textContent = 'Calculating…'; submitButton.textContent = 'Uploading…';
    setControlsDisabled(true); cancel.disabled = false;
    request.open('POST', form.action, true);
    request.responseType = 'json';
    request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
    request.setRequestHeader('Accept', 'application/json');
    request.upload.onprogress = progress => {
      if (!progress.lengthComputable) return;
      const elapsed = Math.max(.001, (performance.now() - startedAt) / 1000);
      const bytesPerSecond = progress.loaded / elapsed;
      const value = Math.min(100, Math.round(progress.loaded / progress.total * 100));
      percent.textContent = `${value}%`; fill.style.width = `${value}%`;
      transferred.textContent = `${formatBytes(progress.loaded)} / ${formatBytes(progress.total)}`;
      speed.textContent = `${formatBytes(bytesPerSecond)}/s`;
      eta.textContent = formatEta((progress.total - progress.loaded) / bytesPerSecond);
    };
    request.upload.onload = () => {
      status.textContent = 'Encrypting media as LDG…'; percent.textContent = '100%'; fill.style.width = '100%';
      fill.classList.add('processing'); eta.textContent = 'Detecting duration, encrypting chunks, and verifying SHA-256…'; cancel.disabled = true;
    };
    request.onload = () => {
      const payload = request.response || {};
      restoreCsrf(payload);
      if (request.status < 200 || request.status >= 300) {
        fail(payload.error && payload.error.message || `Server rejected the upload (HTTP ${request.status}). Check PHP upload limits.`);
        return;
      }
      status.textContent = 'Upload complete'; percent.textContent = '100%'; fill.classList.remove('processing');
      speed.textContent = 'Saved securely'; eta.textContent = payload.data && payload.data.message || 'Asset is ready in the catalog.';
      cancel.hidden = true; request = null;
      window.setTimeout(() => window.location.reload(), 700);
    };
    request.onerror = () => fail('The connection was interrupted. The film was not added to the catalog.');
    request.onabort = () => fail('Upload cancelled. No asset was added.');
    request.send(data);
  });
  cancel.addEventListener('click', () => { if (request) request.abort(); });
})();
</script>

<?= view('web/_layout_bottom') ?>
