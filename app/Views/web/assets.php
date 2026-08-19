<?= view('web/_layout_top', compact('title', 'active', 'admin')) ?>

<section class="asset-catalog-grid">
  <article class="card asset-upload-card">
    <div class="section-heading"><div><p>MEDIA CATALOG</p><h2>Upload a film</h2></div></div>
    <p class="muted"><?= $isAdmin ? 'Files uploaded by an administrator are immediately active and can be assigned to a Player.' : 'Your upload is stored privately as Draft until an administrator reviews it.' ?></p>
    <form id="assetUploadForm" method="post" action="<?= site_url('control/assets/upload') ?>" enctype="multipart/form-data" class="form-stack">
      <?= csrf_field() ?>
      <label>Media file<input id="assetUploadFile" type="file" name="media" accept="video/*,.mkv,.ts" required></label>
      <label>Title <span class="muted">(optional)</span><input type="text" name="title" maxlength="255" value="<?= esc(old('title')) ?>" placeholder="Uses the filename when empty"></label>
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
    <div class="section-heading"><div><p><?= $isAdmin ? 'REMOTE DISTRIBUTION' : 'DISTRIBUTOR CATALOG' ?></p><h2><?= $isAdmin ? 'All submitted assets' : 'Your submitted films' ?></h2></div><span class="badge"><?= count($assets) ?> assets</span></div>
    <?php if ($assets === []): ?>
      <article class="card empty-state"><strong>No media assets yet</strong><p><?= $isAdmin ? 'Upload the first film to begin distributing it.' : 'Upload your first film for administrator review.' ?></p></article>
    <?php endif ?>
    <div class="catalog-list">
      <?php foreach ($assets as $asset): $assetAssignments = $assignments[(int) $asset->id] ?? []; ?>
        <article class="card catalog-card">
          <div class="catalog-head">
            <div><p>ASSET</p><h3><?= esc($asset->title) ?></h3><small><?= esc($asset->filename) ?></small></div>
            <span class="badge asset-status <?= esc($asset->status) ?>"><?= esc(strtoupper($asset->status)) ?></span>
          </div>
          <dl class="asset-facts">
            <div><dt>Size</dt><dd><?= number_format(((int) $asset->size_bytes) / 1048576, 2) ?> MB</dd></div>
            <div><dt>Duration</dt><dd><?= (int) $asset->duration_ms > 0 ? gmdate('H:i:s', (int) floor(((int) $asset->duration_ms) / 1000)) : 'Detecting…' ?></dd></div>
            <div><dt>SHA-256</dt><dd><code title="<?= esc($asset->sha256) ?>"><?= esc(substr($asset->sha256, 0, 16)) ?>…</code></dd></div>
          </dl>
          <div class="asset-review-section">
            <p class="asset-section-label">SUBMISSION DETAILS</p>
            <div class="asset-review-meta">
              <span><small>UPLOADED BY</small><strong><?= esc($userNames[(int) $asset->created_by] ?? 'Unknown account') ?></strong></span>
              <?php if ($asset->reviewed_by !== null): ?><span><small>REVIEWED BY</small><strong><?= esc($userNames[(int) $asset->reviewed_by] ?? 'Administrator') ?></strong></span><?php endif ?>
              <?php if ($asset->reviewed_at !== null): ?><span><small>REVIEWED AT</small><strong><?= esc($asset->reviewed_at->format('Y-m-d H:i')) ?> UTC</strong></span><?php endif ?>
            </div>
          </div>
          <?php if ($asset->status === 'rejected' && $asset->rejection_reason): ?><div class="asset-review-note rejected"><strong>Review feedback</strong><p><?= nl2br(esc($asset->rejection_reason)) ?></p></div><?php endif ?>
          <?php if ($isAdmin && in_array($asset->status, ['draft', 'rejected'], true)): ?>
            <div class="asset-approval-zone">
              <form method="post" action="<?= site_url('control/assets/' . rawurlencode($asset->public_id) . '/approve') ?>"><?= csrf_field() ?><button class="btn primary" type="submit" onclick="return confirm('Approve this film for Player distribution?')">Approve Film</button></form>
              <?php if ($asset->status === 'draft'): ?><form method="post" action="<?= site_url('control/assets/' . rawurlencode($asset->public_id) . '/reject') ?>" class="asset-reject-form"><?= csrf_field() ?><label>Rejection reason<textarea name="rejection_reason" maxlength="1000" required placeholder="Explain what the distributor must correct"></textarea></label><button class="btn danger" type="submit">Reject Film</button></form><?php endif ?>
            </div>
          <?php elseif (!$isAdmin && $asset->status === 'draft'): ?><div class="asset-review-note"><strong>Waiting for administrator review</strong><p>This film cannot be assigned or downloaded by a Player yet.</p></div><?php endif ?>
          <?php if ($isAdmin && $asset->status === 'active'): ?>
          <form method="post" action="<?= site_url('control/assets/' . rawurlencode($asset->public_id) . '/assign') ?>" class="asset-assign-form">
            <?= csrf_field() ?>
            <label>Assign to Player<select name="device_id" required><option value="">Choose an active Player</option><?php foreach ($devices as $device): ?><option value="<?= esc($device->public_id) ?>"><?= esc($device->name) ?><?= $device->location ? ' — ' . esc($device->location) : '' ?></option><?php endforeach ?></select></label>
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
      status.textContent = 'Processing media…'; percent.textContent = '100%'; fill.style.width = '100%';
      fill.classList.add('processing'); eta.textContent = 'Verifying SHA-256 and duration…'; cancel.disabled = true;
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
