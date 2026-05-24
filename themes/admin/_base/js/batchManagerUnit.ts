import '../css/pages/batch_manager_unit.css';
import '../css/features/selection-mode.css';
import { getPageData } from './page-data';
import { AlbumSelector } from './album_selector';
import { TagsCache, CategoriesCache } from './LocalStorageCache';
import { config } from './config';

// Plugin scripts push their per-element field descriptors into this array before save.
// Initialised here so plugins can push before DOMContentLoaded.
type WindowWithPluginValues = Window & {
    pluginValues?: PluginValue[];
    GLightbox?: (opts: { selector: string }) => void;
    pwgDatepicker?: (
        el: HTMLElement,
        opts: { showTimepicker: boolean; cancelButton: string }
    ) => void;
    [pluginKey: string]: unknown;
};

interface PluginValue {
    selector: string;
    api_key: string;
}

const winRef = window as unknown as WindowWithPluginValues;
winRef.pluginValues ??= [];
const pluginValues: PluginValue[] = winRef.pluginValues;

interface BatchUnitPageData {
    str_are_you_sure: string;
    str_yes: string;
    str_no: string;
    str_orphan: string;
    str_meta_warning: string;
    str_meta_yes: string;
    str_title_ab: string;
    str_cancel: string;
}

interface BatchManagerUnitCacheData {
    CACHE_KEYS: { tags: string; categories: string; _hash: string };
    ROOT_URL: string;
    associated_categories: Record<string, unknown>;
    str_create: string;
    active_plugins: string[];
}

interface PwgImageInfo {
    name?: string;
    author?: string;
    date_creation?: string;
    comment?: string;
    level?: string;
    file?: string;
    filesize?: string | number;
    width?: number;
    height?: number;
}

const {
    str_are_you_sure,
    str_yes: _str_yes,
    str_no: _str_no,
    str_orphan,
    str_meta_warning,
    str_meta_yes: _str_meta_yes,
    str_title_ab,
    str_cancel,
} = getPageData<BatchUnitPageData>();

const cacheData = getPageData<BatchManagerUnitCacheData>('pwg-batch-manager-unit-data');

// Map from element ID → related category ID array; built from DOM data-attrs in DOMContentLoaded.
// AlbumSelector also attaches `cat_ids` directly on the array, so the array
// shape carries an optional sidecar property.
type CategoryIdsArray = Array<string | number> & { cat_ids?: Array<string | number> };
const allRelatedCategoryIds: Record<string, CategoryIdsArray | undefined> = {};

// Initialize caches
const tagsCache = new TagsCache({
    serverKey: cacheData.CACHE_KEYS.tags,
    serverId: cacheData.CACHE_KEYS._hash,
    rootUrl: cacheData.ROOT_URL,
});

const categoriesCache = new CategoriesCache({
    serverKey: cacheData.CACHE_KEYS.categories,
    serverId: cacheData.CACHE_KEYS._hash,
    rootUrl: cacheData.ROOT_URL,
});

tagsCache.attach(document.querySelector('[data-ts=tags]'), {
    lang: {
        Add: cacheData.str_create,
    },
});

categoriesCache.attach(document.querySelector('[data-ts=categories]'), {
    filter: function (categories, options) {
        if (this.name === 'dissociate') {
            const filtered = categories.filter((cat) =>
                Boolean(cacheData.associated_categories[String(cat.id)])
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

let b_current_picture_id: string | undefined = undefined;

function pid(pictureId: string | undefined, sel: string): HTMLElement | null {
    return document.querySelector<HTMLElement>('#picture-' + (pictureId ?? '') + ' ' + sel);
}

function pidInput(
    pictureId: string | undefined,
    sel: string
): HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement {
    return document.querySelector<HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement>(
        '#picture-' + (pictureId ?? '') + ' ' + sel
    )!;
}

function _fieldsetId(el: EventTarget | null): string {
    return (
        ((el as HTMLElement).closest('fieldset') as HTMLElement | null)?.dataset['imageId'] ?? ''
    );
}

interface WsResponse<T = unknown> {
    stat?: string;
    result?: T;
    err?: string;
    message?: string;
}

function stringify(val: unknown): string {
    if (val === null || val === undefined) return '';
    if (typeof val === 'string') return val;
    if (typeof val === 'number' || typeof val === 'boolean') return String(val);
    return JSON.stringify(val);
}

function pwgPost<T = unknown>(
    method: string,
    data: Record<string, unknown>
): Promise<WsResponse<T>> {
    const body = new URLSearchParams();
    body.append('method', method);
    for (const [k, v] of Object.entries(data)) {
        if (Array.isArray(v))
            (v as unknown[]).forEach((item) => body.append(k + '[]', stringify(item)));
        else body.append(k, stringify(v));
    }
    return fetch(config.wsUrl + 'format=json', { method: 'POST', body }).then(
        (r) => r.json() as Promise<WsResponse<T>>
    );
}

function _pwgGet<T = unknown>(
    method: string,
    data: Record<string, unknown>
): Promise<WsResponse<T>> {
    const params = new URLSearchParams({ method });
    for (const [k, v] of Object.entries(data)) params.append(k, stringify(v));
    return fetch(config.wsUrl + 'format=json&' + params.toString()).then(
        (r) => r.json() as Promise<WsResponse<T>>
    );
}

function pwgToken(): string {
    return document.querySelector<HTMLInputElement>('input[name=pwg_token]')?.value ?? '';
}

document.addEventListener('DOMContentLoaded', () => {
    // Build related-category map from data-attributes on each element fieldset.
    document.querySelectorAll<HTMLElement>('fieldset.elementEdit').forEach((fs) => {
        const id = fs.dataset['imageId']!;
        try {
            allRelatedCategoryIds[id] = JSON.parse(
                fs.dataset['relatedCategoryIds'] ?? '[]'
            ) as CategoryIdsArray;
        } catch {
            allRelatedCategoryIds[id] = [] as CategoryIdsArray;
        }
    });

    // GLightbox for preview thumbnails.
    winRef.GLightbox?.({ selector: 'a.preview-box' });

    // Datepicker with timepicker for date_creation fields.
    document.querySelectorAll<HTMLElement>('[data-datepicker]').forEach((el) => {
        winRef.pwgDatepicker?.(el, { showTimepicker: true, cancelButton: str_cancel });
    });

    let user_interacted = false;

    document.querySelectorAll<HTMLElement>('input, textarea, select').forEach((el) => {
        el.addEventListener('focus', () => {
            user_interacted = true;
        });
    });

    document.querySelectorAll<HTMLElement>('input, textarea').forEach((el) => {
        el.addEventListener('input', () => {
            const pictureId = el.closest<HTMLElement>('fieldset')?.dataset['imageId'];
            if (user_interacted) showUnsavedLocalBadge(pictureId);
        });
    });

    document.querySelectorAll<HTMLElement>('input[data-datepicker]').forEach((el) => {
        el.addEventListener('change', () => {
            const pictureId = el.closest<HTMLElement>('fieldset')?.dataset['imageId'];
            if (user_interacted) showUnsavedLocalBadge(pictureId);
        });
    });

    document.querySelectorAll<HTMLElement>('select').forEach((el) => {
        el.addEventListener('change', () => {
            const pictureId = el.closest<HTMLElement>('fieldset')?.dataset['imageId'];
            if (user_interacted) showUnsavedLocalBadge(pictureId);
        });
    });

    document
        .querySelectorAll<HTMLElement>(
            '.related-categories-container .remove-item, .datepickerDelete'
        )
        .forEach((el) => {
            el.addEventListener('click', () => {
                user_interacted = true;
                const pictureId = el.closest<HTMLElement>('fieldset')?.dataset['imageId'];
                showUnsavedLocalBadge(pictureId);
            });
        });

    document.querySelectorAll<HTMLElement>('.action-sync-metadata').forEach((el) => {
        el.addEventListener('click', () => {
            const pictureId = el.closest<HTMLElement>('fieldset')?.dataset['imageId'];
            if (!window.confirm(str_meta_warning)) return;
            disableLocalButton(pictureId);
            void pwgPost('pwg.images.syncMetadata', {
                pwg_token: pwgToken(),
                image_id: pictureId,
            })
                .then((data) => {
                    if (data.stat === 'ok') updateBlock(pictureId);
                    else {
                        showErrorLocalBadge(pictureId);
                        enableLocalButton(pictureId);
                    }
                })
                .catch(() => {
                    console.error('Error occurred');
                    enableLocalButton(pictureId);
                });
        });
    });

    document.querySelectorAll<HTMLElement>('.action-delete-picture').forEach((el) => {
        el.addEventListener('click', () => {
            const fieldset = el.closest<HTMLElement>('fieldset')!;
            const pictureId = fieldset.dataset['imageId'];
            if (!window.confirm(str_are_you_sure)) return;
            void pwgPost('pwg.images.delete', { pwg_token: pwgToken(), image_id: pictureId })
                .then((data) => {
                    if (data.stat === 'ok') {
                        fieldset.remove();
                        document
                            .querySelectorAll<HTMLElement>('.pagination-container')
                            .forEach((p) => {
                                p.style.pointerEvents = 'none';
                                p.style.opacity = '0.5';
                            });
                        document.querySelectorAll<HTMLElement>('.button-reload').forEach((b) => {
                            b.style.display = 'block';
                        });
                        document
                            .querySelectorAll<HTMLElement>(
                                `div[data-image_id="${pictureId ?? ''}"]`
                            )
                            .forEach((d) => {
                                d.style.display = 'flex';
                            });
                    } else showErrorLocalBadge(pictureId);
                })
                .catch(() => console.error('Error occurred'));
        });
    });

    document.querySelectorAll<HTMLElement>('.action-save-picture').forEach((el) => {
        el.addEventListener('click', () => {
            const pictureId = el.closest<HTMLElement>('fieldset')?.dataset['imageId'];
            void saveChanges(pictureId);
        });
    });

    document.querySelector('.action-save-global')?.addEventListener('click', () => {
        void saveAllChanges();
    });

    const ab = new AlbumSelector({
        selectedCategoriesIds: [],
        selectAlbum: add_related_category,
        adminMode: true,
        modalTitle: str_title_ab,
    });

    document.querySelectorAll<HTMLElement>('.linked-albums.add-item').forEach((el) => {
        el.addEventListener('click', () => {
            b_current_picture_id = el.closest<HTMLElement>('fieldset')?.dataset['imageId'];
            ab.hardUpdate(allRelatedCategoryIds[b_current_picture_id ?? ''] ?? []);
            ab.open();
        });
    });

    document.querySelectorAll<HTMLElement>('.related-categories-container').forEach((container) => {
        container.addEventListener('click', (e: Event) => {
            const target = e.target as HTMLElement;
            if (target.classList.contains('remove-item')) {
                const cat_id = target.id;
                const picture_id =
                    target.closest<HTMLElement>('fieldset')?.dataset['imageId'] ?? '';
                remove_selected_category(cat_id, picture_id);
                check_related_categories(picture_id, allRelatedCategoryIds[picture_id] ?? []);
            }
        });
    });

    pluginFunctionMapInit(cacheData.active_plugins);
});

function remove_selected_category(cat_id: string, picture_id: string) {
    const arr = allRelatedCategoryIds[picture_id];
    if (arr === undefined) return;
    const idx = arr.indexOf(cat_id);
    if (idx > -1) {
        arr.splice(idx, 1);
        showUnsavedLocalBadge(picture_id);
    }
    document.getElementById(cat_id)?.parentElement?.remove();
}

function add_related_category({
    album,
    getSelectedAlbum,
    addSelectedAlbum,
}: {
    album: { id: string | number; full_name_with_admin_links?: string };
    getSelectedAlbum: () => Array<string | number>;
    addSelectedAlbum: () => void;
}) {
    if (!getSelectedAlbum().includes(album.id)) {
        document
            .querySelector(`#${String(b_current_picture_id)} .related-categories-container`)
            ?.insertAdjacentHTML(
                'beforeend',
                `<div class="breadcrumb-item album-listed">
                <span class="link-path">${album.full_name_with_admin_links ?? ''}</span>
                <span id="${String(album.id)}" class="icon-cancel-circled remove-item"></span>
            </div>`
            );
        showUnsavedLocalBadge(b_current_picture_id);
        addSelectedAlbum();
        if (b_current_picture_id !== undefined) {
            const arr = allRelatedCategoryIds[b_current_picture_id];
            if (arr !== undefined) arr.cat_ids = getSelectedAlbum();
        }
    }
    check_related_categories(b_current_picture_id, getSelectedAlbum());
}

function check_related_categories(
    pictureId: string | undefined,
    selectedAlbum: Array<string | number>
) {
    if (pictureId === undefined) return;
    const badge = document.querySelector<HTMLElement>(`#picture-${pictureId} .linked-albums-badge`);
    if (badge) badge.innerHTML = String(selectedAlbum.length);
    if (selectedAlbum.length === 0) {
        document
            .getElementById(pictureId)
            ?.querySelector('.linked-albums-badge')
            ?.classList.add('badge-red');
        document.getElementById(pictureId)?.querySelector('.add-item')?.classList.add('highlight');
        const orphan = document.querySelector<HTMLElement>(`#${pictureId} .orphan-photo`);
        if (orphan) {
            orphan.innerHTML = str_orphan;
            orphan.style.display = '';
        }
    } else {
        document
            .getElementById(pictureId)
            ?.querySelector('.linked-albums-badge.badge-red')
            ?.classList.remove('badge-red');
        document
            .getElementById(pictureId)
            ?.querySelector('.add-item.highlight')
            ?.classList.remove('highlight');
        const orphan = document.querySelector<HTMLElement>(`#${pictureId} .orphan-photo`);
        if (orphan) orphan.style.display = 'none';
    }
}

function updateUnsavedGlobalBadge() {
    const count = Array.from(document.querySelectorAll<HTMLElement>('.local-unsaved-badge')).filter(
        (el) => el.style.display === 'block'
    ).length;
    const badge = document.querySelector<HTMLElement>('.global-unsaved-badge');
    const counter = document.getElementById('unsaved-count');
    if (count > 0) {
        if (badge) badge.style.display = 'block';
        if (counter) counter.textContent = String(count);
    } else {
        if (badge) badge.style.display = 'none';
        if (counter) counter.textContent = '';
    }
}

function showUnsavedLocalBadge(pictureId: string | undefined) {
    hideSuccesLocalBadge(pictureId);
    hideErrorLocalBadge(pictureId);
    const badge = document.querySelector<HTMLElement>(`#picture-${pictureId} .local-unsaved-badge`);
    if (badge) badge.style.display = 'block';
    updateUnsavedGlobalBadge();
}

function hideUnsavedLocalBadge(pictureId: string | undefined) {
    const badge = document.querySelector<HTMLElement>(`#picture-${pictureId} .local-unsaved-badge`);
    if (badge) badge.style.display = 'none';
    updateUnsavedGlobalBadge();
}

function showErrorLocalBadge(pictureId: string | undefined) {
    const badge = document.querySelector<HTMLElement>(`#picture-${pictureId} .local-error-badge`);
    if (badge) badge.style.display = 'block';
}

function hideErrorLocalBadge(pictureId: string | undefined) {
    const badge = document.querySelector<HTMLElement>(`#picture-${pictureId} .local-error-badge`);
    if (badge) badge.style.display = 'none';
}

function fadeBadge(badge: HTMLElement | null) {
    if (!badge) return;
    badge.style.display = 'block';
    badge.style.opacity = '1';
    setTimeout(() => {
        badge.style.transition = 'opacity 1s';
        badge.style.opacity = '0';
        setTimeout(() => {
            badge.style.display = 'none';
            badge.style.transition = '';
            badge.style.opacity = '';
        }, 1000);
    }, 3000);
}

function updateSuccessGlobalBadge() {
    const count = Array.from(document.querySelectorAll<HTMLElement>('.local-success-badge')).filter(
        (el) => el.style.display === 'block'
    ).length;
    if (count > 0) showSuccesGlobalBadge();
    else hideSuccesGlobalBadge();
}

function showSuccessLocalBadge(pictureId: string | undefined) {
    fadeBadge(document.querySelector<HTMLElement>(`#picture-${pictureId} .local-success-badge`));
}

function hideSuccesLocalBadge(pictureId: string | undefined) {
    const badge = document.querySelector<HTMLElement>(`#picture-${pictureId} .local-success-badge`);
    if (badge) badge.style.display = 'none';
}

function showSuccesGlobalBadge() {
    fadeBadge(document.querySelector<HTMLElement>('.global-succes-badge'));
}

function hideSuccesGlobalBadge() {
    const badge = document.querySelector<HTMLElement>('.global-succes-badge');
    if (badge) badge.style.display = 'none';
}

function showMetasyncSuccesBadge(pictureId: string | undefined) {
    fadeBadge(document.querySelector<HTMLElement>(`#picture-${pictureId} .metasync-success`));
}

function disableLocalButton(pictureId: string | undefined) {
    pid(pictureId, '.action-save-picture')?.classList.add('disabled');
    const i = pid(pictureId, '.action-save-picture i');
    if (i) {
        i.classList.remove('icon-floppy');
        i.classList.add('icon-spin6', 'animate-spin');
    }
    disableGlobalButton();
}

function enableLocalButton(pictureId: string | undefined) {
    pid(pictureId, '.action-save-picture')?.classList.remove('disabled');
    const i = pid(pictureId, '.action-save-picture i');
    if (i) {
        i.classList.remove('icon-spin6', 'animate-spin');
        i.classList.add('icon-floppy');
    }
}

function disableGlobalButton() {
    document.querySelectorAll('.action-save-global').forEach((el) => el.classList.add('disabled'));
    document.querySelectorAll<HTMLElement>('.action-save-global i').forEach((i) => {
        i.classList.remove('icon-floppy');
        i.classList.add('icon-spin6', 'animate-spin');
    });
}

function enableGlobalButton() {
    document
        .querySelectorAll('.action-save-global')
        .forEach((el) => el.classList.remove('disabled'));
    document.querySelectorAll<HTMLElement>('.action-save-global i').forEach((i) => {
        i.classList.remove('icon-spin6', 'animate-spin');
        i.classList.add('icon-floppy');
    });
}

async function saveChanges(pictureId: string | undefined) {
    if (pictureId === undefined) return;
    const unsavedBadge = document.querySelector<HTMLElement>(
        `#picture-${pictureId} .local-unsaved-badge`
    );
    if (unsavedBadge?.style.display !== 'block') return;

    disableLocalButton(pictureId);
    const tags: string[] = [];
    document
        .querySelectorAll<HTMLOptionElement>(`#picture-${pictureId} #tags option`)
        .forEach((opt) => tags.push(opt.value));

    const ajax_data: Record<string, unknown> = {
        image_id: pictureId,
        name: (pidInput(pictureId, '#name') as HTMLInputElement | null)?.value,
        author: (pidInput(pictureId, '#author') as HTMLInputElement | null)?.value,
        date_creation: (pidInput(pictureId, '#date_creation') as HTMLInputElement | null)?.value,
        comment: (pidInput(pictureId, '#description') as HTMLTextAreaElement | null)?.value,
        categories: (allRelatedCategoryIds[pictureId] ?? []).join(';'),
        tag_list: tags,
        level: document.querySelector<HTMLOptionElement>(
            `#picture-${pictureId} #level option:checked`
        )?.value,
        single_value_mode: 'replace',
        multiple_value_mode: 'replace',
        pwg_token: pwgToken(),
    };

    for (const plugin of pluginValues) {
        const sel = plugin.selector;
        const el = document.querySelector<HTMLInputElement | HTMLSelectElement>(
            `#picture-${pictureId} ${sel}`
        );
        ajax_data[plugin.api_key] = el?.value;
    }

    try {
        const data = await pwgPost('pwg.images.setInfo', ajax_data);
        if (data.stat === 'ok') {
            enableLocalButton(pictureId);
            enableGlobalButton();
            hideUnsavedLocalBadge(pictureId);
            showSuccessLocalBadge(pictureId);
            updateSuccessGlobalBadge();
            pluginSaveLoop(cacheData.active_plugins, pictureId);
        } else {
            console.error('Error: ' + JSON.stringify(data));
            enableLocalButton(pictureId);
            enableGlobalButton();
            hideUnsavedLocalBadge(pictureId);
            showErrorLocalBadge(pictureId);
            updateSuccessGlobalBadge();
        }
    } catch (error) {
        enableLocalButton(pictureId);
        enableGlobalButton();
        hideUnsavedLocalBadge(pictureId);
        showErrorLocalBadge(pictureId);
        updateSuccessGlobalBadge();
        console.error('Error:', error);
    }
}

async function saveAllChanges() {
    for (const field of Array.from(document.querySelectorAll<HTMLElement>('fieldset'))) {
        await saveChanges(field.dataset['imageId']);
    }
}

const pluginFunctionMap: Record<string, (pictureId: string | undefined) => void> = {};

function pluginFunctionMapInit(activePlugins: string[]) {
    activePlugins.forEach((pluginId) => {
        const fn = winRef[pluginId + '_batchManagerSave'];
        if (typeof fn === 'function')
            pluginFunctionMap[pluginId] = fn as (pictureId: string | undefined) => void;
    });
}

function pluginSaveLoop(activePlugins: string[], pictureId: string | undefined) {
    activePlugins.forEach((pluginId) => {
        const fn = pluginFunctionMap[pluginId];
        if (typeof fn === 'function') fn(pictureId);
    });
}

function updateBlock(pictureId: string | undefined) {
    const params = new URLSearchParams({
        method: 'pwg.images.getInfo',
        image_id: String(pictureId),
    });
    void fetch(config.wsUrl + 'format=json&' + params.toString())
        .then((r) => r.json() as Promise<WsResponse<PwgImageInfo>>)
        .then((response) => {
            if (response.stat === 'ok' && response.result !== undefined) {
                const r = response.result;
                (pidInput(pictureId, '#name') as HTMLInputElement).value = r.name ?? '';
                (pidInput(pictureId, '#author') as HTMLInputElement).value = r.author ?? '';
                (pidInput(pictureId, '#date_creation') as HTMLInputElement).value =
                    r.date_creation ?? '';
                (pidInput(pictureId, '#description') as HTMLTextAreaElement).value =
                    r.comment ?? '';
                (pidInput(pictureId, '#level') as HTMLSelectElement).value = r.level ?? '';
                const fn = pid(pictureId, '#filename');
                if (fn) fn.textContent = r.file ?? '';
                const fs = pid(pictureId, '#filesize');
                if (fs) fs.textContent = String(r.filesize ?? '');
                const dm = pid(pictureId, '#dimensions');
                if (dm) dm.textContent = String(r.width ?? '') + 'x' + String(r.height ?? '');
                showMetasyncSuccesBadge(pictureId);
                enableLocalButton(pictureId);
                enableGlobalButton();
            } else {
                console.error('Error:', response.message);
                showErrorLocalBadge(pictureId);
                enableLocalButton(pictureId);
                enableGlobalButton();
            }
        })
        .catch((e: unknown) => {
            console.error('Error:', e);
            showErrorLocalBadge(pictureId);
            enableLocalButton(pictureId);
        });
}

export {};
