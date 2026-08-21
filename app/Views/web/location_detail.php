<?= view('web/_layout_top', get_defined_vars()) ?>
<section class="location-detail-head">
  <div><a class="back-link" href="<?= site_url('control/locations') ?>">← All Locations</a><p class="eyebrow">LOCATION · <?= esc($location->code) ?></p><h2><?= esc($location->name) ?></h2><span><?= esc($location->address ?: $location->timezone) ?></span></div>
  <div class="location-detail-actions"><span class="badge <?= esc($location->status) ?>"><?= esc(strtoupper($location->status)) ?></span><?php if ($location->status === 'active'): ?><button class="btn primary" type="button" data-open-dialog="add-studio-dialog">Add Studio</button><?php endif ?></div>
</section>

<section class="card">
  <div class="card-heading"><div><p>STUDIO MANAGEMENT</p><h2>Studios at this Location</h2></div><span class="count"><?= count($studios) ?> Studios</span></div>
  <p class="muted workflow-note">Assigning an operator grants pairing access. One operator may manage multiple Studios, but every Player installation pairs with only one Studio.</p>
  <?php if ($studios === []): ?><div class="empty">No Studios yet. Add the first Studio for this Location.</div><?php else: ?>
  <div class="studio-management-list">
    <?php foreach ($studios as $item): $studio = $item['entity']; ?>
    <article class="studio-management-row <?= $studio->status === 'revoked' ? 'is-revoked' : '' ?>">
      <div class="studio-primary"><span class="studio-icon">▣</span><div><strong><?= esc($studio->name) ?></strong><small><?= esc($studio->public_id) ?></small></div></div>
      <div class="studio-facts">
        <span><small>Lifecycle</small><strong class="badge <?= esc($studio->status) ?>"><?= esc(strtoupper($studio->status)) ?></strong></span>
        <span><small>Connection</small><strong class="badge <?= esc($item['connection']) ?>"><?= esc(strtoupper($item['connection'])) ?></strong></span>
        <span><small>Playback</small><strong><?= esc(ucfirst($studio->playback_state ?: 'unknown')) ?></strong></span>
        <span><small>Assets</small><strong><?= $item['assetCount'] ?></strong></span>
      </div>
      <div class="studio-assignment">
        <label>Assigned operator</label>
        <?php if (in_array($studio->status, ['pending', 'active'], true)): ?>
        <form method="post" action="<?= site_url('control/locations/' . rawurlencode($location->public_id) . '/studios/' . rawurlencode($studio->public_id) . '/assignment') ?>" class="assignment-form">
          <?= csrf_field() ?><select name="assigned_user_id" data-operator-select data-studio="<?= esc($studio->name, 'attr') ?>" data-create-url="<?= site_url('control/locations/' . rawurlencode($location->public_id) . '/studios/' . rawurlencode($studio->public_id) . '/operators') ?>"><option value="">Unassigned</option><?php foreach ($operators as $operator): ?><option value="<?= $operator->id ?>" <?= (int) $studio->assigned_user_id === (int) $operator->id ? 'selected' : '' ?>><?= esc($operator->name) ?><?= ($assignmentCounts[(int) $operator->id] ?? 0) > 0 ? ' · ' . $assignmentCounts[(int) $operator->id] . ' Studio(s)' : '' ?></option><?php endforeach ?><option value="__new__">＋ Add new operator</option></select><button class="btn ghost" type="submit">Assign</button>
        </form>
        <?php else: ?><strong><?= esc($item['operatorName']) ?></strong><small>Reset pairing before changing assignment.</small><?php endif ?>
      </div>
      <div class="studio-row-actions">
        <a class="btn ghost" href="<?= site_url('control/devices/' . rawurlencode($studio->public_id) . '/assets') ?>">View Assets</a>
        <button class="btn primary" type="button" data-open-asset-assignment data-studio-name="<?= esc($studio->name, 'attr') ?>" data-assign-url="<?= site_url('control/locations/' . rawurlencode($location->public_id) . '/studios/' . rawurlencode($studio->public_id) . '/assets') ?>" data-assigned-assets="<?= esc(json_encode($item['assignedAssetIds'], JSON_THROW_ON_ERROR), 'attr') ?>" data-ldg-version="<?= esc((string) $studio->ldg_version, 'attr') ?>" <?= $studio->status !== 'active' ? 'disabled title="Pair and activate this Studio before assigning assets"' : '' ?>>Assign Assets</button>
        <?php if ($studio->status !== 'revoked'): ?><button class="btn ghost" type="button" data-open-dialog="edit-<?= esc($studio->public_id, 'attr') ?>">Edit</button><?php endif ?>
        <?php if (in_array($studio->status, ['active', 'revoked'], true)): ?><button class="btn ghost" type="button" data-open-dialog="reset-<?= esc($studio->public_id, 'attr') ?>">Reset pairing</button><?php endif ?>
        <?php if ($studio->status === 'active'): ?><button class="btn danger" type="button" data-open-dialog="revoke-<?= esc($studio->public_id, 'attr') ?>">Revoke</button><?php endif ?>
        <?php if (in_array($studio->status, ['pending', 'revoked'], true)): ?><button class="btn danger" type="button" data-open-dialog="delete-<?= esc($studio->public_id, 'attr') ?>">Delete</button><?php endif ?>
      </div>
    </article>

    <?php if ($studio->status !== 'revoked'): ?><dialog class="cms-modal" id="edit-<?= esc($studio->public_id, 'attr') ?>"><div class="modal-card"><div class="modal-head"><div><p>EDIT STUDIO</p><h3><?= esc($studio->name) ?></h3></div><button type="button" class="modal-close" data-close-dialog aria-label="Close">×</button></div><form method="post" action="<?= site_url('control/locations/' . rawurlencode($location->public_id) . '/studios/' . rawurlencode($studio->public_id) . '/details') ?>" class="modal-form"><?= csrf_field() ?><label>Studio name<input name="name" value="<?= esc($studio->name) ?>" maxlength="120" required></label><label>Location<select name="location_id" required><?php foreach ($availableLocations as $candidate): ?><option value="<?= esc($candidate->public_id) ?>" <?= (int) $candidate->id === (int) $studio->location_id ? 'selected' : '' ?>><?= esc($candidate->name) ?></option><?php endforeach ?></select></label><label>Timezone<input name="timezone" value="<?= esc($studio->timezone) ?>" placeholder="<?= esc($location->timezone) ?>"></label><div class="modal-actions"><button type="button" class="btn ghost" data-close-dialog>Cancel</button><button class="btn primary" type="submit">Save Studio</button></div></form></div></dialog><?php endif ?>
    <?php if (in_array($studio->status, ['active', 'revoked'], true)): ?><dialog class="cms-modal" id="reset-<?= esc($studio->public_id, 'attr') ?>"><div class="modal-card compact"><div class="modal-head"><div><p>RESET PAIRING</p><h3><?= esc($studio->name) ?></h3></div><button type="button" class="modal-close" data-close-dialog>×</button></div><p>The current Player token will become invalid. The Studio keeps its Location, operator, schedules, and assets, then returns to the pairing screen.</p><form method="post" action="<?= site_url('control/locations/' . rawurlencode($location->public_id) . '/studios/' . rawurlencode($studio->public_id) . '/reset-pairing') ?>" class="modal-actions"><?= csrf_field() ?><button type="button" class="btn ghost" data-close-dialog>Cancel</button><button class="btn primary" type="submit">Reset pairing</button></form></div></dialog><?php endif ?>
    <?php if ($studio->status === 'active'): ?><dialog class="cms-modal" id="revoke-<?= esc($studio->public_id, 'attr') ?>"><div class="modal-card compact"><div class="modal-head"><div><p>REVOKE STUDIO</p><h3><?= esc($studio->name) ?></h3></div><button type="button" class="modal-close" data-close-dialog>×</button></div><p>This immediately invalidates the Player token. On its next CMS contact, the Player returns to pairing.</p><form method="post" action="<?= site_url('control/locations/' . rawurlencode($location->public_id) . '/studios/' . rawurlencode($studio->public_id) . '/revoke') ?>" class="modal-actions"><?= csrf_field() ?><button type="button" class="btn ghost" data-close-dialog>Cancel</button><button class="btn danger" type="submit">Revoke Studio</button></form></div></dialog><?php endif ?>
    <?php if (in_array($studio->status, ['pending', 'revoked'], true)): ?><dialog class="cms-modal" id="delete-<?= esc($studio->public_id, 'attr') ?>"><div class="modal-card compact"><div class="modal-head"><div><p>DELETE STUDIO</p><h3><?= esc($studio->name) ?></h3></div><button type="button" class="modal-close" data-close-dialog>×</button></div><p>This permanently removes the Studio record and its CMS relationships. This action cannot be undone.</p><form method="post" action="<?= site_url('control/locations/' . rawurlencode($location->public_id) . '/studios/' . rawurlencode($studio->public_id) . '/delete') ?>" class="modal-actions"><?= csrf_field() ?><button type="button" class="btn ghost" data-close-dialog>Cancel</button><button class="btn danger" type="submit">Delete permanently</button></form></div></dialog><?php endif ?>
    <?php endforeach ?>
  </div><?php endif ?>
</section>

<dialog class="cms-modal" id="add-studio-dialog"><div class="modal-card"><div class="modal-head"><div><p>NEW STUDIO</p><h3>Add Studio to <?= esc($location->name) ?></h3></div><button type="button" class="modal-close" data-close-dialog>×</button></div><form method="post" action="<?= site_url('control/locations/' . rawurlencode($location->public_id) . '/studios') ?>" class="modal-form"><?= csrf_field() ?><label>Studio name<input name="name" maxlength="120" placeholder="Studio 1" required autofocus></label><label>Location<input value="<?= esc($location->name) ?>" disabled><small>Inherited automatically from this Location.</small></label><label>Timezone (optional)<input name="timezone" placeholder="<?= esc($location->timezone) ?>"><small>Leave empty to inherit <?= esc($location->timezone) ?>.</small></label><label>Initial operator (optional)<select name="assigned_user_id"><option value="">Unassigned</option><?php foreach ($operators as $operator): ?><option value="<?= $operator->id ?>"><?= esc($operator->name) ?></option><?php endforeach ?></select></label><div class="modal-actions"><button type="button" class="btn ghost" data-close-dialog>Cancel</button><button class="btn primary" type="submit">Create Studio</button></div></form></div></dialog>

<dialog class="cms-modal" id="quick-operator-dialog"><div class="modal-card"><div class="modal-head"><div><p>QUICK ACCOUNT</p><h3>Add operator for <span id="quick-operator-studio"></span></h3></div><button type="button" class="modal-close" data-close-dialog>×</button></div><form method="post" action="" class="modal-form" id="quick-operator-form"><?= csrf_field() ?><label>Name<input name="name" maxlength="120" required></label><label>Email<input type="email" name="email" required></label><label>Password<input type="password" name="password" minlength="12" autocomplete="new-password" required></label><label>Confirm password<input type="password" name="password_confirmation" minlength="12" autocomplete="new-password" required></label><p class="muted">The account is created as an active operator and assigned immediately. The Player PC must still complete pairing.</p><div class="modal-actions"><button type="button" class="btn ghost" data-close-dialog>Cancel</button><button class="btn primary" type="submit">Create and assign</button></div></form></div></dialog>

<dialog class="cms-modal studio-assets-modal" id="assign-assets-dialog"><div class="modal-card"><div class="modal-head"><div><p>ASSET DISTRIBUTION</p><h3>Assign assets to <span id="asset-assignment-studio"></span></h3><span>Choose multiple active films for this Studio.</span></div><button type="button" class="modal-close" data-close-dialog aria-label="Close">×</button></div><form method="post" action="" id="studio-asset-assignment-form"><?= csrf_field() ?><div class="studio-asset-toolbar"><label>Search assets<input type="search" placeholder="Title, filename, genre, or distributor…" data-studio-asset-search></label><label>Asset type<select data-studio-asset-type><option value="">All types</option><?php foreach ($assetTypes as $type): ?><option value="<?= esc($type) ?>"><?= esc(ucfirst($type)) ?></option><?php endforeach ?></select></label><button class="btn ghost" type="button" data-select-visible-assets>Select all filtered</button></div><div class="studio-asset-list" data-studio-asset-list><?php if ($assignableAssets === []): ?><div class="empty">No active assets are available.</div><?php else: ?><?php foreach ($assignableAssets as $catalogAsset): $catalogGenres = $assetGenres[(int) $catalogAsset->id] ?? []; $searchText = mb_strtolower(implode(' ', [$catalogAsset->title, $catalogAsset->filename, $catalogAsset->distributor_company, $catalogAsset->asset_type, ...array_column($catalogGenres, 'name')])); ?><label class="studio-asset-option" data-studio-asset-option data-search-text="<?= esc($searchText, 'attr') ?>" data-asset-type="<?= esc($catalogAsset->asset_type ?: 'featured', 'attr') ?>" data-requires-ldg="<?= $catalogAsset->encryption_format === 'ldg-v1' ? '1' : '0' ?>"><input type="checkbox" name="asset_ids[]" value="<?= esc($catalogAsset->public_id, 'attr') ?>" data-asset-size="<?= (int) $catalogAsset->size_bytes ?>"><span class="studio-asset-copy"><strong><?= esc($catalogAsset->title) ?></strong><small><?= esc($catalogAsset->filename) ?></small><em><?= esc(ucfirst($catalogAsset->asset_type ?: 'featured')) ?><?= $catalogGenres !== [] ? ' · ' . esc(implode(', ', array_column($catalogGenres, 'name'))) : '' ?> · <?= number_format(((int) $catalogAsset->size_bytes) / 1048576, 2) ?> MB</em></span><span class="badge studio-asset-state" data-studio-asset-state>AVAILABLE</span></label><?php endforeach ?><?php endif ?><div class="empty" data-studio-assets-empty hidden>No asset matches these filters.</div></div><div class="studio-asset-modal-actions"><span data-studio-asset-summary>No asset selected</span><div><button type="button" class="btn ghost" data-close-dialog>Cancel</button><button type="submit" class="btn primary" data-studio-asset-submit disabled>Assign Assets</button></div></div></form></div></dialog>

<script>
(() => {
  const openDialog = (id) => document.getElementById(id)?.showModal();
  document.querySelectorAll('[data-open-dialog]').forEach((button) => button.addEventListener('click', () => openDialog(button.dataset.openDialog)));
  document.querySelectorAll('[data-close-dialog]').forEach((button) => button.addEventListener('click', () => button.closest('dialog')?.close()));
  document.querySelectorAll('.cms-modal').forEach((dialog) => dialog.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); }));
  document.querySelectorAll('[data-operator-select]').forEach((select) => select.addEventListener('change', () => {
    if (select.value !== '__new__') return;
    document.getElementById('quick-operator-form').action = select.dataset.createUrl;
    document.getElementById('quick-operator-studio').textContent = select.dataset.studio;
    select.value = select.querySelector('option[selected]')?.value || '';
    openDialog('quick-operator-dialog');
  }));
  const assetDialog = document.getElementById('assign-assets-dialog');
  const assetForm = document.getElementById('studio-asset-assignment-form');
  if (assetDialog && assetForm) {
    const assetSearch = assetForm.querySelector('[data-studio-asset-search]');
    const assetType = assetForm.querySelector('[data-studio-asset-type]');
    const assetRows = [...assetForm.querySelectorAll('[data-studio-asset-option]')];
    const assetSummary = assetForm.querySelector('[data-studio-asset-summary]');
    const assetSubmit = assetForm.querySelector('[data-studio-asset-submit]');
    const selectVisible = assetForm.querySelector('[data-select-visible-assets]');
    const assetEmpty = assetForm.querySelector('[data-studio-assets-empty]');

    const formatBytes = (bytes) => {
      const units = ['B', 'KB', 'MB', 'GB', 'TB'];
      let value = Math.max(0, Number(bytes) || 0), unit = 0;
      while (value >= 1024 && unit < units.length - 1) { value /= 1024; unit += 1; }
      return `${value.toFixed(unit >= 3 ? 2 : unit > 0 ? 1 : 0)} ${units[unit]}`;
    };
    const updateAssetSelection = () => {
      const selected = assetRows.map((row) => row.querySelector('input')).filter((input) => input.checked && !input.disabled);
      const bytes = selected.reduce((total, input) => total + Number(input.dataset.assetSize || 0), 0);
      assetSummary.textContent = selected.length ? `${selected.length} asset(s) · ${formatBytes(bytes)}` : 'No asset selected';
      assetSubmit.disabled = selected.length === 0;
      const visible = assetRows.map((row) => row.querySelector('input')).filter((input) => !input.disabled && !input.closest('[data-studio-asset-option]').hidden);
      selectVisible.textContent = visible.length > 0 && visible.every((input) => input.checked) ? 'Clear filtered' : 'Select all filtered';
    };
    const filterAssets = () => {
      const query = assetSearch.value.trim().toLocaleLowerCase();
      const type = assetType.value;
      let visible = 0;
      assetRows.forEach((row) => {
        const matches = (!query || row.dataset.searchText.includes(query)) && (!type || row.dataset.assetType === type);
        row.hidden = !matches;
        if (matches) visible += 1;
      });
      assetEmpty.hidden = visible !== 0;
      updateAssetSelection();
    };
    document.querySelectorAll('[data-open-asset-assignment]').forEach((button) => button.addEventListener('click', () => {
      assetForm.reset();
      assetForm.action = button.dataset.assignUrl;
      document.getElementById('asset-assignment-studio').textContent = button.dataset.studioName;
      const assigned = new Set(JSON.parse(button.dataset.assignedAssets || '[]'));
      const supportsLdg = button.dataset.ldgVersion === 'ldg-v1';
      assetRows.forEach((row) => {
        const input = row.querySelector('input');
        const state = row.querySelector('[data-studio-asset-state]');
        const isAssigned = assigned.has(input.value);
        const incompatible = row.dataset.requiresLdg === '1' && !supportsLdg;
        input.checked = false;
        input.disabled = isAssigned || incompatible;
        row.classList.toggle('is-assigned', isAssigned);
        row.classList.toggle('is-incompatible', incompatible);
        state.textContent = isAssigned ? 'ASSIGNED' : incompatible ? 'PLAYER UPDATE REQUIRED' : 'AVAILABLE';
        state.className = `badge studio-asset-state${isAssigned ? ' active' : incompatible ? ' rejected' : ''}`;
      });
      assetSearch.value = '';
      assetType.value = '';
      filterAssets();
      openDialog('assign-assets-dialog');
      window.setTimeout(() => assetSearch.focus(), 0);
    }));
    assetRows.forEach((row) => row.querySelector('input').addEventListener('change', updateAssetSelection));
    assetSearch.addEventListener('input', filterAssets);
    assetType.addEventListener('change', filterAssets);
    selectVisible.addEventListener('click', () => {
      const visible = assetRows.map((row) => row.querySelector('input')).filter((input) => !input.disabled && !input.closest('[data-studio-asset-option]').hidden);
      const shouldCheck = visible.some((input) => !input.checked);
      visible.forEach((input) => { input.checked = shouldCheck; });
      updateAssetSelection();
    });
  }
  if (location.hash === '#add-studio') { openDialog('add-studio-dialog'); history.replaceState(null, '', location.pathname); }
})();
</script>
<?= view('web/_layout_bottom') ?>
