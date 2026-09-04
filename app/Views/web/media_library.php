<?= view('web/_layout_top', get_defined_vars()) ?>

<section class="library-hero">
  <div><p>CATALOG INTELLIGENCE</p><h2>Media Library</h2><span><?= $isAdmin ? 'Review, organize, and distribute every submitted film.' : 'Upload films and follow their review and distribution status.' ?></span></div>
  <div class="cms-toolbar-actions"><?php if ($isAdmin): ?><button class="btn ghost" type="button" data-cms-modal-open="genre-manager-modal">Manage genre options</button><?php endif ?><button class="btn primary" type="button" data-open-asset-upload>+ Add Asset</button></div>
</section>

<section class="library-status-grid" aria-label="Asset status summary">
  <?php foreach (['total' => 'Total', 'draft' => 'Draft', 'active' => 'Active', 'rejected' => 'Rejected', 'expired' => 'Expired'] as $value => $label): ?>
    <a class="library-status-card <?= ($filters['status'] === $value || ($value === 'total' && $filters['status'] === '')) ? 'selected' : '' ?>" href="<?= site_url('control/library' . ($value === 'total' ? '' : '?status=' . $value)) ?>"><span><?= esc(strtoupper($label)) ?></span><strong><?= (int) $statusCounts[$value] ?></strong></a>
  <?php endforeach ?>
</section>

<section class="card library-filter-card">
  <form method="get" action="<?= site_url('control/library') ?>" class="library-filters <?= $isAdmin ? 'with-distributor' : '' ?>">
    <label>Search<input type="search" name="q" value="<?= esc($filters['search']) ?>" placeholder="Title, file, distributor, or genre"></label>
    <label>Type<select name="type"><option value="">All types</option><?php foreach ($assetTypes as $type): ?><option value="<?= esc($type) ?>" <?= $filters['type'] === $type ? 'selected' : '' ?>><?= esc(ucfirst($type)) ?></option><?php endforeach ?></select></label>
    <label>Genre<select name="genre"><option value="">All genres</option><?php foreach ($genres as $genre): ?><option value="<?= (int) $genre->id ?>" <?= $filters['genre'] === (string) $genre->id ? 'selected' : '' ?>><?= esc($genre->name) ?></option><?php endforeach ?></select></label>
    <label>Status<select name="status"><option value="">All statuses</option><?php foreach (['draft','active','rejected','expired'] as $value): ?><option value="<?= $value ?>" <?= $filters['status'] === $value ? 'selected' : '' ?>><?= esc(ucfirst($value)) ?></option><?php endforeach ?></select></label>
    <label>Availability<select name="availability"><option value="">Any availability</option><option value="available" <?= $filters['availability'] === 'available' ? 'selected' : '' ?>>Available on Studio</option><option value="unassigned" <?= $filters['availability'] === 'unassigned' ? 'selected' : '' ?>>Not assigned</option></select></label>
    <?php if ($isAdmin): ?><label>Distributor<select name="distributor"><option value="0">All distributors</option><?php foreach ($distributors as $distributor): ?><option value="<?= (int) $distributor->id ?>" <?= (int) $filters['distributor'] === (int) $distributor->id ? 'selected' : '' ?>><?= esc($distributor->name) ?></option><?php endforeach ?></select></label><?php endif ?>
    <div class="library-filter-actions"><button class="btn primary" type="submit">Apply</button><a class="btn ghost" href="<?= site_url('control/library') ?>">Reset</a></div>
  </form>
</section>

<div class="section-heading"><div><p>ALL MEDIA</p><h2>Catalog</h2></div><span class="badge" data-library-result-count><?= (int) $catalogTotal ?> results</span></div>
<section data-library-collection data-endpoint="<?= site_url('control/library/collection') ?>">
  <div class="library-layered-list" data-cms-async-items></div>
  <nav data-cms-async-pagination aria-label="Media library pages"></nav>
</section>
<noscript><article class="card empty-state"><strong>JavaScript is required</strong><p>Enable JavaScript to load the paginated media catalog.</p></article></noscript>

<script>
document.addEventListener('DOMContentLoaded', () => {
  const root = document.querySelector('[data-library-collection]');
  const form = document.querySelector('.library-filters');
  const count = document.querySelector('[data-library-result-count]');
  if (!root || !form || !window.CmsAsync) return;
  const htmlNode = html => {
    const template = document.createElement('template');
    template.innerHTML = String(html || '').trim();
    return template.content;
  };
  const parameters = () => {
    const result = {};
    for (const [key, value] of new FormData(form)) if (String(value) !== '') result[key] = value;
    const page = new URL(window.location.href).searchParams.get('page');
    if (page) result.page = page;
    return result;
  };
  const syncUrl = values => {
    const url = new URL(form.action, window.location.href);
    Object.entries(values).forEach(([key, value]) => url.searchParams.set(key, value));
    window.history.replaceState({}, '', url);
  };
  const collection = window.CmsAsync.createCollection({
    root,
    endpoint: root.dataset.endpoint,
    skeletonCount: 6,
    skeletonVariant: 'card',
    renderItem: item => htmlNode(item.html),
    emptyTitle: 'No matching media',
    emptyMessage: 'Change the filters or add a new asset to the library.'
  });
  root.addEventListener('cms:collection-loaded', event => {
    const detail = event.detail || {};
    if (count) count.textContent = `${Number(detail.pagination && detail.pagination.total) || 0} results`;
    if (detail.parameters) syncUrl(detail.parameters);
  });
  const loadFilters = () => {
    const values = parameters();
    delete values.page;
    window.history.pushState({}, '', window.CmsAsync.buildUrl(form.action, values));
    collection.load(values);
  };
  form.addEventListener('submit', event => { event.preventDefault(); loadFilters(); });
  form.querySelectorAll('select').forEach(select => select.addEventListener('change', loadFilters));
  const search = form.querySelector('input[type="search"]');
  if (search) search.addEventListener('input', window.CmsAsync.debounce(loadFilters, 350));
  document.querySelectorAll('.library-status-card').forEach(card => card.addEventListener('click', event => {
    event.preventDefault();
    const status = new URL(card.href).searchParams.get('status') || '';
    form.elements.status.value = status;
    document.querySelectorAll('.library-status-card').forEach(candidate => candidate.classList.toggle('selected', candidate === card));
    loadFilters();
  }));
  window.addEventListener('popstate', () => window.location.reload());
  collection.load(parameters());
});
</script>

<div class="library-modal" data-asset-upload-modal hidden>
  <div class="library-modal-backdrop" data-close-asset-upload></div>
  <section class="library-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="asset-upload-title">
    <header><div><p>MEDIA CATALOG</p><h2 id="asset-upload-title">Add Asset</h2><span><?= $isAdmin ? 'Administrator uploads are immediately active.' : 'Distributor uploads remain Draft until reviewed.' ?></span></div><button type="button" class="library-modal-close" aria-label="Close upload form" data-close-asset-upload>×</button></header>
    <div class="library-modal-body"><?= view('web/_asset_upload_form', get_defined_vars()) ?></div>
  </section>
</div>

<?php if ($isAdmin): ?>
<dialog class="cms-action-modal" id="genre-manager-modal" data-cms-modal <?= session('modal') === 'genre-manager-modal' ? 'data-auto-open="true"' : '' ?>>
  <div class="cms-modal-shell"><header class="cms-modal-header"><div><p>TAXONOMY</p><h2>Manage Genres</h2><span>Create catalog filters and control which options appear in film forms.</span></div><button class="cms-modal-x" type="button" data-cms-modal-close>×</button></header><div class="cms-modal-body"><form method="post" action="<?= site_url('control/genres') ?>" class="genre-create-form"><?= csrf_field() ?><input type="hidden" name="_modal_context" value="genre-manager-modal"><input name="name" maxlength="80" placeholder="New genre name" required autofocus><button class="btn primary" type="submit">Add Genre</button></form><div class="genre-admin-list"><?php foreach ($genres as $genre): ?><div><span><strong><?= esc($genre->name) ?></strong><small><?= esc(strtoupper($genre->status)) ?></small></span><form method="post" action="<?= site_url('control/genres/' . rawurlencode($genre->public_id) . '/status') ?>"><?= csrf_field() ?><input type="hidden" name="status" value="<?= $genre->status === 'active' ? 'inactive' : 'active' ?>"><button class="btn ghost" type="submit"><?= $genre->status === 'active' ? 'Disable' : 'Enable' ?></button></form></div><?php endforeach ?></div></div><footer class="cms-modal-footer"><span>Disabled genres remain attached to existing films.</span><div><button class="btn primary" type="button" data-cms-modal-close>Done</button></div></footer></div>
</dialog>
<?php endif ?>

<?= view('web/_layout_bottom') ?>
