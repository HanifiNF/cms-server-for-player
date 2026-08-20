<?php
$selectedGenreIds = array_values(array_unique(array_map('intval', $selectedGenreIds ?? [])));
$genreInputId = preg_replace('/[^a-zA-Z0-9_-]/', '-', (string) ($genreInputId ?? 'genres'));
?>
<div class="genre-multiselect" data-genre-multiselect>
  <button class="genre-multiselect-trigger" type="button" aria-expanded="false" aria-controls="<?= esc($genreInputId) ?>-panel">
    <span data-genre-summary>Select genres</span><i aria-hidden="true"></i>
  </button>
  <div id="<?= esc($genreInputId) ?>-panel" class="genre-multiselect-panel" hidden>
    <label class="genre-search"><span class="sr-only">Search genres</span><input type="search" placeholder="Search genres…" autocomplete="off" data-genre-search></label>
    <div class="genre-option-list" role="listbox" aria-multiselectable="true">
      <?php foreach ($activeGenres as $genre): ?>
        <label class="genre-option" data-genre-option>
          <input type="checkbox" name="genre_ids[]" value="<?= (int) $genre->id ?>" data-genre-name="<?= esc($genre->name, 'attr') ?>" <?= in_array((int) $genre->id, $selectedGenreIds, true) ? 'checked' : '' ?>>
          <span><?= esc($genre->name) ?></span>
        </label>
      <?php endforeach ?>
      <p class="genre-no-results" data-genre-empty hidden>No matching genres.</p>
    </div>
  </div>
  <div class="genre-selection" data-genre-chips aria-live="polite"></div>
</div>
