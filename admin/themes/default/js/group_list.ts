import TomSelect from 'tom-select';
import Cookies from 'js-cookie';
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
    rootUrl,
    serverId,
    serverKey,
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
    str_no_delete_confirmation,
    str_other_copy,
    str_renaming_done,
    str_set_default,
    str_unset_default,
    str_user_associated,
    str_user_dissociate,
    str_user_dissociated,
    str_user_list,
    str_yes_delete_confirmation,
} = getPageData<GroupListPageData>();

interface GroupListCacheData {
    CACHE_KEYS: { users: string; _hash: string };
    ROOT_URL: string;
    str_create: string;
}

const cacheData = getPageData<GroupListCacheData>('pwg-group-list-data');

let complete: any;
let copy_name: any;
let data: any;
let dest_grp: any;
let exist: any;
let group: any;
let groupBox: any;
let grp_id: any;
let item: any;
let merge_group: any;
let name_dest: any;
let name_merge: any;
let newgroup: any;
let option: any;
let searchString: any;
let str_merge_group: any;
let updateUserSearch: any;

const DELAY_FEEDBACK = 3000;

const qs = <T extends HTMLElement = HTMLElement>(sel: string, ctx: Element | Document = document) =>
    ctx.querySelector<T>(sel);
const qsa = <T extends HTMLElement = HTMLElement>(
    sel: string,
    ctx: Element | Document = document
) => Array.from(ctx.querySelectorAll<T>(sel));
const grp = (id: any) => document.getElementById('group-' + id);
const grpQ = (id: any, sel: string) => grp(id)?.querySelector<HTMLElement>(sel);

function pwgPost(method: string, body: string | URLSearchParams): Promise<any> {
    return fetch(`${config.wsUrl}format=json&method=${method}`, {
        method: 'POST',
        ...(typeof body === 'string'
            ? { headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body }
            : { body }),
    }).then((r) => r.json());
}

function show(el: HTMLElement | null | undefined) {
    if (el) el.style.display = '';
}
function hide(el: HTMLElement | null | undefined) {
    if (el) el.style.display = 'none';
}
function fadeIn(el: HTMLElement | null | undefined) {
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
// eslint-disable-next-line @typescript-eslint/no-explicit-any
type TS = any;
function tsChangeHTML(ts: TS, sel: string, html: string) {
    qsa(sel).forEach((el) => ts.changeHTML(el, html));
}
function tsChangeAttr(ts: TS, sel: string, attr: string, val: string) {
    qsa(sel).forEach((el) => ts.changeAttribute(el, attr, val));
}
function tsRemoveClass(ts: TS, sel: string, cls: string) {
    qsa(sel).forEach((el) => ts.removeClass(el, cls));
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
        const ts: TS = new (window as any).TemporaryState();
        qsa('.actionButtons button').forEach((el) => {
            ts.changeHTML(el, "<i class='icon-spin6 animate-spin'> </i>");
            ts.changeAttribute(el, 'style', 'pointer-events: none');
        });
        qsa('.actionButtons a').forEach((el) =>
            ts.changeAttribute(el, 'style', 'pointer-events: none')
        );

        if (name.replace(/\s/g, '').length !== 0) {
            pwgPost('pwg.groups.add', new URLSearchParams({ name, pwg_token }))
                .then((raw_data) => {
                    ts.reverse();
                    data = raw_data;
                    if (data.stat === 'ok') {
                        const inp = qs<HTMLInputElement>('.addGroupFormLabelAndInput input');
                        if (inp) inp.value = '';
                        group = data.result.groups[0];
                        newgroup = createGroup(group);
                        qs('#addGroupForm')?.after(newgroup);
                        setupGroupBox(newgroup);
                        updateBadge();
                    } else {
                        showMessage(qs('#addGroupForm .groupError'), str_name_not_empty);
                    }
                })
                .catch(console.log);
        } else {
            ts.reverse();
            showMessage(qs('#addGroupForm .groupError'), str_name_not_empty);
        }
    });

    qsa('.GroupContainer').forEach((el) => {
        if (el.id !== 'group-template') setupGroupBox(el);
    });
});

function createGroup(group: any): HTMLElement {
    const newgroup = document.getElementById('group-template')!.cloneNode(true) as HTMLElement;
    newgroup.id = 'group-' + group.id;
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
    if (cbLabel) cbLabel.setAttribute('for', 'Group-Checkbox-selection-' + group.id);
    const cbInput = q('.Group-checkbox input');
    if (cbInput) cbInput.id = 'Group-Checkbox-selection-' + group.id;
    const placeholder = q('.input-edit-group-name');
    if (placeholder) placeholder.setAttribute('placeholder', group.name);
    const nbUsers = q('.group_number_users');
    if (nbUsers)
        nbUsers.innerHTML =
            group.nb_users + ' ' + (group.nb_users > 1 ? str_members_default : str_member_default);
    const perms = q('.manage-permissions') as HTMLAnchorElement | null;
    if (perms) perms.href = config.adminUrl + 'page=group_perm&group_id=' + group.id;
    hideAddGroupForm();
    const colors = ['icon-red', 'icon-blue', 'icon-yellow', 'icon-purple', 'icon-green'];
    q('.icon-users-1')?.classList.add(colors[Number(group.id) % 5]);
    showMessage(q('.groupMessage'), str_group_created);
    return newgroup;
}

function setupGroupBox(groupBoxEl: HTMLElement) {
    const id = groupBoxEl.dataset['id'];
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
        renameGroup(id, (q('.group_name-editable') as HTMLInputElement)?.value ?? '');
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
    if (isDefault == '1') setupDefaultActions(id, true);
    else if (isDefault == '0') setupDefaultActions(id, false);

    q('.manage-users')?.addEventListener('click', () => openUserManager(id));
    q('#GroupDuplicate')?.addEventListener('click', () => duplicateAction(id));
}

function toogleSelection(group_id: any, toggle: any) {
    const box = document.getElementById('group-' + group_id);
    if (!box) return;
    const q = (sel: string) => box.querySelector<HTMLElement>(sel);

    if (toggle) {
        (q('.Group-checkbox input') as HTMLInputElement)!.checked = true;
        box.classList.add('GroupBackgroudSelected');
        q('.icon-users-1')?.classList.add('OrangeIcon');
        q('.group_number_users')?.classList.add('OrangeFont');

        const itemEl = document.createElement('div');
        itemEl.dataset['id'] = String(group_id);
        itemEl.innerHTML = `<a class='icon-cancel'></a><p>${q('#group_name')?.innerHTML ?? ''}</p>`;
        qs('.DeleteGroupList')?.appendChild(itemEl);
        itemEl.querySelector('a')?.addEventListener('click', () => {
            (q('.Group-checkbox input') as HTMLInputElement)!.checked = false;
            toogleSelection(group_id, undefined);
        });
        updateSelectionPanel();

        const optEl = document.createElement('option');
        optEl.value = String(group_id);
        optEl.textContent = q('#group_name')?.innerHTML ?? '';
        qs('#MergeOptionsChoices')?.appendChild(optEl);
    } else {
        (q('.Group-checkbox input') as HTMLInputElement)!.checked = false;
        box.classList.remove('GroupBackgroudSelected');
        q('.icon-users-1')?.classList.remove('OrangeIcon');
        q('.group_number_users')?.classList.remove('OrangeFont');
        qsa<HTMLElement>('.DeleteGroupList div').forEach((el) => {
            if (el.dataset['id'] == group_id) el.remove();
        });
        updateSelectionPanel();
        qs<HTMLOptionElement>(`#MergeOptionsChoices option[value="${group_id}"]`)?.remove();
    }
}

function deleteGroup(id: any) {
    const name =
        document.getElementById('group-' + id)?.querySelector<HTMLElement>('#group_name')
            ?.innerHTML ?? '';
    if (!window.confirm(str_delete.replace('%s', name))) return;

    pwgPost('pwg.groups.delete', `group_id=${id}&pwg_token=${pwg_token}`)
        .then((raw_data) => {
            data = raw_data;
            if (data.stat === 'ok') {
                document.getElementById('group-' + id)?.remove();
                qs(`.DeleteGroupList div[data-id="${id}"]`)?.remove();
                qs(`#MergeOptionsChoices option[value="${id}"]`)?.remove();
                updateBadge();
                window.alert(str_group_deleted.replace('%s', name));
            }
        })
        .catch(console.log);
}

function renameGroup(id: any, newName: any) {
    const ts: TS = new (window as any).TemporaryState();
    const validateEl = grpQ(id, '.group-rename .validate');
    const spanEl = grpQ(id, '.group-rename span');
    if (validateEl) {
        ts.changeHTML(validateEl, "<i class='animate-spin icon-spin6'></i>");
        ts.removeClass(validateEl, 'icon-ok');
    }
    if (spanEl) ts.changeAttribute(spanEl, 'style', 'pointer-events: none');

    if (String(newName).replace(/\s/g, '').length !== 0) {
        pwgPost(
            'pwg.groups.setInfo',
            `group_id=${id}&pwg_token=${pwg_token}&name=${encodeURIComponent(newName)}`
        )
            .then((raw_data) => {
                ts.reverse();
                data = raw_data;
                if (data.stat === 'ok') {
                    newName = data.result.groups[0].name;
                    showMessage(grpQ(id, '.groupMessage'), str_renaming_done);
                    const nameEl = grpQ(id, '#group_name');
                    if (nameEl) nameEl.innerHTML = newName;
                    displayRenameForm(false, id);
                } else {
                    showMessage(grpQ(id, '.groupError'), str_name_taken);
                }
            })
            .catch(console.log);
    } else {
        ts.reverse();
        showMessage(grpQ(id, '.groupError'), str_name_not_empty);
    }
}

function displayRenameForm(doDisplay: boolean, grp_id: any) {
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

function setDefaultGroup(id: any, is_default: boolean) {
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

    pwgPost('pwg.groups.setInfo', `group_id=${id}&pwg_token=${pwg_token}&is_default=${is_default}`)
        .then((raw_data) => {
            data = raw_data;
            hide(grpQ(id, '#GroupOptions'));
            if (data.stat === 'ok') setupDefaultActions(id, is_default);
        })
        .catch(console.log);
}

function setupDefaultActions(id: any, is_default: boolean) {
    const defaultBtn = grpQ(id, '#GroupDefault');
    const token = grpQ(id, '.is-default-token');
    if (defaultBtn) {
        defaultBtn.removeAttribute('style');
        defaultBtn.classList.add('icon-star');
    }
    token?.classList.remove('icon-spin6', 'animate-spin');
    token?.classList.add('icon-star');

    const newDefaultBtn = defaultBtn?.cloneNode(true) as HTMLElement;
    const newToken = token?.cloneNode(true) as HTMLElement;
    defaultBtn?.replaceWith(newDefaultBtn ?? defaultBtn);
    token?.replaceWith(newToken ?? token);

    if (is_default) {
        if (newDefaultBtn) newDefaultBtn.innerHTML = str_unset_default;
        if (newToken) {
            newToken.title = str_unset_default;
            newToken.classList.remove('deactivate');
        }
        newDefaultBtn?.addEventListener('click', () => setDefaultGroup(id, false));
        newToken?.addEventListener('click', () => setDefaultGroup(id, false));
    } else {
        if (newDefaultBtn) newDefaultBtn.innerHTML = str_set_default;
        if (newToken) {
            newToken.title = str_set_default;
            newToken.classList.add('deactivate');
        }
        newDefaultBtn?.addEventListener('click', () => setDefaultGroup(id, true));
    }
}

function duplicateAction(id: any) {
    const ts: TS = new (window as any).TemporaryState();
    const dupEl = grpQ(id, '#GroupDuplicate');
    if (dupEl) {
        ts.changeHTML(dupEl, "<i class='icon-spin6 animate-spin'> </i>");
        ts.removeClass(dupEl, 'icon-docs');
        ts.changeAttribute(dupEl, 'style', 'pointer-events: none; text-align: center;');
    }

    copy_name = (grpQ(id, '#group_name')?.innerHTML ?? '') + str_copy;
    const name_exist = (name: string) =>
        qsa('.Group-name-container p').some((el) => el.innerHTML === name);
    let i = 1;
    while (name_exist(copy_name)) {
        copy_name =
            (grpQ(id, '#group_name')?.innerHTML ?? '') + str_other_copy.replace('%s', String(i++));
    }

    pwgPost(
        'pwg.groups.duplicate',
        `group_id=${id}&pwg_token=${pwg_token}&copy_name=${encodeURIComponent(copy_name)}`
    )
        .then((raw_data) => {
            ts.reverse();
            data = raw_data;
            if (data.stat === 'ok') {
                hide(grpQ(id, '#GroupOptions'));
                group = data.result.groups[0];
                const gb = createGroup(group);
                grp(id)?.after(gb);
                setupGroupBox(gb);
                updateBadge();
                if (data.result.groups[0].is_default == 'true')
                    setupDefaultActions(data.result.groups[0].id, true);
            }
        })
        .catch(console.log);
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
    const els: Record<string, HTMLElement | null> = {
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
    const ts: TS = new (window as any).TemporaryState();
    ts.changeAttribute(mergeBtn, 'style', 'pointer-events: none');
    ts.changeHTML(mergeBtn, "<i class='icon-spin6 animate-spin'> </i>");
    ts.removeClass(mergeBtn, 'icon-ok');

    merge_group = [];
    str_merge_group = '';
    name_merge = [];
    name_dest = [];
    dest_grp = qs<HTMLSelectElement>('#MergeOptionsChoices')?.value ?? '';

    qsa('.DeleteGroupList div').forEach((el) => {
        const eid = el.dataset['id'];
        if (eid != dest_grp) {
            str_merge_group += '&merge_group_id[]=' + eid;
            merge_group.push(eid);
            name_merge.push(el.querySelector('p')?.innerHTML ?? '');
        } else {
            name_dest = el.querySelector('p')?.innerHTML ?? '';
        }
    });

    fetch(`${config.wsUrl}format=json&method=pwg.groups.merge`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `destination_group_id=${dest_grp}${str_merge_group}&pwg_token=${pwg_token}`,
    })
        .then((r) => r.json())
        .then((raw_data) => {
            ts.reverse();
            data = raw_data;
            if (data.stat === 'ok') {
                updateSelectionPanel('Selection');
                merge_group.forEach((id: any) => {
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
                pwgPost('pwg.users.getList', `group_id=${dest_grp}`).then((rd) => {
                    const number = rd.result.users.length;
                    if (numUsersEl)
                        numUsersEl.innerHTML =
                            number + ' ' + (number > 1 ? str_members_default : str_member_default);
                    updateBadge();
                });
            }
        })
        .catch(console.log);
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
    const ts: TS = new (window as any).TemporaryState();
    ts.changeAttribute(deleteBtn, 'style', 'pointer-events: none');
    ts.changeHTML(deleteBtn, "<i class='icon-spin6 animate-spin'> </i>");
    ts.removeClass(deleteBtn, 'icon-ok');

    const body = ids.map((id) => `group_id[]=${id}`).join('&') + `&pwg_token=${pwg_token}`;
    fetch(config.wsUrl + 'format=json&method=pwg.groups.delete', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body,
    })
        .then((r) => r.json())
        .then((raw_data) => {
            data = raw_data;
            if (data.stat === 'ok') {
                qsa('.DeleteGroupList div').forEach((el) => {
                    const id = el.dataset['id'];
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
        .catch(console.log);
});

/*------- User Manager -------*/

let selectizeTs: TomSelect | null = null;
let usersCache: any = {};
let usersInGroup: any[] = [];
const maxOffsetUserCont = 322;

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
        selectizeTs = new TomSelect(addUserSelect, {});
    }

    let idSearch = '';
    qs('.UserSearch input')?.addEventListener('focus', () => {
        if (idSearch !== document.getElementById('UserList')?.dataset['groupId']) {
            updateUserSearch();
        }
    });

    updateUserSearch = function () {
        if (!selectizeTs) return;
        selectizeTs.clear();
        if (!usersCache.key) {
            usersCache = new UsersCache({
                serverKey: cacheData.CACHE_KEYS.users,
                serverId: cacheData.CACHE_KEYS._hash,
                rootUrl: cacheData.ROOT_URL,
            });
        }
        const cachedData = JSON.parse(usersCache.storage[usersCache.key] ?? '{"data":[]}').data;
        cachedData.forEach((u: any) =>
            selectizeTs!.addOption({ value: String(u.id), text: u.username })
        );
        idSearch = document.getElementById('UserList')?.dataset['groupId'] ?? '';
        Object.values(selectizeTs.options as Record<string, any>).forEach((opt) => {
            if (opt.text === 'guest') selectizeTs!.removeOption(opt.value);
        });
        qsa('.UsernameBlock').forEach((el) => {
            const uid = el.dataset['id'];
            if (uid) selectizeTs!.removeOption(uid);
        });
    };
});

function openUserManager(grp_id: any) {
    const triggerEl = grpQ(grp_id, '#UserListTrigger');
    const ts: TS = new (window as any).TemporaryState();
    if (triggerEl) {
        ts.removeClass(triggerEl, 'icon-user-1');
        ts.changeAttribute(triggerEl, 'style', 'pointer-events: none');
        ts.changeHTML(triggerEl, "<i class='icon-spin6 animate-spin'> </i>");
    }

    pwgPost('pwg.users.getList', `group_id=${grp_id}`)
        .then((raw_data) => {
            ts.reverse();
            data = raw_data;
            if (data.stat === 'ok') {
                const nameEl = qs('.group-name-block p');
                if (nameEl)
                    nameEl.innerHTML =
                        (grpQ(grp_id, '#group_name')?.innerHTML ?? '') + ' / ' + str_user_list;
                const listEl = qs('.UsersInGroupList');
                if (listEl) listEl.innerHTML = '';
                show(document.getElementById('UserList'));

                usersInGroup = data.result.users;
                usersInGroup.sort((a: any, b: any) =>
                    a.username.toLowerCase() < b.username.toLowerCase() ? -1 : 1
                );

                const listContainer = qs('.UsersInGroupList')!;
                let i = 0;
                while (listContainer.offsetHeight <= maxOffsetUserCont && usersInGroup[i]) {
                    listContainer.appendChild(
                        getUserDisplay(usersInGroup[i].username, usersInGroup[i].id, grp_id)
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
                if (linkEl) linkEl.href = config.adminUrl + 'page=user_list&group=' + grp_id;
            }
        })
        .catch(console.log);
}

function getUserDisplay(username: string, user_id: any, grp_id: any): HTMLElement {
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

        pwgPost(
            'pwg.groups.deleteUser',
            `group_id=${grp_id}&user_id=${user_id}&pwg_token=${pwg_token}`
        ).then((rd) => {
            data = rd;
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
                usersInGroup = usersInGroup.filter((u: any) => u.id != user_id);
                const badge = qs('.UserNumberBadge');
                updateMembernumber(parseInt(badge?.innerHTML ?? '0') - 1, grp_id);
            }
        });
    });

    return userBlock;
}

function updateMembernumber(number: number, grp_id: any) {
    const groupUsersEl = qs(`.GroupContainer[data-id="${grp_id}"] .group_number_users`);
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
    const id = selectizeTs?.getValue() ?? '';
    if (!id) return;

    const ts: TS = new (window as any).TemporaryState();
    const submitBtn = document.getElementById('UserSubmit');
    if (submitBtn) {
        ts.changeHTML(submitBtn, "<i class='icon-spin6 animate-spin'> </i>");
        ts.removeClass(submitBtn, 'icon-user-add');
        ts.changeAttribute(submitBtn, 'style', 'pointer-events:none');
    }

    pwgPost('pwg.groups.addUser', `group_id=${grp_id}&user_id=${id}&pwg_token=${pwg_token}`).then(
        (rd) => {
            ts.reverse();
            data = rd;
            if (data.stat === 'ok') {
                const cached = JSON.parse(usersCache.storage[usersCache.key] ?? '{"data":[]}').data;
                let username = 'undefined';
                cached.forEach((u: any) => {
                    if (u.id == id) username = u.username;
                });

                const listContainer = qs('.UsersInGroupList')!;
                const userBlock = getUserDisplay(username, id, grp_id);
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
                usersInGroup.push({ username, id });

                while (listContainer.offsetHeight > maxOffsetUserCont)
                    qsa('.UsernameBlock', listContainer).pop()?.remove();
                const badge = qs('.UserNumberBadge');
                updateMembernumber(parseInt(badge?.innerHTML ?? '0') + 1, grp_id!);
            }
        }
    );
});

qs('.input-user-name')?.addEventListener('input', function (this: HTMLInputElement) {
    searchString = this.value.toLowerCase();
    grp_id = qs('.UserListPopIn')?.dataset['groupId'];
    const listContainer = qs('.UsersInGroupList')!;
    if (searchString !== '') {
        const container = qs<HTMLElement>('.UsersInGroupListContainer');
        if (container) container.style.minHeight = container.offsetHeight + 'px';
        usersInGroup.forEach((u: any) => {
            const isSearched = u.username.toLowerCase().includes(searchString);
            const existing = qs<HTMLElement>(`.UsernameBlock[data-id="${u.id}"]`, listContainer);
            if (existing && !isSearched) existing.remove();
            else if (!existing && isSearched)
                listContainer.prepend(getUserDisplay(u.username, u.id, grp_id));
        });
    } else {
        const container = qs<HTMLElement>('.UsersInGroupListContainer');
        if (container) container.style.minHeight = '';
        listContainer.innerHTML = '';
        let i = 0;
        while (listContainer.offsetHeight <= maxOffsetUserCont && usersInGroup[i]) {
            listContainer.appendChild(
                getUserDisplay(usersInGroup[i].username, usersInGroup[i].id, grp_id)
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
