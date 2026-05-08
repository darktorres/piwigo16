import Cookies from 'js-cookie';
import { getPageData } from './page-data';
import { config } from './config';

interface TagsPageData {
    pwg_token: string;
    total: number;
    orphan_tag_names: string[];
    str_already_exist: string;
    str_and_others_tags: string;
    str_clear_selection: string;
    str_copy: string;
    str_delete: string;
    str_delete_orphan_tags: string;
    str_delete_tags: string;
    str_delete_them: string;
    str_keep_them: string;
    str_merged_into: string;
    str_no_delete_confirmation: string;
    str_no_photos: string;
    str_number_photos: string;
    str_orphan_tags: string;
    str_other_copy: string;
    str_select_all_tag: string;
    str_selection_done: string;
    str_tag_created: string;
    str_tag_deleted: string;
    str_tag_found: string;
    str_tag_rename: string;
    str_tag_selected: string;
    str_tags_deleted: string;
    str_tags_found: string;
    str_yes_delete_confirmation: string;
    str_yes_rename_confirmation: string;
}

const {
    pwg_token,
    total: _total,
    orphan_tag_names,
    str_already_exist,
    str_and_others_tags,
    str_clear_selection,
    str_copy,
    str_delete,
    str_delete_orphan_tags,
    str_delete_tags,
    str_delete_them: _str_delete_them,
    str_keep_them: _str_keep_them,
    str_merged_into,
    str_no_delete_confirmation: _str_no_delete_confirmation,
    str_no_photos,
    str_number_photos,
    str_orphan_tags,
    str_other_copy,
    str_select_all_tag,
    str_selection_done,
    str_tag_created,
    str_tag_deleted,
    str_tag_found,
    str_tag_rename,
    str_tag_selected,
    str_tags_deleted,
    str_tags_found,
    str_yes_delete_confirmation: _str_yes_delete_confirmation,
    str_yes_rename_confirmation,
} = getPageData<TagsPageData>();

interface Tag {
    id: number | string;
    name: string;
    raw_name?: string;
    url_name: string;
    counter?: number;
}

type TagId = number | string;

interface WsResponse<T = unknown> {
    stat?: string;
    result?: T;
    err?: string;
    message?: string;
}

interface TemporaryStateLike {
    changeHTML(el: HTMLElement, html: string): void;
    changeAttribute(el: HTMLElement, attr: string, val: string): void;
    addClass(el: HTMLElement, cls: string): void;
    removeClass(el: HTMLElement, cls: string): void;
    reverse(): void;
}
function newTemporaryState(): TemporaryStateLike {
    return new (
        window as unknown as { TemporaryState: new () => TemporaryStateLike }
    ).TemporaryState();
}

const maxItemDisplayed = 5;
let mergeOption = false;

const qs = <T extends HTMLElement = HTMLElement>(sel: string, ctx: Element | Document = document) =>
    ctx.querySelector<T>(sel);
const qsa = <T extends HTMLElement = HTMLElement>(
    sel: string,
    ctx: Element | Document = document
) => Array.from(ctx.querySelectorAll<T>(sel));
const show = (el: HTMLElement | null | undefined) => {
    if (el) el.style.display = '';
};
const hide = (el: HTMLElement | null | undefined) => {
    if (el) el.style.display = 'none';
};

let dataTags: Tag[] = JSON.parse(qs('.tag-container')?.dataset['tags'] ?? '[]') as Tag[];

/*------- init select -------*/
const sel100 = document.getElementById('select-100') as HTMLInputElement | null;
if (sel100) sel100.checked = true;

/*------- Orphan tags -------*/
qs('.info-warning p a')?.addEventListener('click', () => {
    const url = qs<HTMLAnchorElement>('.info-warning p a')!.dataset['url'] ?? '';
    const tags = orphan_tag_names;
    const str_orphans = str_orphan_tags
        .replace('%s1', String(tags.length))
        .replace('%s2', tags.join(', '));
    if (window.confirm(str_delete_orphan_tags + '\n\n' + str_orphans)) {
        window.location.href = url.replace(/amp;/g, '');
    } else {
        hide(qs<HTMLElement>('.info-warning'));
    }
});

/*------- Tag box creation / recycling -------*/

function createTagBox(
    id: TagId,
    name: string,
    url_name: string,
    count: number,
    raw_name: string | null = null
): HTMLElement {
    const rawName = raw_name ?? name;
    const u_edit = config.adminUrl + 'page=batch_manager&filter=tag-' + String(id);
    const u_view = 'index.php?/tags/' + String(id) + '-' + url_name;
    let html = qs('.tag-template')!
        .innerHTML.replace(/%name%/g, unescape(name))
        .replace('%U_VIEW%', u_view)
        .replace('%U_EDIT%', u_edit)
        .replace('%raw_name%', rawName);
    if (name === rawName) html = html.replace('icon-globe', '');

    const div = document.createElement('div');
    div.className = 'tag-box test';
    div.dataset['id'] = String(id);
    div.dataset['selected'] = '0';
    div.innerHTML = html;

    if (
        (document.getElementById('toggleSelectionMode') as HTMLInputElement | null)?.checked ===
        true
    ) {
        div.classList.add('selection');
        qsa('.in-selection-mode', div).forEach((el) => {
            el.style.display = '';
        });
    }
    const headerI = qs('.tag-dropdown-header i', div);
    if (count > 0) {
        qsa('.dropdown-option.view, .dropdown-option.manage', div).forEach((el) => {
            el.style.display = 'block';
        });
        if (headerI) headerI.innerHTML = str_number_photos.replace('%d', String(count));
    } else {
        if (headerI) headerI.innerHTML = str_no_photos;
    }
    return div;
}

function recycleTagBox(
    tagBox: HTMLElement,
    id: TagId,
    name: string,
    url_name: string,
    count: number,
    raw_name: string | null = null
) {
    const rawName = raw_name ?? name;
    tagBox.dataset['id'] = String(id);
    qsa('.tag-name, .tag-dropdown-header b', tagBox).forEach((el) => {
        el.innerHTML = name;
    });
    const editable = qs<HTMLInputElement>('.tag-name-editable', tagBox);
    if (editable) editable.value = name;
    tagBox.dataset['selected'] = '0';
    const nameEl = qs('.tag-name', tagBox);
    if (nameEl) nameEl.dataset['rawname'] = rawName;
    const viewEl = qs<HTMLAnchorElement>('.dropdown-option.view', tagBox);
    if (viewEl) viewEl.href = 'index.php?/tags/' + String(id) + '-' + url_name;
    const manageEl = qs<HTMLAnchorElement>('.dropdown-option.manage', tagBox);
    if (manageEl) manageEl.href = config.adminUrl + 'page=batch_manager&filter=tag-' + String(id);
    if (count > 0) {
        qsa('.dropdown-option.view, .dropdown-option.manage', tagBox).forEach((el) => {
            el.style.display = 'block';
        });
        const hi = qs('.tag-dropdown-header i', tagBox);
        if (hi) hi.innerHTML = str_number_photos.replace('%d', String(count));
    } else {
        const hi = qs('.tag-dropdown-header i', tagBox);
        if (hi) hi.innerHTML = str_no_photos;
    }
}

function updateBadge() {
    const badge = qs('.badge-number');
    if (badge) badge.innerHTML = String(dataTags.length);
    const addLabel = qs('.tag-header #add-tag .add-tag-label');
    if (addLabel) addLabel.classList.toggle('highlight', dataTags.length === 0);
}

/*------- Add tag -------*/

qs('.add-tag-container')?.addEventListener('click', () => {
    document.getElementById('add-tag')?.classList.add('input-mode');
    document.getElementById('add-tag-input')?.focus();
    qsa('.tag-info').forEach(hide);
});

qs('#add-tag .icon-cancel-circled')?.addEventListener('click', () => {
    document.getElementById('add-tag')?.classList.remove('input-mode');
    qsa('.tag-info').forEach(hide);
});

qsa('.tag-box').forEach((el) => setupTagbox(el));

document.addEventListener('DOMContentLoaded', () => {
    qs('.TagSubmit')?.addEventListener('click', () => {
        const submitBtn = qs<HTMLElement>('.TagSubmit');
        const loadingBtn = qs<HTMLElement>('.TagLoading');
        if (submitBtn) hide(submitBtn);
        if (loadingBtn) show(loadingBtn);
        const tagboxid = qs<HTMLElement>('.RenameTagPopInContainer .tag-property-input')!.id;
        void renameTag(
            tagboxid,
            qs<HTMLInputElement>('.RenameTagPopInContainer .tag-property-input')!.value
        )
            .then(() => {
                if (submitBtn) show(submitBtn);
                if (loadingBtn) hide(loadingBtn);
                rename_tag_close();
                cleanCheckmark();
                const el = qs(`[data-id="${tagboxid}"]`)!;
                const wrapper = document.createElement('div');
                wrapper.className = 'tag-changed';
                el.parentNode?.insertBefore(wrapper, el);
                wrapper.appendChild(el);
                const checkmark = document.createElement('i');
                checkmark.className = 'icon-ok tag-checkmark';
                wrapper.prepend(checkmark);
            })
            .catch((message: unknown) => {
                if (submitBtn) show(submitBtn);
                if (loadingBtn) hide(loadingBtn);
                console.error(message);
            });
    });
});

(document.getElementById('add-tag') as HTMLFormElement | null)?.addEventListener('submit', (e) => {
    e.preventDefault();
    const input = document.getElementById('add-tag-input') as HTMLInputElement;
    if (input.value === '') return;
    const loadState = newTemporaryState();
    const iconValidate = qs<HTMLElement>('#add-tag .icon-validate')!;
    loadState.removeClass(iconValidate, 'icon-plus');
    loadState.changeHTML(iconValidate, "<i class='icon-spin6 animate-spin'> </i>");
    loadState.changeAttribute(iconValidate, 'style', 'pointer-event:none');
    void addTag(input.value)
        .then(() => {
            showMessage(str_tag_created.replace('%s', input.value));
            input.value = '';
            document.getElementById('add-tag')?.classList.remove('input-mode');
            qs<HTMLInputElement>('#search-tag .search-input')?.dispatchEvent(new Event('input'));
            loadState.reverse();
        })
        .catch((message: unknown) => {
            loadState.reverse();
            showError(message instanceof Error ? message.message : String(message));
        });
});

qs('#add-tag .icon-validate')?.addEventListener('click', () => {
    if (document.getElementById('add-tag')?.classList.contains('input-mode') === true) {
        (document.getElementById('add-tag') as HTMLFormElement | null)?.dispatchEvent(
            new Event('submit', { bubbles: true, cancelable: true })
        );
    }
});

function addTag(name: string): Promise<void> {
    return fetch(config.wsUrl + 'format=json&method=pwg.tags.add', {
        method: 'POST',
        body: new URLSearchParams({ name }),
    })
        .then((r) => r.json() as Promise<WsResponse<Tag>>)
        .then((data) => {
            if (data.stat === 'ok' && data.result !== undefined) {
                const tag = createTagBox(data.result.id, data.result.name, data.result.url_name, 0);
                qs('.tag-container')?.prepend(tag);
                setupTagbox(tag);
                updateSearchInfo();
                dataTags.unshift({
                    name: data.result.name,
                    raw_name: data.result.name,
                    id: data.result.id,
                    url_name: data.result.url_name,
                });
                updateBadge();
            } else {
                throw new Error(str_already_exist.replace('%s', name));
            }
        });
}

/*------- Setup Tag Box -------*/

function setupTagbox(tagBox: HTMLElement) {
    const q = (sel: string) => tagBox.querySelector<HTMLElement>(sel);

    q('.showOptions')?.addEventListener('click', () => {
        const block = q('.tag-dropdown-block');
        if (block) block.style.display = 'grid';
    });

    document.addEventListener('mouseup', (e: MouseEvent) => {
        e.stopPropagation();
        const target = e.target as Element;
        const optionClicked = qsa('.dropdown-option', tagBox).some((el) => el.contains(target));
        if (!optionClicked) hide(q('.tag-dropdown-block'));
    });

    tagBox.addEventListener('click', () => {
        if (qs('.tag-container')?.classList.contains('selection') === true) {
            const id = tagBox.dataset['id'] ?? '';
            if (tagBox.dataset['selected'] === '1') {
                tagBox.dataset['selected'] = '0';
                removeSelectedItem(id);
            } else {
                tagBox.dataset['selected'] = '1';
                addSelectedItem(id);
            }
            updateSelectionContent();
        }
    });

    q('.dropdown-option.edit')?.addEventListener('click', (e: Event) => {
        const id =
            (e.currentTarget as HTMLElement).closest<HTMLElement>('.tag-box')?.dataset['id'] ?? '';
        const tagIndex = dataTags.findIndex((tag) => String(tag.id) === id);
        if (tagIndex === -1) return;
        const tagRawName = dataTags[tagIndex]!.raw_name ?? q('.tag-name')?.dataset['rawname'] ?? '';
        const tagName = dataTags[tagIndex]!.name;
        set_up_popin(tagBox.dataset['id'] ?? '', tagRawName, tagName);
        rename_tag_open();
    });

    q('.dropdown-option.delete')?.addEventListener('click', () => {
        const tagName = q('.tag-name')?.innerHTML ?? '';
        if (window.confirm(str_delete.replace('%s', tagName))) {
            removeTag(tagBox.dataset['id'] ?? '', tagName);
        }
    });

    q('.dropdown-option.duplicate')?.addEventListener('click', () => {
        void duplicateTag(
            tagBox.dataset['id'] ?? '',
            q('.tag-name')?.dataset['rawname'] ?? ''
        ).then((d) => {
            if (d !== undefined) showMessage(str_tag_created.replace('%s', d.name));
        });
    });
}

function set_up_popin(id: TagId, tagRawName: string, tagName: string) {
    qs<HTMLElement>('.RenameTagPopInContainer .tag-property-input')!.id = String(id);
    qs('.AddIconTitle span')!.innerHTML = str_tag_rename.replace('%s', tagName);
    qsa('.ClosePopIn, .TagCancel').forEach((el) => {
        el.addEventListener('click', rename_tag_close);
    });
    qs('.TagSubmit')!.innerHTML = str_yes_rename_confirmation;
    qs<HTMLInputElement>('.RenameTagPopInContainer .tag-property-input')!.value = tagRawName;
}

function rename_tag_close() {
    hide(document.getElementById('RenameTag'));
}
function rename_tag_open() {
    show(document.getElementById('RenameTag'));
    qs<HTMLInputElement>('.tag-property-input')?.focus();
}

function cleanCheckmark() {
    qsa('.tag-changed > *').forEach((el) => el.parentElement?.replaceWith(el));
    qsa('.tag-checkmark').forEach((el) => el.remove());
}

function removeTag(id: TagId, name: string) {
    const body = new URLSearchParams({ method: 'pwg.tags.delete', tag_id: String(id), pwg_token });
    void fetch(config.wsUrl + 'format=json', { method: 'POST', body })
        .then((r) => r.json() as Promise<WsResponse>)
        .then((data) => {
            if (data.stat === 'ok') {
                qs(`.tag-box[data-id="${String(id)}"]`)?.remove();
                dataTags = dataTags.filter((tag) => tag.id !== id);
                showMessage(str_tag_deleted.replace('%s', name));
                updateBadge();
                updateSearchInfo();
                updatePaginationMenu();
            } else {
                showError('A problem has occured');
            }
        });
}

function renameTag(id: TagId, new_name: string): Promise<Tag | void> {
    const body = new URLSearchParams({
        method: 'pwg.tags.rename',
        tag_id: String(id),
        new_name,
        pwg_token,
    });
    return fetch(config.wsUrl + 'format=json', { method: 'POST', body })
        .then((r) => r.json() as Promise<WsResponse<Tag>>)
        .then((data) => {
            if (data.stat === 'ok' && data.result !== undefined) {
                const result = data.result;
                qsa(
                    `.tag-box[data-id="${String(id)}"] p, .tag-box[data-id="${String(id)}"] .tag-dropdown-header b`
                ).forEach((el) => {
                    el.innerHTML = result.name;
                });
                const editable = qs<HTMLInputElement>(
                    `.tag-box[data-id="${String(id)}"] .tag-name-editable`
                );
                if (editable) editable.value = result.name;
                const nameEl = qs(`.tag-box[data-id="${String(id)}"] .tag-name`);
                if (nameEl) nameEl.dataset['rawname'] = result.raw_name ?? result.name;
                const viewEl = qs<HTMLAnchorElement>('.dropdown-option.view');
                if (viewEl) viewEl.href = 'index.php?/tags/' + String(id) + '-' + result.url_name;
                const idx = dataTags.findIndex((tag) => tag.id === id);
                if (idx === -1) return result;
                dataTags[idx]!.name = result.name;
                dataTags[idx]!.raw_name = result.raw_name;
                dataTags[idx]!.url_name = result.url_name;
                return result;
            } else {
                throw new Error(str_already_exist.replace('%s', new_name));
            }
        });
}

function duplicateTag(id: TagId, name: string): Promise<Tag | undefined> {
    let copy_name = name + str_copy;
    const name_exist = (n: string) => qsa('.tag-box .tag-name').some((el) => el.innerHTML === n);
    let i = 1;
    while (name_exist(copy_name)) copy_name = name + str_other_copy.replace('%s', String(i++));

    const body = new URLSearchParams({
        method: 'pwg.tags.duplicate',
        tag_id: String(id),
        copy_name,
        pwg_token,
    });
    return fetch(config.wsUrl + 'format=json', { method: 'POST', body })
        .then((r) => r.json() as Promise<WsResponse<Tag & { count?: number }>>)
        .then((data) => {
            if (data.stat === 'ok' && data.result !== undefined) {
                const result = data.result;
                const tag = createTagBox(
                    result.id,
                    result.name,
                    result.url_name,
                    result.count ?? 0
                );
                const orig = qs(`.tag-box[data-id="${String(id)}"]`);
                orig?.after(tag);
                setupTagbox(tag);
                const idx = dataTags.findIndex((t) => t.id === id);
                dataTags.splice(idx + 1, 0, {
                    name: result.name,
                    id: result.id,
                    url_name: result.url_name,
                    counter: result.count,
                });
                updateBadge();
                updateSearchInfo();
                return result;
            }
            return undefined;
        });
}

/*------- Selection mode -------*/

let selected: TagId[] = [];

(document.getElementById('toggleSelectionMode') as HTMLInputElement).checked = false;
document
    .getElementById('toggleSelectionMode')
    ?.addEventListener('click', function (this: HTMLInputElement) {
        selectionMode(this.checked);
        qsa('.tag-info').forEach(hide);
    });

function selectionMode(isSelection: boolean) {
    if (isSelection) {
        qsa('.in-selection-mode').forEach((el) => el.classList.add('show'));
        qsa('.not-in-selection-mode').forEach((el) => el.classList.add('hide'));
        qs('.tag-container')?.classList.add('selection');
        qsa('.tag-box').forEach((el) => el.classList.remove('edit-name'));
    } else {
        qsa('.in-selection-mode').forEach((el) => el.classList.remove('show'));
        qsa('.not-in-selection-mode').forEach((el) => el.classList.remove('hide'));
        qs('.tag-container')?.classList.remove('selection');
        qsa('.tag-box').forEach((el) => (el.dataset['selected'] = '0'));
        hide(qs<HTMLElement>('.tag-select-message'));
        clearSelection();
    }
}

function clearSelection() {
    selected = [];
    const list = qs('.selection-mode-tag .tag-list');
    if (list) list.innerHTML = '';
    hide(qs('.selection-other-tags'));
    updateSelectionContent();
}

function addSelectedItem(id: TagId) {
    if (selected.includes(id)) return;
    selected.push(id);
    if (selected.length > maxItemDisplayed) {
        show(qs('.selection-other-tags'));
        const numDisplayed = qsa('.selection-mode-tag .tag-list div').length;
        const othersEl = qs('.selection-other-tags');
        if (othersEl)
            othersEl.innerHTML = str_and_others_tags.replace(
                '%s',
                String(selected.length - numDisplayed)
            );
    } else {
        hide(qs('.selection-other-tags'));
        const found = dataTags.find((tag) => String(tag.id) === String(id));
        if (found) createSelectionItem(id, found.name);
    }
}

function createSelectionItem(id: TagId, name: string) {
    const div = document.createElement('div');
    div.dataset['id'] = String(id);
    div.innerHTML = `<a class="icon-cancel"></a><p>${name}</p>`;
    qs('.selection-mode-tag .tag-list')?.prepend(div);
    div.querySelector('a')?.addEventListener('click', () => removeSelectedItem(id));
}

function removeSelectedItem(id: TagId) {
    if (!selected.includes(id)) return;
    selected = selected.filter((tag) => parseInt(String(tag)) !== parseInt(String(id)));
    const tagEl = qs<HTMLElement>(`.tag-box[data-id="${String(id)}"]`);
    if (tagEl) tagEl.dataset['selected'] = '0';

    const selItem = qs(`.selection-mode-tag .tag-list div[data-id="${String(id)}"]`);
    if (selItem) {
        selItem.remove();
        if (selected.length >= maxItemDisplayed) {
            let i = 0;
            let isNotCreate = true;
            while (i < selected.length && isNotCreate) {
                if (
                    qs(`.selection-mode-tag .tag-list div[data-id="${String(selected[i])}"]`) ===
                    null
                ) {
                    isNotCreate = false;
                    const indexOfTag = dataTags.findIndex((tag) => tag.id === selected[i]);
                    createSelectionItem(selected[i]!, dataTags[indexOfTag]!.name);
                }
                i++;
            }
        }
    }
    const numDisplayed = qsa('.selection-mode-tag .tag-list div').length;
    const othersEl = qs('.selection-other-tags');
    if (othersEl)
        othersEl.innerHTML = str_and_others_tags.replace(
            '%s',
            String(selected.length - numDisplayed)
        );
    if (selected.length - numDisplayed <= 0) hide(othersEl);
    hide(qs<HTMLElement>('.tag-select-message'));
}

function updateMergeItems() {
    const opts = qs('#MergeOptionsChoices');
    if (opts) opts.innerHTML = '';
    selected.forEach((id) => {
        const found = dataTags.find((tag) => tag.id === id);
        const opt = document.createElement('option');
        opt.value = String(id);
        opt.textContent = found?.name ?? '';
        qs('#MergeOptionsChoices')?.appendChild(opt);
    });
}

function updateSelectionContent() {
    const number = selected.length;
    const nothingSelected = document.getElementById('nothing-selected');
    const selectionMode_tag = qs('.selection-mode-tag');
    const mergeOpts = document.getElementById('MergeOptionsBlock');
    const mergeSel = document.getElementById('MergeSelectionMode');

    if (number === 0) {
        mergeOption = false;
        show(nothingSelected);
        hide(selectionMode_tag);
        hide(mergeOpts);
    } else if (number === 1) {
        mergeOption = false;
        hide(nothingSelected);
        show(selectionMode_tag);
        hide(mergeOpts);
        mergeSel?.classList.add('unavailable');
    } else {
        hide(nothingSelected);
        mergeSel?.classList.remove('unavailable');
        if (mergeOption) {
            show(mergeOpts);
            hide(selectionMode_tag);
            updateMergeItems();
        } else {
            hide(mergeOpts);
            show(selectionMode_tag);
        }
    }
}

document.getElementById('MergeSelectionMode')?.addEventListener('click', () => {
    mergeOption = true;
    updateSelectionContent();
});
document.getElementById('CancelMerge')?.addEventListener('click', () => {
    mergeOption = false;
    updateSelectionContent();
});

document.getElementById('selectAll')?.addEventListener('click', () => {
    void selectAll(tagToDisplay());
    updateSelectionContent();
    if (selected.length < dataTags.length) {
        showSelectMessage(
            str_selection_done.replace('%d', String(qsa('.tag-box').length)),
            str_select_all_tag.replace('%d', String(dataTags.length)),
            () => {
                const msgA = qs('.tag-select-message a');
                if (msgA) msgA.innerHTML = '';
                const msgDiv = qs('.tag-select-message div');
                if (msgDiv) msgDiv.innerHTML = "<i class='icon-spin6 animate-spin'> </i>";
                setTimeout(() => {
                    void selectAll(dataTags).then(() => {
                        updateSelectionContent();
                        showSelectMessage(
                            str_tag_selected.replace(/%d/g, String(selected.length)),
                            str_clear_selection,
                            () => {
                                selectNone();
                                hide(qs<HTMLElement>('.tag-select-message'));
                            }
                        );
                    });
                }, 5);
            }
        );
    }
});

function selectAll(dataArr: Tag[]): Promise<unknown[]> {
    const promises: Promise<void>[] = [];
    dataArr.forEach((tag) => {
        promises.push(
            new Promise<void>((res) => {
                const el = qs<HTMLElement>(`.tag-box[data-id="${String(tag.id)}"]`);
                if (el) el.dataset['selected'] = '1';
                addSelectedItem(tag.id);
                res();
            })
        );
    });
    return Promise.all(promises);
}

function showSelectMessage(str1: string, str2: string, callback: () => void) {
    const msg = qs<HTMLElement>('.tag-select-message')!;
    show(msg);
    const div = qs('.tag-select-message div');
    if (div) div.innerHTML = str1;
    const a = qs('.tag-select-message a');
    if (a) {
        a.innerHTML = str2;
    }
    const newA = qs('.tag-select-message a')!.cloneNode(true) as HTMLElement;
    qs('.tag-select-message a')!.replaceWith(newA);
    newA.addEventListener('click', callback);
}

document.getElementById('selectNone')?.addEventListener('click', () => {
    hide(qs<HTMLElement>('.tag-select-message'));
    selectNone();
});
function selectNone() {
    qsa('.tag-box').forEach((el) => {
        el.dataset['selected'] = '0';
    });
    clearSelection();
}

document.getElementById('selectInvert')?.addEventListener('click', () => {
    hide(qs<HTMLElement>('.tag-select-message'));
    selectInvert(tagToDisplay());
});
function selectInvert(dataArr: Tag[]) {
    dataArr.forEach((tag) => {
        const tagBox = qs<HTMLElement>(`.tag-box[data-id="${String(tag.id)}"]`);
        if (tagBox === null) return;
        if (tagBox.dataset['selected'] === '1') {
            tagBox.dataset['selected'] = '0';
            removeSelectedItem(tag.id);
        } else {
            tagBox.dataset['selected'] = '1';
            addSelectedItem(tag.id);
        }
    });
    updateSelectionContent();
}

/*------- Actions in selection mode -------*/

document.getElementById('DeleteSelectionMode')?.addEventListener('click', () => {
    const names: string[] = [];
    selected.forEach((id) => {
        const found = dataTags.find((t) => t.id === id);
        if (found) names.push(found.name);
    });
    if (!window.confirm(str_delete_tags.replace('%s', tagListToString(names)))) return;
    removeSelectedTags();
});

function removeSelectedTags() {
    const names: string[] = [];
    selected.forEach((id) => {
        const f = dataTags.find((t) => t.id === id);
        if (f) names.push(f.name);
    });
    const body = new URLSearchParams({ pwg_token });
    selected.forEach((id) => body.append('tag_id[]', String(id)));
    body.append('method', 'pwg.tags.delete');
    void fetch(config.wsUrl + 'format=json', { method: 'POST', body })
        .then((r) => r.text())
        .then((rawText) => {
            const raw_data = rawText.slice(rawText.search('{'));
            if ((JSON.parse(raw_data) as WsResponse).stat === 'ok') {
                selected.forEach((id) => qs(`.tag-box[data-id="${String(id)}"]`)?.remove());
                dataTags = dataTags.filter((tag) => !selected.includes(tag.id));
                clearSelection();
                updatePaginationMenu();
                updateBadge();
                updateSearchInfo();
                window.alert(str_tags_deleted.replace('%s', tagListToString(names)));
            }
        });
}

qs('.ConfirmMergeButton')?.addEventListener('click', () => {
    const dest_id = qs<HTMLSelectElement>('#MergeOptionsChoices')?.value ?? '';
    mergeGroups(dest_id, selected);
});

interface MergeResult {
    deleted_tag: TagId[];
    destination_tag: TagId;
    images_in_merged_tag: unknown[];
}

function mergeGroups(destination_id: TagId, merge_ids: TagId[]) {
    const destination_name =
        qs<HTMLElement>(`.tag-box[data-id="${String(destination_id)}"] .tag-name`)?.innerHTML ?? '';
    const merge_name = merge_ids.map(
        (id) => qs<HTMLElement>(`.tag-box[data-id="${String(id)}"] .tag-name`)?.innerHTML ?? ''
    );
    const str_message = str_merged_into
        .replace('%s1', tagListToString(merge_name))
        .replace('%s2', destination_name);

    const body = new URLSearchParams({
        pwg_token,
        destination_tag_id: String(destination_id),
        method: 'pwg.tags.merge',
    });
    merge_ids.forEach((id) => body.append('merge_tag_id[]', String(id)));
    void fetch(config.wsUrl + 'format=json', { method: 'POST', body })
        .then((r) => r.text())
        .then((rawText) => {
            const raw_data = rawText.slice(rawText.search('{'));
            const data = JSON.parse(raw_data) as WsResponse<MergeResult>;
            if (data.stat === 'ok' && data.result !== undefined) {
                const result = data.result;
                result.deleted_tag.forEach((id) => {
                    if (result.destination_tag !== id) {
                        qs(`.tag-box[data-id="${String(id)}"]`)?.remove();
                        dataTags = dataTags.filter((t) => id !== t.id);
                    }
                });
                if (result.images_in_merged_tag.length > 0) {
                    const tagBox = qs(`.tag-box[data-id="${String(result.destination_tag)}"]`);
                    if (tagBox !== null) {
                        qsa(
                            '.dropdown-option.view, .dropdown-option.manage, .tag-dropdown-header i',
                            tagBox
                        ).forEach((el) => show(el));
                        const hi = qs('.tag-dropdown-header i', tagBox);
                        if (hi)
                            hi.innerHTML = str_number_photos.replace(
                                '%d',
                                String(result.images_in_merged_tag.length)
                            );
                    }
                    const idx = dataTags.findIndex((t) => t.id === result.destination_tag);
                    if (idx !== -1) dataTags[idx]!.counter = result.images_in_merged_tag.length;
                }
                qsa('.tag-box').forEach((el) => {
                    el.dataset['selected'] = '0';
                });
                clearSelection();
                updatePaginationMenu();
                updateBadge();
                updateSearchInfo();
                window.alert(str_message);
            }
        });
}

function tagListToString(list: string[]) {
    if (list.length > 5)
        return (
            list.slice(0, 5).join(', ') +
            ' ' +
            str_and_others_tags.replace('%s', String(list.length - 5))
        );
    return list.join(', ');
}

/*------- Filter research -------*/

let searchTimeOut: ReturnType<typeof setTimeout> | null = null;
const delaySearchInput = 300;

qs<HTMLInputElement>('#search-tag .search-input')?.addEventListener(
    'input',
    function (this: HTMLInputElement) {
        actualPage = 1;
        if (searchTimeOut) clearTimeout(searchTimeOut);
        searchTimeOut = setTimeout(() => {
            updatePaginationMenu();
            hide(qs('.emptyResearch'));
            if (dataTags.filter(isDataSearched).length === 0) show(qs('.emptyResearch'));
        }, delaySearchInput);
    }
);

function isDataSearched(tagObj: Tag): boolean {
    const name = (tagObj.raw_name ?? tagObj.name).toLowerCase();
    const searchStr = qs<HTMLInputElement>('#search-tag .search-input')?.value ?? '';
    return name.includes(searchStr.toLowerCase());
}

/*------- Show Info -------*/
function showError(message: string) {
    const errEl = qs<HTMLElement>('.info-error');
    if (errEl) {
        errEl.querySelector('p')!.innerHTML = message;
        errEl.title = message;
        errEl.style.display = 'flex';
    }
    hide(qs('.info-info'));
}
function showMessage(message: string) {
    const msgEl = qs<HTMLElement>('.info-message');
    if (msgEl) {
        msgEl.querySelector('p')!.innerHTML = message;
        msgEl.title = message;
        msgEl.style.display = 'flex';
    }
    hide(qs('.info-info'));
}

/*------- Pagination -------*/

let per_page = parseInt(qs('.tag-container')?.dataset['perPage'] ?? '20');
const pageItem = '<a data-page="%d">%d</a>';
const pageEllipsis = '<span>...</span>';
let promisePending = false;
let updateAsk = false;
let actualPage = 1;

function askUpdatePage() {
    if (!promisePending) {
        promisePending = true;
        void updatePage().then(promiseFinish);
    } else updateAsk = true;
}
function promiseFinish() {
    promisePending = false;
    if (updateAsk) {
        updateAsk = false;
        askUpdatePage();
    }
}

function updatePaginationMenu() {
    const container = qs('.pagination-item-container');
    if (container) container.innerHTML = '';
    actualPage = Math.min(actualPage, getNumberPages());
    if (getNumberPages() > 1) {
        show(qs('.pagination-container'));
        createPaginationMenu();
    } else hide(qs('.pagination-container'));
    updateArrows();
    askUpdatePage();
    hide(qs<HTMLElement>('.tag-select-message'));
}

function createPaginationMenu() {
    const nbPage = getNumberPages();
    appendPaginationItem(1);
    if (actualPage > 2) appendPaginationItem();
    if (actualPage !== 1 && actualPage !== nbPage) appendPaginationItem(actualPage);
    if (actualPage < nbPage - 1) appendPaginationItem();
    appendPaginationItem(nbPage);
}

function appendPaginationItem(page: number | null = null) {
    const container = qs('.pagination-item-container')!;
    if (page !== null) {
        const div = document.createElement('div');
        div.innerHTML = pageItem.replace(/%d/g, String(page));
        const el = div.firstElementChild as HTMLElement;
        if (actualPage === page) el.classList.add('actual');
        el.addEventListener('click', () => {
            actualPage = parseInt(el.dataset['page'] ?? '1');
            updatePaginationMenu();
        });
        container.appendChild(el);
    } else {
        container.insertAdjacentHTML('beforeend', pageEllipsis);
    }
}

function updateArrows() {
    qs('.pagination-arrow.left')?.classList.toggle('unavailable', actualPage === 1);
    qs('.pagination-arrow.rigth')?.classList.toggle('unavailable', actualPage === getNumberPages());
}

function getNumberPages(): number {
    const dataVisible = dataTags.filter(isDataSearched).length;
    return Math.max(1, Math.floor((dataVisible - 1) / per_page) + 1);
}

function movePage(toRight = true) {
    qsa('.tag-box').forEach((el) => el.classList.remove('edit-name'));
    if (toRight && actualPage < getNumberPages()) {
        actualPage++;
        updatePaginationMenu();
    } else if (!toRight && actualPage > 1) {
        actualPage--;
        updatePaginationMenu();
    }
}

async function updatePage(): Promise<void> {
    const dataToDisplay = tagToDisplay();
    const tagBoxEls = qsa('.tag-box');
    cleanCheckmark();

    show(qs('.pageLoad'));
    tagBoxEls.forEach((el) => {
        el.style.transition = 'opacity 0.5s';
        el.style.opacity = '0';
    });
    await new Promise((resolve) => setTimeout(resolve, 500));

    const boxToRecycle = Math.min(dataToDisplay.length, tagBoxEls.length);
    for (let i = 0; i < boxToRecycle; i++) {
        const tag = dataToDisplay[i]!;
        recycleTagBox(
            tagBoxEls[i]!,
            tag.id,
            tag.name,
            tag.url_name,
            tag.counter ?? 0,
            tag.raw_name ?? null
        );
    }
    if (dataToDisplay.length < tagBoxEls.length) {
        for (let j = boxToRecycle; j < tagBoxEls.length; j++) tagBoxEls[j]!.remove();
    } else if (dataToDisplay.length > tagBoxEls.length) {
        for (let j = boxToRecycle; j < dataToDisplay.length; j++) {
            const tag = dataToDisplay[j]!;
            const newTag = createTagBox(
                tag.id,
                tag.name,
                tag.url_name,
                tag.counter ?? 0,
                tag.raw_name ?? null
            );
            newTag.style.opacity = '0';
            qs('.tag-container')?.appendChild(newTag);
            setupTagbox(newTag);
        }
    }
    selected.forEach((id) => {
        const el = qs<HTMLElement>(`.tag-box[data-id="${String(id)}"]`);
        if (el) el.dataset['selected'] = '1';
    });

    hide(qs('.pageLoad'));
    qsa('.tag-box').forEach((el) => {
        el.style.transition = 'opacity 0.5s';
        el.style.opacity = '1';
    });
    if (getNumberPages() > 1)
        qsa('.tag-pagination').forEach((el) => {
            el.style.transition = 'opacity 0.5s';
            el.style.opacity = '1';
        });
    updateSearchInfo();
}

function tagToDisplay(): Tag[] {
    return dataTags
        .filter(isDataSearched)
        .slice((actualPage - 1) * per_page, actualPage * per_page);
}

qs('.pagination-arrow.rigth')?.addEventListener('click', () => movePage());
qs('.pagination-arrow.left')?.addEventListener('click', () => movePage(false));

if (getNumberPages() > 1) {
    show(qs('.pagination-container'));
    createPaginationMenu();
    updateArrows();
} else hide(qs('.pagination-container'));

qsa('.pagination-per-page a').forEach((a) => {
    a.addEventListener('click', function (this: HTMLElement) {
        per_page = parseInt(this.innerHTML);
        updatePaginationMenu();
        qsa('.pagination-per-page .selected').forEach((el) => el.classList.remove('selected'));
        this.classList.add('selected');
        Cookies.set('pwg_tags_per_page', String(per_page));
    });
});

function updateSearchInfo() {
    const searchEl = qs<HTMLInputElement>('.search-input');
    const infoEl = qs('.search-info');
    if (!infoEl) return;
    if (searchEl?.value !== undefined && searchEl.value !== '') {
        const n = dataTags.filter(isDataSearched).length;
        infoEl.innerHTML =
            n > 1
                ? str_tags_found.replace('%d', String(n))
                : str_tag_found.replace('%d', String(n));
    } else {
        infoEl.innerHTML = '';
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const savedPerPage = Cookies.get('pwg_tags_per_page');
    qsa('.pagination-per-page .selected').forEach((el) => el.classList.remove('selected'));
    if (savedPerPage !== undefined && savedPerPage !== '')
        document.getElementById(savedPerPage)?.click();
});

export {};
