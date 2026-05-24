import '../css/pages/group_list.css';
import TomSelect from 'tom-select';
import { getPageData } from './page-data';
import { UsersCache } from './LocalStorageCache';
import { config } from './config';

interface GroupListPageData {
    pwg_token: string;
    rootUrl: string;
    serverId: string;
    serverKey: string;
    str_copy: string;
    str_delete: string;
    str_group_created: string;
    str_group_deleted: string;
    str_groups_deleted: string;
    str_member_default: string;
    str_members_default: string;
    str_merged_into: string;
    str_name_not_empty: string;
    str_name_taken: string;
    str_no_delete_confirmation: string;
    str_other_copy: string;
    str_renaming_done: string;
    str_set_default: string;
    str_unset_default: string;
    str_user_associated: string;
    str_user_dissociate: string;
    str_user_dissociated: string;
    str_user_list: string;
    str_yes_delete_confirmation: string;
}

const {
    pwg_token,
    rootUrl: _rootUrl,
    serverId: _serverId,
    serverKey: _serverKey,
    str_copy,
    str_delete,
    str_group_created,
    str_group_deleted,
    str_groups_deleted,
    str_member_default,
    str_members_default,
    str_merged_into,
    str_name_not_empty,
    str_name_taken,
    str_no_delete_confirmation: _str_no_delete_confirmation,
    str_other_copy,
    str_renaming_done,
    str_set_default,
    str_unset_default,
    str_user_associated,
    str_user_dissociate,
    str_user_dissociated,
    str_user_list,
    str_yes_delete_confirmation: _str_yes_delete_confirmation,
} = getPageData<GroupListPageData>();

interface GroupListCacheData {
    CACHE_KEYS: { users: string; _hash: string };
    ROOT_URL: string;
    str_create: string;
}

const cacheData = getPageData<GroupListCacheData>('pwg-group-list-data');

interface Group {
    id: number | string;
    name: string;
    is_default?: boolean | string;
    nb_users?: number;
    lastmodified?: string;
}

type GroupId = number | string;

const DELAY_FEEDBACK = 3000;

const qs = <T extends HTMLElement = HTMLElement>(sel: string, ctx: Element | Document = document) =>
    ctx.querySelector<T>(sel);
const qsa = <T extends HTMLElement = HTMLElement>(
    sel: string,
    ctx: Element | Document = document
) => Array.from(ctx.querySelectorAll<T>(sel));
const grp = (id: number | string) => document.getElementById('group-' + String(id));
const grpQ = (id: number | string, sel: string) => grp(id)?.querySelector<HTMLElement>(sel);

interface WsResponse<T = unknown> {
    stat?: string;
    result?: T;
    err?: string;
    message?: string;
}

function pwgPost<T = unknown>(
    method: string,
    body: string | URLSearchParams
): Promise<WsResponse<T>> {
    return fetch(`${config.wsUrl}format=json&method=${method}`, {
        method: 'POST',
        ...(typeof body === 'string'
            ? { headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body }
            : { body }),
    }).then((r) => r.json() as Promise<WsResponse<T>>);
}

function show(el: HTMLElement | null | undefined) {
    if (el) el.style.display = '';
}
function hide(el: HTMLElement | null | undefined) {
    if (el) el.style.display = 'none';
}
function _fadeIn(el: HTMLElement | null | undefined) {
    if (el) el.style.display = '';
}
function fadeOut(el: HTMLElement | null | undefined, delay = 0) {
    if (!el) return;
    setTimeout(() => {
        el.style.transition = 'opacity 0.4s';
        el.style.opacity = '0';
        setTimeout(() => {
            el.style.display = 'none';
            el.style.opacity = '';
            el.style.transition = '';
        }, 400);
    }, delay);
}
function showMessage(el: HTMLElement | null | undefined, msg: string) {
    if (!el) return;
    el.innerHTML = msg;
    el.style.display = '';
    fadeOut(el, DELAY_FEEDBACK);
}

// TemporaryState helpers that handle multiple elements
interface TemporaryStateLike {
    changeHTML(el: HTMLElement, html: string): void;
    changeAttribute(el: HTMLElement, attr: string, val: string): void;
    addClass(el: HTMLElement, cls: string): void;
    removeClass(el: HTMLElement, cls: string): void;
    reverse(): void;
}
type TS = TemporaryStateLike;
function _tsChangeHTML(ts: TS, sel: string, html: string) {
    qsa(sel).forEach((el) => ts.changeHTML(el, html));
}
function _tsChangeAttr(ts: TS, sel: string, attr: string, val: string) {
    qsa(sel).forEach((el) => ts.changeAttribute(el, attr, val));
}
function _tsRemoveClass(ts: TS, sel: string, cls: string) {
    qsa(sel).forEach((el) => ts.removeClass(el, cls));
}

interface GlobalsWithTemporaryState {
    TemporaryState: new () => TemporaryStateLike;
}
function newTemporaryState(): TS {
    return new (window as unknown as GlobalsWithTemporaryState).TemporaryState();
}

/*------- Group Popin -------*/

document.querySelector('.group_details_popup_trigger')?.addEventListener('click', () => {
    show(qs('.Group_details-popup-container'));
});
document.querySelector('.CloseGroupPopup')?.addEventListener('click', () => {
    hide(qs('.Group_details-popup-container'));
});

function updateBadge() {
    const badge = qs('.badge-number');
    if (badge) badge.innerHTML = String(qsa('.GroupContainer').length - 2);
}

/*------- Add User toggle -------*/

document.getElementById('form-btn')?.addEventListener('click', () => {
    show(document.getElementById('cancel'));
    show(document.getElementById('addUserLabel'));
    show(qs('.UserSearch'));
    show(document.getElementById('UserSubmit'));
    hide(document.getElementById('form-btn'));
    qsa('.groups .list_user').forEach((el) => {
        el.style.maxHeight = '100px';
    });
});

document.getElementById('cancel')?.addEventListener('click', () => {
    hide(document.getElementById('cancel'));
    hide(document.getElementById('addUserLabel'));
    hide(qs('.UserSearch'));
    hide(document.getElementById('UserSubmit'));
    show(document.getElementById('form-btn'));
    qsa('.groups .list_user').forEach((el) => {
        el.style.maxHeight = '200px';
    });
});

/*------- Add Group toggle -------*/

let isToggle = true;

qs('.addGroupBlock')?.addEventListener('click', () => {
    if (isToggle) deployAddGroupForm();
    else hideAddGroupForm();
});

function deployAddGroupForm() {
    const block = qs('.addGroupBlock')!;
    block.style.transition = 'top 0.4s, padding 0.4s';
    block.style.top = '20%';
    block.style.padding = '0px';
    setTimeout(() => {
        const form = qs<HTMLElement>('#addGroupForm form');
        if (form) form.style.display = '';
        document.getElementById('addGroupNameInput')?.focus();
    }, 400);
    isToggle = false;
}

function hideAddGroupForm() {
    const form = qs<HTMLElement>('#addGroupForm form');
    if (form) {
        form.style.transition = 'opacity 0.3s';
        form.style.opacity = '0';
        setTimeout(() => {
            form.style.display = 'none';
            form.style.opacity = '';
            form.style.transition = '';
            const block = qs('.addGroupBlock')!;
            block.style.transition = 'top 0.4s, padding 0.4s';
            block.style.top = '50%';
            block.style.padding = '100px 0';
        }, 300);
    }
    isToggle = true;
}

/*------- Add Group Submit -------*/

document.addEventListener('DOMContentLoaded', () => {
    qs<HTMLFormElement>('#addGroupForm form')?.addEventListener('submit', (e) => {
        e.preventDefault();
        const name = qs<HTMLInputElement>('#addGroupForm input[type=text]')?.value ?? '';
        const ts: TS = newTemporaryState();
        qsa('.actionButtons button').forEach((el) => {
            ts.changeHTML(el, "<i class='icon-spin6 animate-spin'> </i>");
            ts.changeAttribute(el, 'style', 'pointer-events: none');
        });
        qsa('.actionButtons a').forEach((el) =>
            ts.changeAttribute(el, 'style', 'pointer-events: none')
        );

        if (name.replace(/\s/g, '').length !== 0) {
            void pwgPost<{ groups: Group[] }>(
                'pwg.groups.add',
                new URLSearchParams({ name, pwg_token })
            )
                .then((data) => {
                    ts.reverse();
                    if (data.stat === 'ok' && data.result !== undefined) {
                        const inp = qs<HTMLInputElement>('.addGroupFormLabelAndInput input');
                        if (inp) inp.value = '';
                        const newGroupEl = createGroup(data.result.groups[0]!);
                        qs('#addGroupForm')?.after(newGroupEl);
                        setupGroupBox(newGroupEl);
                        updateBadge();
                    } else {
                        showMessage(qs('#addGroupForm .groupError'), str_name_not_empty);
                    }
                })
                .catch((e: unknown) => console.error(e));
        } else {
            ts.reverse();
            showMessage(qs('#addGroupForm .groupError'), str_name_not_empty);
        }
    });

    qsa('.GroupContainer').forEach((el) => {
        if (el.id !== 'group-template') setupGroupBox(el);
    });
});

function createGroup(group: Group): HTMLElement {
    const newgroup = document.getElementById('group-template')!.cloneNode(true) as HTMLElement;
    newgroup.id = 'group-' + String(group.id);
    newgroup.dataset['id'] = String(group.id);
    const q = (sel: string) => newgroup.querySelector<HTMLElement>(sel);
    const nameEl = q('#group_name');
    if (nameEl) nameEl.innerHTML = group.name;
    const editable = q('.group_name-editable') as HTMLInputElement | null;
    if (editable) {
        editable.value = group.name;
        editable.innerHTML = group.name;
    }
    const cbLabel = q('.Group-checkbox label');
    if (cbLabel) cbLabel.setAttribute('for', 'Group-Checkbox-selection-' + String(group.id));
    const cbInput = q('.Group-checkbox input');
    if (cbInput) cbInput.id = 'Group-Checkbox-selection-' + String(group.id);
    const placeholder = q('.input-edit-group-name');
    if (placeholder) placeholder.setAttribute('placeholder', group.name);
    const nbUsers = q('.group_number_users');
    const userCount = group.nb_users ?? 0;
    if (nbUsers)
        nbUsers.innerHTML =
            userCount + ' ' + (userCount > 1 ? str_members_default : str_member_default);
    const perms = q('.manage-permissions') as HTMLAnchorElement | null;
    if (perms) perms.href = config.adminUrl + 'page=group_perm&group_id=' + String(group.id);
    hideAddGroupForm();
    const colors = ['icon-red', 'icon-blue', 'icon-yellow', 'icon-purple', 'icon-green'];
    q('.icon-users-1')?.classList.add(colors[Number(group.id) % 5]!);
    showMessage(q('.groupMessage'), str_group_created);
    return newgroup;
}

function setupGroupBox(groupBoxEl: HTMLElement) {
    const id = groupBoxEl.dataset['id'] ?? '';
    const q = (sel: string) => groupBoxEl.querySelector<HTMLElement>(sel);

    const cb = q('.Group-checkbox input[type="checkbox"]') as HTMLInputElement | null;
    cb?.addEventListener('change', () => toogleSelection(id, cb.checked));
    if (cb) cb.checked = false;

    q('.group-dropdown-options')?.addEventListener('click', () => {
        const opts = q('#GroupOptions');
        if (opts) opts.style.display = opts.style.display === 'none' ? '' : 'none';
    });

    q('#GroupDelete')?.addEventListener('click', () => deleteGroup(id));

    q('.Group-name .icon-pencil, #GroupEdit')?.addEventListener('click', () => {
        displayRenameForm(true, id);
        setTimeout(() => {
            hide(q('#GroupOptions'));
        }, 10);
    });

    q('.group-rename .validate')?.addEventListener('click', () => {
        q('.group-rename form')?.dispatchEvent(
            new Event('submit', { bubbles: true, cancelable: true })
        );
    });

    (q('.group-rename form') as HTMLFormElement | null)?.addEventListener('submit', (e) => {
        e.preventDefault();
        const editable = q('.group_name-editable') as HTMLInputElement | null;
        renameGroup(id, editable?.value ?? '');
    });

    q('.group-rename .icon-cancel')?.addEventListener('click', () => {
        displayRenameForm(false, id);
        const editable = q('.group_name-editable') as HTMLInputElement | null;
        const nameP = q('.Group-name-container p');
        if (editable && nameP) editable.value = nameP.innerHTML;
    });

    document.addEventListener('mouseup', (e: MouseEvent) => {
        const target = e.target as Element;
        const optionClicked = Array.from(groupBoxEl.querySelectorAll('#GroupOptions div')).some(
            (div) => div.contains(target)
        );
        if (!optionClicked) hide(q('#GroupOptions'));
    });

    const isDefault = groupBoxEl.dataset['default'];
    if (isDefault === '1') setupDefaultActions(id, true);
    else if (isDefault === '0') setupDefaultActions(id, false);

    q('.manage-users')?.addEventListener('click', () => openUserManager(id));
    q('#GroupDuplicate')?.addEventListener('click', () => duplicateAction(id));
}

function toogleSelection(group_id: GroupId, toggle: boolean | undefined) {
    const box = document.getElementById('group-' + String(group_id));
    if (!box) return;
    const q = (sel: string) => box.querySelector<HTMLElement>(sel);

    if (toggle === true) {
        (q('.Group-checkbox input') as HTMLInputElement).checked = true;
        box.classList.add('GroupBackgroudSelected');
        q('.icon-users-1')?.classList.add('OrangeIcon');
        q('.group_number_users')?.classList.add('OrangeFont');

        const itemEl = document.createElement('div');
        itemEl.dataset['id'] = String(group_id);
        itemEl.innerHTML = `<a class='icon-cancel'></a><p>${q('#group_name')?.innerHTML ?? ''}</p>`;
        qs('.DeleteGroupList')?.appendChild(itemEl);
        itemEl.querySelector('a')?.addEventListener('click', () => {
            (q('.Group-checkbox input') as HTMLInputElement).checked = false;
            toogleSelection(group_id, undefined);
        });
        updateSelectionPanel();

        const optEl = document.createElement('option');
        optEl.value = String(group_id);
        optEl.textContent = q('#group_name')?.innerHTML ?? '';
        qs('#MergeOptionsChoices')?.appendChild(optEl);
    } else {
        (q('.Group-checkbox input') as HTMLInputElement).checked = false;
        box.classList.remove('GroupBackgroudSelected');
        q('.icon-users-1')?.classList.remove('OrangeIcon');
        q('.group_number_users')?.classList.remove('OrangeFont');
        qsa<HTMLElement>('.DeleteGroupList div').forEach((el) => {
            if (el.dataset['id'] === String(group_id)) el.remove();
        });
        updateSelectionPanel();
        qs<HTMLOptionElement>(`#MergeOptionsChoices option[value="${group_id}"]`)?.remove();
    }
}

function deleteGroup(id: GroupId) {
    const name =
        document.getElementById('group-' + String(id))?.querySelector<HTMLElement>('#group_name')
            ?.innerHTML ?? '';
    if (!window.confirm(str_delete.replace('%s', name))) return;

    void pwgPost('pwg.groups.delete', `group_id=${String(id)}&pwg_token=${pwg_token}`)
        .then((data) => {
            if (data.stat === 'ok') {
                document.getElementById('group-' + String(id))?.remove();
                qs(`.DeleteGroupList div[data-id="${String(id)}"]`)?.remove();
                qs(`#MergeOptionsChoices option[value="${String(id)}"]`)?.remove();
                updateBadge();
                window.alert(str_group_deleted.replace('%s', name));
            }
        })
        .catch((e: unknown) => console.error(e));
}

function renameGroup(id: GroupId, newName: string) {
    const ts: TS = newTemporaryState();
    const validateEl = grpQ(id, '.group-rename .validate');
    const spanEl = grpQ(id, '.group-rename span');
    if (validateEl) {
        ts.changeHTML(validateEl, "<i class='animate-spin icon-spin6'></i>");
        ts.removeClass(validateEl, 'icon-ok');
    }
    if (spanEl) ts.changeAttribute(spanEl, 'style', 'pointer-events: none');

    if (newName.replace(/\s/g, '').length !== 0) {
        void pwgPost<{ groups: Group[] }>(
            'pwg.groups.setInfo',
            `group_id=${String(id)}&pwg_token=${pwg_token}&name=${encodeURIComponent(newName)}`
        )
            .then((data) => {
                ts.reverse();
                if (data.stat === 'ok' && data.result !== undefined) {
                    const updatedName = data.result.groups[0]!.name;
                    showMessage(grpQ(id, '.groupMessage'), str_renaming_done);
                    const nameEl = grpQ(id, '#group_name');
                    if (nameEl) nameEl.innerHTML = updatedName;
                    displayRenameForm(false, id);
                } else {
                    showMessage(grpQ(id, '.groupError'), str_name_taken);
                }
            })
            .catch((e: unknown) => console.error(e));
    } else {
        ts.reverse();
        showMessage(grpQ(id, '.groupError'), str_name_not_empty);
    }
}

function displayRenameForm(doDisplay: boolean, grp_id: GroupId) {
    const renameEl = grpQ(grp_id, '.group-rename');
    const pencil = grpQ(grp_id, '.Group-name-container .icon-pencil');
    const nameP = grpQ(grp_id, '.Group-name-container p');
    if (doDisplay) {
        if (renameEl) renameEl.style.display = 'flex';
        hide(pencil);
        if (nameP) nameP.style.opacity = '0';
    } else {
        hide(renameEl);
        if (pencil) pencil.removeAttribute('style');
        if (nameP) nameP.style.opacity = '1';
    }
}

function setDefaultGroup(id: GroupId, is_default: boolean) {
    const defaultBtn = grpQ(id, '#GroupDefault');
    const token = grpQ(id, '.is-default-token');
    if (defaultBtn) {
        defaultBtn.style.width = defaultBtn.offsetWidth + 'px';
        defaultBtn.innerHTML = "<i class='icon-spin6 animate-spin'> </i>";
        defaultBtn.classList.remove('icon-star');
        defaultBtn.style.pointerEvents = 'none';
        defaultBtn.style.textAlign = 'center';
    }
    token?.classList.add('icon-spin6', 'animate-spin');
    token?.classList.remove('icon-star');

    void pwgPost(
        'pwg.groups.setInfo',
        `group_id=${String(id)}&pwg_token=${pwg_token}&is_default=${String(is_default)}`
    )
        .then((data) => {
            hide(grpQ(id, '#GroupOptions'));
            if (data.stat === 'ok') setupDefaultActions(id, is_default);
        })
        .catch((e: unknown) => console.error(e));
}

function setupDefaultActions(id: GroupId, is_default: boolean) {
    const defaultBtn = grpQ(id, '#GroupDefault');
    const token = grpQ(id, '.is-default-token');
    if (defaultBtn) {
        defaultBtn.removeAttribute('style');
        defaultBtn.classList.add('icon-star');
    }
    token?.classList.remove('icon-spin6', 'animate-spin');
    token?.classList.add('icon-star');

    const newDefaultBtn =
        defaultBtn !== undefined && defaultBtn !== null
            ? (defaultBtn.cloneNode(true) as HTMLElement)
            : null;
    const newToken =
        token !== undefined && token !== null ? (token.cloneNode(true) as HTMLElement) : null;
    if (defaultBtn !== undefined && defaultBtn !== null && newDefaultBtn !== null)
        defaultBtn.replaceWith(newDefaultBtn);
    if (token !== undefined && token !== null && newToken !== null) token.replaceWith(newToken);

    if (is_default) {
        if (newDefaultBtn !== null) newDefaultBtn.innerHTML = str_unset_default;
        if (newToken !== null) {
            newToken.title = str_unset_default;
            newToken.classList.remove('deactivate');
        }
        newDefaultBtn?.addEventListener('click', () => setDefaultGroup(id, false));
        newToken?.addEventListener('click', () => setDefaultGroup(id, false));
    } else {
        if (newDefaultBtn !== null) newDefaultBtn.innerHTML = str_set_default;
        if (newToken !== null) {
            newToken.title = str_set_default;
            newToken.classList.add('deactivate');
        }
        newDefaultBtn?.addEventListener('click', () => setDefaultGroup(id, true));
    }
}

function duplicateAction(id: GroupId) {
    const ts: TS = newTemporaryState();
    const dupEl = grpQ(id, '#GroupDuplicate');
    if (dupEl) {
        ts.changeHTML(dupEl, "<i class='icon-spin6 animate-spin'> </i>");
        ts.removeClass(dupEl, 'icon-docs');
        ts.changeAttribute(dupEl, 'style', 'pointer-events: none; text-align: center;');
    }

    let copy_name = (grpQ(id, '#group_name')?.innerHTML ?? '') + str_copy;
    const name_exist = (name: string) =>
        qsa('.Group-name-container p').some((el) => el.innerHTML === name);
    let i = 1;
    while (name_exist(copy_name)) {
        copy_name =
            (grpQ(id, '#group_name')?.innerHTML ?? '') + str_other_copy.replace('%s', String(i++));
    }

    void pwgPost<{ groups: Group[] }>(
        'pwg.groups.duplicate',
        `group_id=${String(id)}&pwg_token=${pwg_token}&copy_name=${encodeURIComponent(copy_name)}`
    )
        .then((data) => {
            ts.reverse();
            if (data.stat === 'ok' && data.result !== undefined) {
                hide(grpQ(id, '#GroupOptions'));
                const newGroup = data.result.groups[0]!;
                const gb = createGroup(newGroup);
                grp(id)?.after(gb);
                setupGroupBox(gb);
                updateBadge();
                if (newGroup.is_default === 'true' || newGroup.is_default === true)
                    setupDefaultActions(newGroup.id, true);
            }
        })
        .catch((e: unknown) => console.error(e));
}

/*------- Selection mode -------*/

document.addEventListener('DOMContentLoaded', () => {
    // Close user-list popup on ESC or outside click
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            const userList = document.getElementById('UserList');
            if (userList) userList.style.display = 'none';
        }
    });
    document.addEventListener('click', (e) => {
        if (!(e.target as Element).closest('.UserListPopInContainer')) {
            const userList = document.getElementById('UserList');
            if (userList) userList.style.display = 'none';
        }
    });

    document
        .getElementById('CancelMerge')
        ?.addEventListener('click', () => updateSelectionPanel('Selection'));
    document
        .getElementById('CancelDelete')
        ?.addEventListener('click', () => updateSelectionPanel('Selection'));
    document.getElementById('addGroupClose')?.addEventListener('click', () => hideAddGroupForm());

    const toggleSel = document.getElementById('toggleSelectionMode') as HTMLInputElement | null;
    if (toggleSel) {
        toggleSel.checked = false;
        toggleSel.addEventListener('click', () => {
            if (toggleSel.checked) {
                qsa('.in-selection-mode').forEach((el) => {
                    el.style.display = '';
                });
                qsa('.not-in-selection-mode').forEach((el) => {
                    el.style.display = 'none';
                });
                qsa('.GroupManagerButtons').forEach((el) => el.classList.remove('visible'));
            } else {
                qsa('.in-selection-mode').forEach((el) => {
                    el.style.display = 'none';
                });
                qsa('.not-in-selection-mode').forEach((el) => {
                    el.removeAttribute('style');
                });
                qsa<HTMLInputElement>('.Group-checkbox input').forEach((el) => {
                    el.checked = false;
                    el.dispatchEvent(new Event('change'));
                });
            }
        });
    }
});

let state = 'NoSelection';

function updateSelectionPanel(changedState = '') {
    const numSelect = qsa('.DeleteGroupList div').length;
    if (numSelect === 0) updateStatePanel('NoSelection');
    else if (changedState === '') {
        if (numSelect === 1 && state !== 'ConfirmDeletion') updateStatePanel('OneSelected');
        if (numSelect > 1 && state === 'OneSelected') updateStatePanel('Selection');
    } else {
        updateStatePanel(
            changedState === 'Selection' && numSelect === 1 ? 'OneSelected' : changedState
        );
    }
    const numEl = qs('.number-Selected');
    if (numEl) numEl.innerHTML = String(numSelect);
}

function updateStatePanel(newState = 'Selection') {
    state = newState;
    const els = {
        delete: document.getElementById('DeleteSelectionMode'),
        merge: document.getElementById('MergeSelectionMode'),
        mergeOpts: document.getElementById('MergeOptionsBlock'),
        confirm: document.getElementById('ConfirmGroupAction'),
        nothing: document.getElementById('nothing-selected'),
    };

    switch (newState) {
        case 'OneSelected':
            show(els.delete);
            show(els.merge);
            buttonUnavailable(els.merge);
            buttonAvailable(els.delete, "updateSelectionPanel('ConfirmDeletion')");
            hide(els.mergeOpts);
            hide(els.confirm);
            break;
        case 'ConfirmDeletion':
            hide(els.delete);
            hide(els.merge);
            hide(els.mergeOpts);
            show(els.confirm);
            break;
        case 'Selection':
            show(els.delete);
            show(els.merge);
            buttonAvailable(els.merge, "updateSelectionPanel('OptionMerge')");
            buttonAvailable(els.delete, "updateSelectionPanel('ConfirmDeletion')");
            hide(els.mergeOpts);
            hide(els.confirm);
            break;
        case 'OptionMerge':
            hide(els.delete);
            hide(els.merge);
            show(els.mergeOpts);
            hide(els.confirm);
            break;
    }
    if (newState === 'NoSelection') {
        show(els.delete);
        show(els.merge);
        buttonUnavailable(els.merge);
        buttonUnavailable(els.delete);
        qsa('.SelectionModeGroup').forEach(hide);
        show(els.nothing);
        hide(els.mergeOpts);
        hide(els.confirm);
    } else {
        qsa('.SelectionModeGroup').forEach((el) => {
            el.style.display = '';
        });
        hide(els.nothing);
    }
}

function buttonAvailable(el: HTMLElement | null, onClickState: string) {
    if (!el) return;
    el.classList.remove('unavailable');
    el.onclick = () => updateSelectionPanel(onClickState);
}
function buttonUnavailable(el: HTMLElement | null) {
    if (!el) return;
    el.classList.add('unavailable');
    el.onclick = null;
}

/*------- Merge -------*/

qs('.ConfirmMergeButton')?.addEventListener('click', () => {
    const mergeBtn = qs('.ConfirmMergeButton')!;
    const ts: TS = newTemporaryState();
    ts.changeAttribute(mergeBtn, 'style', 'pointer-events: none');
    ts.changeHTML(mergeBtn, "<i class='icon-spin6 animate-spin'> </i>");
    ts.removeClass(mergeBtn, 'icon-ok');

    const merge_group: string[] = [];
    const name_merge: string[] = [];
    let str_merge_group = '';
    let name_dest = '';
    const dest_grp = qs<HTMLSelectElement>('#MergeOptionsChoices')?.value ?? '';

    qsa('.DeleteGroupList div').forEach((el) => {
        const eid = el.dataset['id'] ?? '';
        if (eid !== dest_grp) {
            str_merge_group += '&merge_group_id[]=' + eid;
            merge_group.push(eid);
            name_merge.push(el.querySelector('p')?.innerHTML ?? '');
        } else {
            name_dest = el.querySelector('p')?.innerHTML ?? '';
        }
    });

    void fetch(`${config.wsUrl}format=json&method=pwg.groups.merge`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `destination_group_id=${dest_grp}${str_merge_group}&pwg_token=${pwg_token}`,
    })
        .then((r) => r.json() as Promise<WsResponse>)
        .then((data) => {
            ts.reverse();
            if (data.stat === 'ok') {
                updateSelectionPanel('Selection');
                merge_group.forEach((id) => {
                    const el = document.getElementById('group-' + id);
                    if (el) {
                        el.style.transition = 'opacity 0.4s';
                        el.style.opacity = '0';
                        setTimeout(() => el.remove(), 400);
                    }
                });
                toogleSelection(dest_grp, false);
                const delList = qs('.DeleteGroupList');
                if (delList) delList.innerHTML = '';
                const mergeOpts = qs('#MergeOptionsChoices');
                if (mergeOpts) mergeOpts.innerHTML = '';

                window.alert(
                    str_merged_into.replace('%s1', name_merge.toString()).replace('%s2', name_dest)
                );

                const numUsersEl = grpQ(dest_grp, '.group_number_users');
                if (numUsersEl) numUsersEl.innerHTML = "<i class='icon-spin6 animate-spin'> </i>";
                void pwgPost<{ users: unknown[] }>(
                    'pwg.users.getList',
                    `group_id=${String(dest_grp)}`
                ).then((rd) => {
                    const number = rd.result?.users.length ?? 0;
                    if (numUsersEl)
                        numUsersEl.innerHTML =
                            number + ' ' + (number > 1 ? str_members_default : str_member_default);
                    updateBadge();
                });
            }
        })
        .catch((e: unknown) => console.error(e));
});

/*------- Delete selection -------*/

qs('.ConfirmDeleteButton')?.addEventListener('click', () => {
    const names: string[] = [],
        ids: string[] = [];
    qsa('.DeleteGroupList div').forEach((el) => {
        const id = el.dataset['id'] ?? '';
        names.push(grpQ(id, '#group_name')?.innerHTML ?? '');
        ids.push(id);
    });

    const deleteBtn = qs('.ConfirmDeleteButton')!;
    const ts: TS = newTemporaryState();
    ts.changeAttribute(deleteBtn, 'style', 'pointer-events: none');
    ts.changeHTML(deleteBtn, "<i class='icon-spin6 animate-spin'> </i>");
    ts.removeClass(deleteBtn, 'icon-ok');

    const body = ids.map((id) => `group_id[]=${id}`).join('&') + `&pwg_token=${pwg_token}`;
    void fetch(config.wsUrl + 'format=json&method=pwg.groups.delete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body,
    })
        .then((r) => r.json() as Promise<WsResponse>)
        .then((data) => {
            if (data.stat === 'ok') {
                qsa('.DeleteGroupList div').forEach((el) => {
                    const id = el.dataset['id'] ?? '';
                    el.remove();
                    document.getElementById('group-' + id)?.remove();
                    qs(`#MergeOptionsChoices option[value="${id}"]`)?.remove();
                });
                ts.reverse();
                updateSelectionPanel('NoSelection');
                window.alert(str_groups_deleted.replace('%s', names.toString()));
                updateBadge();
            }
        })
        .catch((e: unknown) => console.error(e));
});

/*------- User Manager -------*/

interface CachedUser {
    id: number | string;
    username: string;
}

interface UsersCacheLike {
    key?: string;
    storage: Record<string, string>;
}

let addUserTs: TomSelect | null = null;
let usersCache: UsersCacheLike | null = null;
let usersInGroup: CachedUser[] = [];
const maxOffsetUserCont = 322;
let updateUserSearch: () => void = () => undefined;

const dissociateUserInfoEl = document.createElement('div');
dissociateUserInfoEl.className = 'ValidationUserDissociated';
dissociateUserInfoEl.innerHTML = "<p class='icon-ok'></p>";
dissociateUserInfoEl.style.display = 'none';
qs('.group-name-block')?.appendChild(dissociateUserInfoEl);

const associateUserInfoEl = document.createElement('div');
associateUserInfoEl.className = 'ValidationUserAssociated';
associateUserInfoEl.innerHTML = "<p class='icon-ok'></p>";

document.addEventListener('DOMContentLoaded', () => {
    const addUserSelect = qs<HTMLSelectElement>('.AddUserBlock select');
    if (addUserSelect) {
        addUserTs = new TomSelect(addUserSelect, {});
    }

    let idSearch = '';
    qs('.UserSearch input')?.addEventListener('focus', () => {
        if (idSearch !== document.getElementById('UserList')?.dataset['groupId']) {
            updateUserSearch();
        }
    });

    updateUserSearch = function () {
        if (addUserTs === null) return;
        addUserTs.clear();
        if (usersCache?.key === undefined) {
            usersCache = new UsersCache({
                serverKey: cacheData.CACHE_KEYS.users,
                serverId: cacheData.CACHE_KEYS._hash,
                rootUrl: cacheData.ROOT_URL,
            }) as unknown as UsersCacheLike;
        }
        const raw = usersCache.storage[usersCache.key ?? ''] ?? '{"data":[]}';
        const cachedData = (JSON.parse(raw) as { data: CachedUser[] }).data;
        cachedData.forEach((u) =>
            addUserTs!.addOption({ value: String(u.id), text: u.username })
        );
        idSearch = document.getElementById('UserList')?.dataset['groupId'] ?? '';
        Object.values(
            addUserTs.options as Record<string, { value: string; text: string }>
        ).forEach((opt) => {
            if (opt.text === 'guest') addUserTs!.removeOption(opt.value);
        });
        qsa('.UsernameBlock').forEach((el) => {
            const uid = el.dataset['id'];
            if (uid !== undefined && uid !== '') addUserTs!.removeOption(uid);
        });
    };
});

function openUserManager(grp_id: GroupId) {
    const triggerEl = grpQ(grp_id, '#UserListTrigger');
    const ts: TS = newTemporaryState();
    if (triggerEl) {
        ts.removeClass(triggerEl, 'icon-user-1');
        ts.changeAttribute(triggerEl, 'style', 'pointer-events: none');
        ts.changeHTML(triggerEl, "<i class='icon-spin6 animate-spin'> </i>");
    }

    void pwgPost<{ users: CachedUser[] }>('pwg.users.getList', `group_id=${String(grp_id)}`)
        .then((data) => {
            ts.reverse();
            if (data.stat === 'ok' && data.result !== undefined) {
                const nameEl = qs('.group-name-block p');
                if (nameEl)
                    nameEl.innerHTML =
                        (grpQ(grp_id, '#group_name')?.innerHTML ?? '') + ' / ' + str_user_list;
                const listEl = qs('.UsersInGroupList');
                if (listEl) listEl.innerHTML = '';
                show(document.getElementById('UserList'));

                usersInGroup = data.result.users;
                usersInGroup.sort((a, b) =>
                    a.username.toLowerCase() < b.username.toLowerCase() ? -1 : 1
                );

                const listContainer = qs('.UsersInGroupList')!;
                let i = 0;
                while (listContainer.offsetHeight <= maxOffsetUserCont && i < usersInGroup.length) {
                    listContainer.appendChild(
                        getUserDisplay(usersInGroup[i]!.username, usersInGroup[i]!.id, grp_id)
                    );
                    i++;
                }
                while (listContainer.offsetHeight > maxOffsetUserCont) {
                    qs('.UsernameBlock', listContainer)?.remove();
                }

                updateMembernumber(usersInGroup.length, grp_id);
                if (document.getElementById('UserList'))
                    document.getElementById('UserList')!.dataset['groupId'] = String(grp_id);
                const linkEl = qs<HTMLAnchorElement>('.LinkUserManager a');
                if (linkEl)
                    linkEl.href = config.adminUrl + 'page=user_list&group=' + String(grp_id);
            }
        })
        .catch((e: unknown) => console.error(e));
}

function getUserDisplay(username: string, user_id: number | string, grp_id: GroupId): HTMLElement {
    const userBlock = document.createElement('div');
    userBlock.className = 'UsernameBlock';
    userBlock.dataset['id'] = String(user_id);
    userBlock.innerHTML = `<span class="icon-user-1"></span><p>${username}</p><div class="Tooltip"><span class="icon-cancel"></span><p class="TooltipText">${str_user_dissociate}</p></div>`;

    const listEl = qs('.UsersInGroupList');
    while (listEl && listEl.offsetHeight > maxOffsetUserCont) {
        qsa('.UsernameBlock', listEl).pop()?.remove();
    }

    userBlock.querySelector('.icon-cancel')?.addEventListener('click', () => {
        const cancelIcon = userBlock.querySelector<HTMLElement>('.icon-cancel')!;
        cancelIcon.classList.add('icon-spin6', 'animate-spin');
        cancelIcon.style.pointerEvents = 'none';
        cancelIcon.classList.remove('icon-cancel');

        void pwgPost(
            'pwg.groups.deleteUser',
            `group_id=${String(grp_id)}&user_id=${String(user_id)}&pwg_token=${pwg_token}`
        ).then((data) => {
            if (data.stat === 'ok') {
                fadeOut(associateUserInfoEl, 0);
                dissociateUserInfoEl.querySelector('p')!.innerHTML = str_user_dissociated.replace(
                    '%s',
                    username
                );
                show(dissociateUserInfoEl);
                qsa<HTMLElement>('.UsernameBlock').forEach((el) => {
                    el.style.marginRight = '10px';
                    el.style.border = 'none';
                });
                userBlock.remove();
                updateUserSearch();
                usersInGroup = usersInGroup.filter((u) => u.id !== user_id);
                const badge = qs('.UserNumberBadge');
                updateMembernumber(parseInt(badge?.innerHTML ?? '0') - 1, grp_id);
            }
        });
    });

    return userBlock;
}

function updateMembernumber(number: number, grp_id: GroupId) {
    const groupUsersEl = qs(`.GroupContainer[data-id="${String(grp_id)}"] .group_number_users`);
    if (groupUsersEl)
        groupUsersEl.innerHTML =
            String(number) + ' ' + (number > 1 ? str_members_default : str_member_default);
    const badge = qs('.UserNumberBadge');
    if (badge) badge.innerHTML = String(number);
    const shown1 = qs('.AmountOfUsersShown strong:nth-child(1)');
    if (shown1) shown1.innerHTML = String(qsa('.UsernameBlock').length);
    const shown2 = qs('.AmountOfUsersShown strong:nth-child(2)');
    if (shown2) shown2.innerHTML = String(number);
}

qs('.CloseUserList')?.addEventListener('click', () => hide(document.getElementById('UserList')));

qs('.AddUserBlock button')?.addEventListener('click', () => {
    const grp_id = document.getElementById('UserList')?.dataset['groupId'];
    const id = addUserTs?.getValue() ?? '';
    if (id === '' || grp_id === undefined) return;

    const ts: TS = newTemporaryState();
    const submitBtn = document.getElementById('UserSubmit');
    if (submitBtn) {
        ts.changeHTML(submitBtn, "<i class='icon-spin6 animate-spin'> </i>");
        ts.removeClass(submitBtn, 'icon-user-add');
        ts.changeAttribute(submitBtn, 'style', 'pointer-events:none');
    }

    void pwgPost(
        'pwg.groups.addUser',
        `group_id=${grp_id}&user_id=${String(id)}&pwg_token=${pwg_token}`
    ).then((data) => {
        ts.reverse();
        if (data.stat === 'ok') {
            const raw = usersCache?.storage[usersCache.key ?? ''] ?? '{"data":[]}';
            const cached = (JSON.parse(raw) as { data: CachedUser[] }).data;
            let username = 'undefined';
            cached.forEach((u) => {
                if (String(u.id) === String(id)) username = u.username;
            });

            const listContainer = qs('.UsersInGroupList')!;
            const idStr = Array.isArray(id) ? id[0]! : id;
            const userBlock = getUserDisplay(username, idStr, grp_id);
            listContainer.prepend(userBlock);

            fadeOut(dissociateUserInfoEl, 0);
            qsa<HTMLElement>('.UsernameBlock')
                .slice(1)
                .forEach((el) => {
                    el.style.marginRight = '10px';
                    el.style.border = 'none';
                });
            associateUserInfoEl.remove();
            userBlock.after(associateUserInfoEl);
            associateUserInfoEl.querySelector('p')!.innerHTML = str_user_associated;
            show(associateUserInfoEl);

            updateUserSearch();
            usersInGroup.push({ username, id: idStr });

            while (listContainer.offsetHeight > maxOffsetUserCont)
                qsa('.UsernameBlock', listContainer).pop()?.remove();
            const badge = qs('.UserNumberBadge');
            updateMembernumber(parseInt(badge?.innerHTML ?? '0') + 1, grp_id);
        }
    });
});

qs('.input-user-name')?.addEventListener('input', function (this: HTMLInputElement) {
    const searchString = this.value.toLowerCase();
    const grp_id = qs('.UserListPopIn')?.dataset['groupId'] ?? '';
    const listContainer = qs('.UsersInGroupList')!;
    if (searchString !== '') {
        const container = qs<HTMLElement>('.UsersInGroupListContainer');
        if (container) container.style.minHeight = container.offsetHeight + 'px';
        usersInGroup.forEach((u) => {
            const isSearched = u.username.toLowerCase().includes(searchString);
            const existing = qs<HTMLElement>(
                `.UsernameBlock[data-id="${String(u.id)}"]`,
                listContainer
            );
            if (existing !== null && !isSearched) existing.remove();
            else if (existing === null && isSearched)
                listContainer.prepend(getUserDisplay(u.username, u.id, grp_id));
        });
    } else {
        const container = qs<HTMLElement>('.UsersInGroupListContainer');
        if (container) container.style.minHeight = '';
        listContainer.innerHTML = '';
        let i = 0;
        while (listContainer.offsetHeight <= maxOffsetUserCont && i < usersInGroup.length) {
            listContainer.appendChild(
                getUserDisplay(usersInGroup[i]!.username, usersInGroup[i]!.id, grp_id)
            );
            i++;
        }
    }
    const shown1 = qs('.AmountOfUsersShown strong:nth-child(1)');
    if (shown1) shown1.innerHTML = String(qsa('.UsernameBlock', listContainer).length);
    while (listContainer.offsetHeight > maxOffsetUserCont)
        qsa('.UsernameBlock', listContainer).pop()?.remove();
});

export {};
