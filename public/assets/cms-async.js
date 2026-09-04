(function () {
  'use strict';

  var responseCache = new Map();

  function debounce(callback, delay) {
    var timer = null;
    function debounced() {
      var context = this;
      var args = arguments;
      window.clearTimeout(timer);
      timer = window.setTimeout(function () { callback.apply(context, args); }, Number(delay) || 250);
    }
    debounced.cancel = function () { window.clearTimeout(timer); timer = null; };
    return debounced;
  }

  function buildUrl(endpoint, parameters) {
    var url = new URL(endpoint, window.location.href);
    Object.keys(parameters || {}).forEach(function (key) {
      var value = parameters[key];
      url.searchParams.delete(key);
      (Array.isArray(value) ? value : [value]).forEach(function (entry) {
        if (entry !== null && entry !== undefined && String(entry) !== '') url.searchParams.append(key, String(entry));
      });
    });
    return url.toString();
  }

  function skeletonItem(variant) {
    var item = document.createElement('div');
    item.className = 'cms-skeleton cms-skeleton-' + variant;
    item.setAttribute('aria-hidden', 'true');
    if (variant === 'card') {
      var block = document.createElement('span'); block.className = 'cms-skeleton-block';
      var lines = document.createElement('span'); lines.className = 'cms-skeleton-lines';
      ['medium', '', 'short'].forEach(function (size) { var line = document.createElement('i'); line.className = 'cms-skeleton-line ' + size; lines.appendChild(line); });
      item.append(block, lines);
    } else if (variant === 'stat') {
      ['short', 'tall', 'medium'].forEach(function (size) { var line = document.createElement('i'); line.className = 'cms-skeleton-line ' + size; line.style.marginBottom = '10px'; item.appendChild(line); });
    } else {
      ['', 'medium', 'short', 'short'].forEach(function (size) { var line = document.createElement('i'); line.className = 'cms-skeleton-line ' + size; item.appendChild(line); });
    }
    return item;
  }

  function stateNode(title, message, retry) {
    var state = document.createElement('div'); state.className = 'cms-async-state';
    var body = document.createElement('div');
    var heading = document.createElement('strong'); heading.textContent = title;
    var copy = document.createElement('span'); copy.textContent = message;
    body.append(heading, copy);
    if (retry) {
      var button = document.createElement('button'); button.type = 'button'; button.className = 'btn ghost'; button.textContent = 'Retry'; button.addEventListener('click', retry); body.appendChild(button);
    }
    state.appendChild(body);
    return state;
  }

  function createCollection(options) {
    if (!options || !options.root || !options.endpoint || typeof options.renderItem !== 'function') throw new Error('CmsAsync collection requires root, endpoint, and renderItem.');
    var root = typeof options.root === 'string' ? document.querySelector(options.root) : options.root;
    if (!root) throw new Error('CmsAsync collection root was not found.');
    var itemsRoot = root.querySelector('[data-cms-async-items]') || root;
    var paginationRoot = root.querySelector('[data-cms-async-pagination]');
    var activeController = null;
    var requestSequence = 0;
    var destroyed = false;
    var lastParameters = {};
    var skeletonCount = Math.max(1, Number(options.skeletonCount) || 6);
    var skeletonVariant = ['card', 'row', 'stat'].includes(options.skeletonVariant) ? options.skeletonVariant : 'card';
    var cacheTtlMs = Math.max(0, Number(options.cacheTtlMs) || 0);
    var renderBatchSize = Math.max(1, Number(options.renderBatchSize) || 4);
    var timeoutMs = Math.max(3000, Number(options.timeoutMs) || 20000);

    root.classList.add('cms-async-collection');

    function showSkeleton() {
      var grid = document.createElement('div'); grid.className = 'cms-skeleton-grid';
      grid.style.setProperty('--cms-skeleton-columns', String(Math.max(1, Number(options.skeletonColumns) || 1)));
      for (var index = 0; index < skeletonCount; index++) grid.appendChild(skeletonItem(skeletonVariant));
      itemsRoot.replaceChildren(grid);
      if (paginationRoot) paginationRoot.replaceChildren();
      root.dataset.state = 'loading'; root.setAttribute('aria-busy', 'true');
    }

    function renderPagination(meta) {
      if (!paginationRoot) return;
      paginationRoot.replaceChildren();
      paginationRoot.classList.add('cms-async-pagination');
      var page = Math.max(1, Number(meta && meta.page) || 1);
      var pages = Math.max(1, Number(meta && meta.pages) || 1);
      if (pages <= 1) return;
      function addButton(label, targetPage, disabled, current) {
        var button = document.createElement('button'); button.type = 'button'; button.textContent = label; button.disabled = disabled;
        if (current) button.setAttribute('aria-current', 'page');
        if (!disabled && !current) button.addEventListener('click', function () { load(Object.assign({}, lastParameters, { page: targetPage })); });
        paginationRoot.appendChild(button);
      }
      addButton('Previous', page - 1, page <= 1, false);
      var first = Math.max(1, page - 2); var last = Math.min(pages, first + 4); first = Math.max(1, last - 4);
      for (var number = first; number <= last; number++) addButton(String(number), number, false, number === page);
      addButton('Next', page + 1, page >= pages, false);
    }

    function renderPayload(payload, requestId) {
      var envelope = payload && payload.data ? payload.data : payload;
      var items = envelope && Array.isArray(envelope.items) ? envelope.items : [];
      itemsRoot.replaceChildren();
      return new Promise(function (resolve, reject) {
        var index = 0;
        function finish() {
          if (requestId !== requestSequence || destroyed) { resolve(false); return; }
          renderPagination(envelope && envelope.pagination ? envelope.pagination : {});
          root.dataset.state = items.length ? 'ready' : 'empty'; root.setAttribute('aria-busy', 'false');
          root.dispatchEvent(new CustomEvent('cms:collection-loaded', { detail: { items: items, pagination: envelope && envelope.pagination ? envelope.pagination : {}, payload: envelope || {}, parameters: Object.assign({}, lastParameters) } }));
          resolve(true);
        }
        if (!items.length) {
          itemsRoot.appendChild(stateNode(options.emptyTitle || 'No results', options.emptyMessage || 'No items match the current filters.'));
          finish();
          return;
        }
        function renderBatch() {
          if (requestId !== requestSequence || destroyed) { resolve(false); return; }
          try {
            var fragment = document.createDocumentFragment();
            var limit = Math.min(items.length, index + renderBatchSize);
            for (; index < limit; index++) {
              var node = options.renderItem(items[index], index);
              if (!(node instanceof Node)) throw new Error('CmsAsync renderItem must return a DOM Node.');
              fragment.appendChild(node);
            }
            itemsRoot.appendChild(fragment);
          } catch (error) { reject(error); return; }
          if (index < items.length) window.requestAnimationFrame(renderBatch);
          else finish();
        }
        renderBatch();
      });
    }

    function load(parameters, loadOptions) {
      if (destroyed) return Promise.reject(new Error('CmsAsync collection was destroyed.'));
      lastParameters = Object.assign({}, parameters || {});
      var url = buildUrl(options.endpoint, lastParameters);
      var cached = responseCache.get(url);
      if (activeController) activeController.abort();
      var requestId = ++requestSequence;
      if (!(loadOptions && loadOptions.force) && cached && Date.now() - cached.savedAt <= cacheTtlMs) {
        activeController = null;
        return renderPayload(cached.payload, requestId).then(function () { return cached.payload; });
      }
      var controller = new AbortController();
      var timedOut = false;
      var timeout = window.setTimeout(function () { timedOut = true; controller.abort(); }, timeoutMs);
      activeController = controller;
      showSkeleton();
      return window.fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }, signal: controller.signal })
        .then(function (response) { if (!response.ok) throw new Error('Request failed with status ' + response.status); return response.json(); })
        .then(function (payload) {
          if (requestId !== requestSequence || destroyed) return null;
          if (cacheTtlMs > 0) responseCache.set(url, { payload: payload, savedAt: Date.now() });
          return renderPayload(payload, requestId).then(function () { return payload; });
        })
        .catch(function (error) {
          if ((error && error.name === 'AbortError' && !timedOut) || requestId !== requestSequence || destroyed) return null;
          itemsRoot.replaceChildren(stateNode(options.errorTitle || 'Unable to load data', options.errorMessage || 'Please retry the request.', function () { load(lastParameters, { force: true }); }));
          if (paginationRoot) paginationRoot.replaceChildren();
          root.dataset.state = 'error'; root.setAttribute('aria-busy', 'false');
          root.dispatchEvent(new CustomEvent('cms:collection-error', { detail: { error: error } }));
          return null;
        })
        .finally(function () { window.clearTimeout(timeout); if (activeController === controller) activeController = null; });
    }

    return {
      load: load,
      refresh: function () { return load(lastParameters, { force: true }); },
      destroy: function () { destroyed = true; requestSequence++; if (activeController) activeController.abort(); activeController = null; },
    };
  }

  window.CmsAsync = {
    createCollection: createCollection,
    debounce: debounce,
    buildUrl: buildUrl,
    clearCache: function () { responseCache.clear(); },
  };
})();
