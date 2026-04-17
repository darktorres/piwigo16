//Get the data
var dataTags = document.querySelector(".tag-container")?.dataset.tags ? JSON.parse(document.querySelector(".tag-container").dataset.tags) : [];

//Initiate Select
var _sel100 = document.getElementById("select-100");
if (_sel100) _sel100.checked = true;

//Orphan tags
document.querySelector(".info-warning p a")?.addEventListener("click", () => {
    let url = document.querySelector(".info-warning p a")?.dataset.url;
    let tags = orphan_tag_names;
    let str_orphans = str_orphan_tags
        .replace("%s1", tags.length)
        .replace("%s2", tags.join(", "));
    pwgConfirm({
        content: str_orphans,
        title: str_delete_orphan_tags,
        buttons: {
            delete: {
                text: str_delete_them,
                btnClass: "btn-red",
                action: function () {
                    window.location.href = url.replace(/amp;/g, "");
                },
            },
            keep: {
                text: str_keep_them,
                action: function () {
                    document.querySelector(".info-warning").style.display = 'none';
                },
            },
        },
    });
});

//Create and recycle tag box
function createTagBox(id, name, url_name, count, raw_name = null) {
    if (raw_name === null) {
        raw_name = name;
    }
    let u_edit = "admin.php?page=batch_manager&filter=tag-" + id;
    let u_view = "index.php?/tags/" + id + "-" + url_name;
    let templateEl = document.querySelector(".tag-template");
    let html = templateEl ? templateEl.innerHTML : "";
    html = html
        .replace(/%name%/g, unescape(name))
        .replace("%U_VIEW%", u_view)
        .replace("%U_EDIT%", u_edit)
        .replace("%raw_name%", raw_name);
    if (name == raw_name) {
        html = html.replace("icon-globe", "");
    }
    let div = document.createElement("div");
    div.className = "tag-box test";
    div.setAttribute("data-id", id);
    div.setAttribute("data-selected", "0");
    div.innerHTML = html;

    let toggleEl = document.getElementById("toggleSelectionMode");
    if (toggleEl && toggleEl.checked) {
        div.classList.add("selection");
        let inSelection = div.querySelector(".in-selection-mode");
        if (inSelection) inSelection.style.display = '';
    }
    if (count > 0) {
        let views = div.querySelectorAll(".dropdown-option.view, .dropdown-option.manage");
        views.forEach(el => el.style.display = "block");
        let headerI = div.querySelector(".tag-dropdown-header i");
        if (headerI) headerI.innerHTML = str_number_photos.replace("%d", count);
    } else {
        let headerI = div.querySelector(".tag-dropdown-header i");
        if (headerI) headerI.innerHTML = str_no_photos;
    }
    return div;
}

function recycleTagBox(tagBox, id, name, url_name, count, raw_name = null) {
    if (raw_name === null) {
        raw_name = name;
    }
    tagBox = tagBox instanceof NodeList ? tagBox[0] : tagBox;
    tagBox.setAttribute("data-id", id);
    let nameEls = tagBox.querySelectorAll(".tag-name, .tag-dropdown-header b");
    nameEls.forEach(el => el.innerHTML = name);
    let editableEl = tagBox.querySelector(".tag-name-editable");
    if (editableEl) editableEl.value = name;
    tagBox.setAttribute("data-selected", "0");
    let tagNameEl = tagBox.querySelector(".tag-name");
    if (tagNameEl) tagNameEl.dataset.rawname = raw_name;

    //Dropdown
    let u_edit = "admin.php?page=batch_manager&filter=tag-" + id;
    let u_view = "index.php?/tags/" + id + "-" + url_name;
    let viewLink = tagBox.querySelector(".dropdown-option.view");
    let manageLink = tagBox.querySelector(".dropdown-option.manage");
    if (viewLink) viewLink.href = u_view;
    if (manageLink) manageLink.href = u_edit;

    if (count > 0) {
        let views = tagBox.querySelectorAll(".dropdown-option.view, .dropdown-option.manage");
        views.forEach(el => el.style.display = "block");
        let headerI = tagBox.querySelector(".tag-dropdown-header i");
        if (headerI) headerI.innerHTML = str_number_photos.replace("%d", count);
    } else {
        let headerI = tagBox.querySelector(".tag-dropdown-header i");
        if (headerI) headerI.innerHTML = str_no_photos;
    }
}

//Number On Badge
function updateBadge() {
    let badgeEl = document.querySelector(".badge-number");
    if (badgeEl) badgeEl.innerHTML = dataTags.length;
    let labelEl = document.querySelector(".tag-header #add-tag .add-tag-label");
    if (dataTags.length == 0) {
        if (labelEl) labelEl.classList.add("highlight");
    } else {
        if (labelEl) labelEl.classList.remove("highlight");
    }
}

//Add a tag
document.querySelector(".add-tag-container")?.addEventListener("click", function () {
    document.getElementById("add-tag").classList.add("input-mode");
    document.getElementById("add-tag-input").focus();
    let tagInfo = document.querySelector(".tag-info");
    if (tagInfo) tagInfo.style.display = 'none';
});

document.querySelector("#add-tag .icon-cancel-circled")?.addEventListener("click", function () {
    document.getElementById("add-tag").classList.remove("input-mode");
    let tagInfo = document.querySelector(".tag-info");
    if (tagInfo) tagInfo.style.display = 'none';
});

//Display/Hide tag option
document.querySelectorAll(".tag-box").forEach(function (el) {
    setupTagbox(el);
});

//Call the API when rename a tag
document.querySelector(".TagSubmit")?.addEventListener("click", function () {
    document.querySelector(".TagSubmit").style.display = 'none';
    document.querySelector(".TagLoading").style.display = '';
    let inputEl = document.querySelector(".RenameTagPopInContainer .tag-property-input");
    renameTag(
        inputEl.id,
        inputEl.value,
    )
        .then(() => {
            document.querySelector(".TagSubmit").style.display = '';
            document.querySelector(".TagLoading").style.display = 'none';
            rename_tag_close();
        })
        .catch((message) => {
            document.querySelector(".TagSubmit").style.display = '';
            document.querySelector(".TagLoading").style.display = 'none';
            console.error(message);
        });
});

/*-------
 Add a tag
-------*/

document.getElementById("add-tag")?.addEventListener("submit", function (e) {
    e.preventDefault();
    let inputEl = document.getElementById("add-tag-input");
    if (inputEl && inputEl.value != "") {
        loadState = new TemporaryState();
        let validateIcon = document.querySelector("#add-tag .icon-validate");
        loadState.removeClass(validateIcon, "icon-plus");
        loadState.changeHTML(
            validateIcon,
            "<i class='icon-spin6 animate-spin'> </i>",
        );
        loadState.changeAttribute(
            validateIcon,
            "style",
            "pointer-event:none",
        );
        addTag(inputEl.value)
            .then(function () {
                showMessage(
                    str_tag_created.replace("%s", inputEl.value),
                );
                inputEl.value = "";
                document.getElementById("add-tag").classList.remove("input-mode");
                let searchInput = document.querySelector("#search-tag .search-input");
                if (searchInput) searchInput.dispatchEvent(new Event("input"));
                loadState.reverse();
            })
            .catch((message) => {
                loadState.reverse();
                showError(message);
            });
    }
});

document.querySelector("#add-tag .icon-validate")?.addEventListener("click", function () {
    let addTag = document.getElementById("add-tag");
    if (addTag && addTag.classList.contains("input-mode")) {
        addTag.dispatchEvent(new Event("submit"));
    }
});

function addTag(name) {
    return new Promise((resolve, reject) => {
        fetch("ws.php?format=json&method=pwg.tags.add", {
            method: "POST",
            body: new URLSearchParams({ name: name }),
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
        })
        .then(response => response.text())
        .then(raw_data => {
            data = JSON.parse(raw_data);
            if (data.stat === "ok") {
                newTag = createTagBox(
                    data.result.id,
                    data.result.name,
                    data.result.url_name,
                    0,
                );
                let container = document.querySelector(".tag-container");
                if (container) container.prepend(newTag);
                setupTagbox(newTag);
                updateSearchInfo();

                //Update the data
                dataTags.unshift({
                    name: data.result.name,
                    raw_name: data.result.name,
                    id: data.result.id,
                    url_name: data.result.url_name,
                });
                updateBadge();
                resolve();
            } else {
                reject(str_already_exist.replace("%s", name));
            }
        })
        .catch(error => reject(error));
    });
}

/*-------
 Setup Tag Box
-------*/

function setupTagbox(tagBox) {
    //Dropdown options
    let showOptionsBtn = tagBox.querySelector(".showOptions");
    if (showOptionsBtn) {
        showOptionsBtn.addEventListener("click", function () {
            let dropdown = tagBox.querySelector(".tag-dropdown-block");
            if (dropdown) dropdown.style.display = "grid";
        });
    }

    document.addEventListener("mouseup", function (e) {
        e.stopPropagation();
        let option_is_clicked = false;
        tagBox.querySelectorAll(".dropdown-option").forEach(el => {
            if (el.contains(e.target)) {
                option_is_clicked = true;
            }
        });
        if (!option_is_clicked) {
            let dropdown = tagBox.querySelector(".tag-dropdown-block");
            if (dropdown) dropdown.style.display = 'none';
        }
    });

    // Selection behaviour
    tagBox.addEventListener("click", function () {
        let container = document.querySelector(".tag-container");
        if (container && container.classList.contains("selection")) {
            if (this.getAttribute("data-selected") == "1") {
                this.setAttribute("data-selected", "0");
                removeSelectedItem(this.getAttribute("data-id"));
            } else {
                this.setAttribute("data-selected", "1");
                addSelectedItem(this.getAttribute("data-id"));
            }
            updateSelectionContent();
        }
    });

    //Edit Name
    let editBtn = tagBox.querySelector(".dropdown-option.edit");
    if (editBtn) {
        editBtn.addEventListener("click", function () {
            let nameEl = tagBox.querySelector(".tag-name");
            set_up_popin(
                tagBox.dataset.id,
                nameEl?.dataset.rawname || "",
                nameEl?.innerHTML || "",
            );
            rename_tag_open();
        });
    }

    //Delete Tag
    let deleteBtn = tagBox.querySelector(".dropdown-option.delete");
    if (deleteBtn) {
        deleteBtn.addEventListener("click", function () {
            pwgConfirm({
                title: str_delete.replace("%s", tagBox.querySelector(".tag-name")?.innerHTML || ""),
                buttons: {
                    confirm: {
                        text: str_yes_delete_confirmation,
                        btnClass: "btn-red",
                        action: function () {
                            removeTag(
                                tagBox.dataset.id,
                                tagBox.querySelector(".tag-name")?.innerHTML || "",
                            );
                        },
                    },
                    cancel: {
                        text: str_no_delete_confirmation,
                    },
                },
            });
        });
    }

    //Duplicate Tag
    let dupBtn = tagBox.querySelector(".dropdown-option.duplicate");
    if (dupBtn) {
        dupBtn.addEventListener("click", function () {
            let nameEl = tagBox.querySelector(".tag-name");
            duplicateTag(
                tagBox.dataset.id,
                nameEl?.dataset.rawname || "",
            ).then((data) => {
                showMessage(str_tag_created.replace("%s", data.result.name));
            });
        });
    }
}

function set_up_popin(id, tagRawName, tagName) {
    let inputEl = document.querySelector(".RenameTagPopInContainer .tag-property-input");
    if (inputEl) inputEl.id = id;

    let titleEl = document.querySelector(".AddIconTitle span");
    if (titleEl) titleEl.innerHTML = str_tag_rename.replace("%s", tagName);

    document.querySelectorAll(".ClosePopIn, .TagCancel").forEach(el => {
        el.removeEventListener("click", arguments.callee);
        el.addEventListener("click", function () {
            rename_tag_close();
        });
    });

    let submitBtn = document.querySelector(".TagSubmit");
    if (submitBtn) submitBtn.innerHTML = str_yes_rename_confirmation;

    if (inputEl) inputEl.value = tagRawName;
}

function rename_tag_close() {
    let el = document.getElementById("RenameTag");
    if (el) el.style.display = 'none';
}

function rename_tag_open() {
    let el = document.getElementById("RenameTag");
    if (el) el.style.display = '';
    let input = document.querySelector(".tag-property-input");
    if (input) input.focus();
}

function removeTag(id, name) {
    fetch("ws.php?format=json&method=pwg.tags.delete", {
        method: "POST",
        body: new URLSearchParams({
            tag_id: id,
            pwg_token: pwg_token,
        }),
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
    })
    .then(response => response.text())
    .then(raw_data => {
        data = JSON.parse(raw_data);
        if (data.stat === "ok") {
            let el = document.querySelector(".tag-box[data-id=\"" + id + "\"]");
            if (el) el.remove();
            //Update data
            dataTags = dataTags.filter((tag) => tag.id != id);
            showMessage(str_tag_deleted.replace("%s", name));
            updateBadge();
            updateSearchInfo();
            updatePaginationMenu();
        } else {
            throw new Error("A problem has occurred");
        }
    });
}

function renameTag(id, new_name) {
    return new Promise((resolve, reject) => {
        fetch("ws.php?format=json&method=pwg.tags.rename", {
            method: "POST",
            body: new URLSearchParams({
                tag_id: id,
                new_name: new_name,
                pwg_token: pwg_token,
            }),
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
        })
        .then(response => response.text())
        .then(raw_data => {
            data = JSON.parse(raw_data);
            console.log(data);
            if (data.stat === "ok") {
                document.querySelectorAll(".tag-box[data-id=\"" + id + "\"] p, .tag-box[data-id=\"" + id + "\"] .tag-dropdown-header b").forEach(el => {
                    el.innerHTML = data.result.name;
                });
                let editableEl = document.querySelector(".tag-box[data-id=\"" + id + "\"] .tag-name-editable");
                if (editableEl) editableEl.value = data.result.name;
                let u_view = "index.php?/tags/" + id + "-" + data.result.url_name;
                let viewLink = document.querySelector(".dropdown-option.view");
                if (viewLink) viewLink.href = u_view;

                //Update the data
                index = dataTags.findIndex((tag) => tag.id == id);
                dataTags[index].name = data.result.name;
                dataTags[index].url_name = data.result.url_name;

                resolve(data);
            } else {
                reject(str_already_exist.replace("%s", new_name));
            }
        })
        .catch(error => reject(error.statusText || error));
    });
}

function duplicateTag(id, name) {
    return new Promise((resolve, reject) => {
        copy_name = name + str_copy;

        let name_exist = function (name) {
            exist = false;
            document.querySelectorAll(".tag-box .tag-name").forEach(el => {
                if (el.innerHTML === name) exist = true;
            });
            return exist;
        };

        let i = 1;
        while (name_exist(copy_name)) {
            copy_name = name + str_other_copy.replace("%s", i++);
        }

        fetch("ws.php?format=json&method=pwg.tags.duplicate", {
            method: "POST",
            body: new URLSearchParams({
                tag_id: id,
                copy_name: copy_name,
                pwg_token: pwg_token,
            }),
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
        })
        .then(response => response.text())
        .then(raw_data => {
            data = JSON.parse(raw_data);
            if (data.stat === "ok") {
                newTag = createTagBox(
                    data.result.id,
                    data.result.name,
                    data.result.url_name,
                    data.result.count,
                );
                let afterEl = document.querySelector(".tag-box[data-id=\"" + id + "\"]");
                if (afterEl) afterEl.insertAdjacentElement("afterend", newTag);
                setupTagbox(newTag);

                //Update Data
                index = dataTags.findIndex((tag) => tag.id == id);
                dataTags.splice(index + 1, 0, {
                    name: data.result.name,
                    id: data.result.id,
                    url_name: data.result.url_name,
                    counter: data.result.count,
                });
                updateBadge();
                updateSearchInfo();
                resolve(data);
            }
        })
        .catch(error => reject(error.statusText || error));
    });
}

/*-------
 Selection mode
-------*/
var selected = [];
maxItemDisplayed = 5;

let toggleSelectionEl = document.getElementById("toggleSelectionMode");
if (toggleSelectionEl) {
    toggleSelectionEl.checked = false;
    toggleSelectionEl.addEventListener("click", function () {
        selectionMode(this.checked);
        let tagInfo = document.querySelector(".tag-info");
        if (tagInfo) tagInfo.style.display = 'none';
    });
}

function selectionMode(isSelection) {
    if (isSelection) {
        document.querySelectorAll(".in-selection-mode").forEach(el => el.classList.add("show"));
        document.querySelectorAll(".not-in-selection-mode").forEach(el => el.classList.add("hide"));
        document.querySelector(".tag-container")?.classList.add("selection");
        document.querySelectorAll(".tag-box").forEach(el => el.classList.remove("edit-name"));
    } else {
        document.querySelectorAll(".in-selection-mode").forEach(el => el.classList.remove("show"));
        document.querySelectorAll(".not-in-selection-mode").forEach(el => el.classList.remove("hide"));
        document.querySelector(".tag-container")?.classList.remove("selection");
        document.querySelectorAll(".tag-box").forEach(el => el.setAttribute("data-selected", "0"));
        let selectMsg = document.querySelector(".tag-select-message");
        if (selectMsg && selectMsg.style.display !== 'none') {
            selectMsg.style.display = 'none';
        }
        clearSelection();
    }
}

function clearSelection() {
    selected = [];
    let tagList = document.querySelector(".selection-mode-tag .tag-list");
    if (tagList) tagList.innerHTML = "";
    let otherTags = document.querySelector(".selection-other-tags");
    if (otherTags) otherTags.style.display = 'none';
    updateSelectionContent();
}

function addSelectedItem(id) {
    if (!selected.includes(id)) {
        selected.push(id);

        if (selected.length > maxItemDisplayed) {
            let otherTags = document.querySelector(".selection-other-tags");
            if (otherTags) otherTags.style.display = '';
            let numberDisplayed = document.querySelectorAll(".selection-mode-tag .tag-list div").length;
            if (otherTags) otherTags.innerHTML = str_and_others_tags.replace("%s", selected.length - numberDisplayed);
        } else {
            let otherTags = document.querySelector(".selection-other-tags");
            if (otherTags) otherTags.style.display = 'none';
            if (dataTags.findIndex((tag) => tag.id == id) > -1) {
                createSelectionItem(
                    id,
                    dataTags.find((tag) => tag.id == id).name,
                );
            }
        }
    }
}

function createSelectionItem(id, name) {
    let div = document.createElement("div");
    div.setAttribute("data-id", id);
    div.innerHTML = '<a class="icon-cancel"></a><p>' + name + "</p>";
    let tagList = document.querySelector(".selection-mode-tag .tag-list");
    if (tagList) tagList.prepend(div);

    let closeBtn = div.querySelector("a");
    if (closeBtn) {
        closeBtn.addEventListener("click", function () {
            removeSelectedItem(id);
        });
    }
}

function removeSelectedItem(id) {
    if (selected.findIndex((tag) => tag == id) > -1) {
        selected = selected.filter((tag) => {
            return parseInt(tag) != parseInt(id);
        });

        let tagBox = document.querySelector(".tag-box[data-id=\"" + id + "\"]");
        if (tagBox) tagBox.setAttribute("data-selected", "0");

        let tagListItem = document.querySelector(".selection-mode-tag .tag-list div[data-id=\"" + id + "\"]");
        if (tagListItem) {
            tagListItem.remove();

            if (selected.length >= maxItemDisplayed) {
                let i = 0;
                isNotCreate = true;
                while (i < selected.length && isNotCreate) {
                    if (document.querySelector(".selection-mode-tag .tag-list div[data-id=\"" + selected[i] + "\"]") === null) {
                        isNotCreate = false;
                        indexOfTag = dataTags.findIndex((tag) => tag.id == selected[i]);
                        createSelectionItem(selected[i], dataTags[indexOfTag].name);
                    }
                    i++;
                }
            }
        }

        let numberDisplayed = document.querySelectorAll(".selection-mode-tag .tag-list div").length;
        let otherTags = document.querySelector(".selection-other-tags");
        if (otherTags) {
            otherTags.innerHTML = str_and_others_tags.replace("%s", selected.length - numberDisplayed);
            if (selected.length - numberDisplayed <= 0) {
                otherTags.style.display = 'none';
            }
        }

        //Remove the selection message
        let selectMsg = document.querySelector(".tag-select-message");
        if (selectMsg && selectMsg.style.display !== 'none') {
            selectMsg.style.display = 'none';
        }
    }
}

function updateMergeItems() {
    let mergeChoices = document.getElementById("MergeOptionsChoices");
    if (mergeChoices) {
        mergeChoices.innerHTML = "";
        selected.forEach((id) => {
            let opt = document.createElement("option");
            opt.value = id;
            opt.innerHTML = dataTags.find((tag) => tag.id == id).name;
            mergeChoices.appendChild(opt);
        });
    }
}

mergeOption = false;

function updateSelectionContent() {
    number = selected.length;
    let nothingSelected = document.getElementById("nothing-selected");
    let selectionMode = document.querySelector(".selection-mode-tag");
    let mergeBlock = document.getElementById("MergeOptionsBlock");
    let mergeBtn = document.getElementById("MergeSelectionMode");

    if (number == 0) {
        mergeOption = false;
        if (nothingSelected) nothingSelected.style.display = '';
        if (selectionMode) selectionMode.style.display = 'none';
        if (mergeBlock) mergeBlock.style.display = 'none';
    } else if (number == 1) {
        mergeOption = false;
        if (nothingSelected) nothingSelected.style.display = 'none';
        if (selectionMode) selectionMode.style.display = '';
        if (mergeBlock) mergeBlock.style.display = 'none';
        if (mergeBtn) mergeBtn.classList.add("unavailable");
    } else if (number > 1) {
        if (nothingSelected) nothingSelected.style.display = 'none';
        if (mergeBtn) mergeBtn.classList.remove("unavailable");
        if (mergeOption) {
            if (mergeBlock) mergeBlock.style.display = '';
            if (selectionMode) selectionMode.style.display = 'none';
            updateMergeItems();
        } else {
            if (mergeBlock) mergeBlock.style.display = 'none';
            if (selectionMode) selectionMode.style.display = '';
        }
    }
}

document.getElementById("MergeSelectionMode")?.addEventListener("click", function () {
    mergeOption = true;
    updateSelectionContent();
});

document.getElementById("CancelMerge")?.addEventListener("click", function () {
    mergeOption = false;
    updateSelectionContent();
});

document.getElementById("selectAll")?.addEventListener("click", function () {
    selectAll(tagToDisplay());
    updateSelectionContent();
    if (selected.length < dataTags.length) {
        showSelectMessage(
            str_selection_done.replace("%d", document.querySelectorAll(".tag-box").length),
            str_select_all_tag.replace("%d", dataTags.length),
            function () {
                document.querySelector(".tag-select-message a").innerHTML = "";
                let div = document.querySelector(".tag-select-message div");
                if (div) div.innerHTML = "<i class='icon-spin6 animate-spin'> </i>";
                setTimeout(() => {
                    selectAll(dataTags).then(() => {
                        updateSelectionContent();
                        showSelectMessage(
                            str_tag_selected.replace(/%d/g, selected.length),
                            str_clear_selection,
                            function () {
                                selectNone();
                                let selectMsg = document.querySelector(".tag-select-message");
                                if (selectMsg) selectMsg.style.display = 'none';
                            },
                        );
                    });
                }, 5);
            },
        );
    }
});

function selectAll(data) {
    promises = [];
    data.forEach((tag) => {
        promises.push(
            new Promise((res, rej) => {
                let tagBox = document.querySelector(".tag-box[data-id=\"" + tag.id + "\"]");
                if (tagBox) tagBox.setAttribute("data-selected", "1");
                addSelectedItem(tag.id);
                res();
            }),
        );
    });
    return Promise.all(promises);
}

function showSelectMessage(str1, str2, callback) {
    let selectMsg = document.querySelector(".tag-select-message");
    if (selectMsg && getComputedStyle(selectMsg).display === 'none') {
        selectMsg.style.display = 'flex';
    }

    let msgDiv = document.querySelector(".tag-select-message div");
    if (msgDiv) msgDiv.innerHTML = str1;

    let msgLink = document.querySelector(".tag-select-message a");
    if (msgLink) {
        msgLink.innerHTML = str2;
        msgLink.removeEventListener("click", arguments.callee);
        msgLink.addEventListener("click", callback);
    }
}

document.getElementById("selectNone")?.addEventListener("click", function () {
    let selectMsg = document.querySelector(".tag-select-message");
    if (selectMsg) selectMsg.style.display = 'none';
    selectNone();
});

function selectNone() {
    document.querySelectorAll(".tag-box").forEach(el => el.setAttribute("data-selected", "0"));
    clearSelection();
}

document.getElementById("selectInvert")?.addEventListener("click", function () {
    let selectMsg = document.querySelector(".tag-select-message");
    if (selectMsg) selectMsg.style.display = 'none';
    selectInvert(tagToDisplay());
});

function selectInvert(data) {
    data.forEach((tag) => {
        let tagBox = document.querySelector(".tag-box[data-id=\"" + tag.id + "\"]");
        if (tagBox) {
            if (tagBox.getAttribute("data-selected") == "1") {
                tagBox.setAttribute("data-selected", "0");
                removeSelectedItem(tag.id);
            } else {
                tagBox.setAttribute("data-selected", "1");
                addSelectedItem(tag.id);
            }
        }
    });
    updateSelectionContent();
}

/*-------
 Actions in selection mode
-------*/

//Remove tags
document.getElementById("DeleteSelectionMode")?.addEventListener("click", function () {
    let names = [];
    selected.forEach(function (id) {
        names.push(dataTags.find((tag) => tag.id == id).name);
    });

    pwgConfirm({
        title: str_delete_tags.replace("%s", tagListToString(names)),
        buttons: {
            confirm: {
                text: str_yes_delete_confirmation,
                btnClass: "btn-red",
                action: function () {
                    removeSelectedTags();
                },
            },
            cancel: {
                text: str_no_delete_confirmation,
            },
        },
    });
});

function removeSelectedTags() {
    let names = [];
    selected.forEach(function (id) {
        names.push(dataTags.find((tag) => tag.id == id).name);
    });

    fetch("ws.php?format=json&method=pwg.tags.delete", {
        method: "POST",
        body: new URLSearchParams({
            pwg_token: pwg_token,
            tag_id: selected,
        }),
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
    })
    .then(response => response.text())
    .then(raw_data => {
        raw_data = raw_data.slice(raw_data.search("{"));
        if ((JSON.parse(raw_data).stat = "ok")) {
            selected.forEach(function (id) {
                let el = document.querySelector(".tag-box[data-id=\"" + id + "\"]");
                if (el) el.remove();
            });

            // Update Data
            dataTags = dataTags.filter(
                (tag) => !selected.includes(tag.id),
            );

            clearSelection();
            updatePaginationMenu();
            updateBadge();
            updateSearchInfo();
        }
    });
}

//Merge Tags
document.querySelector(".ConfirmMergeButton")?.addEventListener("click", () => {
    let destSelect = document.getElementById("MergeOptionsChoices");
    if (destSelect) {
        dest_id = destSelect.value;
        mergeGroups(dest_id, selected);
    }
});

function mergeGroups(destination_id, merge_ids) {
    let destNameEl = document.querySelector(".tag-box[data-id=\"" + destination_id + "\"] .tag-name");
    destination_name = destNameEl ? destNameEl.innerHTML : "";
    merge_name = [];

    merge_ids.forEach((id) => {
        let el = document.querySelector(".tag-box[data-id=\"" + id + "\"] .tag-name");
        if (el) merge_name.push(el.innerHTML);
    });

    str_message = str_merged_into
        .replace("%s1", tagListToString(merge_name))
        .replace("%s2", destination_name);

    fetch("ws.php?format=json&method=pwg.tags.merge", {
        method: "POST",
        body: new URLSearchParams({
            pwg_token: pwg_token,
            destination_tag_id: destination_id,
            merge_tag_id: merge_ids,
        }),
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' }
    })
    .then(response => response.text())
    .then(raw_data => {
        raw_data = raw_data.slice(raw_data.search("{"));
        data = JSON.parse(raw_data);
        if (data.stat === "ok") {
            data.result.deleted_tag.forEach((id) => {
                if (data.result.destination_tag != id) {
                    let el = document.querySelector(".tag-box[data-id=\"" + id + "\"]");
                    if (el) el.remove();
                    // Update data
                    dataTags = dataTags.filter(
                        (tag) => id != tag.id,
                    );
                }
            });
            if (data.result.images_in_merged_tag.length > 0) {
                let tagBox = document.querySelector(".tag-box[data-id=\"" + data.result.destination_tag + "\"]");
                if (tagBox) {
                    let views = tagBox.querySelectorAll(".dropdown-option.view, .dropdown-option.manage, .tag-dropdown-header i");
                    views.forEach(el => el.style.display = '');
                    let headerI = tagBox.querySelector(".tag-dropdown-header i");
                    if (headerI) headerI.innerHTML = str_number_photos.replace("%d", data.result.images_in_merged_tag.length);
                }

                // Update data
                index = dataTags.findIndex((tag) => tag.id == data.result.destination_tag);
                dataTags[index].counter = data.result.images_in_merged_tag.length;
            }
            document.querySelectorAll(".tag-box").forEach(el => el.setAttribute("data-selected", "0"));
            clearSelection();
            updatePaginationMenu();
            updateBadge();
            updateSearchInfo();
        }
    });
}

function tagListToString(list) {
    if (list.length > 5) {
        return (
            list.slice(0, 5).join(", ") +
            " " +
            str_and_others_tags.replace("%s", list.length - 5)
        );
    } else {
        return list.join(", ");
    }
}

/*-------
 Filter research
-------*/

var maxShown = 100;
var searchTimeOut;
var delaySearchInput = 300;

document.querySelector("#search-tag .search-input")?.addEventListener("input", function () {
    actualPage = 1;

    clearTimeout(searchTimeOut);
    searchTimeOut = setTimeout(() => {
        updatePaginationMenu();
        if (dataTags.filter(isDataSearched).length == 0) {
            document.querySelector(".emptyResearch").style.display = '';
        } else {
            document.querySelector(".emptyResearch").style.display = 'none';
        }
    }, delaySearchInput);
});

function isSearched(tagBox, stringSearch) {
    let name = tagBox.querySelector("p")?.textContent.toLowerCase() || "";
    if (name.startsWith(stringSearch.toLowerCase())) {
        return true;
    } else {
        return false;
    }
}

function isDataSearched(tagObj) {
    let name = tagObj.raw_name.toLowerCase();
    let stringSearch = document.querySelector("#search-tag .search-input")?.value || "";
    if (name.includes(stringSearch.toLowerCase())) {
        return true;
    } else {
        return false;
    }
}

/*-------
 Show Info
-------*/
function showError(message) {
    let errorMsg = document.querySelector(".info-error p");
    if (errorMsg) errorMsg.innerHTML = message;
    let errorBox = document.querySelector(".info-error");
    if (errorBox) errorBox.setAttribute("title", message);
    let infoEl = document.querySelector(".info-info");
    if (infoEl) infoEl.style.display = 'none';
    if (errorBox) errorBox.style.display = "flex";
}

function showMessage(message) {
    let msgEl = document.querySelector(".info-message p");
    if (msgEl) msgEl.innerHTML = message;
    let msgBox = document.querySelector(".info-message");
    if (msgBox) msgBox.setAttribute("title", message);
    let infoEl = document.querySelector(".info-info");
    if (infoEl) infoEl.style.display = 'none';
    if (msgBox) msgBox.style.display = "flex";
}

/*-------
 Pagination
-------*/
var per_page = document.querySelector(".tag-container")?.dataset.per_page || 20;
per_page = parseInt(per_page);
var pageItem = '<a data-page="%d">%d</a>';
var pageEllipsis = "<span>...</span>";
var promisePending = false;
var delay = 100;
var updateAsk = false;

var actualPage = 1;

//Avoid 2 update at the same time
function askUpdatePage() {
    if (!promisePending) {
        promisePending = true;
        updatePage().then(promiseFinish);
    } else {
        updateAsk = true;
    }
}

function promiseFinish() {
    promisePending = false;
    if (updateAsk) {
        updateAsk = false;
        askUpdatePage();
    }
}

function updatePaginationMenu() {
    let container = document.querySelector(".pagination-item-container");
    if (container) container.innerHTML = "";

    actualPage = Math.min(actualPage, getNumberPages());

    let paginationContainer = document.querySelector(".pagination-container");
    if (getNumberPages() > 1) {
        if (paginationContainer) paginationContainer.style.display = '';
        createPaginationMenu();
    } else {
        if (paginationContainer) paginationContainer.style.display = 'none';
    }

    updateArrows();
    askUpdatePage();

    //Remove the selection message
    let selectMsg = document.querySelector(".tag-select-message");
    if (selectMsg && selectMsg.style.display !== 'none') {
        selectMsg.style.display = 'none';
    }
}

function createPaginationMenu() {
    nbPage = getNumberPages();

    appendPaginationItem(1);

    if (actualPage > 2) {
        appendPaginationItem();
    }

    if (actualPage != 1 && actualPage != nbPage) {
        appendPaginationItem(actualPage);
    }

    if (actualPage < nbPage - 1) {
        appendPaginationItem();
    }

    appendPaginationItem(nbPage);
}

function appendPaginationItem(page = null) {
    if (page != null) {
        let newItem = document.createElement("a");
        newItem.setAttribute("data-page", page);
        newItem.innerHTML = page;
        let container = document.querySelector(".pagination-item-container");
        if (container) container.appendChild(newItem);

        if (actualPage == page) {
            newItem.classList.add("actual");
        }
        newItem.addEventListener("click", () => {
            actualPage = parseInt(newItem.getAttribute("data-page"));
            updatePaginationMenu();
        });
    } else {
        let ellipsis = document.createElement("span");
        ellipsis.innerHTML = "...";
        let container = document.querySelector(".pagination-item-container");
        if (container) container.appendChild(ellipsis);
    }
}

function updateArrows() {
    let leftArrow = document.querySelector(".pagination-arrow.left");
    let rightArrow = document.querySelector(".pagination-arrow.right");

    if (actualPage == 1) {
        if (leftArrow) leftArrow.classList.add("unavailable");
    } else {
        if (leftArrow) leftArrow.classList.remove("unavailable");
    }

    if (actualPage == getNumberPages()) {
        if (rightArrow) rightArrow.classList.add("unavailable");
    } else {
        if (rightArrow) rightArrow.classList.remove("unavailable");
    }
}

function getNumberPages() {
    dataVisible = dataTags.filter(isDataSearched).length;
    return Math.floor((dataVisible - 1) / per_page) + 1;
}

function movePage(toRight = true) {
    document.querySelectorAll(".tag-box").forEach(el => el.classList.remove("edit-name"));
    if (toRight) {
        if (actualPage < getNumberPages()) {
            actualPage++;
            updatePaginationMenu();
        }
    } else {
        if (actualPage > 1) {
            actualPage--;
            updatePaginationMenu();
        }
    }
}

function updatePage() {
    return new Promise((resolve, reject) => {
        newPage = actualPage;
        dataToDisplay = tagToDisplay();
        tagBoxes = document.querySelectorAll(".tag-box");
        let pageLoad = document.querySelector(".pageLoad");
        if (pageLoad) pageLoad.style.display = '';

        // Fade out tags
        let tagContainer = document.querySelector(".tag-container");
        if (tagContainer) {
            tagContainer.style.opacity = "0";
            tagContainer.style.transition = "opacity 0.5s";
        }

        setTimeout(() => {
            let displayTags = new Promise((res, rej) => {
                boxToRecycle = Math.min(
                    dataToDisplay.length,
                    tagBoxes.length,
                );

                for (let i = 0; i < boxToRecycle; i++) {
                    let tag = dataToDisplay[i];
                    recycleTagBox(
                        tagBoxes[i],
                        tag.id,
                        tag.name,
                        tag.url_name,
                        tag.counter || tag.count,
                        tag.raw_name,
                    );
                }

                if (dataToDisplay.length < tagBoxes.length) {
                    for (let j = boxToRecycle; j < tagBoxes.length; j++) {
                        tagBoxes[j].remove();
                    }
                } else if (dataToDisplay.length > tagBoxes.length) {
                    for (let j = boxToRecycle; j < dataToDisplay.length; j++) {
                        let tag = dataToDisplay[j];
                        newTag = createTagBox(
                            tag.id,
                            tag.name,
                            tag.url_name,
                            tag.counter || tag.count,
                            tag.raw_name,
                        );
                        newTag.style.opacity = "0";
                        if (tagContainer) tagContainer.appendChild(newTag);
                        setupTagbox(newTag);
                    }
                }

                //Select selected tags
                selected.forEach((id) => {
                    let el = document.querySelector(".tag-box[data-id=\"" + id + "\"]");
                    if (el) el.setAttribute("data-selected", "1");
                });

                res();
            });

            displayTags.then(() => {
                if (pageLoad) pageLoad.style.display = 'none';
                if (tagContainer) {
                    tagContainer.style.opacity = "1";
                }
                let tagPag = document.querySelector(".tag-pagination");
                if (getNumberPages() > 1 && tagPag) {
                    tagPag.style.opacity = "1";
                }
                updateSearchInfo();
                resolve();
            });
        }, 500);
    });
}

function tagToDisplay() {
    return dataTags
        .filter(isDataSearched)
        .slice((actualPage - 1) * per_page, actualPage * per_page);
}

document.querySelector(".pagination-arrow.right")?.addEventListener("click", () => {
    movePage();
});

document.querySelector(".pagination-arrow.left")?.addEventListener("click", () => {
    movePage(false);
});

if (getNumberPages() > 1) {
    let paginationContainer = document.querySelector(".pagination-container");
    if (paginationContainer) paginationContainer.style.display = '';
    createPaginationMenu();
    updateArrows();
} else {
    let paginationContainer = document.querySelector(".pagination-container");
    if (paginationContainer) paginationContainer.style.display = 'none';
}

document.querySelectorAll(".pagination-per-page a").forEach(el => {
    el.addEventListener("click", function () {
        per_page = parseInt(this.innerHTML);
        updatePaginationMenu();
        document.querySelectorAll(".pagination-per-page .selected").forEach(el => el.classList.remove("selected"));
        this.classList.add("selected");
        document.cookie = 'pwg_tags_per_page=' + per_page + '; path=/; SameSite=Lax';
    });
});

function updateSearchInfo() {
    let searchInput = document.querySelector(".search-input");
    if (searchInput && searchInput.value != "") {
        let number = dataTags.filter(isDataSearched).length;
        let searchInfo = document.querySelector(".search-info");
        if (searchInfo) {
            if (number > 1) {
                searchInfo.innerHTML = str_tags_found.replace("%d", number);
            } else {
                searchInfo.innerHTML = str_tag_found.replace("%d", number);
            }
        }
    } else {
        let searchInfo = document.querySelector(".search-info");
        if (searchInfo) searchInfo.innerHTML = "";
    }
}

document.addEventListener('DOMContentLoaded', function () {
    function setPagination() {
        var match = document.cookie.match(/(?:^|;)\s*pwg_tags_per_page=([^;]*)/);
        var saved = match ? match[1] : null;
        if (saved) {
            document.querySelectorAll(".pagination-per-page .selected").forEach(el => el.classList.remove("selected"));
            var el = document.getElementById(saved);
            if (el) el.click();
        }
    }

    setPagination();
});
