(function () {
  'use strict';

  document.addEventListener('DOMContentLoaded', function () {
    if (!window.CmsAsync) return;
    document.querySelectorAll('[data-cms-static-collection]').forEach(function (root) {
      var count = root.querySelector('[data-cms-static-count]');
      var templateNode = function (html) {
        var template = document.createElement('template');
        template.innerHTML = String(html || '').trim();
        return template.content;
      };
      var collection = window.CmsAsync.createCollection({
        root: root.querySelector('[data-cms-static-body]'),
        endpoint: root.dataset.endpoint,
        skeletonCount: Number(root.dataset.skeletonCount) || 4,
        skeletonVariant: root.dataset.skeletonVariant || 'row',
        renderBatchSize: 4,
        renderItem: function (item) { return templateNode(item.html); },
        emptyTitle: root.dataset.emptyTitle || 'No data',
        emptyMessage: root.dataset.emptyMessage || 'There are no records to display.'
      });
      root.querySelector('[data-cms-static-body]').addEventListener('cms:collection-loaded', function (event) {
        var total = Number(event.detail && event.detail.pagination && event.detail.pagination.total) || 0;
        if (count) count.textContent = total + ' ' + (root.dataset.countLabel || 'results');
      });
      collection.load({});
    });
  });
})();
