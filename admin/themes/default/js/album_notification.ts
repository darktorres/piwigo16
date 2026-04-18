import { initModule } from './moduleInit.js';
import TomSelect from 'tom-select';

export function init(_cfg: Record<string, unknown>): void {
    function checkWhoOptions(): void {
        const checkedEl = document.querySelector<HTMLInputElement>('input[name=who]:checked');
        const option = checkedEl ? checkedEl.value : '';
        document.querySelectorAll<HTMLElement>('.who_option').forEach(el => { el.style.display = 'none'; });
        const target = document.querySelector<HTMLElement>('.who_' + option);
        if (target) target.style.display = '';
    }

    document.querySelectorAll<HTMLInputElement>('input[name=who]').forEach(el => {
        el.addEventListener('change', () => checkWhoOptions());
    });
    checkWhoOptions();

    document.querySelectorAll<HTMLSelectElement>('.who_option select').forEach(el => {
        new TomSelect(el, { plugins: ['remove_button'] });
    });

    const categoryNotify = document.getElementById('categoryNotify');
    if (categoryNotify) {
        categoryNotify.addEventListener('submit', function (e) {
            const checkedEl = document.querySelector<HTMLInputElement>('input[name=who]:checked');
            const who_option = checkedEl ? checkedEl.value : '';
            const selectEl = document.querySelector<HTMLSelectElement>('.who_' + who_option + ' select');
            const who_selected = !!(selectEl?.querySelector('option:checked'));
            const errorsEl = document.querySelector<HTMLElement>('.actionButtons .errors');
            if (!who_selected) {
                if (errorsEl) errorsEl.style.display = '';
                e.preventDefault();
            } else {
                if (errorsEl) errorsEl.style.display = 'none';
                console.log('form can be submitted');
            }
        });
    }
}

initModule(init);
