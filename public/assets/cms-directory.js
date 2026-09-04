(function () {
  'use strict';

  function htmlNode(html) {
    var template = document.createElement('template');
    template.innerHTML = String(html || '').trim();
    return template.content;
  }

  function formParameters(form) {
    var values = {};
    new FormData(form).forEach(function (value, key) {
      if (String(value) === '') return;
      if (Object.prototype.hasOwnProperty.call(values, key)) values[key] = Array.isArray(values[key]) ? values[key].concat([value]) : [values[key], value];
      else values[key] = value;
    });
    var page = new URL(window.location.href).searchParams.get('page');
    if (page) values.page = page;
    return values;
  }

  function init(root) {
    var form = root.querySelector('[data-cms-directory-filter]');
    if (!form || !window.CmsAsync) return;
    var count = root.querySelector('[data-cms-directory-count]');
    var collection = window.CmsAsync.createCollection({
      root: root.querySelector('[data-cms-directory-collection]'),
      endpoint: root.dataset.endpoint,
      skeletonCount: Number(root.dataset.skeletonCount) || 6,
      skeletonVariant: root.dataset.skeletonVariant || 'card',
      skeletonColumns: Number(root.dataset.skeletonColumns) || 1,
      renderBatchSize: Number(root.dataset.renderBatchSize) || 4,
      renderItem: function (item) { return htmlNode(item.html); },
      emptyTitle: root.dataset.emptyTitle || 'No results',
      emptyMessage: root.dataset.emptyMessage || 'No items match the current filters.'
    });
    function parameters() { return formParameters(form); }
    function loadFilters() {
      var values = parameters();
      delete values.page;
      window.history.pushState({}, '', window.CmsAsync.buildUrl(form.action, values));
      collection.load(values);
    }
    form.addEventListener('submit', function (event) { event.preventDefault(); loadFilters(); });
    form.querySelectorAll('select, input[type="date"]').forEach(function (control) { control.addEventListener('change', loadFilters); });
    var search = form.querySelector('input[type="search"], input[name="q"]');
    if (search) search.addEventListener('input', window.CmsAsync.debounce(loadFilters, 350));
    root.addEventListener('cms:collection-loaded', function (event) {
      var detail = event.detail || {};
      var total = Number(detail.pagination && detail.pagination.total) || 0;
      if (count) count.textContent = total + ' ' + (root.dataset.countLabel || 'results');
      if (detail.parameters) window.history.replaceState({}, '', window.CmsAsync.buildUrl(form.action, detail.parameters));
      if (window.CmsModal) window.CmsModal.refresh(root);
      if (root.dataset.autoOpen && window.CmsModal) {
        var automatic = document.getElementById(root.dataset.autoOpen);
        if (automatic && !automatic.open) window.CmsModal.open(automatic, null);
        delete root.dataset.autoOpen;
      }
      root.dispatchEvent(new CustomEvent('cms:directory-ready', { detail: detail }));
    });
    collection.load(parameters());
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-cms-directory]').forEach(init);
    if (document.querySelector('[data-cms-directory]')) window.addEventListener('popstate', function () { window.location.reload(); });
  });
})();
