import GLightbox from 'glightbox';
import tippy from 'tippy.js';
import 'tippy.js/dist/tippy.css';
import { AlbumSelector } from './album_selector';
import { TagsCache, CategoriesCache } from './LocalStorageCache';
import { getPageData } from './page-data';

interface BatchManagerGlobalPageData {
    CACHE_KEYS: { tags: string; categories: string; _hash: string };
    ROOT_URL: string;
    associated_categories: Record<string, any>;
    str_create: string;
}

const pageData = getPageData<BatchManagerGlobalPageData>('pwg-batch-manager-global-data');

// Initialize caches
const tagsCache = new TagsCache({
    serverKey: pageData.CACHE_KEYS.tags,
    serverId: pageData.CACHE_KEYS._hash,
    rootUrl: pageData.ROOT_URL
});

const categoriesCache = new CategoriesCache({
    serverKey: pageData.CACHE_KEYS.categories,
    serverId: pageData.CACHE_KEYS._hash,
    rootUrl: pageData.ROOT_URL
});

tagsCache?.selectize(document.querySelector('[data-selectize=tags]'), { lang: {
    'Add': pageData.str_create
}});

categoriesCache?.selectize(document.querySelector('[data-selectize=categories]'), {
    filter: function(categories: any[], options: any) {
        if (this.name == 'dissociate') {
            const filtered = categories.filter(function(cat: any) {
                return !!pageData.associated_categories[cat.id];
            });

            if (filtered.length > 0) {
                options.default = filtered[0].id;
            }

            return filtered;
        }
        else {
            return categories;
        }
    }
});

declare var all_elements: any;
declare var lang: any;
declare var str_add_alb_associate: any;
declare var str_select_alb_associate: any;

let elements: string[] = [];
let i = 0;
let input: HTMLInputElement | null = null;
let percent = 0;
let progressBar_max = 0;

const pwg_token = (document.querySelector<HTMLInputElement>('input[name=pwg_token]')?.value) ?? '';

const qs = <T extends HTMLElement = HTMLElement>(sel: string) => document.querySelector<T>(sel);
const qsa = <T extends HTMLElement = HTMLElement>(sel: string) => Array.from(document.querySelectorAll<T>(sel));
const show = (el: HTMLElement | null | undefined) => { if (el) el.style.display = ''; };
const hide = (el: HTMLElement | null | undefined) => { if (el) el.style.display = 'none'; };

class AjaxQueue {
    private pending: Array<() => Promise<void>> = [];
    private running = 0;
    private max: number;
    constructor(max: number) { this.max = max; }
    add(fn: () => Promise<void>): void { this.pending.push(fn); this.process(); }
    private process(): void {
        while (this.running < this.max && this.pending.length > 0) {
            const fn = this.pending.shift()!;
            this.running++;
            fn().finally(() => { this.running--; this.process(); });
        }
    }
}

// Derivatives queue (global, used by getDerivativeUrls)
const derivativesQueue = new AjaxQueue(3);

/*---- Thumbnails shift-click ----*/
function enableShiftClick(container: HTMLElement) {
    const inputs: HTMLInputElement[] = [];
    let lastClicked = 0, lastClickedStatus = true;
    container.querySelectorAll<HTMLInputElement>('input[type=checkbox]').forEach((inp, pos) => {
        inputs.push(inp);
        inp.addEventListener('click', (e: MouseEvent) => {
            if (e.shiftKey) {
                let first = lastClicked, last = pos;
                if (first > last) { [first, last] = [last, first]; }
                for (let idx = first; idx <= last; idx++) {
                    inputs[idx].checked = lastClickedStatus;
                    inputs[idx].dispatchEvent(new Event('change'));
                    inputs[idx].closest('li')?.classList.toggle('thumbSelected', lastClickedStatus);
                }
            } else {
                lastClicked = pos;
                lastClickedStatus = inp.checked;
            }
        });
    });
}

/*---- Album Selector ----*/
function select_album_action({ album, addSelectedAlbum }: { album: any; addSelectedAlbum: any }) {
    const assocP = qs('#associate_as p');
    if (assocP) assocP.innerHTML = str_add_alb_associate;
    qs('.selected-associate-action')?.insertAdjacentHTML('beforeend',
        `<div class="selected-associate-item">
            <span>${album.name}</span><span id="${album.id}" class="remove-associate icon-cancel-circled"></span>
            <input type="hidden" id="associate_input_${album.id}" name="associate[]" value="${album.id}">
        </div>`
    );
    addSelectedAlbum();
}

function remove_album_action({ id_album, getSelectedAlbum }: { id_album: any; getSelectedAlbum: any }) {
    document.getElementById(String(id_album))?.parentElement?.remove();
    if (!getSelectedAlbum().length) {
        const assocP = qs('#associate_as p');
        if (assocP) assocP.innerHTML = str_select_alb_associate;
    }
}

/*---- DOMContentLoaded ----*/
document.addEventListener('DOMContentLoaded', () => {
    enableShiftClick(qs('ul.thumbnails') ?? document.body);

    const ab_action = new AlbumSelector({
        adminMode: true,
        selectAlbum: select_album_action,
        removeSelectedAlbum: remove_album_action,
    });

    qs('#associate_as')?.addEventListener('click', () => ab_action.open());
    qs('.selected-associate-action')?.addEventListener('click', (e: Event) => {
        const target = e.target as HTMLElement;
        if (target.classList.contains('remove-associate')) ab_action.remove_selected_album(target.id);
    });
});

/*---- Preview lightbox ----*/
GLightbox({ selector: 'a.preview-box' });
tippy('.thumbnails img', { delay: [0, 0], duration: [200, 200] });

/*---- Datepicker + AddAlbum ----*/
document.querySelectorAll<HTMLElement>('[data-datepicker]').forEach(el => {
    (window as any).pwgDatepicker(el, { showTimepicker: true, cancelButton: lang.Cancel });
});
document.querySelectorAll<HTMLElement>('[data-add-album]').forEach(el => {
    (window as any).pwgAddAlbum(el);
});

/*---- Toggle visibility ----*/
qs<HTMLInputElement>('input[name=remove_author]')?.addEventListener('click', function(this: HTMLInputElement) {
    this.checked ? hide(qs('input[name=author]')) : show(qs('input[name=author]'));
});
qs<HTMLInputElement>('input[name=remove_title]')?.addEventListener('click', function(this: HTMLInputElement) {
    this.checked ? hide(qs('input[name=title]')) : show(qs('input[name=title]'));
});
qs<HTMLInputElement>('input[name=remove_date_creation]')?.addEventListener('click', function(this: HTMLInputElement) {
    this.checked ? hide(qs('#set_date_creation')) : show(qs('#set_date_creation'));
});

/*---- Derivatives ----*/
var derivatives: { elements: any; done: number; total: number; finished: () => boolean } = {
    elements: null, done: 0, total: 0,
    finished() { return this.done === this.total && this.elements && this.elements.length === 0; }
};

function progress_start() {
    show(qs('#uploadingActions'));
    const pb = qs<HTMLElement>('#uploadingActions .progress-bar'); if (pb) pb.style.width = '0%';
}
function progress_end() { hide(qs('#uploadingActions')); }

function progress(success: any) {
    percent = Math.floor(derivatives.done / derivatives.total * 100);
    const pb = qs<HTMLElement>('#uploadingActions .progressbar'); if (pb) pb.style.width = percent + '%';
    if (success !== undefined) {
        const type = success ? 'regenerateSuccess' : 'regenerateError';
        const inp = qs<HTMLInputElement>(`[name="${type}"]`);
        if (inp) inp.value = String(parseInt(inp.value ?? '0') + 1);
    }
    if (derivatives.finished()) { progress_end(); qs<HTMLElement>('#applyAction')?.click(); }
}

function updateDerivativeBadge() {
    const badge = qs('#regenerationStatus .badge-number');
    if (badge) badge.innerHTML = derivatives.done + '/' + derivatives.total;
}

function getDerivativeUrls() {
    const ids = derivatives.elements.splice(0, 500);
    const types: string[] = [];
    document.querySelectorAll<HTMLInputElement>('#action_generate_derivatives input').forEach(t => { if (t.checked) types.push(t.value); });
    hide(qs('#applyActionBlock')); hide(qs('.permitActionListButton')); hide(qs('#confirmDel'));
    show(qs('#regenerationMsg'));
    const regen = qs('#regenerationText'); if (regen) regen.innerHTML = lang.generateMsg;
    progress_start();

    const body = new URLSearchParams({ max_urls: '100000', types: types.join(',') });
    ids.forEach((id: any) => body.append('ids[]', id));
    fetch('ws.php?format=json&method=pwg.getMissingDerivatives', { method: 'POST', body }).then(r => r.json()).then((data: any) => {
        if (!data.stat || data.stat !== 'ok') return;
        derivatives.total += data.result.urls.length;
        updateDerivativeBadge();
        progress(undefined);
        data.result.urls.forEach((url: string) => {
            derivativesQueue.add(async () => {
                try { await fetch(url + '&ajaxload=true'); derivatives.done++; updateDerivativeBadge(); progress(true); }
                catch { derivatives.done++; updateDerivativeBadge(); progress(false); }
            });
        });
        if (derivatives.elements.length) setTimeout(getDerivativeUrls, 25 * (derivatives.total - derivatives.done));
    });
}

function selectGenerateDerivAll() {
    document.querySelectorAll<HTMLInputElement>('#action_generate_derivatives input[type=checkbox]').forEach(el => { el.checked = true; el.dispatchEvent(new Event('change')); });
}
function selectGenerateDerivNone() {
    document.querySelectorAll<HTMLInputElement>('#action_generate_derivatives input[type=checkbox]').forEach(el => { el.checked = false; el.dispatchEvent(new Event('change')); });
}
function selectDelDerivAll() {
    document.querySelectorAll<HTMLInputElement>('#action_delete_derivatives input[name="del_derivatives_type[]"]').forEach(el => { el.checked = true; el.dispatchEvent(new Event('change')); });
}
function selectDelDerivNone() {
    document.querySelectorAll<HTMLInputElement>('#action_delete_derivatives input[name="del_derivatives_type[]"]').forEach(el => { el.checked = false; el.dispatchEvent(new Event('change')); });
}

window.addEventListener('keypress', (e: KeyboardEvent) => {
    const selected = (qs<HTMLSelectElement>("select[name='selectAction']"))?.value;
    const haveTextarea = selected ? document.querySelectorAll(`#action_${selected} textarea`).length : 0;
    const addLinkedAlbum = document.getElementById('addLinkedAlbum');
    const isVisible = addLinkedAlbum && getComputedStyle(addLinkedAlbum).display !== 'none';
    if (e.key === 'Enter' && selected && selected !== '-1' && !haveTextarea && !isVisible) {
        e.preventDefault();
        qs<HTMLElement>('#applyAction')?.click();
    }
});

/*---- Apply action ----*/
qs<HTMLElement>('#applyAction')?.addEventListener('click', (e: Event) => {
    if (typeof elements !== 'undefined') return;

    const selectAction = (qs<HTMLSelectElement>('[name="selectAction"]'))?.value;

    if (selectAction === 'metadata') {
        e.preventDefault(); e.stopPropagation();
        qsa('.bulkAction').forEach(hide);
        const regenText = qs('#regenerationText'); if (regenText) regenText.innerHTML = lang.syncProgressMessage;
        elements = [];
        if ((qs<HTMLInputElement>('input[name=setSelected]'))?.checked) { elements = all_elements; }
        else { document.querySelectorAll<HTMLInputElement>('input[name="selection[]"]:checked').forEach(el => elements.push(el.value)); }

        const queuedMgr = new AjaxQueue(1);
        progressBar_max = elements.length;
        let todo = 0;
        const syncBlockSize = Math.min(Math.floor(elements.length / 2) || 1, 1000);
        let image_ids: any[] = [];
        hide(qs('#applyActionBlock')); hide(qs('.permitActionListButton')); hide(qs('#confirmDel'));
        show(qs('#regenerationMsg'));
        progress_bar_start();

        for (let idx = 0; idx < elements.length; idx++) {
            image_ids.push(elements[idx]);
            if (idx % syncBlockSize !== syncBlockSize - 1 && idx !== elements.length - 1) continue;
            const ids = [...image_ids];
            const thisBatchSize = ids.length;
            queuedMgr.add(async () => {
                try {
                    const body = new URLSearchParams({ pwg_token, image_id: ids.join(',') });
                    await fetch('ws.php?format=json&method=pwg.images.syncMetadata', { method: 'POST', body }).then(r => r.json());
                } finally {
                    todo += thisBatchSize;
                    const badge = qs('#regenerationStatus .badge-number'); if (badge) badge.innerHTML = todo + '/' + progressBar_max;
                    progress_bar(todo, progressBar_max, false);
                }
            });
            image_ids = [];
        }
        return;
    }

    if (selectAction === 'delete') {
        const confirmCb = qs<HTMLInputElement>('#confirmDel input[name=confirm_deletion]');
        if (!confirmCb?.checked) {
            const errSpan = qs<HTMLElement>('#confirmDel span.errors'); if (errSpan) errSpan.style.visibility = 'visible';
            return;
        }
        e.stopPropagation();
    } else { return; }

    qsa('.bulkAction').forEach(hide);
    const queuedMgr = new AjaxQueue(1);
    elements = [];
    if ((qs<HTMLInputElement>('input[name=setSelected]'))?.checked) { elements = all_elements; }
    else { document.querySelectorAll<HTMLInputElement>('input[name="selection[]"]:checked').forEach(el => elements.push(el.value)); }

    progressBar_max = elements.length;
    let todo = 0;
    const deleteBlockSize = Math.min(Math.floor(elements.length / 2) || 1, 1000);
    let image_ids: any[] = [];
    hide(qs('#applyActionBlock')); hide(qs('.permitActionListButton')); hide(qs('#confirmDel'));
    const regenText = qs('#regenerationText'); if (regenText) regenText.innerHTML = lang.deleteProgressMessage;
    show(qs('#regenerationMsg'));
    progress_bar_start();

    for (let idx = 0; idx < elements.length; idx++) {
        image_ids.push(elements[idx]);
        if (idx % deleteBlockSize !== deleteBlockSize - 1 && idx !== elements.length - 1) continue;
        const ids = [...image_ids];
        const thisBatchSize = ids.length;
        queuedMgr.add(async () => {
            try {
                const body = new URLSearchParams({ method: 'pwg.images.delete', pwg_token, image_id: ids.join(',') });
                await fetch('ws.php?format=json', { method: 'POST', body }).then(r => r.json());
            } finally {
                todo += thisBatchSize;
                const badge = qs('#regenerationStatus .badge-number'); if (badge) badge.innerHTML = todo + '/' + progressBar_max;
                progress_bar(todo, progressBar_max, false);
            }
        });
        image_ids = [];
    }
    document.querySelector('form')?.insertAdjacentHTML('beforeend', `<input type="hidden" name="nb_photos_deleted" value="${elements.length}">`);
});

function progress_bar_start() {
    show(qs('#uploadingActions'));
    const pb = qs<HTMLElement>('#uploadingActions .progress-bar'); if (pb) pb.style.width = '0%';
}
function progress_bar_end() { hide(qs('#uploadingActions')); }
function progress_bar(val: any, max: any, _success: any) {
    percent = Math.floor(val / max * 100);
    const pb = qs<HTMLElement>('#uploadingActions .progressbar'); if (pb) pb.style.width = percent + '%';
    if (val === max) qs<HTMLElement>('#applyAction')?.click();
}

qs<HTMLInputElement>('#confirmDel input[name=confirm_deletion]')?.addEventListener('change', () => {
    const errSpan = qs<HTMLElement>('#confirmDel span.errors'); if (errSpan) errSpan.style.visibility = 'hidden';
});

qs('#sync_md5sum')?.addEventListener('click', (e) => {
    e.preventDefault();
    hide(qs('#sync_md5sum')); show(qs('#add_md5sum'));
    const originEl = qs('#md5sum_to_add');
    const origin = parseInt(originEl?.dataset['origin'] ?? '0');
    add_md5sum_block(Math.min(Math.floor(origin / 2) || 1, 1000));
});

function add_md5sum_block(blockSize: any) {
    const body = new URLSearchParams({ pwg_token, block_size: String(blockSize ?? '') });
    fetch('ws.php?format=json&method=pwg.images.setMd5sum', { method: 'POST', body }).then(r => r.json()).then((data: any) => {
        const el = qs('#md5sum_to_add'); if (el) el.innerHTML = data.result.nb_no_md5sum;
        const origin = parseInt(qs('#md5sum_to_add')?.dataset['origin'] ?? '0');
        const pctDone = 100 - Math.floor(data.result.nb_no_md5sum * 100 / origin);
        const addedEl = qs('#md5sum_added'); if (addedEl) addedEl.innerHTML = String(pctDone);
        if (data.result.nb_no_md5sum > 0) { add_md5sum_block(undefined); }
        else { document.location = `admin.php?page=batch_manager&action=sync_md5sum&nb_md5sum_added=${origin}` as any; }
    }).catch((xhr: any) => {
        hide(qs('#add_md5sum'));
        const errEl = qs('#add_md5sum_error'); if (errEl) { errEl.style.display = ''; errEl.innerHTML = 'error: ' + xhr.message; }
    });
}

qs('#delete_orphans')?.addEventListener('click', (e) => {
    e.preventDefault();
    hide(qs('#delete_orphans')); show(qs('#orphans_deletion'));
    const origin = parseInt(qs('#orphans_to_delete')?.dataset['origin'] ?? '0');
    delete_orphans_block(Math.min(Math.floor(origin / 2) || 1, 1000));
});

function delete_orphans_block(blockSize: any) {
    const body = new URLSearchParams({ pwg_token, block_size: String(blockSize ?? '') });
    fetch('ws.php?format=json&method=pwg.images.deleteOrphans', { method: 'POST', body }).then(r => r.json()).then((data: any) => {
        const el = qs('#orphans_to_delete'); if (el) el.innerHTML = data.result.nb_orphans;
        const origin = parseInt(qs('#orphans_to_delete')?.dataset['origin'] ?? '0');
        const pctDone = 100 - Math.floor(data.result.nb_orphans * 100 / origin);
        const delEl = qs('#orphans_deleted'); if (delEl) delEl.innerHTML = String(pctDone);
        if (data.result.nb_orphans > 0) { delete_orphans_block(undefined); }
        else { document.location = `admin.php?page=batch_manager&action=delete_orphans&nb_orphans_deleted=${origin}` as any; }
    }).catch((xhr: any) => {
        hide(qs('#orphans_deletion'));
        const errEl = qs('#orphans_deletion_error'); if (errEl) { errEl.style.display = ''; errEl.innerHTML = 'error: ' + xhr.message; }
    });
}

(window as any).selectGenerateDerivAll = selectGenerateDerivAll;
(window as any).selectGenerateDerivNone = selectGenerateDerivNone;
(window as any).selectDelDerivAll = selectDelDerivAll;
(window as any).selectDelDerivNone = selectDelDerivNone;
(window as any).getDerivativeUrls = getDerivativeUrls;

export {};
