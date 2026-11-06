(() => {
  const form = document.querySelector('[data-access-form]');
  if (!(form instanceof HTMLFormElement)) return;
  const tokenField = form.querySelector('input[name="access_token"]');
  const error = document.querySelector('[data-access-error]');
  const token = new URLSearchParams(window.location.hash.slice(1)).get('token') || '';
  window.history.replaceState(null, '', window.location.pathname + window.location.search);
  if (!/^[a-f0-9]{64}$/.test(token) || !(tokenField instanceof HTMLInputElement)) {
    if (error instanceof HTMLElement) error.hidden = false;
    for (const control of form.querySelectorAll('button[type="submit"]')) control.disabled = true;
    return;
  }
  tokenField.value = token;
})();
