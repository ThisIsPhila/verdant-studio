(() => {
    const form = document.querySelector('#recovery-reset-form');
    const status = document.querySelector('#recovery-status');
    const invalid = document.querySelector('#recovery-link-invalid');
    const tokenInput = document.querySelector('#recovery-token');
    if (!form || !status || !invalid || !tokenInput) return;

    const token = new URLSearchParams(window.location.hash.slice(1)).get('token') || tokenInput.value;
    const csrf = form.querySelector('input[name="csrf"]')?.value || '';
    const validationUrl = form.dataset.validationUrl || '/password-reset/validate';
    if (window.location.hash) window.history.replaceState(null, document.title, window.location.pathname);

    if (!/^[A-Za-z0-9_-]{43}$/.test(token)) {
        status.hidden = true;
        invalid.hidden = false;
        return;
    }

    const body = new URLSearchParams({ csrf, token });
    fetch(validationUrl, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
        body,
        cache: 'no-store',
        referrerPolicy: 'no-referrer',
    }).then((response) => response.ok ? response.json() : { valid: false })
        .then((result) => {
            status.hidden = true;
            if (result.valid !== true) {
                invalid.hidden = false;
                return;
            }
            tokenInput.value = token;
            form.hidden = false;
            form.querySelector('input[name="password"]')?.focus();
        })
        .catch(() => {
            status.hidden = true;
            invalid.hidden = false;
        });
})();
