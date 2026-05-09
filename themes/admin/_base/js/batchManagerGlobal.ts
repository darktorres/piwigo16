import GLightbox from 'glightbox';
import tippy from 'tippy.js';
import 'tippy.js/dist/tippy.css';
import { AlbumSelector } from './album_selector';
import { TagsCache, CategoriesCache } from './LocalStorageCache';
import type { CacheItem, SelectizerOptions } from './LocalStorageCache';
import { getPageData } from './page-data';
import { config } from './config';

interface BatchManagerGlobalPageData {
    CACHE_KEYS: { tags: string; categories: string; _hash: string };
    ROOT_URL: string;
    associated_categories: Record<string, unknown>;
    str_create: string;
    nb_thumbs_page: number;
    nb_thumbs_set: number;
    all_elements: string[];
    lang: {
        Cancel: string;
        deleteProgressMessage: string;
        syncProgressMessage: string;
        AreYouSure: string;
        generateMsg: string;
    };
    str_add_alb_associate: string;
    str_select_alb_associate: string;
    applyOnDetails_pattern: string;
    selectedMessage_pattern: string;
    selectedMessage_none: string;
    selectedMessage_all: string;
}

interface WindowGlobals {
    pwgDatepicker?: (
        el: HTMLElement,
        opts: { showTimepicker: boolean; cancelButton: string }
    ) => void;
    pwgAddAlbum?: (el: HTMLElement) => void;
    getDerivativeUrls?: () => void;
}

interface DerivativesUrlsResult {
    urls: string[];
}

interface NbNoMd5sumResult {
    nb_no_md5sum: number;
}

interface NbOrphansResult {
    nb_orphans: number;
}

interface WsResponse<T> {
    stat?: string;
    result?: T;
    err?: string;
    message?: string;
}

const pageData = getPageData<BatchManagerGlobalPageData>('pwg-batch-manager-global-data');
const lang = pageData.lang;

// Initialize caches
const tagsCache = new TagsCache({
    serverKey: pageData.CACHE_KEYS.tags,
    serverId: pageData.CACHE_KEYS._hash,
    rootUrl: pageData.ROOT_URL,
});

const categoriesCache = new CategoriesCache({
    serverKey: pageData.CACHE_KEYS.categories,
    serverId: pageData.CACHE_KEYS._hash,
    rootUrl: pageData.ROOT_URL,
});

tagsCache.selectize(document.querySelector('[data-selectize=tags]'), {
    lang: {
        Add: pageData.str_create,
    },
});

categoriesCache.selectize(document.querySelector('[data-selectize=categories]'), {
    filter: function (categories: CacheItem[], options: SelectizerOptions) {
        if (this.name === 'dissociate') {
            const filtered = categories.filter(
                (cat) => pageData.associated_categories[String(cat.id)] !== undefined
            );

            if (filtered.length > 0) {
                options.default = filtered[0]!.id;
            }

            return filtered;
        } else {
            return categories;
        }
    },
});

const all_elements = pageData.all_elements;
const str_add_alb_associate = pageData.str_add_alb_associate;
const str_select_alb_associate = pageData.str_select_alb_associate;
const nb_thumbs_set = pageData.nb_thumbs_set;
const applyOnDetails_pattern = pageData.applyOnDetails_pattern;
const selectedMessage_pattern = pageData.selectedMessage_pattern;
const selectedMessage_none = pageData.selectedMessage_none;
const selectedMessage_all = pageData.selectedMessage_all;

let elements: string[] = [];
let percent = 0;
let progressBar_max = 0;

const pwg_token = document.querySelector<HTMLInputElement>('input[name=pwg_token]')?.value ?? '';

const qs = <T extends HTMLElement = HTMLElement>(sel: string) => document.querySelector<T>(sel);
const qsa = <T extends HTMLElement = HTMLElement>(sel: string) =>
    Array.from(document.querySelectorAll<T>(sel));
const show = (el: HTMLElement | null | undefined) => {
    if (el) el.style.display = '';
};
const hide = (el: HTMLElement | null | undefined) => {
    if (el) el.style.display = 'none';
};

class AjaxQueue {
    private pending: Array<() => Promise<void>> = [];
    private running = 0;
    private max: number;
    constructor(max: number) {
        this.max = max;
    }
    add(fn: () => Promise<void>): void {
        this.pending.push(fn);
        this.process();
    }
    private process(): void {
        while (this.running < this.max && this.pending.length > 0) {
            const fn = this.pending.shift()!;
            this.running++;
            void fn().finally(() => {
                this.running--;
                this.process();
            });
        }
    }
}

// Derivatives queue (global, used by getDerivativeUrls)
const derivativesQueue = new AjaxQueue(3);

/*---- Thumbnails shift-click ----*/
function enableShiftClick(container: HTMLElement) {
    const inputs: HTMLInputElement[] = [];
    let lastClicked = 0,
        lastClickedStatus = true;
    container.querySelectorAll<HTMLInputElement>('input[type=checkbox]').forEach((inp, pos) => {
        inputs.push(inp);
        inp.addEventListener('click', (e: MouseEvent) => {
            if (e.shiftKey) {
                let first = lastClicked,
                    last = pos;
                if (first > last) {
                    [first, last] = [last, first];
                }
                for (let idx = first; idx <= last; idx++) {
                    inputs[idx]!.checked = lastClickedStatus;
                    inputs[idx]!.dispatchEvent(new Event('change'));
                    inputs[idx]!.closest('li')?.classList.toggle(
                        'thumbSelected',
                        lastClickedStatus
                    );
                }
            } else {
                lastClicked = pos;
                lastClickedStatus = inp.checked;
            }
        });
    });
}

/*---- Album Selector ----*/
interface AlbumItem {
    id: string | number;
    name?: string;
    full_name_with_admin_links?: string;
}

function select_album_action({
    album,
    addSelectedAlbum,
}: {
    album: AlbumItem;
    addSelectedAlbum: () => void;
}) {
    const assocP = qs('#associate_as p');
    if (assocP) assocP.innerHTML = str_add_alb_associate;
    qs('.selected-associate-action')?.insertAdjacentHTML(
        'beforeend',
        `<div class="selected-associate-item">
            <span>${album.name ?? ''}</span><span id="${String(album.id)}" class="remove-associate icon-cancel-circled"></span>
            <input type="hidden" id="associate_input_${String(album.id)}" name="associate[]" value="${String(album.id)}">
        </div>`
    );
    addSelectedAlbum();
}

function remove_album_action({
    id_album,
    getSelectedAlbum,
}: {
    id_album: string | number;
    getSelectedAlbum: () => Array<string | number>;
}) {
    document.getElementById(String(id_album))?.parentElement?.remove();
    if (getSelectedAlbum().length === 0) {
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
        if (target.classList.contains('remove-associate'))
            ab_action.remove_selected_album(target.id);
    });
});

/*---- Preview lightbox ----*/
GLightbox({ selector: 'a.preview-box' });
tippy('.thumbnails img', { delay: [0, 0], duration: [200, 200] });

/*---- Datepicker + AddAlbum ----*/
const winRef = window as unknown as Window & WindowGlobals;
document.querySelectorAll<HTMLElement>('[data-datepicker]').forEach((el) => {
    winRef.pwgDatepicker?.(el, { showTimepicker: true, cancelButton: lang.Cancel });
});
document.querySelectorAll<HTMLElement>('[data-add-album]').forEach((el) => {
    winRef.pwgAddAlbum?.(el);
});

/*---- Toggle visibility ----*/
qs<HTMLInputElement>('input[name=remove_author]')?.addEventListener(
    'click',
    function (this: HTMLInputElement) {
        if (this.checked) hide(qs('input[name=author]'));
        else show(qs('input[name=author]'));
    }
);
qs<HTMLInputElement>('input[name=remove_title]')?.addEventListener(
    'click',
    function (this: HTMLInputElement) {
        if (this.checked) hide(qs('input[name=title]'));
        else show(qs('input[name=title]'));
    }
);
qs<HTMLInputElement>('input[name=remove_date_creation]')?.addEventListener(
    'click',
    function (this: HTMLInputElement) {
        if (this.checked) hide(qs('#set_date_creation'));
        else show(qs('#set_date_creation'));
    }
);

/*---- Derivatives ----*/
const derivatives: {
    elements: string[];
    done: number;
    total: number;
    finished: () => boolean;
} = {
    elements: [],
    done: 0,
    total: 0,
    finished() {
        return this.done === this.total && this.elements.length === 0;
    },
};

function progress_start() {
    show(qs('#uploadingActions'));
    const pb = qs<HTMLElement>('#uploadingActions .progress-bar');
    if (pb) pb.style.width = '0%';
}
function progress_end() {
    hide(qs('#uploadingActions'));
}

function progress(success: boolean | undefined) {
    percent = Math.floor((derivatives.done / derivatives.total) * 100);
    const pb = qs<HTMLElement>('#uploadingActions .progressbar');
    if (pb) pb.style.width = percent + '%';
    if (success !== undefined) {
        const type = success ? 'regenerateSuccess' : 'regenerateError';
        const inp = qs<HTMLInputElement>(`[name="${type}"]`);
        if (inp) inp.value = String(parseInt(inp.value) + 1);
    }
    if (derivatives.finished()) {
        progress_end();
        qs<HTMLElement>('#applyAction')?.click();
    }
}

function updateDerivativeBadge() {
    const badge = qs('#regenerationStatus .badge-number');
    if (badge) badge.innerHTML = derivatives.done + '/' + derivatives.total;
}

function getDerivativeUrls() {
    const ids = derivatives.elements.splice(0, 500);
    const types: string[] = [];
    document
        .querySelectorAll<HTMLInputElement>('#action_generate_derivatives input')
        .forEach((t) => {
            if (t.checked) types.push(t.value);
        });
    hide(qs('#applyActionBlock'));
    hide(qs('.permitActionListButton'));
    hide(qs('#confirmDel'));
    show(qs('#regenerationMsg'));
    const regen = qs('#regenerationText');
    if (regen) regen.innerHTML = lang.generateMsg;
    progress_start();

    const body = new URLSearchParams({ max_urls: '100000', types: types.join(',') });
    ids.forEach((id) => body.append('ids[]', id));
    void fetch(config.wsUrl + 'format=json&method=pwg.getMissingDerivatives', {
        method: 'POST',
        body,
    })
        .then((r) => r.json() as Promise<WsResponse<DerivativesUrlsResult>>)
        .then((data) => {
            if (data.stat !== 'ok' || data.result === undefined) return;
            derivatives.total += data.result.urls.length;
            updateDerivativeBadge();
            progress(undefined);
            data.result.urls.forEach((url) => {
                derivativesQueue.add(async () => {
                    try {
                        await fetch(url + '&ajaxload=true');
                        derivatives.done++;
                        updateDerivativeBadge();
                        progress(true);
                    } catch {
                        derivatives.done++;
                        updateDerivativeBadge();
                        progress(false);
                    }
                });
            });
            if (derivatives.elements.length > 0)
                setTimeout(getDerivativeUrls, 25 * (derivatives.total - derivatives.done));
        });
}

function selectGenerateDerivAll() {
    document
        .querySelectorAll<HTMLInputElement>('#action_generate_derivatives input[type=checkbox]')
        .forEach((el) => {
            el.checked = true;
            el.dispatchEvent(new Event('change'));
        });
}
function selectGenerateDerivNone() {
    document
        .querySelectorAll<HTMLInputElement>('#action_generate_derivatives input[type=checkbox]')
        .forEach((el) => {
            el.checked = false;
            el.dispatchEvent(new Event('change'));
        });
}
function selectDelDerivAll() {
    document
        .querySelectorAll<HTMLInputElement>(
            '#action_delete_derivatives input[name="del_derivatives_type[]"]'
        )
        .forEach((el) => {
            el.checked = true;
            el.dispatchEvent(new Event('change'));
        });
}
function selectDelDerivNone() {
    document
        .querySelectorAll<HTMLInputElement>(
            '#action_delete_derivatives input[name="del_derivatives_type[]"]'
        )
        .forEach((el) => {
            el.checked = false;
            el.dispatchEvent(new Event('change'));
        });
}

window.addEventListener('keypress', (e: KeyboardEvent) => {
    const selected = qs<HTMLSelectElement>("select[name='selectAction']")?.value;
    const haveTextarea =
        selected !== undefined && selected !== ''
            ? document.querySelectorAll(`#action_${selected} textarea`).length
            : 0;
    const addLinkedAlbum = document.getElementById('addLinkedAlbum');
    const isVisible =
        addLinkedAlbum !== null && getComputedStyle(addLinkedAlbum).display !== 'none';
    if (
        e.key === 'Enter' &&
        selected !== undefined &&
        selected !== '' &&
        selected !== '-1' &&
        haveTextarea === 0 &&
        !isVisible
    ) {
        e.preventDefault();
        qs<HTMLElement>('#applyAction')?.click();
    }
});

/*---- Apply action ----*/
qs<HTMLElement>('#applyAction')?.addEventListener('click', (e: Event) => {
    if (typeof elements !== 'undefined') return;

    const selectAction = qs<HTMLSelectElement>('[name="selectAction"]')?.value;

    if (selectAction === 'metadata') {
        e.preventDefault();
        e.stopPropagation();
        qsa('.bulkAction').forEach(hide);
        const regenText = qs('#regenerationText');
        if (regenText) regenText.innerHTML = lang.syncProgressMessage;
        elements = [];
        if (qs<HTMLInputElement>('input[name=setSelected]')?.checked === true) {
            elements = all_elements;
        } else {
            document
                .querySelectorAll<HTMLInputElement>('input[name="selection[]"]:checked')
                .forEach((el) => elements.push(el.value));
        }

        const queuedMgr = new AjaxQueue(1);
        progressBar_max = elements.length;
        let todo = 0;
        const syncBlockSize = Math.min(Math.floor(elements.length / 2) || 1, 1000);
        let image_ids: string[] = [];
        hide(qs('#applyActionBlock'));
        hide(qs('.permitActionListButton'));
        hide(qs('#confirmDel'));
        show(qs('#regenerationMsg'));
        progress_bar_start();

        for (let idx = 0; idx < elements.length; idx++) {
            image_ids.push(elements[idx]!);
            if (idx % syncBlockSize !== syncBlockSize - 1 && idx !== elements.length - 1) continue;
            const ids = [...image_ids];
            const thisBatchSize = ids.length;
            queuedMgr.add(async () => {
                try {
                    const body = new URLSearchParams({ pwg_token, image_id: ids.join(',') });
                    await fetch(config.wsUrl + 'format=json&method=pwg.images.syncMetadata', {
                        method: 'POST',
                        body,
                    }).then((r) => r.json());
                } finally {
                    todo += thisBatchSize;
                    const badge = qs('#regenerationStatus .badge-number');
                    if (badge) badge.innerHTML = todo + '/' + progressBar_max;
                    progress_bar(todo, progressBar_max, false);
                }
            });
            image_ids = [];
        }
        return;
    }

    if (selectAction === 'delete') {
        const confirmCb = qs<HTMLInputElement>('#confirmDel input[name=confirm_deletion]');
        if (confirmCb?.checked !== true) {
            const errSpan = qs<HTMLElement>('#confirmDel span.errors');
            if (errSpan) errSpan.style.visibility = 'visible';
            return;
        }
        e.stopPropagation();
    } else {
        return;
    }

    qsa('.bulkAction').forEach(hide);
    const queuedMgr = new AjaxQueue(1);
    elements = [];
    if (qs<HTMLInputElement>('input[name=setSelected]')?.checked === true) {
        elements = all_elements;
    } else {
        document
            .querySelectorAll<HTMLInputElement>('input[name="selection[]"]:checked')
            .forEach((el) => elements.push(el.value));
    }

    progressBar_max = elements.length;
    let todo = 0;
    const deleteBlockSize = Math.min(Math.floor(elements.length / 2) || 1, 1000);
    let image_ids: string[] = [];
    hide(qs('#applyActionBlock'));
    hide(qs('.permitActionListButton'));
    hide(qs('#confirmDel'));
    const regenText = qs('#regenerationText');
    if (regenText) regenText.innerHTML = lang.deleteProgressMessage;
    show(qs('#regenerationMsg'));
    progress_bar_start();

    for (let idx = 0; idx < elements.length; idx++) {
        image_ids.push(elements[idx]!);
        if (idx % deleteBlockSize !== deleteBlockSize - 1 && idx !== elements.length - 1) continue;
        const ids = [...image_ids];
        const thisBatchSize = ids.length;
        queuedMgr.add(async () => {
            try {
                const body = new URLSearchParams({
                    method: 'pwg.images.delete',
                    pwg_token,
                    image_id: ids.join(','),
                });
                await fetch(config.wsUrl + 'format=json', { method: 'POST', body }).then((r) =>
                    r.json()
                );
            } finally {
                todo += thisBatchSize;
                const badge = qs('#regenerationStatus .badge-number');
                if (badge) badge.innerHTML = todo + '/' + progressBar_max;
                progress_bar(todo, progressBar_max, false);
            }
        });
        image_ids = [];
    }
    document
        .querySelector('form')
        ?.insertAdjacentHTML(
            'beforeend',
            `<input type="hidden" name="nb_photos_deleted" value="${elements.length}">`
        );
});

function progress_bar_start() {
    show(qs('#uploadingActions'));
    const pb = qs<HTMLElement>('#uploadingActions .progress-bar');
    if (pb) pb.style.width = '0%';
}
function progress_bar(val: number, max: number, _success: boolean) {
    percent = Math.floor((val / max) * 100);
    const pb = qs<HTMLElement>('#uploadingActions .progressbar');
    if (pb) pb.style.width = percent + '%';
    if (val === max) qs<HTMLElement>('#applyAction')?.click();
}

qs<HTMLInputElement>('#confirmDel input[name=confirm_deletion]')?.addEventListener('change', () => {
    const errSpan = qs<HTMLElement>('#confirmDel span.errors');
    if (errSpan) errSpan.style.visibility = 'hidden';
});

qs('#sync_md5sum')?.addEventListener('click', (e) => {
    e.preventDefault();
    hide(qs('#sync_md5sum'));
    show(qs('#add_md5sum'));
    const originEl = qs('#md5sum_to_add');
    const origin = parseInt(originEl?.dataset['origin'] ?? '0');
    add_md5sum_block(Math.min(Math.floor(origin / 2) || 1, 1000));
});

function add_md5sum_block(blockSize: number | undefined) {
    const body = new URLSearchParams({
        pwg_token,
        block_size: blockSize === undefined ? '' : String(blockSize),
    });
    void fetch(config.wsUrl + 'format=json&method=pwg.images.setMd5sum', { method: 'POST', body })
        .then((r) => r.json() as Promise<WsResponse<NbNoMd5sumResult>>)
        .then((data) => {
            if (data.result === undefined) return;
            const el = qs('#md5sum_to_add');
            if (el) el.innerHTML = String(data.result.nb_no_md5sum);
            const origin = parseInt(qs('#md5sum_to_add')?.dataset['origin'] ?? '0');
            const pctDone = 100 - Math.floor((data.result.nb_no_md5sum * 100) / origin);
            const addedEl = qs('#md5sum_added');
            if (addedEl) addedEl.innerHTML = String(pctDone);
            if (data.result.nb_no_md5sum > 0) {
                add_md5sum_block(undefined);
            } else {
                document.location = `${config.adminUrl}page=batch_manager&action=sync_md5sum&nb_md5sum_added=${String(origin)}`;
            }
        })
        .catch((xhr: unknown) => {
            hide(qs('#add_md5sum'));
            const errEl = qs('#add_md5sum_error');
            if (errEl) {
                errEl.style.display = '';
                const msg = xhr instanceof Error ? xhr.message : String(xhr);
                errEl.innerHTML = 'error: ' + msg;
            }
        });
}

qs('#delete_orphans')?.addEventListener('click', (e) => {
    e.preventDefault();
    hide(qs('#delete_orphans'));
    show(qs('#orphans_deletion'));
    const origin = parseInt(qs('#orphans_to_delete')?.dataset['origin'] ?? '0');
    delete_orphans_block(Math.min(Math.floor(origin / 2) || 1, 1000));
});

function delete_orphans_block(blockSize: number | undefined) {
    const body = new URLSearchParams({
        pwg_token,
        block_size: blockSize === undefined ? '' : String(blockSize),
    });
    void fetch(config.wsUrl + 'format=json&method=pwg.images.deleteOrphans', {
        method: 'POST',
        body,
    })
        .then((r) => r.json() as Promise<WsResponse<NbOrphansResult>>)
        .then((data) => {
            if (data.result === undefined) return;
            const el = qs('#orphans_to_delete');
            if (el) el.innerHTML = String(data.result.nb_orphans);
            const origin = parseInt(qs('#orphans_to_delete')?.dataset['origin'] ?? '0');
            const pctDone = 100 - Math.floor((data.result.nb_orphans * 100) / origin);
            const delEl = qs('#orphans_deleted');
            if (delEl) delEl.innerHTML = String(pctDone);
            if (data.result.nb_orphans > 0) {
                delete_orphans_block(undefined);
            } else {
                document.location = `${config.adminUrl}page=batch_manager&action=delete_orphans&nb_orphans_deleted=${String(origin)}`;
            }
        })
        .catch((xhr: unknown) => {
            hide(qs('#orphans_deletion'));
            const errEl = qs('#orphans_deletion_error');
            if (errEl) {
                errEl.style.display = '';
                const msg = xhr instanceof Error ? xhr.message : String(xhr);
                errEl.innerHTML = 'error: ' + msg;
            }
        });
}

/*---- Selection / permit-action UI (migrated from {footer_script}) ----*/
declare function sprintf(fmt: string, ...args: unknown[]): string;

function checkPermitAction(): void {
    const setSelectedEl = qs<HTMLInputElement>('input[name=setSelected]');
    const nbSelected =
        setSelectedEl?.checked === true
            ? nb_thumbs_set
            : qsa<HTMLInputElement>('.thumbnails input[type=checkbox]').filter((el) => el.checked)
                  .length;

    const permitAction = qs('#permitAction');
    const forbidAction = qs('#forbidAction');
    if (nbSelected === 0) {
        if (permitAction) permitAction.style.display = 'none';
        if (forbidAction) forbidAction.style.display = '';
    } else {
        if (permitAction) permitAction.style.display = '';
        if (forbidAction) forbidAction.style.display = 'none';
    }

    const applyOnDetails = qs('#applyOnDetails');
    if (applyOnDetails) applyOnDetails.textContent = sprintf(applyOnDetails_pattern, nbSelected);

    const selectedMessage = qs('#selectedMessage');
    if (selectedMessage) {
        if (nbSelected === 0) {
            selectedMessage.textContent = sprintf(selectedMessage_none, nb_thumbs_set);
        } else if (nbSelected === nb_thumbs_set) {
            selectedMessage.textContent = sprintf(selectedMessage_all, nb_thumbs_set);
        } else {
            selectedMessage.textContent = sprintf(
                selectedMessage_pattern,
                nbSelected,
                nb_thumbs_set
            );
        }
    }
}

function selectPageThumbnails(): void {
    qsa('.thumbnails label').forEach((label) => {
        const checkbox = label.querySelector<HTMLInputElement>('input[type=checkbox]');
        if (checkbox) {
            checkbox.checked = true;
            checkbox.dispatchEvent(new Event('change'));
        }
        const li = label.closest('li');
        if (li) li.classList.add('thumbSelected');
    });
}

qsa('[id^=action_]').forEach((el) => {
    el.style.display = 'none';
});

qs<HTMLSelectElement>('select[name=selectAction]')?.addEventListener(
    'change',
    function (this: HTMLSelectElement) {
        qsa('[id^=action_]').forEach((el) => {
            el.style.display = 'none';
        });

        const action = this.value;
        const actionEl = document.getElementById('action_' + action);
        if (actionEl) actionEl.style.display = '';

        const applyActionBlock = qs('#applyActionBlock');
        if (applyActionBlock) {
            applyActionBlock.style.display = this.value !== '-1' ? '' : 'none';
        }

        const confirmDel = qs<HTMLElement>('#confirmDel');
        if (confirmDel) {
            confirmDel.style.visibility =
                this.value === 'delete' || this.value === 'delete_derivatives'
                    ? 'visible'
                    : 'hidden';
        }
    }
);

qsa('.wrap1 label').forEach((label) => {
    label.addEventListener('click', function (this: HTMLElement) {
        const setSelectedEl = qs<HTMLInputElement>('input[name=setSelected]');
        if (setSelectedEl) {
            setSelectedEl.checked = false;
            setSelectedEl.dispatchEvent(new Event('change'));
        }

        const li = this.closest('li');
        const checkbox = this.querySelector<HTMLInputElement>('input[type=checkbox]');

        if (checkbox?.checked === true) {
            if (li) li.classList.add('thumbSelected');
        } else {
            if (li) li.classList.remove('thumbSelected');
        }

        checkPermitAction();
    });
});

qs('#selectAll')?.addEventListener('click', (e) => {
    e.preventDefault();
    const setSelectedEl = qs<HTMLInputElement>('input[name=setSelected]');
    if (setSelectedEl) {
        setSelectedEl.checked = false;
        setSelectedEl.dispatchEvent(new Event('change'));
    }
    selectPageThumbnails();
    checkPermitAction();
});

qs('#selectNone')?.addEventListener('click', (e) => {
    e.preventDefault();
    const setSelectedEl = qs<HTMLInputElement>('input[name=setSelected]');
    if (setSelectedEl) {
        setSelectedEl.checked = false;
        setSelectedEl.dispatchEvent(new Event('change'));
    }
    qsa('.thumbnails label').forEach((label) => {
        const checkbox = label.querySelector<HTMLInputElement>('input[type=checkbox]');
        if (checkbox?.checked === true) {
            checkbox.checked = false;
            checkbox.dispatchEvent(new Event('change'));
        }
        const li = label.closest('li');
        if (li) li.classList.remove('thumbSelected');
    });
    checkPermitAction();
});

qs('#selectInvert')?.addEventListener('click', (e) => {
    e.preventDefault();
    const setSelectedEl = qs<HTMLInputElement>('input[name=setSelected]');
    if (setSelectedEl) {
        setSelectedEl.checked = false;
        setSelectedEl.dispatchEvent(new Event('change'));
    }
    qsa('.thumbnails label').forEach((label) => {
        const checkbox = label.querySelector<HTMLInputElement>('input[type=checkbox]');
        if (checkbox) {
            checkbox.checked = !checkbox.checked;
            checkbox.dispatchEvent(new Event('change'));
        }
        const li = label.closest('li');
        if (checkbox?.checked === true) {
            if (li) li.classList.add('thumbSelected');
        } else {
            if (li) li.classList.remove('thumbSelected');
        }
    });
    checkPermitAction();
});

qs('#selectSet')?.addEventListener('click', (e) => {
    e.preventDefault();
    selectPageThumbnails();
    const setSelectedEl = qs<HTMLInputElement>('input[name=setSelected]');
    if (setSelectedEl) {
        setSelectedEl.checked = true;
        setSelectedEl.dispatchEvent(new Event('change'));
    }
    checkPermitAction();
});

qs<HTMLInputElement>('input[name=setSelected]')?.addEventListener(
    'change',
    function (this: HTMLInputElement) {
        const wholeSet = qs<HTMLInputElement>('input[name=whole_set]');
        if (wholeSet) wholeSet.value = this.checked ? all_elements.join(',') : '';
    }
);

// If the whole set was selected on page load (after a first action), trigger
// change once so input[name=whole_set] is filled in.
const setSelectedCheck = qs<HTMLInputElement>('input[name="setSelected"]');
if (setSelectedCheck?.checked === true) {
    setSelectedCheck.dispatchEvent(new Event('change'));
}

// Apply-action handler for the derivatives flow. The other handler in this
// module (above, with the legacy `typeof elements` guard) covers the
// metadata/delete branches; this one preventDefault's for the
// generate_derivatives and delete_derivatives branches.
qs<HTMLElement>('#applyAction')?.addEventListener('click', (e) => {
    const action = qs<HTMLSelectElement>('[name="selectAction"]')?.value;

    if (action === 'delete_derivatives') {
        const confirmDeletionEl = qs<HTMLInputElement>('#confirmDel input[name=confirm_deletion]');
        if (confirmDeletionEl?.checked !== true) {
            const errorsEl = qs<HTMLElement>('#confirmDel span.errors');
            if (errorsEl) errorsEl.style.visibility = 'visible';
            e.preventDefault();
            return;
        }
        return;
    }

    if (action !== 'generate_derivatives' || derivatives.finished()) {
        return;
    }

    qsa('.bulkAction').forEach((el) => {
        el.style.display = 'none';
    });

    derivatives.elements = [];
    const setSelectedEl = qs<HTMLInputElement>('input[name="setSelected"]');
    if (setSelectedEl?.checked === true) {
        derivatives.elements = all_elements;
    } else {
        qsa<HTMLInputElement>('.thumbnails input[type=checkbox]').forEach((cb) => {
            if (cb.checked) {
                derivatives.elements.push(cb.value);
            }
        });
    }

    const applyActionBlock = qs('#applyActionBlock');
    if (applyActionBlock) applyActionBlock.style.display = 'none';
    const selectActionEl = qs<HTMLSelectElement>('select[name="selectAction"]');
    if (selectActionEl) selectActionEl.style.display = 'none';
    qsa('.permitActionListButton div').forEach((el) => el.classList.add('hidden'));
    const regenerationMsg = qs('#regenerationMsg');
    if (regenerationMsg) regenerationMsg.style.display = '';

    progress_start();
    progress(undefined);
    getDerivativeUrls();
    e.preventDefault();
});

checkPermitAction();

qs<HTMLSelectElement>('select[name=filter_prefilter]')?.addEventListener(
    'change',
    function (this: HTMLSelectElement) {
        const val = this.value;
        const emptyCaddie = qs<HTMLElement>('#empty_caddie');
        const duplicatesOptions = qs<HTMLElement>('#duplicates_options');
        const deleteOrphans = qs<HTMLElement>('#delete_orphans');
        const syncMd5sum = qs<HTMLElement>('#sync_md5sum');
        if (emptyCaddie) emptyCaddie.style.display = val === 'caddie' ? '' : 'none';
        if (duplicatesOptions) duplicatesOptions.style.display = val === 'duplicates' ? '' : 'none';
        if (deleteOrphans) deleteOrphans.style.display = val === 'no_album' ? '' : 'none';
        if (syncMd5sum) syncMd5sum.style.display = val === 'no_sync_md5sum' ? '' : 'none';
    }
);

winRef.getDerivativeUrls = getDerivativeUrls;

document.addEventListener('click', (e) => {
    const a = (e.target as HTMLElement | null)?.closest<HTMLAnchorElement>('a[data-deriv-select]');
    if (!a) return;
    e.preventDefault();
    switch (a.dataset['derivSelect']) {
        case 'generate-all':  selectGenerateDerivAll(); break;
        case 'generate-none': selectGenerateDerivNone(); break;
        case 'delete-all':    selectDelDerivAll(); break;
        case 'delete-none':   selectDelDerivNone(); break;
    }
});

export {};
