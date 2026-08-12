<?= view('web/_layout_top', get_defined_vars()) ?>
<?php
$editingItems = $editing['items'] ?? [];
$formDevice = (string) old('device_id', $editing['device_public_id'] ?? '');
$formTitle = (string) old('title', $editing['title'] ?? '');
$formDescription = (string) old('description', $editing['description'] ?? '');
$formStart = (string) old('start_at', $editing['start_local'] ?? '');
$formPriority = (string) old('priority', $editing['priority'] ?? '0');
$oldKeys = old('media_keys');
$oldDurations = old('duration_ms');
$initialItems = [];
if (is_array($oldKeys)) {
    foreach (array_values($oldKeys) as $index => $key) $initialItems[] = ['mediaKey' => $key, 'durationMs' => (int) ($oldDurations[$index] ?? 0)];
} else {
    foreach ($editingItems as $item) $initialItems[] = ['mediaKey' => $item['media_key'], 'durationMs' => (int) $item['duration_override_ms']];
}
?>
<section class="card schedule-editor">
  <div class="card-heading"><div><p><?= $editing ? 'EDIT SCHEDULE' : 'NEW SCHEDULE' ?></p><h2><?= $editing ? esc($editing['title']) : 'Create a one-time playlist' ?></h2></div><?php if ($editing): ?><a class="btn ghost" href="<?= site_url('control/schedules') ?>">Cancel edit</a><?php endif ?></div>
  <?php if ($devices === []): ?><div class="alert error">Create and pair an active Player before adding schedules.</div><?php endif ?>
  <form id="scheduleForm" method="post" action="<?= $editing ? site_url('control/schedules/' . rawurlencode($editing['public_id']) . '/update') : site_url('control/schedules') ?>" class="schedule-form">
    <?= csrf_field() ?>
    <div class="schedule-fields">
      <label>Schedule title<input name="title" value="<?= esc($formTitle) ?>" maxlength="255" placeholder="Morning playlist" required></label>
      <label>Target Player<select id="scheduleDevice" name="device_id" required><option value="">Choose a Player</option><?php foreach ($devices as $device): ?><option value="<?= esc($device['id']) ?>" <?= $formDevice === $device['id'] ? 'selected' : '' ?>><?= esc($device['name']) ?><?= $device['location'] ? ' — ' . esc($device['location']) : '' ?></option><?php endforeach ?></select></label>
      <label>Start time <span id="scheduleTimezone" class="field-note"></span><input type="datetime-local" name="start_at" value="<?= esc($formStart) ?>" required></label>
      <label>Priority<input type="number" name="priority" value="<?= esc($formPriority) ?>" min="-100" max="100"></label>
    </div>
    <label>Description (optional)<input name="description" value="<?= esc($formDescription) ?>" maxlength="1000" placeholder="Notes for this playback"></label>
    <div class="playlist-builder">
      <div class="section-heading"><div><p>PLAYLIST</p><h2>Ready media on this Player</h2></div><span id="playlistTotal" class="badge">00:00:00</span></div>
      <div class="playlist-picker"><select id="mediaPicker"><option value="">Select Ready media</option></select><button id="addMedia" type="button" class="btn ghost">Add to playlist</button></div>
      <div id="playlistRows" class="playlist-rows"></div>
      <div id="playlistEmpty" class="empty">Choose a Player, then add one or more Ready films.</div>
    </div>
    <label class="check-row"><input type="checkbox" name="loop_enabled" value="1" <?= (string) old('loop_enabled', !empty($editing['loop_enabled']) ? '1' : '0') === '1' ? 'checked' : '' ?>> Loop playlist until the schedule end time</label>
    <div class="form-action"><button class="btn primary" type="submit" <?= $devices === [] ? 'disabled' : '' ?>><?= $editing ? 'Save schedule' : 'Create schedule' ?></button></div>
  </form>
</section>

<section class="schedule-list">
  <div class="section-heading"><div><p>DELIVERY PLAN</p><h2>All schedules</h2></div><span class="badge"><?= count($schedules) ?> schedules</span></div>
  <?php if ($schedules === []): ?><article class="card empty">No schedules yet. Create one above and refresh the target Player.</article><?php endif ?>
  <?php foreach ($schedules as $schedule):
    $tz = new DateTimeZone($schedule['timezone']);
    $start = (new DateTimeImmutable($schedule['start_at'], new DateTimeZone('UTC')))->setTimezone($tz);
    $end = (new DateTimeImmutable($schedule['end_at'], new DateTimeZone('UTC')))->setTimezone($tz);
  ?>
    <article class="card schedule-card">
      <div class="schedule-card-head"><div><span class="badge <?= esc($schedule['display_status']) ?>"><?= esc(strtoupper($schedule['display_status'])) ?></span><h3><?= esc($schedule['title']) ?></h3><p><?= esc($schedule['device_name']) ?><?= $schedule['device_location'] ? ' · ' . esc($schedule['device_location']) : '' ?></p></div><strong>Revision <?= (int) $schedule['revision'] ?></strong></div>
      <div class="schedule-meta"><span><small>START</small><?= esc($start->format('Y-m-d H:i')) ?></span><span><small>END</small><?= esc($end->format('Y-m-d H:i')) ?></span><span><small>TIMEZONE</small><?= esc($schedule['timezone']) ?></span><span><small>PLAYLIST</small><?= count($schedule['items']) ?> items</span></div>
      <ol class="schedule-items"><?php foreach ($schedule['items'] as $item): ?><li><span><?= esc($item['title_snapshot']) ?></span><small><?= gmdate('H:i:s', intdiv((int) $item['duration_override_ms'], 1000)) ?></small></li><?php endforeach ?></ol>
      <div class="schedule-actions"><a class="btn ghost" href="<?= site_url('control/schedules?edit=' . rawurlencode($schedule['public_id'])) ?>">Edit</a><form method="post" action="<?= site_url('control/schedules/' . rawurlencode($schedule['public_id']) . '/status') ?>"><?= csrf_field() ?><input type="hidden" name="enabled" value="<?= $schedule['status'] === 'active' ? '0' : '1' ?>"><button class="btn ghost" type="submit"><?= $schedule['status'] === 'active' ? 'Disable' : 'Enable' ?></button></form><form method="post" action="<?= site_url('control/schedules/' . rawurlencode($schedule['public_id']) . '/delete') ?>"><?= csrf_field() ?><button class="btn danger" type="submit" onclick="return confirm('Delete this schedule from the CMS and Player cache on next refresh?')">Delete</button></form></div>
    </article>
  <?php endforeach ?>
</section>
<script>
(() => {
  const devices = <?= json_encode($devices, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
  const initial = <?= json_encode($initialItems, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
  const byId = new Map(devices.map(device => [device.id, device]));
  const deviceSelect = document.getElementById('scheduleDevice');
  const picker = document.getElementById('mediaPicker');
  const rows = document.getElementById('playlistRows');
  let playlist = [];
  const duration = ms => { const s = Math.max(0, Math.round(Number(ms) / 1000)); return [Math.floor(s / 3600), Math.floor(s % 3600 / 60), s % 60].map(v => String(v).padStart(2, '0')).join(':'); };
  function selectedDevice() { return byId.get(deviceSelect.value) || null; }
  function mediaMap() { return new Map((selectedDevice()?.media || []).map(item => [item.mediaKey, item])); }
  function rebuildPicker() {
    const device = selectedDevice();
    document.getElementById('scheduleTimezone').textContent = device ? `(${device.timezone})` : '';
    picker.innerHTML = '<option value="">Select Ready media</option>';
    for (const item of device?.media || []) { const option = document.createElement('option'); option.value = item.mediaKey; option.textContent = `${item.title} · ${duration(item.durationMs)} · ${item.source === 'managed' ? 'Downloaded' : 'Media Folder'}`; picker.appendChild(option); }
  }
  function render() {
    const available = mediaMap(); rows.innerHTML = '';
    playlist = playlist.filter(item => available.has(item.mediaKey));
    playlist.forEach((entry, index) => {
      const media = available.get(entry.mediaKey); const row = document.createElement('div'); row.className = 'playlist-row';
      row.innerHTML = `<span class="playlist-order">${index + 1}</span><span class="playlist-name"><strong></strong><small></small></span><label>Duration (seconds)<input type="number" min="1" max="86400" step="1"></label><span class="playlist-controls"><button type="button" class="btn ghost" data-action="up">↑</button><button type="button" class="btn ghost" data-action="down">↓</button><button type="button" class="btn danger" data-action="remove">×</button></span><input type="hidden" name="media_keys[]"><input type="hidden" name="duration_ms[]">`;
      row.querySelector('strong').textContent = media.title; row.querySelector('small').textContent = media.filename;
      const seconds = row.querySelector('input[type=number]'); seconds.value = Math.max(1, Math.round((entry.durationMs || media.durationMs) / 1000));
      const durationHidden = row.querySelector('input[name="duration_ms[]"]');
      row.querySelector('input[name="media_keys[]"]').value = entry.mediaKey; durationHidden.value = Number(seconds.value) * 1000;
      seconds.addEventListener('input', () => { entry.durationMs = Math.max(0, Number(seconds.value) * 1000); durationHidden.value = entry.durationMs; updateTotal(); });
      row.querySelector('[data-action=up]').onclick = () => { if (index > 0) [playlist[index - 1], playlist[index]] = [playlist[index], playlist[index - 1]]; render(); };
      row.querySelector('[data-action=down]').onclick = () => { if (index < playlist.length - 1) [playlist[index + 1], playlist[index]] = [playlist[index], playlist[index + 1]]; render(); };
      row.querySelector('[data-action=remove]').onclick = () => { playlist.splice(index, 1); render(); }; rows.appendChild(row);
    });
    document.getElementById('playlistEmpty').style.display = playlist.length ? 'none' : 'block'; updateTotal();
  }
  function updateTotal() { const available = mediaMap(); const total = playlist.reduce((sum, item) => sum + Number(item.durationMs || available.get(item.mediaKey)?.durationMs || 0), 0); document.getElementById('playlistTotal').textContent = duration(total); }
  deviceSelect.addEventListener('change', () => { playlist = []; rebuildPicker(); render(); });
  document.getElementById('addMedia').onclick = () => { const item = mediaMap().get(picker.value); if (!item) return; playlist.push({ mediaKey: item.mediaKey, durationMs: item.durationMs }); picker.value = ''; render(); };
  rebuildPicker(); const available = mediaMap(); playlist = initial.filter(item => available.has(item.mediaKey)).map(item => ({ mediaKey: item.mediaKey, durationMs: item.durationMs || available.get(item.mediaKey).durationMs })); render();
})();
</script>
<?= view('web/_layout_bottom') ?>
