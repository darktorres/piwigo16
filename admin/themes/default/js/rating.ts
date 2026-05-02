import { CategoriesCache } from './LocalStorageCache';
import { getPageData } from './page-data';

interface RatingPageData {
    CACHE_KEYS: { categories: string; _hash: string };
    ROOT_URL: string;
    str_create: string;
    nb_elements: number;
}

declare class PwgWS {
    constructor(rootUrl: string);
    callService(
        method: string,
        params: Record<string, unknown>,
        opts: {
            method?: string;
            onFailure?: (num: number, text: string) => void;
            onSuccess?: (result: unknown) => void;
        }
    ): void;
}

const pageData = getPageData<RatingPageData>('pwg-rating-data');

const categoriesCache = new CategoriesCache({
    serverKey: pageData.CACHE_KEYS.categories,
    serverId: pageData.CACHE_KEYS._hash,
    rootUrl: pageData.ROOT_URL,
});

categoriesCache?.selectize(document.querySelector('[data-selectize=categories]'));

/*---- Filter UI (migrated from {footer_script}) ----*/

interface SelectizeSelect extends HTMLSelectElement {
    selectize?: { setValue: (v: string | null) => void };
}

function checkCatFilter(): void {
    const catSelect = document.querySelector<SelectizeSelect>('select[name=cat]');
    const removeBtn = document.getElementById('removeAlbumFilter');
    if (!catSelect || !removeBtn) return;
    removeBtn.style.display = catSelect.value === '' ? 'none' : '';
}

document.getElementById('removeAlbumFilter')?.addEventListener('click', (e) => {
    e.preventDefault();
    const catSelect = document.querySelector<SelectizeSelect>('select[name=cat]');
    catSelect?.selectize?.setValue(null);
});

checkCatFilter();
document
    .querySelector<HTMLSelectElement>('select[name=cat]')
    ?.addEventListener('change', checkCatFilter);

const h1El = document.querySelector('h1');
if (h1El) {
    const badge = document.createElement('span');
    badge.className = 'badge-number';
    badge.textContent = String(pageData.nb_elements);
    h1El.appendChild(badge);
}

/*---- Per-rate delete (migrated from {footer_script} + inline onclick) ----*/

document.addEventListener('click', (e) => {
    const target = (e.target as HTMLElement | null)?.closest<HTMLElement>('.rating-delete');
    if (!target) return;
    e.preventDefault();
    const tr = target.closest<HTMLTableRowElement>('tr');
    if (tr) tr.style.opacity = '0.4';

    const params: Record<string, unknown> = {
        image_id: target.dataset['imageId'] ?? '',
        user_id: target.dataset['userId'] ?? '',
    };
    if (target.dataset['anonymousId']) {
        params['anonymous_id'] = target.dataset['anonymousId'];
    }

    new PwgWS(pageData.ROOT_URL).callService('pwg.rates.delete', params, {
        method: 'POST',
        onFailure: (num, text) => {
            if (tr) tr.style.opacity = '';
            alert(num + ' ' + text);
        },
        onSuccess: (result) => {
            if (result) {
                tr?.remove();
            } else {
                alert(String(result));
            }
        },
    });
});

export {};
