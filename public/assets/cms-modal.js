(function () {
  var dialogs = Array.from(document.querySelectorAll('[data-cms-modal]'));
  if (!dialogs.length) return;
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

  document.querySelectorAll('[data-cms-modal-open]').forEach(function (trigger) {
    trigger.addEventListener('click', function () { openDialog(document.getElementById(trigger.dataset.cmsModalOpen), trigger); });
  });
  dialogs.forEach(function (dialog) {
    dialog.querySelectorAll('[data-cms-modal-close]').forEach(function (button) {
      button.addEventListener('click', function () { closeDialog(dialog); });
    });
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
  });
  var automatic = dialogs.find(function (dialog) { return dialog.dataset.autoOpen === 'true'; });
  if (automatic) openDialog(automatic, null);
})();
