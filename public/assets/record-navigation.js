document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('[data-record-form]');
    const navigation = document.querySelector('.record-navigation');
    if (!form || !navigation) {
        return;
    }

    let changed = form.hasAttribute('data-record-dirty');
    form.addEventListener('input', () => { changed = true; });
    form.addEventListener('change', () => { changed = true; });
    const observer = new MutationObserver(() => { changed = true; });
    form.querySelectorAll('select').forEach((select) => observer.observe(select, { childList: true }));
    navigation.addEventListener('click', (event) => {
        if (changed && event.target.closest('a') && !window.confirm('Ungespeicherte Änderungen gehen verloren. Datensatz wechseln?')) {
            event.preventDefault();
        }
    });
});
