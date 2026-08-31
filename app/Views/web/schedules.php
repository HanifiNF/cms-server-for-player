<?= view('web/_layout_top', get_defined_vars()) ?>
<?php
$editingItems = $editing['items'] ?? [];
$formDevice = (string) old('device_id', $editing['device_public_id'] ?? '');
$formDevices = old('device_ids', array_column($editing['targets'] ?? [], 'public_id'));
$formDevices = is_array($formDevices) ? array_values(array_unique(array_map('strval', $formDevices))) : [];
if ($formDevices === [] && $formDevice !== '') $formDevices[] = $formDevice;
$formTimezone = (string) old('timezone', $editing['timezone'] ?? 'Asia/Jakarta');
$formTitle = (string) old('title', $editing['title'] ?? '');
$formDescription = (string) old('description', $editing['description'] ?? '');
$formStart = (string) old('start_at', $editing['start_local'] ?? '');
$formPriority = (string) old('priority', $editing['priority'] ?? '0');
$formRecurrence = (string) old('recurrence', $editing['recurrence'] ?? 'one_time');
$formUntil = (string) old('recurrence_until', $editing['recurrence_values']['until'] ?? '');
$formAutoExpiry = old('auto_expiry_until');
if ($formAutoExpiry === null) $formAutoExpiry = $editing ? '0' : '1';
$formDays = old('days_of_week', $editing['recurrence_values']['daysOfWeek'] ?? []);
$formDays = is_array($formDays) ? array_map('intval', $formDays) : [];
$formatDuration = static function (int $milliseconds): string {
    $seconds = max(0, intdiv($milliseconds, 1000));
    return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
};
$oldKeys = old('media_keys');
$oldDurations = old('duration_ms');
$oldGaps = old('gap_after_ms');
$initialItems = [];
if (is_array($oldKeys)) {
    foreach (array_values($oldKeys) as $index => $key) $initialItems[] = ['mediaKey' => $key, 'durationMs' => (int) ($oldDurations[$index] ?? 0), 'gapAfterMs' => (int) ($oldGaps[$index] ?? 0)];
} else {
    foreach ($editingItems as $item) $initialItems[] = ['mediaKey' => $item['media_key'], 'durationMs' => (int) $item['duration_override_ms'], 'gapAfterMs' => (int) ($item['gap_after_ms'] ?? 0)];
}
$deviceGroups = [];
$availableTimezones = ['Asia/Jakarta' => 'Asia/Jakarta'];
foreach ($devices as $device) {
    $locationKey = (string) ($device['locationId'] ?: 'unassigned');
    if (! isset($deviceGroups[$locationKey])) $deviceGroups[$locationKey] = [
        'name' => (string) ($device['location'] ?: 'No Location'), 'code' => (string) ($device['locationCode'] ?? ''), 'devices' => [],
    ];
    $deviceGroups[$locationKey]['devices'][] = $device;
    $availableTimezones[(string) $device['timezone']] = (string) $device['timezone'];
}
?>
<div class="cms-page-toolbar"><div><p>DELIVERY PLAN</p><h2>Playback schedules</h2><span>Create one-time, daily, or weekly playlists for one or more Studios.</span></div><div class="cms-toolbar-actions"><span class="count"><?= count($schedules) ?> Schedules</span><button class="btn primary" type="button" data-cms-modal-open="schedule-editor-modal" <?= $devices === [] ? 'disabled title="Pair an active Studio first"' : '' ?>>+ Create Schedule</button></div></div>
<?php if ($devices === []): ?><div class="alert error">Create and pair an active Studio before adding schedules.</div><?php endif ?>
<dialog class="cms-action-modal full" id="schedule-editor-modal" data-cms-modal <?= $editing || old('_modal_context') === 'schedule-editor' ? 'data-auto-open="true"' : '' ?>>
  <form id="scheduleForm" method="post" action="<?= $editing ? site_url('control/schedules/' . rawurlencode($editing['public_id']) . '/update') : site_url('control/schedules') ?>" class="cms-modal-shell schedule-form schedule-modal-form">
    <?= csrf_field() ?><input type="hidden" name="_modal_context" value="schedule-editor">
    <header class="cms-modal-header"><div><p><?= $editing ? 'EDIT SCHEDULE' : 'NEW SCHEDULE' ?></p><h2><?= $editing ? esc($editing['title']) : 'Create a playback schedule' ?></h2><span>Build the playlist and delivery rules without leaving the schedule overview.</span></div><?php if ($editing): ?><a class="cms-modal-x" href="<?= site_url('control/schedules') ?>" aria-label="Cancel edit">×</a><?php else: ?><button class="cms-modal-x" type="button" data-cms-modal-close aria-label="Close">×</button><?php endif ?></header>
    <div class="cms-modal-body schedule-modal-body">
    <div class="schedule-fields">
      <label>Schedule title<input name="title" value="<?= esc($formTitle) ?>" maxlength="255" placeholder="Morning playlist" required></label>
      <div class="schedule-target-field"><span class="schedule-field-label">Target Studios</span><details class="schedule-target-picker" id="scheduleTargetPicker"><summary><span id="scheduleTargetSummary">Choose one or more Studios</span><b aria-hidden="true">⌄</b></summary><div class="schedule-target-panel"><label class="schedule-target-search">Search Location or Studio<input type="search" id="scheduleTargetSearch" placeholder="Search Location or Studio…"></label><div class="schedule-target-groups"><?php foreach ($deviceGroups as $locationKey => $group): ?><details class="schedule-target-location" data-target-location data-search-text="<?= esc(mb_strtolower($group['name'] . ' ' . implode(' ', array_column($group['devices'], 'name'))), 'attr') ?>"><summary><label><input type="checkbox" data-target-location-check><span><strong><?= esc($group['name']) ?></strong><small><?= count($group['devices']) ?> Studio(s)<?= $group['code'] ? ' · ' . esc($group['code']) : '' ?></small></span></label><b aria-hidden="true">⌄</b></summary><div><?php foreach ($group['devices'] as $device): ?><label class="schedule-target-option" data-target-option data-search-text="<?= esc(mb_strtolower($group['name'] . ' ' . $device['name']), 'attr') ?>"><input type="checkbox" name="device_ids[]" value="<?= esc($device['id'], 'attr') ?>" data-target-device <?= in_array((string) $device['id'], $formDevices, true) ? 'checked' : '' ?>><span><strong><?= esc($device['name']) ?></strong><small><?= count($device['media']) ?> Ready media · <?= esc($device['timezone']) ?></small></span></label><?php endforeach ?></div></details><?php endforeach ?><div class="empty schedule-target-empty" id="scheduleTargetEmpty" hidden>No Location or Studio matches this search.</div></div></div></details></div>
      <label>Schedule timezone<select id="scheduleTimezone" name="timezone" required><?php foreach ($availableTimezones as $timezone): ?><option value="<?= esc($timezone, 'attr') ?>" <?= $formTimezone === $timezone ? 'selected' : '' ?>><?= esc($timezone) ?></option><?php endforeach ?></select></label>
      <label>Start time<input id="scheduleStartTime" type="datetime-local" name="start_at" value="<?= esc($formStart) ?>" step="1" required></label>
      <label>Priority<input type="number" name="priority" value="<?= esc($formPriority) ?>" min="-100" max="100"></label>
    </div>
    <div class="recurrence-panel" id="scheduleRecurrencePanel" data-mode="<?= esc($formRecurrence, 'attr') ?>">
      <label class="schedule-repeat-field">Repeat<select id="scheduleRecurrence" name="recurrence"><option value="one_time" <?= $formRecurrence === 'one_time' ? 'selected' : '' ?>>One time</option><option value="daily" <?= $formRecurrence === 'daily' ? 'selected' : '' ?>>Daily</option><option value="weekly" <?= $formRecurrence === 'weekly' ? 'selected' : '' ?>>Weekly</option></select></label>
      <fieldset id="weekdayFields"><legend>Play on</legend><div class="weekday-options"><?php foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $day => $name): ?><label><input type="checkbox" name="days_of_week[]" value="<?= $day ?>" <?= in_array($day, $formDays, true) ? 'checked' : '' ?>><?= $name ?></label><?php endforeach ?></div></fieldset>
      <div id="recurrenceUntilField" class="schedule-until-field"><label><span class="schedule-until-label">End date <small class="field-note">(optional when every film has no expiry)</small></span><input id="scheduleRecurrenceUntil" type="date" name="recurrence_until" value="<?= esc($formUntil) ?>"></label><input type="hidden" name="auto_expiry_until" value="0"><div class="schedule-auto-expiry-field"><span>Expiry policy</span><label class="schedule-auto-expiry"><input id="scheduleAutoExpiry" type="checkbox" name="auto_expiry_until" value="1" <?= (string) $formAutoExpiry === '1' ? 'checked' : '' ?>> Use the earliest film expiry automatically</label></div><small id="scheduleExpiryHint" class="schedule-expiry-hint"></small></div>
    </div>
    <label>Description (optional)<input name="description" value="<?= esc($formDescription) ?>" maxlength="1000" placeholder="Notes for this playback"></label>
    <div class="playlist-builder">
      <div class="section-heading"><div><p>PLAYLIST</p><h2>Ready media on every selected Studio</h2></div><span id="playlistTotal" class="badge">00:00:00</span></div>
      <div class="media-picker-filters"><input id="mediaSearch" type="search" placeholder="Search media title"><select id="mediaTypeFilter"><option value="">All types</option><option value="featured">Featured</option><option value="ads">Ads</option><option value="trailer">Trailer</option><option value="local">Local media</option></select><select id="mediaGenreFilter"><option value="">All genres</option></select></div>
      <div class="playlist-picker"><select id="mediaPicker"><option value="">Select Ready media</option></select><button id="addMedia" type="button" class="btn ghost">Add to playlist</button></div>
      <div class="playlist-timeline-summary" aria-live="polite">
        <span><small>SCHEDULE START</small><strong id="timelineScheduleStart">—</strong></span>
        <span><small>SCHEDULE END</small><strong id="timelineScheduleEnd">—</strong></span>
        <span><small>FILM DURATION</small><strong id="timelineFilmDuration">00:00:00</strong></span>
        <span><small>TOTAL GAP</small><strong id="timelineGapDuration">00:00:00</strong></span>
        <span><small>TOTAL DURATION</small><strong id="timelineTotalDuration">00:00:00</strong></span>
        <span><small>TIMEZONE</small><strong id="timelineTimezone"><?= esc($formTimezone) ?></strong></span>
      </div>
      <div id="playlistRows" class="playlist-rows"></div>
      <div id="playlistEmpty" class="empty">Choose one or more Studios, then add media available on all of them.</div>
    </div>
    <label class="check-row"><input type="checkbox" name="loop_enabled" value="1" <?= (string) old('loop_enabled', !empty($editing['loop_enabled']) ? '1' : '0') === '1' ? 'checked' : '' ?>> Loop playlist until the schedule end time</label>
    </div>
    <footer class="cms-modal-footer"><span>All selected Studios use this same absolute start time and playlist.</span><div><?php if ($editing): ?><a class="btn ghost" href="<?= site_url('control/schedules') ?>">Cancel</a><?php else: ?><button class="btn ghost" type="button" data-cms-modal-close>Cancel</button><?php endif ?><button class="btn primary" type="submit" <?= $devices === [] ? 'disabled' : '' ?>><?= $editing ? 'Save Schedule' : 'Create Schedule' ?></button></div></footer>
  </form>
</dialog>

<section class="schedule-list">
  <div class="section-heading"><div><p>SCHEDULE DIRECTORY</p><h2>All schedules</h2></div><span class="badge"><?= count($schedules) ?> schedules</span></div>
  <?php if ($schedules === []): ?><article class="card empty">No schedules yet. Create one above and refresh the target Studio.</article><?php endif ?>
  <?php foreach ($schedules as $schedule):
    $tz = new DateTimeZone($schedule['timezone']);
    $start = (new DateTimeImmutable($schedule['start_at'], new DateTimeZone('UTC')))->setTimezone($tz);
    $end = (new DateTimeImmutable($schedule['end_at'], new DateTimeZone('UTC')))->setTimezone($tz);
    $recurrenceConfig = is_array($schedule['recurrence_config']) ? $schedule['recurrence_config'] : json_decode((string) ($schedule['recurrence_config'] ?? ''), true);
    $recurrenceConfig = is_array($recurrenceConfig) ? $recurrenceConfig : [];
    $repeatLabel = match ($schedule['recurrence']) {
      'daily' => 'Daily',
      'weekly' => 'Weekly · ' . implode(', ', array_map(static fn ($day) => [1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'][(int) $day] ?? '', $recurrenceConfig['daysOfWeek'] ?? [])),
      default => 'One time',
    };
    if ($schedule['recurrence'] !== 'one_time') $repeatLabel .= !empty($recurrenceConfig['until']) ? ' · until ' . $recurrenceConfig['until'] : ' · no end date';
  ?>
    <article class="card schedule-card">
      <div class="schedule-card-head"><div><span class="badge <?= esc($schedule['display_status']) ?>"><?= esc(strtoupper($schedule['display_status'])) ?></span><h3><?= esc($schedule['title']) ?></h3><p><?php if ((int) $schedule['target_count'] === 1): ?><?= esc($schedule['device_name']) ?><?= $schedule['device_location'] ? ' · ' . esc($schedule['device_location']) : '' ?><?php else: ?><?= (int) $schedule['target_count'] ?> Studios · <?= (int) $schedule['location_count'] ?> Locations<?php endif ?></p></div><strong>Revision <?= (int) $schedule['revision'] ?></strong></div>
      <?php if ((int) $schedule['target_count'] > 1): ?><details class="schedule-card-targets"><summary>View <?= (int) $schedule['target_count'] ?> target Studios <span>⌄</span></summary><div><?php foreach ($schedule['targets'] as $target): ?><span><strong><?= esc($target['name']) ?></strong><small><?= esc($target['location'] ?: 'No Location') ?></small></span><?php endforeach ?></div></details><?php endif ?>
      <div class="schedule-meta schedule-meta-detailed"><span><small>FIRST START</small><?= esc($start->format('Y-m-d H:i:s')) ?></span><span><small>OCCURRENCE END</small><?= esc($end->format('Y-m-d H:i:s')) ?></span><span><small>FILM DURATION</small><?= esc($formatDuration((int) $schedule['timeline']['film_duration_ms'])) ?></span><span><small>TOTAL GAP</small><?= esc($formatDuration((int) $schedule['timeline']['gap_duration_ms'])) ?></span><span><small>TOTAL DURATION</small><?= esc($formatDuration((int) $schedule['timeline']['total_duration_ms'])) ?></span><span><small>REPEAT</small><?= esc($repeatLabel) ?></span></div>
      <ol class="schedule-items schedule-timeline-items">
        <?php foreach ($schedule['items'] as $item):
          $itemStart = $start->modify('+' . (int) $item['start_offset_ms'] . ' milliseconds');
          $contentEnd = $start->modify('+' . (int) $item['content_end_offset_ms'] . ' milliseconds');
          $nextStart = $start->modify('+' . (int) $item['next_start_offset_ms'] . ' milliseconds');
          $effectiveGap = (int) $item['effective_gap_after_ms'];
        ?>
          <li>
            <div class="schedule-item-title"><span><?= esc($item['title_snapshot']) ?></span><small>Duration <?= esc($formatDuration((int) $item['duration_override_ms'])) ?><?= $effectiveGap > 0 ? ' · Gap ' . esc($formatDuration($effectiveGap)) : '' ?></small></div>
            <div class="schedule-item-times"><span><small>STARTS AT</small><?= esc($itemStart->format('Y-m-d H:i:s')) ?></span><span><small>CONTENT ENDS</small><?= esc($contentEnd->format('Y-m-d H:i:s')) ?></span><span><small><?= $effectiveGap > 0 ? 'NEXT START' : 'TIMELINE END' ?></small><?= esc(($effectiveGap > 0 ? $nextStart : $contentEnd)->format('Y-m-d H:i:s')) ?></span></div>
          </li>
        <?php endforeach ?>
      </ol>
      <div class="schedule-actions"><a class="btn ghost" href="<?= site_url('control/schedules?edit=' . rawurlencode($schedule['public_id'])) ?>">Edit</a><button class="btn ghost" type="button" data-cms-modal-open="status-schedule-<?= esc($schedule['public_id'], 'attr') ?>"><?= $schedule['status'] === 'active' ? 'Disable' : 'Enable' ?></button><button class="btn danger" type="button" data-cms-modal-open="delete-schedule-<?= esc($schedule['public_id'], 'attr') ?>">Delete</button></div>
    </article>
    <dialog class="cms-action-modal" id="status-schedule-<?= esc($schedule['public_id'], 'attr') ?>" data-cms-modal><form method="post" action="<?= site_url('control/schedules/' . rawurlencode($schedule['public_id']) . '/status') ?>" class="cms-modal-shell"><?= csrf_field() ?><input type="hidden" name="enabled" value="<?= $schedule['status'] === 'active' ? '0' : '1' ?>"><header class="cms-modal-header"><div><p>SCHEDULE STATUS</p><h2><?= $schedule['status'] === 'active' ? 'Disable' : 'Enable' ?> <?= esc($schedule['title']) ?>?</h2></div><button class="cms-modal-x" type="button" data-cms-modal-close>×</button></header><div class="cms-modal-body"><div class="cms-confirm-message <?= $schedule['status'] === 'active' ? 'danger' : '' ?>"><strong><?= $schedule['status'] === 'active' ? 'Future playback occurrences will be disabled.' : 'The schedule will become available to all target Players.' ?></strong>The updated revision is delivered to every target Studio when it refreshes or receives its realtime notification.</div></div><footer class="cms-modal-footer"><span>Schedule status change</span><div><button class="btn ghost" type="button" data-cms-modal-close>Cancel</button><button class="btn <?= $schedule['status'] === 'active' ? 'danger' : 'primary' ?>" type="submit">Confirm <?= $schedule['status'] === 'active' ? 'Disable' : 'Enable' ?></button></div></footer></form></dialog>
    <dialog class="cms-action-modal" id="delete-schedule-<?= esc($schedule['public_id'], 'attr') ?>" data-cms-modal><form method="post" action="<?= site_url('control/schedules/' . rawurlencode($schedule['public_id']) . '/delete') ?>" class="cms-modal-shell"><?= csrf_field() ?><header class="cms-modal-header"><div><p>DANGER ZONE</p><h2>Delete <?= esc($schedule['title']) ?>?</h2></div><button class="cms-modal-x" type="button" data-cms-modal-close>×</button></header><div class="cms-modal-body"><div class="cms-confirm-message danger"><strong>This schedule will be permanently removed.</strong>All target Players remove it from their local schedule cache on the next refresh.</div></div><footer class="cms-modal-footer"><span>Permanent deletion</span><div><button class="btn ghost" type="button" data-cms-modal-close>Cancel</button><button class="btn danger" type="submit">Delete Schedule</button></div></footer></form></dialog>
  <?php endforeach ?>
</section>
<script>
(() => {
  const devices = <?= json_encode($devices, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
  const initial = <?= json_encode($initialItems, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
  const byId = new Map(devices.map(device => [String(device.id), device]));
  const targetPicker = document.getElementById('scheduleTargetPicker');
  const targetSearch = document.getElementById('scheduleTargetSearch');
  const targetChecks = [...document.querySelectorAll('[data-target-device]')];
  const locationGroups = [...document.querySelectorAll('[data-target-location]')];
  const picker = document.getElementById('mediaPicker');
  const rows = document.getElementById('playlistRows');
  const recurrence = document.getElementById('scheduleRecurrence');
  const recurrenceUntil = document.getElementById('scheduleRecurrenceUntil');
  const autoExpiryUntil = document.getElementById('scheduleAutoExpiry');
  const expiryHint = document.getElementById('scheduleExpiryHint');
  const mediaSearch = document.getElementById('mediaSearch');
  const mediaTypeFilter = document.getElementById('mediaTypeFilter');
  const mediaGenreFilter = document.getElementById('mediaGenreFilter');
  const startInput = document.getElementById('scheduleStartTime');
  const timezoneInput = document.getElementById('scheduleTimezone');
  let playlist = [];
  const duration = ms => { const s = Math.max(0, Math.round(Number(ms) / 1000)); return [Math.floor(s / 3600), Math.floor(s % 3600 / 60), s % 60].map(v => String(v).padStart(2, '0')).join(':'); };
  function selectedDevices() { return targetChecks.filter(check => check.checked).map(check => byId.get(String(check.value))).filter(Boolean); }
  function mediaMap() {
    const selected = selectedDevices();
    if (!selected.length) return new Map();
    const common = new Map((selected[0].media || []).map(item => [item.mediaKey, item]));
    for (const device of selected.slice(1)) {
      const keys = new Set((device.media || []).map(item => item.mediaKey));
      for (const key of common.keys()) if (!keys.has(key)) common.delete(key);
    }
    return common;
  }
  function updateLocationChecks() {
    for (const group of locationGroups) {
      const children = [...group.querySelectorAll('[data-target-device]')];
      const checked = children.filter(child => child.checked).length;
      const parent = group.querySelector('[data-target-location-check]');
      parent.checked = checked > 0 && checked === children.length;
      parent.indeterminate = checked > 0 && checked < children.length;
    }
  }
  function updateTargetSummary() {
    const selected = selectedDevices();
    const summary = document.getElementById('scheduleTargetSummary');
    if (!selected.length) summary.textContent = 'Choose one or more Studios';
    else if (selected.length === 1) summary.textContent = `${selected[0].location} — ${selected[0].name}`;
    else summary.textContent = `${selected.length} Studios across ${new Set(selected.map(device => device.location)).size} Locations`;
    updateLocationChecks();
  }
  function updateRecurrenceFields() {
    document.getElementById('scheduleRecurrencePanel').dataset.mode = recurrence.value;
    document.getElementById('weekdayFields').hidden = recurrence.value !== 'weekly';
    document.getElementById('recurrenceUntilField').hidden = recurrence.value === 'one_time';
    syncExpiryEndDate();
  }
  function earliestPlaylistExpiry() {
    const available = mediaMap();
    return playlist
      .map(entry => available.get(entry.mediaKey))
      .filter(item => item && /^\d{4}-\d{2}-\d{2}$/.test(String(item.expiresOn || '')))
      .sort((left, right) => String(left.expiresOn).localeCompare(String(right.expiresOn)))[0] || null;
  }
  function syncExpiryEndDate() {
    if (recurrence.value === 'one_time') {
      recurrenceUntil.removeAttribute('max');
      expiryHint.textContent = '';
      expiryHint.classList.remove('warning');
      return;
    }
    const limitingMedia = earliestPlaylistExpiry();
    if (!limitingMedia) {
      recurrenceUntil.removeAttribute('max');
      if (autoExpiryUntil.checked && recurrenceUntil.dataset.autoValue && recurrenceUntil.value === recurrenceUntil.dataset.autoValue) recurrenceUntil.value = '';
      delete recurrenceUntil.dataset.autoValue;
      expiryHint.textContent = 'No film in this playlist has an expiry date. End date may remain blank.';
      expiryHint.classList.remove('warning');
      return;
    }
    const limit = String(limitingMedia.expiresOn);
    recurrenceUntil.max = limit;
    if (autoExpiryUntil.checked) {
      recurrenceUntil.value = limit;
      recurrenceUntil.dataset.autoValue = limit;
    }
    const tooLate = Boolean(recurrenceUntil.value && recurrenceUntil.value > limit);
    expiryHint.textContent = tooLate
      ? `End date must not pass ${limit}, the earliest expiry in this playlist (${limitingMedia.title}).`
      : `Limited by ${limitingMedia.title}, which expires on ${limit}.`;
    expiryHint.classList.toggle('warning', tooLate);
  }
  function rebuildPicker() {
    const available = mediaMap();
    picker.innerHTML = '<option value="">Select Ready media</option>';
    const search = mediaSearch.value.trim().toLowerCase(); const type = mediaTypeFilter.value; const genre = mediaGenreFilter.value;
    for (const item of available.values()) { if (search && !`${item.title} ${item.filename}`.toLowerCase().includes(search)) continue; if (type && item.type !== type) continue; if (genre && !(item.genres || []).includes(genre)) continue; const option = document.createElement('option'); option.value = item.mediaKey; option.textContent = `${item.title} · ${String(item.type || 'local').toUpperCase()} · ${duration(item.durationMs)} · ${item.source === 'managed' ? 'Downloaded' : 'Media Folder'}`; picker.appendChild(option); }
  }
  function rebuildGenreFilter() { const selected = mediaGenreFilter.value; const genres = [...new Set([...mediaMap().values()].flatMap(item => item.genres || []))].sort(); mediaGenreFilter.innerHTML = '<option value="">All genres</option>'; for (const genre of genres) { const option = document.createElement('option'); option.value = genre; option.textContent = genre; mediaGenreFilter.appendChild(option); } if (genres.includes(selected)) mediaGenreFilter.value = selected; }
  function render() {
    const available = mediaMap(); rows.innerHTML = '';
    playlist = playlist.filter(item => available.has(item.mediaKey));
    playlist.forEach((entry, index) => {
      const media = available.get(entry.mediaKey); const row = document.createElement('div'); row.className = 'playlist-row'; row.dataset.timelineIndex = String(index);
      row.innerHTML = `<span class="playlist-order">${index + 1}</span><span class="playlist-name"><strong></strong><small></small></span><fieldset class="timeline-duration" data-time="duration"><legend>Film duration</legend><label>Hours<input data-unit="hours" type="number" min="0" max="24"></label><label>Minutes<input data-unit="minutes" type="number" min="0" max="59"></label><label>Seconds<input data-unit="seconds" type="number" min="0" max="59"></label></fieldset><fieldset class="timeline-duration film-gap" data-time="gap"><legend>Gap after film</legend><label>Hours<input data-unit="hours" type="number" min="0" max="24"></label><label>Minutes<input data-unit="minutes" type="number" min="0" max="59"></label><label>Seconds<input data-unit="seconds" type="number" min="0" max="59"></label></fieldset><span class="playlist-controls"><button type="button" class="btn ghost" data-action="up" aria-label="Move film up">↑</button><button type="button" class="btn ghost" data-action="down" aria-label="Move film down">↓</button><button type="button" class="btn danger" data-action="remove" aria-label="Remove film">×</button></span><div class="playlist-item-timeline"><span class="timeline-clock" data-start-clock><small>STARTS AT</small><strong data-timeline-start>—</strong><input data-timeline-start-input type="datetime-local" step="1" hidden><em data-start-note></em><b class="timeline-clock-error" data-start-error></b></span><span class="timeline-clock"><small>CONTENT ENDS</small><strong data-timeline-end>—</strong><em>Calculated from film duration</em></span><span class="timeline-clock" data-boundary-clock><small data-timeline-next-label>TIMELINE ENDS</small><strong data-timeline-next>—</strong><div class="timeline-boundary-controls"><input data-timeline-boundary-input type="datetime-local" step="1" hidden><button data-action="reset-gap" class="btn ghost" type="button" hidden>Reset</button></div><em data-timeline-status></em><b class="timeline-clock-error" data-boundary-error></b></span></div><input type="hidden" name="media_keys[]"><input type="hidden" name="duration_ms[]"><input type="hidden" name="gap_after_ms[]">`;
      row.querySelector('strong').textContent = media.title; row.querySelector('small').textContent = media.filename;
      const durationHidden = row.querySelector('input[name="duration_ms[]"]');
      const gapHidden = row.querySelector('input[name="gap_after_ms[]"]');
      row.querySelector('input[name="media_keys[]"]').value = entry.mediaKey;
      const bindTime = (selector, initialMs, onChange) => { const box = row.querySelector(selector); const totalSeconds = Math.max(0, Math.round(Number(initialMs || 0) / 1000)); box.querySelector('[data-unit=hours]').value = Math.floor(totalSeconds / 3600); box.querySelector('[data-unit=minutes]').value = Math.floor(totalSeconds % 3600 / 60); box.querySelector('[data-unit=seconds]').value = totalSeconds % 60; const sync = () => { const h = Math.max(0, Number(box.querySelector('[data-unit=hours]').value) || 0); const m = Math.max(0, Math.min(59, Number(box.querySelector('[data-unit=minutes]').value) || 0)); const s = Math.max(0, Math.min(59, Number(box.querySelector('[data-unit=seconds]').value) || 0)); onChange((h * 3600 + m * 60 + s) * 1000); }; box.querySelectorAll('input').forEach(input => input.addEventListener('input', sync)); sync(); };
      bindTime('[data-time=duration]', entry.durationMs || media.durationMs, value => { entry.durationMs = value; durationHidden.value = value; updateTotal(); });
      bindTime('[data-time=gap]', entry.gapAfterMs || 0, value => { entry.gapAfterMs = value; gapHidden.value = value; updateTotal(); });
      const gapActive = index < playlist.length - 1 || document.querySelector('input[name=loop_enabled]')?.checked;
      row.querySelector('.film-gap').classList.toggle('inactive', !gapActive);
      row.querySelectorAll('.film-gap input').forEach(input => { input.disabled = !gapActive; });
      const manualStart = row.querySelector('[data-timeline-start-input]');
      const boundaryInput = row.querySelector('[data-timeline-boundary-input]');
      const resetGap = row.querySelector('[data-action=reset-gap]');
      if (index > 0) {
        row.querySelector('[data-timeline-start]').hidden = true;
        manualStart.hidden = false;
        row.querySelector('[data-start-note]').textContent = 'Adjusts the previous film gap';
        manualStart.addEventListener('change', () => applyBoundary(index - 1, manualStart, row.querySelector('[data-start-error]')));
      } else {
        row.querySelector('[data-start-note]').textContent = 'Locked to schedule start';
      }
      if (gapActive) {
        row.querySelector('[data-timeline-next]').hidden = true;
        boundaryInput.hidden = false;
        resetGap.hidden = false;
        boundaryInput.addEventListener('change', () => applyBoundary(index, boundaryInput, row.querySelector('[data-boundary-error]')));
        resetGap.onclick = () => { entry.gapAfterMs = 0; render(); };
      }
      for (const input of [manualStart, boundaryInput]) input.addEventListener('input', () => {
        input.classList.remove('invalid');
      });
      row.querySelector('[data-action=up]').onclick = () => { if (index > 0) [playlist[index - 1], playlist[index]] = [playlist[index], playlist[index - 1]]; render(); };
      row.querySelector('[data-action=down]').onclick = () => { if (index < playlist.length - 1) [playlist[index + 1], playlist[index]] = [playlist[index], playlist[index + 1]]; render(); };
      row.querySelector('[data-action=remove]').onclick = () => { playlist.splice(index, 1); render(); }; rows.appendChild(row);
    });
    document.getElementById('playlistEmpty').style.display = playlist.length ? 'none' : 'block'; updateTotal(); syncExpiryEndDate();
  }
  function zonedEpoch(value) {
    const match = String(value || '').match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?$/);
    if (!match) return null;
    const desired = Date.UTC(Number(match[1]), Number(match[2]) - 1, Number(match[3]), Number(match[4]), Number(match[5]), Number(match[6] || 0));
    let guess = desired;
    try {
      const formatter = new Intl.DateTimeFormat('en-CA', { timeZone: timezoneInput.value, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' });
      for (let attempt = 0; attempt < 3; attempt++) {
        const parts = Object.fromEntries(formatter.formatToParts(new Date(guess)).filter(part => part.type !== 'literal').map(part => [part.type, part.value]));
        const observed = Date.UTC(Number(parts.year), Number(parts.month) - 1, Number(parts.day), Number(parts.hour), Number(parts.minute), Number(parts.second));
        guess += desired - observed;
      }
      return guess;
    } catch (_) { return desired; }
  }
  function scheduleStartEpoch() { return zonedEpoch(startInput.value); }
  function formatMoment(epoch) {
    if (!Number.isFinite(epoch)) return '—';
    try { return new Intl.DateTimeFormat('en-GB', { timeZone: timezoneInput.value, day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' }).format(new Date(epoch)).replace(',', ' ·'); }
    catch (_) { return new Date(epoch).toISOString().replace('T', ' ').slice(0, 19); }
  }
  function formatMomentInput(epoch) {
    if (!Number.isFinite(epoch)) return '';
    try {
      const formatter = new Intl.DateTimeFormat('en-CA', { timeZone: timezoneInput.value, year: 'numeric', month: '2-digit', day: '2-digit', hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23' });
      const parts = Object.fromEntries(formatter.formatToParts(new Date(epoch)).filter(part => part.type !== 'literal').map(part => [part.type, part.value]));
      return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}:${parts.second}`;
    } catch (_) { return new Date(epoch).toISOString().slice(0, 19); }
  }
  function timelineSnapshot() {
    const available = mediaMap(); const loop = document.querySelector('input[name=loop_enabled]')?.checked;
    const items = []; let cursor = 0; let filmTotal = 0; let gapTotal = 0;
    playlist.forEach((item, index) => {
      const filmDuration = Math.max(0, Number(item.durationMs || available.get(item.mediaKey)?.durationMs || 0));
      const gap = (index < playlist.length - 1 || loop) ? Math.max(0, Number(item.gapAfterMs || 0)) : 0;
      const contentEnd = cursor + filmDuration; const nextStart = contentEnd + gap;
      items.push({ start: cursor, contentEnd, nextStart, filmDuration, gap });
      filmTotal += filmDuration; gapTotal += gap; cursor = nextStart;
    });
    return { items, filmTotal, gapTotal, total: cursor, loop };
  }
  function applyBoundary(previousIndex, control, errorTarget) {
    control.setCustomValidity(''); control.classList.remove('invalid'); errorTarget.textContent = '';
    const scheduleStart = scheduleStartEpoch(); const requestedBoundary = zonedEpoch(control.value);
    const timeline = timelineSnapshot(); const previous = timeline.items[previousIndex];
    let message = '';
    if (scheduleStart === null) message = 'Choose the schedule start time first.';
    else if (requestedBoundary === null || !previous) message = 'Choose a valid boundary date and time.';
    else {
      const gap = Math.round(requestedBoundary - (scheduleStart + previous.contentEnd));
      if (gap < 0) message = `This film cannot end before its content finishes at ${formatMoment(scheduleStart + previous.contentEnd)}.`;
      else if (gap > 86400000) message = 'A film gap may not exceed 24 hours.';
      else {
        playlist[previousIndex].gapAfterMs = gap;
        const previousRow = rows.querySelector(`[data-timeline-index="${previousIndex}"]`);
        if (previousRow) previousRow.querySelector('input[name="gap_after_ms[]"]').value = String(gap);
        updateTotal();
        return;
      }
    }
    control.setCustomValidity(message); control.classList.add('invalid'); errorTarget.textContent = message; control.reportValidity();
  }
  function updateTotal() {
    const startEpoch = scheduleStartEpoch(); const timeline = timelineSnapshot();
    timeline.items.forEach((item, index) => {
      const row = rows.querySelector(`[data-timeline-index="${index}"]`);
      if (row) {
        const startValue = startEpoch === null ? null : startEpoch + item.start;
        const contentEndValue = startEpoch === null ? null : startEpoch + item.contentEnd;
        const boundaryValue = startEpoch === null ? null : startEpoch + item.nextStart;
        row.querySelector('[data-timeline-start]').textContent = startValue === null ? '—' : formatMoment(startValue);
        row.querySelector('[data-timeline-end]').textContent = contentEndValue === null ? '—' : formatMoment(contentEndValue);
        row.querySelector('[data-timeline-next-label]').textContent = index === playlist.length - 1 ? (timeline.loop ? 'NEXT CYCLE STARTS' : 'SCHEDULE ENDS') : 'TIMELINE ENDS';
        row.querySelector('[data-timeline-next]').textContent = boundaryValue === null ? '—' : formatMoment(boundaryValue);
        const manualStart = row.querySelector('[data-timeline-start-input]');
        const boundaryInput = row.querySelector('[data-timeline-boundary-input]');
        if (!manualStart.hidden) { manualStart.value = startValue === null ? '' : formatMomentInput(startValue); manualStart.disabled = startValue === null; }
        if (!boundaryInput.hidden) { boundaryInput.value = boundaryValue === null ? '' : formatMomentInput(boundaryValue); boundaryInput.disabled = boundaryValue === null; }
        const status = row.querySelector('[data-timeline-status]');
        status.textContent = item.gap > 0 ? `Adjusted · ${duration(item.gap)} gap` : (index === playlist.length - 1 && !timeline.loop ? 'Ends with the film' : 'Automatic · no gap');
        status.classList.toggle('adjusted', item.gap > 0);
        const reset = row.querySelector('[data-action=reset-gap]');
        if (reset) reset.hidden = item.gap <= 0;
      }
    });
    document.getElementById('playlistTotal').textContent = duration(timeline.total);
    document.getElementById('timelineFilmDuration').textContent = duration(timeline.filmTotal);
    document.getElementById('timelineGapDuration').textContent = duration(timeline.gapTotal);
    document.getElementById('timelineTotalDuration').textContent = duration(timeline.total);
    document.getElementById('timelineScheduleStart').textContent = startEpoch === null ? '—' : formatMoment(startEpoch);
    document.getElementById('timelineScheduleEnd').textContent = startEpoch === null ? '—' : formatMoment(startEpoch + timeline.total);
    document.getElementById('timelineTimezone').textContent = timezoneInput.value || '—';
  }
  function targetsChanged() { updateTargetSummary(); rebuildGenreFilter(); rebuildPicker(); render(); }
  for (const check of targetChecks) check.addEventListener('change', targetsChanged);
  for (const group of locationGroups) {
    const parent = group.querySelector('[data-target-location-check]');
    parent.addEventListener('click', event => event.stopPropagation());
    parent.addEventListener('change', () => {
      for (const child of group.querySelectorAll('[data-target-device]')) child.checked = parent.checked;
      targetsChanged();
    });
  }
  targetSearch.addEventListener('input', () => {
    const query = targetSearch.value.trim().toLowerCase(); let visibleGroups = 0;
    for (const group of locationGroups) {
      let visibleOptions = 0;
      for (const option of group.querySelectorAll('[data-target-option]')) { const visible = !query || option.dataset.searchText.includes(query); option.hidden = !visible; if (visible) visibleOptions++; }
      const visible = !query || group.dataset.searchText.includes(query) || visibleOptions > 0; group.hidden = !visible; if (visible) { visibleGroups++; if (query) group.open = true; }
    }
    document.getElementById('scheduleTargetEmpty').hidden = visibleGroups > 0;
  });
  document.addEventListener('click', event => { if (targetPicker.open && !targetPicker.contains(event.target)) targetPicker.open = false; });
  mediaSearch.addEventListener('input', rebuildPicker); mediaTypeFilter.addEventListener('change', rebuildPicker); mediaGenreFilter.addEventListener('change', rebuildPicker);
  startInput.addEventListener('input', updateTotal); timezoneInput.addEventListener('change', updateTotal);
  recurrence.addEventListener('change', updateRecurrenceFields);
  autoExpiryUntil.addEventListener('change', syncExpiryEndDate);
  document.querySelector('input[name=loop_enabled]')?.addEventListener('change', render);
  recurrenceUntil.addEventListener('input', () => {
    if (autoExpiryUntil.checked && recurrenceUntil.value !== recurrenceUntil.dataset.autoValue) autoExpiryUntil.checked = false;
    syncExpiryEndDate();
  });
  document.getElementById('addMedia').onclick = () => { const item = mediaMap().get(picker.value); if (!item || playlist.some(entry => entry.mediaKey === item.mediaKey)) return; playlist.push({ mediaKey: item.mediaKey, durationMs: item.durationMs, gapAfterMs: 0 }); picker.value = ''; render(); };
  updateTargetSummary(); rebuildGenreFilter(); rebuildPicker(); updateRecurrenceFields(); const available = mediaMap(); playlist = initial.filter(item => available.has(item.mediaKey)).map(item => ({ mediaKey: item.mediaKey, durationMs: item.durationMs || available.get(item.mediaKey).durationMs, gapAfterMs: item.gapAfterMs || 0 })); render();
})();
</script>
<?= view('web/_layout_bottom') ?>
