import { initModule } from './moduleInit.js';
import { pwgConfirm } from './pwgConfirm.js';
import { TemporaryState } from './common.js';

interface TagsConfig {
    orphan_tag_names: string[];
    total_tags: number;
    pwg_token: string;
    str_delete: string;
    str_delete_tags: string;
    str_yes_delete_confirmation: string;
    str_no_delete_confirmation: string;
    str_yes_rename_confirmation: string;
    str_tag_deleted: string;
    str_tags_deleted: string;
    str_already_exist: string;
    str_tag_created: string;
    str_tag_renamed: string;
    str_tag_rename: string;
    str_delete_orphan_tags: string;
    str_orphan_tags: string;
    str_delete_them: string;
    str_keep_them: string;
    str_copy: string;
    str_other_copy: string;
    str_merged_into: string;
    str_and_others_tags: string;
    str_others_tags_available: string;
    str_number_photos: string;
    str_no_photos: string;
    str_select_all_tag: string;
    str_clear_selection: string;
    str_selection_done: string;
    str_tag_selected: string;
    str_tags_found: string;
    str_tag_found: string;
}

interface TagData {
    id: string | number;
    name: string;
    raw_name: string;
    url_name: string;
    counter?: number;
    count?: number;
}

export function init(cfg: TagsConfig): void {
    const {
        orphan_tag_names, total_tags, pwg_token, str_delete, str_delete_tags,
        str_yes_delete_confirmation, str_no_delete_confirmation, str_yes_rename_confirmation,
        str_tag_deleted, str_already_exist, str_tag_created, str_tag_rename,
        str_delete_orphan_tags, str_orphan_tags, str_delete_them, str_keep_them,
        str_copy, str_other_copy, str_merged_into, str_and_others_tags,
        str_number_photos, str_no_photos, str_select_all_tag, str_clear_selection,
        str_selection_done, str_tag_selected, str_tags_found, str_tag_found,
    } = cfg;

    const _tagContainer = document.querySelector<HTMLElement>('.tag-container');
    let dataTags: TagData[] = _tagContainer?.dataset['tags']
        ? (JSON.parse(_tagContainer.dataset['tags']) as TagData[])
        : [];

    const _sel100 = document.getElementById('select-100') as HTMLInputElement | null;
    if (_sel100) _sel100.checked = true;

    document.querySelector('.info-warning p a')?.addEventListener('click', () => {
        const url = (document.querySelector<HTMLAnchorElement>('.info-warning p a'))?.dataset['url'] ?? '';
        const tags = orphan_tag_names;
        const str_orphans = str_orphan_tags
            .replace('%s1', String(tags.length))
            .replace('%s2', tags.join(', '));
        pwgConfirm({
            content: str_orphans,
            title: str_delete_orphan_tags,
            buttons: {
                delete: {
                    text: str_delete_them,
                    btnClass: 'btn-red',
                    action: function () {
                        window.location.href = url.replace(/amp;/g, '');
                    },
                },
                keep: {
                    text: str_keep_them,
                    action: function () {
                        const warning = document.querySelector<HTMLElement>('.info-warning');
                        if (warning) warning.style.display = 'none';
                    },
                },
            },
        });
    });

    function createTagBox(id: string | number, name: string, url_name: string, count: number, raw_name: string | null = null): HTMLElement {
        if (raw_name === null) raw_name = name;
        const u_edit = 'admin.php?page=batch_manager&filter=tag-' + String(id);
        const u_view = 'index.php?/tags/' + String(id) + '-' + url_name;
        const templateEl = document.querySelector('.tag-template');
        let html = templateEl ? templateEl.innerHTML : '';
        html = html
            .replace(/%name%/g, unescape(name))
            .replace('%U_VIEW%', u_view)
            .replace('%U_EDIT%', u_edit)
            .replace('%raw_name%', raw_name);
        if (name === raw_name) html = html.replace('icon-globe', '');
        const div = document.createElement('div');
        div.className = 'tag-box test';
        div.setAttribute('data-id', String(id));
        div.setAttribute('data-selected', '0');
        div.innerHTML = html;

        const toggleEl = document.getElementById('toggleSelectionMode') as HTMLInputElement | null;
        if (toggleEl && toggleEl.checked) {
            div.classList.add('selection');
            const inSelection = div.querySelector<HTMLElement>('.in-selection-mode');
            if (inSelection) inSelection.style.display = '';
        }
        if (count > 0) {
            div.querySelectorAll<HTMLElement>('.dropdown-option.view, .dropdown-option.manage').forEach(el => { el.style.display = 'block'; });
            const headerI = div.querySelector<HTMLElement>('.tag-dropdown-header i');
            if (headerI) headerI.innerHTML = str_number_photos.replace('%d', String(count));
        } else {
            const headerI = div.querySelector<HTMLElement>('.tag-dropdown-header i');
            if (headerI) headerI.innerHTML = str_no_photos;
        }
        return div;
    }

    function recycleTagBox(tagBox: HTMLElement, id: string | number, name: string, url_name: string, count: number | undefined, raw_name: string | null = null): void {
        if (raw_name === null) raw_name = name;
        tagBox.setAttribute('data-id', String(id));
        tagBox.querySelectorAll<HTMLElement>('.tag-name, .tag-dropdown-header b').forEach(el => { el.innerHTML = name; });
        const editableEl = tagBox.querySelector<HTMLInputElement>('.tag-name-editable');
        if (editableEl) editableEl.value = name;
        tagBox.setAttribute('data-selected', '0');
        const tagNameEl = tagBox.querySelector<HTMLElement>('.tag-name');
        if (tagNameEl) tagNameEl.dataset['rawname'] = raw_name;

        const u_edit = 'admin.php?page=batch_manager&filter=tag-' + String(id);
        const u_view = 'index.php?/tags/' + String(id) + '-' + url_name;
        const viewLink = tagBox.querySelector<HTMLAnchorElement>('.dropdown-option.view');
        const manageLink = tagBox.querySelector<HTMLAnchorElement>('.dropdown-option.manage');
        if (viewLink) viewLink.href = u_view;
        if (manageLink) manageLink.href = u_edit;

        if (count && count > 0) {
            tagBox.querySelectorAll<HTMLElement>('.dropdown-option.view, .dropdown-option.manage').forEach(el => { el.style.display = 'block'; });
            const headerI = tagBox.querySelector<HTMLElement>('.tag-dropdown-header i');
            if (headerI) headerI.innerHTML = str_number_photos.replace('%d', String(count));
        } else {
            const headerI = tagBox.querySelector<HTMLElement>('.tag-dropdown-header i');
            if (headerI) headerI.innerHTML = str_no_photos;
        }
    }

    function updateBadge(): void {
        const badgeEl = document.querySelector<HTMLElement>('.badge-number');
        if (badgeEl) badgeEl.innerHTML = String(dataTags.length);
        const labelEl = document.querySelector<HTMLElement>('.tag-header #add-tag .add-tag-label');
        if (dataTags.length === 0) {
            if (labelEl) labelEl.classList.add('highlight');
        } else {
            if (labelEl) labelEl.classList.remove('highlight');
        }
    }

    document.querySelector('.add-tag-container')?.addEventListener('click', function () {
        document.getElementById('add-tag')?.classList.add('input-mode');
        (document.getElementById('add-tag-input') as HTMLInputElement | null)?.focus();
        const tagInfo = document.querySelector<HTMLElement>('.tag-info');
        if (tagInfo) tagInfo.style.display = 'none';
    });

    document.querySelector('#add-tag .icon-cancel-circled')?.addEventListener('click', function () {
        document.getElementById('add-tag')?.classList.remove('input-mode');
        const tagInfo = document.querySelector<HTMLElement>('.tag-info');
        if (tagInfo) tagInfo.style.display = 'none';
    });

    document.querySelectorAll<HTMLElement>('.tag-box').forEach(function (el) {
        setupTagbox(el);
    });

    document.querySelector('.TagSubmit')?.addEventListener('click', function () {
        const submitBtn = document.querySelector<HTMLElement>('.TagSubmit');
        const loadingEl = document.querySelector<HTMLElement>('.TagLoading');
        if (submitBtn) submitBtn.style.display = 'none';
        if (loadingEl) loadingEl.style.display = '';
        const inputEl = document.querySelector<HTMLInputElement>('.RenameTagPopInContainer .tag-property-input');
        if (!inputEl) return;
        renameTag(inputEl.id, inputEl.value)
            .then(() => {
                if (submitBtn) submitBtn.style.display = '';
                if (loadingEl) loadingEl.style.display = 'none';
                rename_tag_close();
            })
            .catch((message: string) => {
                if (submitBtn) submitBtn.style.display = '';
                if (loadingEl) loadingEl.style.display = 'none';
                console.error(message);
            });
    });

    document.getElementById('add-tag')?.addEventListener('submit', function (e) {
        e.preventDefault();
        const inputEl = document.getElementById('add-tag-input') as HTMLInputElement | null;
        if (inputEl && inputEl.value !== '') {
            const loadState = new TemporaryState();
            const validateIcon = document.querySelector<HTMLElement>('#add-tag .icon-validate');
            if (validateIcon) {
                loadState.removeClass(validateIcon, 'icon-plus');
                loadState.changeHTML(validateIcon, "<i class='icon-spin6 animate-spin'> </i>");
                loadState.changeAttribute(validateIcon, 'style', 'pointer-event:none');
            }
            addTag(inputEl.value)
                .then(function () {
                    showMessage(str_tag_created.replace('%s', inputEl.value));
                    inputEl.value = '';
                    document.getElementById('add-tag')?.classList.remove('input-mode');
                    const searchInput = document.querySelector('#search-tag .search-input');
                    if (searchInput) searchInput.dispatchEvent(new Event('input'));
                    loadState.reverse();
                })
                .catch((message: string) => {
                    loadState.reverse();
                    showError(message);
                });
        }
    });

    document.querySelector('#add-tag .icon-validate')?.addEventListener('click', function () {
        const addTagEl = document.getElementById('add-tag');
        if (addTagEl && addTagEl.classList.contains('input-mode')) {
            addTagEl.dispatchEvent(new Event('submit'));
        }
    });

    function addTag(name: string): Promise<void> {
        return new Promise((resolve, reject) => {
            fetch('ws.php?format=json&method=pwg.tags.add', {
                method: 'POST',
                body: new URLSearchParams({ name }),
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            })
                .then(response => response.text())
                .then(raw_data => {
                    const data = JSON.parse(raw_data) as { stat: string; result: { id: string | number; name: string; url_name: string } };
                    if (data.stat === 'ok') {
                        const newTag = createTagBox(data.result.id, data.result.name, data.result.url_name, 0);
                        const container = document.querySelector('.tag-container');
                        if (container) container.prepend(newTag);
                        setupTagbox(newTag);
                        updateSearchInfo();
                        dataTags.unshift({
                            name: data.result.name,
                            raw_name: data.result.name,
                            id: data.result.id,
                            url_name: data.result.url_name,
                        });
                        updateBadge();
                        resolve();
                    } else {
                        reject(new Error(str_already_exist.replace('%s', name)));
                    }
                })
                .catch((error: unknown) => reject(error instanceof Error ? error : new Error(String(error))));
        });
    }

    function setupTagbox(tagBox: HTMLElement): void {
        const showOptionsBtn = tagBox.querySelector<HTMLElement>('.showOptions');
        if (showOptionsBtn) {
            showOptionsBtn.addEventListener('click', function () {
                const dropdown = tagBox.querySelector<HTMLElement>('.tag-dropdown-block');
                if (dropdown) dropdown.style.display = 'grid';
            });
        }

        document.addEventListener('mouseup', function (e) {
            e.stopPropagation();
            let option_is_clicked = false;
            tagBox.querySelectorAll('.dropdown-option').forEach(el => {
                if (el.contains(e.target as Node)) option_is_clicked = true;
            });
            if (!option_is_clicked) {
                const dropdown = tagBox.querySelector<HTMLElement>('.tag-dropdown-block');
                if (dropdown) dropdown.style.display = 'none';
            }
        });

        tagBox.addEventListener('click', function (this: HTMLElement) {
            const container = document.querySelector('.tag-container');
            if (container && container.classList.contains('selection')) {
                if (this.getAttribute('data-selected') === '1') {
                    this.setAttribute('data-selected', '0');
                    removeSelectedItem(this.getAttribute('data-id') ?? '');
                } else {
                    this.setAttribute('data-selected', '1');
                    addSelectedItem(this.getAttribute('data-id') ?? '');
                }
                updateSelectionContent();
            }
        });

        const editBtn = tagBox.querySelector<HTMLElement>('.dropdown-option.edit');
        if (editBtn) {
            editBtn.addEventListener('click', function () {
                const nameEl = tagBox.querySelector<HTMLElement>('.tag-name');
                set_up_popin(
                    tagBox.dataset['id'] ?? '',
                    nameEl?.dataset['rawname'] ?? '',
                    nameEl?.innerHTML ?? '',
                );
                rename_tag_open();
            });
        }

        const deleteBtn = tagBox.querySelector<HTMLElement>('.dropdown-option.delete');
        if (deleteBtn) {
            deleteBtn.addEventListener('click', function () {
                pwgConfirm({
                    title: str_delete.replace('%s', tagBox.querySelector<HTMLElement>('.tag-name')?.innerHTML ?? ''),
                    buttons: {
                        confirm: {
                            text: str_yes_delete_confirmation,
                            btnClass: 'btn-red',
                            action: function () {
                                removeTag(
                                    tagBox.dataset['id'] ?? '',
                                    tagBox.querySelector<HTMLElement>('.tag-name')?.innerHTML ?? '',
                                );
                            },
                        },
                        cancel: { text: str_no_delete_confirmation },
                    },
                });
            });
        }

        const dupBtn = tagBox.querySelector<HTMLElement>('.dropdown-option.duplicate');
        if (dupBtn) {
            dupBtn.addEventListener('click', function () {
                const nameEl = tagBox.querySelector<HTMLElement>('.tag-name');
                void duplicateTag(tagBox.dataset['id'] ?? '', nameEl?.dataset['rawname'] ?? '').then((data) => {
                    showMessage(str_tag_created.replace('%s', data.result.name));
                });
            });
        }
    }

    function set_up_popin(id: string, tagRawName: string, tagName: string): void {
        const inputEl = document.querySelector<HTMLInputElement>('.RenameTagPopInContainer .tag-property-input');
        if (inputEl) inputEl.id = id;
        const titleEl = document.querySelector<HTMLElement>('.AddIconTitle span');
        if (titleEl) titleEl.innerHTML = str_tag_rename.replace('%s', tagName);
        document.querySelectorAll<HTMLElement>('.ClosePopIn, .TagCancel').forEach(el => {
            el.addEventListener('click', function () { rename_tag_close(); });
        });
        const submitBtn = document.querySelector<HTMLElement>('.TagSubmit');
        if (submitBtn) submitBtn.innerHTML = str_yes_rename_confirmation;
        if (inputEl) inputEl.value = tagRawName;
    }

    function rename_tag_close(): void {
        const el = document.getElementById('RenameTag');
        if (el) el.style.display = 'none';
    }

    function rename_tag_open(): void {
        const el = document.getElementById('RenameTag');
        if (el) el.style.display = '';
        const input = document.querySelector<HTMLElement>('.tag-property-input');
        if (input) input.focus();
    }

    function removeTag(id: string, name: string): void {
        void fetch('ws.php?format=json&method=pwg.tags.delete', {
            method: 'POST',
            body: new URLSearchParams({ tag_id: id, pwg_token }),
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        })
            .then(response => response.text())
            .then(raw_data => {
                const data = JSON.parse(raw_data) as { stat: string };
                if (data.stat === 'ok') {
                    document.querySelector('.tag-box[data-id="' + id + '"]')?.remove();
                    dataTags = dataTags.filter((tag) => tag.id != id);
                    showMessage(str_tag_deleted.replace('%s', name));
                    updateBadge();
                    updateSearchInfo();
                    updatePaginationMenu();
                } else {
                    throw new Error('A problem has occurred');
                }
            });
    }

    function renameTag(id: string, new_name: string): Promise<unknown> {
        return new Promise((resolve, reject) => {
            fetch('ws.php?format=json&method=pwg.tags.rename', {
                method: 'POST',
                body: new URLSearchParams({ tag_id: id, new_name, pwg_token }),
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            })
                .then(response => response.text())
                .then(raw_data => {
                    const data = JSON.parse(raw_data) as { stat: string; result: { name: string; url_name: string } };
                    console.log(data);
                    if (data.stat === 'ok') {
                        document.querySelectorAll<HTMLElement>(
                            '.tag-box[data-id="' + id + '"] p, .tag-box[data-id="' + id + '"] .tag-dropdown-header b'
                        ).forEach(el => { el.innerHTML = data.result.name; });
                        const editableEl = document.querySelector<HTMLInputElement>('.tag-box[data-id="' + id + '"] .tag-name-editable');
                        if (editableEl) editableEl.value = data.result.name;
                        const viewLink = document.querySelector<HTMLAnchorElement>('.dropdown-option.view');
                        if (viewLink) viewLink.href = 'index.php?/tags/' + id + '-' + data.result.url_name;
                        const index = dataTags.findIndex((tag) => tag.id == id);
                        dataTags[index]!.name = data.result.name;
                        dataTags[index]!.url_name = data.result.url_name;
                        resolve(data);
                    } else {
                        reject(new Error(str_already_exist.replace('%s', new_name)));
                    }
                })
                .catch((error: unknown) => reject(error instanceof Error ? error : new Error(String(error))));
        });
    }

    function duplicateTag(id: string, name: string): Promise<{ result: { name: string; id: string | number; url_name: string; count: number } }> {
        return new Promise((resolve, reject) => {
            let copy_name = name + str_copy;
            const name_exist = (n: string): boolean => {
                let exist = false;
                document.querySelectorAll<HTMLElement>('.tag-box .tag-name').forEach(el => {
                    if (el.innerHTML === n) exist = true;
                });
                return exist;
            };
            let i = 1;
            while (name_exist(copy_name)) {
                copy_name = name + str_other_copy.replace('%s', String(i++));
            }
            fetch('ws.php?format=json&method=pwg.tags.duplicate', {
                method: 'POST',
                body: new URLSearchParams({ tag_id: id, copy_name, pwg_token }),
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            })
                .then(response => response.text())
                .then(raw_data => {
                    const data = JSON.parse(raw_data) as { stat: string; result: { id: string | number; name: string; url_name: string; count: number } };
                    if (data.stat === 'ok') {
                        const newTag = createTagBox(data.result.id, data.result.name, data.result.url_name, data.result.count);
                        const afterEl = document.querySelector('.tag-box[data-id="' + id + '"]');
                        if (afterEl) afterEl.insertAdjacentElement('afterend', newTag);
                        setupTagbox(newTag);
                        const index = dataTags.findIndex((tag) => tag.id == id);
                        dataTags.splice(index + 1, 0, {
                            name: data.result.name,
                            raw_name: data.result.name,
                            id: data.result.id,
                            url_name: data.result.url_name,
                            counter: data.result.count,
                        });
                        updateBadge();
                        updateSearchInfo();
                        resolve(data);
                    }
                })
                .catch((error: unknown) => reject(error instanceof Error ? error : new Error(String(error))));
        });
    }

    /*------- Selection mode -------*/
    let selected: string[] = [];
    const maxItemDisplayed = 5;

    const toggleSelectionEl = document.getElementById('toggleSelectionMode') as HTMLInputElement | null;
    if (toggleSelectionEl) {
        toggleSelectionEl.checked = false;
        toggleSelectionEl.addEventListener('click', function () {
            selectionMode(this.checked);
            const tagInfo = document.querySelector<HTMLElement>('.tag-info');
            if (tagInfo) tagInfo.style.display = 'none';
        });
    }

    function selectionMode(isSelection: boolean): void {
        if (isSelection) {
            document.querySelectorAll<HTMLElement>('.in-selection-mode').forEach(el => el.classList.add('show'));
            document.querySelectorAll<HTMLElement>('.not-in-selection-mode').forEach(el => el.classList.add('hide'));
            document.querySelector('.tag-container')?.classList.add('selection');
            document.querySelectorAll<HTMLElement>('.tag-box').forEach(el => el.classList.remove('edit-name'));
        } else {
            document.querySelectorAll<HTMLElement>('.in-selection-mode').forEach(el => el.classList.remove('show'));
            document.querySelectorAll<HTMLElement>('.not-in-selection-mode').forEach(el => el.classList.remove('hide'));
            document.querySelector('.tag-container')?.classList.remove('selection');
            document.querySelectorAll<HTMLElement>('.tag-box').forEach(el => el.setAttribute('data-selected', '0'));
            const selectMsg = document.querySelector<HTMLElement>('.tag-select-message');
            if (selectMsg && selectMsg.style.display !== 'none') selectMsg.style.display = 'none';
            clearSelection();
        }
    }

    function clearSelection(): void {
        selected = [];
        const tagList = document.querySelector<HTMLElement>('.selection-mode-tag .tag-list');
        if (tagList) tagList.innerHTML = '';
        const otherTags = document.querySelector<HTMLElement>('.selection-other-tags');
        if (otherTags) otherTags.style.display = 'none';
        updateSelectionContent();
    }

    function addSelectedItem(id: string): void {
        if (!selected.includes(id)) {
            selected.push(id);
            if (selected.length > maxItemDisplayed) {
                const otherTags = document.querySelector<HTMLElement>('.selection-other-tags');
                if (otherTags) {
                    otherTags.style.display = '';
                    const numberDisplayed = document.querySelectorAll('.selection-mode-tag .tag-list div').length;
                    otherTags.innerHTML = str_and_others_tags.replace('%s', String(selected.length - numberDisplayed));
                }
            } else {
                const otherTags = document.querySelector<HTMLElement>('.selection-other-tags');
                if (otherTags) otherTags.style.display = 'none';
                if (dataTags.findIndex((tag) => tag.id == id) > -1) {
                    createSelectionItem(id, dataTags.find((tag) => tag.id == id)!.name);
                }
            }
        }
    }

    function createSelectionItem(id: string, name: string): void {
        const div = document.createElement('div');
        div.setAttribute('data-id', id);
        div.innerHTML = '<a class="icon-cancel"></a><p>' + name + '</p>';
        const tagList = document.querySelector<HTMLElement>('.selection-mode-tag .tag-list');
        if (tagList) tagList.prepend(div);
        const closeBtn = div.querySelector('a');
        if (closeBtn) {
            closeBtn.addEventListener('click', function () { removeSelectedItem(id); });
        }
    }

    function removeSelectedItem(id: string): void {
        if (selected.findIndex((tag) => tag === id) > -1) {
            selected = selected.filter((tag) => parseInt(tag) !== parseInt(id));

            const tagBox = document.querySelector<HTMLElement>('.tag-box[data-id="' + id + '"]');
            if (tagBox) tagBox.setAttribute('data-selected', '0');

            const tagListItem = document.querySelector<HTMLElement>('.selection-mode-tag .tag-list div[data-id="' + id + '"]');
            if (tagListItem) {
                tagListItem.remove();
                if (selected.length >= maxItemDisplayed) {
                    let i = 0;
                    let isNotCreate = true;
                    while (i < selected.length && isNotCreate) {
                        if (document.querySelector('.selection-mode-tag .tag-list div[data-id="' + selected[i] + '"]') === null) {
                            isNotCreate = false;
                            const indexOfTag = dataTags.findIndex((tag) => tag.id == selected[i]);
                            createSelectionItem(selected[i]!, dataTags[indexOfTag]!.name);
                        }
                        i++;
                    }
                }
            }

            const numberDisplayed = document.querySelectorAll('.selection-mode-tag .tag-list div').length;
            const otherTags = document.querySelector<HTMLElement>('.selection-other-tags');
            if (otherTags) {
                otherTags.innerHTML = str_and_others_tags.replace('%s', String(selected.length - numberDisplayed));
                if (selected.length - numberDisplayed <= 0) otherTags.style.display = 'none';
            }

            const selectMsg = document.querySelector<HTMLElement>('.tag-select-message');
            if (selectMsg && selectMsg.style.display !== 'none') selectMsg.style.display = 'none';
        }
    }

    function updateMergeItems(): void {
        const mergeChoices = document.getElementById('MergeOptionsChoices') as HTMLSelectElement | null;
        if (mergeChoices) {
            mergeChoices.innerHTML = '';
            selected.forEach((id) => {
                const opt = document.createElement('option');
                opt.value = id;
                opt.innerHTML = dataTags.find((tag) => tag.id == id)?.name ?? '';
                mergeChoices.appendChild(opt);
            });
        }
    }

    let mergeOption = false;

    function updateSelectionContent(): void {
        const number = selected.length;
        const nothingSelected = document.getElementById('nothing-selected');
        const selectionModeEl = document.querySelector<HTMLElement>('.selection-mode-tag');
        const mergeBlock = document.getElementById('MergeOptionsBlock');
        const mergeBtn = document.getElementById('MergeSelectionMode');

        if (number === 0) {
            mergeOption = false;
            if (nothingSelected) nothingSelected.style.display = '';
            if (selectionModeEl) selectionModeEl.style.display = 'none';
            if (mergeBlock) mergeBlock.style.display = 'none';
        } else if (number === 1) {
            mergeOption = false;
            if (nothingSelected) nothingSelected.style.display = 'none';
            if (selectionModeEl) selectionModeEl.style.display = '';
            if (mergeBlock) mergeBlock.style.display = 'none';
            if (mergeBtn) mergeBtn.classList.add('unavailable');
        } else {
            if (nothingSelected) nothingSelected.style.display = 'none';
            if (mergeBtn) mergeBtn.classList.remove('unavailable');
            if (mergeOption) {
                if (mergeBlock) mergeBlock.style.display = '';
                if (selectionModeEl) selectionModeEl.style.display = 'none';
                updateMergeItems();
            } else {
                if (mergeBlock) mergeBlock.style.display = 'none';
                if (selectionModeEl) selectionModeEl.style.display = '';
            }
        }
    }

    document.getElementById('MergeSelectionMode')?.addEventListener('click', function () {
        mergeOption = true;
        updateSelectionContent();
    });

    document.getElementById('CancelMerge')?.addEventListener('click', function () {
        mergeOption = false;
        updateSelectionContent();
    });

    document.getElementById('selectAll')?.addEventListener('click', function () {
        void selectAll(tagToDisplay());
        updateSelectionContent();
        if (selected.length < dataTags.length) {
            showSelectMessage(
                str_selection_done.replace('%d', String(document.querySelectorAll('.tag-box').length)),
                str_select_all_tag.replace('%d', String(dataTags.length)),
                function () {
                    const msgLink = document.querySelector<HTMLElement>('.tag-select-message a');
                    if (msgLink) msgLink.innerHTML = '';
                    const div = document.querySelector<HTMLElement>('.tag-select-message div');
                    if (div) div.innerHTML = "<i class='icon-spin6 animate-spin'> </i>";
                    setTimeout(() => {
                        void selectAll(dataTags).then(() => {
                            updateSelectionContent();
                            showSelectMessage(
                                str_tag_selected.replace(/%d/g, String(selected.length)),
                                str_clear_selection,
                                function () {
                                    selectNone();
                                    const selectMsg = document.querySelector<HTMLElement>('.tag-select-message');
                                    if (selectMsg) selectMsg.style.display = 'none';
                                },
                            );
                        });
                    }, 5);
                },
            );
        }
    });

    function selectAll(data: TagData[]): Promise<void[]> {
        const promises: Promise<void>[] = data.map((tag) =>
            new Promise<void>((res) => {
                const tagBox = document.querySelector<HTMLElement>('.tag-box[data-id="' + String(tag.id) + '"]');
                if (tagBox) tagBox.setAttribute('data-selected', '1');
                addSelectedItem(String(tag.id));
                res();
            }),
        );
        return Promise.all(promises);
    }

    function showSelectMessage(str1: string, str2: string, callback: () => void): void {
        const selectMsg = document.querySelector<HTMLElement>('.tag-select-message');
        if (selectMsg && getComputedStyle(selectMsg).display === 'none') {
            selectMsg.style.display = 'flex';
        }
        const msgDiv = document.querySelector<HTMLElement>('.tag-select-message div');
        if (msgDiv) msgDiv.innerHTML = str1;
        const msgLink = document.querySelector<HTMLElement>('.tag-select-message a');
        if (msgLink) {
            msgLink.innerHTML = str2;
            msgLink.addEventListener('click', callback);
        }
    }

    document.getElementById('selectNone')?.addEventListener('click', function () {
        const selectMsg = document.querySelector<HTMLElement>('.tag-select-message');
        if (selectMsg) selectMsg.style.display = 'none';
        selectNone();
    });

    function selectNone(): void {
        document.querySelectorAll<HTMLElement>('.tag-box').forEach(el => el.setAttribute('data-selected', '0'));
        clearSelection();
    }

    document.getElementById('selectInvert')?.addEventListener('click', function () {
        const selectMsg = document.querySelector<HTMLElement>('.tag-select-message');
        if (selectMsg) selectMsg.style.display = 'none';
        selectInvert(tagToDisplay());
    });

    function selectInvert(data: TagData[]): void {
        data.forEach((tag) => {
            const tagBox = document.querySelector<HTMLElement>('.tag-box[data-id="' + String(tag.id) + '"]');
            if (tagBox) {
                if (tagBox.getAttribute('data-selected') === '1') {
                    tagBox.setAttribute('data-selected', '0');
                    removeSelectedItem(String(tag.id));
                } else {
                    tagBox.setAttribute('data-selected', '1');
                    addSelectedItem(String(tag.id));
                }
            }
        });
        updateSelectionContent();
    }

    /*------- Actions in selection mode -------*/

    document.getElementById('DeleteSelectionMode')?.addEventListener('click', function () {
        const names: string[] = selected.map(id => dataTags.find((tag) => tag.id == id)?.name ?? '');
        pwgConfirm({
            title: str_delete_tags.replace('%s', tagListToString(names)),
            buttons: {
                confirm: {
                    text: str_yes_delete_confirmation,
                    btnClass: 'btn-red',
                    action: function () { removeSelectedTags(); },
                },
                cancel: { text: str_no_delete_confirmation },
            },
        });
    });

    function removeSelectedTags(): void {
        void fetch('ws.php?format=json&method=pwg.tags.delete', {
            method: 'POST',
            body: new URLSearchParams({ pwg_token, tag_id: selected.join(',') }),
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        })
            .then(response => response.text())
            .then(raw_data => {
                const trimmed = raw_data.slice(raw_data.search('{'));
                const data = JSON.parse(trimmed) as { stat: string };
                if (data.stat === 'ok') {
                    selected.forEach(id => { document.querySelector('.tag-box[data-id="' + id + '"]')?.remove(); });
                    dataTags = dataTags.filter((tag) => !selected.includes(String(tag.id)));
                    clearSelection();
                    updatePaginationMenu();
                    updateBadge();
                    updateSearchInfo();
                }
            });
    }

    document.querySelector('.ConfirmMergeButton')?.addEventListener('click', () => {
        const destSelect = document.getElementById('MergeOptionsChoices') as HTMLSelectElement | null;
        if (destSelect) mergeGroups(destSelect.value, selected);
    });

    function mergeGroups(destination_id: string, merge_ids: string[]): void {
        const destNameEl = document.querySelector<HTMLElement>('.tag-box[data-id="' + destination_id + '"] .tag-name');
        const destination_name = destNameEl ? destNameEl.innerHTML : '';
        const merge_name: string[] = [];
        merge_ids.forEach((id) => {
            const el = document.querySelector<HTMLElement>('.tag-box[data-id="' + id + '"] .tag-name');
            if (el) merge_name.push(el.innerHTML);
        });
        void str_merged_into
            .replace('%s1', tagListToString(merge_name))
            .replace('%s2', destination_name);

        void fetch('ws.php?format=json&method=pwg.tags.merge', {
            method: 'POST',
            body: new URLSearchParams({ pwg_token, destination_tag_id: destination_id, merge_tag_id: merge_ids.join(',') }),
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        })
            .then(response => response.text())
            .then(raw_data => {
                const trimmed = raw_data.slice(raw_data.search('{'));
                const data = JSON.parse(trimmed) as { stat: string; result: { deleted_tag: (string | number)[]; destination_tag: string | number; images_in_merged_tag: unknown[] } };
                if (data.stat === 'ok') {
                    data.result.deleted_tag.forEach((id) => {
                        if (data.result.destination_tag != id) {
                            document.querySelector('.tag-box[data-id="' + String(id) + '"]')?.remove();
                            dataTags = dataTags.filter((tag) => id != tag.id);
                        }
                    });
                    if (data.result.images_in_merged_tag.length > 0) {
                        const tagBox = document.querySelector<HTMLElement>('.tag-box[data-id="' + String(data.result.destination_tag) + '"]');
                        if (tagBox) {
                            tagBox.querySelectorAll<HTMLElement>('.dropdown-option.view, .dropdown-option.manage, .tag-dropdown-header i').forEach(el => { el.style.display = ''; });
                            const headerI = tagBox.querySelector<HTMLElement>('.tag-dropdown-header i');
                            if (headerI) headerI.innerHTML = str_number_photos.replace('%d', String(data.result.images_in_merged_tag.length));
                        }
                        const index = dataTags.findIndex((tag) => tag.id == data.result.destination_tag);
                        dataTags[index]!.counter = data.result.images_in_merged_tag.length;
                    }
                    document.querySelectorAll<HTMLElement>('.tag-box').forEach(el => el.setAttribute('data-selected', '0'));
                    clearSelection();
                    updatePaginationMenu();
                    updateBadge();
                    updateSearchInfo();
                }
            });
    }

    function tagListToString(list: string[]): string {
        if (list.length > 5) {
            return list.slice(0, 5).join(', ') + ' ' + str_and_others_tags.replace('%s', String(list.length - 5));
        }
        return list.join(', ');
    }

    /*------- Filter / search -------*/

    let searchTimeOut: ReturnType<typeof setTimeout> | undefined;
    const delaySearchInput = 300;

    document.querySelector('#search-tag .search-input')?.addEventListener('input', function () {
        actualPage = 1;
        clearTimeout(searchTimeOut);
        searchTimeOut = setTimeout(() => {
            updatePaginationMenu();
            const emptyResearch = document.querySelector<HTMLElement>('.emptyResearch');
            if (emptyResearch) {
                emptyResearch.style.display = dataTags.filter(isDataSearched).length === 0 ? '' : 'none';
            }
        }, delaySearchInput);
    });

    function isDataSearched(tagObj: TagData): boolean {
        const name = String(tagObj.raw_name).toLowerCase();
        const stringSearch = (document.querySelector<HTMLInputElement>('#search-tag .search-input'))?.value ?? '';
        return name.includes(stringSearch.toLowerCase());
    }

    /*------- Show Info -------*/

    function showError(message: string): void {
        const errorMsg = document.querySelector<HTMLElement>('.info-error p');
        if (errorMsg) errorMsg.innerHTML = message;
        const errorBox = document.querySelector<HTMLElement>('.info-error');
        if (errorBox) { errorBox.setAttribute('title', message); errorBox.style.display = 'flex'; }
        const infoEl = document.querySelector<HTMLElement>('.info-info');
        if (infoEl) infoEl.style.display = 'none';
    }

    function showMessage(message: string): void {
        const msgEl = document.querySelector<HTMLElement>('.info-message p');
        if (msgEl) msgEl.innerHTML = message;
        const msgBox = document.querySelector<HTMLElement>('.info-message');
        if (msgBox) { msgBox.setAttribute('title', message); msgBox.style.display = 'flex'; }
        const infoEl = document.querySelector<HTMLElement>('.info-info');
        if (infoEl) infoEl.style.display = 'none';
    }

    /*------- Pagination -------*/

    let per_page: number = parseInt((document.querySelector<HTMLElement>('.tag-container'))?.dataset['per_page'] ?? '20');
    let promisePending = false;
    let updateAsk = false;
    let actualPage = 1;

    function askUpdatePage(): void {
        if (!promisePending) {
            promisePending = true;
            void updatePage().then(promiseFinish);
        } else {
            updateAsk = true;
        }
    }

    function promiseFinish(): void {
        promisePending = false;
        if (updateAsk) {
            updateAsk = false;
            askUpdatePage();
        }
    }

    function updatePaginationMenu(): void {
        const container = document.querySelector<HTMLElement>('.pagination-item-container');
        if (container) container.innerHTML = '';
        actualPage = Math.min(actualPage, getNumberPages());
        const paginationContainer = document.querySelector<HTMLElement>('.pagination-container');
        if (getNumberPages() > 1) {
            if (paginationContainer) paginationContainer.style.display = '';
            createPaginationMenu();
        } else {
            if (paginationContainer) paginationContainer.style.display = 'none';
        }
        updateArrows();
        askUpdatePage();
        const selectMsg = document.querySelector<HTMLElement>('.tag-select-message');
        if (selectMsg && selectMsg.style.display !== 'none') selectMsg.style.display = 'none';
    }

    function createPaginationMenu(): void {
        const nbPage = getNumberPages();
        appendPaginationItem(1);
        if (actualPage > 2) appendPaginationItem();
        if (actualPage !== 1 && actualPage !== nbPage) appendPaginationItem(actualPage);
        if (actualPage < nbPage - 1) appendPaginationItem();
        appendPaginationItem(nbPage);
    }

    function appendPaginationItem(page: number | null = null): void {
        const container = document.querySelector<HTMLElement>('.pagination-item-container');
        if (page !== null) {
            const newItem = document.createElement('a');
            newItem.setAttribute('data-page', String(page));
            newItem.innerHTML = String(page);
            if (container) container.appendChild(newItem);
            if (actualPage === page) newItem.classList.add('actual');
            newItem.addEventListener('click', () => {
                actualPage = parseInt(newItem.getAttribute('data-page') ?? '1');
                updatePaginationMenu();
            });
        } else {
            const ellipsis = document.createElement('span');
            ellipsis.innerHTML = '...';
            if (container) container.appendChild(ellipsis);
        }
    }

    function updateArrows(): void {
        const leftArrow = document.querySelector<HTMLElement>('.pagination-arrow.left');
        const rightArrow = document.querySelector<HTMLElement>('.pagination-arrow.right');
        if (actualPage === 1) {
            if (leftArrow) leftArrow.classList.add('unavailable');
        } else {
            if (leftArrow) leftArrow.classList.remove('unavailable');
        }
        if (actualPage === getNumberPages()) {
            if (rightArrow) rightArrow.classList.add('unavailable');
        } else {
            if (rightArrow) rightArrow.classList.remove('unavailable');
        }
    }

    function getNumberPages(): number {
        const dataVisible = dataTags.filter(isDataSearched).length;
        return Math.max(1, Math.floor((dataVisible - 1) / per_page) + 1);
    }

    function movePage(toRight = true): void {
        document.querySelectorAll<HTMLElement>('.tag-box').forEach(el => el.classList.remove('edit-name'));
        if (toRight) {
            if (actualPage < getNumberPages()) { actualPage++; updatePaginationMenu(); }
        } else {
            if (actualPage > 1) { actualPage--; updatePaginationMenu(); }
        }
    }

    function updatePage(): Promise<void> {
        return new Promise((resolve) => {
            const dataToDisplay = tagToDisplay();
            const tagBoxes = document.querySelectorAll<HTMLElement>('.tag-box');
            const pageLoad = document.querySelector<HTMLElement>('.pageLoad');
            if (pageLoad) pageLoad.style.display = '';
            const tagContainer = document.querySelector<HTMLElement>('.tag-container');
            if (tagContainer) { tagContainer.style.opacity = '0'; tagContainer.style.transition = 'opacity 0.5s'; }

            setTimeout(() => {
                const displayTags = new Promise<void>((res) => {
                    const boxToRecycle = Math.min(dataToDisplay.length, tagBoxes.length);
                    for (let i = 0; i < boxToRecycle; i++) {
                        const tag = dataToDisplay[i]!;
                        recycleTagBox(tagBoxes[i]!, tag.id, tag.name, tag.url_name, tag.counter ?? tag.count, tag.raw_name);
                    }
                    if (dataToDisplay.length < tagBoxes.length) {
                        for (let j = boxToRecycle; j < tagBoxes.length; j++) tagBoxes[j]!.remove();
                    } else if (dataToDisplay.length > tagBoxes.length) {
                        for (let j = boxToRecycle; j < dataToDisplay.length; j++) {
                            const tag = dataToDisplay[j]!;
                            const newTag = createTagBox(tag.id, tag.name, tag.url_name, tag.counter ?? tag.count ?? 0, tag.raw_name);
                            newTag.style.opacity = '0';
                            if (tagContainer) tagContainer.appendChild(newTag);
                            setupTagbox(newTag);
                        }
                    }
                    selected.forEach((id) => {
                        const el = document.querySelector<HTMLElement>('.tag-box[data-id="' + id + '"]');
                        if (el) el.setAttribute('data-selected', '1');
                    });
                    res();
                });

                void displayTags.then(() => {
                    if (pageLoad) pageLoad.style.display = 'none';
                    if (tagContainer) tagContainer.style.opacity = '1';
                    const tagPag = document.querySelector<HTMLElement>('.tag-pagination');
                    if (getNumberPages() > 1 && tagPag) tagPag.style.opacity = '1';
                    updateSearchInfo();
                    resolve();
                });
            }, 500);
        });
    }

    function tagToDisplay(): TagData[] {
        return dataTags.filter(isDataSearched).slice((actualPage - 1) * per_page, actualPage * per_page);
    }

    document.querySelector('.pagination-arrow.right')?.addEventListener('click', () => { movePage(); });
    document.querySelector('.pagination-arrow.left')?.addEventListener('click', () => { movePage(false); });

    if (getNumberPages() > 1) {
        const paginationContainer = document.querySelector<HTMLElement>('.pagination-container');
        if (paginationContainer) paginationContainer.style.display = '';
        createPaginationMenu();
        updateArrows();
    } else {
        const paginationContainer = document.querySelector<HTMLElement>('.pagination-container');
        if (paginationContainer) paginationContainer.style.display = 'none';
    }

    document.querySelectorAll<HTMLElement>('.pagination-per-page a').forEach(function (el) {
        el.addEventListener('click', function (this: HTMLElement) {
            per_page = parseInt(this.innerHTML);
            updatePaginationMenu();
            document.querySelectorAll<HTMLElement>('.pagination-per-page .selected').forEach(s => s.classList.remove('selected'));
            this.classList.add('selected');
            document.cookie = 'pwg_tags_per_page=' + String(per_page) + '; path=/; SameSite=Lax';
        });
    });

    function updateSearchInfo(): void {
        const searchInput = document.querySelector<HTMLInputElement>('.search-input');
        const searchInfo = document.querySelector<HTMLElement>('.search-info');
        if (!searchInfo) return;
        if (searchInput && searchInput.value !== '') {
            const number = dataTags.filter(isDataSearched).length;
            searchInfo.innerHTML = number > 1
                ? str_tags_found.replace('%d', String(number))
                : str_tag_found.replace('%d', String(number));
        } else {
            searchInfo.innerHTML = '';
        }
    }

    const h1 = document.querySelector<HTMLElement>('h1');
    if (h1) h1.insertAdjacentHTML('beforeend', '<span class="badge-number">' + String(total_tags || '0') + '</span>');

    function setPagination(): void {
        const match = document.cookie.match(/(?:^|;)\s*pwg_tags_per_page=([^;]*)/);
        const saved = match ? match[1] : null;
        if (saved) {
            document.querySelectorAll<HTMLElement>('.pagination-per-page .selected').forEach(el => el.classList.remove('selected'));
            const el = document.getElementById(saved);
            if (el) el.click();
        }
    }

    setPagination();

    if (!document.cookie.match(/(?:^|;)\s*pwg_tags_per_page=/)) {
        document.cookie = 'pwg_tags_per_page=100; path=/; SameSite=Lax';
    }
}

initModule(init as unknown as (cfg: Record<string, unknown>) => void);
