(() => {
  const select = document.querySelector('[data-storage-driver]');
  if (!select) return;
  const fields = [...document.querySelectorAll('[data-storage-fields]')];
  const port = document.querySelector('[data-ftps-port]');
  const mode = document.querySelector('[data-ftps-mode]');
  const applyDriver = () => {
    fields.forEach((group) => {
      const active = group.dataset.storageFields === select.value;
      group.hidden = !active;
      group.querySelectorAll('input, select, textarea').forEach((control) => { control.disabled = !active; });
    });
  };
  const applyMode = () => {
    if (!port || !mode) return;
    const previousDefault = port.dataset.defaultPort || '21';
    if (port.value === previousDefault || port.value === '') port.value = mode.value === 'implicit' ? '990' : '21';
    port.dataset.defaultPort = port.value;
  };
  select.addEventListener('change', applyDriver);
  mode?.addEventListener('change', applyMode);
  applyDriver();
  applyMode();
})();
