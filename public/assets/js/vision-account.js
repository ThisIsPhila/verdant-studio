'use strict';
(() => {
    const root = document.querySelector('[data-account-tabs]');
    const navigation = root?.querySelector('.account-navigation');
    if (!navigation) return;
    const tabs = [...navigation.querySelectorAll('a')];
    const panels = tabs.map(tab => document.getElementById(tab.hash.slice(1)));
    if (panels.some(panel => !panel)) return;

    // Preserve native section links without JavaScript; enhanced hashes never scroll.
    panels.forEach(panel => { panel.id += '-panel'; });
    navigation.setAttribute('role', 'tablist');
    const desktop = window.matchMedia('(min-width: 1024px)');
    const orientation = () => navigation.setAttribute('aria-orientation', desktop.matches ? 'vertical' : 'horizontal');
    desktop.addEventListener('change', orientation);
    orientation();
    root.dataset.tabsReady = '';
    const select = (index, writeHistory = false) => {
        tabs.forEach((tab, i) => {
            const active = i === index;
            tab.id = panels[i].id + '-tab';
            tab.setAttribute('role', 'tab');
            tab.setAttribute('aria-controls', panels[i].id);
            tab.setAttribute('aria-selected', String(active));
            tab.tabIndex = active ? 0 : -1;
            panels[i].setAttribute('role', 'tabpanel');
            panels[i].setAttribute('aria-labelledby', tab.id);
            panels[i].setAttribute('aria-hidden', String(!active));
            panels[i].inert = !active;
            panels[i].tabIndex = active ? 0 : -1;
        });
        if (writeHistory && location.hash !== tabs[index].hash) history.pushState(history.state, '', tabs[index].hash);
    };
    const restore = () => {
        const index = tabs.findIndex(tab => tab.hash === location.hash);
        select(index < 0 ? 0 : index);
    };
    tabs.forEach((tab, index) => {
        tab.addEventListener('click', event => {
            if (event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
            event.preventDefault();
            select(index, true);
        });
        tab.addEventListener('keydown', event => {
            if (event.key === ' ') {
                event.preventDefault();
                select(index, true);
                return;
            }
            const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1
                : ['ArrowRight', 'ArrowDown'].includes(event.key) ? (index + 1) % tabs.length
                : ['ArrowLeft', 'ArrowUp'].includes(event.key) ? (index + tabs.length - 1) % tabs.length : null;
            if (next === null) return;
            event.preventDefault();
            select(next, true);
            tabs[next].focus({ preventScroll: true });
        });
    });
    window.addEventListener('hashchange', restore);
    window.addEventListener('popstate', restore);
    restore();
})();
