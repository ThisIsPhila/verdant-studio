(() => {
  const toggle = document.querySelector('.admin-menu-toggle');
  const sidebar = document.getElementById('admin-navigation');
  if (!toggle || !sidebar) return;
  toggle.hidden = false;
  document.body.classList.add('has-admin-navigation');
  const close = () => {
    toggle.setAttribute('aria-expanded', 'false');
    sidebar.classList.remove('is-open');
  };
  toggle.addEventListener('click', () => {
    const open = toggle.getAttribute('aria-expanded') !== 'true';
    toggle.setAttribute('aria-expanded', String(open));
    sidebar.classList.toggle('is-open', open);
  });
  document.addEventListener('keydown', event => {
    if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
      close();
      toggle.focus();
    }
  });
})();
