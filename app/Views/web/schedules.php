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
$oldPlaybackStartOffsets = old('playback_start_offset_ms');
$oldGaps = old('gap_after_ms');
$oldVolumes = old('volume_percent');
$initialItems = [];
if (is_array($oldKeys)) {
    foreach (array_values($oldKeys) as $index => $key) $initialItems[] = ['mediaKey' => $key, 'durationMs' => (int) ($oldDurations[$index] ?? 0), 'startOffsetMs' => (int) ($oldPlaybackStartOffsets[$index] ?? 0), 'gapAfterMs' => (int) ($oldGaps[$index] ?? 0), 'volumePercent' => (int) ($oldVolumes[$index] ?? 100)];
} else {
    foreach ($editingItems as $item) $initialItems[] = ['mediaKey' => $item['media_key'], 'durationMs' => (int) $item['duration_override_ms'], 'startOffsetMs' => (int) ($item['playback_start_offset_ms'] ?? 0), 'gapAfterMs' => (int) ($item['gap_after_ms'] ?? 0), 'volumePercent' => (int) ($item['volume_percent'] ?? 100)];
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
$scheduleDirectory ??= ['all' => $schedules, 'filters' => [], 'options' => ['locations' => [], 'assets' => []], 'total' => count($schedules), 'total_all' => count($schedules), 'page' => 1, 'pages' => 1];
$directoryFilters = $scheduleDirectory['filters'];
$directoryOptions = $scheduleDirectory['options'];
$filteredSchedules = $scheduleDirectory['all'];
$directoryQuery = http_build_query(array_filter($directoryFilters, static fn ($value): bool => $value !== null && $value !== '' && $value !== []));
$pageQuery = static function (int $page) use ($directoryFilters): string {
    $values = $directoryFilters;
    $values['page'] = $page;
    return http_build_query(array_filter($values, static fn ($value): bool => $value !== null && $value !== '' && $value !== []));
};
$selectedLocations = (array) ($directoryFilters['location_ids'] ?? []);
$selectedStudios = (array) ($directoryFilters['device_ids'] ?? []);
$selectedAssets = (array) ($directoryFilters['asset_ids'] ?? []);
?>
<div class="cms-page-toolbar"><div><p>DELIVERY PLAN</p><h2>Playback schedules</h2><span>Create one-time, daily, or weekly playlists for one or more Studios.</span></div><div class="cms-toolbar-actions"><span class="count"><?= (int) $scheduleDirectory['total'] ?> of <?= (int) $scheduleDirectory['total_all'] ?> Schedules</span><button class="btn ghost" type="button" data-cms-modal-open="bulk-disable-schedules" <?= $filteredSchedules === [] ? 'disabled' : '' ?>>Disable Schedules</button><button class="btn danger" type="button" data-cms-modal-open="bulk-delete-schedules" <?= $filteredSchedules === [] ? 'disabled' : '' ?>>Delete Schedules</button><button class="btn primary" type="button" data-cms-modal-open="schedule-editor-modal" <?= $devices === [] ? 'disabled title="Pair an active Studio first"' : '' ?>>+ Create Schedule</button></div></div>
<?php if ($devices === []): ?><div class="alert error">Create and pair an active Studio before adding schedules.</div><?php endif ?>

<form class="schedule-directory-filter" method="get" action="<?= site_url('control/schedules') ?>" id="scheduleDirectoryFilter">
  <label class="schedule-filter-search">Search schedules<input type="search" name="q" value="<?= esc($directoryFilters['q'] ?? '') ?>" placeholder="Title, Studio, Location, asset, day, or date"></label>
  <details class="schedule-filter-picker" id="directoryLocationPicker"><summary><span>Location / Studio<?= $selectedLocations || $selectedStudios ? ' · ' . (count($selectedLocations) + count($selectedStudios)) : '' ?></span><b>⌄</b></summary><div class="schedule-filter-popover"><input type="search" data-filter-options-search placeholder="Search Location or Studio"><div class="schedule-filter-options"><?php foreach ($directoryOptions['locations'] as $location): ?><details data-directory-location data-search-text="<?= esc(mb_strtolower($location['name'] . ' ' . implode(' ', array_column($location['studios'], 'name'))), 'attr') ?>"><summary><label><input type="checkbox" name="location_ids[]" value="<?= esc($location['id'], 'attr') ?>" data-directory-location-check <?= in_array($location['id'], $selectedLocations, true) ? 'checked' : '' ?>><span><strong><?= esc($location['name']) ?></strong><small><?= count($location['studios']) ?> Studio(s)</small></span></label><b>⌄</b></summary><div><?php foreach ($location['studios'] as $studio): ?><label data-search-text="<?= esc(mb_strtolower($studio['name']), 'attr') ?>"><input type="checkbox" name="device_ids[]" value="<?= esc($studio['id'], 'attr') ?>" data-directory-device data-location-id="<?= esc($location['id'], 'attr') ?>" <?= in_array($studio['id'], $selectedStudios, true) ? 'checked' : '' ?>><span><?= esc($studio['name']) ?></span></label><?php endforeach ?></div></details><?php endforeach ?></div></div></details>
  <details class="schedule-filter-picker" id="directoryAssetPicker"><summary><span>Assets<?= $selectedAssets ? ' · ' . count($selectedAssets) : '' ?></span><b>⌄</b></summary><div class="schedule-filter-popover"><input type="search" data-filter-options-search placeholder="Search asset"><div class="schedule-filter-options asset-options"><?php foreach ($directoryOptions['assets'] as $asset): ?><label data-directory-asset data-search-text="<?= esc(mb_strtolower($asset['title']), 'attr') ?>" data-location-ids="<?= esc(implode(',', $asset['location_ids']), 'attr') ?>" data-device-ids="<?= esc(implode(',', $asset['device_ids']), 'attr') ?>"><input type="checkbox" name="asset_ids[]" value="<?= esc($asset['id'], 'attr') ?>" <?= in_array($asset['id'], $selectedAssets, true) ? 'checked' : '' ?>><span><?= esc($asset['title']) ?></span></label><?php endforeach ?><p class="empty" data-directory-asset-empty hidden>No schedule asset is available for the selected target.</p></div></div></details>
  <label>Date from<input type="date" name="date_from" value="<?= esc($directoryFilters['date_from'] ?? '') ?>"></label>
  <label>Date to<input type="date" name="date_to" value="<?= esc($directoryFilters['date_to'] ?? '') ?>"></label>
  <label>Time<select name="period"><option value="">Any time</option><option value="morning" <?= in_array('morning', (array) ($directoryFilters['period'] ?? []), true) ? 'selected' : '' ?>>Morning · 00:00–09:00</option><option value="noon" <?= in_array('noon', (array) ($directoryFilters['period'] ?? []), true) ? 'selected' : '' ?>>Noon · 09:00–15:00</option><option value="night" <?= in_array('night', (array) ($directoryFilters['period'] ?? []), true) ? 'selected' : '' ?>>Night · 15:00–24:00</option></select></label>
  <label>Status<select name="status"><option value="">Any status</option><?php foreach (['active' => 'Active now', 'upcoming' => 'Upcoming', 'completed' => 'Completed', 'disabled' => 'Disabled'] as $value => $label): ?><option value="<?= $value ?>" <?= in_array($value, (array) ($directoryFilters['status'] ?? []), true) ? 'selected' : '' ?>><?= $label ?></option><?php endforeach ?></select></label>
  <div class="schedule-filter-actions"><a class="btn ghost" href="<?= site_url('control/schedules') ?>">Reset</a><button class="btn primary" type="submit">Apply Filters</button></div>
</form>
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
    <label>Description (optional)<input name="description" value="<?= esc($formDescription) ?>" maxlength="1000" placeholder="Notes for this playback"></label>
    <div class="schedule-compose-grid">
      <section class="schedule-compose-card recurrence-card">
        <div class="section-heading"><div><p>OCCURRENCE</p><h2>Repeat</h2></div></div>
        <div class="recurrence-panel" id="scheduleRecurrencePanel" data-mode="<?= esc($formRecurrence, 'attr') ?>">
          <label class="schedule-repeat-field">Repeat<select id="scheduleRecurrence" name="recurrence"><option value="one_time" <?= $formRecurrence === 'one_time' ? 'selected' : '' ?>>One time</option><option value="daily" <?= $formRecurrence === 'daily' ? 'selected' : '' ?>>Daily</option><option value="weekly" <?= $formRecurrence === 'weekly' ? 'selected' : '' ?>>Weekly</option></select></label>
          <fieldset id="weekdayFields"><legend>Play on</legend><div class="weekday-options"><?php foreach ([1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat', 7 => 'Sun'] as $day => $name): ?><label><input type="checkbox" name="days_of_week[]" value="<?= $day ?>" <?= in_array($day, $formDays, true) ? 'checked' : '' ?>><?= $name ?></label><?php endforeach ?></div></fieldset>
          <div id="recurrenceUntilField" class="schedule-until-field"><label><span class="schedule-until-label">End date <small class="field-note">(optional when every film has no expiry)</small></span><input id="scheduleRecurrenceUntil" type="date" name="recurrence_until" value="<?= esc($formUntil) ?>"></label><input type="hidden" name="auto_expiry_until" value="0"><div class="schedule-auto-expiry-field"><span>Expiry policy</span><label class="schedule-auto-expiry"><input id="scheduleAutoExpiry" type="checkbox" name="auto_expiry_until" value="1" <?= (string) $formAutoExpiry === '1' ? 'checked' : '' ?>> Use the earliest film expiry automatically</label></div><small id="scheduleExpiryHint" class="schedule-expiry-hint"></small></div>
        </div>
        <div class="default-gap-panel">
          <div><span class="schedule-field-label">Default film gap</span><small>Applied automatically between films. Individual gaps can still be adjusted in the playlist.</small></div>
          <fieldset class="compact-gap-fields" id="defaultGapFields"><legend class="sr-only">Default film gap</legend><label><input data-unit="hours" type="number" min="0" max="24" inputmode="numeric"> h</label><label><input data-unit="minutes" type="number" min="0" max="59" inputmode="numeric"> m</label><label><input data-unit="seconds" type="number" min="0" max="59" inputmode="numeric"> s</label></fieldset>
          <button class="btn ghost default-gap-apply" id="applyDefaultGap" type="button">Apply to all gaps</button>
        </div>
      </section>
      <section class="schedule-compose-card asset-selection-card">
        <div class="section-heading"><div><p>MEDIA LIBRARY</p><h2>Select assets</h2></div><span id="mediaPickerCount" class="badge">0 Ready</span></div>
        <div class="media-picker-filters"><input id="mediaSearch" type="search" placeholder="Search film title or filename"><select id="mediaTypeFilter"><option value="">All types</option><option value="featured">Featured</option><option value="ads">Ads</option><option value="trailer">Trailer</option><option value="local">Local media</option></select><select id="mediaGenreFilter"><option value="">All genres</option></select></div>
        <div id="mediaPickerList" class="media-picker-list"></div>
        <div id="mediaPickerEmpty" class="empty media-picker-empty">Choose one or more Studios to see Ready media.</div>
      </section>
    </div>
    <div class="playlist-builder">
      <div class="section-heading"><div><p>PLAYLIST</p><h2>Selected assets</h2></div><span id="playlistTotal" class="badge">00:00:00</span></div>
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

<?php foreach (['disable' => ['title' => 'Disable schedules', 'action' => 'bulk-disable', 'button' => 'Disable selected', 'message' => 'Future occurrences stop on every target Player. Existing playback is not forcibly interrupted.'], 'delete' => ['title' => 'Delete schedules', 'action' => 'bulk-delete', 'button' => 'Delete selected', 'message' => 'This permanently removes the selected schedules and their cached Player definitions.']] as $bulkMode => $bulk): ?>
<dialog class="cms-action-modal wide schedule-bulk-modal" id="bulk-<?= $bulkMode ?>-schedules" data-cms-modal>
  <form method="post" action="<?= site_url('control/schedules/' . $bulk['action']) ?>" class="cms-modal-shell" data-bulk-schedule-form>
    <?= csrf_field() ?><input type="hidden" name="return_query" value="<?= esc($directoryQuery, 'attr') ?>">
    <header class="cms-modal-header"><div><p>BULK SCHEDULE MANAGEMENT</p><h2><?= esc($bulk['title']) ?></h2><span>The active directory filters are already applied. Select up to 100 schedules.</span></div><button class="cms-modal-x" type="button" data-cms-modal-close>×</button></header>
    <div class="cms-modal-body">
      <div class="cms-confirm-message <?= $bulkMode === 'delete' ? 'danger' : '' ?>"><strong><?= esc($bulk['message']) ?></strong>One device revision and realtime notification is produced per affected Studio.</div>
      <div class="schedule-bulk-tools"><input type="search" data-bulk-schedule-search placeholder="Search within these <?= (int) $scheduleDirectory['total'] ?> results"><label><input type="checkbox" data-bulk-select-visible> Select visible</label><strong data-bulk-selection-count>0 selected</strong></div>
      <div class="schedule-bulk-list">
        <?php foreach ($filteredSchedules as $schedule):
          $bulkText = mb_strtolower($schedule['title'] . ' ' . implode(' ', array_column($schedule['targets'], 'name')) . ' ' . implode(' ', array_column($schedule['targets'], 'location')) . ' ' . implode(' ', array_column($schedule['items'], 'title_snapshot')) . ' ' . $schedule['display_status']);
          $cannotDisable = $bulkMode === 'disable' && $schedule['status'] === 'disabled';
        ?>
        <label data-bulk-schedule-row data-search-text="<?= esc($bulkText, 'attr') ?>" class="<?= $cannotDisable ? 'disabled' : '' ?>"><input type="checkbox" name="schedule_ids[]" value="<?= esc($schedule['public_id'], 'attr') ?>" data-bulk-schedule-check <?= $cannotDisable ? 'disabled' : '' ?>><span><strong><?= esc($schedule['title']) ?></strong><small><?= esc(implode(', ', array_column($schedule['targets'], 'name'))) ?> · <?= esc(strtoupper($schedule['display_status'])) ?> · <?= count($schedule['items']) ?> asset(s)</small></span></label>
        <?php endforeach ?>
      </div>
    </div>
    <footer class="cms-modal-footer"><span>Only explicitly selected schedules are changed.</span><div><button class="btn ghost" type="button" data-cms-modal-close>Cancel</button><button class="btn <?= $bulkMode === 'delete' ? 'danger' : 'primary' ?>" type="submit" data-bulk-submit disabled><?= esc($bulk['button']) ?></button></div></footer>
  </form>
</dialog>
<?php endforeach ?>

<section class="schedule-list">
  <div class="section-heading"><div><p>SCHEDULE DIRECTORY</p><h2>Matching schedules</h2></div><span class="badge"><?= (int) $scheduleDirectory['total'] ?> results</span></div>
  <?php if ($schedules === []): ?><article class="card empty">No schedule matches these filters. Reset the filters or create a new schedule.</article><?php endif ?>
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
            <div class="schedule-item-title"><span><?= esc($item['title_snapshot']) ?></span><small>Source <?= esc($formatDuration((int) $item['duration_override_ms'])) ?><?= (int) $item['playback_start_offset_ms'] > 0 ? ' · Starts from ' . esc($formatDuration((int) $item['playback_start_offset_ms'])) : '' ?> · Plays <?= esc($formatDuration((int) $item['effective_duration_ms'])) ?><?= $effectiveGap > 0 ? ' · Gap ' . esc($formatDuration($effectiveGap)) : '' ?> · Volume <?= (int) ($item['volume_percent'] ?? 100) ?>%</small></div>
            <div class="schedule-item-times"><span><small>STARTS AT</small><?= esc($itemStart->format('Y-m-d H:i:s')) ?></span><span><small>CONTENT ENDS</small><?= esc($contentEnd->format('Y-m-d H:i:s')) ?></span><span><small><?= $effectiveGap > 0 ? 'NEXT START' : 'TIMELINE END' ?></small><?= esc(($effectiveGap > 0 ? $nextStart : $contentEnd)->format('Y-m-d H:i:s')) ?></span></div>
          </li>
        <?php endforeach ?>
      </ol>
      <div class="schedule-actions"><a class="btn ghost" href="<?= site_url('control/schedules?edit=' . rawurlencode($schedule['public_id'])) ?>">Edit</a><button class="btn ghost" type="button" data-cms-modal-open="status-schedule-<?= esc($schedule['public_id'], 'attr') ?>"><?= $schedule['status'] === 'active' ? 'Disable' : 'Enable' ?></button><button class="btn danger" type="button" data-cms-modal-open="delete-schedule-<?= esc($schedule['public_id'], 'attr') ?>">Delete</button></div>
    </article>
    <dialog class="cms-action-modal" id="status-schedule-<?= esc($schedule['public_id'], 'attr') ?>" data-cms-modal><form method="post" action="<?= site_url('control/schedules/' . rawurlencode($schedule['public_id']) . '/status') ?>" class="cms-modal-shell"><?= csrf_field() ?><input type="hidden" name="enabled" value="<?= $schedule['status'] === 'active' ? '0' : '1' ?>"><header class="cms-modal-header"><div><p>SCHEDULE STATUS</p><h2><?= $schedule['status'] === 'active' ? 'Disable' : 'Enable' ?> <?= esc($schedule['title']) ?>?</h2></div><button class="cms-modal-x" type="button" data-cms-modal-close>×</button></header><div class="cms-modal-body"><div class="cms-confirm-message <?= $schedule['status'] === 'active' ? 'danger' : '' ?>"><strong><?= $schedule['status'] === 'active' ? 'Future playback occurrences will be disabled.' : 'The schedule will become available to all target Players.' ?></strong>The updated revision is delivered to every target Studio when it refreshes or receives its realtime notification.</div></div><footer class="cms-modal-footer"><span>Schedule status change</span><div><button class="btn ghost" type="button" data-cms-modal-close>Cancel</button><button class="btn <?= $schedule['status'] === 'active' ? 'danger' : 'primary' ?>" type="submit">Confirm <?= $schedule['status'] === 'active' ? 'Disable' : 'Enable' ?></button></div></footer></form></dialog>
    <dialog class="cms-action-modal" id="delete-schedule-<?= esc($schedule['public_id'], 'attr') ?>" data-cms-modal><form method="post" action="<?= site_url('control/schedules/' . rawurlencode($schedule['public_id']) . '/delete') ?>" class="cms-modal-shell"><?= csrf_field() ?><header class="cms-modal-header"><div><p>DANGER ZONE</p><h2>Delete <?= esc($schedule['title']) ?>?</h2></div><button class="cms-modal-x" type="button" data-cms-modal-close>×</button></header><div class="cms-modal-body"><div class="cms-confirm-message danger"><strong>This schedule will be permanently removed.</strong>All target Players remove it from their local schedule cache on the next refresh.</div></div><footer class="cms-modal-footer"><span>Permanent deletion</span><div><button class="btn ghost" type="button" data-cms-modal-close>Cancel</button><button class="btn danger" type="submit">Delete Schedule</button></div></footer></form></dialog>
  <?php endforeach ?>
  <?php if ((int) $scheduleDirectory['pages'] > 1): ?><nav class="schedule-pagination" aria-label="Schedule directory pages"><?php if ((int) $scheduleDirectory['page'] > 1): ?><a class="page-move" href="<?= site_url('control/schedules') . '?' . esc($pageQuery((int) $scheduleDirectory['page'] - 1), 'attr') ?>">← Previous</a><?php endif ?><?php for ($page = 1; $page <= (int) $scheduleDirectory['pages']; $page++): ?><a class="<?= $page === (int) $scheduleDirectory['page'] ? 'active' : '' ?>" href="<?= site_url('control/schedules') . '?' . esc($pageQuery($page), 'attr') ?>" <?= $page === (int) $scheduleDirectory['page'] ? 'aria-current="page"' : '' ?>><?= $page ?></a><?php endfor ?><?php if ((int) $scheduleDirectory['page'] < (int) $scheduleDirectory['pages']): ?><a class="page-move" href="<?= site_url('control/schedules') . '?' . esc($pageQuery((int) $scheduleDirectory['page'] + 1), 'attr') ?>">Next →</a><?php endif ?></nav><?php endif ?>
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
  const mediaPickerList = document.getElementById('mediaPickerList');
  const mediaPickerEmpty = document.getElementById('mediaPickerEmpty');
  const mediaPickerCount = document.getElementById('mediaPickerCount');
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
  const loopInput = document.querySelector('input[name=loop_enabled]');
  const defaultGapFields = document.getElementById('defaultGapFields');
  const applyDefaultGap = document.getElementById('applyDefaultGap');
  let playlist = [];
  const duration = ms => { const s = Math.max(0, Math.round(Number(ms) / 1000)); return [Math.floor(s / 3600), Math.floor(s % 3600 / 60), s % 60].map(v => String(v).padStart(2, '0')).join(':'); };
  const readTimeFields = box => { const h = Math.max(0, Number(box.querySelector('[data-unit=hours]').value) || 0); const m = Math.max(0, Math.min(59, Number(box.querySelector('[data-unit=minutes]').value) || 0)); const s = Math.max(0, Math.min(59, Number(box.querySelector('[data-unit=seconds]').value) || 0)); return (h * 3600 + m * 60 + s) * 1000; };
  const setTimeFields = (box, milliseconds) => { const seconds = Math.max(0, Math.round(Number(milliseconds || 0) / 1000)); box.querySelector('[data-unit=hours]').value = Math.floor(seconds / 3600); box.querySelector('[data-unit=minutes]').value = Math.floor(seconds % 3600 / 60); box.querySelector('[data-unit=seconds]').value = seconds % 60; };
  const mediaFilename = item => String(item.storageFilename || item.filename || 'Unknown filename');
  const compactFilename = value => {
    const filename = String(value || 'Unknown filename');
    if (!/\.ldg$/i.test(filename) || filename.length <= 22) return filename;
    return `${filename.slice(0, 14)}\u2026.ldg`;
  };
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
  function renderMediaPicker() {
    const available = mediaMap();
    const search = mediaSearch.value.trim().toLowerCase(); const type = mediaTypeFilter.value; const genre = mediaGenreFilter.value;
    const selectedKeys = new Set(playlist.map(item => item.mediaKey));
    const visible = [...available.values()].filter(item => {
      const searchable = `${item.title} ${item.filename} ${item.storageFilename || ''} ${item.type || ''} ${(item.genres || []).join(' ')}`.toLowerCase();
      return (!search || searchable.includes(search)) && (!type || item.type === type) && (!genre || (item.genres || []).includes(genre));
    });
    mediaPickerList.innerHTML = '';
    mediaPickerCount.textContent = `${visible.length}${visible.length !== available.size ? ` of ${available.size}` : ''} Ready`;
    for (const item of visible) {
      const selected = selectedKeys.has(item.mediaKey);
      const card = document.createElement('article'); card.className = `media-choice-card${selected ? ' selected' : ''}`;
      const poster = document.createElement('div'); poster.className = 'media-choice-poster';
      const fallback = document.createElement('span'); fallback.className = 'media-choice-poster-fallback'; fallback.textContent = String(item.title || 'M').trim().charAt(0).toUpperCase() || 'M';
      poster.appendChild(fallback);
      if (item.posterUrl) {
        const image = document.createElement('img'); image.src = item.posterUrl; image.alt = ''; image.loading = 'lazy';
        image.addEventListener('load', () => { fallback.hidden = true; });
        image.addEventListener('error', () => { image.hidden = true; fallback.hidden = false; });
        poster.prepend(image);
      }
      const copy = document.createElement('div'); copy.className = 'media-choice-copy';
      const heading = document.createElement('div'); heading.className = 'media-choice-heading';
      const title = document.createElement('strong'); title.textContent = item.title || item.filename || 'Untitled media'; title.title = title.textContent;
      const typeBadge = document.createElement('span'); typeBadge.className = 'media-choice-type'; typeBadge.textContent = String(item.type || 'local').toUpperCase();
      heading.append(title, typeBadge);
      const filename = document.createElement('small'); const fullFilename = mediaFilename(item); filename.textContent = compactFilename(fullFilename); filename.title = fullFilename;
      const facts = document.createElement('div'); facts.className = 'media-choice-facts';
      for (const value of [duration(item.durationMs), item.source === 'managed' ? 'Downloaded' : 'Media Folder', ...(item.genres || []).slice(0, 2)]) { const chip = document.createElement('span'); chip.textContent = value; facts.appendChild(chip); }
      if (item.expiresOn) { const expiry = document.createElement('span'); expiry.className = 'media-choice-expiry'; expiry.textContent = `Expires ${item.expiresOn}`; facts.appendChild(expiry); }
      copy.append(heading, filename, facts);
      const add = document.createElement('button'); add.type = 'button'; add.className = `btn ${selected ? 'ghost' : 'primary'} media-choice-action`; add.textContent = selected ? 'Selected' : 'Add'; add.disabled = selected;
      add.addEventListener('click', () => { if (selectedKeys.has(item.mediaKey)) return; playlist.push({ mediaKey: item.mediaKey, durationMs: item.durationMs, startOffsetMs: 0, gapAfterMs: defaultGapMs, gapOverridden: false, volumePercent: 100 }); render(); });
      card.append(poster, copy, add); mediaPickerList.appendChild(card);
    }
    const hasTargets = selectedDevices().length > 0;
    mediaPickerEmpty.textContent = !hasTargets ? 'Choose one or more Studios to see Ready media.' : (available.size === 0 ? 'No Ready media is shared by every selected Studio.' : 'No media matches these filters.');
    mediaPickerEmpty.hidden = visible.length > 0;
  }
  function rebuildGenreFilter() { const selected = mediaGenreFilter.value; const genres = [...new Set([...mediaMap().values()].flatMap(item => item.genres || []))].sort(); mediaGenreFilter.innerHTML = '<option value="">All genres</option>'; for (const genre of genres) { const option = document.createElement('option'); option.value = genre; option.textContent = genre; mediaGenreFilter.appendChild(option); } if (genres.includes(selected)) mediaGenreFilter.value = selected; }
  function render() {
    const available = mediaMap(); rows.innerHTML = '';
    playlist = playlist.filter(item => available.has(item.mediaKey));
    playlist.forEach((entry, index) => {
      const media = available.get(entry.mediaKey); const row = document.createElement('div'); row.className = 'playlist-row'; row.dataset.timelineIndex = String(index);
      row.innerHTML = `<span class="playlist-order">${index + 1}</span><span class="playlist-name"><strong data-playlist-title></strong><small class="playlist-duration" data-playlist-duration></small><small class="playlist-file" data-playlist-file></small></span><fieldset class="film-volume"><legend>Film volume</legend><label><input data-volume-range type="range" min="0" max="100" step="1"><output data-volume-output>100%</output></label></fieldset><span class="playlist-controls"><button type="button" class="btn ghost" data-action="up" aria-label="Move film up">↑</button><button type="button" class="btn ghost" data-action="down" aria-label="Move film down">↓</button><button type="button" class="btn danger" data-action="remove" aria-label="Remove film">×</button></span><div class="playlist-item-timeline"><span class="timeline-clock" data-start-clock><small>STARTS AT</small><strong data-timeline-start>—</strong><input data-timeline-start-input type="datetime-local" step="1" hidden><em data-start-note></em><b class="timeline-clock-error" data-start-error></b></span><span class="timeline-clock"><small>CONTENT ENDS</small><strong data-timeline-end>—</strong><em data-played-duration>Calculated from film duration</em></span><span class="timeline-clock" data-boundary-clock><small data-timeline-next-label>TIMELINE ENDS</small><strong data-timeline-next>—</strong><div class="timeline-boundary-controls"><input data-timeline-boundary-input type="datetime-local" step="1" hidden><button data-action="reset-gap" class="btn ghost" type="button" hidden>Reset</button></div><em data-timeline-status></em><b class="timeline-clock-error" data-boundary-error></b></span></div><input type="hidden" name="media_keys[]"><input type="hidden" name="duration_ms[]"><input type="hidden" name="playback_start_offset_ms[]"><input type="hidden" name="gap_after_ms[]"><input type="hidden" name="volume_percent[]">`;
      const sourceDurationMs = Math.max(0, Number(media.durationMs) || 0);
      const fullFilename = mediaFilename(media);
      row.querySelector('[data-playlist-title]').textContent = media.title;
      row.querySelector('[data-playlist-duration]').textContent = `Duration ${duration(sourceDurationMs)}`;
      row.querySelector('[data-playlist-file]').textContent = compactFilename(fullFilename);
      row.querySelector('[data-playlist-file]').title = fullFilename;
      const durationHidden = row.querySelector('input[name="duration_ms[]"]');
      const playbackStartOffsetHidden = row.querySelector('input[name="playback_start_offset_ms[]"]');
      const gapHidden = row.querySelector('input[name="gap_after_ms[]"]');
      const volumeHidden = row.querySelector('input[name="volume_percent[]"]');
      row.querySelector('input[name="media_keys[]"]').value = entry.mediaKey;
      entry.durationMs = sourceDurationMs;
      entry.startOffsetMs = 0;
      durationHidden.value = String(sourceDurationMs);
      playbackStartOffsetHidden.value = '0';
      gapHidden.value = String(Math.max(0, Number(entry.gapAfterMs) || 0));
      const volumeRange = row.querySelector('[data-volume-range]');
      const volumeOutput = row.querySelector('[data-volume-output]');
      const syncVolume = () => { const value = Math.max(0, Math.min(100, Math.round(Number(volumeRange.value) || 0))); entry.volumePercent = value; volumeHidden.value = String(value); volumeOutput.value = `${value}%`; volumeOutput.textContent = `${value}%`; };
      volumeRange.value = String(Number.isFinite(Number(entry.volumePercent)) ? entry.volumePercent : 100);
      volumeRange.addEventListener('input', syncVolume);
      syncVolume();
      const gapActive = index < playlist.length - 1 || loopInput?.checked;
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
        resetGap.onclick = () => { entry.gapAfterMs = defaultGapMs; entry.gapOverridden = false; render(); };
      }
      for (const input of [manualStart, boundaryInput]) input.addEventListener('input', () => {
        input.classList.remove('invalid');
      });
      row.querySelector('[data-action=up]').onclick = () => { if (index > 0) [playlist[index - 1], playlist[index]] = [playlist[index], playlist[index - 1]]; render(); };
      row.querySelector('[data-action=down]').onclick = () => { if (index < playlist.length - 1) [playlist[index + 1], playlist[index]] = [playlist[index], playlist[index + 1]]; render(); };
      row.querySelector('[data-action=remove]').onclick = () => { playlist.splice(index, 1); render(); };
      rows.appendChild(row);
      if (gapActive) {
        const divider = document.createElement('div'); divider.className = 'playlist-gap-divider'; divider.dataset.gapIndex = String(index);
        divider.innerHTML = `<span aria-hidden="true"></span><div class="playlist-gap-control" role="group" aria-label="${index === playlist.length - 1 ? 'Loop gap' : 'Gap after film'}"><strong>${index === playlist.length - 1 ? 'Loop gap' : 'Gap'}</strong><label><input data-unit="hours" type="number" min="0" max="24" inputmode="numeric" aria-label="Gap hours"> h</label><label><input data-unit="minutes" type="number" min="0" max="59" inputmode="numeric" aria-label="Gap minutes"> m</label><label><input data-unit="seconds" type="number" min="0" max="59" inputmode="numeric" aria-label="Gap seconds"> s</label><button type="button" data-use-default title="Use default gap">Default</button></div><span aria-hidden="true"></span>`;
        const fields = divider.querySelector('.playlist-gap-control');
        const reset = divider.querySelector('[data-use-default]');
        const syncGap = () => { entry.gapAfterMs = readTimeFields(fields); entry.gapOverridden = entry.gapAfterMs !== defaultGapMs; gapHidden.value = String(entry.gapAfterMs); reset.hidden = !entry.gapOverridden; updateTotal(); };
        setTimeFields(fields, entry.gapAfterMs);
        fields.querySelectorAll('input').forEach(input => input.addEventListener('input', syncGap));
        reset.hidden = !entry.gapOverridden;
        reset.addEventListener('click', () => { entry.gapAfterMs = defaultGapMs; entry.gapOverridden = false; gapHidden.value = String(defaultGapMs); setTimeFields(fields, defaultGapMs); reset.hidden = true; updateTotal(); });
        rows.appendChild(divider);
      }
    });
    document.getElementById('playlistEmpty').style.display = playlist.length ? 'none' : 'block'; renderMediaPicker(); updateTotal(); syncExpiryEndDate();
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
      const sourceDuration = Math.max(0, Number(item.durationMs || available.get(item.mediaKey)?.durationMs || 0));
      const startOffset = Math.max(0, Number(item.startOffsetMs || 0));
      const filmDuration = Math.max(0, sourceDuration - startOffset);
      const gap = (index < playlist.length - 1 || loop) ? Math.max(0, Number(item.gapAfterMs || 0)) : 0;
      const contentEnd = cursor + filmDuration; const nextStart = contentEnd + gap;
      items.push({ start: cursor, contentEnd, nextStart, sourceDuration, startOffset, filmDuration, gap });
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
        playlist[previousIndex].gapOverridden = gap !== defaultGapMs;
        render();
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
        row.querySelector('[data-played-duration]').textContent = `Plays ${duration(item.filmDuration)}`;
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
  function targetsChanged() { updateTargetSummary(); rebuildGenreFilter(); render(); }
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
  mediaSearch.addEventListener('input', renderMediaPicker); mediaTypeFilter.addEventListener('change', renderMediaPicker); mediaGenreFilter.addEventListener('change', renderMediaPicker);
  startInput.addEventListener('input', updateTotal); timezoneInput.addEventListener('change', updateTotal);
  recurrence.addEventListener('change', updateRecurrenceFields);
  autoExpiryUntil.addEventListener('change', syncExpiryEndDate);
  loopInput?.addEventListener('change', render);
  recurrenceUntil.addEventListener('input', () => {
    if (autoExpiryUntil.checked && recurrenceUntil.value !== recurrenceUntil.dataset.autoValue) autoExpiryUntil.checked = false;
    syncExpiryEndDate();
  });
  const initialGapValues = initial.slice(0, Math.max(0, initial.length - (loopInput?.checked ? 0 : 1))).map(item => Math.max(0, Number(item.gapAfterMs) || 0));
  let defaultGapMs = initialGapValues.length > 0 && initialGapValues.every(value => value === initialGapValues[0]) ? initialGapValues[0] : 0;
  setTimeFields(defaultGapFields, defaultGapMs);
  const syncDefaultGap = applyToAll => {
    defaultGapMs = readTimeFields(defaultGapFields);
    playlist.forEach(entry => { if (applyToAll || !entry.gapOverridden) { entry.gapAfterMs = defaultGapMs; entry.gapOverridden = false; } });
    for (const entry of playlist) {
      const index = playlist.indexOf(entry); const row = rows.querySelector(`[data-timeline-index="${index}"]`); const divider = rows.querySelector(`[data-gap-index="${index}"]`);
      if (row) row.querySelector('input[name="gap_after_ms[]"]').value = String(entry.gapAfterMs);
      if (divider) { setTimeFields(divider.querySelector('.playlist-gap-control'), entry.gapAfterMs); divider.querySelector('[data-use-default]').hidden = !entry.gapOverridden; }
    }
    updateTotal();
  };
  defaultGapFields.querySelectorAll('input').forEach(input => input.addEventListener('input', () => syncDefaultGap(false)));
  applyDefaultGap.addEventListener('click', () => syncDefaultGap(true));
  updateTargetSummary(); rebuildGenreFilter(); updateRecurrenceFields(); const available = mediaMap(); playlist = initial.filter(item => available.has(item.mediaKey)).map(item => { const gapAfterMs = Math.max(0, Number(item.gapAfterMs) || 0); return { mediaKey: item.mediaKey, durationMs: available.get(item.mediaKey).durationMs, startOffsetMs: 0, gapAfterMs, gapOverridden: gapAfterMs !== defaultGapMs, volumePercent: Number.isFinite(Number(item.volumePercent)) ? Number(item.volumePercent) : 100 }; }); render();
})();
</script>
<script>
(() => {
  const filterForm = document.getElementById('scheduleDirectoryFilter');
  if (filterForm) {
    const locationGroups = [...filterForm.querySelectorAll('[data-directory-location]')];
    const locationChecks = [...filterForm.querySelectorAll('[data-directory-location-check]')];
    const deviceChecks = [...filterForm.querySelectorAll('[data-directory-device]')];
    const assetRows = [...filterForm.querySelectorAll('[data-directory-asset]')];
    const refreshAssets = () => {
      const locations = new Set(locationChecks.filter(check => check.checked).map(check => check.value));
      const devices = new Set(deviceChecks.filter(check => check.checked).map(check => check.value));
      let visible = 0;
      for (const row of assetRows) {
        const rowLocations = new Set((row.dataset.locationIds || '').split(',').filter(Boolean));
        const rowDevices = new Set((row.dataset.deviceIds || '').split(',').filter(Boolean));
        const targetMatch = (!locations.size && !devices.size)
          || [...locations].some(id => rowLocations.has(id))
          || [...devices].some(id => rowDevices.has(id));
        row.hidden = !targetMatch;
        if (targetMatch) visible++;
      }
      filterForm.querySelector('[data-directory-asset-empty]').hidden = visible > 0;
    };
    const syncLocation = (group, source = 'studio') => {
      const location = group.querySelector('[data-directory-location-check]');
      const studios = [...group.querySelectorAll('[data-directory-device]')];
      if (source === 'location') {
        for (const studio of studios) studio.checked = location.checked;
        location.indeterminate = false;
      } else {
        const selected = studios.filter(studio => studio.checked).length;
        location.checked = studios.length > 0 && selected === studios.length;
        location.indeterminate = selected > 0 && selected < studios.length;
      }
    };
    for (const group of locationGroups) {
      const location = group.querySelector('[data-directory-location-check]');
      const studios = [...group.querySelectorAll('[data-directory-device]')];
      location.addEventListener('click', event => event.stopPropagation());
      location.addEventListener('change', () => { syncLocation(group, 'location'); refreshAssets(); });
      for (const studio of studios) studio.addEventListener('change', () => { syncLocation(group); refreshAssets(); });
      syncLocation(group, location.checked ? 'location' : 'studio');
    }
    for (const input of filterForm.querySelectorAll('[data-filter-options-search]')) input.addEventListener('input', () => {
      const query = input.value.trim().toLowerCase();
      const container = input.closest('.schedule-filter-popover');
      for (const option of container.querySelectorAll('[data-search-text]')) {
        const matches = !query || (option.dataset.searchText || '').includes(query);
        if (option.matches('[data-directory-asset]')) option.hidden = !matches;
        else if (option.matches('details')) { option.hidden = !matches; if (query && matches) option.open = true; }
      }
      if (container.closest('#directoryAssetPicker')) refreshAssets();
    });
    refreshAssets();
  }

  for (const form of document.querySelectorAll('[data-bulk-schedule-form]')) {
    const search = form.querySelector('[data-bulk-schedule-search]');
    const rows = [...form.querySelectorAll('[data-bulk-schedule-row]')];
    const checks = [...form.querySelectorAll('[data-bulk-schedule-check]')];
    const selectVisible = form.querySelector('[data-bulk-select-visible]');
    const count = form.querySelector('[data-bulk-selection-count]');
    const submit = form.querySelector('[data-bulk-submit]');
    const update = () => {
      const selected = checks.filter(check => check.checked).length;
      count.textContent = `${selected} selected`;
      submit.disabled = selected === 0 || selected > 100;
      selectVisible.checked = rows.filter(row => !row.hidden && !row.querySelector('input').disabled).every(row => row.querySelector('input').checked);
    };
    search.addEventListener('input', () => {
      const query = search.value.trim().toLowerCase();
      for (const row of rows) row.hidden = !!query && !(row.dataset.searchText || '').includes(query);
      update();
    });
    selectVisible.addEventListener('change', () => {
      for (const row of rows) if (!row.hidden && !row.querySelector('input').disabled) row.querySelector('input').checked = selectVisible.checked;
      update();
    });
    for (const check of checks) check.addEventListener('change', update);
    update();
  }
})();
</script>
<?= view('web/_layout_bottom') ?>
