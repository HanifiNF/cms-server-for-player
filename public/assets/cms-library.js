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

  }

  function formatUploadBytes(bytes) {
    var units = ['B', 'KB', 'MB', 'GB', 'TB'];
    var value = Math.max(0, Number(bytes) || 0);
    var unit = 0;
    while (value >= 1024 && unit < units.length - 1) { value /= 1024; unit += 1; }
    return value.toFixed(unit >= 3 ? 2 : unit > 0 ? 1 : 0) + ' ' + units[unit];
  }

  function formatUploadEta(seconds) {
    if (!Number.isFinite(seconds) || seconds < 0) return 'Calculating…';
    var rounded = Math.ceil(seconds);
    if (rounded < 60) return rounded + 's remaining';
    return Math.floor(rounded / 60) + 'm ' + (rounded % 60) + 's remaining';
  }

  function hexDigest(buffer) {
    return Array.from(new Uint8Array(buffer)).map(function (byte) { return byte.toString(16).padStart(2, '0'); }).join('');
  }

  function fallbackSha256(buffer) {
    var bytes = new Uint8Array(buffer);
    var hash = new Uint32Array([0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19]);
    var constants = new Uint32Array([
      0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
      0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
      0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
      0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
      0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
      0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
      0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
      0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
    ]);
    var words = new Uint32Array(64);
    var rotate = function (value, shift) { return (value >>> shift) | (value << (32 - shift)); };
    var compress = function (source, offset) {
      var index;
      for (index = 0; index < 16; index += 1) {
        var position = offset + index * 4;
        words[index] = ((source[position] << 24) | (source[position + 1] << 16) | (source[position + 2] << 8) | source[position + 3]) >>> 0;
      }
      for (index = 16; index < 64; index += 1) {
        var first = words[index - 15];
        var second = words[index - 2];
        var sigma0 = rotate(first, 7) ^ rotate(first, 18) ^ (first >>> 3);
        var sigma1 = rotate(second, 17) ^ rotate(second, 19) ^ (second >>> 10);
        words[index] = (words[index - 16] + sigma0 + words[index - 7] + sigma1) >>> 0;
      }
      var a = hash[0], b = hash[1], c = hash[2], d = hash[3], e = hash[4], f = hash[5], g = hash[6], h = hash[7];
      for (index = 0; index < 64; index += 1) {
        var sum1 = rotate(e, 6) ^ rotate(e, 11) ^ rotate(e, 25);
        var choice = (e & f) ^ (~e & g);
        var temporary1 = (h + sum1 + choice + constants[index] + words[index]) >>> 0;
        var sum0 = rotate(a, 2) ^ rotate(a, 13) ^ rotate(a, 22);
        var majority = (a & b) ^ (a & c) ^ (b & c);
        var temporary2 = (sum0 + majority) >>> 0;
        h = g; g = f; f = e; e = (d + temporary1) >>> 0; d = c; c = b; b = a; a = (temporary1 + temporary2) >>> 0;
      }
      hash[0] = (hash[0] + a) >>> 0; hash[1] = (hash[1] + b) >>> 0;
      hash[2] = (hash[2] + c) >>> 0; hash[3] = (hash[3] + d) >>> 0;
      hash[4] = (hash[4] + e) >>> 0; hash[5] = (hash[5] + f) >>> 0;
      hash[6] = (hash[6] + g) >>> 0; hash[7] = (hash[7] + h) >>> 0;
    };
    var completeLength = bytes.length - (bytes.length % 64);
    for (var offset = 0; offset < completeLength; offset += 64) compress(bytes, offset);
    var remainder = bytes.length - completeLength;
    var tail = new Uint8Array(remainder < 56 ? 64 : 128);
    tail.set(bytes.subarray(completeLength));
    tail[remainder] = 0x80;
    var bitHigh = Math.floor(bytes.length / 0x20000000);
    var bitLow = (bytes.length << 3) >>> 0;
    var lengthOffset = tail.length - 8;
    tail[lengthOffset] = (bitHigh >>> 24) & 255; tail[lengthOffset + 1] = (bitHigh >>> 16) & 255;
    tail[lengthOffset + 2] = (bitHigh >>> 8) & 255; tail[lengthOffset + 3] = bitHigh & 255;
    tail[lengthOffset + 4] = (bitLow >>> 24) & 255; tail[lengthOffset + 5] = (bitLow >>> 16) & 255;
    tail[lengthOffset + 6] = (bitLow >>> 8) & 255; tail[lengthOffset + 7] = bitLow & 255;
    for (offset = 0; offset < tail.length; offset += 64) compress(tail, offset);
    return Array.from(hash).map(function (word) { return word.toString(16).padStart(8, '0'); }).join('');
  }

  var hashWorker = null;
  var hashRequest = 0;
  var hashCallbacks = {};

  function digestBuffer(buffer) {
    if (window.crypto && crypto.subtle) return crypto.subtle.digest('SHA-256', buffer).then(hexDigest);
    if (window.Worker && window.Blob && window.URL && URL.createObjectURL) {
      if (!hashWorker) {
        var source = fallbackSha256.toString() + ';self.onmessage=function(event){try{self.postMessage({id:event.data.id,hash:fallbackSha256(event.data.buffer)});}catch(error){self.postMessage({id:event.data.id,error:error.message||String(error)});}};';
        hashWorker = new Worker(URL.createObjectURL(new Blob([source], { type: 'text/javascript' })));
        hashWorker.onmessage = function (event) {
          var callback = hashCallbacks[event.data.id];
          if (!callback) return;
          delete hashCallbacks[event.data.id];
          event.data.error ? callback.reject(new Error(event.data.error)) : callback.resolve(event.data.hash);
        };
      }
      return new Promise(function (resolve, reject) {
        hashRequest += 1;
        hashCallbacks[hashRequest] = { resolve: resolve, reject: reject };
        hashWorker.postMessage({ id: hashRequest, buffer: buffer }, [buffer]);
      });
    }
    return Promise.resolve(fallbackSha256(buffer));
  }

  function digestBlob(blob) {
    return blob.arrayBuffer().then(digestBuffer);
  }

  function fileFingerprint(file) {
    var sampleSize = 1048576;
    return Promise.all([
      file.slice(0, Math.min(sampleSize, file.size)).arrayBuffer(),
      file.slice(Math.max(0, file.size - sampleSize), file.size).arrayBuffer()
    ]).then(function (samples) {
      var identity = new TextEncoder().encode([file.name, file.size, file.lastModified, file.type].join('\n') + '\n');
      var joined = new Uint8Array(identity.byteLength + samples[0].byteLength + samples[1].byteLength);
      joined.set(identity, 0);
      joined.set(new Uint8Array(samples[0]), identity.byteLength);
      joined.set(new Uint8Array(samples[1]), identity.byteLength + samples[0].byteLength);
      return digestBuffer(joined.buffer);
    });
  }

  Array.from(document.querySelectorAll('[data-resumable-upload-form]')).forEach(function (form) {
    if (!window.XMLHttpRequest || !window.FormData || !window.Uint8Array || !window.ArrayBuffer) return;
    var root = form.closest('[data-asset-upload-modal], [data-cms-modal]') || form.parentElement;
    var fileInput = form.querySelector('[data-upload-file]');
    var submitButton = form.querySelector('[data-upload-submit]');
    var panel = root.querySelector('[data-upload-progress]');
    if (!fileInput || !submitButton || !panel) return;
    var status = panel.querySelector('[data-upload-status]');
    var percent = panel.querySelector('[data-upload-percent]');
    var fill = panel.querySelector('[data-upload-fill]');
    var transferred = panel.querySelector('[data-upload-transferred]');
    var speed = panel.querySelector('[data-upload-speed]');
    var eta = panel.querySelector('[data-upload-eta]');
    var error = panel.querySelector('[data-upload-error]');
    var cancel = panel.querySelector('[data-upload-cancel]');
    var request = null;
    var session = null;
    var cancelled = false;
    var originalAction = form.action;
    var deliveryInputs = Array.from(form.querySelectorAll('input[name="delivery_mode"]'));
    var mediaField = form.querySelector('[data-media-file-field]');
    var posterField = form.querySelector('[data-poster-field]');
    var sideloadNote = form.querySelector('[data-sideload-note]');

    function syncDeliveryMode() {
      var selected = deliveryInputs.find(function (input) { return input.checked; });
      var sideload = selected && selected.value === 'sideload';
      form.action = sideload ? '/control/assets/external-encryption' : originalAction;
      fileInput.required = !sideload;
      fileInput.disabled = Boolean(sideload);
      if (mediaField) mediaField.hidden = sideload;
      if (posterField) posterField.hidden = false;
      if (sideloadNote) sideloadNote.hidden = !sideload;
      submitButton.textContent = sideload ? 'Create encryption job' : 'Upload asset';
      var title = form.querySelector('input[name="title"]');
      if (title) title.required = Boolean(sideload);
    }
    deliveryInputs.forEach(function (input) { input.addEventListener('change', syncDeliveryMode); });
    syncDeliveryMode();

    function restoreCsrf(payload) {
      if (!payload || !payload.csrf) return;
      var token = form.querySelector('input[name="' + CSS.escape(payload.csrf.name) + '"]');
      if (token) token.value = payload.csrf.hash;
    }

    function csrfData() {
      var data = new FormData();
      var token = form.querySelector('input[name^="csrf_"]');
      if (token) data.append(token.name, token.value);
      return data;
    }

    function uploadUrl(value) {
      try {
        var parsed = new URL(value, window.location.href);
        return window.location.origin + parsed.pathname.replace(/\/+$/, '');
      } catch (_) {
        return '/control/assets/uploads';
      }
    }

    function localRedirect(value) {
      if (!value) return window.location.href;
      try {
        var parsed = new URL(value, window.location.href);
        return window.location.origin + parsed.pathname + parsed.search + parsed.hash;
      } catch (_) {
        return window.location.href;
      }
    }

    function copyFormData(source) {
      var copy = new FormData();
      source.forEach(function (value, name) { copy.append(name, value); });
      return copy;
    }

    function refreshFormDataCsrf(data) {
      var token = form.querySelector('input[name^="csrf_"]');
      if (!token) return;
      data.delete(token.name);
      data.append(token.name, token.value);
    }

    function setUploading(uploading) {
      root.dataset.uploading = uploading ? 'true' : 'false';
      form.querySelectorAll('input, button, select, textarea').forEach(function (control) { control.disabled = uploading; });
      Array.from(root.querySelectorAll('[data-close-asset-upload], [data-cms-modal-close]')).forEach(function (button) { button.disabled = uploading; });
      cancel.disabled = !uploading;
    }

    function renderProgress(loaded, total, startedAt, startedBytes) {
      var value = total > 0 ? Math.min(100, Math.round(loaded / total * 100)) : 0;
      var elapsed = Math.max(0.001, (performance.now() - startedAt) / 1000);
      var bytesPerSecond = Math.max(0, loaded - startedBytes) / elapsed;
      percent.textContent = value + '%';
      fill.style.width = value + '%';
      transferred.textContent = formatUploadBytes(loaded) + ' / ' + formatUploadBytes(total);
      speed.textContent = bytesPerSecond > 0 ? formatUploadBytes(bytesPerSecond) + '/s' : 'Calculating speed…';
      eta.textContent = bytesPerSecond > 0 ? formatUploadEta((total - loaded) / bytesPerSecond) : 'Calculating…';
    }

    function fail(message) {
      status.textContent = cancelled ? 'Cancelled' : session && session.status === 'failed' ? 'Failed' : 'Paused';
      error.textContent = message;
      fill.classList.remove('processing');
      setUploading(false);
      cancel.disabled = true;
      submitButton.textContent = cancelled ? 'Start another upload' : 'Resume upload';
      request = null;
    }

    function send(url, data, onProgress) {
      return new Promise(function (resolve, reject) {
        var xhr = new XMLHttpRequest();
        request = xhr;
        xhr.open('POST', url, true);
        xhr.responseType = 'json';
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.setRequestHeader('Accept', 'application/json');
        if (onProgress) xhr.upload.onprogress = onProgress;
        xhr.onload = function () {
          var payload = xhr.response || {};
          var code = xhr.status;
          restoreCsrf(payload);
          if (request === xhr) request = null;
          if (code >= 200 && code < 300) resolve(payload);
          else {
            var responseError = new Error(payload.error && payload.error.message || 'Server rejected the upload (HTTP ' + code + ').');
            responseError.httpStatus = code;
            reject(responseError);
          }
        };
        xhr.onerror = function () { if (request === xhr) request = null; reject(new Error('The connection was interrupted.')); };
        xhr.onabort = function () { if (request === xhr) request = null; reject(new Error('Upload request was cancelled.')); };
        xhr.send(data);
      });
    }

    function readStatus(base, id) {
      return fetch(base + '/' + encodeURIComponent(id), {
        credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function (response) { return response.json().then(function (payload) {
        restoreCsrf(payload);
        if (!response.ok) throw new Error(payload.error && payload.error.message || 'Upload status is unavailable.');
        return payload.data.session;
      }); });
    }

    function recoverCsrf(base) {
      var separator = base.indexOf('?') === -1 ? '?' : '&';
      return fetch(base + separator + 'csrf_refresh=' + encodeURIComponent(String(Date.now())), {
        credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      }).then(function (response) { return response.json(); }).then(function (payload) {
        restoreCsrf(payload);
        if (!payload.csrf) throw new Error('A fresh upload security token could not be obtained.');
      });
    }

    function wait(ms) { return new Promise(function (resolve) { window.setTimeout(resolve, ms); }); }

    function isRetryable(uploadError) {
      return !uploadError.httpStatus || uploadError.httpStatus === 403 || uploadError.httpStatus >= 500;
    }

    function renderProcessing(current) {
      var processing = current && current.processing || {};
      var value = Math.max(0, Math.min(100, Number(processing.percent) || 0));
      var stageBytes = Math.max(0, Number(processing.processed_bytes) || 0);
      var stageTotal = Math.max(0, Number(processing.total_bytes) || 0);
      status.textContent = (processing.stage_label || (current.status === 'queued' ? 'Preparing processing' : 'Processing encrypted media')) + '…';
      percent.textContent = Math.round(value) + '%';
      fill.style.width = value + '%';
      fill.classList.remove('processing');
      transferred.textContent = stageTotal > 0 ? formatUploadBytes(stageBytes) + ' / ' + formatUploadBytes(stageTotal) : 'Upload complete';
      speed.textContent = 'Processing ' + Math.round(value) + '%';
      eta.textContent = processing.eta_seconds === null || processing.eta_seconds === undefined
        ? 'Calculating completion time…'
        : formatUploadEta(Number(processing.eta_seconds));
    }

    async function waitForProcessing(base, current) {
      var deadline = Date.now() + 86400000;
      var failures = 0;
      var queuedAt = Date.now();
      renderProcessing(current);
      cancel.disabled = true;
      while ((current.status === 'queued' || current.status === 'processing') && Date.now() < deadline) {
        await wait(1000);
        try {
          current = await readStatus(base, current.id);
          failures = 0;
          renderProcessing(current);
        } catch (pollError) {
          failures += 1;
          if (failures >= 3) throw pollError;
        }
        if (current.status === 'queued' && Date.now() - queuedAt > 15000) {
          throw new Error('The media worker did not start. Check media.workerPhpPath, then resume the upload.');
        }
      }
      if (current.status === 'queued' || current.status === 'processing') throw new Error('Processing is taking longer than expected. Resume to check it again.');
      if (current.status === 'failed') throw new Error(current.error || 'Encrypted media processing failed.');
      return current;
    }

    async function uploadChunk(base, file, current, index, startedAt, startedBytes) {
      var start = index * current.chunk_size_bytes;
      var blob = file.slice(start, Math.min(file.size, start + current.chunk_size_bytes));
      var hash = await digestBlob(blob);
      for (var attempt = 0; attempt <= 3; attempt += 1) {
        if (cancelled) throw new Error('Upload cancelled.');
        var data = csrfData();
        data.append('index', String(index));
        data.append('sha256', hash);
        data.append('chunk', blob, file.name + '.part');
        try {
          var payload = await send(base + '/' + encodeURIComponent(current.id) + '/chunks', data, function (progress) {
            renderProgress(start + progress.loaded, file.size, startedAt, startedBytes);
          });
          return payload.data.session;
        } catch (uploadError) {
          try {
            var refreshed = await readStatus(base, current.id);
            if (refreshed.next_chunk_index > index) return refreshed;
            current = refreshed;
          } catch (_) {}
          if (cancelled || attempt === 3 || !isRetryable(uploadError)) throw uploadError;
          status.textContent = 'Connection interrupted · retrying…';
          await wait([1000, 2000, 4000][attempt]);
        }
      }
      return current;
    }

    form.addEventListener('submit', async function (event) {
      var delivery = form.querySelector('input[name="delivery_mode"]:checked');
      if (delivery && delivery.value === 'sideload') {
        event.preventDefault();
        if (form.dataset.sideloadSubmitting === 'true') return;
        if (!form.reportValidity()) return;
        form.dataset.sideloadSubmitting = 'true';
        submitButton.disabled = true;
        submitButton.textContent = 'Creating job…';
        try {
          error.textContent = '';
          for (var jobAttempt = 0; jobAttempt < 2; jobAttempt += 1) {
            await recoverCsrf(uploadUrl(form.dataset.uploadBase));
            // The film input is disabled in side-load mode, so this contains
            // metadata and the optional poster without uploading the film.
            var jobData = new FormData(form);
            jobData.delete('media');
            var jobResponse = await fetch(form.action, {
              method: 'POST', credentials: 'same-origin', cache: 'no-store',
              headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
              body: jobData
            });
            // A rejected CSRF request has not reached the controller. Other
            // failures must not auto-retry, since the job may already exist.
            if (jobResponse.status === 403 && jobAttempt === 0) continue;
            var jobPayload = await jobResponse.json().catch(function () { return {}; });
            restoreCsrf(jobPayload);
            if (!jobResponse.ok || !jobPayload.data || !jobPayload.data.redirect) {
              throw new Error(jobPayload.error && jobPayload.error.message || 'Could not create encryption job (HTTP ' + jobResponse.status + ').');
            }
            window.location.assign(localRedirect(jobPayload.data.redirect));
            break;
          }
        } catch (tokenError) {
          form.dataset.sideloadSubmitting = 'false';
          submitButton.disabled = false;
          submitButton.textContent = 'Create encryption job';
          panel.hidden = false;
          status.textContent = 'Encryption job could not be created';
          error.textContent = tokenError.message || 'Check the connection and try again.';
          cancel.disabled = true;
        }
        return;
      }
      if (!fileInput.files.length && form.dataset.uploadPurpose === 'revision') return;
      event.preventDefault();
      if (request || !fileInput.files.length) return;
      var file = fileInput.files[0];
      // Capture every successful form control before setUploading() disables the
      // form. Disabled controls are intentionally omitted by new FormData(form).
      var submittedFields = new FormData(form);
      var base = uploadUrl(form.dataset.uploadBase);
      var startedAt = performance.now();
      var startedBytes = 0;
      cancelled = false;
      panel.hidden = false;
      error.textContent = '';
      status.textContent = 'Preparing resumable upload…';
      fill.classList.remove('processing');
      submitButton.textContent = 'Uploading…';
      setUploading(true);
      renderProgress(0, file.size, startedAt, 0);
      try {
        var fingerprint = await fileFingerprint(file);
        if (cancelled) throw new Error('Upload cancelled.');
        await recoverCsrf(base);
        var initialized = null;
        for (var initiateAttempt = 0; initiateAttempt <= 3; initiateAttempt += 1) {
          if (cancelled) throw new Error('Upload cancelled.');
          var initData = copyFormData(submittedFields);
          initData.delete(fileInput.name);
          refreshFormDataCsrf(initData);
          initData.append('purpose', form.dataset.uploadPurpose || 'asset');
          if (form.dataset.uploadTarget) initData.append('target_asset_id', form.dataset.uploadTarget);
          initData.append('filename', file.name);
          initData.append('mime_type', file.type || 'application/octet-stream');
          initData.append('size_bytes', String(file.size));
          initData.append('last_modified_ms', String(file.lastModified || 0));
          initData.append('fingerprint', fingerprint);
          try {
            initialized = await send(base, initData);
            break;
          } catch (initiateError) {
            if (initiateAttempt === 3 || !isRetryable(initiateError)) throw initiateError;
            try { await recoverCsrf(base); } catch (_) {}
            status.textContent = 'Connection interrupted · retrying…';
            await wait([1000, 2000, 4000][initiateAttempt]);
          }
        }
        session = initialized.data.session;
        if (session.status === 'completed') {
          window.location.assign(localRedirect(initialized.data.redirect_url));
          return;
        }
        startedBytes = session.received_bytes;
        status.textContent = session.received_bytes > 0 ? 'Resuming upload…' : 'Uploading film…';
        renderProgress(session.received_bytes, file.size, startedAt, startedBytes);
        while (session.received_bytes < file.size) {
          session = await uploadChunk(base, file, session, session.next_chunk_index, startedAt, startedBytes);
        }
        status.textContent = 'Preparing encrypted media processing…';
        percent.textContent = '0%';
        fill.style.width = '0%';
        fill.classList.remove('processing');
        speed.textContent = 'Upload complete';
        eta.textContent = 'Starting background worker…';
        cancel.disabled = true;
        session = await readStatus(base, session.id);
        if (session.status === 'completed') { window.location.reload(); return; }
        var finalized = await send(base + '/' + encodeURIComponent(session.id) + '/finalize', csrfData());
        session = finalized.data.session;
        if (session.status !== 'completed') session = await waitForProcessing(base, session);
        status.textContent = 'Upload complete';
        percent.textContent = '100%';
        fill.style.width = '100%';
        fill.classList.remove('processing');
        speed.textContent = 'Saved securely';
        eta.textContent = 'Asset is ready in the catalog.';
        window.setTimeout(function () {
          var destination = finalized.data.redirect_url || (session.result_public_id ? '/control/library/' + encodeURIComponent(session.result_public_id) : window.location.href);
          window.location.assign(localRedirect(destination));
        }, 700);
      } catch (uploadError) {
        if (!cancelled && session) {
          try {
            var latest = await readStatus(base, session.id);
            if (latest.status === 'processing') latest = await waitForProcessing(base, latest);
            session = latest;
            renderProgress(latest.received_bytes, file.size, startedAt, startedBytes);
            if (latest.status === 'completed') { window.location.reload(); return; }
          } catch (_) {}
        }
        fail(uploadError.message || 'Upload could not continue.');
      }
    });

    cancel.addEventListener('click', async function () {
      cancelled = true;
      if (request) request.abort();
      if (!session) { fail('Upload cancelled. No asset was added.'); return; }
      try {
        var base = uploadUrl(form.dataset.uploadBase);
        try { session = await readStatus(base, session.id); } catch (_) {}
        await send(base + '/' + encodeURIComponent(session.id) + '/cancel', csrfData());
        session = null;
        fail('Upload cancelled. Staged chunks were removed.');
      } catch (cancelError) {
        fail(cancelError.message || 'Upload cancellation could not be confirmed.');
      }
    });
  });

  var distributionModals = Array.from(document.querySelectorAll('[data-distribution-modal]'));
  if (distributionModals.length) {
    var distributionFocus = null;

    function updateDistributionForm(modal) {
      var form = modal.querySelector('.distribution-form');
      if (!form) return;
      var checkedStudios = Array.from(form.querySelectorAll('[data-studio-check]:checked'));
      var summary = form.querySelector('[data-distribution-summary]');
      var submit = form.querySelector('[data-distribution-submit]');
      if (summary) summary.textContent = checkedStudios.length ? checkedStudios.length + ' Studio(s) selected' : 'No Studio selected';
      if (submit) submit.disabled = checkedStudios.length === 0;

      form.querySelectorAll('[data-distribution-location]').forEach(function (location) {
        var parent = location.querySelector('[data-location-check]');
        if (!parent) return;
        var enabledChildren = Array.from(location.querySelectorAll('[data-studio-check]:not(:disabled)'));
        var checkedChildren = enabledChildren.filter(function (child) { return child.checked; });
        parent.checked = enabledChildren.length > 0 && checkedChildren.length === enabledChildren.length;
        parent.indeterminate = checkedChildren.length > 0 && checkedChildren.length < enabledChildren.length;
      });
    }

    function closeDistribution(modal) {
      modal.hidden = true;
      var form = modal.querySelector('.distribution-form');
      if (form) form.reset();
      var search = modal.querySelector('[data-distribution-search]');
      if (search) search.dispatchEvent(new Event('input'));
      updateDistributionForm(modal);
      if (!distributionModals.some(function (candidate) { return !candidate.hidden; })) document.body.classList.remove('library-modal-open');
      if (distributionFocus) distributionFocus.focus();
    }

    document.querySelectorAll('[data-open-distribution]').forEach(function (button) {
      button.addEventListener('click', function () {
        var modal = document.querySelector('[data-distribution-modal="' + button.dataset.openDistribution + '"]');
        if (!modal) return;
        distributionFocus = button;
        modal.hidden = false;
        document.body.classList.add('library-modal-open');
        var focusTarget = modal.querySelector('[data-distribution-search], .library-modal-close');
        if (focusTarget) focusTarget.focus();
      });
    });

    distributionModals.forEach(function (distributionModal) {
      distributionModal.querySelectorAll('[data-close-distribution]').forEach(function (button) {
        button.addEventListener('click', function () { closeDistribution(distributionModal); });
      });
      var form = distributionModal.querySelector('.distribution-form');
      if (!form) return;
      form.querySelectorAll('[data-location-check]').forEach(function (parent) {
        parent.addEventListener('change', function () {
          var location = parent.closest('[data-distribution-location]');
          location.querySelectorAll('[data-studio-check]:not(:disabled)').forEach(function (child) { child.checked = parent.checked; });
          updateDistributionForm(distributionModal);
        });
      });
      form.querySelectorAll('[data-studio-check]').forEach(function (child) {
        child.addEventListener('change', function () { updateDistributionForm(distributionModal); });
      });
      var search = form.querySelector('[data-distribution-search]');
      if (search) search.addEventListener('input', function () {
        var query = search.value.trim().toLocaleLowerCase();
        var visibleLocations = 0;
        form.querySelectorAll('[data-distribution-location]').forEach(function (location) {
          var locationName = location.querySelector('summary strong').textContent.toLocaleLowerCase();
          var locationMatches = query === '' || locationName.includes(query);
          var visibleStudios = 0;
          location.querySelectorAll('[data-distribution-studio]').forEach(function (studio) {
            var matches = locationMatches || studio.dataset.searchText.includes(query);
            studio.hidden = !matches;
            if (matches) visibleStudios += 1;
          });
          location.hidden = visibleStudios === 0;
          if (!location.hidden) visibleLocations += 1;
          if (query !== '' && !location.hidden) location.open = true;
        });
        var empty = form.querySelector('[data-distribution-empty]');
        if (empty) empty.hidden = visibleLocations !== 0;
      });
      updateDistributionForm(distributionModal);
    });

    document.addEventListener('keydown', function (event) {
      if (event.key !== 'Escape') return;
      var openModal = distributionModals.find(function (candidate) { return !candidate.hidden; });
      if (openModal) closeDistribution(openModal);
    });
  }
})();
