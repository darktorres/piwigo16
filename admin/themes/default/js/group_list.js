import { initModule } from './moduleInit.js';
import { TemporaryState } from './common.js';
import { pwgConfirm } from './pwgConfirm.js';
import { UsersCache } from './LocalStorageCache.js';
import TomSelect from 'tom-select';

const DELAY_FEEDBACK = 3000;

// Module-level config - initialized by init()
let pwg_token = '';
let str_member_default = '';
let str_members_default = '';
let str_group_created = '';
let str_renaming_done = '';
let str_name_taken = '';
let str_name_not_empty = '';
let _str_group_deleted = '';
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

export function init(cfg) {
    pwg_token = cfg.pwgToken;
    str_member_default = cfg.strMemberDefault;
    str_members_default = cfg.strMembersDefault;
    str_group_created = cfg.strGroupCreated;
    str_renaming_done = cfg.strRenamingDone;
    str_name_taken = cfg.strNameTaken;
    str_name_not_empty = cfg.strNameNotEmpty;
    _str_group_deleted = cfg.strGroupDeleted;
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

    // Setup validation messages for user association/dissociation
    let dissociateUserInfo = document.createElement("div");
    dissociateUserInfo.className = "ValidationUserDissociated";
    dissociateUserInfo.innerHTML = "<p class='icon-ok'></p>";
    document.querySelector(".group-name-block")?.appendChild(dissociateUserInfo);
    dissociateUserInfo.style.display = 'none';

    var associateUserInfo = document.createElement("div");
    associateUserInfo.className = "ValidationUserAssociated";
    associateUserInfo.innerHTML = "<p class='icon-ok'></p>";

    /*-------
    Group Popin
    -------*/

    document.querySelector(".group_details_popup_trigger")?.addEventListener("click", function () {
    let el = document.querySelector(".Group_details-popup-container");
    if (el) el.style.display = '';
});

document.querySelector(".CloseGroupPopup")?.addEventListener("click", function () {
    let el = document.querySelector(".Group_details-popup-container");
    if (el) el.style.display = 'none';
});

//Number On Badge
function updateBadge() {
    let badge = document.querySelector(".badge-number");
    if (badge) badge.innerHTML = document.querySelectorAll(".GroupContainer").length - 2; //Less the add group div and the template
}

/*-------
 Add User toggle and reduces height of user list when add user form is visible
 -------*/

document.getElementById("form-btn")?.addEventListener("click", function () {
    document.getElementById("cancel").style.display = '';
    document.getElementById("addUserLabel").style.display = '';
    document.querySelector(".UserSearch").style.display = '';
    document.getElementById("UserSubmit").style.display = '';
    document.getElementById("form-btn").style.display = 'none';
    let userList = document.querySelector(".groups .list_user");
    if (userList) userList.style.maxHeight = "100px";
});

document.getElementById("cancel")?.addEventListener("click", function () {
    document.getElementById("cancel").style.display = 'none';
    document.getElementById("addUserLabel").style.display = 'none';
    document.querySelector(".UserSearch").style.display = 'none';
    document.getElementById("UserSubmit").style.display = 'none';
    document.getElementById("form-btn").style.display = '';
    let userList = document.querySelector(".groups .list_user");
    if (userList) userList.style.maxHeight = "200px";
});

/*-------
 Add Group toggle
 -------*/
var isToggle = true;
document.querySelector(".addGroupBlock")?.addEventListener("click", function () {
    if (isToggle) {
        deployAddGroupForm();
    } else {
        hideAddGroupForm();
    }
});

var deployAddGroupForm = function () {
    let block = document.querySelector(".addGroupBlock");
    if (block) {
        block.style.transition = "all 0.4s ease";
        block.style.top = "20%";
        block.style.padding = "0px";
    }
    setTimeout(() => {
        let form = document.querySelector("#addGroupForm form");
        if (form) form.style.display = 'block';
        let input = document.getElementById("addGroupNameInput");
        if (input) input.focus();
    }, 400);
    isToggle = false;
};

var hideAddGroupForm = function () {
    let form = document.querySelector("#addGroupForm form");
    if (form) {
        form.style.opacity = "0";
        form.style.transition = "opacity 0.4s ease";
    }
    setTimeout(() => {
        let block = document.querySelector(".addGroupBlock");
        if (block) {
            block.style.top = "50%";
            block.style.padding = "100px 0";
        }
        if (form) form.style.display = 'none';
        if (form) form.style.opacity = "1";
    }, 400);
    isToggle = true;
};

    /*-------
    Add Group Submit
    -------*/

    document.querySelector("#addGroupForm form")?.addEventListener("submit", function (e) {
        e.preventDefault();
        let name = document.querySelector("#addGroupForm input[type=text]").value;
        let loadState = new TemporaryState();
        loadState.changeHTML(
            document.querySelectorAll(".actionButtons button"),
            "<i class='icon-spin6 animate-spin'> </i>",
        );
        loadState.changeAttribute(
            document.querySelectorAll(".actionButtons button"),
            "style",
            "pointer-events: none",
        );
        loadState.changeAttribute(
            document.querySelectorAll(".actionButtons a"),
            "style",
            "pointer-events: none",
        );

        if (name.replace(/\s/g, "").length != 0) {
            fetch("ws.php?format=json&method=pwg.groups.add", {
                method: "POST",
                body: new URLSearchParams({
                    name: name,
                    pwg_token: pwg_token,
                }),
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
            })
            .then(response => response.text())
            .then(raw_data => {
                var data, group, groupBox;
                loadState.reverse();
                data = JSON.parse(raw_data);
                if (data.stat === "ok") {
                    document.querySelector(".addGroupFormLabelAndInput input").value = "";
                    group = data.result.groups[0];
                    groupBox = createGroup(group);
                    document.getElementById("addGroupForm").insertAdjacentElement("afterend", groupBox);
                    setupGroupBox(groupBox);
                    updateBadge();
                } else {
                    let error = document.querySelector("#addGroupForm .groupError");
                    if (error) {
                        error.innerHTML = str_name_not_empty;
                        error.style.display = '';
                        setTimeout(() => error.style.display = 'none', DELAY_FEEDBACK);
                    }
                }
            })
            .catch(error => {
                loadState.reverse();
                console.log(error);
            });
        } else {
            loadState.reverse();
            let error = document.querySelector("#addGroupForm .groupError");
            if (error) {
                error.innerHTML = str_name_not_empty;
                error.style.display = '';
                setTimeout(() => error.style.display = 'none', DELAY_FEEDBACK);
            }
        }
    });

    var createGroup = function (group) {
    //Setup the group
    let template = document.getElementById("group-template");
    let newgroup = template.cloneNode(true);
    newgroup.id = "group-" + group.id;
    newgroup.setAttribute("data-id", group.id);

    let nameEl = newgroup.querySelector("#group_name");
    if (nameEl) nameEl.innerHTML = group.name;

    let editableEl = newgroup.querySelector(".group_name-editable");
    if (editableEl) editableEl.value = group.name;

    let checkbox = newgroup.querySelector(".Group-checkbox label");
    if (checkbox) checkbox.setAttribute("for", "Group-Checkbox-selection-" + group.id);

    let checkboxInput = newgroup.querySelector(".Group-checkbox input");
    if (checkboxInput) checkboxInput.id = "Group-Checkbox-selection-" + group.id;

    let placeholder = newgroup.querySelector(".input-edit-group-name");
    if (placeholder) placeholder.setAttribute("placeholder", group.name);

    let userCount = newgroup.querySelector(".group_number_users");
    if (userCount) userCount.innerHTML = group.nb_users + " " + (group.nb_users > 1 ? str_members_default : str_member_default);

    let editableHtml = newgroup.querySelector(".group_name-editable");
    if (editableHtml) editableHtml.innerHTML = group.name;

    let manageLink = newgroup.querySelector(".manage-permissions");
    if (manageLink) manageLink.href = "admin.php?page=group_perm&group_id=" + group.id;

    hideAddGroupForm();

    //Setup the icon color
    var colors = ["icon-red", "icon-blue", "icon-yellow", "icon-purple", "icon-green"];
    var colorId = Number(group.id) % 5;
    let icon = newgroup.querySelector(".icon-users-1");
    if (icon) icon.classList.add(colors[colorId]);

    //Place group in first Place
    let msg = newgroup.querySelector(".groupMessage");
    if (msg) {
        msg.innerHTML = str_group_created;
        msg.style.display = '';
        setTimeout(() => msg.style.display = 'none', DELAY_FEEDBACK);
    }
    return newgroup;
};

    /*-------
    SETUP JS ON GROUP BOX
    -------*/
    document.querySelectorAll(".GroupContainer").forEach((el) => {
        if (el.id != "group-template") setupGroupBox(el);
    });

    var setupGroupBox = function (groupBox) {
    var id = groupBox.getAttribute("data-id");

    /* Change background color of group block if checked in selection mode */
    let checkbox = groupBox.querySelector(".Group-checkbox input[type='checkbox']");
    if (checkbox) {
        checkbox.addEventListener("change", function () {
            toggleSelection(id, this.checked);
        });
        checkbox.checked = false;
    }

    /* Display the option on the click on "..." */
    let dropdown = groupBox.querySelector(".group-dropdown-options");
    if (dropdown) {
        dropdown.addEventListener("click", function () {
            let options = groupBox.querySelector("#GroupOptions");
            if (options) options.style.display = window.getComputedStyle(options).display === 'none' ? 'block' : 'none';
        });
    }

    /* Set the delete action */
    let deleteBtn = groupBox.querySelector("#GroupDelete");
    if (deleteBtn) deleteBtn.addEventListener("click", () => deleteGroup(id));

    /* Set the rename action */
    document.querySelectorAll("#group-" + id + " .Group-name .icon-pencil, #group-" + id + " #GroupEdit").forEach(el => {
        el?.addEventListener("click", function () {
            displayRenameForm(true, id);
            setTimeout(() => {
                let opts = groupBox.querySelector("#GroupOptions");
                if (opts) opts.style.display = 'none';
            }, 10);
        });
    });

    let validateBtn = groupBox.querySelector(".group-rename .validate");
    if (validateBtn) {
        validateBtn.addEventListener("click", function () {
            let form = groupBox.querySelector(".group-rename form");
            if (form) form.dispatchEvent(new Event("submit"));
        });
    }

    let renameForm = groupBox.querySelector(".group-rename form");
    if (renameForm) {
        renameForm.addEventListener("submit", function (e) {
            e.preventDefault();
            let input = groupBox.querySelector(".group_name-editable");
            renameGroup(id, input?.value || "");
        });
    }

    let cancelBtn = groupBox.querySelector(".group-rename .icon-cancel");
    if (cancelBtn) {
        cancelBtn.addEventListener("click", function () {
            displayRenameForm(false, id);
            let input = groupBox.querySelector(".group_name-editable");
            let nameContainer = groupBox.querySelector(".Group-name-container p");
            if (input && nameContainer) input.value = nameContainer.innerHTML;
        });
    }

    /* Hide group options and rename field on click on the screen */
    document.addEventListener("mouseup", function (e) {
        e.stopPropagation();
        let option_is_clicked = false;
        document.querySelectorAll("#GroupOptions div").forEach(el => {
            if (el.contains(e.target)) {
                option_is_clicked = true;
            }
        });
        if (!option_is_clicked) {
            let opts = groupBox.querySelector("#GroupOptions");
            if (opts) opts.style.display = 'none';
        }
    });

    /* Setup the default action */
    let isDefault = groupBox.getAttribute("data-default");
    if (isDefault == "1") {
        setupDefaultActions(id, true);
    } else if (isDefault == "0") {
        setupDefaultActions(id, false);
    }

    let manageBtn = groupBox.querySelector(".manage-users");
    if (manageBtn) manageBtn.addEventListener("click", () => openUserManager(id));

    let dupBtn = groupBox.querySelector("#GroupDuplicate");
    if (dupBtn) dupBtn.addEventListener("click", () => duplicateAction(id));
};

var toggleSelection = function (group_id, toggle) {
    let groupBox = document.getElementById("group-" + group_id);
    if (!groupBox) return;

    if (toggle) {
        let checkbox = groupBox.querySelector(".Group-checkbox input");
        if (checkbox) checkbox.checked = true;

        groupBox.classList.add("GroupBackgroundSelected");
        groupBox.querySelector(".icon-users-1")?.classList.add("OrangeIcon");
        groupBox.querySelector(".group_number_users")?.classList.add("OrangeFont");

        //Display item selection on selection panel
        let item = document.createElement("div");
        item.setAttribute("data-id", group_id);
        item.innerHTML = '<a class="icon-cancel"></a><p>' + (groupBox.querySelector("#group_name")?.innerHTML || "") + '</p>';
        document.querySelector(".DeleteGroupList")?.appendChild(item);
        item.querySelector("a")?.addEventListener("click", function () {
            let checkbox = groupBox.querySelector(".Group-checkbox input");
            if (checkbox) checkbox.checked = false;
            toggleSelection(group_id, false);
        });

        updateSelectionPanel();

        let option = document.createElement("option");
        option.value = group_id;
        option.innerHTML = groupBox.querySelector("#group_name")?.innerHTML || "";
        document.getElementById("MergeOptionsChoices")?.appendChild(option);
    } else {
        let checkbox = groupBox.querySelector(".Group-checkbox input");
        if (checkbox) checkbox.checked = false;

        groupBox.classList.remove("GroupBackgroundSelected");
        groupBox.querySelector(".icon-users-1")?.classList.remove("OrangeIcon");
        groupBox.querySelector(".group_number_users")?.classList.remove("OrangeFont");

        document.querySelectorAll(".DeleteGroupList div").forEach(el => {
            if (el.getAttribute("data-id") == group_id) el.remove();
        });

        updateSelectionPanel();

        document.querySelectorAll("#MergeOptionsChoices option").forEach(el => {
            if (el.value == group_id) el.remove();
        });
    }
};

/* Group Ajax and Display Functions */
var deleteGroup = function (id) {
    let groupName = document.querySelector("#group-" + id + " #group_name")?.innerHTML || "";
    pwgConfirm({
        title: str_delete.replace("%s", groupName),
        buttons: {
            confirm: {
                text: str_yes_delete_confirmation,
                btnClass: "btn-red",
                action: function () {
                    fetch("ws.php?format=json&method=pwg.groups.delete", {
                        method: "POST",
                        body: "group_id=" + id + "&pwg_token=" + pwg_token,
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    })
                    .then(response => response.text())
                    .then(raw_data => {
                        var data = JSON.parse(raw_data);
                        if (data.stat === "ok") {
                            document.getElementById("group-" + id)?.remove();
                            document.querySelectorAll(".DeleteGroupList div[data-id=" + id + "]").forEach(el => el.remove());
                            document.querySelectorAll("#MergeOptionsChoices option[value=" + id + "]").forEach(el => el.remove());
                        }
                        updateBadge();
                    });
                },
            },
            cancel: {
                text: str_no_delete_confirmation,
            },
        },
    });
};

var renameGroup = function (id, newName) {
    let loadState = new TemporaryState();
    let validateBtn = document.querySelector("#group-" + id + " .group-rename .validate");
    loadState.changeHTML(validateBtn, "<i class='animate-spin icon-spin6'></i>");
    loadState.removeClass(validateBtn, "icon-ok");
    loadState.changeAttribute(document.querySelector("#group-" + id + " .group-rename span"), "style", "pointer-events: none");

    if (newName.replace(/\s/g, "").length != 0) {
        fetch("ws.php?format=json&method=pwg.groups.setInfo", {
            method: "POST",
            body: "group_id=" + id + "&pwg_token=" + pwg_token + "&name=" + encodeURIComponent(newName),
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        })
        .then(response => response.text())
        .then(raw_data => {
            var data = JSON.parse(raw_data);
            loadState.reverse();
            if (data.stat === "ok") {
                newName = data.result.groups[0].name;
                //Display message
                let msg = document.querySelector("#group-" + id + " .groupMessage");
                if (msg) {
                    msg.innerHTML = str_renaming_done;
                    msg.style.display = '';
                    setTimeout(() => msg.style.display = 'none', DELAY_FEEDBACK);
                }
                let nameEl = document.querySelector("#group-" + id + " #group_name");
                if (nameEl) nameEl.innerHTML = newName;

                //Hide editable field
                displayRenameForm(false, id);
            } else {
                //Display error message
                let error = document.querySelector("#group-" + id + " .groupError");
                if (error) {
                    error.innerHTML = str_name_taken;
                    error.style.display = '';
                    setTimeout(() => error.style.display = 'none', DELAY_FEEDBACK);
                }
            }
        })
        .catch(error => console.log(error));
    } else {
        loadState.reverse();
        let error = document.querySelector("#group-" + id + " .groupError");
        if (error) {
            error.innerHTML = str_name_not_empty;
            error.style.display = '';
            setTimeout(() => error.style.display = 'none', DELAY_FEEDBACK);
        }
    }
};

// Hide or display rename form
var displayRenameForm = function (doDisplay, grp_id) {
    if (doDisplay) {
        let form = document.querySelector("#group-" + grp_id + " .group-rename");
        if (form) form.style.display = "flex";
        let pencil = document.querySelector("#group-" + grp_id + " .Group-name-container .icon-pencil");
        if (pencil) pencil.style.display = 'none';
        let name = document.querySelector("#group-" + grp_id + " .Group-name-container p");
        if (name) name.style.opacity = "0";
    } else {
        let form = document.querySelector("#group-" + grp_id + " .group-rename");
        if (form) form.style.display = 'none';
        let pencil = document.querySelector("#group-" + grp_id + " .Group-name-container .icon-pencil");
        if (pencil) pencil.style.display = '';
        let name = document.querySelector("#group-" + grp_id + " .Group-name-container p");
        if (name) name.style.opacity = "1";
    }
};

var setDefaultGroup = function (id, is_default) {
    let btn = document.querySelector("#group-" + id + " #GroupDefault");
    if (btn) {
        btn.style.width = btn.offsetWidth + "px";
        btn.innerHTML = "<i class='icon-spin6 animate-spin'> </i>";
        btn.classList.remove("icon-star");
        btn.style.pointerEvents = "none";
        btn.style.textAlign = "center";
    }

    let token = document.querySelector("#group-" + id + " .is-default-token");
    if (token) {
        token.classList.add("icon-spin6", "animate-spin");
        token.classList.remove("icon-star");
    }

    fetch("ws.php?format=json&method=pwg.groups.setInfo", {
        method: "POST",
        body: "group_id=" + id + "&pwg_token=" + pwg_token + "&is_default=" + is_default,
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    })
    .then(response => response.text())
    .then(raw_data => {
        var data = JSON.parse(raw_data);
        let opts = document.querySelector("#group-" + id + " #GroupOptions");
        if (opts) opts.style.display = 'none';
        if (data.stat === "ok") {
            if (is_default) {
                setupDefaultActions(id, true);
            } else {
                setupDefaultActions(id, false);
            }
        }
    })
    .catch(error => console.log(error));
};

var setupDefaultActions = function (id, is_default) {
    let btn = document.querySelector("#group-" + id + " #GroupDefault");
    if (btn) {
        btn.style.cssText = "";
        btn.classList.add("icon-star");
    }

    let token = document.querySelector("#group-" + id + " .is-default-token");
    if (token) {
        token.classList.remove("icon-spin6", "animate-spin");
        token.classList.add("icon-star");
    }

    if (is_default) {
        let btn = document.querySelector("#group-" + id + " #GroupDefault");
        if (btn) {
            btn.innerHTML = str_unset_default;
            btn.removeEventListener("click", arguments.callee);
            btn.addEventListener("click", function () { setDefaultGroup(id, false); });
        }

        let token = document.querySelector("#group-" + id + " .is-default-token");
        if (token) {
            token.setAttribute("title", str_unset_default);
            token.classList.remove("deactivate");
            token.removeEventListener("click", arguments.callee);
            token.addEventListener("click", function () { setDefaultGroup(id, false); });
        }
    } else {
        let btn = document.querySelector("#group-" + id + " #GroupDefault");
        if (btn) {
            btn.innerHTML = str_set_default;
            btn.addEventListener("click", function () { setDefaultGroup(id, true); });
        }

        let token = document.querySelector("#group-" + id + " .is-default-token");
        if (token) {
            token.setAttribute("title", str_set_default);
            token.classList.add("deactivate");
            token.removeEventListener("click", arguments.callee);
        }
    }
};

var duplicateAction = function (id) {
    let loadState = new TemporaryState();
    let dupBtn = document.querySelector("#group-" + id + " #GroupDuplicate");
    loadState.changeHTML(dupBtn, "<i class='icon-spin6 animate-spin'> </i>");
    loadState.removeClass(dupBtn, "icon-docs");
    loadState.changeAttribute(dupBtn, "style", "pointer-events: none; text-align: center;");

    var copy_name = (document.querySelector("#group-" + id + " #group_name")?.innerHTML || "") + str_copy;

    let name_exist = function (name) {
        let exist = false;
        document.querySelectorAll(".Group-name-container p").forEach(el => {
            if (el.innerHTML === name) exist = true;
        });
        return exist;
    };

    let i = 1;
    while (name_exist(copy_name)) {
        copy_name = (document.querySelector("#group-" + id + " #group_name")?.innerHTML || "") + str_other_copy.replace("%s", i++);
    }

    fetch("ws.php?format=json&method=pwg.groups.duplicate", {
        method: "POST",
        body: "group_id=" + id + "&pwg_token=" + pwg_token + "&copy_name=" + encodeURIComponent(copy_name),
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    })
    .then(response => response.text())
    .then(raw_data => {
        var data = JSON.parse(raw_data);
        var group;
        loadState.reverse();
        if (data.stat === "ok") {
            let opts = document.querySelector("#group-" + id + " #GroupOptions");
            if (opts) opts.style.display = 'none';
            group = data.result.groups[0];
            let groupbox = createGroup(group);
            document.getElementById("group-" + id)?.insertAdjacentElement("afterend", groupbox);
            setupGroupBox(groupbox);
            updateBadge();

            /* data.result.groups[0].is_default is a string */
            if (data.result.groups[0].is_default == "true") {
                setupDefaultActions(data.result.groups[0].id, true);
            }
        }
    })
    .catch(error => console.log(error));
};

/*-------
 Selection mode toggle actions,
 changes "..." in group block to checkbox,
 disables group actions in selection mode
 -------*/

    let toggleBtn = document.getElementById("toggleSelectionMode");
    if (toggleBtn) {
        toggleBtn.checked = false;
        toggleBtn.addEventListener("click", function () {
            if (this.checked) {
                document.querySelectorAll(".in-selection-mode").forEach(el => el.style.display = 'block');
                document.querySelectorAll(".not-in-selection-mode").forEach(el => el.style.display = 'none');
                document.querySelector(".GroupManagerButtons")?.classList.remove("visible");
            } else {
                document.querySelectorAll(".in-selection-mode").forEach(el => el.style.display = '');
                document.querySelectorAll(".not-in-selection-mode").forEach(el => el.style.cssText = "");
                document.querySelectorAll(".Group-checkbox input").forEach(el => el.checked = false);
                document.querySelectorAll(".Group-checkbox input[type='checkbox']").forEach(el => {
                    el.dispatchEvent(new Event("change"));
                });
            }
        });
    }

    /*-------
    Update Selection Panel
    -------*/
    var state = "NoSelection";

var updateSelectionPanel = function (changedState = "") {
    let numSelect = document.querySelectorAll(".DeleteGroupList div").length;

    if (numSelect == 0) {
        updateStatePanel("NoSelection");
    } else if (changedState == "") {
        if (numSelect == 1 && state != "ConfirmDeletion")
            updateStatePanel("OneSelected");
        if (numSelect > 1 && state == "OneSelected")
            updateStatePanel("Selection");
    } else {
        if (changedState == "Selection" && numSelect == 1)
            updateStatePanel("OneSelected");
        else updateStatePanel(changedState);
    }

    let numEl = document.querySelector(".number-Selected");
    if (numEl) numEl.innerHTML = numSelect + "";
};

/*Update the state of the panel in 5 states :
 NoSelection, OneSelected, ConfirmDeletion, Selection, OptionMerge
 */
var updateStatePanel = function (newState = "Selection") {
    state = newState;
    let deleteBtn = document.getElementById("DeleteSelectionMode");
    let mergeBtn = document.getElementById("MergeSelectionMode");
    let mergeBlock = document.getElementById("MergeOptionsBlock");
    let confirmBlock = document.getElementById("ConfirmGroupAction");

    switch (newState) {
        case "OneSelected":
            if (deleteBtn) deleteBtn.style.display = '';
            if (mergeBtn) mergeBtn.style.display = '';
            buttonUnavailable(mergeBtn);
            buttonAvailable(deleteBtn, "updateSelectionPanel('ConfirmDeletion')");
            if (mergeBlock) mergeBlock.style.display = 'none';
            if (confirmBlock) confirmBlock.style.display = 'none';
            break;
        case "ConfirmDeletion":
            if (deleteBtn) deleteBtn.style.display = 'none';
            if (mergeBtn) mergeBtn.style.display = 'none';
            if (mergeBlock) mergeBlock.style.display = 'none';
            if (confirmBlock) confirmBlock.style.display = '';
            break;
        case "Selection":
            if (deleteBtn) deleteBtn.style.display = '';
            if (mergeBtn) mergeBtn.style.display = '';
            buttonAvailable(mergeBtn, "updateSelectionPanel('OptionMerge')");
            buttonAvailable(deleteBtn, "updateSelectionPanel('ConfirmDeletion')");
            if (mergeBlock) mergeBlock.style.display = 'none';
            if (confirmBlock) confirmBlock.style.display = 'none';
            break;
        case "OptionMerge":
            if (deleteBtn) deleteBtn.style.display = 'none';
            if (mergeBtn) mergeBtn.style.display = 'none';
            if (mergeBlock) mergeBlock.style.display = '';
            if (confirmBlock) confirmBlock.style.display = 'none';
            break;
    }
    var selectionModeGroup = document.querySelector(".SelectionModeGroup");
    if (newState == "NoSelection") {
        if (deleteBtn) deleteBtn.style.display = '';
        if (mergeBtn) mergeBtn.style.display = '';
        buttonUnavailable(mergeBtn);
        buttonUnavailable(deleteBtn);
        if (selectionModeGroup) selectionModeGroup.style.display = 'none';
        let nothingSelected = document.getElementById("nothing-selected");
        if (nothingSelected) nothingSelected.style.display = '';
        if (mergeBlock) mergeBlock.style.display = 'none';
        if (confirmBlock) confirmBlock.style.display = 'none';
    } else {
        if (selectionModeGroup) selectionModeGroup.style.display = '';
        let nothingSelected = document.getElementById("nothing-selected");
        if (nothingSelected) nothingSelected.style.display = 'none';
    }
};

var buttonAvailable = function (button, onClick) {
    if (button) {
        button.classList.remove("unavailable");
        button.setAttribute("onClick", onClick);
    }
};

var buttonUnavailable = function (button) {
    if (button) {
        button.classList.add("unavailable");
        button.removeAttribute("onClick");
    }
};

/*-------
 Merge function on button's panel
 -------*/

document.querySelector(".ConfirmMergeButton")?.addEventListener("click", function () {
    var merge_group = [];
    var str_merge_group = "";
    var name_merge = [];
    var name_dest = [];
    var dest_grp;

    let loadState = new TemporaryState();
    let mergeBtn = document.querySelector(".ConfirmMergeButton");
    loadState.changeAttribute(mergeBtn, "style", "pointer-events: none");
    loadState.changeHTML(mergeBtn, "<i class='icon-spin6 animate-spin'> </i>");
    loadState.removeClass(mergeBtn, "icon-ok");

    let destSelect = document.getElementById("MergeOptionsChoices");
    dest_grp = destSelect ? destSelect.value : "";

    document.querySelectorAll(".DeleteGroupList div").forEach(el => {
        if (dest_grp != el.getAttribute("data-id")) {
            str_merge_group += "&merge_group_id[]=" + el.getAttribute("data-id");
            merge_group.push(el.getAttribute("data-id"));
            name_merge.push(el.querySelector("p")?.innerHTML || "");
        } else {
            name_dest = el.querySelector("p")?.innerHTML || "";
        }
    });

    fetch("ws.php?format=json&method=pwg.groups.merge", {
        method: "POST",
        body: "destination_group_id=" + dest_grp + str_merge_group + "&pwg_token=" + pwg_token,
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    })
    .then(response => response.text())
    .then(raw_data => {
        var data = JSON.parse(raw_data);
        loadState.reverse();
        if (data.stat === "ok") {
            updateSelectionPanel("Selection");
            merge_group.forEach(function (id) {
                let el = document.getElementById("group-" + id);
                if (el) {
                    el.style.opacity = "0";
                    el.style.transition = "opacity 0.4s ease";
                    setTimeout(() => el.remove(), 400);
                }
            });
            toggleSelection(dest_grp, false);
            let list = document.querySelector(".DeleteGroupList");
            if (list) list.innerHTML = "";
            let mergeSelect = document.getElementById("MergeOptionsChoices");
            if (mergeSelect) mergeSelect.innerHTML = "";

            pwgConfirm({
                title: str_merged_into
                    .replace("%s1", name_merge.toString())
                    .replace("%s2", name_dest),
                buttons: { ok: { text: 'OK' } }
            });

            let userCount = document.querySelector("#group-" + dest_grp + " .group_number_users");
            if (userCount) userCount.innerHTML = "<i class='icon-spin6 animate-spin'> </i>";

            fetch("ws.php?format=json&method=pwg.users.getList", {
                method: "POST",
                body: "group_id=" + dest_grp,
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            })
            .then(response => response.text())
            .then(raw_data => {
                var data = JSON.parse(raw_data);
                let number = data.result.users.length;
                let userCountEl = document.querySelector("#group-" + dest_grp + " .group_number_users");
                if (userCountEl) {
                    userCountEl.innerHTML = number + " " + (number > 1 ? str_members_default : str_member_default);
                }
                updateBadge();
            });
        }
    });
});

/*-------
 Delete function on button's panel
 -------*/

document.querySelector(".ConfirmDeleteButton")?.addEventListener("click", function () {
    let names = [];
    let ids = [];
    document.querySelectorAll(".DeleteGroupList div").forEach(el => {
        let id = el.getAttribute("data-id");
        names.push(document.querySelector("#group-" + id + " #group_name")?.innerHTML || "");
        ids.push(id);
    });

    let loadState = new TemporaryState();
    let delBtn = document.querySelector(".ConfirmDeleteButton");
    loadState.changeAttribute(delBtn, "style", "pointer-events: none");
    loadState.changeHTML(delBtn, "<i class='icon-spin6 animate-spin'> </i>");
    loadState.removeClass(delBtn, "icon-ok");

    var str_id = "";
    ids.forEach(function (id) {
        str_id += "group_id[]=" + id + "&";
    });

    fetch("ws.php?format=json&method=pwg.groups.delete", {
        method: "POST",
        body: str_id + "pwg_token=" + pwg_token,
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    })
    .then(response => response.text())
    .then(raw_data => {
        var data = JSON.parse(raw_data);
        if (data.stat === "ok") {
            document.querySelectorAll(".DeleteGroupList div").forEach(el => {
                let id = el.getAttribute("data-id");
                el.remove();
                document.getElementById("group-" + id)?.remove();
                document.querySelectorAll("#MergeOptionsChoices option[value=" + id + "]").forEach(opt => opt.remove());
            });

            loadState.reverse();
            updateSelectionPanel("NoSelection");
            pwgConfirm({
                title: str_groups_deleted.replace("%s", names.toString()),
                buttons: { ok: { text: 'OK' } }
            });
            updateBadge();
        }
    })
    .catch(error => console.log(error));
});

/*-------
 Manage User Part
 -------*/

// Initialize the research user bar
var selectize;

// Initialize the cache
var usersCache = {};

var usersInGroup = [];

// Max offset of the user container (322 = 6 lines)
var maxOffsetUserCont = 322;

    // Setup the user research bar
    // initialize the TomSelect control
    let selectEl = document.querySelector(".AddUserBlock select");
    if (selectEl) {
        selectize = selectEl.tomselect || new TomSelect(selectEl, {});

        var idSearch = "";
        var updateUserSearch;

        document.querySelector(".UserSearch input")?.addEventListener("focus", function () {
            if (idSearch != document.getElementById("UserList")?.getAttribute("data-group_id")) {
                updateUserSearch();
            }
        });

        // Update User search bar (remove group users in selection)
        updateUserSearch = function () {
            var usersCache;
            if (selectize) selectize.clear();
            usersCache = new UsersCache({
                serverKey: serverKey,
                serverId: serverId,
                rootUrl: rootUrl,
            });
            try {
                JSON.parse(usersCache.storage[usersCache.key]).data.forEach(function (u) {
                    if (selectize) selectize.addOption({ value: u.id, text: u.username });
                });
            } catch (_e) {
                // Handle cache parsing errors
            }
            idSearch = document.getElementById("UserList")?.getAttribute("data-group_id");

            if (selectize) {
                for (const [, value] of Object.entries(selectize.options)) {
                    if (value.username === "guest") {
                        selectize.removeOption(value.id);
                    }
                }
            }

            document.querySelectorAll(".UsernameBlock").forEach(el => {
                if (selectize) selectize.removeOption(el.getAttribute("data-id"));
            });
        };
    }

    // Display the user manager for a specific group
    var openUserManager = function (grp_id) {
    let loadState = new TemporaryState();
    let trigger = document.querySelector("#group-" + grp_id + " #UserListTrigger");
    loadState.removeClass(trigger, "icon-user-1");
    loadState.changeAttribute(trigger, "style", "pointer-events: none");
    loadState.changeHTML(trigger, "<i class='icon-spin6 animate-spin'> </i>");

    fetch("ws.php?format=json&method=pwg.users.getList", {
        method: "POST",
        body: "group_id=" + grp_id,
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    })
    .then(response => response.text())
    .then(raw_data => {
        var data = JSON.parse(raw_data);
        loadState.reverse();
        if (data.stat === "ok") {
            //Set the popin name
            let nameBlock = document.querySelector(".group-name-block p");
            if (nameBlock) nameBlock.innerHTML = (document.querySelector("#group-" + grp_id + " #group_name")?.innerHTML || "") + " / " + str_user_list;

            let userList = document.querySelector(".UsersInGroupList");
            if (userList) userList.innerHTML = "";

            //Display the popin
            let popup = document.getElementById("UserList");
            if (popup) popup.style.display = '';

            //Fill with user blocks
            usersInGroup = data.result.users;
            // Sort in alphabetic order
            usersInGroup.sort(function (a, b) {
                if (a.username.toLowerCase() < b.username.toLowerCase()) {
                    return -1;
                } else return 1;
            });

            let i = 0;
            let userListEl = document.querySelector(".UsersInGroupList");
            while (userListEl && userListEl.offsetHeight <= maxOffsetUserCont && usersInGroup[i] != undefined) {
                let userEl = getUserDisplay(usersInGroup[i].username, usersInGroup[i].id, grp_id);
                userListEl.appendChild(userEl);
                i++;
            }

            while (userListEl && userListEl.offsetHeight > maxOffsetUserCont) {
                userListEl.querySelector(".UsernameBlock:last-child")?.remove();
            }

            updateMembernumber(usersInGroup.length, grp_id);
            //Attribute the group id to the div
            if (popup) popup.setAttribute("data-group_id", grp_id);

            let managerLink = document.querySelector(".LinkUserManager a");
            if (managerLink) managerLink.href = "admin.php?page=user_list&group=" + grp_id;
        }
    })
    .catch(error => console.log(error));
};

//Add a user block
var getUserDisplay = function (username, user_id, grp_id) {
    let userBlock = document.createElement("div");
    userBlock.className = "UsernameBlock";
    userBlock.setAttribute("data-id", user_id);
    userBlock.innerHTML = '<span class="icon-user-1"></span><p>' + username + '</p><div class="Tooltip"><span class="icon-cancel"></span><p class="TooltipText">' + str_user_dissociate + '</p></div>';

    let userList = document.querySelector(".UsersInGroupList");
    while (userList && userList.offsetHeight > maxOffsetUserCont) {
        userList.querySelector(".UsernameBlock:last-child")?.remove();
    }

    //Setup the delete action
    let cancelBtn = userBlock.querySelector(".icon-cancel");
    if (cancelBtn) {
        cancelBtn.addEventListener("click", function () {
            cancelBtn.classList.add("icon-spin6", "animate-spin");
            cancelBtn.style.pointerEvents = "none";
            cancelBtn.classList.remove("icon-cancel");

            fetch("ws.php?format=json&method=pwg.groups.deleteUser", {
                method: "POST",
                body: "group_id=" + grp_id + "&user_id=" + user_id + "&pwg_token=" + pwg_token,
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            })
            .then(response => response.text())
            .then(raw_data => {
                var data = JSON.parse(raw_data);
                if (data.stat === "ok") {
                    let str = str_user_dissociated.replace("%s", username);
                    associateUserInfo.style.display = 'none';
                    dissociateUserInfo.querySelector("p").innerHTML = str;
                    dissociateUserInfo.style.display = '';

                    document.querySelectorAll(".UsernameBlock").forEach(el => {
                        el.style.marginRight = "10px";
                        el.style.border = "none";
                    });
                    userBlock.remove();

                    updateUserSearch();

                    let userList = document.querySelector(".UsersInGroupList");
                    while (userList && userList.offsetHeight > maxOffsetUserCont) {
                        userList.querySelector(".UsernameBlock:last-child")?.remove();
                    }

                    usersInGroup = usersInGroup.filter((u) => u.id != user_id);

                    //Update member number
                    let badgeNum = document.querySelector(".UserNumberBadge")?.innerHTML || "0";
                    updateMembernumber(parseInt(badgeNum) - 1, grp_id);
                }
            });
        });
    }
    return userBlock;
};

//Update member number function
function updateMembernumber(number, grp_id) {
    let el = document.querySelector(".GroupContainer[data-id=" + grp_id + "] .group_number_users");
    if (el) el.innerHTML = number + " " + (number > 1 ? str_members_default : str_member_default);

    let badge = document.querySelector(".UserNumberBadge");
    if (badge) badge.innerHTML = number;

    let shown2 = document.querySelector(".AmountOfUsersShown strong:nth-child(2)");
    if (shown2) shown2.innerHTML = number;

    let shown1 = document.querySelector(".AmountOfUsersShown strong:nth-child(1)");
    if (shown1) shown1.innerHTML = document.querySelectorAll(".UsernameBlock").length;
}

// Close pop-up on cross click
document.querySelector(".CloseUserList")?.addEventListener("click", function () {
    let popup = document.getElementById("UserList");
    if (popup) popup.style.display = 'none';
});

// Adding Group Action
document.querySelector(".AddUserBlock button")?.addEventListener("click", function () {
    let grp_id = document.getElementById("UserList")?.getAttribute("data-group_id");
    let id = selectize ? selectize.getValue() : "";

    if (id != "") {
        let loadState = new TemporaryState();
        let submitBtn = document.getElementById("UserSubmit");
        loadState.changeHTML(submitBtn, "<i class='icon-spin6 animate-spin'> </i>");
        loadState.removeClass(submitBtn, "icon-user-add");
        loadState.changeAttribute(submitBtn, "style", "pointer-events:none");

        fetch("ws.php?format=json&method=pwg.groups.addUser", {
            method: "POST",
            body: "group_id=" + grp_id + "&user_id=" + id + "&pwg_token=" + pwg_token,
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        })
        .then(response => response.text())
        .then(raw_data => {
            var data = JSON.parse(raw_data);
            loadState.reverse();

            if (data.stat === "ok") {
                // Get the username
                let username = "undefined";
                try {
                    JSON.parse(usersCache.storage[usersCache.key]).data.forEach(function (u) {
                        if (u.id == id) {
                            username = u.username;
                        }
                    });
                } catch (_e) {
                    // Handle cache errors
                }

                let userBlock = getUserDisplay(username, id, grp_id);
                document.querySelector(".UsersInGroupList")?.insertBefore(userBlock, document.querySelector(".UsersInGroupList")?.firstChild);

                associateUserInfo.style.display = 'none';

                document.querySelectorAll(".UsernameBlock:first-child").forEach(el => {
                    el.style.marginRight = "0px";
                    el.style.border = "2px solid #c2f5c2";
                });
                document.querySelectorAll(".UsernameBlock").forEach((el, idx) => {
                    if (idx > 0) {
                        el.style.marginRight = "10px";
                        el.style.border = "none";
                    }
                });

                if (associateUserInfo.parentNode) associateUserInfo.parentNode.removeChild(associateUserInfo);
                userBlock.insertAdjacentElement("afterend", associateUserInfo);
                associateUserInfo.querySelector("p").innerHTML = str_user_associated;
                associateUserInfo.style.display = '';

                updateUserSearch();

                usersInGroup.push({ username: username, id: id });

                let userList = document.querySelector(".UsersInGroupList");
                while (userList && userList.offsetHeight > maxOffsetUserCont) {
                    userList.querySelector(".UsernameBlock:last-child")?.remove();
                }

                //Update member number
                let badgeNum = document.querySelector(".UserNumberBadge")?.innerHTML || "0";
                updateMembernumber(parseInt(badgeNum) + 1, grp_id);
            }
        });
    }
});

document.querySelector(".input-user-name")?.addEventListener("input", function () {
    var searchString = this.value.toLowerCase();
    let grp_id = document.querySelector(".UserListPopIn")?.getAttribute("data-group_id");
    if (searchString != "") {
        let container = document.querySelector(".UsersInGroupListContainer");
        if (container) container.style.minHeight = container.offsetHeight + "px";

        usersInGroup.forEach(function (u) {
            let isSearched = u.username.toLowerCase().includes(searchString);
            let userBlock = document.querySelector(".UsernameBlock[data-id=" + u.id + "]");
            if (userBlock && !isSearched) {
                userBlock.remove();
            } else if (!userBlock && isSearched) {
                let newBlock = getUserDisplay(u.username, u.id, grp_id);
                document.querySelector(".UsersInGroupList")?.insertBefore(newBlock, document.querySelector(".UsersInGroupList")?.firstChild);
            }
        });
    } else {
        let container = document.querySelector(".UsersInGroupListContainer");
        if (container) container.style.minHeight = "";
        let userList = document.querySelector(".UsersInGroupList");
        if (userList) userList.innerHTML = "";

        let i = 0;
        while (userList && userList.offsetHeight <= maxOffsetUserCont && usersInGroup[i] != undefined) {
            let userEl = getUserDisplay(usersInGroup[i].username, usersInGroup[i].id, grp_id);
            userList.appendChild(userEl);
            i++;
        }
    }
    let shown = document.querySelector(".AmountOfUsersShown strong:nth-child(1)");
    if (shown) shown.innerHTML = document.querySelectorAll(".UsernameBlock").length;

    let userList = document.querySelector(".UsersInGroupList");
    while (userList && userList.offsetHeight > maxOffsetUserCont) {
        userList.querySelector(".UsernameBlock:last-child")?.remove();
    }
});

    // temporary fix for #1283 (begin) : force user local storage cache on page load.
    usersCache = new UsersCache({
      serverKey: serverKey,
      serverId: serverId,
      rootUrl: rootUrl
    });

  usersCache.selectize(document.querySelectorAll('select.UserSearch'));
  // temporary fix for #1283 (end)

  // Escape key handler
  document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
      const userListEl = document.getElementById("UserList");
      if (userListEl) {
        userListEl.style.display = 'none';
      }
    }
  });

  // Click-outside handler for UserList
  document.addEventListener('click', function(e) {
    if (!e.target.closest(".UserListPopInContainer")) {
      const userListEl = document.getElementById("UserList");
      if (userListEl) {
        userListEl.style.display = 'none';
      }
    }
  });
}

initModule(init);
