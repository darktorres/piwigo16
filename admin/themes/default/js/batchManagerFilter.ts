import { getPageData } from './page-data';

interface SliderConfig {
    values: unknown[];
    selected: { min: unknown; max: unknown };
    text: string;
}

interface BatchFilterPageData {
    sliders: Record<string, SliderConfig>;
    selected_filter_cat_ids: (string | number)[];
    str_select_album: string;
    str_select_tag: string;
}

const {
    sliders,
    selected_filter_cat_ids,
    str_select_album,
    str_select_tag,
} = getPageData<BatchFilterPageData>('pwg-filter-page-data');

let errorFilters = '';

function filter_enable(filter: string): void {
    const el = document.getElementById(filter);
    if (el) el.style.display = '';
    const cb = document.querySelector<HTMLInputElement>(`input[type=checkbox][name=${filter}_use]`);
    if (cb) cb.checked = true;
    document.querySelector<HTMLElement>(`#addFilter a[data-value=${filter}]`)?.classList.add('disabled');
    document.querySelectorAll<HTMLElement>('.noFilter').forEach(e => { e.style.display = 'none'; });
    document.querySelector<HTMLElement>('.addFilter-button')?.classList.remove('highlight');
}

function filter_disable(filter: string): void {
    const el = document.getElementById(filter);
    if (el) el.style.display = 'none';
    const cb = document.querySelector<HTMLInputElement>(`input[name=${filter}_use]`);
    if (cb) cb.checked = false;
    document.querySelector<HTMLElement>(`#addFilter a[data-value=${filter}]`)?.classList.remove('disabled');
    const visible = Array.from(document.querySelectorAll('#filterList li'))
        .filter(li => (li as HTMLElement).style.display !== 'none' && getComputedStyle(li).display !== 'none').length;
    if (visible === 0) {
        document.querySelectorAll<HTMLElement>('.noFilter').forEach(e => { e.style.display = ''; });
        document.querySelector<HTMLElement>('.addFilter-button')?.classList.add('highlight');
    }
}

function select_album_filter({ album, newSelectedAlbum, getSelectedAlbum }: { album: any; newSelectedAlbum: () => void; getSelectedAlbum: () => (string | number)[] }): void {
    const nameEl = document.getElementById('selectedAlbumNameFilter');
    if (nameEl) nameEl.innerHTML = album.name;
    newSelectedAlbum();
    hide_filters_error(str_select_album);
    const valInput = document.getElementById('filterCategoryValue') as HTMLInputElement | null;
    if (valInput) valInput.value = String(+getSelectedAlbum()[0]);
    const selectEl = document.getElementById('selectAlbumFilter');
    if (selectEl) selectEl.style.display = 'none';
    const selectedArea = document.getElementById('selectedAlbumFilterArea');
    if (selectedArea) selectedArea.style.display = '';
}

function show_filters_error(message: string): void {
    errorFilters = message;
    const errorEl = document.getElementById('errorFilter');
    if (errorEl) { errorEl.innerHTML = `<p>${message}</p>`; errorEl.style.display = ''; }
}

function hide_filters_error(message: string): void {
    if (message === errorFilters) {
        const errorEl = document.getElementById('errorFilter');
        if (errorEl) errorEl.style.display = 'none';
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const doubleSlider = (window as any).pwgDoubleSlider as (el: HTMLElement, options: any) => void;

    const ab_filter = new (window as any).AlbumSelector({
        selectedCategoriesIds: selected_filter_cat_ids,
        selectAlbum: select_album_filter,
        adminMode: true,
    });

    document.getElementById('selectAlbumFilter')?.addEventListener('click', () => ab_filter.open());
    document.getElementById('selectedAlbumEditFilter')?.addEventListener('click', () => ab_filter.open());

    document.querySelectorAll<HTMLElement>('.removeFilter').forEach(el => {
        el.classList.add('icon-cancel-circled');
        el.addEventListener('click', (e) => {
            e.preventDefault();
            const filter = el.closest('li')?.id ?? '';
            filter_disable(filter);
        });
    });

    document.querySelectorAll<HTMLElement>('#addFilter a').forEach(a => {
        a.addEventListener('click', () => filter_enable(a.dataset['value'] ?? ''));
    });

    document.getElementById('removeFilters')?.addEventListener('click', (e) => {
        e.preventDefault();
        document.querySelectorAll<HTMLElement>('#filterList li').forEach(li => filter_disable(li.id));
    });

    document.querySelectorAll<HTMLElement>('[data-slider=widths]').forEach(el => doubleSlider(el, sliders.widths));
    document.querySelectorAll<HTMLElement>('[data-slider=heights]').forEach(el => doubleSlider(el, sliders.heights));
    document.querySelectorAll<HTMLElement>('[data-slider=ratios]').forEach(el => doubleSlider(el, sliders.ratios));
    document.querySelectorAll<HTMLElement>('[data-slider=filesizes]').forEach(el => doubleSlider(el, sliders.filesizes));

    document.addEventListener('mouseup', (e: MouseEvent) => {
        e.stopPropagation();
        if (!(e.target as HTMLElement).classList.contains('addFilter-button')) {
            document.querySelectorAll<HTMLElement>('.addFilter-dropdown').forEach(el => { el.style.display = 'none'; });
        }
    });

    document.querySelectorAll<HTMLSelectElement>('.filterBlock select[data-selectize="tags"]').forEach(sel => {
        sel.addEventListener('change', () => { if (sel.value) hide_filters_error(str_select_tag); });
    });

    let applyAbort: AbortController | null = null;
    document.getElementById('applyFilter')?.addEventListener('click', (e) => {
        applyAbort?.abort();
        applyAbort = new AbortController();
        const { signal } = applyAbort;

        const filterTags = document.getElementById('filter_tags');
        if (filterTags && getComputedStyle(filterTags).display !== 'none') {
            const tags = document.querySelector<HTMLSelectElement>('.filterBlock select[data-selectize="tags"]');
            if (!tags?.value) {
                e.preventDefault();
                show_filters_error(str_select_tag);
                document.querySelectorAll<HTMLElement>('#filter_tags .removeFilter').forEach(el => {
                    el.addEventListener('click', () => hide_filters_error(str_select_tag), { signal });
                });
            }
        }

        const filterCat = document.getElementById('filter_category');
        if (filterCat && getComputedStyle(filterCat).display !== 'none') {
            if (ab_filter.get_selected_albums().length === 0) {
                e.preventDefault();
                show_filters_error(str_select_album);
                document.querySelectorAll<HTMLElement>('#filter_category .removeFilter').forEach(el => {
                    el.addEventListener('click', () => hide_filters_error(str_select_album), { signal });
                });
            }
        }
    });

    document.querySelector('.help-popin-search')?.addEventListener('click', () => {
        const modal = document.getElementById('modalQuickSearch');
        if (modal) modal.style.display = '';
    });
    document.getElementById('closeModalQuickSearch')?.addEventListener('click', () => {
        const modal = document.getElementById('modalQuickSearch');
        if (modal) modal.style.display = 'none';
    });
});

export {};
