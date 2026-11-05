document.querySelectorAll('[data-catalog-add]').forEach((control) => {
    const button = control.querySelector('[data-catalog-submit]');

    if (!(button instanceof HTMLButtonElement)) {
        return;
    }

    button.addEventListener('click', async () => {
        const status = control.querySelector('[data-catalog-status]');
        const name = control.querySelector('[name="name"]');
        const location = control.querySelector('[name="katalog_ort"], [name="ort"]');
        const target = document.getElementById(control.dataset.selectId);

        if (!(status instanceof HTMLElement)
            || !(name instanceof HTMLInputElement)
            || !(target instanceof HTMLElement)) {
            return;
        }

        if (name.value.trim() === '') {
            status.textContent = 'Bitte gib einen Namen ein.';
            name.focus();
            return;
        }

        button.disabled = true;
        status.textContent = 'Wird gespeichert …';

        try {
            const response = await fetch('/kataloge/api.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': control.dataset.csrfToken,
                },
                body: JSON.stringify({
                    type: control.dataset.catalogType,
                    name: name.value,
                    ort: location instanceof HTMLInputElement ? location.value : '',
                }),
            });
            const result = await response.json();

            if (!response.ok) {
                status.textContent = result.error || 'Der Eintrag konnte nicht gespeichert werden.';
                return;
            }

            if (target instanceof HTMLSelectElement) {
                target.add(new Option(result.label, String(result.id), false, true));
                const copyCheckbox = document.getElementById(control.dataset.copyCheckbox);
                if (copyCheckbox instanceof HTMLInputElement && copyCheckbox.checked) {
                    copyCheckbox.checked = false;
                    copyCheckbox.dispatchEvent(new Event('change'));
                }
                target.focus();
            } else {
                const item = document.createElement('p');
                const input = document.createElement('input');
                const label = document.createElement('label');
                const inputId = `kanal-${result.id}`;

                input.type = 'checkbox';
                input.name = 'marketing_kanal_ids[]';
                input.value = String(result.id);
                input.id = inputId;
                input.checked = true;
                label.htmlFor = inputId;
                label.textContent = result.label;
                item.append(input, label);
                target.append(item);
                input.focus();
            }

            name.value = '';
            if (location instanceof HTMLInputElement) {
                location.value = '';
            }
            const emptyMessage = control.querySelector('[data-catalog-empty]');
            if (emptyMessage instanceof HTMLElement) {
                emptyMessage.hidden = true;
            }
            status.textContent = 'Eintrag hinzugefügt und ausgewählt.';
        } catch {
            status.textContent = 'Die Anfrage ist fehlgeschlagen. Bitte prüfe die Verbindung und versuche es erneut.';
        } finally {
            button.disabled = false;
        }
    });
});

document.querySelectorAll('[data-copy-from-student]').forEach((checkbox) => {
    const select = document.getElementById(checkbox.dataset.copySelectId);

    if (!(checkbox instanceof HTMLInputElement) || !(select instanceof HTMLSelectElement)) {
        return;
    }

    const updateDisabledState = () => {
        select.disabled = checkbox.checked;
    };

    checkbox.addEventListener('change', updateDisabledState);
    select.addEventListener('change', () => {
        if (select.value !== '') {
            checkbox.checked = false;
            updateDisabledState();
        }
    });
    updateDisabledState();
});
