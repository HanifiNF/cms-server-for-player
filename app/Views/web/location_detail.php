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
        <a class="btn ghost" href="<?= site_url('control/devices/' . rawurlencode($studio->public_id) . '/assets') ?>">Assets</a>
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
  if (location.hash === '#add-studio') { openDialog('add-studio-dialog'); history.replaceState(null, '', location.pathname); }
})();
</script>
<?= view('web/_layout_bottom') ?>
