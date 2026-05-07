import { getPageData } from './page-data';

interface ConfigurationSearchPageData {
    filters_names: string[];
}

const { filters_names } = getPageData<ConfigurationSearchPageData>();

const _hide = (id: string) => {
    const el = document.getElementById(id);
    if (el) el.style.display = 'none';
};
const _show = (id: string) => {
    const el = document.getElementById(id);
    if (el) el.style.display = '';
};
const _el = (id: string) => document.getElementById(id);

for (const filter_name of filters_names) {
    const filterCb = _el(filter_name + 'Filters') as HTMLInputElement | null;
    const selectEl = _el('f' + filter_name + 'Select') as HTMLSelectElement | null;
    const defaultCb = _el('default_' + filter_name) as HTMLInputElement | null;

    if (filterCb && !filterCb.checked) {
        _hide('f' + filter_name + 'Select');
        _hide(filter_name + 'Arrow');
        if (defaultCb?.parentElement) defaultCb.parentElement.style.display = 'none';
    }

    if (selectEl && selectEl.value !== 'admins-only') {
        _hide(filter_name + 'AdminIcon');
    }

    if (defaultCb?.checked && defaultCb.parentElement) {
        defaultCb.parentElement.classList.add('selected-filter-container');
    }

    filterCb?.addEventListener('click', () => {
        const cb = _el(filter_name + 'Filters') as HTMLInputElement | null;
        const sel = _el('f' + filter_name + 'Select') as HTMLSelectElement | null;
        const def = _el('default_' + filter_name) as HTMLInputElement | null;
        if (cb?.checked) {
            _show('f' + filter_name + 'Select');
            _show(filter_name + 'Arrow');
            if (def?.parentElement) def.parentElement.style.display = '';
            if (sel?.value === 'admins-only') _show(filter_name + 'AdminIcon');
        } else {
            _hide('f' + filter_name + 'Select');
            _hide(filter_name + 'Arrow');
            _hide(filter_name + 'AdminIcon');
            if (def?.parentElement) def.parentElement.style.display = 'none';
        }
    });

    selectEl?.addEventListener('click', () => {
        const sel = _el('f' + filter_name + 'Select') as HTMLSelectElement | null;
        if (sel?.value === 'admins-only') _show(filter_name + 'AdminIcon');
        else _hide(filter_name + 'AdminIcon');
    });

    defaultCb?.addEventListener('click', () => {
        const cb = _el('default_' + filter_name) as HTMLInputElement | null;
        const parent = cb?.parentElement;
        if (!parent) return;
        if (cb.checked) parent.classList.add('selected-filter-container');
        else parent.classList.remove('selected-filter-container');
    });
}
