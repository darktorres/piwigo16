import Cookies from 'js-cookie';
import { getPageData } from './page-data';

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
    total,
    orphan_tag_names,
    str_already_exist,
    str_and_others_tags,
    str_clear_selection,
    str_copy,
    str_delete,
    str_delete_orphan_tags,
    str_delete_tags,
    str_delete_them,
    str_keep_them,
    str_merged_into,
    str_no_delete_confirmation,
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
    str_yes_delete_confirmation,
    str_yes_rename_confirmation,
} = getPageData<TagsPageData>();

// mutable state
let number: any;
let $tagboxid: any;
let boxToRecycle: any;
let copy_name: any;
let data: any;
let dataToDisplay: any;
let dataVisible: any;
let dest_id: any;
let destination_name: any;
let index: any;
let indexOfTag: any;
let isNotCreate: any;
let loadState: any;
const maxItemDisplayed = 5;
let mergeOption: any = false;
let merge_name: any;
let nbPage: any;
let newPage: any;
let newTag: any;
let promises: any;
let str_message: any;
let tagBox: any;

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

let dataTags: any[] = JSON.parse(qs('.tag-container')?.dataset['tags'] ?? '[]');

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
    id: any,
    name: any,
    url_name: any,
    count: any,
    raw_name: any = null
): HTMLElement {
    if (raw_name === null) raw_name = name;
    const u_edit = 'admin.php?page=batch_manager&filter=tag-' + id;
    const u_view = 'index.php?/tags/' + id + '-' + url_name;
    let html = qs('.tag-template')!
        .innerHTML.replace(/%name%/g, unescape(String(name)))
        .replace('%U_VIEW%', u_view)
        .replace('%U_EDIT%', u_edit)
        .replace('%raw_name%', raw_name);
    if (name == raw_name) html = html.replace('icon-globe', '');

    const div = document.createElement('div');
    div.className = 'tag-box test';
    div.dataset['id'] = String(id);
    div.dataset['selected'] = '0';
    div.innerHTML = html;

    if ((document.getElementById('toggleSelectionMode') as HTMLInputElement)?.checked) {
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
        if (headerI) headerI.innerHTML = str_number_photos.replace('%d', count);
    } else {
        if (headerI) headerI.innerHTML = str_no_photos;
    }
    return div;
}

function recycleTagBox(
    tagBox: HTMLElement,
    id: any,
    name: any,
    url_name: any,
    count: any,
    raw_name: any = null
) {
    if (raw_name === null) raw_name = name;
    tagBox.dataset['id'] = String(id);
    qsa('.tag-name, .tag-dropdown-header b', tagBox).forEach((el) => {
        el.innerHTML = name;
    });
    const editable = qs<HTMLInputElement>('.tag-name-editable', tagBox);
    if (editable) editable.value = name;
    tagBox.dataset['selected'] = '0';
    const nameEl = qs('.tag-name', tagBox);
    if (nameEl) nameEl.dataset['rawname'] = raw_name;
    const viewEl = qs<HTMLAnchorElement>('.dropdown-option.view', tagBox);
    if (viewEl) viewEl.href = 'index.php?/tags/' + id + '-' + url_name;
    const manageEl = qs<HTMLAnchorElement>('.dropdown-option.manage', tagBox);
    if (manageEl) manageEl.href = 'admin.php?page=batch_manager&filter=tag-' + id;
    if (count > 0) {
        qsa('.dropdown-option.view, .dropdown-option.manage', tagBox).forEach((el) => {
            el.style.display = 'block';
        });
        const hi = qs('.tag-dropdown-header i', tagBox);
        if (hi) hi.innerHTML = str_number_photos.replace('%d', count);
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
        $tagboxid = qs<HTMLElement>('.RenameTagPopInContainer .tag-property-input')!.id;
        renameTag(
            $tagboxid,
            qs<HTMLInputElement>('.RenameTagPopInContainer .tag-property-input')!.value
        )
            .then(() => {
                if (submitBtn) show(submitBtn);
                if (loadingBtn) hide(loadingBtn);
                rename_tag_close();
                cleanCheckmark();
                const el = qs(`[data-id="${$tagboxid}"]`)!;
                const wrapper = document.createElement('div');
                wrapper.className = 'tag-changed';
                el.parentNode?.insertBefore(wrapper, el);
                wrapper.appendChild(el);
                const checkmark = document.createElement('i');
                checkmark.className = 'icon-ok tag-checkmark';
                wrapper.prepend(checkmark);
            })
            .catch((message: any) => {
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
    loadState = new (window as any).TemporaryState();
    const iconValidate = qs<HTMLElement>('#add-tag .icon-validate')!;
    loadState.removeClass(iconValidate, 'icon-plus');
    loadState.changeHTML(iconValidate, "<i class='icon-spin6 animate-spin'> </i>");
    loadState.changeAttribute(iconValidate, 'style', 'pointer-event:none');
    addTag(input.value)
        .then(() => {
            showMessage(str_tag_created.replace('%s', input.value));
            input.value = '';
            document.getElementById('add-tag')?.classList.remove('input-mode');
            qs<HTMLInputElement>('#search-tag .search-input')?.dispatchEvent(new Event('input'));
            loadState.reverse();
        })
        .catch((message: any) => {
            loadState.reverse();
            showError(message);
        });
});

qs('#add-tag .icon-validate')?.addEventListener('click', () => {
    if (document.getElementById('add-tag')?.classList.contains('input-mode')) {
        (document.getElementById('add-tag') as HTMLFormElement)?.dispatchEvent(
            new Event('submit', { bubbles: true, cancelable: true })
        );
    }
});

function addTag(name: any): Promise<any> {
    return fetch('ws.php?format=json&method=pwg.tags.add', {
        method: 'POST',
        body: new URLSearchParams({ name }),
    })
        .then((r) => r.json())
        .then((rawData) => {
            data = rawData;
            if (data.stat === 'ok') {
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
                throw str_already_exist.replace('%s', name);
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
        if (qs('.tag-container')?.classList.contains('selection')) {
            const id = tagBox.dataset['id'];
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
        const id = (e.currentTarget as HTMLElement).closest<HTMLElement>('.tag-box')?.dataset['id'];
        const tagIndex = dataTags.findIndex((tag: any) => tag.id == id);
        const tagRawName = dataTags[tagIndex].raw_name ?? q('.tag-name')?.dataset['rawname'];
        const tagName = dataTags[tagIndex].name ?? q('.tag-name')?.innerHTML;
        set_up_popin(tagBox.dataset['id'], tagRawName, tagName);
        rename_tag_open();
    });

    q('.dropdown-option.delete')?.addEventListener('click', () => {
        const tagName = q('.tag-name')?.innerHTML ?? '';
        if (window.confirm(str_delete.replace('%s', tagName))) {
            removeTag(tagBox.dataset['id'], tagName);
        }
    });

    q('.dropdown-option.duplicate')?.addEventListener('click', () => {
        duplicateTag(tagBox.dataset['id'], q('.tag-name')?.dataset['rawname']).then((d: any) => {
            showMessage(str_tag_created.replace('%s', d.result.name));
        });
    });
}

function set_up_popin(id: any, tagRawName: any, tagName: any) {
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

function removeTag(id: any, name: any) {
    const body = new URLSearchParams({ method: 'pwg.tags.delete', tag_id: id, pwg_token });
    fetch('ws.php?format=json', { method: 'POST', body })
        .then((r) => r.json())
        .then((rawData) => {
            data = rawData;
            if (data.stat === 'ok') {
                qs(`.tag-box[data-id="${id}"]`)?.remove();
                dataTags = dataTags.filter((tag: any) => tag.id != id);
                showMessage(str_tag_deleted.replace('%s', name));
                updateBadge();
                updateSearchInfo();
                updatePaginationMenu();
            } else {
                showError('A problem has occured');
            }
        });
}

function renameTag(id: any, new_name: any): Promise<any> {
    const body = new URLSearchParams({
        method: 'pwg.tags.rename',
        tag_id: id,
        new_name,
        pwg_token,
    });
    return fetch('ws.php?format=json', { method: 'POST', body })
        .then((r) => r.json())
        .then((rawData) => {
            data = rawData;
            if (data.stat === 'ok') {
                qsa(
                    `.tag-box[data-id="${id}"] p, .tag-box[data-id="${id}"] .tag-dropdown-header b`
                ).forEach((el) => {
                    el.innerHTML = data.result.name;
                });
                const editable = qs<HTMLInputElement>(
                    `.tag-box[data-id="${id}"] .tag-name-editable`
                );
                if (editable) editable.value = data.result.name;
                const nameEl = qs(`.tag-box[data-id="${id}"] .tag-name`);
                if (nameEl) nameEl.dataset['rawname'] = data.result.raw_name;
                const viewEl = qs<HTMLAnchorElement>('.dropdown-option.view');
                if (viewEl) viewEl.href = 'index.php?/tags/' + id + '-' + data.result.url_name;
                index = dataTags.findIndex((tag: any) => tag.id == id);
                dataTags[index].name = data.result.name;
                dataTags[index].raw_name = data.result.raw_name;
                dataTags[index].url_name = data.result.url_name;
                return data;
            } else {
                throw str_already_exist.replace('%s', new_name);
            }
        });
}

function duplicateTag(id: any, name: any): Promise<any> {
    copy_name = name + str_copy;
    const name_exist = (n: string) => qsa('.tag-box .tag-name').some((el) => el.innerHTML === n);
    let i = 1;
    while (name_exist(copy_name)) copy_name = name + str_other_copy.replace('%s', String(i++));

    const body = new URLSearchParams({
        method: 'pwg.tags.duplicate',
        tag_id: id,
        copy_name,
        pwg_token,
    });
    return fetch('ws.php?format=json', { method: 'POST', body })
        .then((r) => r.json())
        .then((rawData) => {
            data = rawData;
            if (data.stat === 'ok') {
                const tag = createTagBox(
                    data.result.id,
                    data.result.name,
                    data.result.url_name,
                    data.result.count
                );
                const orig = qs(`.tag-box[data-id="${id}"]`);
                orig?.after(tag);
                setupTagbox(tag);
                index = dataTags.findIndex((t: any) => t.id == id);
                dataTags.splice(index + 1, 0, {
                    name: data.result.name,
                    id: data.result.id,
                    url_name: data.result.url_name,
                    counter: data.result.count,
                });
                updateBadge();
                updateSearchInfo();
                return data;
            }
        });
}

/*------- Selection mode -------*/

let selected: any[] = [];

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

function addSelectedItem(id: any) {
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
        const found = dataTags.find((tag: any) => tag.id == id);
        if (found) createSelectionItem(id, found.name);
    }
}

function createSelectionItem(id: any, name: any) {
    const div = document.createElement('div');
    div.dataset['id'] = String(id);
    div.innerHTML = `<a class="icon-cancel"></a><p>${name}</p>`;
    qs('.selection-mode-tag .tag-list')?.prepend(div);
    div.querySelector('a')?.addEventListener('click', () => removeSelectedItem(id));
}

function removeSelectedItem(id: any) {
    if (!selected.find((tag: any) => tag == id)) return;
    selected = selected.filter((tag: any) => parseInt(tag) !== parseInt(id));
    const tagEl = qs<HTMLElement>(`.tag-box[data-id="${id}"]`);
    if (tagEl) tagEl.dataset['selected'] = '0';

    const selItem = qs(`.selection-mode-tag .tag-list div[data-id="${id}"]`);
    if (selItem) {
        selItem.remove();
        if (selected.length >= maxItemDisplayed) {
            let i = 0;
            isNotCreate = true;
            while (i < selected.length && isNotCreate) {
                if (!qs(`.selection-mode-tag .tag-list div[data-id="${selected[i]}"]`)) {
                    isNotCreate = false;
                    indexOfTag = dataTags.findIndex((tag: any) => tag.id == selected[i]);
                    createSelectionItem(selected[i], dataTags[indexOfTag].name);
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
    selected.forEach((id: any) => {
        const found = dataTags.find((tag: any) => tag.id == id);
        const opt = document.createElement('option');
        opt.value = String(id);
        opt.textContent = found?.name ?? '';
        qs('#MergeOptionsChoices')?.appendChild(opt);
    });
}

function updateSelectionContent() {
    number = selected.length;
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
    selectAll(tagToDisplay());
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
                    selectAll(dataTags).then(() => {
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

function selectAll(dataArr: any[]): Promise<any> {
    promises = [];
    dataArr.forEach((tag: any) => {
        promises.push(
            new Promise<any>((res) => {
                const el = qs<HTMLElement>(`.tag-box[data-id="${tag.id}"]`);
                if (el) el.dataset['selected'] = '1';
                addSelectedItem(tag.id);
                res(undefined);
            })
        );
    });
    return Promise.all(promises);
}

function showSelectMessage(str1: any, str2: any, callback: any) {
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
function selectInvert(dataArr: any[]) {
    dataArr.forEach((tag: any) => {
        tagBox = qs(`.tag-box[data-id="${tag.id}"]`);
        if (tagBox?.dataset['selected'] == '1') {
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
    selected.forEach((id: any) => {
        const found = dataTags.find((t: any) => t.id == id);
        if (found) names.push(found.name);
    });
    if (!window.confirm(str_delete_tags.replace('%s', tagListToString(names)))) return;
    removeSelectedTags();
});

function removeSelectedTags() {
    const names: string[] = [];
    selected.forEach((id: any) => {
        const f = dataTags.find((t: any) => t.id == id);
        if (f) names.push(f.name);
    });
    const body = new URLSearchParams({ pwg_token });
    selected.forEach((id: any) => body.append('tag_id[]', id));
    body.append('method', 'pwg.tags.delete');
    fetch('ws.php?format=json', { method: 'POST', body })
        .then((r) => r.text())
        .then((rawText) => {
            const raw_data = rawText.slice(rawText.search('{'));
            if (JSON.parse(raw_data).stat === 'ok') {
                selected.forEach((id: any) => qs(`.tag-box[data-id="${id}"]`)?.remove());
                dataTags = dataTags.filter((tag: any) => !selected.includes(tag.id));
                clearSelection();
                updatePaginationMenu();
                updateBadge();
                updateSearchInfo();
                window.alert(str_tags_deleted.replace('%s', tagListToString(names)));
            }
        });
}

qs('.ConfirmMergeButton')?.addEventListener('click', () => {
    dest_id = qs<HTMLSelectElement>('#MergeOptionsChoices')?.value;
    mergeGroups(dest_id, selected);
});

function mergeGroups(destination_id: any, merge_ids: any[]) {
    destination_name =
        qs<HTMLElement>(`.tag-box[data-id="${destination_id}"] .tag-name`)?.innerHTML ?? '';
    merge_name = merge_ids.map(
        (id: any) => qs<HTMLElement>(`.tag-box[data-id="${id}"] .tag-name`)?.innerHTML ?? ''
    );
    str_message = str_merged_into
        .replace('%s1', tagListToString(merge_name))
        .replace('%s2', destination_name);

    const body = new URLSearchParams({
        pwg_token,
        destination_tag_id: destination_id,
        method: 'pwg.tags.merge',
    });
    merge_ids.forEach((id: any) => body.append('merge_tag_id[]', id));
    fetch('ws.php?format=json', { method: 'POST', body })
        .then((r) => r.text())
        .then((rawText) => {
            const raw_data = rawText.slice(rawText.search('{'));
            data = JSON.parse(raw_data);
            if (data.stat === 'ok') {
                data.result.deleted_tag.forEach((id: any) => {
                    if (data.result.destination_tag != id) {
                        qs(`.tag-box[data-id="${id}"]`)?.remove();
                        dataTags = dataTags.filter((t: any) => id != t.id);
                    }
                });
                if (data.result.images_in_merged_tag.length > 0) {
                    tagBox = qs(`.tag-box[data-id="${data.result.destination_tag}"]`);
                    qsa(
                        '.dropdown-option.view, .dropdown-option.manage, .tag-dropdown-header i',
                        tagBox
                    ).forEach((el) => show(el));
                    const hi = qs('.tag-dropdown-header i', tagBox);
                    if (hi)
                        hi.innerHTML = str_number_photos.replace(
                            '%d',
                            data.result.images_in_merged_tag.length
                        );
                    index = dataTags.findIndex((t: any) => t.id == data.result.destination_tag);
                    dataTags[index].counter = data.result.images_in_merged_tag.length;
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

function tagListToString(list: any[]) {
    if (list.length > 5)
        return (
            list.slice(0, 5).join(', ') +
            ' ' +
            str_and_others_tags.replace('%s', String(list.length - 5))
        );
    return list.join(', ');
}

/*------- Filter research -------*/

const maxShown = 100;
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

function isDataSearched(tagObj: any): boolean {
    const name = tagObj.raw_name.toLowerCase();
    const searchStr = qs<HTMLInputElement>('#search-tag .search-input')?.value ?? '';
    return name.includes(searchStr.toLowerCase());
}

/*------- Show Info -------*/
function showError(message: any) {
    const errEl = qs<HTMLElement>('.info-error');
    if (errEl) {
        errEl.querySelector('p')!.innerHTML = message;
        errEl.title = message;
        errEl.style.display = 'flex';
    }
    hide(qs('.info-info'));
}
function showMessage(message: any) {
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
        updatePage().then(promiseFinish);
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
    nbPage = getNumberPages();
    appendPaginationItem(1);
    if (actualPage > 2) appendPaginationItem();
    if (actualPage != 1 && actualPage != nbPage) appendPaginationItem(actualPage);
    if (actualPage < nbPage - 1) appendPaginationItem();
    appendPaginationItem(nbPage);
}

function appendPaginationItem(page: any = null) {
    const container = qs('.pagination-item-container')!;
    if (page != null) {
        const div = document.createElement('div');
        div.innerHTML = pageItem.replace(/%d/g, String(page));
        const el = div.firstElementChild as HTMLElement;
        if (actualPage == page) el.classList.add('actual');
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
    qs('.pagination-arrow.left')?.classList.toggle('unavailable', actualPage == 1);
    qs('.pagination-arrow.rigth')?.classList.toggle('unavailable', actualPage == getNumberPages());
}

function getNumberPages(): number {
    dataVisible = dataTags.filter(isDataSearched).length;
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
    newPage = actualPage;
    dataToDisplay = tagToDisplay();
    const tagBoxEls = qsa('.tag-box');
    cleanCheckmark();

    show(qs('.pageLoad'));
    tagBoxEls.forEach((el) => {
        el.style.transition = 'opacity 0.5s';
        el.style.opacity = '0';
    });
    await new Promise((resolve) => setTimeout(resolve, 500));

    boxToRecycle = Math.min(dataToDisplay.length, tagBoxEls.length);
    for (let i = 0; i < boxToRecycle; i++) {
        const tag = dataToDisplay[i];
        recycleTagBox(tagBoxEls[i], tag.id, tag.name, tag.url_name, tag.counter, tag.raw_name);
    }
    if (dataToDisplay.length < tagBoxEls.length) {
        for (let j = boxToRecycle; j < tagBoxEls.length; j++) tagBoxEls[j].remove();
    } else if (dataToDisplay.length > tagBoxEls.length) {
        for (let j = boxToRecycle; j < dataToDisplay.length; j++) {
            const tag = dataToDisplay[j];
            newTag = createTagBox(tag.id, tag.name, tag.url_name, tag.counter, tag.raw_name);
            newTag.style.opacity = '0';
            qs('.tag-container')?.appendChild(newTag);
            setupTagbox(newTag);
        }
    }
    selected.forEach((id: any) => {
        const el = qs<HTMLElement>(`.tag-box[data-id="${id}"]`);
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

function tagToDisplay(): any[] {
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
    if (searchEl?.value) {
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
    if (savedPerPage) document.getElementById(savedPerPage)?.click();
});

export {};
