var _docReady = function(fn) { document.readyState !== 'loading' ? fn() : document.addEventListener('DOMContentLoaded', fn); };
_docReady( function () {
    activateLineOptions();
    checkFilters();

    if (current_param.ip != "") {
        addIpFilter(current_param.ip);
    }
    if (current_param.image_id != "") {
        addImageFilter(current_param.image_id);
    }
    if (current_param.user_id != "-1") {
        addUserFilter(filter_user_name);
    }

    document.querySelector(".elem-type-select").addEventListener("change", function (e) {
        console.log(document.querySelector(".elem-type-select option:checked").getAttribute("value"));

        if (document.querySelector(".elem-type-select option:checked").getAttribute("value") == "visited") {
            current_param.types = {
                0: "none",
                1: "picture",
            };
        } else if (
            document.querySelector(".elem-type-select option:checked").getAttribute("value") == "downloaded"
        ) {
            current_param.types = {
                0: "high",
                1: "other",
            };
        } else {
            current_param.types = {
                0: "none",
                1: "picture",
                2: "high",
                3: "other",
            };
        }

        fillHistoryResult(current_param);
    });

    document.querySelector(".date-start").addEventListener("change", function () {
        if (
            current_param.start !=
            document.querySelector('.date-start input[name="start"]').getAttribute("value")
        ) {
            current_param.start = document.querySelector('.date-start input[name="start"]').getAttribute(
                "value",
            );
            current_param.pageNumber = 0;
            fillHistoryResult(current_param);
        }
    });

    document.querySelector(".date-end").addEventListener("change", function () {
        const newValue = document.querySelector('.date-end input[name="end"]').getAttribute("value");
        if (current_param.end != newValue) {
            current_param.end = newValue;
            current_param.pageNumber = 0;
            // The datepicker first fills the end-date with '1899-12-31',
            // which triggers an unnecessary ajax request
            // when you come to the history search page from a photo.
            if (newValue !== "1899-12-31") {
                fillHistoryResult(current_param);
            }
        }
    });

    document.getElementById("start_unset").addEventListener("click", function () {
        console.log("here" + current_param.start);
        if (!current_param.start == "") {
            current_param.pageNumber = 0;
            current_param.start = "";
            fillHistoryResult(current_param);
        }
    });

    document.getElementById("end_unset").addEventListener("click", function () {
        if (!current_param.start == today) {
            current_param.end = today;
            current_param.pageNumber = 0;
            fillHistoryResult(current_param);
        }
    });

    document.querySelector(".pagination-arrow.right").addEventListener("click", function () {
        current_param.pageNumber += 1;
        fillHistoryResult(current_param);
    });

    document.querySelector(".pagination-arrow.left").addEventListener("click", function () {
        current_param.pageNumber -= 1;
        fillHistoryResult(current_param);
    });

    document.querySelector(".refresh-results").addEventListener("click", function () {
        fillHistoryResult(current_param);
    });
});

function activateLineOptions() {
    document.querySelectorAll(".search-line .img-option").forEach(function (el) {
        el.style.display = 'none';
    });

    /* Display the option on the click on "..." */
    document.querySelectorAll(".search-line .toggle-img-option").forEach(function (el) {
        el.addEventListener("click", function () {
            var imgOption = this.closest(".search-line").querySelector(".img-option");
            if (imgOption) {
                imgOption.style.display = getComputedStyle(imgOption).display === 'none' ? '' : 'none';
            }
        });
    });

    /* Hide img options and rename field on click on the screen */

    document.addEventListener("mouseup", function (e) {
        e.stopPropagation();
        let option_is_clicked = false;
        document.querySelectorAll(".img-option span").forEach(function (el) {
            if (el.contains(e.target)) {
                option_is_clicked = true;
            }
        });
        if (!option_is_clicked) {
            document.querySelectorAll(".search-line .img-option").forEach(function (el) {
                el.style.display = 'none';
            });
        }
    });
}

function fillSummaryResult(summary) {
    document.querySelector(".user-list").innerHTML = '';

    document.querySelector(".summary-lines .summary-data").innerHTML = summary.NB_LINES;
    document.querySelector(".summary-weight .summary-data").innerHTML =
        unit_MB.replace("%s", summary.FILESIZE);
    document.querySelector(".summary-users .summary-data").innerHTML = summary.USERS;
    document.querySelector(".summary-guests .summary-data").innerHTML = summary.GUESTS;

    if (summary.GUESTS.split(" ")[0] != "0") {
        var guestDataEl = document.querySelector(".summary-guests .summary-data");
        guestDataEl.classList.add("icon-plus-circled");
        guestDataEl.addEventListener("click", function () {
            if (current_param.user_id == "-1") {
                current_param.user_id = guest_id;
                addGuestFilter(str_guest);
                fillHistoryResult(current_param);
            }
        });
        guestDataEl.style.cursor = "pointer";

        document.querySelector(".summary-guests").style.display = '';
    } else {
        document.querySelector(".summary-guests").style.display = 'none';
    }

    var id_of = [];
    var user_dot_title = "";

    // not sorted
    summary.MEMBERS.forEach((keyval) => {
        for (const [key, value] of Object.entries(keyval)) {
            id_of[key] = value;
            user_dot_title += key + ", ";
        }
    });
    user_dot_title = user_dot_title.slice(0, -2);
    var userDot = document.querySelector(".user-dot");
    userDot.setAttribute("title", user_dot_title);
    userDot.classList.add("tiptip");

    var tmp = 0;
    document.querySelector(".user-dot").style.display = 'none';
    //sorted
    for (const [key, value] of Object.entries(summary.SORTED_MEMBERS)) {
        if (tmp < 5) {
            var new_user_item = document.getElementById("-2").cloneNode(true);

            new_user_item.classList.remove("hide");
            new_user_item.querySelector(".user-item-name").innerHTML = key;
            new_user_item.dataset.userId = id_of[key];

            new_user_item.addEventListener("click", function () {
                if (current_param.user_id != id_of[key]) {
                    current_param.user_id = this.dataset.userId;
                    addUserFilter(key);
                    fillHistoryResult(current_param);
                }
            });
            document.querySelector(".user-list").appendChild(new_user_item);
            tmp++;
        } else {
            document.querySelector(".user-dot").style.display = '';
        }
    }
}

function showResults(doShow) {
    console.log("EMPTY");
    if (doShow) {
        document.querySelector(".search-summary").style.display = '';
        document.querySelector(".container").style.display = '';
    } else {
        document.querySelector(".search-summary").style.display = 'none';
        document.querySelector(".container").style.display = 'none';
    }
}

function fillHistoryResult(ajaxParam) {
    // console.log(current_param);
    // document.querySelector(".tab .search-line").remove();
    fetch(API_METHOD, {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: new URLSearchParams(ajaxParam),
    })
        .then(function (response) { return response.json(); })
        .then(function (raw_data) {
            showResults(false);
            document.querySelector(".loading").classList.remove("hide");
            document.querySelector(".noResults").style.display = 'none';
            document.querySelector(".tab").innerHTML = '';

            data = raw_data.result["lines"];
            imageDisplay = raw_data.result["params"].display_thumbnail;
            maxPage = raw_data.result["maxPage"];
            summary = raw_data.result["summary"];
            // console.log(raw_data);

            //clear lines before refill

            if (data.length > 0) {
                var id = 0;
                data.forEach((line) => {
                    lineConstructor(line, id, imageDisplay);
                    id++;
                });

                fillSummaryResult(summary);
                showResults(true);
                document.querySelector(".noResults").style.display = 'none';
            } else {
                showResults(false);
                document.querySelector(".noResults").style.display = '';
            }

            activateLineOptions();
            document.querySelector(".loading").classList.add("hide");
            updatePagination(maxPage);

            tippy('.tiptip', { delay: 0, placement: 'top' });
        })
        .catch(function (e) {
            console.log(e);
        });
}

function lineConstructor(line, id, imageDisplay) {
    let newLine = document.getElementById("-1").cloneNode(true);

    let sections = [
        "categories",
        "tags",
        "best_rated",
        "memories-1-year-ago",
        "list",
        "search",
        "most_visited",
        "recent_pics",
        "recent_cats",
        "favorites",
    ];

    let icons = [
        "line-icon icon-folder-open icon-yellow",
        "line-icon icon-tags icon-blue",
        "line-icon icon-star icon-green",
        "line-icon icon-clock icon-yellow",
        "line-icon icon-dice-solid icon-purple",
        "line-icon icon-search icon-purple",
        "line-icon icon-fire icon-red",
        "line-icon icon-clock icon-yellow",
        "line-icon icon-clock icon-yellow",
        "line-icon icon-heart icon-red",
    ];

    newLine.classList.remove("hide");

    /* console log to help debug */
    // console.log(line);
    newLine.id = id;
    // console.log(id);

    newLine.querySelector(".date-day").innerHTML = line.DATE;
    newLine.querySelector(".date-hour").innerHTML = line.TIME;

    newLine
        .querySelector(".user-name")
        .innerHTML = line.USERNAME + '<i class="add-filter icon-plus-circled"></i>';

    newLine.querySelector(".user-name").id = line.USERID;
    if (current_param.user_id == "-1") {
        newLine.querySelector(".user-name").addEventListener("click", function () {
            current_param.user_id = this.id + "";
            current_param.pageNumber = 0;
            addUserFilter(this.innerHTML);
            fillHistoryResult(current_param);
        });
    }

    newLine
        .querySelector(".user-ip")
        .innerHTML = line.IP + '<i class="add-filter icon-plus-circled"></i>';
    newLine.querySelector(".user-ip").dataset.ip = line.IP;
    if (current_param.ip == "") {
        newLine.querySelector(".user-ip").addEventListener("click", function () {
            current_param.ip = this.dataset.ip;
            current_param.pageNumber = 0;
            addIpFilter(this.innerHTML);
            fillHistoryResult(current_param);
        });
    }

    newLine.querySelector(".add-img-as-filter").dataset.imgId = line.IMAGEID;
    if (current_param.image_id == "") {
        newLine.querySelector(".add-img-as-filter").addEventListener("click", function () {
            current_param.image_id = this.dataset.imgId;
            current_param.pageNumber = 0;
            addImageFilter(this.dataset.imgId);
            fillHistoryResult(current_param);
        });
    }

    if (line.EDIT_IMAGE != "") {
        newLine.querySelector(".edit-img").setAttribute("href", line.EDIT_IMAGE);
    } else {
        var editImg = newLine.querySelector(".edit-img");
        editImg.setAttribute("href", "#");
        editImg.classList.add("notClickable", "tiptip");
        editImg.setAttribute("title", str_no_longer_exist_photo);
        editImg.addEventListener("click", function (e) {
            e.preventDefault();
        });
    }

    switch (line.SECTION) {
        case "tags":
            if (line.TAGS.length > 1 && line.TAGS.length <= 2) {
                newLine
                    .querySelector(".type-name")
                    .innerHTML = line.TAGS[0] + ", " + line.TAGS[1] + ", ...";
                newLine
                    .querySelector(".type-id")
                    .innerHTML =
                    "#" + line.TAGIDS[0] + ", " + line.TAGIDS[1] + ", ...";
            } else if (line.TAGS.length > 2) {
                newLine
                    .querySelector(".type-name")
                    .innerHTML =
                    line.TAGS[0] +
                    ", " +
                    line.TAGS[1] +
                    ", " +
                    line.TAGS[2] +
                    ", ...";
                newLine
                    .querySelector(".type-id")
                    .innerHTML =
                    "#" +
                    line.TAGIDS[0] +
                    ", " +
                    line.TAGIDS[1] +
                    ", " +
                    line.TAGIDS[2] +
                    ", ...";
            } else {
                newLine.querySelector(".type-name").innerHTML = line.TAGS[0];
                newLine.querySelector(".type-id").innerHTML = "#" + line.TAGIDS[0];
            }

            let detail_str = "";
            line.TAGS.forEach((tag) => {
                detail_str += tag + ", ";
            });
            detail_str = detail_str.slice(0, -2);
            newLine.querySelector(".detail-item-1").innerHTML = detail_str;
            var detailItem = newLine.querySelector(".detail-item-1");
            detailItem.setAttribute("title", detail_str);
            detailItem.classList.remove("hide", "add");
            detailItem.classList.add("icon-tags");
            break;

        case "most_visited":
            newLine.querySelector(".type-name").innerHTML = str_most_visited;
            var detail = newLine.querySelector(".detail-item-1");
            detail.innerHTML = str_most_visited;
            detail.classList.add("icon-fire");
            newLine.querySelector(".type-id").style.display = 'none';
            break;
        case "best_rated":
            newLine.querySelector(".type-name").innerHTML = str_best_rated;
            var detail = newLine.querySelector(".detail-item-1");
            detail.innerHTML = str_best_rated;
            detail.classList.add("icon-star");
            newLine.querySelector(".type-id").style.display = 'none';
            break;
        case "list":
            newLine.querySelector(".type-name").innerHTML = str_list;
            var detail = newLine.querySelector(".detail-item-1");
            detail.innerHTML = str_list;
            detail.classList.add("icon-dice-solid");
            newLine.querySelector(".type-id").style.display = 'none';
            break;
        case "search":
            // for debug
            // console.log('search n° : ', line.SEARCH_ID, ' ', line.SEARCH_DETAILS);
            const search_details = line.SEARCH_DETAILS;
            const search_icons = {
                allwords: "gallery-icon-search",
                tags: "gallery-icon-tag",
                date_posted: "gallery-icon-calendar-plus",
                cat: "gallery-icon-album",
                author: "gallery-icon-user-edit",
                added_by: "gallery-icon-user",
                filetypes: "gallery-icon-file-image",
            };
            newLine.querySelector(".type-name").innerHTML = line.SECTION;
            newLine.querySelector(".type-id").innerHTML = "#" + line.SEARCH_ID;
            if (!line.SEARCH_ID) {
                newLine.querySelector(".type-id").style.display = 'none';
            }

            if (!search_details) {
                newLine.querySelector(".detail-item-1").style.display = 'none';
                break;
            }
            let active_search_details = {};
            Object.keys(search_details).forEach((key) => {
                if (search_details[key] !== null) {
                    active_search_details[key] = search_details[key];
                }
            });
            let count_item = 1;
            let active_more = [];
            const active_items = Object.keys(active_search_details);
            if (active_items.length > 0) {
                if (active_search_details.allwords) {
                    var detailEl = newLine.querySelector(".detail-item-" + count_item);
                    detailEl.innerHTML = active_search_details.allwords.join(" ");
                    detailEl.classList.add(search_icons.allwords, "tiptip");
                    detailEl.setAttribute(
                        "title",
                        "<b>" +
                        str_search_details["allwords"] +
                        " :</b> " +
                        active_search_details.allwords.join(" "),
                    );
                    count_item++;
                    active_more.push("allwords");
                }
                if (active_search_details.cat) {
                    const array_cat = Object.values(active_search_details.cat);
                    const cat = array_cat.join(" + ");
                    let temp_div = document.createElement("div");
                    temp_div.innerHTML = cat;
                    let text = temp_div.textContent.trim();
                    var detailEl = newLine.querySelector(".detail-item-" + count_item);
                    detailEl.innerHTML = cat;
                    detailEl.classList.add(search_icons.cat, "tiptip");
                    detailEl.setAttribute(
                        "title",
                        "<b>" +
                        str_search_details["cat"] +
                        " :</b> " +
                        text,
                    );
                    detailEl.classList.remove("hide");
                    count_item++;
                    active_more.push("cat");
                }
                if (count_item <= 2 && active_search_details.tags) {
                    const array_tags = Object.values(
                        active_search_details.tags,
                    );
                    var detailEl = newLine.querySelector(".detail-item-" + count_item);
                    detailEl.innerHTML = array_tags.join(" + ");
                    detailEl.classList.add(search_icons.tags, "tiptip");
                    detailEl.setAttribute(
                        "title",
                        "<b>" +
                        str_search_details["tags"] +
                        " :</b> " +
                        array_tags.join(" + "),
                    );
                    detailEl.classList.remove("hide");
                    count_item++;
                    active_more.push("tags");
                }
                if (count_item <= 2) {
                    let badge_to_add =
                        active_items.length == 1 ? 1 : count_item == 1 ? 2 : 1;
                    let badge_added = 0;
                    active_items.some((key) => {
                        if (
                            key !== "allwords" &&
                            key !== "cat" &&
                            key !== "tags"
                        ) {
                            let array_key;
                            if (Array.isArray(active_search_details[key])) {
                                array_key = active_search_details[key];
                            } else if (
                                typeof active_search_details[key] === "object"
                            ) {
                                array_key = Object.values(
                                    active_search_details[key],
                                );
                            } else {
                                array_key = [active_search_details[key]];
                            }
                            var detailEl = newLine.querySelector(".detail-item-" + count_item);
                            detailEl.innerHTML = array_key.join(" + ");
                            detailEl.classList.add(search_icons[key], "tiptip");
                            detailEl.setAttribute(
                                "title",
                                "<b>" +
                                str_search_details[key] +
                                " :</b> " +
                                array_key.join(" + "),
                            );
                            detailEl.classList.remove("hide");
                            count_item++;
                            badge_added++;
                            active_more.push(key);
                            if (badge_added === badge_to_add) {
                                return true;
                            }
                        }
                        return false;
                    });
                }
            } else {
                newLine.querySelector(".detail-item-1").style.display = 'none';
            }
            if (active_items.length >= 3) {
                let count_more = 0;
                let search_details_str = Object.entries(active_search_details)
                    .filter(([key]) => !active_more.includes(key))
                    .map(([key, value]) => {
                        let value_str;
                        if (Array.isArray(value)) {
                            value_str = value.join(" + ");
                        } else if (typeof value === "object") {
                            value_str = Object.entries(value)
                                .map(([k, v]) => v)
                                .join(" + ");
                        } else {
                            value_str = value;
                        }

                        if (key == "cat") {
                            let temp_div = document.createElement("div");
                            temp_div.innerHTML = value_str;
                            let text = temp_div.textContent.trim();
                            value_str = text;
                        }
                        count_more++;
                        return `<b>${str_search_details[key]}</b> : ${value_str}`;
                    })
                    .join(" <br />");
                var detailItem = newLine.querySelector(".detail-item-3");
                detailItem.innerHTML = sprintf(str_and_more, count_more);
                detailItem.classList.add("icon-info-circled-1", "tiptip");
                detailItem.setAttribute("title", search_details_str);
                detailItem.classList.remove("hide");
            }
            break;
        case "favorites":
            newLine.querySelector(".type-name").innerHTML = str_favorites;
            var detail = newLine.querySelector(".detail-item-1");
            detail.innerHTML = str_favorites;
            detail.classList.add("icon-heart");
            newLine.querySelector(".type-id").style.display = 'none';
            break;
        case "recent_cats":
            newLine.querySelector(".type-name").innerHTML = str_recent_cats;
            var detail = newLine.querySelector(".detail-item-1");
            detail.innerHTML = str_recent_cats;
            detail.classList.add("icon-clock");
            newLine.querySelector(".type-id").style.display = 'none';
            break;
        case "recent_pics":
            newLine.querySelector(".type-name").innerHTML = str_recent_pics;
            var detail = newLine.querySelector(".detail-item-1");
            detail.innerHTML = str_recent_pics;
            detail.classList.add("icon-clock");
            newLine.querySelector(".type-id").style.display = 'none';
            break;
        case "categories":
            newLine.querySelector(".type-name").innerHTML = line.CATEGORY;
            var detail = newLine.querySelector(".detail-item-1");
            detail.innerHTML = line.CATEGORY;
            detail.classList.add("icon-folder-open", "tiptip");
            detail.setAttribute("title", line.FULL_CATEGORY_PATH);
            if (line.IMAGE == "") {
                newLine.querySelector(".type-id").style.display = 'none';
            }
            break;
        case "memories-1-year-ago":
            newLine.querySelector(".type-name").innerHTML = str_memories;
            var detail = newLine.querySelector(".detail-item-1");
            detail.innerHTML = str_memories;
            detail.classList.add("icon-clock");
            newLine.querySelector(".type-id").style.display = 'none';
            break;
        case "contact":
            newLine
                .querySelector(".type-icon i")
                .classList.add("line-icon", "icon-mail-1", "icon-yellow");
            newLine.querySelector(".type-name").innerHTML = str_contact_form;
            newLine.querySelector(".detail-item-1").innerHTML = str_contact_form;
            newLine.querySelector(".type-id").style.display = 'none';
            break;
        default:
            newLine
                .querySelector(".type-icon i")
                .classList.add("line-icon", "icon-help-puzzle", "icon-grey");
            newLine.querySelector(".type-name").innerHTML = line.SECTION;
            newLine.querySelector(".type-id").style.display = 'none';
            break;
    }

    if (line.IMAGE != "") {
        newLine.querySelector(".type-name").innerHTML = line.IMAGENAME;
        newLine.querySelector(".type-icon").innerHTML = line.IMAGE;
        newLine.querySelector(".type-id").innerHTML = "#" + line.IMAGEID;
        newLine
            .querySelector(".type-icon")
            .setAttribute("href", line.EDIT_IMAGE);
        newLine.querySelector(".type-icon").classList.remove("no-img");
        newLine
            .querySelector(".type-icon img")
            .setAttribute("title", str_edit_img);
        newLine.querySelector(".type-icon img").classList.add("tiptip");
        newLine.querySelector(".type-id").style.display = '';
    } else {
        var iconEl = newLine.querySelector(".type-icon .icon-file-image");
        if (iconEl) iconEl.classList.remove("icon-file-image");
        var toggleOption = newLine.querySelector(".toggle-img-option");
        if (toggleOption) toggleOption.style.display = 'none';

        if (sections.indexOf(line.SECTION) != -1) {
            var lineIconClass = icons[sections.indexOf(line.SECTION)];
            newLine.querySelector(".type-icon i").classList.add(...lineIconClass.split(" "));
        } else {
            console.log("Unhandled section : " + line.SECTION);
        }
    }

    newLine.querySelector(".detail-item-1").classList.remove("hide");
    if (line.TYPE == "high") {
        var detailItem = newLine.querySelector(".detail-item-1");
        detailItem.innerHTML = str_dwld;
        detailItem.classList.add("icon-blue");
        detailItem.classList.remove("detail-item-1", "hide");
        newLine.querySelector(".date-dwld-icon").classList.add("icon-blue", "icon-floppy");
    } else {
        var dwldIcon = newLine.querySelector(".date-dwld-icon");
        if (dwldIcon) dwldIcon.remove();
    }
    displayLine(newLine);
}

function displayLine(line) {
    document.querySelector(".tab").appendChild(line);
}

function addUserFilter(username) {
    var newFilter = document.getElementById("default-filter").cloneNode(true);
    newFilter.classList.remove("hide");

    newFilter.querySelector(".filter-title").innerHTML = username;
    newFilter.querySelector(".filter-icon").classList.add("icon-user");

    newFilter.querySelector(".remove-filter").addEventListener("click", function () {
        this.parentElement.remove();

        current_param.user_id = "-1";
        current_param.pageNumber = 0;
        fillHistoryResult(current_param);
        checkFilters();
        document.querySelector(".summary-guests").style.display = '';
    });

    document.querySelector(".summary-guests").style.display = 'none';
    document.querySelector(".filter-container").appendChild(newFilter);
    checkFilters();
}

function addGuestFilter(username) {
    var newFilter = document.getElementById("default-filter").cloneNode(true);
    newFilter.classList.remove("hide");

    newFilter.querySelector(".filter-title").innerHTML = username;
    newFilter.querySelector(".filter-icon").classList.add("icon-user-secret");

    newFilter.querySelector(".remove-filter").addEventListener("click", function () {
        this.parentElement.remove();

        current_param.user_id = "-1";
        current_param.pageNumber = 0;
        fillHistoryResult(current_param);
        checkFilters();
    });

    document.querySelector(".filter-container").appendChild(newFilter);
    checkFilters();
}

function addIpFilter(ip) {
    var newFilter = document.getElementById("default-filter").cloneNode(true);
    newFilter.classList.remove("hide");

    newFilter.querySelector(".filter-title").innerHTML = ip;
    newFilter.querySelector(".filter-icon").innerHTML = "IP ";
    newFilter.querySelector(".filter-icon").classList.add("bold");

    newFilter.querySelector(".remove-filter").addEventListener("click", function () {
        this.parentElement.remove();

        current_param.ip = "";
        current_param.pageNumber = 0;
        fillHistoryResult(current_param);
        checkFilters();
    });

    document.querySelector(".filter-container").appendChild(newFilter);
    checkFilters();
}

function addImageFilter(img_id) {
    var newFilter = document.getElementById("default-filter").cloneNode(true);
    newFilter.classList.remove("hide");

    newFilter.querySelector(".filter-title").innerHTML = "Image #" + img_id;
    newFilter.querySelector(".filter-icon").classList.add("icon-picture");

    newFilter.querySelector(".remove-filter").addEventListener("click", function () {
        this.parentElement.remove();

        current_param.image_id = "";
        current_param.pageNumber = 0;
        fillHistoryResult(current_param);
        checkFilters();
    });

    document.querySelector(".filter-container").appendChild(newFilter);
    checkFilters();
}

function updateArrows(actualPage, maxPage) {
    if (actualPage == 0) {
        document.querySelector(".pagination-arrow.left").classList.add("unavailable");
    } else {
        document.querySelector(".pagination-arrow.left").classList.remove("unavailable");
    }

    if (actualPage == maxPage - 1) {
        document.querySelector(".pagination-arrow.right").classList.add("unavailable");
    } else {
        document.querySelector(".pagination-arrow.right").classList.remove("unavailable");
    }
}

function updatePagination(maxPage) {
    updateArrows(current_param.pageNumber, maxPage);

    document.querySelector(".pagination-item-container").innerHTML = '';
    document.querySelector(".pagination-item-container").insertAdjacentHTML(
        "beforeend",
        "<a class='actual'>" +
        (current_param.pageNumber + 1) +
        "/" +
        maxPage +
        "</a>",
    );
}

function checkFilters() {
    if (document.querySelector(".filter-container").childElementCount - 1 > 0) {
        //Check if there are filters
        document.querySelector(".filter-tags label").style.display = '';
    } else {
        document.querySelector(".filter-tags label").style.display = 'none';
    }
}
