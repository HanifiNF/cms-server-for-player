<form method="post" action="<?= site_url('control/assets/upload') ?>" enctype="multipart/form-data" class="form-stack library-upload-form" data-asset-upload-form>
  <?= csrf_field() ?>
  <label>Media file<input type="file" name="media" accept="video/*,.mkv,.ts" required data-upload-file></label>
  <div class="film-field-pair"><label>Title <span class="muted">(optional)</span><input type="text" name="title" maxlength="255" value="<?= esc(old('title')) ?>" placeholder="Uses the filename when empty"></label><label>Asset type<select name="asset_type" required><?php foreach ($assetTypes as $type): ?><option value="<?= esc($type) ?>" <?= old('asset_type', 'featured') === $type ? 'selected' : '' ?>><?= esc(ucfirst($type)) ?></option><?php endforeach ?></select></label></div>
  <label>Poster <span class="muted">(optional, max 10 MB)</span><input type="file" name="poster" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp"></label>
  <label>Synopsis <span class="muted">(optional)</span><textarea name="synopsis" maxlength="5000" rows="4" placeholder="Short film synopsis"><?= esc(old('synopsis')) ?></textarea></label>
  <input type="hidden" name="genres_present" value="1">
  <div class="film-field-pair"><div class="film-control-group"><span class="field-label">Genres <span class="muted">(multiple)</span></span><?= view('web/_genre_multiselect', ['activeGenres' => $activeGenres, 'selectedGenreIds' => array_map('intval', (array) old('genre_ids', [])), 'genreInputId' => 'library-upload-genres']) ?><small class="muted">Choose one or more genres.</small></div><label>Language<input name="language" maxlength="80" value="<?= esc(old('language')) ?>" placeholder="Indonesian"></label></div>
  <div class="film-field-pair"><label>Subtitles<input name="subtitles" maxlength="160" value="<?= esc(old('subtitles')) ?>" placeholder="English, Indonesian"></label><label>Age rating<select name="age_rating"><option value="">Not specified</option><?php foreach (['SU', '13+', '17+', '21+'] as $rating): ?><option value="<?= $rating ?>" <?= old('age_rating') === $rating ? 'selected' : '' ?>><?= $rating ?></option><?php endforeach ?></select></label></div>
  <div class="film-field-pair"><label>Production year<input type="number" name="production_year" min="1888" max="<?= date('Y') + 2 ?>" value="<?= esc(old('production_year')) ?>" placeholder="<?= date('Y') ?>"></label><label>Release date<input type="date" name="release_date" value="<?= esc(old('release_date')) ?>"></label></div>
  <div class="film-field-pair"><label>Valid until <span class="muted">(optional)</span><input type="date" name="expires_on" min="<?= esc($catalogToday) ?>" value="<?= esc(old('expires_on')) ?>"></label><label>Distributor company<input name="distributor_company" maxlength="180" value="<?= esc(old('distributor_company')) ?>" placeholder="Company or studio name"></label></div>
  <div class="library-upload-actions"><button class="btn ghost" type="button" data-close-asset-upload>Cancel</button><button class="btn primary" type="submit" data-upload-submit>Upload asset</button></div>
</form>
<div class="asset-upload-progress" hidden aria-live="polite" data-upload-progress>
  <div class="upload-progress-head"><strong data-upload-status>Preparing upload…</strong><span data-upload-percent>0%</span></div>
  <div class="upload-progress-track"><div class="upload-progress-fill" data-upload-fill></div></div>
  <div class="upload-progress-metrics"><span data-upload-transferred>0 B / 0 B</span><span data-upload-speed>—</span><span data-upload-eta>Calculating…</span></div>
  <p class="upload-progress-error" data-upload-error></p>
  <button class="btn ghost" type="button" data-upload-cancel>Cancel upload</button>
</div>
<small class="upload-note">Large films remain in this window while the CMS uploads, encrypts, detects duration, and verifies the asset.</small>
