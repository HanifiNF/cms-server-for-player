<article>
  <div><strong>Revision <?= (int) $version->revision ?></strong><span class="badge asset-status <?= esc($version->status) ?>"><?= esc(strtoupper($version->status)) ?></span></div>
  <p><?= esc($version->filename) ?></p>
  <dl><span><dt>Submitted</dt><dd><?= esc($userNames[(int) $version->submitted_by] ?? 'Unknown') ?></dd></span><span><dt>Size</dt><dd><?= number_format(((int) $version->size_bytes) / 1048576, 2) ?> MB</dd></span><span><dt>SHA-256</dt><dd><code title="<?= esc($version->sha256) ?>"><?= esc(substr((string) $version->sha256, 0, 14)) ?>…</code></dd></span></dl>
  <?php if ($version->rejection_reason): ?><small><?= esc($version->rejection_reason) ?></small><?php endif ?>
</article>
