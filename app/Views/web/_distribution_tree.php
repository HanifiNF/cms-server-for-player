<?php
$isAssignMode = ($mode ?? 'assign') === 'assign';
?>
<div class="distribution-tree" data-distribution-tree data-mode="<?= $isAssignMode ? 'assign' : 'unassign' ?>">
  <?php foreach ($distributionLocations as $location):
    $visibleStudios = array_values(array_filter($location['studios'], static fn (array $studio): bool => $isAssignMode || $studio['assigned']));
    if ($visibleStudios === []) continue;
    $selectableCount = count(array_filter($visibleStudios, static fn (array $studio): bool => $isAssignMode ? ($studio['assignable'] && ! $studio['assigned']) : $studio['assigned']));
  ?>
    <details class="distribution-location" data-distribution-location data-search-text="<?= esc(mb_strtolower($location['name'] . ' ' . implode(' ', array_column($visibleStudios, 'name'))), 'attr') ?>">
      <summary>
        <label class="distribution-check" onclick="event.stopPropagation()">
          <?php if ($location['public_id'] !== null): ?><input type="checkbox" name="location_ids[]" value="<?= esc($location['public_id'], 'attr') ?>" data-location-check <?= $selectableCount === 0 ? 'disabled' : '' ?>><?php else: ?><span class="distribution-check-placeholder"></span><?php endif ?>
          <span><strong><?= esc($location['name']) ?></strong><small><?= count($visibleStudios) ?> Studio(s) · <?= $selectableCount ?> selectable</small></span>
        </label>
        <span class="distribution-chevron">⌄</span>
      </summary>
      <div class="distribution-studios">
        <?php foreach ($visibleStudios as $studio):
          $disabled = $isAssignMode ? (! $studio['assignable'] || $studio['assigned']) : ! $studio['assigned'];
          $reason = '';
          if ($isAssignMode && $studio['assigned']) $reason = 'Already assigned';
          elseif ($isAssignMode && ! $studio['compatible']) $reason = 'Player update required';
          elseif ($isAssignMode && $studio['status'] !== 'active') $reason = ucfirst($studio['status']);
          elseif ($isAssignMode && $location['status'] !== 'active') $reason = 'Location inactive';
          elseif (! $isAssignMode && $studio['assignment_status'] === 'removal_pending') $reason = 'Removal pending';
        ?>
          <label class="distribution-studio" data-distribution-studio data-search-text="<?= esc(mb_strtolower($location['name'] . ' ' . $studio['name']), 'attr') ?>">
            <input type="checkbox" name="device_ids[]" value="<?= esc($studio['public_id'], 'attr') ?>" data-studio-check <?= $disabled ? 'disabled' : '' ?>>
            <span><strong><?= esc($studio['name']) ?></strong><small><?= esc($reason !== '' ? $reason : ($isAssignMode ? 'Ready to assign' : strtoupper(str_replace('_', ' ', (string) $studio['assignment_status'])))) ?></small></span>
            <?php if ($studio['assigned']): ?><span class="badge asset-status <?= esc($studio['assignment_status']) ?>"><?= esc(strtoupper(str_replace('_', ' ', (string) $studio['assignment_status']))) ?></span><?php endif ?>
          </label>
        <?php endforeach ?>
      </div>
    </details>
  <?php endforeach ?>
  <div class="empty distribution-search-empty" data-distribution-empty hidden>No Location or Studio matches this search.</div>
</div>
