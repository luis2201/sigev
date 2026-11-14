const updateDependent = (select) => {
    const parent = document.getElementById(select.dataset.parent);
    const area = document.getElementById(select.dataset.areaParent);
    for (const option of select.options) {
        if (!option.value) continue;
        const compatible = option.dataset.parentValue === parent?.value && (!option.dataset.areaValue || option.dataset.areaValue === area?.value);
        option.hidden = !compatible;
        option.disabled = !compatible;
        if (!compatible && option.selected) select.value = '';
    }
};
for (const select of document.querySelectorAll('select[data-parent]')) {
    document.getElementById(select.dataset.parent)?.addEventListener('change', () => updateDependent(select));
    document.getElementById(select.dataset.areaParent)?.addEventListener('change', () => updateDependent(select));
    updateDependent(select);
}
const form = document.querySelector('[data-placement-form]');
if (form) {
    const mode = form.querySelector('#assignment_mode');
    const updateMode = () => {
        for (const section of form.querySelectorAll('[data-mode]')) {
            section.hidden = section.dataset.mode !== mode.value;
            for (const input of section.querySelectorAll('select')) input.disabled = section.hidden;
        }
    };
    if (mode) {
        mode.addEventListener('change', updateMode);
        updateMode();
    }
    form.addEventListener('invalid', (event) => { event.target.closest('details')?.setAttribute('open', ''); }, true);
    const rows = form.querySelector('[data-intervals]');
    let nextIndex = Math.max(-1, ...[...rows.querySelectorAll('[name]')].map((input) => Number(input.name.match(/^intervals\[(\d+)\]/)?.[1] ?? -1))) + 1;
    const prototype = rows.firstElementChild.cloneNode(true);
    const updateIntervalButtons = () => {
        for (const button of rows.querySelectorAll('[data-remove-interval]')) button.disabled = rows.children.length <= 1;
        form.querySelector('[data-add-interval]').disabled = rows.children.length >= 50;
    };
    updateIntervalButtons();
    form.querySelector('[data-add-interval]').addEventListener('click', () => {
        if (rows.children.length >= 50) return;
        const row = prototype.cloneNode(true);
        for (const input of row.querySelectorAll('[name]')) {
            input.name = input.name.replace(/intervals\[\d+\]/, `intervals[${nextIndex}]`);
            if (input.tagName === 'INPUT') input.value = input.name.endsWith('[valid_from]') ? form.querySelector('#practice_starts_on').value : '';
        }
        nextIndex++;
        rows.append(row);
        updateIntervalButtons();
    });
    rows.addEventListener('click', (event) => {
        if (event.target.closest('[data-remove-interval]') && rows.children.length > 1) {
            event.target.closest('[data-interval-row]').remove();
            updateIntervalButtons();
        }
    });
    form.querySelector('#practice_starts_on').addEventListener('change', (event) => {
        for (const input of rows.querySelectorAll('input[name$="[valid_from]"]')) {
            if (!input.value) input.value = event.target.value;
        }
    });
}

const members = document.querySelector('[data-edit-members]');
if (members) {
    const rows = [...members.querySelectorAll('[data-edit-member-row]')];
    const boxes = rows.map((row) => row.querySelector('input[type="checkbox"]'));
    const updateCount = () => {
        const count = boxes.filter((box) => box.checked).length;
        members.querySelector('[data-edit-member-count]').textContent = `${count} integrantes seleccionados`;
        const save = document.querySelector('[data-save-placement]');
        save.textContent = `Guardar cambios para ${count} integrantes`;
        save.disabled = count === 0 || count > 500;
    };
    for (const box of boxes) box.addEventListener('change', updateCount);
    members.querySelector('[data-edit-select-visible]').addEventListener('click', () => {
        let count = boxes.filter((box) => box.checked).length;
        for (const row of rows) {
            const box = row.querySelector('input');
            if (!row.hidden && !box.checked && count < 500) { box.checked = true; count++; }
        }
        updateCount();
    });
    members.querySelector('[data-edit-clear]').addEventListener('click', () => { for (const box of boxes) box.checked = false; updateCount(); });
    const normalize = (value) => value.normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase();
    members.querySelector('[data-edit-member-search]').addEventListener('input', (event) => {
        const term = normalize(event.target.value.trim());
        for (const row of rows) row.hidden = !normalize(row.textContent).includes(term);
    });
    updateCount();
}
