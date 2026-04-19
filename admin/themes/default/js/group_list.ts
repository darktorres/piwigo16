import { initModule } from './moduleInit.js';
import { TemporaryState } from './common.js';
import { pwgConfirm } from './pwgConfirm.js';
import { UsersCache } from './LocalStorageCache.js';
import TomSelect from 'tom-select';

const DELAY_FEEDBACK = 3000;

interface GroupListConfig {
    pwgToken: string;
    strMemberDefault: string;
    strMembersDefault: string;
    strGroupCreated: string;
    strRenamingDone: string;
    strNameTaken: string;
    strNameNotEmpty: string;
    strGroupDeleted: string;
    strGroupsDeleted: string;
    strSetDefault: string;
    strUnsetDefault: string;
    strDelete: string;
    strYesDeleteConfirmation: string;
    strNoDeleteConfirmation: string;
    strUserAssociated: string;
    strUserDissociate: string;
    strUserDissociated: string;
    strUserList: string;
    strMergedInto: string;
    strCopy: string;
    strOtherCopy: string;
    serverKey: string;
    serverId: string;
    rootUrl: string;
}

interface GroupData {
    id: string | number;
    name: string;
    nb_users: number;
    is_default?: string | boolean;
}

interface UserEntry { username: string; id: string | number; }

let pwg_token = '';
let str_member_default = '';
let str_members_default = '';
let str_group_created = '';
let str_renaming_done = '';
let str_name_taken = '';
let str_name_not_empty = '';
let str_group_deleted = '';
let str_groups_deleted = '';
let str_set_default = '';
let str_unset_default = '';
let str_delete = '';
let str_yes_delete_confirmation = '';
let str_no_delete_confirmation = '';
let str_user_associated = '';
let str_user_dissociate = '';
let str_user_dissociated = '';
let str_user_list = '';
let str_merged_into = '';
let str_copy = '';
let str_other_copy = '';
let serverKey = '';
let serverId = '';
let rootUrl = '';

void str_group_deleted;

export function init(cfg: GroupListConfig): void {
    pwg_token = cfg.pwgToken;
    str_member_default = cfg.strMemberDefault;
    str_members_default = cfg.strMembersDefault;
    str_group_created = cfg.strGroupCreated;
    str_renaming_done = cfg.strRenamingDone;
    str_name_taken = cfg.strNameTaken;
    str_name_not_empty = cfg.strNameNotEmpty;
    str_group_deleted = cfg.strGroupDeleted;
    str_groups_deleted = cfg.strGroupsDeleted;
    str_set_default = cfg.strSetDefault;
    str_unset_default = cfg.strUnsetDefault;
    str_delete = cfg.strDelete;
    str_yes_delete_confirmation = cfg.strYesDeleteConfirmation;
    str_no_delete_confirmation = cfg.strNoDeleteConfirmation;
    str_user_associated = cfg.strUserAssociated;
    str_user_dissociate = cfg.strUserDissociate;
    str_user_dissociated = cfg.strUserDissociated;
    str_user_list = cfg.strUserList;
    str_merged_into = cfg.strMergedInto;
    str_copy = cfg.strCopy;
    str_other_copy = cfg.strOtherCopy;
    serverKey = cfg.serverKey;
    serverId = cfg.serverId;
    rootUrl = cfg.rootUrl;

    const dissociateUserInfo = document.createElement('div');
    dissociateUserInfo.className = 'ValidationUserDissociated';
    dissociateUserInfo.innerHTML = "<p class='icon-ok'></p>";
    document.querySelector('.group-name-block')?.appendChild(dissociateUserInfo);
    dissociateUserInfo.style.display = 'none';

    const associateUserInfo = document.createElement('div');
    associateUserInfo.className = 'ValidationUserAssociated';
    associateUserInfo.innerHTML = "<p class='icon-ok'></p>";

    /*------- Group Popin -------*/
    document.querySelector<HTMLElement>('.group_details_popup_trigger')?.addEventListener('click', function () {
        const el = document.querySelector<HTMLElement>('.Group_details-popup-container');
        if (el) el.style.display = '';
    });

    document.querySelector<HTMLElement>('.CloseGroupPopup')?.addEventListener('click', function () {
        const el = document.querySelector<HTMLElement>('.Group_details-popup-container');
        if (el) el.style.display = 'none';
    });

    function updateBadge(): void {
        const badge = document.querySelector<HTMLElement>('.badge-number');
        if (badge) badge.innerHTML = String(document.querySelectorAll('.GroupContainer').length - 2);
    }

    /*------- Add User toggle -------*/
    document.getElementById('form-btn')?.addEventListener('click', function () {
        document.getElementById('cancel')!.style.display = '';
        document.getElementById('addUserLabel')!.style.display = '';
        document.querySelector<HTMLElement>('.UserSearch')!.style.display = '';
        document.getElementById('UserSubmit')!.style.display = '';
        document.getElementById('form-btn')!.style.display = 'none';
        const userList = document.querySelector<HTMLElement>('.groups .list_user');
        if (userList) userList.style.maxHeight = '100px';
    });

    document.getElementById('cancel')?.addEventListener('click', function () {
        document.getElementById('cancel')!.style.display = 'none';
        document.getElementById('addUserLabel')!.style.display = 'none';
        document.querySelector<HTMLElement>('.UserSearch')!.style.display = 'none';
        document.getElementById('UserSubmit')!.style.display = 'none';
        document.getElementById('form-btn')!.style.display = '';
        const userList = document.querySelector<HTMLElement>('.groups .list_user');
        if (userList) userList.style.maxHeight = '200px';
    });

    /*------- Add Group toggle -------*/
    let isToggle = true;
    document.querySelector<HTMLElement>('.addGroupBlock')?.addEventListener('click', function () {
        if (isToggle) {
            deployAddGroupForm();
        } else {
            hideAddGroupForm();
        }
    });

    function deployAddGroupForm(): void {
        const block = document.querySelector<HTMLElement>('.addGroupBlock');
        if (block) {
            block.style.transition = 'all 0.4s ease';
            block.style.top = '20%';
            block.style.padding = '0px';
        }
        setTimeout(() => {
            const form = document.querySelector<HTMLElement>('#addGroupForm form');
            if (form) form.style.display = 'block';
            const input = document.getElementById('addGroupNameInput');
            if (input) input.focus();
        }, 400);
        isToggle = false;
    };

    function hideAddGroupForm(): void {
        const form = document.querySelector<HTMLElement>('#addGroupForm form');
        if (form) {
            form.style.opacity = '0';
            form.style.transition = 'opacity 0.4s ease';
        }
        setTimeout(() => {
            const block = document.querySelector<HTMLElement>('.addGroupBlock');
            if (block) {
                block.style.top = '50%';
                block.style.padding = '100px 0';
            }
            if (form) form.style.display = 'none';
            if (form) form.style.opacity = '1';
        }, 400);
        isToggle = true;
    };

    /*------- Add Group Submit -------*/
    document.querySelector('#addGroupForm form')?.addEventListener('submit', function (e) {
        e.preventDefault();
        const name = (document.querySelector<HTMLInputElement>('#addGroupForm input[type=text]'))!.value;
        const loadState = new TemporaryState();
        loadState.changeHTML(
            document.querySelectorAll('.actionButtons button'),
            "<i class='icon-spin6 animate-spin'> </i>",
        );
        loadState.changeAttribute(
            document.querySelectorAll('.actionButtons button'),
            'style',
            'pointer-events: none',
        );
        loadState.changeAttribute(
            document.querySelectorAll('.actionButtons a'),
            'style',
            'pointer-events: none',
        );

        if (name.replace(/\s/g, '').length!== 0) {
            fetch('ws.php?format=json&method=pwg.groups.add', {
                method: 'POST',
                body: new URLSearchParams({ name: name, pwg_token: pwg_token }),
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            })
            .then(r => r.text())
            .then(raw_data => {
                loadState.reverse();
                const data = JSON.parse(raw_data) as { stat: string; result: { groups: GroupData[] } };
                if (data.stat === 'ok') {
                    (document.querySelector<HTMLInputElement>('.addGroupFormLabelAndInput input'))!.value = '';
                    const group = data.result.groups[0]!;
                    const groupBox = createGroup(group);
                    document.getElementById('addGroupForm')!.insertAdjacentElement('afterend', groupBox);
                    setupGroupBox(groupBox);
                    updateBadge();
                } else {
                    const error = document.querySelector<HTMLElement>('#addGroupForm .groupError');
                    if (error) {
                        error.innerHTML = str_name_not_empty;
                        error.style.display = '';
                        setTimeout(() => { error.style.display = 'none'; }, DELAY_FEEDBACK);
                    }
                }
            })
            .catch((error: unknown) => {
                loadState.reverse();
                console.log(error);
            });
        } else {
            loadState.reverse();
            const error = document.querySelector<HTMLElement>('#addGroupForm .groupError');
            if (error) {
                error.innerHTML = str_name_not_empty;
                error.style.display = '';
                setTimeout(() => { error.style.display = 'none'; }, DELAY_FEEDBACK);
            }
        }
    });

    function createGroup(group: GroupData): HTMLElement {
        const template = document.getElementById('group-template')!;
        const newgroup = template.cloneNode(true) as HTMLElement;
        newgroup.id = 'group-' + String(group.id);
        newgroup.setAttribute('data-id', String(group.id));

        const nameEl = newgroup.querySelector<HTMLElement>('#group_name');
        if (nameEl) nameEl.innerHTML = group.name;

        const editableEl = newgroup.querySelector<HTMLInputElement>('.group_name-editable');
        if (editableEl) editableEl.value = group.name;

        const checkbox = newgroup.querySelector<HTMLElement>('.Group-checkbox label');
        if (checkbox) checkbox.setAttribute('for', 'Group-Checkbox-selection-' + String(group.id));

        const checkboxInput = newgroup.querySelector<HTMLInputElement>('.Group-checkbox input');
        if (checkboxInput) checkboxInput.id = 'Group-Checkbox-selection-' + String(group.id);

        const placeholder = newgroup.querySelector<HTMLInputElement>('.input-edit-group-name');
        if (placeholder) placeholder.setAttribute('placeholder', group.name);

        const userCount = newgroup.querySelector<HTMLElement>('.group_number_users');
        if (userCount) userCount.innerHTML = String(group.nb_users) + ' ' + (group.nb_users > 1 ? str_members_default : str_member_default);

        const editableHtml = newgroup.querySelector<HTMLElement>('.group_name-editable');
        if (editableHtml) editableHtml.innerHTML = group.name;

        const manageLink = newgroup.querySelector<HTMLAnchorElement>('.manage-permissions');
        if (manageLink) manageLink.href = 'admin.php?page=group_perm&group_id=' + String(group.id);

        hideAddGroupForm();

        const colors = ['icon-red', 'icon-blue', 'icon-yellow', 'icon-purple', 'icon-green'];
        const colorId = Number(group.id) % 5;
        const icon = newgroup.querySelector<HTMLElement>('.icon-users-1');
        if (icon) icon.classList.add(colors[colorId]!);

        const msg = newgroup.querySelector<HTMLElement>('.groupMessage');
        if (msg) {
            msg.innerHTML = str_group_created;
            msg.style.display = '';
            setTimeout(() => { msg.style.display = 'none'; }, DELAY_FEEDBACK);
        }
        return newgroup;
    };

    function setupGroupBox(groupBox: HTMLElement): void {
        const id = groupBox.getAttribute('data-id') ?? '';

        const checkbox = groupBox.querySelector<HTMLInputElement>(".Group-checkbox input[type='checkbox']");
        if (checkbox) {
            checkbox.addEventListener('change', function () {
                toggleSelection(id, this.checked);
            });
            checkbox.checked = false;
        }

        const dropdown = groupBox.querySelector<HTMLElement>('.group-dropdown-options');
        if (dropdown) {
            dropdown.addEventListener('click', function () {
                const options = groupBox.querySelector<HTMLElement>('#GroupOptions');
                if (options) options.style.display = window.getComputedStyle(options).display === 'none' ? 'block' : 'none';
            });
        }

        const deleteBtn = groupBox.querySelector<HTMLElement>('#GroupDelete');
        if (deleteBtn) deleteBtn.addEventListener('click', () => deleteGroup(id));

        document.querySelectorAll<HTMLElement>('#group-' + id + ' .Group-name .icon-pencil, #group-' + id + ' #GroupEdit').forEach(el => {
            el.addEventListener('click', function () {
                displayRenameForm(true, id);
                setTimeout(() => {
                    const opts = groupBox.querySelector<HTMLElement>('#GroupOptions');
                    if (opts) opts.style.display = 'none';
                }, 10);
            });
        });

        const validateBtn = groupBox.querySelector<HTMLElement>('.group-rename .validate');
        if (validateBtn) {
            validateBtn.addEventListener('click', function () {
                const form = groupBox.querySelector<HTMLElement>('.group-rename form');
                if (form) form.dispatchEvent(new Event('submit'));
            });
        }

        const renameForm = groupBox.querySelector<HTMLElement>('.group-rename form');
        if (renameForm) {
            renameForm.addEventListener('submit', function (e) {
                e.preventDefault();
                const input = groupBox.querySelector<HTMLInputElement>('.group_name-editable');
                renameGroup(id, input?.value ?? '');
            });
        }

        const cancelBtn = groupBox.querySelector<HTMLElement>('.group-rename .icon-cancel');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                displayRenameForm(false, id);
                const input = groupBox.querySelector<HTMLInputElement>('.group_name-editable');
                const nameContainer = groupBox.querySelector<HTMLElement>('.Group-name-container p');
                if (input && nameContainer) input.value = nameContainer.innerHTML;
            });
        }

        document.addEventListener('mouseup', function (e) {
            e.stopPropagation();
            const option_is_clicked = Array.from(document.querySelectorAll<HTMLElement>('#GroupOptions div')).some(el => el.contains(e.target as Node));
            if (!option_is_clicked) {
                const opts = groupBox.querySelector<HTMLElement>('#GroupOptions');
                if (opts) opts.style.display = 'none';
            }
        });

        const isDefault = groupBox.getAttribute('data-default');
        if (isDefault=== '1') {
            setupDefaultActions(id, true);
        } else if (isDefault=== '0') {
            setupDefaultActions(id, false);
        }

        const manageBtn = groupBox.querySelector<HTMLElement>('.manage-users');
        if (manageBtn) manageBtn.addEventListener('click', () => openUserManager(id));

        const dupBtn = groupBox.querySelector<HTMLElement>('#GroupDuplicate');
        if (dupBtn) dupBtn.addEventListener('click', () => duplicateAction(id));
    };

    /*------- SETUP JS ON GROUP BOX -------*/
    document.querySelectorAll<HTMLElement>('.GroupContainer').forEach((el) => {
        if (el.id!== 'group-template') setupGroupBox(el);
    });

    function toggleSelection(group_id: string, toggle: boolean): void {
        const groupBox = document.getElementById('group-' + group_id);
        if (!groupBox) return;

        if (toggle) {
            const checkbox = groupBox.querySelector<HTMLInputElement>('.Group-checkbox input');
            if (checkbox) checkbox.checked = true;

            groupBox.classList.add('GroupBackgroundSelected');
            groupBox.querySelector<HTMLElement>('.icon-users-1')?.classList.add('OrangeIcon');
            groupBox.querySelector<HTMLElement>('.group_number_users')?.classList.add('OrangeFont');

            const item = document.createElement('div');
            item.setAttribute('data-id', group_id);
            item.innerHTML = '<a class="icon-cancel"></a><p>' + (groupBox.querySelector<HTMLElement>('#group_name')?.innerHTML ?? '') + '</p>';
            document.querySelector('.DeleteGroupList')?.appendChild(item);
            item.querySelector<HTMLElement>('a')?.addEventListener('click', function () {
                const checkbox = groupBox.querySelector<HTMLInputElement>('.Group-checkbox input');
                if (checkbox) checkbox.checked = false;
                toggleSelection(group_id, false);
            });

            updateSelectionPanel();

            const option = document.createElement('option');
            option.value = group_id;
            option.innerHTML = groupBox.querySelector<HTMLElement>('#group_name')?.innerHTML ?? '';
            document.getElementById('MergeOptionsChoices')?.appendChild(option);
        } else {
            const checkbox = groupBox.querySelector<HTMLInputElement>('.Group-checkbox input');
            if (checkbox) checkbox.checked = false;

            groupBox.classList.remove('GroupBackgroundSelected');
            groupBox.querySelector<HTMLElement>('.icon-users-1')?.classList.remove('OrangeIcon');
            groupBox.querySelector<HTMLElement>('.group_number_users')?.classList.remove('OrangeFont');

            document.querySelectorAll<HTMLElement>('.DeleteGroupList div').forEach(el => {
                if (el.getAttribute('data-id')=== group_id) el.remove();
            });

            updateSelectionPanel();

            document.querySelectorAll<HTMLOptionElement>('#MergeOptionsChoices option').forEach(el => {
                if (el.value=== group_id) el.remove();
            });
        }
    };

    function deleteGroup(id: string): void {
        const groupName = document.querySelector<HTMLElement>('#group-' + id + ' #group_name')?.innerHTML ?? '';
        pwgConfirm({
            title: str_delete.replace('%s', groupName),
            buttons: {
                confirm: {
                    text: str_yes_delete_confirmation,
                    btnClass: 'btn-red',
                    action: function () {
                        void fetch('ws.php?format=json&method=pwg.groups.delete', {
                            method: 'POST',
                            body: 'group_id=' + id + '&pwg_token=' + pwg_token,
                            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        })
                        .then(r => r.text())
                        .then(raw_data => {
                            const data = JSON.parse(raw_data) as { stat: string };
                            if (data.stat === 'ok') {
                                document.getElementById('group-' + id)?.remove();
                                document.querySelectorAll<HTMLElement>('.DeleteGroupList div[data-id=' + id + ']').forEach(el => el.remove());
                                document.querySelectorAll<HTMLElement>('#MergeOptionsChoices option[value=' + id + ']').forEach(el => el.remove());
                            }
                            updateBadge();
                        });
                    },
                },
                cancel: { text: str_no_delete_confirmation },
            },
        });
    };

    function renameGroup(id: string, newName: string): void {
        const loadState = new TemporaryState();
        const validateBtn = document.querySelector<HTMLElement>('#group-' + id + ' .group-rename .validate');
        if (validateBtn) { loadState.changeHTML(validateBtn, "<i class='animate-spin icon-spin6'></i>"); loadState.removeClass(validateBtn, 'icon-ok'); }
        const renameSpan = document.querySelector<HTMLElement>('#group-' + id + ' .group-rename span');
        if (renameSpan) loadState.changeAttribute(renameSpan, 'style', 'pointer-events: none');

        if (newName.replace(/\s/g, '').length!== 0) {
            fetch('ws.php?format=json&method=pwg.groups.setInfo', {
                method: 'POST',
                body: 'group_id=' + id + '&pwg_token=' + pwg_token + '&name=' + encodeURIComponent(newName),
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            })
            .then(r => r.text())
            .then(raw_data => {
                const data = JSON.parse(raw_data) as { stat: string; result: { groups: GroupData[] } };
                loadState.reverse();
                if (data.stat === 'ok') {
                    newName = data.result.groups[0]!.name;
                    const msg = document.querySelector<HTMLElement>('#group-' + id + ' .groupMessage');
                    if (msg) {
                        msg.innerHTML = str_renaming_done;
                        msg.style.display = '';
                        setTimeout(() => { msg.style.display = 'none'; }, DELAY_FEEDBACK);
                    }
                    const nameEl = document.querySelector<HTMLElement>('#group-' + id + ' #group_name');
                    if (nameEl) nameEl.innerHTML = newName;
                    displayRenameForm(false, id);
                } else {
                    const error = document.querySelector<HTMLElement>('#group-' + id + ' .groupError');
                    if (error) {
                        error.innerHTML = str_name_taken;
                        error.style.display = '';
                        setTimeout(() => { error.style.display = 'none'; }, DELAY_FEEDBACK);
                    }
                }
            })
            .catch((error: unknown) => console.log(error));
        } else {
            loadState.reverse();
            const error = document.querySelector<HTMLElement>('#group-' + id + ' .groupError');
            if (error) {
                error.innerHTML = str_name_not_empty;
                error.style.display = '';
                setTimeout(() => { error.style.display = 'none'; }, DELAY_FEEDBACK);
            }
        }
    };

    function displayRenameForm(doDisplay: boolean, grp_id: string): void {
        if (doDisplay) {
            const form = document.querySelector<HTMLElement>('#group-' + grp_id + ' .group-rename');
            if (form) form.style.display = 'flex';
            const pencil = document.querySelector<HTMLElement>('#group-' + grp_id + ' .Group-name-container .icon-pencil');
            if (pencil) pencil.style.display = 'none';
            const name = document.querySelector<HTMLElement>('#group-' + grp_id + ' .Group-name-container p');
            if (name) name.style.opacity = '0';
        } else {
            const form = document.querySelector<HTMLElement>('#group-' + grp_id + ' .group-rename');
            if (form) form.style.display = 'none';
            const pencil = document.querySelector<HTMLElement>('#group-' + grp_id + ' .Group-name-container .icon-pencil');
            if (pencil) pencil.style.display = '';
            const name = document.querySelector<HTMLElement>('#group-' + grp_id + ' .Group-name-container p');
            if (name) name.style.opacity = '1';
        }
    };

    function setDefaultGroup(id: string, is_default: boolean): void {
        const btn = document.querySelector<HTMLElement>('#group-' + id + ' #GroupDefault');
        if (btn) {
            btn.style.width = String(btn.offsetWidth) + 'px';
            btn.innerHTML = "<i class='icon-spin6 animate-spin'> </i>";
            btn.classList.remove('icon-star');
            btn.style.pointerEvents = 'none';
            btn.style.textAlign = 'center';
        }

        const token = document.querySelector<HTMLElement>('#group-' + id + ' .is-default-token');
        if (token) {
            token.classList.add('icon-spin6', 'animate-spin');
            token.classList.remove('icon-star');
        }

        fetch('ws.php?format=json&method=pwg.groups.setInfo', {
            method: 'POST',
            body: 'group_id=' + id + '&pwg_token=' + pwg_token + '&is_default=' + String(is_default),
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        })
        .then(r => r.text())
        .then(raw_data => {
            const data = JSON.parse(raw_data) as { stat: string };
            const opts = document.querySelector<HTMLElement>('#group-' + id + ' #GroupOptions');
            if (opts) opts.style.display = 'none';
            if (data.stat === 'ok') {
                setupDefaultActions(id, is_default);
            }
        })
        .catch((error: unknown) => console.log(error));
    };

    function setupDefaultActions(id: string, is_default: boolean): void {
        const btn = document.querySelector<HTMLElement>('#group-' + id + ' #GroupDefault');
        if (btn) {
            btn.style.cssText = '';
            btn.classList.add('icon-star');
        }

        const token = document.querySelector<HTMLElement>('#group-' + id + ' .is-default-token');
        if (token) {
            token.classList.remove('icon-spin6', 'animate-spin');
            token.classList.add('icon-star');
        }

        if (is_default) {
            const btn2 = document.querySelector<HTMLElement>('#group-' + id + ' #GroupDefault');
            if (btn2) {
                btn2.innerHTML = str_unset_default;
                btn2.addEventListener('click', function () { setDefaultGroup(id, false); });
            }

            const token2 = document.querySelector<HTMLElement>('#group-' + id + ' .is-default-token');
            if (token2) {
                token2.setAttribute('title', str_unset_default);
                token2.classList.remove('deactivate');
                token2.addEventListener('click', function () { setDefaultGroup(id, false); });
            }
        } else {
            const btn3 = document.querySelector<HTMLElement>('#group-' + id + ' #GroupDefault');
            if (btn3) {
                btn3.innerHTML = str_set_default;
                btn3.addEventListener('click', function () { setDefaultGroup(id, true); });
            }

            const token3 = document.querySelector<HTMLElement>('#group-' + id + ' .is-default-token');
            if (token3) {
                token3.setAttribute('title', str_set_default);
                token3.classList.add('deactivate');
            }
        }
    };

    function duplicateAction(id: string): void {
        const loadState = new TemporaryState();
        const dupBtn = document.querySelector<HTMLElement>('#group-' + id + ' #GroupDuplicate');
        if (dupBtn) { loadState.changeHTML(dupBtn, "<i class='icon-spin6 animate-spin'> </i>"); loadState.removeClass(dupBtn, 'icon-docs'); loadState.changeAttribute(dupBtn, 'style', 'pointer-events: none; text-align: center;'); }

        let copy_name = (document.querySelector<HTMLElement>('#group-' + id + ' #group_name')?.innerHTML ?? '') + str_copy;

        const name_exist = function (name: string): boolean {
            let exist = false;
            document.querySelectorAll<HTMLElement>('.Group-name-container p').forEach(el => {
                if (el.innerHTML === name) exist = true;
            });
            return exist;
        };

        let i = 1;
        while (name_exist(copy_name)) {
            copy_name = (document.querySelector<HTMLElement>('#group-' + id + ' #group_name')?.innerHTML ?? '') + str_other_copy.replace('%s', String(i++));
        }

        fetch('ws.php?format=json&method=pwg.groups.duplicate', {
            method: 'POST',
            body: 'group_id=' + id + '&pwg_token=' + pwg_token + '&copy_name=' + encodeURIComponent(copy_name),
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        })
        .then(r => r.text())
        .then(raw_data => {
            const data = JSON.parse(raw_data) as { stat: string; result: { groups: GroupData[] } };
            loadState.reverse();
            if (data.stat === 'ok') {
                const opts = document.querySelector<HTMLElement>('#group-' + id + ' #GroupOptions');
                if (opts) opts.style.display = 'none';
                const group = data.result.groups[0]!;
                const groupbox = createGroup(group);
                document.getElementById('group-' + id)?.insertAdjacentElement('afterend', groupbox);
                setupGroupBox(groupbox);
                updateBadge();

                if (group.is_default=== 'true') {
                    setupDefaultActions(String(group.id), true);
                }
            }
        })
        .catch((error: unknown) => console.log(error));
    };

    /*------- Selection mode toggle -------*/
    const toggleBtn = document.getElementById('toggleSelectionMode') as HTMLInputElement | null;
    if (toggleBtn) {
        toggleBtn.checked = false;
        toggleBtn.addEventListener('click', function (this: HTMLInputElement) {
            if (this.checked) {
                document.querySelectorAll<HTMLElement>('.in-selection-mode').forEach(el => { el.style.display = 'block'; });
                document.querySelectorAll<HTMLElement>('.not-in-selection-mode').forEach(el => { el.style.display = 'none'; });
                document.querySelector<HTMLElement>('.GroupManagerButtons')?.classList.remove('visible');
            } else {
                document.querySelectorAll<HTMLElement>('.in-selection-mode').forEach(el => { el.style.display = ''; });
                document.querySelectorAll<HTMLElement>('.not-in-selection-mode').forEach(el => { el.style.cssText = ''; });
                document.querySelectorAll<HTMLInputElement>('.Group-checkbox input').forEach(el => { el.checked = false; });
                document.querySelectorAll<HTMLInputElement>(".Group-checkbox input[type='checkbox']").forEach(el => {
                    el.dispatchEvent(new Event('change'));
                });
            }
        });
    }

    /*------- Update Selection Panel -------*/
    let state = 'NoSelection';

    function updateSelectionPanel(changedState = ''): void {
        const numSelect = document.querySelectorAll('.DeleteGroupList div').length;

        if (numSelect=== 0) {
            updateStatePanel('NoSelection');
        } else if (changedState=== '') {
            if (numSelect=== 1 && state!== 'ConfirmDeletion') updateStatePanel('OneSelected');
            if (numSelect > 1 && state=== 'OneSelected') updateStatePanel('Selection');
        } else {
            if (changedState=== 'Selection' && numSelect=== 1) updateStatePanel('OneSelected');
            else updateStatePanel(changedState);
        }

        const numEl = document.querySelector<HTMLElement>('.number-Selected');
        if (numEl) numEl.innerHTML = String(numSelect);
    };

    function updateStatePanel(newState = 'Selection'): void {
        state = newState;
        const deleteBtn = document.getElementById('DeleteSelectionMode');
        const mergeBtn = document.getElementById('MergeSelectionMode');
        const mergeBlock = document.getElementById('MergeOptionsBlock');
        const confirmBlock = document.getElementById('ConfirmGroupAction');

        switch (newState) {
            case 'OneSelected':
                if (deleteBtn) deleteBtn.style.display = '';
                if (mergeBtn) mergeBtn.style.display = '';
                buttonUnavailable(mergeBtn);
                buttonAvailable(deleteBtn, "updateSelectionPanel('ConfirmDeletion')");
                if (mergeBlock) mergeBlock.style.display = 'none';
                if (confirmBlock) confirmBlock.style.display = 'none';
                break;
            case 'ConfirmDeletion':
                if (deleteBtn) deleteBtn.style.display = 'none';
                if (mergeBtn) mergeBtn.style.display = 'none';
                if (mergeBlock) mergeBlock.style.display = 'none';
                if (confirmBlock) confirmBlock.style.display = '';
                break;
            case 'Selection':
                if (deleteBtn) deleteBtn.style.display = '';
                if (mergeBtn) mergeBtn.style.display = '';
                buttonAvailable(mergeBtn, "updateSelectionPanel('OptionMerge')");
                buttonAvailable(deleteBtn, "updateSelectionPanel('ConfirmDeletion')");
                if (mergeBlock) mergeBlock.style.display = 'none';
                if (confirmBlock) confirmBlock.style.display = 'none';
                break;
            case 'OptionMerge':
                if (deleteBtn) deleteBtn.style.display = 'none';
                if (mergeBtn) mergeBtn.style.display = 'none';
                if (mergeBlock) mergeBlock.style.display = '';
                if (confirmBlock) confirmBlock.style.display = 'none';
                break;
        }
        const selectionModeGroup = document.querySelector<HTMLElement>('.SelectionModeGroup');
        if (newState=== 'NoSelection') {
            if (deleteBtn) deleteBtn.style.display = '';
            if (mergeBtn) mergeBtn.style.display = '';
            buttonUnavailable(mergeBtn);
            buttonUnavailable(deleteBtn);
            if (selectionModeGroup) selectionModeGroup.style.display = 'none';
            const nothingSelected = document.getElementById('nothing-selected');
            if (nothingSelected) nothingSelected.style.display = '';
            if (mergeBlock) mergeBlock.style.display = 'none';
            if (confirmBlock) confirmBlock.style.display = 'none';
        } else {
            if (selectionModeGroup) selectionModeGroup.style.display = '';
            const nothingSelected = document.getElementById('nothing-selected');
            if (nothingSelected) nothingSelected.style.display = 'none';
        }
    };

    function buttonAvailable(button: HTMLElement | null | undefined, onClick: string): void {
        if (button) {
            button.classList.remove('unavailable');
            button.setAttribute('onClick', onClick);
        }
    };

    function buttonUnavailable(button: HTMLElement | null | undefined): void {
        if (button) {
            button.classList.add('unavailable');
            button.removeAttribute('onClick');
        }
    };

    /*------- Merge function -------*/
    document.querySelector<HTMLElement>('.ConfirmMergeButton')?.addEventListener('click', function () {
        const merge_group: string[] = [];
        let str_merge_group = '';
        const name_merge: string[] = [];
        let name_dest: string = '';
        const loadState = new TemporaryState();
        const mergeBtn = document.querySelector<HTMLElement>('.ConfirmMergeButton');
        if (mergeBtn) { loadState.changeAttribute(mergeBtn, 'style', 'pointer-events: none'); loadState.changeHTML(mergeBtn, "<i class='icon-spin6 animate-spin'> </i>"); loadState.removeClass(mergeBtn, 'icon-ok'); }

        const destSelect = document.getElementById('MergeOptionsChoices') as HTMLSelectElement | null;
        const dest_grp: string = destSelect ? destSelect.value : '';

        document.querySelectorAll<HTMLElement>('.DeleteGroupList div').forEach(el => {
            if (dest_grp!== el.getAttribute('data-id')) {
                str_merge_group += '&merge_group_id[]=' + el.getAttribute('data-id');
                merge_group.push(el.getAttribute('data-id') ?? '');
                name_merge.push(el.querySelector<HTMLElement>('p')?.innerHTML ?? '');
            } else {
                name_dest = el.querySelector<HTMLElement>('p')?.innerHTML ?? '';
            }
        });

        void fetch('ws.php?format=json&method=pwg.groups.merge', {
            method: 'POST',
            body: 'destination_group_id=' + dest_grp + str_merge_group + '&pwg_token=' + pwg_token,
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        })
        .then(r => r.text())
        .then(raw_data => {
            const data = JSON.parse(raw_data) as { stat: string };
            loadState.reverse();
            if (data.stat === 'ok') {
                updateSelectionPanel('Selection');
                merge_group.forEach(function (id) {
                    const el = document.getElementById('group-' + id);
                    if (el) {
                        el.style.opacity = '0';
                        el.style.transition = 'opacity 0.4s ease';
                        setTimeout(() => el.remove(), 400);
                    }
                });
                toggleSelection(dest_grp, false);
                const list = document.querySelector<HTMLElement>('.DeleteGroupList');
                if (list) list.innerHTML = '';
                const mergeSelect = document.getElementById('MergeOptionsChoices');
                if (mergeSelect) mergeSelect.innerHTML = '';

                pwgConfirm({
                    title: str_merged_into
                        .replace('%s1', name_merge.toString())
                        .replace('%s2', name_dest),
                    buttons: { ok: { text: 'OK' } },
                });

                const userCount = document.querySelector<HTMLElement>('#group-' + dest_grp + ' .group_number_users');
                if (userCount) userCount.innerHTML = "<i class='icon-spin6 animate-spin'> </i>";

                void fetch('ws.php?format=json&method=pwg.users.getList', {
                    method: 'POST',
                    body: 'group_id=' + dest_grp,
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                })
                .then(r => r.text())
                .then(raw_data => {
                    const data2 = JSON.parse(raw_data) as { result: { users: UserEntry[] } };
                    const number = data2.result.users.length;
                    const userCountEl = document.querySelector<HTMLElement>('#group-' + dest_grp + ' .group_number_users');
                    if (userCountEl) {
                        userCountEl.innerHTML = String(number) + ' ' + (number > 1 ? str_members_default : str_member_default);
                    }
                    updateBadge();
                });
            }
        });
    });

    /*------- Delete function on panel -------*/
    document.querySelector<HTMLElement>('.ConfirmDeleteButton')?.addEventListener('click', function () {
        const names: string[] = [];
        const ids: string[] = [];
        document.querySelectorAll<HTMLElement>('.DeleteGroupList div').forEach(el => {
            const id = el.getAttribute('data-id') ?? '';
            names.push(document.querySelector<HTMLElement>('#group-' + id + ' #group_name')?.innerHTML ?? '');
            ids.push(id);
        });

        const loadState = new TemporaryState();
        const delBtn = document.querySelector<HTMLElement>('.ConfirmDeleteButton');
        if (delBtn) { loadState.changeAttribute(delBtn, 'style', 'pointer-events: none'); loadState.changeHTML(delBtn, "<i class='icon-spin6 animate-spin'> </i>"); loadState.removeClass(delBtn, 'icon-ok'); }

        let str_id = '';
        ids.forEach(function (id) { str_id += 'group_id[]=' + id + '&'; });

        fetch('ws.php?format=json&method=pwg.groups.delete', {
            method: 'POST',
            body: str_id + 'pwg_token=' + pwg_token,
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        })
        .then(r => r.text())
        .then(raw_data => {
            const data = JSON.parse(raw_data) as { stat: string };
            if (data.stat === 'ok') {
                document.querySelectorAll<HTMLElement>('.DeleteGroupList div').forEach(el => {
                    const id = el.getAttribute('data-id');
                    el.remove();
                    document.getElementById('group-' + String(id))?.remove();
                    document.querySelectorAll<HTMLElement>('#MergeOptionsChoices option[value=' + String(id) + ']').forEach(opt => opt.remove());
                });
                loadState.reverse();
                updateSelectionPanel('NoSelection');
                pwgConfirm({
                    title: str_groups_deleted.replace('%s', names.toString()),
                    buttons: { ok: { text: 'OK' } },
                });
                updateBadge();
            }
        })
        .catch((error: unknown) => console.log(error));
    });

    /*------- Manage User Part -------*/
    let selectize: TomSelect | undefined;
    let usersCache: UsersCache | null = null;
    let usersInGroup: UserEntry[] = [];
    const maxOffsetUserCont = 322;

    const selectEl = document.querySelector<HTMLSelectElement>('.AddUserBlock select');
    if (selectEl) {
        selectize = (selectEl as HTMLSelectElement & { tomselect?: TomSelect }).tomselect ?? new TomSelect(selectEl, {});

        let idSearch = '';

        const updateUserSearch = function (): void {
            if (selectize) selectize.clear();
            const localCache = new UsersCache({
                serverKey: serverKey,
                serverId: serverId,
                rootUrl: rootUrl,
            });
            const cachedUsers = localCache.getFromStorage();
            if (cachedUsers) cachedUsers.forEach(function (u) {
                if (selectize) selectize.addOption({ value: u['id'], text: u['username'] });
            });
            idSearch = document.getElementById('UserList')?.getAttribute('data-group_id') ?? '';

            if (selectize) {
                for (const [, value] of Object.entries(selectize.options as Record<string, Record<string, string>>)) {
                    if (value['username'] === 'guest') {
                        selectize.removeOption(value['id'] ?? '');
                    }
                }
            }

            document.querySelectorAll<HTMLElement>('.UsernameBlock').forEach(el => {
                if (selectize) selectize.removeOption(el.getAttribute('data-id') ?? '');
            });
        };

        document.querySelector<HTMLElement>('.UserSearch input')?.addEventListener('focus', function () {
            if (idSearch!== document.getElementById('UserList')?.getAttribute('data-group_id')) {
                updateUserSearch();
            }
        });
    }

    function openUserManager(grp_id: string): void {
        const loadState = new TemporaryState();
        const trigger = document.querySelector<HTMLElement>('#group-' + grp_id + ' #UserListTrigger');
        if (trigger) { loadState.removeClass(trigger, 'icon-user-1'); loadState.changeAttribute(trigger, 'style', 'pointer-events: none'); loadState.changeHTML(trigger, "<i class='icon-spin6 animate-spin'> </i>"); }

        fetch('ws.php?format=json&method=pwg.users.getList', {
            method: 'POST',
            body: 'group_id=' + grp_id,
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        })
        .then(r => r.text())
        .then(raw_data => {
            const data = JSON.parse(raw_data) as { stat: string; result: { users: UserEntry[] } };
            loadState.reverse();
            if (data.stat === 'ok') {
                const nameBlock = document.querySelector<HTMLElement>('.group-name-block p');
                if (nameBlock) nameBlock.innerHTML = (document.querySelector<HTMLElement>('#group-' + grp_id + ' #group_name')?.innerHTML ?? '') + ' / ' + str_user_list;

                const userList = document.querySelector<HTMLElement>('.UsersInGroupList');
                if (userList) userList.innerHTML = '';

                const popup = document.getElementById('UserList');
                if (popup) popup.style.display = '';

                usersInGroup = data.result.users;
                usersInGroup.sort(function (a, b) {
                    return a.username.toLowerCase() < b.username.toLowerCase() ? -1 : 1;
                });

                let i = 0;
                const userListEl = document.querySelector<HTMLElement>('.UsersInGroupList');
                while (userListEl && userListEl.offsetHeight <= maxOffsetUserCont && usersInGroup[i]!== undefined) {
                    const userEl = getUserDisplay(usersInGroup[i]!.username, usersInGroup[i]!.id, grp_id);
                    userListEl.appendChild(userEl);
                    i++;
                }

                while (userListEl && userListEl.offsetHeight > maxOffsetUserCont) {
                    userListEl.querySelector<HTMLElement>('.UsernameBlock:last-child')?.remove();
                }

                updateMembernumber(usersInGroup.length, grp_id);
                if (popup) popup.setAttribute('data-group_id', grp_id);

                const managerLink = document.querySelector<HTMLAnchorElement>('.LinkUserManager a');
                if (managerLink) managerLink.href = 'admin.php?page=user_list&group=' + grp_id;
            }
        })
        .catch((error: unknown) => console.log(error));
    };

    function getUserDisplay(username: string, user_id: string | number, grp_id: string): HTMLElement {
        const userBlock = document.createElement('div');
        userBlock.className = 'UsernameBlock';
        userBlock.setAttribute('data-id', String(user_id));
        userBlock.innerHTML = '<span class="icon-user-1"></span><p>' + username + '</p><div class="Tooltip"><span class="icon-cancel"></span><p class="TooltipText">' + str_user_dissociate + '</p></div>';

        const userList = document.querySelector<HTMLElement>('.UsersInGroupList');
        while (userList && userList.offsetHeight > maxOffsetUserCont) {
            userList.querySelector<HTMLElement>('.UsernameBlock:last-child')?.remove();
        }

        const cancelBtn = userBlock.querySelector<HTMLElement>('.icon-cancel');
        if (cancelBtn) {
            cancelBtn.addEventListener('click', function () {
                cancelBtn.classList.add('icon-spin6', 'animate-spin');
                cancelBtn.style.pointerEvents = 'none';
                cancelBtn.classList.remove('icon-cancel');

                void fetch('ws.php?format=json&method=pwg.groups.deleteUser', {
                    method: 'POST',
                    body: 'group_id=' + grp_id + '&user_id=' + String(user_id) + '&pwg_token=' + pwg_token,
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                })
                .then(r => r.text())
                .then(raw_data => {
                    const data = JSON.parse(raw_data) as { stat: string };
                    if (data.stat === 'ok') {
                        const str = str_user_dissociated.replace('%s', username);
                        associateUserInfo.style.display = 'none';
                        const pEl = dissociateUserInfo.querySelector<HTMLElement>('p');
                        if (pEl) pEl.innerHTML = str;
                        dissociateUserInfo.style.display = '';

                        document.querySelectorAll<HTMLElement>('.UsernameBlock').forEach(el => {
                            el.style.marginRight = '10px';
                            el.style.border = 'none';
                        });
                        userBlock.remove();

                        if (selectize) {
                            const updateFn = (document.querySelector<HTMLElement>('.UserSearch input') as (HTMLElement & { _updateFn?: () => void }) | null)?._updateFn;
                            if (typeof updateFn === 'function') updateFn();
                        }

                        const uList = document.querySelector<HTMLElement>('.UsersInGroupList');
                        while (uList && uList.offsetHeight > maxOffsetUserCont) {
                            uList.querySelector<HTMLElement>('.UsernameBlock:last-child')?.remove();
                        }

                        usersInGroup = usersInGroup.filter((u) => u.id!== user_id);

                        const badgeNum = document.querySelector<HTMLElement>('.UserNumberBadge')?.innerHTML ?? '0';
                        updateMembernumber(parseInt(badgeNum) - 1, grp_id);
                    }
                });
            });
        }
        return userBlock;
    };

    function updateMembernumber(number: number, grp_id: string): void {
        const el = document.querySelector<HTMLElement>('.GroupContainer[data-id=' + grp_id + '] .group_number_users');
        if (el) el.innerHTML = String(number) + ' ' + (number > 1 ? str_members_default : str_member_default);

        const badge = document.querySelector<HTMLElement>('.UserNumberBadge');
        if (badge) badge.innerHTML = String(number);

        const shown2 = document.querySelector<HTMLElement>('.AmountOfUsersShown strong:nth-child(2)');
        if (shown2) shown2.innerHTML = String(number);

        const shown1 = document.querySelector<HTMLElement>('.AmountOfUsersShown strong:nth-child(1)');
        if (shown1) shown1.innerHTML = String(document.querySelectorAll('.UsernameBlock').length);
    }

    document.querySelector<HTMLElement>('.CloseUserList')?.addEventListener('click', function () {
        const popup = document.getElementById('UserList');
        if (popup) popup.style.display = 'none';
    });

    document.querySelector<HTMLElement>('.AddUserBlock button')?.addEventListener('click', function () {
        const grp_id = document.getElementById('UserList')?.getAttribute('data-group_id') ?? '';
        const id = selectize ? String(selectize.getValue()) : '';

        if (id!== '') {
            const loadState = new TemporaryState();
            const submitBtn = document.getElementById('UserSubmit');
            if (submitBtn) { loadState.changeHTML(submitBtn, "<i class='icon-spin6 animate-spin'> </i>"); loadState.removeClass(submitBtn, 'icon-user-add'); loadState.changeAttribute(submitBtn, 'style', 'pointer-events:none'); }

            void fetch('ws.php?format=json&method=pwg.groups.addUser', {
                method: 'POST',
                body: 'group_id=' + grp_id + '&user_id=' + id + '&pwg_token=' + pwg_token,
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            })
            .then(r => r.text())
            .then(raw_data => {
                const data = JSON.parse(raw_data) as { stat: string };
                loadState.reverse();

                if (data.stat === 'ok') {
                    let username = 'undefined';
                    const cachedData = usersCache?.getFromStorage();
                    if (cachedData) cachedData.forEach(function (u) {
                        if (String(u['id'])=== id) username = String(u['username']);
                    });

                    const userBlock = getUserDisplay(username, id, grp_id);
                    const listEl = document.querySelector<HTMLElement>('.UsersInGroupList');
                    listEl?.insertBefore(userBlock, listEl.firstChild);

                    associateUserInfo.style.display = 'none';

                    document.querySelectorAll<HTMLElement>('.UsernameBlock:first-child').forEach(el => {
                        el.style.marginRight = '0px';
                        el.style.border = '2px solid #c2f5c2';
                    });
                    document.querySelectorAll<HTMLElement>('.UsernameBlock').forEach((el, idx) => {
                        if (idx > 0) {
                            el.style.marginRight = '10px';
                            el.style.border = 'none';
                        }
                    });

                    if (associateUserInfo.parentNode) associateUserInfo.parentNode.removeChild(associateUserInfo);
                    userBlock.insertAdjacentElement('afterend', associateUserInfo);
                    const assocP = associateUserInfo.querySelector<HTMLElement>('p');
                    if (assocP) assocP.innerHTML = str_user_associated;
                    associateUserInfo.style.display = '';

                    if (selectize) {
                        const inputEl = document.querySelector<HTMLElement>('.UserSearch input');
                        inputEl?.dispatchEvent(new Event('focus'));
                    }

                    usersInGroup.push({ username: username, id: id });

                    const uList = document.querySelector<HTMLElement>('.UsersInGroupList');
                    while (uList && uList.offsetHeight > maxOffsetUserCont) {
                        uList.querySelector<HTMLElement>('.UsernameBlock:last-child')?.remove();
                    }

                    const badgeNum = document.querySelector<HTMLElement>('.UserNumberBadge')?.innerHTML ?? '0';
                    updateMembernumber(parseInt(badgeNum) + 1, grp_id);
                }
            });
        }
    });

    document.querySelector<HTMLElement>('.input-user-name')?.addEventListener('input', function (this: HTMLElement) {
        const searchString = (this as HTMLInputElement).value.toLowerCase();
        const grp_id = document.querySelector<HTMLElement>('.UserListPopIn')?.getAttribute('data-group_id') ?? '';
        if (searchString!== '') {
            const container = document.querySelector<HTMLElement>('.UsersInGroupListContainer');
            if (container) container.style.minHeight = String(container.offsetHeight) + 'px';

            usersInGroup.forEach(function (u) {
                const isSearched = u.username.toLowerCase().includes(searchString);
                const userBlock = document.querySelector<HTMLElement>('.UsernameBlock[data-id=' + String(u.id) + ']');
                if (userBlock && !isSearched) {
                    userBlock.remove();
                } else if (!userBlock && isSearched) {
                    const newBlock = getUserDisplay(u.username, u.id, grp_id);
                    const listEl = document.querySelector<HTMLElement>('.UsersInGroupList');
                    listEl?.insertBefore(newBlock, listEl.firstChild);
                }
            });
        } else {
            const container = document.querySelector<HTMLElement>('.UsersInGroupListContainer');
            if (container) container.style.minHeight = '';
            const userList = document.querySelector<HTMLElement>('.UsersInGroupList');
            if (userList) userList.innerHTML = '';

            let i = 0;
            while (userList && userList.offsetHeight <= maxOffsetUserCont && usersInGroup[i]!== undefined) {
                const userEl = getUserDisplay(usersInGroup[i]!.username, usersInGroup[i]!.id, grp_id);
                userList.appendChild(userEl);
                i++;
            }
        }
        const shown = document.querySelector<HTMLElement>('.AmountOfUsersShown strong:nth-child(1)');
        if (shown) shown.innerHTML = String(document.querySelectorAll('.UsernameBlock').length);

        const userList = document.querySelector<HTMLElement>('.UsersInGroupList');
        while (userList && userList.offsetHeight > maxOffsetUserCont) {
            userList.querySelector<HTMLElement>('.UsernameBlock:last-child')?.remove();
        }
    });

    // temporary fix for #1283: force user local storage cache on page load
    usersCache = new UsersCache({
        serverKey: serverKey,
        serverId: serverId,
        rootUrl: rootUrl,
    });

    usersCache.selectize(document.querySelectorAll('select.UserSearch'));

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            const userListEl = document.getElementById('UserList');
            if (userListEl) userListEl.style.display = 'none';
        }
    });

    document.addEventListener('click', function (e) {
        if (!(e.target as Element).closest('.UserListPopInContainer')) {
            const userListEl = document.getElementById('UserList');
            if (userListEl) userListEl.style.display = 'none';
        }
    });
}

initModule(init as unknown as (cfg: Record<string, unknown>) => void);
