<label class="studio-asset-option" data-studio-asset-option data-requires-ldg="<?= $asset->encryption_format === 'ldg-v1' ? '1' : '0' ?>">
  <input type="checkbox" value="<?= esc($asset->public_id, 'attr') ?>" data-asset-size="<?= (int) $asset->size_bytes ?>">
  <span class="studio-asset-copy"><strong><?= esc($asset->title) ?></strong><small><?= esc($asset->filename) ?></small><em><?= esc(ucfirst($asset->asset_type ?: 'featured')) ?><?= $genres !== [] ? ' · ' . esc(implode(', ', array_column($genres, 'name'))) : '' ?> · <?= number_format(((int) $asset->size_bytes) / 1048576, 2) ?> MB</em></span>
  <span class="badge studio-asset-state" data-studio-asset-state>AVAILABLE</span>
</label>
