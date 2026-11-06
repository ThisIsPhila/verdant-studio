(() => {
  const regionSelector = '#rental-calendar-region';
  let activeRequest = null;
  let requestNumber = 0;

  const canonicalUrl = (value) => {
    const url = new URL(value, window.location.href);
    if (url.origin !== window.location.origin || url.pathname !== '/admin/rental-calendar') return null;
    const month = url.searchParams.get('month');
    const view = url.searchParams.get('view') || 'month';
    if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(month || '') || month < '2000-01' || month > '2100-12'
      || !['month', 'agenda'].includes(view)) return null;
    url.search = new URLSearchParams({ month, view }).toString();
    url.hash = '';
    return url;
  };

  const currentRegion = () => document.querySelector(regionSelector);
  const setStatus = (region, message, error = false, retryUrl = null) => {
    const status = region?.querySelector('.rental-calendar-load-status');
    if (!status) return;
    status.replaceChildren();
    status.hidden = !message;
    status.classList.toggle('is-error', error);
    if (message) status.append(document.createTextNode(message));
    if (retryUrl) {
      const link = document.createElement('a');
      link.href = retryUrl;
      link.textContent = ' Reload this month';
      status.append(link);
    }
  };

  const focusTarget = (region, focusKey) => {
    if (!focusKey) return;
    const target = region.querySelector(`[data-calendar-focus="${CSS.escape(focusKey)}"]`);
    target?.focus({ preventScroll: true });
  };

  const fetchRegion = async (targetValue, { push = true, state = null, focusKey = null } = {}) => {
    const target = canonicalUrl(targetValue);
    const region = currentRegion();
    if (!target || !region) return false;
    if (activeRequest) activeRequest.abort();
    activeRequest = new AbortController();
    const localRequest = ++requestNumber;
    const focus = focusKey || document.activeElement?.dataset?.calendarFocus || null;
    const scrollY = window.scrollY;
    setStatus(region, 'Loading rental calendar…');
    region.setAttribute('aria-busy', 'true');
    const requestUrl = new URL(target.href);
    requestUrl.searchParams.set('fragment', '1');
    try {
      const response = await fetch(requestUrl, {
        credentials: 'same-origin',
        headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' },
        signal: activeRequest.signal,
      });
      if (localRequest !== requestNumber) return false;
      if (!response.ok || response.redirected || new URL(response.url).pathname !== '/admin/rental-calendar') {
        throw new Error('The calendar could not be refreshed.');
      }
      const html = await response.text();
      const parsed = new DOMParser().parseFromString(html, 'text/html');
      const nextRegion = parsed.querySelector(regionSelector);
      if (!nextRegion) throw new Error('The calendar response was incomplete.');
      if (push) {
        history.replaceState({ ...(history.state || {}), calendarScrollY: scrollY }, '', location.href);
        history.pushState({ calendarScrollY: scrollY, calendarFocus: focus }, '', target.href);
      }
      region.replaceWith(nextRegion);
      window.scrollTo({ top: state?.calendarScrollY ?? scrollY, behavior: 'auto' });
      focusTarget(nextRegion, state?.calendarFocus || focus);
      return true;
    } catch (error) {
      if (error.name === 'AbortError' || localRequest !== requestNumber) return false;
      const liveRegion = currentRegion();
      setStatus(liveRegion, 'The calendar did not update.', true, target.href);
      liveRegion?.removeAttribute('aria-busy');
      return false;
    } finally {
      if (localRequest === requestNumber) {
        currentRegion()?.removeAttribute('aria-busy');
        activeRequest = null;
      }
    }
  };

  document.addEventListener('click', (event) => {
    const region = currentRegion();
    const calendarLink = event.target.closest(`${regionSelector} [data-calendar-nav]`);
    if (calendarLink && !event.defaultPrevented && event.button === 0 && !event.metaKey && !event.ctrlKey && !event.shiftKey && !event.altKey) {
      const target = canonicalUrl(calendarLink.href);
      if (target) {
        event.preventDefault();
        fetchRegion(target.href, { focusKey: calendarLink.dataset.calendarFocus });
      }
      return;
    }
    const itemLink = event.target.closest(`${regionSelector} [data-calendar-item-id]`);
    if (itemLink && region) {
      try {
        sessionStorage.setItem('verdantRentalCalendarReturn', JSON.stringify({
          url: `${location.pathname}${location.search}`,
          scrollY: window.scrollY,
          itemId: itemLink.dataset.calendarItemId,
        }));
      } catch (_) { /* Storage may be unavailable; the validated return URL still works. */ }
    }
  });

  document.addEventListener('submit', (event) => {
    if (!event.target.matches(`${regionSelector} [data-calendar-picker]`)) return;
    const data = new FormData(event.target);
    const url = new URL('/admin/rental-calendar', window.location.origin);
    for (const [key, value] of data.entries()) url.searchParams.set(key, String(value));
    const target = canonicalUrl(url.href);
    if (target) {
      event.preventDefault();
      fetchRegion(target.href, { focusKey: 'month-picker' });
    }
  });

  window.addEventListener('popstate', (event) => {
    const target = canonicalUrl(location.href);
    if (target) fetchRegion(target.href, { push: false, state: event.state || null });
  });

  const currentUrl = canonicalUrl(location.href);
  if (currentUrl) history.replaceState({ ...(history.state || {}), calendarScrollY: window.scrollY }, '', currentUrl.href);
  try {
    const saved = JSON.parse(sessionStorage.getItem('verdantRentalCalendarReturn') || 'null');
    const region = currentRegion();
    if (saved && region && saved.url === `${location.pathname}${location.search}`) {
      sessionStorage.removeItem('verdantRentalCalendarReturn');
      requestAnimationFrame(() => {
        window.scrollTo({ top: Number(saved.scrollY) || 0, behavior: 'auto' });
        const target = region.querySelector(`[data-calendar-item-id="${CSS.escape(String(saved.itemId))}"]`);
        target?.focus({ preventScroll: true });
      });
    }
  } catch (_) { /* The calendar remains usable when session storage is unavailable. */ }
})();
