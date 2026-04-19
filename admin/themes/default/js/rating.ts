import { initModule } from './moduleInit.js';
import { CategoriesCache } from './LocalStorageCache.js';
import { PwgWS } from './pwgws.js';

interface RatingConfig {
    categoriesServerKey?: string;
    categoriesServerId?: string;
    rootUrl?: string;
    nbElements?: number;
}

export function init(cfg: RatingConfig): void {
    const { categoriesServerKey = '', categoriesServerId = '', rootUrl = '', nbElements = 0 } = cfg;

    const categoriesCache = new CategoriesCache({
        serverKey: categoriesServerKey,
        serverId: categoriesServerId,
        rootUrl,
    });

    categoriesCache.selectize(document.querySelectorAll('[data-selectize=categories]'));

    const removeAlbumFilter = document.getElementById('removeAlbumFilter');
    if (removeAlbumFilter) {
        removeAlbumFilter.addEventListener('click', function (event) {
            event.preventDefault();
            const catSelect = document.querySelector<HTMLSelectElement & { tomselect?: { setValue(v: null): void } }>('select[name=cat]');
            if (catSelect?.tomselect) catSelect.tomselect.setValue(null);
        });
    }

    function checkCatFilter(): void {
        const catSelect = document.querySelector<HTMLSelectElement>('select[name=cat]');
        const filterBtn = document.getElementById('removeAlbumFilter');
        if (!filterBtn) return;
        filterBtn.style.display = (catSelect && catSelect.value !== '') ? '' : 'none';
    }

    checkCatFilter();
    const catSelectEl = document.querySelector<HTMLSelectElement>('select[name=cat]');
    if (catSelectEl) catSelectEl.addEventListener('change', checkCatFilter);

    const h1 = document.querySelector('h1');
    if (h1) h1.insertAdjacentHTML('beforeend', "<span class='badge-number'>" + nbElements + '</span>');

    function del(node: HTMLElement, id: string, uid: string, aid: string | undefined): false {
        const trEl = node.closest('tr');
        const data: Record<string, string> = { image_id: id, user_id: uid };
        if (aid) data['anonymous_id'] = aid;

        const anim = trEl ? trEl.animate([{ opacity: 1 }, { opacity: 0.4 }], { duration: 1000, fill: 'forwards' }) : null;
        (new PwgWS(rootUrl)).callService('pwg.rates.delete', data, {
            method: 'POST',
            onFailure: function (num, text) {
                if (anim) anim.cancel();
                if (trEl) (trEl as HTMLElement).style.opacity = '1';
                alert(num + ' ' + text);
            },
            onSuccess: function (result: unknown) {
                if (result) {
                    if (trEl) trEl.remove();
                } else {
                    alert(String(result));
                }
            },
        });
        return false;
    }

    document.addEventListener('click', function (e) {
        const deleteBtn = (e.target as HTMLElement).closest<HTMLElement>('[data-action="pwgDeleteRate"]');
        if (deleteBtn) {
            e.preventDefault();
            const id = deleteBtn.dataset['imageId'] ?? '';
            const uid = deleteBtn.dataset['userId'] ?? '';
            const aid = deleteBtn.dataset['anonymousId'];
            del(deleteBtn, id, uid, aid);
        }
    });
}

initModule(init as (cfg: Record<string, unknown>) => void);
