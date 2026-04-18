import { initModule } from './moduleInit.js';
import { TemporaryState, sprintf } from './common.js';
import { pwgConfirm } from './pwgConfirm.js';
import TomSelect from 'tom-select';
import noUiSlider from 'nouislider';
import tippy from 'tippy.js';

const color_icons = [
    "icon-red",
    "icon-blue",
    "icon-yellow",
    "icon-purple",
    "icon-green",
];
const status_arr = ["webmaster", "admin", "normal", "generic", "guest"];
const level_arr = ["0", "1", "2", "4", "8"];
let current_users = [];
let guest_id = 0;
let guest_user = {};
let connected_user = 0;
let groups_arr = [];
let nb_days = "";
let nb_photos = "";
let nb_photos_per_page = "";
let last_user_index = -1;
let last_user_id = -1;
let pwg_token = "";
let selection = [];
let first_update = true;
let total_users = 0;
let groupSelectize = null;
let groupGuestSelectize = null;

export function init(cfg) {
    // Assign config from cfg
    guest_id = cfg.guestId;
    connected_user = cfg.connectedUser;
    nb_days = cfg.nbDays;
    nb_photos = cfg.nbPhotos;
    nb_photos_per_page = cfg.nbPhotosPerPage;
    pwg_token = cfg.pwgToken;

    // Compute derived values
    const groupsArrId = cfg.groupsArrId;
    const groupsArrName = cfg.groupsArrName;
    groups_arr = groupsArrId.map((elem, index) => [elem, groupsArrName[index]]);

    // Parse register dates
    const registerDates = cfg.registerDatesStr.split(',');

    /*----------------
    Escape of pop-in
    ----------------*/

    //get out of pop in via escape key
    document.addEventListener("keydown", function (e) {
    if (e.keyCode === 27) {
        // ESC button
        const userList = document.getElementById("UserList");
        if (userList) userList.style.display = 'none';
    }
});

    //get out of pop in via clicking outside pop in
    //$(document).on('click', function (e) {
    //    console.log($(e.target));
    //    if ($(e.target) && $(e.target).closest(".UserListPopInContainer").length === 0) {
    //        $("#UserList").fadeOut();
    //    }
    //});

    /*----------------
    Group Selectize
    ----------------*/

    document.querySelectorAll("[data-selectize=groups]").forEach(function (el) {
        new TomSelect(el, {
            valueField: "value",
            labelField: "label",
            searchField: ["label"],
            plugins: ["remove_button"],
        });
    });

    var _groupEls = document.querySelectorAll("[data-selectize=groups]");
    groupSelectize = _groupEls[0] ? _groupEls[0].tomselect : null;
    groupGuestSelectize = _groupEls[1] ? _groupEls[1].tomselect : null;

/*-----------------
OnClick functions
-----------------*/
function open_user_list() {
    hide_temporary_messages();
    const userList = document.getElementById("UserList");
    if (userList) userList.style.display = 'flex';
}

function close_user_list() {
    hide_temporary_messages();
    const userList = document.getElementById("UserList");
    if (userList) userList.style.display = 'none';
}

function open_guest_user_list() {
    hide_temporary_messages();
    const guestUserList = document.getElementById("GuestUserList");
    if (guestUserList) guestUserList.style.display = 'flex';
}

function close_guest_user_list() {
    hide_temporary_messages();
    const guestUserList = document.getElementById("GuestUserList");
    if (guestUserList) guestUserList.style.display = 'none';
}

    function isSelectionMode() {
        const toggleSelectionMode = document.getElementById("toggleSelectionMode");
        return toggleSelectionMode ? toggleSelectionMode.checked : false;
    }

    tippy('.user-property-register', { maxWidth: 300, delay: 0, placement: 'top' });
    tippy('.user-property-last-visit', { maxWidth: 300, delay: 0, placement: 'top' });

    const advFilterLevelSelects = document.querySelectorAll(".advanced-filter-level select option");
    if (advFilterLevelSelects.length > 1) {
        advFilterLevelSelects[1].remove();
    }

    document.querySelectorAll(".edit-password").forEach(function (el) {
        el.addEventListener("click", function () {
            document.querySelectorAll(".user-property-password").forEach(el => el.style.display = 'none');
            document.querySelectorAll(".user-property-password-change").forEach(el => {
                el.style.display = 'flex';
            });
        });
    });

    document.querySelectorAll(".edit-password-cancel").forEach(function (el) {
        el.addEventListener("click", function () {
            document.querySelectorAll(".user-property-password").forEach(el => el.style.display = '');
            document.querySelectorAll(".user-property-password-change").forEach(el => el.style.display = 'none');
        });
    });

    document.querySelectorAll(".edit-username").forEach(function (el) {
        el.addEventListener("click", function () {
            document.querySelectorAll(".user-property-username").forEach(el => el.style.display = 'none');
            document.querySelectorAll(".user-property-username-change").forEach(el => {
                el.style.display = 'flex';
            });
        });
    });

    document.querySelectorAll(".edit-username-cancel").forEach(function (el) {
        el.addEventListener("click", function () {
            document.querySelectorAll(".user-property-username").forEach(el => el.style.display = '');
            document.querySelectorAll(".user-property-username-change").forEach(el => el.style.display = 'none');
        });
    });

    document.querySelectorAll("#UserList .close-update-button").forEach(el => {
        el.addEventListener("click", close_user_list);
    });
    document.querySelectorAll(".CloseUserList").forEach(el => {
        el.addEventListener("click", close_user_list);
    });

    const toggleSelectionMode = document.getElementById("toggleSelectionMode");
    if (toggleSelectionMode) {
        toggleSelectionMode.checked = false;
        toggleSelectionMode.addEventListener("click", function () {
            let isSelection = this.checked;
            selectionMode(isSelection);
        });
    }

    document.querySelectorAll(".edit-guest-user-button").forEach(el => {
        el.addEventListener("click", open_guest_user_list);
    });
    document.querySelectorAll(".CloseGuestUserList").forEach(el => {
        el.addEventListener("click", close_guest_user_list);
    });
    document.querySelectorAll("#GuestUserList .close-update-button").forEach(el => {
        el.addEventListener("click", close_guest_user_list);
    });

    const showPassword = document.getElementById("show_password");
    if (showPassword) {
        showPassword.addEventListener("click", function () {
            if (this.classList.contains("icon-eye")) {
                this.classList.remove("icon-eye");
                this.classList.add("icon-eye-off");
                const addUserPassword = document.getElementById("AddUserPassword");
                if (addUserPassword) addUserPassword.type = "text";
            } else {
                this.classList.remove("icon-eye-off");
                this.classList.add("icon-eye");
                const addUserPassword = document.getElementById("AddUserPassword");
                if (addUserPassword) addUserPassword.type = "password";
            }
        });
    }
    /* Action */
    document.querySelectorAll("[id^=action_]").forEach(el => {
        el.style.display = 'none';
    });

    document.querySelectorAll("select[name=selectAction]").forEach(function (el) {
        el.addEventListener("change", function () {
            document.querySelectorAll("#applyActionBlock .infos").forEach(el => {
                el.style.display = 'none';
            });
            document.querySelectorAll("[id^=action_]").forEach(el => {
                el.style.display = 'none';
            });
            const actionEl = document.getElementById("action_" + this.value);
            if (actionEl) actionEl.style.display = '';
            const applyActionBlock = document.getElementById("applyActionBlock");
            if (applyActionBlock) {
                applyActionBlock.style.display = this.value != -1 ? '' : 'none';
            }
        });
    });

    document.querySelectorAll(".yes_no_radio .user-list-checkbox").forEach(function (el) {
        el.addEventListener("click", function () {
            if (this.getAttribute("data-selected") !== "1") {
                this.setAttribute("data-selected", "1");
                this.parentElement.querySelectorAll(".user-list-checkbox").forEach(sibling => {
                    if (sibling !== this) sibling.setAttribute("data-selected", "0");
                });
            }
        });
    });

    document.querySelectorAll(".AddUserGenPassword").forEach(el => {
        el.addEventListener("click", gen_password);
    });
    document.querySelectorAll(".AddUserSubmit").forEach(el => {
        el.addEventListener("click", add_user);
    });
    document.querySelectorAll(".AddUserCancel").forEach(el => {
        el.addEventListener("click", add_user_close);
    });
    document.querySelectorAll(".CloseAddUser").forEach(el => {
        el.addEventListener("click", add_user_close);
    });

    //open add user pop in
    document.querySelectorAll(".add-user-button").forEach(el => {
        el.addEventListener("click", add_user_open);
    });

    /* Select */

    const selectSetBtn = document.getElementById("selectSet");
    if (selectSetBtn) {
        selectSetBtn.addEventListener("click", function () {
            select_whole_set();
            return false;
        });
    }

    const selectAllPageBtn = document.getElementById("selectAllPage");
    if (selectAllPageBtn) {
        selectAllPageBtn.addEventListener("click", function () {
            let selection_ids = selection.map((x) => x.id);
            for (let i = 0; i < current_users.length; i++) {
                if (!selection_ids.includes(current_users[i].id)) {
                    selection.push({
                        id: current_users[i].id,
                        username: current_users[i].username,
                    });
                }
            }
            update_selection_content();
            return false;
        });
    }

    const selectNoneBtn = document.getElementById("selectNone");
    if (selectNoneBtn) {
        selectNoneBtn.addEventListener("click", function () {
            selection = [];
            update_selection_content();
            return false;
        });
    }

    const selectInvertBtn = document.getElementById("selectInvert");
    if (selectInvertBtn) {
        selectInvertBtn.addEventListener("click", function () {
            let selection_ids = selection.map((x) => x.id);
            for (let i = 0; i < current_users.length; i++) {
                if (selection_ids.includes(current_users[i].id)) {
                    selection.splice(
                        selection.findIndex((x) => x.id == current_users[i].id),
                        1,
                    );
                } else {
                    selection.push({
                        id: current_users[i].id,
                        username: current_users[i].username,
                    });
                }
            }
            update_selection_content();
            return false;
        });
    }

    const permitActionSelectAction = document.querySelector("#permitActionUserList select[name=selectAction]");
    if (permitActionSelectAction) permitActionSelectAction.value = "-1";

    document.querySelectorAll(".advanced-filter-btn").forEach(el => {
        el.addEventListener("click", advanced_filter_button_click);
    });
    document.querySelectorAll(".advanced-filter span.icon-cancel").forEach(el => {
        el.addEventListener("click", advanced_filter_hide);
    });
    document.querySelectorAll(".advanced-filter-select").forEach(el => {
        el.addEventListener("change", update_user_list);
    });
    const userSearch = document.getElementById("user_search");
    if (userSearch) {
        userSearch.addEventListener("input", update_user_list);
    }

    /*View manager*/

    const displayCompact = document.getElementById("displayCompact");
    const displayLine = document.getElementById("displayLine");
    const displayTile = document.getElementById("displayTile");

    if (displayCompact && displayCompact.checked) {
        setDisplayCompact();
    }

    if (displayLine && displayLine.checked) {
        setDisplayLine();
    }

    if (displayTile && displayTile.checked) {
        setDisplayTile();
    }

    if (displayCompact) {
        displayCompact.addEventListener("change", function () {
            setDisplayCompact();

            const addAlbums = document.querySelectorAll(".addAlbum");
            addAlbums.forEach(el => {
                if (el.classList.contains("input-mode")) {
                    el.querySelectorAll("p").forEach(p => p.style.display = 'none');
                }
            });
            set_view_selector("compact");
        });
    }

    if (displayLine) {
        displayLine.addEventListener("change", function () {
            setDisplayLine();

            const addAlbums = document.querySelectorAll(".addAlbum");
            addAlbums.forEach(el => {
                if (el.classList.contains("input-mode")) {
                    el.querySelectorAll("p").forEach(p => p.style.display = 'none');
                }
            });
            set_view_selector("line");
        });
    }

    if (displayTile) {
        displayTile.addEventListener("change", function () {
            setDisplayTile();

            const addAlbums = document.querySelectorAll(".addAlbum");
            addAlbums.forEach(el => {
                if (el.classList.contains("input-mode")) {
                    el.querySelectorAll("p").forEach(p => p.style.display = '');
                }
            });
            set_view_selector("tile");
        });
    }

    /* Pagination */

    const paginationPages = ["5", "10", "25", "50"];
    paginationPages.forEach(page => {
        const el = document.getElementById("pagination-per-page-" + page);
        if (el) {
            if (page === pagination) {
                el.classList.add("selected-pagination");
            } else {
                el.classList.remove("selected-pagination");
            }
        }
    });

    const paginationBtn = document.getElementById("pagination-per-page-" + pagination);
    if (paginationBtn) {
        paginationBtn.dispatchEvent(new Event("click", { bubbles: true }));
    }

    if (has_group) {
        advanced_filter_button_click();
        const filterGroup = document.querySelector("select[name='filter_group']");
        if (filterGroup) filterGroup.value = has_group;
        update_user_list();
    }

    document.querySelectorAll(".search-cancel").forEach(el => {
        el.addEventListener("click", function () {
            document.querySelectorAll(".search-input").forEach(input => {
                input.value = "";
                input.dispatchEvent(new Event("input", { bubbles: true }));
            });
        });
    });

    document.querySelectorAll(".search-input").forEach(el => {
        el.addEventListener("input", function () {
            if (this.value == "") {
                document.querySelectorAll(".search-cancel").forEach(cancel => {
                    cancel.style.display = 'none';
                });
            } else {
                document.querySelectorAll(".search-cancel").forEach(cancel => {
                    cancel.style.display = '';
                });
            }
        });
    });

    function set_view_selector(view_type) {
    const params = new URLSearchParams({
        param: "user-manager-view",
        value: view_type,
    });
    fetch("ws.php?format=json&method=pwg.users.preferences.set", {
        method: "POST",
        body: params,
    }).catch(err => console.error("Error setting view selector:", err));
}

function setDisplayTile() {
    document.querySelectorAll(".user-container-wrapper").forEach(el => {
        el.classList.remove("compactView", "lineView");
        el.classList.add("tileView");
    });
    document.querySelectorAll(".user-header-col").forEach(el => {
        el.classList.add("hide");
    });
}

function setDisplayLine() {
    document.querySelectorAll(".user-container-wrapper").forEach(el => {
        el.classList.remove("tileView", "compactView");
        el.classList.add("lineView");
    });
    document.querySelectorAll(".user-header-col").forEach(el => {
        el.classList.remove("hide");
    });
}

function setDisplayCompact() {
    document.querySelectorAll(".user-container-wrapper").forEach(el => {
        el.classList.remove("tileView", "lineView");
        el.classList.add("compactView");
    });
    document.querySelectorAll(".user-header-col").forEach(el => {
        el.classList.add("hide");
    });

    if (per_page < 10) {
        per_page = 10;
        update_pagination_menu();
        update_user_list();

        const pages = ["5", "10", "25", "50"];
        pages.forEach(page => {
            const el = document.getElementById("pagination-per-page-" + page);
            if (el) {
                if (page === "10") {
                    el.classList.add("selected-pagination");
                } else {
                    el.classList.remove("selected-pagination");
                }
            }
        });
    }
}

/*----------------
Checkboxes
----------------*/

function checkbox_change() {
    if (this.getAttribute("data-selected") == "1") {
        this.querySelectorAll("i").forEach(el => el.style.display = 'none');
    } else {
        this.querySelectorAll("i").forEach(el => el.style.display = '');
    }
}

function checkbox_click() {
    if (this.getAttribute("data-selected") == "1") {
        this.setAttribute("data-selected", "0");
        this.querySelectorAll("i").forEach(el => el.style.display = 'none');
    } else {
        this.setAttribute("data-selected", "1");
        this.querySelectorAll("i").forEach(el => el.style.display = '');
    }
}

document.querySelectorAll(".user-list-checkbox").forEach(el => {
    el.removeEventListener("change", checkbox_change);
    el.removeEventListener("click", checkbox_click);
    el.addEventListener("change", checkbox_change);
    el.addEventListener("click", checkbox_click);
});

/* ---------------
User edit sliders 
----------------*/
const nb_image_page_values = [
    1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21,
    22, 23, 24, 25, 26, 27, 28, 29, 30, 35, 40, 45, 50, 60, 70, 80, 90, 100,
    200, 300, 500, 999,
];
const recent_period_values = [
    0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20,
    25, 30, 40, 50, 60, 80, 99,
];
const recent_period_init = 0;
//const recent_period_init = getSliderKeyFromValue($('.period-select-bar input[name=recent_period]').val(), recent_period_values);
const nb_image_page_init = 0;
//const nb_image_page_init = getSliderKeyFromValue($('.photos-select-bar input[name=nb_image_page]').val(), nb_image_page_values);

/**
 * find the key from a value in the startStopValues array
 */
function getSliderKeyFromValue(value, values) {
    for (var key in values) {
        if (values[key] >= value) {
            return key;
        }
    }
    return 0;
}

function getNbImagePageInfoFromIdx(idx) {
    return sprintf(nb_photos, nb_image_page_values[idx]);
}

function getRecentPeriodInfoFromIdx(idx) {
    return sprintf(nb_days, recent_period_values[idx]);
    //return recent_period_values[idx].toString();
}

/* Photos bar slider */
(function () {
    function makePhotosSlider(scope) {
        var el = document.querySelector(scope + " .photos-select-bar .slider-bar-container");
        if (!el) return;
        noUiSlider.create(el, {
            start: [nb_image_page_init],
            range: { min: 0, max: nb_image_page_values.length - 1 },
            step: 1,
            connect: [true, false],
        });
        el.noUiSlider.on("slide", function (values) {
            var val = Math.round(parseFloat(values[0]));
            const infos = document.querySelector(scope + " .photos-select-bar .nb-img-page-infos");
            if (infos) infos.innerHTML = getNbImagePageInfoFromIdx(val);
        });
        el.noUiSlider.on("change", function (values) {
            var val = Math.round(parseFloat(values[0]));
            const infos = document.querySelector(scope + " .photos-select-bar .nb-img-page-infos");
            if (infos) infos.innerHTML = getNbImagePageInfoFromIdx(val);
            const input = document.querySelector(scope + " .photos-select-bar input[name=nb_image_page]");
            if (input) {
                input.value = nb_image_page_values[val];
                input.dispatchEvent(new Event("change", { bubbles: true }));
            }
        });
    }

    makePhotosSlider("#UserList");
    makePhotosSlider("#GuestUserList");

    const permitActionPhotosInfo = document.querySelector("#permitActionUserList .photos-select-bar .nb-img-page-infos");
    if (permitActionPhotosInfo) {
        permitActionPhotosInfo.innerHTML = getNbImagePageInfoFromIdx(0);
    }
    makePhotosSlider("#permitActionUserList");
}());

/* recent_period slider */
(function () {
    function makePeriodSlider(scope) {
        var el = document.querySelector(scope + " .period-select-bar .slider-bar-container");
        if (!el) return;
        noUiSlider.create(el, {
            start: [recent_period_init],
            range: { min: 0, max: recent_period_values.length - 1 },
            step: 1,
            connect: [true, false],
        });
        el.noUiSlider.on("slide", function (values) {
            var val = Math.round(parseFloat(values[0]));
            const infos = document.querySelector(scope + " .period-select-bar .recent_period_infos");
            if (infos) infos.innerHTML = getRecentPeriodInfoFromIdx(val);
        });
        el.noUiSlider.on("change", function (values) {
            var val = Math.round(parseFloat(values[0]));
            const infos = document.querySelector(scope + " .period-select-bar .recent_period_infos");
            if (infos) infos.innerHTML = getRecentPeriodInfoFromIdx(val);
            const input = document.querySelector(scope + " .period-select-bar input[name=recent_period]");
            if (input) {
                input.value = recent_period_values[val];
                input.dispatchEvent(new Event("change", { bubbles: true }));
            }
        });
    }

    makePeriodSlider("#UserList");
    makePeriodSlider("#GuestUserList");
    makePeriodSlider("#permitActionUserList");

    var permitPhotosSlider = document.querySelector("#permitActionUserList .photos-select-bar .slider-bar-container");
    if (permitPhotosSlider && permitPhotosSlider.noUiSlider) permitPhotosSlider.noUiSlider.set(0);

    let period_info = getRecentPeriodInfoFromIdx(0);
    const permitActionPeriodInfo = document.querySelector("#permitActionUserList .period-select-bar .recent_period_infos");
    if (permitActionPeriodInfo) {
        permitActionPeriodInfo.innerHTML = period_info;
    }
}());

/* -----------
Pagination
------------*/

let per_page = 5;
let actual_page = 1;
let max_page = 1;
let nb_filtered_users = 0;
const page_ellipsis = "<span>...</span>";
const page_item = '<a data-page="%d">%d</a>';
let promise_pending = false;
let update_ask = false;

function move_to_page(page) {
    if (page < 1 || page > max_page) return;
    actual_page = page;
    update_pagination_menu();
    update_user_list();
}

document.querySelectorAll(".pagination-arrow.right").forEach(el => {
    el.addEventListener("click", () => {
        move_to_page(actual_page + 1);
    });
});

document.querySelectorAll(".pagination-arrow.left").forEach(el => {
    el.addEventListener("click", () => {
        move_to_page(actual_page - 1);
    });
});

document.querySelectorAll(".pagination-per-page a").forEach(function (el) {
    el.addEventListener("click", function () {
        const value = parseInt(this.innerHTML);
        const params = new URLSearchParams({
            param: "user-manager-pagination",
            value: value,
            is_json: false,
        });
        fetch("ws.php?format=json&method=pwg.users.preferences.set", {
            method: "POST",
            body: params,
        }).catch(err => console.error("Error setting pagination:", err));

        per_page = value;
        actual_page = 1;
        update_pagination_menu();
        update_user_list();

        this.parentElement.querySelectorAll("a").forEach(a => {
            a.classList.remove("selected-pagination");
        });

        this.classList.add("selected-pagination");
    });
});

function append_pagination_item(page = null) {
    const container = document.querySelector(".pagination-item-container");
    if (!container) return;

    if (page != null) {
        const html = page_item.replace(/%d/g, page);
        const temp = document.createElement("div");
        temp.innerHTML = html;
        const new_tag = temp.firstElementChild;

        container.appendChild(new_tag);
        if (actual_page == page) {
            new_tag.classList.add("actual");
        }
        new_tag.addEventListener("click", () => {
            move_to_page(parseInt(new_tag.getAttribute("data-page")));
        });
    } else {
        const temp = document.createElement("div");
        temp.innerHTML = page_ellipsis;
        container.appendChild(temp.firstElementChild);
    }
}

function update_pagination_items() {
    const container = document.querySelector(".pagination-item-container");
    if (!container) return;

    container.querySelectorAll("a").forEach(el => el.remove());
    container.querySelectorAll("span").forEach(el => el.remove());

    append_pagination_item(1);

    if (actual_page > 2) {
        append_pagination_item();
    }
    if (actual_page != 1 && actual_page != max_page) {
        append_pagination_item(actual_page);
    }
    if (actual_page < max_page - 1) {
        append_pagination_item();
    }
    append_pagination_item(max_page);
}

function update_pagination_menu() {
    max_page = Math.ceil(nb_filtered_users / per_page);
    updateArrows();
    update_pagination_items();
    const paginationContainer = document.querySelector(".pagination-container");
    if (paginationContainer) {
        paginationContainer.style.display = max_page <= 1 ? 'none' : '';
    }
}

function updateArrows() {
    document.querySelectorAll(".pagination-arrow.left").forEach(el => {
        if (actual_page == 1) {
            el.classList.add("unavailable");
        } else {
            el.classList.remove("unavailable");
        }
    });
    document.querySelectorAll(".pagination-arrow.right").forEach(el => {
        if (actual_page == max_page) {
            el.classList.add("unavailable");
        } else {
            el.classList.remove("unavailable");
        }
    });
}

/*------------------
Advanced filter
------------------*/

function advanced_filter_button_click() {
    const advFilter = document.querySelector(".advanced-filter");
    if (advFilter && !advFilter.classList.contains("advanced-filter-open")) {
        advanced_filter_show();
    } else {
        advanced_filter_hide();
    }
    // update_user_list();
}

function advanced_filter_show() {
    document.querySelectorAll(".advanced-filter-btn, .advanced-filter").forEach(el => {
        el.classList.add("advanced-filter-open");
    });
}

function advanced_filter_hide() {
    document.querySelectorAll(".advanced-filter-btn, .advanced-filter").forEach(el => {
        el.classList.remove("advanced-filter-open");
    });
}

let months = [];

function getDateStr(date) {
    let date_arr = date.split("-");
    let curr_month = months[parseInt(date_arr[1]) - 1];
    return curr_month + " " + date_arr[0];
}

function setupRegisterDates(register_dates) {
    var el = document.querySelector(".advanced-filter .dates-select-bar .slider-bar-container");
    if (el) {
        noUiSlider.create(el, {
            start: [0, register_dates.length - 1],
            range: { min: 0, max: register_dates.length - 1 },
            step: 1,
            connect: true,
        });
        function updateDatesDisplay(values) {
            var lo = Math.round(parseFloat(values[0]));
            var hi = Math.round(parseFloat(values[1]));
            const infos = document.querySelector(".advanced-filter .dates-infos");
            if (infos) {
                infos.innerHTML = sprintf(
                    dates_infos,
                    getDateStr(register_dates[lo]),
                    getDateStr(register_dates[hi]),
                );
            }
        }
        el.noUiSlider.on("slide", updateDatesDisplay);
        el.noUiSlider.on("change", function (values) {
            updateDatesDisplay(values);
            update_user_list();
        });
    }

    const datesInfos = document.querySelector(".advanced-filter .dates-infos");
    if (datesInfos) {
        datesInfos.innerHTML = sprintf(
            dates_infos,
            getDateStr(register_dates[0]),
            getDateStr(register_dates[register_dates.length - 1]),
        );
    }
}
/*------------------
Add User
------------------*/

function gen_password(e) {
    e.preventDefault();

    var characterSet =
        "ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789";

    var i;
    var password;
    var length = getRandomInt(8, 15);

    password = "";
    for (i = 0; i < length; i++) {
        password += characterSet.charAt(
            Math.floor(Math.random() * characterSet.length),
        );
    }

    const addUserPassword = document.getElementById("AddUserPassword");
    if (addUserPassword) addUserPassword.value = password;
}

function add_user_close() {
    const addUser = document.getElementById("AddUser");
    if (addUser) addUser.style.display = 'none';
}

function add_user_open() {
    const addUser = document.getElementById("AddUser");
    if (addUser) {
        addUser.querySelectorAll(".AddUserInput").forEach(el => el.value = "");
        addUser.style.display = 'flex';
        const firstInput = document.querySelector(".AddUserLabelUsername input");
        if (firstInput) firstInput.focus();
    }
}

/*------------------
Selection mode
------------------*/

function checkbox_container_change() {
    if (this.getAttribute("data-selected") == "1") {
        this.setAttribute("data-selected", "0");
        this.querySelectorAll("i").forEach(el => el.style.display = 'none');
    } else {
        this.setAttribute("data-selected", "1");
        this.querySelectorAll("i").forEach(el => el.style.display = '');
    }
}

function checkbox_container_click() {
    let curr_container = this.closest(".user-container");
    let in_container = curr_container != null;
    let curr_user = in_container
        ? current_users[parseInt(curr_container.getAttribute("key"))]
        : { id: -1 };
    if (this.getAttribute("data-selected") == "1") {
        this.setAttribute("data-selected", "0");
        this.querySelectorAll("i").forEach(el => el.style.display = 'none');
        if (in_container) {
            curr_container.classList.remove("container-selected");
            selection = selection.filter((elem) => elem.id != curr_user.id);
        }
    } else {
        this.setAttribute("data-selected", "1");
        this.querySelectorAll("i").forEach(el => el.style.display = '');
        if (in_container) {
            curr_container.classList.add("container-selected");
            selection.push({ id: curr_user.id, username: curr_user.username });
        }
    }
    if (in_container) {
        update_selection_content();
    }
}

function create_user_selected_item(user) {
    const template = document.querySelector("#template .user-selected-item");
    if (!template) return null;

    const new_elem = template.cloneNode(true);
    new_elem.setAttribute("data-id", user.id.toString());

    const p = new_elem.querySelector("p");
    if (p) p.innerHTML = user.username;

    const a = new_elem.querySelector("a");
    if (a) {
        a.addEventListener("click", () => {
            selection.splice(
                selection.findIndex((i) => i.id == user.id),
                1,
            );
            update_selection_content();
        });
    }
    return new_elem;
}

function generate_user_selected_items() {
    let items_created = 0;
    let others = selection.length - 5;

    const userSelectedList = document.querySelector(".user-selected-list");
    if (userSelectedList) {
        userSelectedList.querySelectorAll(".user-selected-item").forEach(el => el.remove());
    }

    for (let i = 0; i < selection.length && items_created < 5; i++) {
        if (typeof selection[i].username !== "undefined") {
            const item = create_user_selected_item(selection[i]);
            if (item && userSelectedList) {
                userSelectedList.appendChild(item);
            }
            items_created += 1;
        }
    }

    const selectionOtherUsers = document.querySelector(".selection-other-users");
    if (selectionOtherUsers) {
        if (others >= 1) {
            selectionOtherUsers.innerHTML = str_and_others_tags.replace("%s", others);
            selectionOtherUsers.style.display = '';
        } else {
            selectionOtherUsers.style.display = 'none';
        }
    }
}

function fill_user_selected_list() {
    let elems_with_username = 0;
    for (let i = 0; i < selection.length && elems_with_username < 5; i++) {
        if (typeof selection[i].username !== "undefined") {
            elems_with_username += 1;
        }
    }
    if (elems_with_username < 5 && elems_with_username != selection.length) {
        get_first_selection_usernames(generate_user_selected_items);
    } else {
        generate_user_selected_items();
    }
}

function update_selection_content() {
    number = selection.length;
    fill_user_selected_list();

    const forbidAction = document.getElementById("forbidAction");
    const permitActionUserList = document.getElementById("permitActionUserList");
    const selectionModeUl = document.querySelector(".selection-mode-ul");

    if (number == 0) {
        if (forbidAction) forbidAction.style.display = '';
        if (selectionModeUl) selectionModeUl.style.display = 'none';
        if (permitActionUserList) permitActionUserList.style.display = 'none';
    } else {
        if (forbidAction) forbidAction.style.display = 'none';
        if (permitActionUserList) permitActionUserList.style.display = '';
        if (selectionModeUl) selectionModeUl.style.display = '';
    }

    set_selected_to_selection();

    document.querySelectorAll("#applyActionBlock .infos").forEach(el => {
        el.style.display = 'none';
    });
}

function set_selected_to_selection() {
    const toggleSelectionMode = document.getElementById("toggleSelectionMode");
    if (!toggleSelectionMode || !toggleSelectionMode.checked) {
        return;
    }

    const containers = document.querySelectorAll(".user-container-wrapper .user-container");
    containers.forEach((container, index) => {
        selection_ids = selection.map((x) => x.id);
        if (selection_ids.includes(current_users[index].id)) {
            container.classList.add("container-selected");
            const checkbox = container.querySelector(".user-list-checkbox");
            if (checkbox) {
                checkbox.setAttribute("data-selected", "1");
                checkbox.querySelectorAll("i").forEach(el => el.style.display = '');
            }
        } else {
            container.classList.remove("container-selected");
            const checkbox = container.querySelector(".user-list-checkbox");
            if (checkbox) {
                checkbox.setAttribute("data-selected", "0");
                checkbox.querySelectorAll("i").forEach(el => el.style.display = 'none');
            }
        }
    });
}

function selectionMode(isSelection) {
    const selectAction = document.querySelector("#permitActionUserList select[name=selectAction]");
    if (selectAction) {
        selectAction.value = "-1";
        selectAction.dispatchEvent(new Event("change", { bubbles: true }));
    }

    if (isSelection) {
        //resets the selection
        //selection = [];
        set_selected_to_selection();

        document.querySelectorAll(".in-selection-mode").forEach(el => {
            el.style.display = '';
        });
        document.querySelectorAll(".not-in-selection-mode").forEach(el => {
            el.style.display = 'none';
        });

        if (view_selector === "tile") {
            document.querySelectorAll(".user-container-email").forEach(el => {
                el.style.display = '';
            });
        }

        if (view_selector === "compact") {
            document.querySelectorAll(".user-container-email").forEach(el => {
                el.style.display = 'none';
            });
        }

        document.querySelectorAll(".user-container").forEach(el => {
            el.classList.add("selectable");
        });
    } else {
        document.querySelectorAll(".container-selected").forEach(el => {
            el.classList.remove("container-selected");
        });

        document.querySelectorAll(".in-selection-mode").forEach(el => {
            el.style.display = 'none';
        });
        document.querySelectorAll(".not-in-selection-mode").forEach(el => {
            el.style.display = '';
        });

        if (view_selector === "tile" || view_selector === "line") {
            document.querySelectorAll(".user-container-email").forEach(el => {
                el.style.display = 'flex';
            });
        }

        document.querySelectorAll(".user-container").forEach(el => {
            el.classList.remove("selectable");
        });
    }
}

/*------------------
General functions
------------------*/

function hide_temporary_messages() {
    document.querySelectorAll(".update-user-success").forEach(el => {
        el.style.display = 'none';
    });
    const addUserSuccess = document.getElementById("AddUserSuccess");
    if (addUserSuccess) addUserSuccess.style.display = 'none';
    document.querySelectorAll(".error-msg").forEach(el => {
        el.style.display = 'none';
    });
}

function get_group_name_from_id(id) {
    for (let i = 0; i < groups_arr.length; i++) {
        if (groups_arr[i][0] == id) {
            return groups_arr[i][1];
        }
    }
    return "group_id error";
}

function get_container_index_from_uid(uid) {
    for (let i = 0; i < current_users.length; i++) {
        if (current_users[i].id == uid) {
            return i;
        }
    }
    return -1;
}

/*-----------------------
Generate User Containers
-----------------------*/
function user_container_click() {
    if (!isSelectionMode()) {
        return;
    }
    let curr_container = this;
    let in_container = curr_container != null;
    let container_checkbox = curr_container.querySelector(".user-list-checkbox");
    let curr_user = in_container
        ? current_users[parseInt(curr_container.getAttribute("key"))]
        : { id: -1 };

    if (container_checkbox && container_checkbox.getAttribute("data-selected") == "1") {
        container_checkbox.setAttribute("data-selected", "0");
        container_checkbox.querySelectorAll("i").forEach(el => el.style.display = 'none');
        if (in_container) {
            curr_container.classList.remove("container-selected");
            selection = selection.filter((elem) => elem.id != curr_user.id);
        }
    } else if (container_checkbox) {
        container_checkbox.setAttribute("data-selected", "1");
        container_checkbox.querySelectorAll("i").forEach(el => el.style.display = '');
        if (in_container) {
            curr_container.classList.add("container-selected");
            selection.push({ id: curr_user.id, username: curr_user.username });
        }
    }
    if (in_container) {
        update_selection_content();
    }
}

function generate_groups(container, groups) {
    const groupsContainer = container.querySelector(".user-container-groups");
    if (groupsContainer) groupsContainer.innerHTML = "";

    if (groups.length >= 1) {
        const template = document.querySelector("#template .group-primary");
        if (template) {
            let primary_grp = template.cloneNode(true);
            primary_grp.innerHTML = get_group_name_from_id(groups[0]);
            primary_grp.classList.add(color_icons[groups[0] % 5]);
            if (groupsContainer) groupsContainer.appendChild(primary_grp);
        }
    }
    if (groups.length >= 2) {
        const template = document.querySelector("#template .group-primary");
        if (template) {
            let primary_grp = template.cloneNode(true);
            primary_grp.innerHTML = get_group_name_from_id(groups[1]);
            primary_grp.classList.add(color_icons[groups[1] % 5]);
            if (groupsContainer) groupsContainer.appendChild(primary_grp);
        }
    }
    if (groups.length >= 3) {
        const template = document.querySelector("#template .group-bonus");
        if (template) {
            let bonus_grp = template.cloneNode(true);
            bonus_grp.innerHTML = "...";
            bonus_grp.classList.add(color_icons[groups[2] % 5]);
            bonus_grp.classList.add("tiptip");
            let groups_in_title = "";
            for (let i = 2; i < groups.length; i++) {
                groups_in_title += get_group_name_from_id(groups[i]) + ", ";
            }
            groups_in_title = groups_in_title.substring(
                0,
                groups_in_title.length - 2,
            );
            bonus_grp.setAttribute("title", groups_in_title);
            if (groupsContainer) groupsContainer.appendChild(bonus_grp);
        }
    }
}

function get_initials(username) {
    let words = username.toUpperCase().split(" ");
    let res = words[0][0];

    if (words.length > 1 && words[1][0] !== undefined) {
        res += words[1][0];
    }
    return res;
}

function fill_container_user_info(container, user_index) {
    let user = current_users[user_index];
    let registration_dates = user.registration_date.split(" ");
    container.setAttribute("key", user_index);

    const usernameSpan = container.querySelector(".user-container-username span");
    if (usernameSpan) usernameSpan.innerHTML = user.username;

    const initialsSpan = container.querySelector(".user-container-initials span");
    if (initialsSpan) {
        initialsSpan.innerHTML = get_initials(user.username);
        initialsSpan.classList.add(color_icons[user.id % 5]);
    }

    const statusSpan = container.querySelector(".user-container-status span");
    if (statusSpan) statusSpan.innerHTML = status_to_str[user.status];

    const emailSpan = container.querySelector(".user-container-email span");
    if (emailSpan) emailSpan.innerHTML = user.email;

    generate_groups(container, user.groups);

    const regDateEl = container.querySelector(".user-container-registration-date");
    if (regDateEl) regDateEl.innerHTML = registration_dates[0];

    const regTimeEl = container.querySelector(".user-container-registration-time");
    if (regTimeEl) regTimeEl.innerHTML = registration_dates[1];

    const regDateSinceEl = container.querySelector(".user-container-registration-date-since");
    if (regDateSinceEl) regDateSinceEl.innerHTML = user.registration_date_since;
}

function generate_user_list() {
    const userTableContent = document.getElementById("user-table-content");
    if (userTableContent) {
        userTableContent.querySelectorAll(".user-container").forEach(el => el.remove());
    }

    for (let i = 0; i < current_users.length; i++) {
        const template = document.querySelector("#template .user-container");
        if (template) {
            let new_container = template.cloneNode(true);
            fill_container_user_info(new_container, i);

            const wrapper = document.querySelector("#user-table-content .user-container-wrapper");
            if (wrapper) wrapper.appendChild(new_container);
        }
    }

    document.querySelectorAll(".user-container .user-list-checkbox").forEach(el => {
        el.removeEventListener("change", checkbox_change);
        el.removeEventListener("click", checkbox_click);
        el.addEventListener("change", checkbox_change);
    });

    document.querySelectorAll(".user-container").forEach(el => {
        el.removeEventListener("click", user_container_click);
        el.addEventListener("click", user_container_click);
    });
}

/*---------------------
Fill the pop-in values
---------------------*/

function get_formatted_date(date_str) {
    if (date_str === null) {
        return "N/A";
    }
    let first_part = date_str.split(" ")[0];
    let formatted = first_part.split("-").join("/");
    // console.log(formatted);
    return formatted;
}

function get_status_index(status) {
    for (let i = 0; i < status_arr.length; i++) {
        if (status_arr[i] === status) {
            return i;
        }
    }
    return 0;
}

function get_level_index(level) {
    for (let i = 0; i < level_arr.length; i++) {
        if (level_arr[i] === level) {
            return i;
        }
    }
    return 0;
}

function set_selected_groups(groups) {
    for (let i = 0; i < groupOptions.length; i++) {
        groupOptions[i].isSelected = groups.includes(groupOptions[i].value);
    }
}

function fill_user_edit_summary(user_to_edit, pop_in, isGuest) {
    const initialsSpan = pop_in.querySelector(".user-property-initials span");
    if (initialsSpan) {
        color_icons.forEach(icon => initialsSpan.classList.remove(icon));
        if (!isGuest) {
            initialsSpan.innerHTML = get_initials(user_to_edit.username);
        }
        initialsSpan.classList.add(color_icons[user_to_edit.id % 5]);
    }

    const usernameSpan = pop_in.querySelector(".user-property-username span");
    if (usernameSpan) {
        usernameSpan.innerHTML = user_to_edit.username;
    }

    const editUsernameSpecifier = pop_in.querySelector(".user-property-username .edit-username-specifier");
    if (editUsernameSpecifier) {
        editUsernameSpecifier.style.display = (user_to_edit.id === connected_user || user_to_edit.id === 1) ? '' : 'none';
    }

    const usernameChangeInput = pop_in.querySelector(".user-property-username-change input");
    if (usernameChangeInput) usernameChangeInput.value = user_to_edit.username;

    const passwordChangeInput = pop_in.querySelector(".user-property-password-change input");
    if (passwordChangeInput) passwordChangeInput.value = "";

    const permissionsLink = pop_in.querySelector(".user-property-permissions a");
    if (permissionsLink) {
        permissionsLink.setAttribute("href", `admin.php?page=user_perm&user_id=${user_to_edit.id}`);
    }

    const registerEl = pop_in.querySelector(".user-property-register");
    if (registerEl) {
        registerEl.innerHTML = get_formatted_date(user_to_edit.registration_date);
        tippy(registerEl, {
            content: `${registered_str}<br />${user_to_edit.registration_date_since}`,
            allowHTML: true,
            placement: 'top',
        });
    }

    const lastVisitEl = pop_in.querySelector(".user-property-last-visit");
    if (lastVisitEl) {
        lastVisitEl.innerHTML = get_formatted_date(user_to_edit.last_visit);
        tippy(lastVisitEl, {
            content: `${last_visit_str}<br />${user_to_edit.last_visit_since}`,
            allowHTML: true,
            placement: 'top',
        });
    }

    const historyLink = pop_in.querySelector(".user-property-history a");
    if (historyLink) {
        historyLink.setAttribute("href", history_base_url + user_to_edit.id);
    }
}

function fill_user_edit_properties(user_to_edit, pop_in) {
    let status_index = get_status_index(user_to_edit.status);
    let level_index = get_level_index(user_to_edit.level);
    let current_group_selectize =
        user_to_edit.id === guest_id ? groupGuestSelectize : groupSelectize;

    const emailInput = pop_in.querySelector(".user-property-email input");
    if (emailInput) emailInput.value = user_to_edit.email;

    const statusOptions = pop_in.querySelectorAll(".user-property-status select option");
    if (statusOptions && statusOptions[status_index]) {
        statusOptions[status_index].selected = true;
    }

    const levelOptions = pop_in.querySelectorAll(".user-property-level select option");
    if (levelOptions && levelOptions[level_index]) {
        levelOptions[level_index].selected = true;
    }

    const photosInputs = pop_in.querySelectorAll(".photos-select-bar input");
    if (photosInputs && photosInputs[0]) {
        photosInputs[0].value = user_to_edit.recent_period;
    }

    set_selected_groups(user_to_edit.groups);
    if (current_group_selectize) {
        current_group_selectize.clear();
        current_group_selectize.load(function (callback) {
            callback(groupOptions);
        });
        groupOptions.filter(function (group) {
            return group.isSelected;
        }).forEach(function (group) {
            current_group_selectize.addItem(group.value);
        });
    }

    const hdCheckbox = pop_in.querySelector('.user-list-checkbox[name="hd_enabled"]');
    if (hdCheckbox) {
        hdCheckbox.setAttribute("data-selected", user_to_edit.enabled_high == "true" ? "1" : "0");
    }
}

function fill_user_edit_preferences(user_to_edit, pop_in) {
    let slider_key_photos = getSliderKeyFromValue(
        parseInt(user_to_edit.nb_image_page),
        nb_image_page_values,
    );
    let slider_key_period = getSliderKeyFromValue(
        parseInt(user_to_edit.recent_period),
        recent_period_values,
    );

    const photosSlider = pop_in.querySelector(".photos-select-bar .slider-bar-container");
    if (photosSlider && photosSlider.noUiSlider) photosSlider.noUiSlider.set(slider_key_photos);

    pop_in.querySelectorAll(".user-property-theme select option").forEach((option) => {
        if (option.value == user_to_edit.theme) {
            option.selected = true;
        }
    });

    pop_in.querySelectorAll(".user-property-lang select option").forEach((option) => {
        if (option.value == user_to_edit.language) {
            option.selected = true;
        }
    });

    const periodSlider = pop_in.querySelector(".period-select-bar .slider-bar-container");
    if (periodSlider && periodSlider.noUiSlider) periodSlider.noUiSlider.set(slider_key_period);

    const expandCheckbox = pop_in.querySelector('.user-list-checkbox[name="expand_all_albums"]');
    if (expandCheckbox) {
        expandCheckbox.setAttribute("data-selected", user_to_edit.expand == "true" ? "1" : "0");
    }

    const nbCommentsCheckbox = pop_in.querySelector('.user-list-checkbox[name="show_nb_comments"]');
    if (nbCommentsCheckbox) {
        nbCommentsCheckbox.setAttribute("data-selected", user_to_edit.show_nb_comments == "true" ? "1" : "0");
    }

    const nbHitsCheckbox = pop_in.querySelector('.user-list-checkbox[name="show_nb_hits"]');
    if (nbHitsCheckbox) {
        nbHitsCheckbox.setAttribute("data-selected", user_to_edit.show_nb_hits == "true" ? "1" : "0");
    }
}

function fill_user_edit_update(user_to_edit, pop_in) {
    const updateButton = pop_in.querySelector(".update-user-button");
    if (updateButton) {
        updateButton.removeEventListener("click", update_user_info);
        updateButton.removeEventListener("click", update_guest_info);
        updateButton.addEventListener("click",
            user_to_edit.id === guest_id ? update_guest_info : update_user_info);
    }

    const editUsernameValidate = pop_in.querySelector(".edit-username-validate");
    if (editUsernameValidate) {
        editUsernameValidate.removeEventListener("click", update_user_username);
        editUsernameValidate.addEventListener("click", update_user_username);
    }

    const editPasswordValidate = pop_in.querySelector(".edit-password-validate");
    if (editPasswordValidate) {
        editPasswordValidate.removeEventListener("click", update_user_password);
        editPasswordValidate.addEventListener("click", update_user_password);
    }

    const deleteButton = pop_in.querySelector(".delete-user-button");
    if (deleteButton) {
        deleteButton.removeEventListener("click", null);
        deleteButton.addEventListener("click", function () {
            pwgConfirm({
                title: title_msg.replace("%s", user_to_edit.username),
                buttons: {
                    confirm: {
                        text: confirm_msg,
                        btnClass: "btn-red",
                        action: function () {
                            delete_user(user_to_edit.id);
                        },
                    },
                    cancel: {
                        text: cancel_msg,
                    },
                },
            });
        });
    }
}

function fill_user_edit_permissions(user_to_edit, pop_in) {
    // Helper to manage display and attributes
    function setPermissionDisplay(container, selector, visible) {
        const els = container.querySelectorAll(selector);
        els.forEach(el => el.style.display = visible ? '' : 'none');
    }

    function setDisabled(container, selector, disabled) {
        const els = container.querySelectorAll(selector);
        els.forEach(el => {
            if (disabled) {
                el.setAttribute("disabled", "disabled");
            } else {
                el.removeAttribute("disabled");
            }
        });
    }

    function setClass(container, selector, className, add) {
        const els = container.querySelectorAll(selector);
        els.forEach(el => {
            if (add) {
                el.classList.add(className);
            } else {
                el.classList.remove(className);
            }
        });
    }

    if (user_to_edit.id != connected_user) {
        // I'm not the connected user
        if (!is_owner(connected_user)) {
            // I'm not the owner, you need to test my permissions
            if (is_owner(user_to_edit.id)) {
                // I want to edit the owner but I'm not the owner (No matter my status)
                setPermissionDisplay(pop_in, ".delete-user-button", false);
                setPermissionDisplay(pop_in, ".user-property-password.edit-password", false);
                setDisabled(pop_in, ".user-property-email .user-property-input", true);
                setClass(pop_in, ".user-property-status .user-property-select", "notClickable", true);
                setPermissionDisplay(pop_in, ".user-property-username .edit-username", false);
            } else {
                setPermissionDisplay(pop_in, ".user-property-password.edit-password", true);
                setDisabled(pop_in, ".user-property-email .user-property-input", false);
                setClass(pop_in, ".user-property-status .user-property-select", "notClickable", false);
                setPermissionDisplay(pop_in, ".user-property-username .edit-username", true);
            }

            if (
                user_to_edit.status == connected_user_status &&
                connected_user_status == "webmaster" &&
                !is_owner(user_to_edit.id)
            ) {
                setPermissionDisplay(pop_in, ".delete-user-button", true);
                setPermissionDisplay(pop_in, ".user-property-password.edit-password", true);
                setDisabled(pop_in, ".user-property-email .user-property-input", false);
                setClass(pop_in, ".user-property-status .user-property-select", "notClickable", false);
                setPermissionDisplay(pop_in, ".user-property-username .edit-username", true);
            } else if (
                user_to_edit.status == connected_user_status &&
                connected_user_status == "admin"
            ) {
                setPermissionDisplay(pop_in, ".delete-user-button", false);
                setPermissionDisplay(pop_in, ".user-property-password.edit-password", true);
                setDisabled(pop_in, ".user-property-email .user-property-input", false);
                setClass(pop_in, ".user-property-username .edit-username", "notClickable", false);
                setClass(pop_in, ".user-property-status .user-property-select", "notClickable", true);
            } else if (
                user_to_edit.status == "webmaster" &&
                connected_user_status == "admin"
            ) {
                setPermissionDisplay(pop_in, ".user-property-password.edit-password", false);
                setDisabled(pop_in, ".user-property-email .user-property-input", true);
                setClass(pop_in, ".user-property-status .user-property-select", "notClickable", true);
                setPermissionDisplay(pop_in, ".user-property-username .edit-username", false);
            } else if (
                user_to_edit.status == "admin" &&
                connected_user_status == "webmaster"
            ) {
                setPermissionDisplay(pop_in, ".user-property-password.edit-password", true);
                setDisabled(pop_in, ".user-property-email .user-property-input", false);
                setClass(pop_in, ".user-property-status .user-property-select", "notClickable", false);
                setPermissionDisplay(pop_in, ".user-property-username .edit-username", true);
            }
        } else {
            // I'm the owner, I can do whatever I want
            setPermissionDisplay(pop_in, ".delete-user-button", true);
            setPermissionDisplay(pop_in, ".user-property-password.edit-password", true);
            setDisabled(pop_in, ".user-property-email .user-property-input", false);
            setClass(pop_in, ".user-property-status .user-property-select", "notClickable", false);
            setPermissionDisplay(pop_in, ".user-property-username .edit-username", true);
        }
    } else {
        // I'm the connected user
        setPermissionDisplay(pop_in, ".delete-user-button", false);
        setPermissionDisplay(pop_in, ".user-property-password.edit-password", true);
        setDisabled(pop_in, ".user-property-email .user-property-input", false);
        setClass(pop_in, ".user-property-status .user-property-select", "notClickable", true);
        setPermissionDisplay(pop_in, ".user-property-username .edit-username", true);
    }

    document.querySelectorAll(".notClickableBefore").forEach(el => {
        el.classList.remove("notClickableBefore");
    });
    document.querySelectorAll(".notClickable").forEach(el => {
        if (el.parentElement) {
            el.parentElement.classList.add("notClickableBefore");
        }
    });
}

function is_owner(user_id) {
    return user_id === owner_id;
}

function fill_user_edit(user_to_edit) {
    let pop_in = document.querySelector(".UserListPopInContainer");
    if (!pop_in) return;
    fill_user_edit_summary(user_to_edit, pop_in, false);
    fill_user_edit_properties(user_to_edit, pop_in);
    fill_user_edit_preferences(user_to_edit, pop_in);
    fill_user_edit_update(user_to_edit, pop_in);
    fill_user_edit_permissions(user_to_edit, pop_in);
}

function fill_guest_edit() {
    let user_to_edit = guest_user;
    let pop_in = document.querySelector(".GuestUserListPopInContainer");
    if (!pop_in) return;
    fill_user_edit_summary(user_to_edit, pop_in, true);
    fill_user_edit_properties(user_to_edit, pop_in);
    fill_user_edit_preferences(user_to_edit, pop_in);
    fill_user_edit_update(user_to_edit, pop_in);
}

/*-------------------
Fill data for setInfo
-------------------*/

function fill_ajax_data_from_properties(ajax_data, pop_in) {
    let groups_selected = [];
    const groupItems = pop_in.querySelectorAll(".user-property-group .ts-control .item");
    groupItems.forEach(function (item) {
        groups_selected.push(parseInt(item.getAttribute("data-value")));
    });

    const emailInput = pop_in.querySelector(".user-property-email input");
    ajax_data["email"] = emailInput ? emailInput.value : "";

    const statusSelect = pop_in.querySelector(".user-property-status select");
    const statusValue = statusSelect ? statusSelect.value : "";
    if (
        connected_user_status == "admin" &&
        statusValue != "webmaster" &&
        statusValue != "admin"
    ) {
        ajax_data["status"] = statusValue;
    } else if (connected_user_status == "webmaster") {
        ajax_data["status"] = statusValue;
    }

    const levelSelect = pop_in.querySelector(".user-property-level select");
    ajax_data["level"] = levelSelect ? levelSelect.value : "";
    ajax_data["group_id"] = groups_selected.length == 0 ? -1 : groups_selected;

    const hdCheckbox = pop_in.querySelector('.user-list-checkbox[name="hd_enabled"]');
    ajax_data["enabled_high"] = hdCheckbox && hdCheckbox.getAttribute("data-selected") == "1" ? true : false;
    return ajax_data;
}

function fill_ajax_data_from_preferences(ajax_data, pop_in) {
    const themeSelect = pop_in.querySelector(".user-property-theme select");
    ajax_data["theme"] = themeSelect ? themeSelect.value : "";

    const langSelect = pop_in.querySelector(".user-property-lang select");
    ajax_data["language"] = langSelect ? langSelect.value : "";

    let photosValue = 0;
    const photosSlider2 = pop_in.querySelector(".photos-select-bar .slider-bar-container");
    if (photosSlider2 && photosSlider2.noUiSlider) photosValue = Math.round(parseFloat(photosSlider2.noUiSlider.get()));
    ajax_data["nb_image_page"] = nb_image_page_values[photosValue];

    let periodValue = 0;
    const periodSlider2 = pop_in.querySelector(".period-select-bar .slider-bar-container");
    if (periodSlider2 && periodSlider2.noUiSlider) periodValue = Math.round(parseFloat(periodSlider2.noUiSlider.get()));
    ajax_data["recent_period"] = recent_period_values[periodValue];

    const expandCheckbox = pop_in.querySelector('.user-list-checkbox[name="expand_all_albums"]');
    ajax_data["expand"] = expandCheckbox && expandCheckbox.getAttribute("data-selected") == "1" ? true : false;

    const nbCommentsCheckbox = pop_in.querySelector('.user-list-checkbox[name="show_nb_comments"]');
    ajax_data["show_nb_comments"] = nbCommentsCheckbox && nbCommentsCheckbox.getAttribute("data-selected") == "1" ? true : false;

    const nbHitsCheckbox = pop_in.querySelector('.user-list-checkbox[name="show_nb_hits"]');
    ajax_data["show_nb_hits"] = nbHitsCheckbox && nbHitsCheckbox.getAttribute("data-selected") == "1" ? true : false;

    return ajax_data;
}

function fill_ajax_data_from_container(ajax_data, pop_in) {
    ajax_data = fill_ajax_data_from_properties(ajax_data, pop_in);
    ajax_data = fill_ajax_data_from_preferences(ajax_data, pop_in);
    return ajax_data;
}

/*----------------
Ajax Requests
----------------*/

function get_first_selection_usernames(callback) {
    let first_ids = selection.slice(0, 50).map((x) => x.id);
    const params = new URLSearchParams({
        display: "username",
        order: "id",
        user_id: first_ids,
        exclude: [guest_id],
    });

    fetch("ws.php?format=json&method=pwg.users.getList", {
        method: "POST",
        body: params,
    })
        .then(response => response.json())
        .then(data => {
            let result = data.result.users;
            for (let i = 0; i < result.length; i++) {
                let index = selection.findIndex((x) => x.id === result[i].id);
                if (index != -1) {
                    selection[index].username = result[i].username;
                }
            }
            callback();
        })
        .catch(err => console.error("Error getting selection usernames:", err));
}

function select_whole_set() {
    const statusSelect = document.querySelector(".advanced-filter-select[name=filter_status]");
    const groupSelect = document.querySelector(".advanced-filter-select[name=filter_group]");
    const levelSelect = document.querySelector(".advanced-filter-select[name=filter_level]");

    let minRegister = 0, maxRegister = 0;
    const _datesSliderWS = document.querySelector(".dates-select-bar .slider-bar-container");
    if (_datesSliderWS && _datesSliderWS.noUiSlider) {
        const _v = _datesSliderWS.noUiSlider.get().map(Number);
        minRegister = register_dates[Math.round(_v[0])];
        maxRegister = register_dates[Math.round(_v[1])];
    }

    const params = new URLSearchParams({
        display: "only_id",
        order: "id",
        page: actual_page - 1,
        per_page: 0,
        exclude: [guest_id],
        status: statusSelect ? statusSelect.value : "",
        group_id: groupSelect ? groupSelect.value : "",
        min_level: levelSelect ? levelSelect.value : "",
        max_level: levelSelect ? levelSelect.value : "",
        min_register: minRegister,
        max_register: maxRegister,
    });

    const loadingEl = document.querySelector("#checkActions .loading");
    if (loadingEl) loadingEl.style.display = '';

    fetch("ws.php?format=json&method=pwg.users.getList", {
        method: "POST",
        body: params,
    })
        .then(response => response.json())
        .then(data => {
            selection = data.result.map((x) => {
                return { id: x };
            });
            if (loadingEl) loadingEl.style.display = 'none';
            update_selection_content();
        })
        .catch(err => {
            console.error("Error selecting whole set:", err);
            if (loadingEl) loadingEl.style.display = 'none';
        });
}

function update_user_username() {
    let pop_in_container = document.querySelector(".UserListPopInContainer");
    if (!pop_in_container) return;

    let ajax_data = {
        pwg_token: pwg_token,
        user_id: last_user_id,
    };
    const usernameInput = pop_in_container.querySelector(".user-property-input-username");
    ajax_data["username"] = usernameInput ? usernameInput.value : "";

    if (ajax_data.username.replace(/\s/g, "").length == 0) {
        const failEl = document.querySelector(".update-user-fail");
        if (failEl) {
            failEl.innerHTML = fieldNotEmpty;
            failEl.style.display = '';
            setTimeout(() => {
                failEl.style.display = 'none';
            }, 1500 + 2500);
        }
        return;
    }

    const params = new URLSearchParams(ajax_data);
    fetch("ws.php?format=json&method=pwg.users.setInfo", {
        method: "POST",
        body: params,
    })
        .then(response => response.json())
        .then(data => {
            if (data.stat == "ok") {
                if (last_user_index != -1) {
                    current_users[last_user_index].username =
                        data.result.users[0].username;
                    const titleEl = document.querySelector("#UserList .user-property-username .edit-username-title");
                    if (titleEl) titleEl.innerHTML = current_users[last_user_index].username;

                    const initialsEl = document.querySelector("#UserList .user-property-initials span");
                    if (initialsEl) initialsEl.innerHTML = get_initials(current_users[last_user_index].username);

                    const container = document.querySelector("#user-table-content .user-container");
                    if (container) {
                        fill_container_user_info(container, last_user_index);
                    }
                }

                const successEl = document.querySelector("#UserList .update-user-success");
                if (successEl) {
                    successEl.style.display = '';
                    setTimeout(() => {
                        successEl.style.display = 'none';
                    }, 1500 + 2500);
                }

                document.querySelectorAll(".user-property-username").forEach(el => {
                    el.style.display = '';
                });
                document.querySelectorAll(".user-property-username-change").forEach(el => {
                    el.style.display = 'none';
                });
            }
        })
        .catch(err => console.error("Error updating username:", err));
}

function update_user_password() {
    let pop_in_container = document.querySelector(".UserListPopInContainer");
    if (!pop_in_container) return;

    let ajax_data = {
        pwg_token: pwg_token,
        user_id: last_user_id,
    };
    const passwordInput = pop_in_container.querySelector(".user-property-input-password");
    ajax_data["password"] = passwordInput ? passwordInput.value : "";

    const params = new URLSearchParams(ajax_data);
    fetch("ws.php?format=json&method=pwg.users.setInfo", {
        method: "POST",
        body: params,
    })
        .then(response => response.json())
        .then(data => {
            if (data.stat == "ok") {
                const successEl = document.querySelector("#UserList .update-user-success");
                if (successEl) {
                    successEl.style.display = '';
                    setTimeout(() => {
                        successEl.style.display = 'none';
                    }, 1500 + 2500);
                }

                document.querySelectorAll(".user-property-password").forEach(el => {
                    el.style.display = '';
                });
                document.querySelectorAll(".user-property-password-change").forEach(el => {
                    el.style.display = 'none';
                });
            }
        })
        .catch(err => console.error("Error updating password:", err));
}

function update_user_info() {
    //Show spinner
    document.querySelectorAll(".update-user-button i").forEach(el => {
        el.classList.remove("icon-floppy");
        el.classList.add("icon-spin6", "animate-spin");
    });
    document.querySelectorAll(".update-user-button").forEach(el => {
        el.classList.add("unclickable");
    });

    let pop_in_container = document.querySelector(".UserListPopInContainer");
    if (!pop_in_container) return;

    let ajax_data = {
        pwg_token: pwg_token,
        user_id: last_user_id,
    };

    ajax_data = fill_ajax_data_from_container(ajax_data, pop_in_container);

    // Hide fail/success messages before sending
    document.querySelectorAll("#UserList .update-user-fail").forEach(el => {
        el.style.display = 'none';
    });
    document.querySelectorAll("#UserList .update-user-success").forEach(el => {
        el.style.display = 'none';
    });

    const params = new URLSearchParams(ajax_data);
    fetch("ws.php?format=json&method=pwg.users.setInfo", {
        method: "POST",
        body: params,
    })
        .then(response => response.json())
        .then(data => {
            if (data.stat === "ok") {
                let result_user = data.result.users[0];
                if (last_user_index != -1) {
                    current_users[last_user_index].email = result_user.email;
                    current_users[last_user_index].enabled_high = result_user.enabled_high;
                    current_users[last_user_index].expand = result_user.expand;
                    current_users[last_user_index].groups = result_user.groups;
                    current_users[last_user_index].language = result_user.language;
                    current_users[last_user_index].level = result_user.level;
                    current_users[last_user_index].nb_image_page = result_user.nb_image_page;
                    current_users[last_user_index].recent_period = result_user.recent_period;
                    current_users[last_user_index].show_nb_comments = result_user.show_nb_comments;
                    current_users[last_user_index].show_nb_hits = result_user.show_nb_hits;
                    current_users[last_user_index].status = result_user.status;
                    current_users[last_user_index].theme = result_user.theme;

                    const container = document.querySelector("#user-table-content .user-container");
                    if (container) {
                        fill_container_user_info(container, last_user_index);
                    }
                }

                const successEl = document.querySelector("#UserList .update-user-success");
                if (successEl) {
                    successEl.style.display = '';
                    setTimeout(() => {
                        successEl.style.display = 'none';
                    }, 1500 + 2500);
                }

                //Hide spinner
                document.querySelectorAll(".update-user-button i").forEach(el => {
                    el.classList.remove("icon-spin6", "animate-spin");
                    el.classList.add("icon-floppy");
                });
                document.querySelectorAll(".update-user-button").forEach(el => {
                    el.classList.remove("unclickable");
                });
            } else if (data.stat === "fail") {
                const failEl = document.querySelector("#UserList .update-user-fail");
                if (failEl) {
                    failEl.innerHTML = data.message;
                    failEl.style.display = '';
                }

                document.querySelectorAll(".update-user-button i").forEach(el => {
                    el.classList.add("icon-floppy");
                    el.classList.remove("icon-spin6", "animate-spin");
                });
                document.querySelectorAll(".update-user-button").forEach(el => {
                    el.classList.remove("unclickable");
                });

                setTimeout(() => {
                    if (failEl) failEl.style.display = 'none';
                }, 5000);
            }
        })
        .catch(err => {
            console.error("Error updating user info:", err);
            document.querySelectorAll(".update-user-button i").forEach(el => {
                el.classList.remove("icon-spin6", "animate-spin");
                el.classList.add("icon-floppy");
            });
            document.querySelectorAll(".update-user-button").forEach(el => {
                el.classList.remove("unclickable");
            });
        });
}

function get_guest_info() {
    const params = new URLSearchParams({
        display: "all",
        user_id: guest_id,
    });

    fetch("ws.php?format=json&method=pwg.users.getList", {
        method: "POST",
        body: params,
    })
        .then(response => response.json())
        .then(data => {
            if (data.stat == "ok") {
                guest_user = data.result.users[0];
                fill_guest_edit();
            }
        })
        .catch(err => console.error("Error getting guest info:", err));
}

function get_user_info(uid, callback = null) {
    const params = new URLSearchParams({
        display: "all",
        user_id: uid,
    });

    fetch("ws.php?format=json&method=pwg.users.getList", {
        method: "POST",
        body: params,
    })
        .then(response => response.json())
        .then(data => {
            if (data.stat == "ok") {
                let result_user = data.result.users[0];
                fill_user_edit(result_user);
                if (callback) callback();
            }
        })
        .catch(err => console.error("Error getting user info:", err));
}

function update_guest_info() {
    //Show spinner
    document.querySelectorAll(".update-user-button i").forEach(el => {
        el.classList.remove("icon-floppy");
        el.classList.add("icon-spin6", "animate-spin");
    });
    document.querySelectorAll(".update-user-button").forEach(el => {
        el.classList.add("unclickable");
    });

    let pop_in_container = document.querySelector(".GuestUserListPopInContainer");
    if (!pop_in_container) return;

    let ajax_data = {
        pwg_token: pwg_token,
        user_id: guest_id,
    };
    ajax_data = fill_ajax_data_from_container(ajax_data, pop_in_container);
    delete ajax_data.email;
    delete ajax_data.status;

    const params = new URLSearchParams(ajax_data);
    fetch("ws.php?format=json&method=pwg.users.setInfo", {
        method: "POST",
        body: params,
    })
        .then(response => response.json())
        .then(data => {
            if (data.stat == "ok") {
                const successEl = document.querySelector("#GuestUserList .update-user-success");
                if (successEl) {
                    successEl.style.display = '';
                    setTimeout(() => {
                        successEl.style.display = 'none';
                    }, 1500 + 2500);
                }
            }
            //Hide spinner
            document.querySelectorAll(".update-user-button i").forEach(el => {
                el.classList.remove("icon-spin6", "animate-spin");
                el.classList.add("icon-floppy");
            });
            document.querySelectorAll(".update-user-button").forEach(el => {
                el.classList.remove("unclickable");
            });
        })
        .catch(err => {
            console.error("Error updating guest info:", err);
            document.querySelectorAll(".update-user-button i").forEach(el => {
                el.classList.remove("icon-spin6", "animate-spin");
                el.classList.add("icon-floppy");
            });
            document.querySelectorAll(".update-user-button").forEach(el => {
                el.classList.remove("unclickable");
            });
        });
}

function update_user_list() {
    let update_data = {
        display: "all",
        order: "id DESC", // We want the most recent user first
        page: actual_page - 1,
        per_page: per_page,
        exclude: [guest_id],
    };

    const userSearch = document.getElementById("user_search");
    if (userSearch && userSearch.value.length != 0) {
        update_data["filter"] = userSearch.value;
    }

    const advancedFilter = document.querySelector(".advanced-filter");
    if (advancedFilter && advancedFilter.classList.contains("advanced-filter-open")) {
        const statusSelect = document.querySelector(".advanced-filter-select[name=filter_status]");
        const groupSelect = document.querySelector(".advanced-filter-select[name=filter_group]");
        const levelSelect = document.querySelector(".advanced-filter-select[name=filter_level]");

        update_data["status"] = statusSelect ? statusSelect.value : "";
        update_data["group_id"] = groupSelect ? groupSelect.value : "";
        update_data["min_level"] = levelSelect ? levelSelect.value : "";
        update_data["max_level"] = levelSelect ? levelSelect.value : "";

        let minRegister = 0, maxRegister = 0;
        const _datesSliderUL = document.querySelector(".dates-select-bar .slider-bar-container");
        if (_datesSliderUL && _datesSliderUL.noUiSlider) {
            const _v = _datesSliderUL.noUiSlider.get().map(Number);
            minRegister = register_dates[Math.round(_v[0])];
            maxRegister = register_dates[Math.round(_v[1])];
        }
        update_data["min_register"] = minRegister;
        update_data["max_register"] = maxRegister;
    }

    const spinnerEl = document.querySelector(".user-update-spinner");
    if (spinnerEl) spinnerEl.style.display = '';

    const params = new URLSearchParams(update_data);
    fetch("ws.php?format=json&method=pwg.users.getList", {
        method: "POST",
        body: params,
    })
        .then(response => response.json())
        .then(data => {
            if (data.stat === "fail") {
                console.log(data.message);
                if (spinnerEl) spinnerEl.style.display = 'none';
                return;
            }

            total_users = data.result.total_count;
            if (first_update) {
                const h1 = document.querySelector("h1");
                if (h1) {
                    h1.appendChild(
                        Object.assign(document.createElement("span"), {
                            className: "badge-number",
                            innerHTML: total_users
                        })
                    );
                }
                first_update = false;
            }

            nb_filtered_users = data.result.total_count;
            update_pagination_menu();
            current_users = data.result.users;
            generate_user_list();

            // Add click handler to user edit columns
            document.querySelectorAll(".user-col.user-first-col.user-container-edit").forEach(el => {
                el.removeEventListener("click", null);
                el.addEventListener("click", function () {
                    let uid_index = this.closest(".user-container").getAttribute("key");
                    last_user_id = current_users[uid_index].id;
                    last_user_index = uid_index;
                    fill_user_edit(current_users[uid_index]);
                    const userListEl = document.getElementById("UserList");
                    if (userListEl) userListEl.style.display = 'flex';
                });
            });

            set_selected_to_selection();

            if (spinnerEl) spinnerEl.style.display = 'none';

            // Count active filters
            let nb_filters = 0;
            const statusVal = document.querySelector(".advanced-filter-select[name=filter_status]");
            if (statusVal && statusVal.value != "") nb_filters += 1;

            const groupVal = document.querySelector(".advanced-filter-select[name=filter_group]");
            if (groupVal && groupVal.value != "") nb_filters += 1;

            const levelVal = document.querySelector(".advanced-filter-select[name=filter_level]");
            if (levelVal && levelVal.value != "") nb_filters += 1;

            const _datesSliderF = document.querySelector(".dates-select-bar .slider-bar-container");
            if (_datesSliderF && _datesSliderF.noUiSlider) {
                const _v = _datesSliderF.noUiSlider.get().map(Number);
                if (Math.round(_v[0]) != 0) nb_filters += 1;
                if (Math.round(_v[1]) != register_dates.length - 1) nb_filters += 1;
            }

            show_filter_infos(nb_filters);
        })
        .catch(err => {
            console.error("Error updating user list:", err);
            if (spinnerEl) spinnerEl.style.display = 'none';
        });
}

function add_user() {
    const usernameInput = document.querySelector(".AddUserLabelUsername .user-property-input");
    const usernameVal = usernameInput ? usernameInput.value : "";

    const addUserErrors = document.querySelector("#AddUser .AddUserErrors");
    addUserErrors.style.visibility = "hidden";

    if (usernameVal == "") {
        if (addUserErrors) {
            addUserErrors.innerHTML = missingUsername;
            addUserErrors.style.visibility = "visible";
        }
        return false;
    }

    let ajax_data = {
        pwg_token: pwg_token,
    };
    ajax_data.username = usernameVal;

    const passwordInput = document.getElementById("AddUserPassword");
    ajax_data.password = passwordInput ? passwordInput.value : "";

    const emailInput = document.querySelector(".AddUserLabelEmail .user-property-input");
    ajax_data.email = emailInput ? emailInput.value : "";

    const sendByEmailCheckbox = document.querySelector('.user-list-checkbox[name="send_by_email"]');
    ajax_data.send_password_by_mail = sendByEmailCheckbox && sendByEmailCheckbox.getAttribute("data-selected") == "1" ? true : false;

    const params = new URLSearchParams(ajax_data);
    fetch("ws.php?format=json&method=pwg.users.add", {
        method: "POST",
        body: params,
    })
        .then(response => response.json())
        .then(data => {
            if (data.stat == "ok") {
                let new_user_id = data.result.users[0].id;
                update_user_list();
                add_user_close();

                document.querySelectorAll("#AddUser .user-property-input").forEach(el => {
                    el.value = "";
                });

                const editNowBtn = document.querySelector("#AddUserSuccess .edit-now");
                if (editNowBtn) {
                    editNowBtn.removeEventListener("click", null);
                    editNowBtn.addEventListener("click", () => {
                        last_user_id = new_user_id;
                        last_user_index = get_container_index_from_uid(new_user_id);
                        if (last_user_index != -1) {
                            fill_user_edit(current_users[last_user_index]);
                            open_user_list();
                        } else {
                            get_user_info(new_user_id, open_user_list);
                        }
                    });
                }

                const successLabel = document.querySelector("#AddUserSuccess label span:first-child");
                if (successLabel) {
                    successLabel.innerHTML = user_added_str.replace("%s", ajax_data.username);
                }

                const addUserSuccess = document.getElementById("AddUserSuccess");
                if (addUserSuccess) addUserSuccess.style.display = "flex";
            } else {
                if (addUserErrors) {
                    addUserErrors.innerHTML = data.message;
                    addUserErrors.style.visibility = "visible";
                }
            }
        })
        .catch(err => console.error("Error adding user:", err));
}

function delete_user(uid) {
    const params = new URLSearchParams({
        user_id: uid,
        pwg_token: pwg_token,
    });

    fetch("ws.php?format=json&method=pwg.users.delete", {
        method: "POST",
        body: params,
    })
        .then(response => response.json())
        .then(data => {
            close_user_list();
            update_user_list();
        })
        .catch(err => console.error("Error deleting user:", err));
}

function show_filter_infos(nb_filters) {
    const userSearch = document.getElementById("user_search");
    if ((userSearch && userSearch.value.length != 0) || nb_filters != 0) {
        const filteredUsersEls = document.querySelectorAll(".filtered-users");
        const message = total_users != "1"
            ? filtered_users.replace(/%d/g, total_users)
            : filtered_user.replace(/%d/g, total_users);
        filteredUsersEls.forEach(el => el.innerHTML = message);
    } else {
        document.querySelectorAll(".filtered-users").forEach(el => el.innerHTML = "");
    }

    if (nb_filters != 0) {
        document.querySelectorAll(".advanced-filter-btn").forEach(el => {
            el.style.width = "80px";
        });
        document.querySelectorAll(".filter-counter").forEach(el => {
            el.innerHTML = nb_filters;
            el.style.display = "flex";
        });
    } else {
        document.querySelectorAll(".advanced-filter-btn").forEach(el => {
            el.style.width = "70px";
        });
        document.querySelectorAll(".filter-counter").forEach(el => {
            el.innerHTML = "0";
            el.style.display = "none";
        });
    }
    // Setup string messages from config
    const title_msg = cfg.titleMsg;
    const are_you_sure_msg = cfg.areYouSureMsg;
    const confirm_msg = cfg.confirmMsg;
    const cancel_msg = cfg.cancelMsg;
    const str_and_others_tags = cfg.strAndOthersTags;
    const missingConfirm = cfg.missingConfirm;
    const missingUsername = cfg.missingUsername;
    const fieldNotEmpty = cfg.fieldNotEmpty;
    const registered_str = cfg.registeredStr;
    const last_visit_str = cfg.lastVisitStr;
    const dates_infos = cfg.datesInfos;
    const hide_str = cfg.hideStr;
    const show_str = cfg.showStr;
    const user_added_str = cfg.userAddedStr;
    const str_popin_update_btn = cfg.strPopinUpdateBtn;
    const filtered_users = cfg.filteredUsers;
    const filtered_user = cfg.filteredUser;
    const history_base_url = cfg.historyBaseUrl;
    const status_to_str = cfg.statusToStr;
    const view_selector = cfg.viewSelector;
    const pagination = cfg.pagination;
    const months = cfg.months;
    const connected_user_status = cfg.connectedUserStatus;
    const owner_id = cfg.ownerId;
    const has_group = cfg.hasGroup;
    const groupOptions = groups_arr.map(x => ({ value: x[0], label: x[1], isSelected: 0 }));

  // Startup
  setupRegisterDates(register_dates);
  selectionMode(false);
  get_guest_info();
  update_user_list();
  update_selection_content();

  tippy('.icon-help-circled', { maxWidth: 700, delay: 0, placement: 'top' });

  // Only webmaster can set admin or webmaster to others users
  if (connected_user_status !== 'webmaster') {
    document.querySelectorAll('select[name="status"] option[value="webmaster"], select[name="status"] option[value="admin"]').forEach(function(el) {
      el.setAttribute("disabled", true);
    });
  }

  // We set the applyAction btn click event here so plugins can add cases to the list
  // which is not possible if this JS part is in a JS file
  // see #1571 on Github
  const applyActionBtn = document.getElementById("applyAction");
  if (applyActionBtn) {
    applyActionBtn.addEventListener('click', function(event) {
      event.preventDefault();
      const selectActionEl = document.querySelector("select[name=selectAction]");
      let action = selectActionEl ? selectActionEl.value : '';
      let method = 'pwg.users.setInfo';
      let data = {
        pwg_token: pwg_token,
        user_id: selection.map(x => x.id)
      };
      switch (action) {
        case 'delete':
          if (!(document.querySelector("#permitActionUserList .user-list-checkbox[name=confirm_deletion]")
              ?.getAttribute("data-selected") === "1")) {
            alert(missingConfirm);
            return;
          }
          method = 'pwg.users.delete';
          break;
        case 'group_associate':
          method = 'pwg.groups.addUser';
          data.group_id = document.querySelector("#permitActionUserList select[name=associate]")?.value;
          break;
        case 'group_dissociate':
          method = 'pwg.groups.deleteUser';
          data.group_id = document.querySelector("#permitActionUserList select[name=dissociate]")?.value;
          break;
        case 'status':
          data.status = document.querySelector("#permitActionUserList select[name=status]")?.value;
          break;
        case 'enabled_high':
          data.enabled_high = document.querySelector("#permitActionUserList .user-list-checkbox[name=enabled_high_yes]")
            ?.getAttribute("data-selected") === "1" ? true : false;
          break;
        case 'level':
          data.level = document.querySelector("#permitActionUserList select[name=level]")?.value;
          break;
        case 'nb_image_page':
          data.nb_image_page = document.querySelector("#permitActionUserList input[name=nb_image_page]")?.value;
          break;
        case 'theme':
          data.theme = document.querySelector("#permitActionUserList select[name=theme]")?.value;
          break;
        case 'language':
          data.language = document.querySelector("#permitActionUserList select[name=language]")?.value;
          break;
        case 'recent_period': {
          const _ps = document.querySelector('#permitActionUserList .period-select-bar .slider-bar-container');
          data.recent_period = recent_period_values[_ps && _ps.noUiSlider ? Math.round(parseFloat(_ps.noUiSlider.get())) : 0];
          break;
        }
        case 'expand':
          data.expand = document.querySelector("#permitActionUserList .user-list-checkbox[name=expand_yes]")
            ?.getAttribute("data-selected") === "1" ? true : false;
          break;
        case 'show_nb_comments':
          data.show_nb_comments = document.querySelector("#permitActionUserList .user-list-checkbox[name=show_nb_comments_yes]")
            ?.getAttribute("data-selected") === "1" ? true : false;
          break;
        case 'show_nb_hits':
          data.show_nb_hits = document.querySelector("#permitActionUserList .user-list-checkbox[name=show_nb_hits_yes]")
            ?.getAttribute("data-selected") === "1" ? true : false;
          break;
        default:
          alert("Unexpected action");
          return;
      }
      const loadingEl = document.getElementById('applyActionLoading');
      const infosEl = document.querySelector("#applyActionBlock .infos");
      if (loadingEl) loadingEl.style.display = '';
      if (infosEl) infosEl.style.display = 'none';
      const params = new URLSearchParams();
      Object.keys(data).forEach(function(k) {
        const v = data[k];
        if (Array.isArray(v)) {
          v.forEach(function(item) { params.append(k + '[]', item); });
        } else {
          params.append(k, v);
        }
      });
      fetch("ws.php?format=json&method=" + method, {
        method: "POST",
        headers: {"Content-Type": "application/x-www-form-urlencoded"},
        body: params
      })
        .then(function(r) { return r.json(); })
        .then(function(responseData) {
          if (loadingEl) loadingEl.style.display = 'none';
          if (infosEl) infosEl.style.display = 'inline-block';
          update_user_list();
          if (action == 'delete') {
            selection = [];
            update_selection_content();
          }
        })
        .catch(function() {
          if (loadingEl) loadingEl.style.display = 'none';
        });
    });
  }
  }
}

initModule(init);
