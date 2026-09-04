<article class="asset-inventory-row">
  <span class="asset-inventory-media"><strong><?= esc($asset->title) ?></strong><small><?= esc($asset->relative_path ?: $asset->filename) ?></small><code title="<?= esc($asset->media_key) ?>"><?= esc($asset->media_key) ?></code></span>
  <span data-label="Source"><?= $asset->source === 'managed' ? 'CMS Download' : 'Media Folder' ?></span>
  <span data-label="Duration"><?= esc($formatDuration((int) $asset->duration_ms)) ?></span>
  <span data-label="Size"><?= esc($formatBytes((int) $asset->size_bytes)) ?></span>
  <span data-label="Status"><b class="badge asset-status <?= esc($asset->status) ?>"><?= esc(strtoupper($asset->status)) ?></b></span>
  <span data-label="Last reported"><?= $asset->last_reported_at ? esc($asset->last_reported_at->format('Y-m-d H:i:s')) . ' UTC' : 'Never' ?></span>
</article>
