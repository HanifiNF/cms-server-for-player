(function () {
  'use strict';

  var dialogs = [];
  var boundDialogs = new WeakSet();
  var lastFocus = new WeakMap();

  function syncBodyLock() {
    document.body.classList.toggle('cms-modal-open', dialogs.some(function (dialog) { return dialog.open; }));
  }
  function openDialog(dialog, trigger) {
    if (!dialog || dialog.open) return;
    if (trigger) lastFocus.set(dialog, trigger);
    dialog.showModal();
    syncBodyLock();
    window.setTimeout(function () {
      var target = dialog.querySelector('[autofocus], input:not([type="hidden"]):not(:disabled), select:not(:disabled), textarea:not(:disabled), button:not(:disabled)');
      if (target) target.focus();
    }, 0);
  }
  function closeDialog(dialog) {
    if (!dialog || !dialog.open || dialog.dataset.locked === 'true') return;
    dialog.close();
  }
  function bindDialog(dialog) {
    if (boundDialogs.has(dialog)) return;
    boundDialogs.add(dialog);
    dialogs.push(dialog);
    dialog.addEventListener('click', function (event) {
      if (event.target !== dialog) return;
      var box = dialog.getBoundingClientRect();
      if (event.clientX < box.left || event.clientX > box.right || event.clientY < box.top || event.clientY > box.bottom) closeDialog(dialog);
    });
    dialog.addEventListener('cancel', function (event) { if (dialog.dataset.locked === 'true') event.preventDefault(); });
    dialog.addEventListener('close', function () {
      syncBodyLock();
      var trigger = lastFocus.get(dialog);
      if (trigger && document.contains(trigger)) trigger.focus();
    });
  }
  function refresh(scope) {
    var container = scope && scope.querySelectorAll ? scope : document;
    if (container.matches && container.matches('[data-cms-modal]')) bindDialog(container);
    container.querySelectorAll('[data-cms-modal]').forEach(bindDialog);
    dialogs = dialogs.filter(function (dialog) { return document.contains(dialog); });
    var automatic = dialogs.find(function (dialog) { return dialog.dataset.autoOpen === 'true' && !dialog.open; });
    if (automatic) openDialog(automatic, null);
  }

  document.addEventListener('click', function (event) {
    var closeTrigger = event.target.closest('[data-cms-modal-close]');
    if (closeTrigger) { closeDialog(closeTrigger.closest('[data-cms-modal]')); return; }
    var openTrigger = event.target.closest('[data-cms-modal-open]');
    if (!openTrigger) return;
    var dialog = document.getElementById(openTrigger.dataset.cmsModalOpen);
    if (dialog) { bindDialog(dialog); openDialog(dialog, openTrigger); }
  });

  refresh(document);
  window.CmsModal = { refresh: refresh, open: openDialog, close: closeDialog };
})();
