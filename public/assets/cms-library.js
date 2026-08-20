(function () {
  var widgets = Array.from(document.querySelectorAll('[data-genre-multiselect]'));

  function closeGenre(widget) {
    var trigger = widget.querySelector('.genre-multiselect-trigger');
    var panel = widget.querySelector('.genre-multiselect-panel');
    if (!trigger || !panel) return;
    trigger.setAttribute('aria-expanded', 'false');
    panel.hidden = true;
  }

  widgets.forEach(function (widget) {
    var trigger = widget.querySelector('.genre-multiselect-trigger');
    var panel = widget.querySelector('.genre-multiselect-panel');
    var search = widget.querySelector('[data-genre-search]');
    var summary = widget.querySelector('[data-genre-summary]');
    var chipList = widget.querySelector('[data-genre-chips]');
    var options = Array.from(widget.querySelectorAll('[data-genre-option]'));
    var empty = widget.querySelector('[data-genre-empty]');

    function update() {
      var selected = options.map(function (option) { return option.querySelector('input'); }).filter(function (input) { return input.checked; });
      summary.textContent = selected.length === 0 ? 'Select genres' : selected.length === 1 ? selected[0].dataset.genreName : selected.length + ' genres selected';
      var chips = selected.map(function (input) {
        var chip = document.createElement('button');
        chip.type = 'button';
        chip.textContent = input.dataset.genreName;
        chip.title = 'Remove ' + input.dataset.genreName;
        chip.addEventListener('click', function () { input.checked = false; update(); });
        return chip;
      });
      chipList.replaceChildren.apply(chipList, chips);
    }

    trigger.addEventListener('click', function () {
      var willOpen = panel.hidden;
      widgets.forEach(closeGenre);
      if (!willOpen) return;
      panel.hidden = false;
      trigger.setAttribute('aria-expanded', 'true');
      search.value = '';
      options.forEach(function (option) { option.hidden = false; });
      empty.hidden = true;
      window.setTimeout(function () { search.focus(); }, 0);
    });
    search.addEventListener('input', function () {
      var query = search.value.trim().toLocaleLowerCase();
      var visible = 0;
      options.forEach(function (option) {
        var matches = option.textContent.toLocaleLowerCase().includes(query);
        option.hidden = !matches;
        if (matches) visible += 1;
      });
      empty.hidden = visible !== 0;
    });
    options.forEach(function (option) { option.querySelector('input').addEventListener('change', update); });
    update();
  });

  document.addEventListener('click', function (event) {
    widgets.forEach(function (widget) { if (!widget.contains(event.target)) closeGenre(widget); });
  });

  var modal = document.querySelector('[data-asset-upload-modal]');
  if (modal) {
    var openButtons = document.querySelectorAll('[data-open-asset-upload]');
    var closeButtons = modal.querySelectorAll('[data-close-asset-upload]');
    var lastFocus = null;

    function openModal(event) {
      lastFocus = event.currentTarget;
      modal.hidden = false;
      document.body.classList.add('library-modal-open');
      var closeButton = modal.querySelector('.library-modal-close');
      if (closeButton) closeButton.focus();
    }
    function closeModal() {
      if (modal.dataset.uploading === 'true') return;
      modal.hidden = true;
      document.body.classList.remove('library-modal-open');
      if (lastFocus) lastFocus.focus();
    }
    openButtons.forEach(function (button) { button.addEventListener('click', openModal); });
    closeButtons.forEach(function (button) { button.addEventListener('click', closeModal); });
    document.addEventListener('keydown', function (event) { if (event.key === 'Escape' && !modal.hidden) closeModal(); });

    var form = modal.querySelector('[data-asset-upload-form]');
    if (form && window.XMLHttpRequest && window.FormData) {
      var fileInput = form.querySelector('[data-upload-file]');
      var submitButton = form.querySelector('[data-upload-submit]');
      var panel = modal.querySelector('[data-upload-progress]');
      var status = panel.querySelector('[data-upload-status]');
      var percent = panel.querySelector('[data-upload-percent]');
      var fill = panel.querySelector('[data-upload-fill]');
      var transferred = panel.querySelector('[data-upload-transferred]');
      var speed = panel.querySelector('[data-upload-speed]');
      var eta = panel.querySelector('[data-upload-eta]');
      var error = panel.querySelector('[data-upload-error]');
      var cancel = panel.querySelector('[data-upload-cancel]');
      var request = null;

      function formatBytes(bytes) {
        var units = ['B', 'KB', 'MB', 'GB', 'TB'];
        var value = Math.max(0, Number(bytes) || 0);
        var unit = 0;
        while (value >= 1024 && unit < units.length - 1) { value /= 1024; unit += 1; }
        return value.toFixed(unit >= 3 ? 2 : unit > 0 ? 1 : 0) + ' ' + units[unit];
      }
      function formatEta(seconds) {
        if (!Number.isFinite(seconds) || seconds < 0) return 'Calculating…';
        var rounded = Math.ceil(seconds);
        if (rounded < 60) return rounded + 's remaining';
        return Math.floor(rounded / 60) + 'm ' + (rounded % 60) + 's remaining';
      }
      function setUploading(uploading) {
        modal.dataset.uploading = uploading ? 'true' : 'false';
        form.querySelectorAll('input, button, select, textarea').forEach(function (control) { control.disabled = uploading; });
        closeButtons.forEach(function (button) { button.disabled = uploading; });
        cancel.disabled = !uploading;
      }
      function restoreCsrf(payload) {
        if (!payload || !payload.csrf) return;
        var token = form.querySelector('input[name="' + CSS.escape(payload.csrf.name) + '"]');
        if (token) token.value = payload.csrf.hash;
      }
      function fail(message) {
        status.textContent = 'Upload failed';
        error.textContent = message;
        fill.classList.remove('processing');
        setUploading(false);
        cancel.disabled = true;
        submitButton.textContent = 'Try upload again';
        request = null;
      }

      form.addEventListener('submit', function (event) {
        event.preventDefault();
        if (request || !fileInput.files.length) return;
        var startedAt = performance.now();
        var data = new FormData(form);
        request = new XMLHttpRequest();
        panel.hidden = false;
        error.textContent = '';
        status.textContent = 'Uploading film…';
        percent.textContent = '0%';
        fill.style.width = '0%';
        fill.classList.remove('processing');
        transferred.textContent = '0 B / ' + formatBytes(fileInput.files[0].size);
        speed.textContent = 'Calculating speed…';
        eta.textContent = 'Calculating…';
        submitButton.textContent = 'Uploading…';
        setUploading(true);
        cancel.disabled = false;
        request.open('POST', form.action, true);
        request.responseType = 'json';
        request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        request.setRequestHeader('Accept', 'application/json');
        request.upload.onprogress = function (progress) {
          if (!progress.lengthComputable) return;
          var elapsed = Math.max(0.001, (performance.now() - startedAt) / 1000);
          var bytesPerSecond = progress.loaded / elapsed;
          var value = Math.min(100, Math.round(progress.loaded / progress.total * 100));
          percent.textContent = value + '%';
          fill.style.width = value + '%';
          transferred.textContent = formatBytes(progress.loaded) + ' / ' + formatBytes(progress.total);
          speed.textContent = formatBytes(bytesPerSecond) + '/s';
          eta.textContent = formatEta((progress.total - progress.loaded) / bytesPerSecond);
        };
        request.upload.onload = function () {
          status.textContent = 'Encrypting media as LDG…';
          percent.textContent = '100%';
          fill.style.width = '100%';
          fill.classList.add('processing');
          eta.textContent = 'Detecting duration, encrypting chunks, and verifying SHA-256…';
          cancel.disabled = true;
        };
        request.onload = function () {
          var payload = request.response || {};
          restoreCsrf(payload);
          if (request.status < 200 || request.status >= 300) {
            fail(payload.error && payload.error.message || 'Server rejected the upload (HTTP ' + request.status + ').');
            return;
          }
          status.textContent = 'Upload complete';
          percent.textContent = '100%';
          fill.classList.remove('processing');
          speed.textContent = 'Saved securely';
          eta.textContent = payload.data && payload.data.message || 'Asset is ready in the catalog.';
          cancel.disabled = true;
          request = null;
          window.setTimeout(function () { window.location.reload(); }, 700);
        };
        request.onerror = function () { fail('The connection was interrupted. The film was not added to the catalog.'); };
        request.onabort = function () { fail('Upload cancelled. No asset was added.'); };
        request.send(data);
      });
      cancel.addEventListener('click', function () { if (request) request.abort(); });
    }
  }
})();
