for (const picker of document.querySelectorAll('[data-membership-picker]')) {
    const key = picker.dataset.selectionKey;
    const checkboxes = [...picker.querySelectorAll('input[name="participation_ids[]"]')];
    const selected = new Map();
    const reason = picker.querySelector('[data-membership-reason]');
    const help = picker.querySelector('[data-selection-help]');
    const defaultHelp = help.textContent;
    let canStore = true;
    try {
        if (picker.dataset.reset === 'true') sessionStorage.removeItem(key);
        const stored = JSON.parse(sessionStorage.getItem(key) || 'null');
        if (stored && stored.expires > Date.now() && Array.isArray(stored.students)) {
            for (const student of stored.students.slice(0, 500)) {
                if (/^\d+$/.test(student.id) && typeof student.name === 'string') selected.set(student.id, student.name);
            }
            if (!reason.value && typeof stored.reason === 'string') reason.value = stored.reason;
        }
    } catch {
        canStore = false;
    }
    for (const checkbox of checkboxes) {
        if (checkbox.checked) selected.set(checkbox.value, checkbox.dataset.memberName);
    }
    const save = () => {
        if (!canStore) return;
        try {
            sessionStorage.setItem(key, JSON.stringify({
                students: [...selected].map(([id, name]) => ({ id, name })),
                reason: reason.value,
                expires: Date.now() + 2 * 60 * 60 * 1000,
            }));
        } catch {
            canStore = false;
            help.textContent = 'El navegador no permite conservar la selección entre páginas. Confirme los estudiantes de esta página antes de continuar.';
        }
    };
    const render = () => {
        const visibleIds = new Set(checkboxes.map((checkbox) => checkbox.value));
        for (const checkbox of checkboxes) checkbox.checked = selected.has(checkbox.value);
        picker.querySelector('[data-selection-count]').textContent = `${selected.size} seleccionados`;
        picker.querySelector('[data-membership-submit]').disabled = selected.size === 0;
        const inputs = picker.querySelector('[data-selected-inputs]');
        const list = picker.querySelector('[data-selected-list]');
        inputs.replaceChildren();
        list.replaceChildren();
        picker.querySelector('[data-selected-details]').hidden = selected.size === 0;
        for (const [id, name] of selected) {
            if (!visibleIds.has(id)) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = 'participation_ids[]';
                input.value = id;
                inputs.append(input);
            }
            const item = document.createElement('li');
            item.className = 'flex items-center justify-between gap-3';
            const label = document.createElement('span');
            label.textContent = name;
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'text-sm text-brand-800 underline';
            remove.textContent = 'Desmarcar';
            remove.setAttribute('aria-label', `Desmarcar a ${name}`);
            remove.addEventListener('click', () => {
                selected.delete(id);
                render();
                save();
            });
            item.append(label, remove);
            list.append(item);
        }
    };
    for (const checkbox of checkboxes) {
        checkbox.addEventListener('change', () => {
            if (checkbox.checked && selected.size >= 500 && !selected.has(checkbox.value)) {
                checkbox.checked = false;
                help.textContent = 'Puede seleccionar hasta 500 estudiantes por operación.';
                return;
            }
            if (checkbox.checked) selected.set(checkbox.value, checkbox.dataset.memberName);
            else selected.delete(checkbox.value);
            if (canStore) help.textContent = defaultHelp;
            render();
            save();
        });
    }
    picker.querySelector('[data-select-page]').addEventListener('click', () => {
        for (const checkbox of checkboxes) {
            if (selected.size < 500 || selected.has(checkbox.value)) selected.set(checkbox.value, checkbox.dataset.memberName);
        }
        render();
        save();
    });
    picker.querySelector('[data-clear-selection]').addEventListener('click', () => {
        selected.clear();
        render();
        save();
    });
    reason.addEventListener('input', save);
    picker.addEventListener('submit', (event) => {
        if (selected.size === 0) {
            event.preventDefault();
            help.textContent = 'Seleccione al menos un estudiante.';
        }
        render();
        save();
    });
    window.addEventListener('pageshow', (event) => {
        if (!event.persisted || !canStore) return;
        try {
            const stored = JSON.parse(sessionStorage.getItem(key) || 'null');
            selected.clear();
            if (stored && stored.expires > Date.now() && Array.isArray(stored.students)) {
                for (const student of stored.students.slice(0, 500)) {
                    if (/^\d+$/.test(student.id) && typeof student.name === 'string') selected.set(student.id, student.name);
                }
                if (typeof stored.reason === 'string') reason.value = stored.reason;
            }
            render();
        } catch {
            canStore = false;
        }
    });
    picker.querySelector('[data-selection-tools]').hidden = false;
    render();
    if (canStore) help.textContent = 'La selección se conserva al buscar o cambiar de página en esta pestaña. Máximo 500 por operación.';
    else help.textContent = 'El navegador no permite conservar la selección entre páginas. Confirme los estudiantes de esta página antes de continuar.';
}
