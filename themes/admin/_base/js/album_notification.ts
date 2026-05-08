function checkWhoOptions(): void {
    const checked = document.querySelector<HTMLInputElement>('input[name=who]:checked');
    const option = checked?.value ?? '';
    document.querySelectorAll<HTMLElement>('.who_option').forEach((el) => {
        el.style.display = 'none';
    });
    if (option) {
        document.querySelectorAll<HTMLElement>('.who_' + option).forEach((el) => {
            el.style.display = '';
        });
    }
}

document.querySelectorAll<HTMLInputElement>('input[name=who]').forEach((el) => {
    el.addEventListener('change', checkWhoOptions);
});
checkWhoOptions();

document.querySelector<HTMLFormElement>('form#categoryNotify')?.addEventListener('submit', (e) => {
    const checked = document.querySelector<HTMLInputElement>('input[name=who]:checked');
    const who_option = checked?.value ?? '';
    const selEl = document.querySelector<HTMLSelectElement>('.who_' + who_option + ' select');
    const who_selected = selEl !== null && selEl.querySelectorAll('option:checked').length > 0;
    const errEl = document.querySelector<HTMLElement>('.actionButtons .errors');
    if (!who_selected) {
        if (errEl) errEl.style.display = '';
        e.preventDefault();
    } else {
        if (errEl) errEl.style.display = 'none';
    }
});
