(() => {
    const search = document.querySelector('[data-recipe-search]');
    if (search) {
        search.hidden = false;
        search.addEventListener('input', () => {
            const query = search.value.trim().toLocaleLowerCase();
            const entries = [...document.querySelectorAll('[data-recipe-name]')];
            entries.forEach(entry => { entry.hidden = !entry.dataset.recipeName.includes(query); });
            document.querySelector('[data-recipe-empty]').hidden = entries.some(entry => !entry.hidden);
        });
    }
    const form = document.querySelector('[data-recipe-editor]');
    if (!form) return;
    const tabs = [...form.querySelectorAll('[data-recipe-tab]')];
    const panels = [...form.querySelectorAll('[data-recipe-panel]')];
    const message = form.querySelector('[data-recipe-message]');
    const show = key => {
        tabs.forEach(tab => {
            const active = tab.dataset.recipeTab === key;
            tab.setAttribute('aria-selected', String(active));
            tab.tabIndex = active ? 0 : -1;
        });
        panels.forEach(panel => { panel.hidden = panel.dataset.recipePanel !== key; });
    };
    form.querySelector('.recipe-tabs').setAttribute('role', 'tablist');
    tabs.forEach((tab, index) => {
        tab.id = `recipe-tab-${tab.dataset.recipeTab}`;
        tab.setAttribute('role', 'tab');
        tab.setAttribute('aria-controls', `recipe-${tab.dataset.recipeTab}`);
        tab.addEventListener('click', event => { event.preventDefault(); show(tab.dataset.recipeTab); });
        tab.addEventListener('keydown', event => {
            let next;
            if (event.key === 'ArrowRight') next = (index + 1) % tabs.length;
            if (event.key === 'ArrowLeft') next = (index + tabs.length - 1) % tabs.length;
            if (event.key === 'Home') next = 0;
            if (event.key === 'End') next = tabs.length - 1;
            if (next === undefined) return;
            event.preventDefault();
            show(tabs[next].dataset.recipeTab);
            tabs[next].focus();
        });
    });
    panels.forEach(panel => {
        panel.setAttribute('role', 'tabpanel');
        panel.setAttribute('aria-labelledby', `recipe-tab-${panel.dataset.recipePanel}`);
    });
    const updateSummary = () => {
        const summary = form.querySelector('[data-recipe-summary]');
        summary.replaceChildren();
        form.querySelectorAll('[data-recipe-item]').forEach(item => {
            const value = suffix => item.querySelector(`[name$="[${suffix}]"]`);
            const name = item.querySelector('[data-item-label]').value.trim() || 'New item';
            item.querySelector('legend').textContent = name;
            const source = item.querySelector('[data-item-source]').value;
            const selected = [...item.querySelectorAll(`[data-item-source-panel="${source}"] input:checked`)]
                .map(input => input.dataset.itemName);
            const entry = document.createElement('li');
            entry.textContent = `${name} · ${value('archived').checked ? 'Archived' : value('required').checked ? 'Required' : 'Optional'} · ${value('min_quantity').value}–${value('max_quantity').value} · ${value('permitted_mode').selectedOptions[0].textContent}${selected.length ? ' · ' + selected.join(', ') : ''}`;
            summary.append(entry);
        });
    };
    const installPicker = picker => {
        const input = picker.querySelector('[data-picker-search]');
        const more = picker.querySelector('[data-picker-more]');
        const options = [...picker.querySelectorAll('[data-picker-option]')];
        let limit = 40;
        const refresh = () => {
            const query = input.value.trim().toLocaleLowerCase();
            let matches = 0;
            const selected = [];
            options.forEach(option => {
                const checkbox = option.querySelector('input');
                if (checkbox.checked) selected.push(checkbox.dataset.itemName);
                const matchesQuery = option.dataset.search.includes(query);
                if (matchesQuery) matches++;
                option.hidden = !checkbox.checked && (!matchesQuery || matches > limit);
            });
            more.hidden = matches <= limit;
            picker.querySelector('[data-picker-count]').textContent = `${selected.length} selected · ${matches} matching`;
            picker.nextElementSibling.textContent = selected.length ? selected.join(', ') : 'No items selected.';
        };
        input.hidden = false;
        input.addEventListener('input', () => { limit = 40; refresh(); });
        more.addEventListener('click', () => { limit += 40; refresh(); });
        picker.addEventListener('change', event => {
            if (event.target.checked && options.filter(option => option.querySelector('input').checked).length > 100) {
                event.target.checked = false;
                message.textContent = 'Choose at most 100 items per list.';
            }
            refresh();
        });
        refresh();
    };
    const installItem = item => {
        item.querySelectorAll('[data-recipe-picker]').forEach(installPicker);
        const source = item.querySelector('[data-item-source]');
        const refreshSource = () => item.querySelectorAll('[data-item-source-panel]').forEach(panel => {
            panel.hidden = panel.dataset.itemSourcePanel !== source.value;
        });
        source.addEventListener('change', refreshSource);
        refreshSource();
        const remove = item.querySelector('[data-item-remove]');
        if (remove) {
            remove.hidden = false;
            remove.addEventListener('click', () => { item.remove(); updateSummary(); });
        }
    };
    form.querySelectorAll('[data-recipe-item]').forEach(installItem);
    form.querySelectorAll('[data-recipe-add]').forEach(link => link.addEventListener('click', event => {
        event.preventDefault();
        if (form.querySelectorAll('[data-recipe-item]').length >= 30) {
            message.textContent = 'A recipe can contain at most 30 items.';
            return;
        }
        const index = Number(form.dataset.nextIndex);
        form.dataset.nextIndex = String(index + 1);
        const fragment = form.querySelector('[data-recipe-template]').content.cloneNode(true);
        fragment.querySelectorAll('[name]').forEach(input => { input.name = input.name.replace('__INDEX__', String(index)); });
        const item = fragment.querySelector('[data-recipe-item]');
        item.querySelector('[data-item-key]').value = `item-${crypto.randomUUID()}`;
        item.querySelector('[data-item-source]').value = link.dataset.recipeAdd === 'choices' ? 'family' : 'product';
        form.querySelector(`[data-recipe-items="${link.dataset.recipeAdd}"]`).append(fragment);
        installItem(item);
        updateSummary();
        item.querySelector('[data-item-label]').focus();
    }));
    form.addEventListener('input', updateSummary);
    form.addEventListener('change', updateSummary);
    form.addEventListener('invalid', event => {
        const panel = event.target.closest('[data-recipe-panel]');
        if (panel) show(panel.dataset.recipePanel);
        let parent = event.target.parentElement;
        while (parent && parent !== form) {
            if (parent.tagName === 'DETAILS') parent.open = true;
            parent = parent.parentElement;
        }
    }, true);
    show('details');
    updateSummary();
})();
