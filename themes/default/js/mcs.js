export function initMCS() {
function toggleEl(el, callback) {
    var isHidden = window.getComputedStyle(el).display === 'none';
    el.style.display = isHidden ? '' : 'none';
    if (callback) callback.call(el);
}

function isVisible(el) {
    return el && window.getComputedStyle(el).display !== 'none';
}

related_categories_ids = [];

document.querySelectorAll(".linkedAlbumPopInContainer .ClosePopIn").forEach(function (el) {
    el.classList.add(prefix_icon + "cancel");
});
var linkedAlbumSearching = document.querySelector(".linkedAlbumPopInContainer .searching");
if (linkedAlbumSearching) linkedAlbumSearching.style.display = 'none';

document.querySelectorAll(".filter-validate").forEach(function (btn) {
    btn.addEventListener("click", function () {
        var loading = this.querySelector(".loading");
        var validateText = this.querySelector(".validate-text");
        if (loading) loading.style.display = 'block';
        if (validateText) validateText.style.display = 'none';
    });
});

// If we open another filter, hide all other dropdowns except the one just opened
document.querySelectorAll("div.filter").forEach(function (filterEl) {
    filterEl.addEventListener("click", function () {
        var self = this;
        Array.from(this.parentElement.children).forEach(function (sib) {
            if (sib === self) return;
            sib.classList.remove("show-filter-dropdown");
            sib.querySelectorAll("div.filter-form").forEach(function (form) {
                form.style.display = 'none';
            });
        });
    });
});

// If we open the choose filters modal hide all filter forms if any open
document.querySelectorAll("div.filter-manager").forEach(function (el) {
    el.addEventListener("click", function () {
        document.querySelectorAll("div.filter div.filter-form").forEach(function (form) {
            form.style.display = 'none';
        });
    });
});

global_params.search_id = search_id;

if (!global_params.fields) {
    global_params.fields = {};
}

// Declare params sent to pwg.images.filteredSearch.update
PS_params = {};
PS_params.search_id = search_id;
empty_filters_list = [];

// Setup word filter
if (global_params.fields.allwords) {
    var filterWord = document.querySelector(".filter-word");
    if (filterWord) filterWord.style.display = 'flex';
    var wordController = document.querySelector(".filter-manager-controller.word");
    if (wordController) wordController.checked = true;

    word_search_str = "";
    word_search_words =
        global_params.fields.allwords.words != null
            ? global_params.fields.allwords.words
            : [];
    word_search_words.forEach(function (word) { word_search_str += word + " "; });
    var wordSearchEl = document.getElementById("word-search");
    if (wordSearchEl) wordSearchEl.value = word_search_str.slice(0, -1);

    var filterWordSearchWords = document.querySelector(".filter-word .search-words");
    if (
        global_params.fields.allwords.words &&
        global_params.fields.allwords.words.length > 0
    ) {
        if (filterWord) filterWord.classList.add("filter-filled");
        if (filterWordSearchWords) filterWordSearchWords.innerHTML = word_search_str.slice(0, -1);
    } else {
        if (filterWordSearchWords) filterWordSearchWords.innerHTML = str_word_widget_label;
    }

    word_search_fields = global_params.fields.allwords.fields;
    Object.keys(word_search_fields).forEach(function (field_key) {
        var el = document.getElementById(word_search_fields[field_key]);
        if (el) el.checked = true;
    });

    word_search_mode = global_params.fields.allwords.mode;
    var wordModeInput = document.querySelector(".word-search-options input[value=" + word_search_mode + "]");
    if (wordModeInput) wordModeInput.checked = true;

    if (global_params.fields.search_in_tags) {
        var tagsCheckbox = document.getElementById("tags");
        if (tagsCheckbox) tagsCheckbox.checked = true;
    }

    document.querySelectorAll(".filter-word .filter-actions .clear").forEach(function (btn) {
        btn.addEventListener("click", function () {
            var ws = document.querySelector(".filter-word #word-search");
            if (ws) ws.value = "";
            document.querySelectorAll(".filter-word .search-params input").forEach(function (inp) { inp.checked = true; });
            var andInput = document.querySelector(".filter-word .word-search-options input[value='AND']");
            if (andInput) andInput.checked = true;
        });
    });

    PS_params.allwords = word_search_str.slice(0, -1);
    PS_params.allwords_fields = word_search_fields;
    PS_params.allwords_mode = word_search_mode;

    empty_filters_list.push(PS_params.allwords);
}
// Hide filter spinner
document.querySelectorAll(".filter-spinner").forEach(function (el) { el.style.display = 'none'; });

// Setup tag filter
var tagSearchEl = document.getElementById("tag-search");
if (tagSearchEl) {
    new TomSelect(tagSearchEl, {
        plugins: ["remove_button"],
        maxOptions: tagSearchEl.querySelectorAll("option").length,
        items: global_params.fields.tags ? global_params.fields.tags.words : null,
    });
}
if (global_params.fields.tags) {
    var filterTag = document.querySelector(".filter-tag");
    if (filterTag) filterTag.style.display = 'flex';
    var tagsController = document.querySelector(".filter-manager-controller.tags");
    if (tagsController) tagsController.checked = true;
    var tagsModeInput = document.querySelector(
        ".filter-tag-form .search-params input[value=" + global_params.fields.tags.mode + "]"
    );
    if (tagsModeInput) tagsModeInput.checked = true;

    tag_search_str = "";
    if (tagSearchEl && tagSearchEl.tomselect) {
        tagSearchEl.tomselect.getValue().forEach(function (id) {
            var item = tagSearchEl.tomselect.getItem(id);
            var itemText = item ? item.textContent : '';
            tag_search_str += itemText.replace(/\(\d+ \w+\)×/, "").trim() + ", ";
        });
    }
    var filterTagSearchWords = document.querySelector(".filter.filter-tag .search-words");
    if (
        global_params.fields.tags.words &&
        global_params.fields.tags.words.length > 0
    ) {
        if (filterTag) filterTag.classList.add("filter-filled");
        if (filterTagSearchWords) filterTagSearchWords.textContent = tag_search_str.slice(0, -2);
    } else {
        if (filterTagSearchWords) filterTagSearchWords.textContent = str_tags_widget_label;
    }

    document.querySelectorAll(".filter-tag .filter-actions .clear").forEach(function (btn) {
        btn.addEventListener("click", function () {
            if (tagSearchEl && tagSearchEl.tomselect) tagSearchEl.tomselect.clear();
            var andInput = document.querySelector(".filter-tag .search-params input[value='AND']");
            if (andInput) andInput.checked = true;
        });
    });

    PS_params.tags =
        global_params.fields.tags.words.length > 0
            ? global_params.fields.tags.words
            : "";
    PS_params.tags_mode = global_params.fields.tags.mode;

    empty_filters_list.push(PS_params.tags);
}

// Setup Date post filter
if (global_params.fields.hasOwnProperty("date_posted")) {
    var filterDatePosted = document.querySelector(".filter-date_posted");
    if (filterDatePosted) filterDatePosted.style.display = 'flex';
    var datePostedController = document.querySelector(".filter-manager-controller.date_posted");
    if (datePostedController) datePostedController.checked = true;

    if (
        global_params.fields.date_posted != null &&
        global_params.fields.date_posted != ""
    ) {
        var datePostedInput = document.getElementById("date_posted-" + global_params.fields.date_posted);
        if (datePostedInput) datePostedInput.checked = true;

        var datePeriodEl = document.querySelector(
            ".date_posted-option label#" + global_params.fields.date_posted + " .date-period"
        );
        date_posted_str = datePeriodEl ? datePeriodEl.textContent : "";
        if (filterDatePosted) filterDatePosted.classList.add("filter-filled");
        var filterDatePostedSearchWords = document.querySelector(".filter.filter-date_posted .search-words");
        if (filterDatePostedSearchWords) filterDatePostedSearchWords.textContent = date_posted_str;
    }

    document.querySelectorAll(".filter-date_posted .filter-actions .clear").forEach(function (btn) {
        btn.addEventListener("click", function () {
            document.querySelectorAll(".date_posted-option input").forEach(function (inp) { inp.checked = false; });
        });
    });

    PS_params.date_posted =
        global_params.fields.date_posted.length > 0
            ? global_params.fields.date_posted
            : "";

    empty_filters_list.push(PS_params.date_posted);
}

// Setup album filter
if (global_params.fields.cat) {
    var filterAlbum = document.querySelector(".filter-album");
    if (filterAlbum) filterAlbum.style.display = 'flex';
    var albumController = document.querySelector(".filter-manager-controller.album");
    if (albumController) albumController.checked = true;

    album_widget_value = "";
    global_params.fields.cat.words.forEach(function (cat_id) {
        add_related_category(cat_id, fullname_of_cat[cat_id]);
        album_widget_value += fullname_of_cat[cat_id] + ", ";
    });
    var filterAlbumSearchWords = document.querySelector(".filter-album .search-words");
    if (
        global_params.fields.cat.words &&
        global_params.fields.cat.words.length > 0
    ) {
        if (filterAlbum) filterAlbum.classList.add("filter-filled");
        if (filterAlbumSearchWords) filterAlbumSearchWords.innerHTML = album_widget_value.slice(0, -2);
    } else {
        if (filterAlbumSearchWords) filterAlbumSearchWords.innerHTML = str_album_widget_label;
    }

    if (global_params.fields.cat.sub_inc) {
        var searchSubCats = document.getElementById("search-sub-cats");
        if (searchSubCats) searchSubCats.checked = true;
    }

    document.querySelectorAll(".filter-album .filter-actions .clear").forEach(function (btn) {
        btn.addEventListener("click", function () {
            related_categories_ids = [];
            var selectedCatsContainer = document.querySelector(".selected-categories-container");
            if (selectedCatsContainer) selectedCatsContainer.innerHTML = '';
            var searchSubCats = document.getElementById("search-sub-cats");
            if (searchSubCats) searchSubCats.checked = false;
        });
    });

    PS_params.categories =
        global_params.fields.cat.words.length > 0
            ? global_params.fields.cat.words
            : "";
    PS_params.categories_withsubs = global_params.fields.cat.sub_inc;

    empty_filters_list.push(PS_params.categories);
}

// Setup author filter
var authorsEl = document.getElementById("authors");
if (authorsEl) {
    new TomSelect(authorsEl, {
        plugins: ["remove_button"],
        maxOptions: authorsEl.querySelectorAll("option").length,
        items: global_params.fields.author ? global_params.fields.author.words : null,
    });
    if (global_params.fields.author) {
        var filterAuthors = document.querySelector(".filter-authors");
        if (filterAuthors) filterAuthors.style.display = 'flex';
        var authorController = document.querySelector(".filter-manager-controller.author");
        if (authorController) authorController.checked = true;

        author_search_str = "";
        if (authorsEl.tomselect) {
            authorsEl.tomselect.getValue().forEach(function (id) {
                var item = authorsEl.tomselect.getItem(id);
                var itemText = item ? item.textContent : '';
                author_search_str += itemText.replace(/\(\d+ \w+\)×/, "").trim() + ", ";
            });
        }

        var filterAuthorsSearchWords = document.querySelector(".filter.filter-authors .search-words");
        if (
            global_params.fields.author.words &&
            global_params.fields.author.words.length > 0
        ) {
            if (filterAuthors) filterAuthors.classList.add("filter-filled");
            if (filterAuthorsSearchWords) filterAuthorsSearchWords.textContent = author_search_str.slice(0, -2);
        } else {
            if (filterAuthorsSearchWords) filterAuthorsSearchWords.textContent = str_author_widget_label;
        }

        document.querySelectorAll(".filter-authors .filter-actions .clear").forEach(function (btn) {
            btn.addEventListener("click", function () {
                if (authorsEl.tomselect) authorsEl.tomselect.clear();
            });
        });

        PS_params.authors =
            global_params.fields.author.words.length > 0
                ? global_params.fields.author.words
                : "";

        empty_filters_list.push(PS_params.authors);
    }
}

// Setup added_by filter
if (global_params.fields.added_by) {
    var filterAddedBy = document.querySelector(".filter-added_by");
    if (filterAddedBy) filterAddedBy.style.display = 'flex';
    var addedByController = document.querySelector(".filter-manager-controller.added_by");
    if (addedByController) addedByController.checked = true;

    if (
        global_params.fields.added_by &&
        global_params.fields.added_by.length > 0
    ) {
        if (filterAddedBy) filterAddedBy.classList.add("filter-filled");

        added_by_names = [];

        document.querySelectorAll(".added_by-option").forEach(function (option) {
            var input = option.querySelector("input");
            if (!input) return;
            var added_by_id = parseInt(input.getAttribute("name"));
            if (global_params.fields.added_by.includes(added_by_id)) {
                input.checked = true;
                var nameEl = option.querySelector(".added_by-name");
                if (nameEl) added_by_names.push(nameEl.textContent);
            }
        });

        var filterAddedBySearchWords = document.querySelector(".filter.filter-added_by .search-words");
        if (filterAddedBySearchWords) filterAddedBySearchWords.textContent = added_by_names.join(", ");
    } else {
        var filterAddedBySearchWords2 = document.querySelector(".filter.filter-added_by .search-words");
        if (filterAddedBySearchWords2) filterAddedBySearchWords2.textContent = str_added_by_widget_label;
    }

    document.querySelectorAll(".filter-added_by .filter-actions .clear").forEach(function (btn) {
        btn.addEventListener("click", function () {
            document.querySelectorAll(".filter-added_by .added_by-option input").forEach(function (inp) { inp.checked = false; });
        });
    });

    PS_params.added_by =
        global_params.fields.added_by.length > 0
            ? global_params.fields.added_by
            : "";

    empty_filters_list.push(PS_params.added_by);
}

// Setup filetypes filter
if (global_params.fields.filetypes) {
    var filterFiletypes = document.querySelector(".filter-filetypes");
    if (filterFiletypes) filterFiletypes.style.display = 'flex';
    var filetypesController = document.querySelector(".filter-manager-controller.filetypes");
    if (filetypesController) filetypesController.checked = true;

    filetypes_search_str = "";
    global_params.fields.filetypes.forEach(function (ft) { filetypes_search_str += ft + ", "; });

    var filterFiletypesSearchWords = document.querySelector(".filter.filter-filetypes .search-words");
    if (
        global_params.fields.filetypes &&
        global_params.fields.filetypes.length > 0
    ) {
        if (filterFiletypes) filterFiletypes.classList.add("filter-filled");
        if (filterFiletypesSearchWords)
            filterFiletypesSearchWords.textContent = filetypes_search_str.toUpperCase().slice(0, -2);

        document.querySelectorAll(".filetypes-option input").forEach(function (inp) {
            if (global_params.fields.filetypes.includes(inp.getAttribute("name"))) {
                inp.checked = true;
            }
        });
    } else {
        if (filterFiletypesSearchWords) filterFiletypesSearchWords.textContent = str_filetypes_widget_label;
    }

    document.querySelectorAll(".filter-filetypes .filter-actions .clear").forEach(function (btn) {
        btn.addEventListener("click", function () {
            document.querySelectorAll(".filter-filetypes .filetypes-option input").forEach(function (inp) { inp.checked = false; });
        });
    });

    PS_params.filetypes =
        global_params.fields.filetypes.length > 0
            ? global_params.fields.filetypes
            : "";

    empty_filters_list.push(PS_params.filetypes);
}

// Adapt no result message
if (document.querySelectorAll(".filter-filled").length === 0) {
    var noResultTop = document.querySelector(".mcs-no-result .text .top");
    var noResultBot = document.querySelector(".mcs-no-result .text .bot");
    if (noResultTop) noResultTop.innerHTML = str_empty_search_top_alt;
    if (noResultBot) noResultBot.innerHTML = str_empty_search_bot_alt;
}

if (!empty_filters_list.every(function (param) { return param === "" || param === null; })) {
    var clearAll = document.querySelector(".clear-all");
    if (clearAll) {
        clearAll.classList.add("clickable");
        document.querySelector(".clear-all.clickable").addEventListener("click", function () {
            var exclude_params = [
                "search_id",
                "allwords_mode",
                "allwords_fields",
                "tags_mode",
                "categories_withsubs",
            ];
            for (var key in PS_params) {
                if (!exclude_params.includes(key)) {
                    PS_params[key] = "";
                }
            }
            performSearch(PS_params, true);
        });
    }
}

/**
 * Filter Manager
 */
document.querySelectorAll(".filter-manager").forEach(function (el) {
    el.addEventListener("click", function () {
        var popin = document.querySelector(".filter-manager-popin");
        if (popin) popin.style.display = 'block';
    });
});

document.addEventListener("keyup", function (e) {
    // 27 is 'Escape'
    if (e.keyCode === 27) {
        var fmClose = document.querySelector(".filter-manager-popin .filter-manager-close");
        if (fmClose) fmClose.click();
        var tagsClose = document.querySelector(".tags-found-popin .tags-found-close");
        if (tagsClose) tagsClose.click();
        var albumsClose = document.querySelector(".albums-found-popin .albums-found-close");
        if (albumsClose) albumsClose.click();
    }
    // 13 is 'Enter'
    if (e.keyCode === 13) {
        document.querySelectorAll(".filter-form .filter-validate").forEach(function (el) {
            if (isVisible(el)) el.click();
        });
    }
});

var filterManagerPopin = document.querySelector(".filter-manager-popin");
if (filterManagerPopin) {
    filterManagerPopin.addEventListener("click", function (e) {
        if (e.target === this) {
            var fmClose = this.querySelector(".filter-manager-close");
            if (fmClose) fmClose.click();
        }
    });
}

document.querySelectorAll(
    ".filter-manager-popin .filter-cancel, .filter-manager-popin .filter-manager-close"
).forEach(function (btn) {
    btn.addEventListener("click", function () {
        if (filterManagerPopin) filterManagerPopin.style.display = 'none';
        document.querySelectorAll(".filter-manager-controller-container input").forEach(function (input) {
            var filterEl = document.querySelector(".filter.filter-" + input.dataset.wid);
            if (input.checked) {
                if (filterEl && !isVisible(filterEl)) {
                    input.checked = false;
                }
            } else {
                if (filterEl && isVisible(filterEl)) {
                    input.checked = true;
                }
            }
        });
    });
});

var fmValidate = document.querySelector(".filter-manager-popin .filter-validate");
if (fmValidate) {
    fmValidate.addEventListener("click", function () {
        document.querySelectorAll(".filter-manager-controller-container input").forEach(function (input) {
            var filterEl = document.querySelector(".filter.filter-" + input.dataset.wid);
            if (input.checked) {
                if (filterEl && !isVisible(filterEl)) {
                    updateFilters(input.dataset.wid, "add");
                }
            } else {
                if (filterEl && isVisible(filterEl)) {
                    console.log(input.dataset.wid);
                    updateFilters(input.dataset.wid, "del");
                }
            }
        });
        // Set second param to true to trigger reload
        performSearch(PS_params, true);
    });
}

/**
 * Tags & Albums found
 */
document.querySelectorAll(".mcs-tags-found").forEach(function (el) {
    el.addEventListener("click", function () {
        var popin = document.querySelector(".tags-found-popin");
        if (popin) popin.style.display = 'block';
    });
});
document.querySelectorAll(".mcs-albums-found").forEach(function (el) {
    el.addEventListener("click", function () {
        var popin = document.querySelector(".albums-found-popin");
        if (popin) popin.style.display = 'block';
    });
});

var tagsFoundPopin = document.querySelector(".tags-found-popin");
if (tagsFoundPopin) {
    tagsFoundPopin.addEventListener("click", function (e) {
        if (e.target === this) {
            var closeBtn = this.querySelector(".tags-found-close");
            if (closeBtn) closeBtn.click();
        }
    });
}
document.querySelectorAll(".tags-found-close").forEach(function (btn) {
    btn.addEventListener("click", function () {
        if (tagsFoundPopin) tagsFoundPopin.style.display = 'none';
    });
});

var albumsFoundPopin = document.querySelector(".albums-found-popin");
if (albumsFoundPopin) {
    albumsFoundPopin.addEventListener("click", function (e) {
        if (e.target === this) {
            var closeBtn = this.querySelector(".albums-found-close");
            if (closeBtn) closeBtn.click();
        }
    });
}
document.querySelectorAll(".albums-found-close").forEach(function (btn) {
    btn.addEventListener("click", function () {
        if (albumsFoundPopin) albumsFoundPopin.style.display = 'none';
    });
});

/**
 * Filter Word
 */
var filterWordEl = document.querySelector(".filter-word");
if (filterWordEl) {
    filterWordEl.addEventListener("click", function (e) {
        if (
            e.target.closest(".filter-form") !== null ||
            e.target.classList.contains("filter-form")
        ) {
            return;
        }
        var filterWordForm = document.querySelector(".filter-word-form");
        if (!filterWordForm) return;
        toggleEl(filterWordForm, function () {
            if (isVisible(this)) {
                filterWordEl.classList.add("show-filter-dropdown");
                var wordSearchInput = document.getElementById("word-search");
                if (wordSearchInput) wordSearchInput.focus();
            } else {
                filterWordEl.classList.remove("show-filter-dropdown");

                global_params.fields.allwords = {};
                var wordSearchInput = document.getElementById("word-search");
                global_params.fields.allwords.words = wordSearchInput ? wordSearchInput.value : '';
                var wordModeChecked = document.querySelector(".word-search-options input:checked");
                global_params.fields.allwords.mode = wordModeChecked ? wordModeChecked.getAttribute("value") : '';

                PS_params.allwords = wordSearchInput ? wordSearchInput.value : '';
                PS_params.allwords_mode = wordModeChecked ? wordModeChecked.getAttribute("value") : '';

                new_fields = [];
                document.querySelectorAll(".filter-word-form .search-params input:checked").forEach(function (inp) {
                    if (inp.getAttribute("name") == "tags") {
                        global_params.fields.search_in_tags = true;
                    }
                    new_fields.push(inp.getAttribute("name"));
                });
                if (
                    !document.querySelector(".filter-word-form .search-params input[name='tags']:checked")
                ) {
                    delete global_params.fields.search_in_tags;
                }
                global_params.fields.allwords.fields = new_fields;
                PS_params.allwords_fields =
                    new_fields.length > 0 ? new_fields : "";
            }
        });
    });
}
document.querySelectorAll(".filter-word .filter-validate").forEach(function (btn) {
    btn.addEventListener("click", function () {
        if (filterWordEl) filterWordEl.click();
        performSearch(PS_params, true);
    });
});
document.querySelectorAll(".filter-word .filter-actions .delete").forEach(function (btn) {
    btn.addEventListener("click", function () {
        updateFilters("word", "del");
        performSearch(PS_params, true);
        if (filterWordEl && !filterWordEl.classList.contains("filter-filled")) {
            filterWordEl.style.display = 'none';
            var wordController = document.querySelector(".filter-manager-controller.word");
            if (wordController) wordController.checked = false;
        }
    });
});

/**
 * Filter Tag
 */
var filterTagEl = document.querySelector(".filter-tag");
if (filterTagEl) {
    filterTagEl.addEventListener("click", function (e) {
        if (
            e.target.closest(".filter-form") !== null ||
            e.target.classList.contains("filter-form") ||
            e.target.classList.contains("remove")
        ) {
            return;
        }
        var filterTagForm = document.querySelector(".filter-tag-form");
        if (!filterTagForm) return;
        toggleEl(filterTagForm, function () {
            if (isVisible(this)) {
                filterTagEl.classList.add("show-filter-dropdown");
            } else {
                filterTagEl.classList.remove("show-filter-dropdown");
                global_params.fields.tags = {};
                var tagModeChecked = document.querySelector(".filter-tag-form .search-params input:checked");
                global_params.fields.tags.mode = tagModeChecked ? tagModeChecked.value : '';
                var tagVals = tagSearchEl && tagSearchEl.tomselect ? tagSearchEl.tomselect.getValue() : [];
                global_params.fields.tags.words = tagVals;

                PS_params.tags = tagVals.length > 0 ? tagVals : "";
                PS_params.tags_mode = tagModeChecked ? tagModeChecked.value : '';
            }
        });
    });
}
document.querySelectorAll(".filter-tag .filter-validate").forEach(function (btn) {
    btn.addEventListener("click", function () {
        if (filterTagEl) filterTagEl.click();
        performSearch(PS_params, true);
    });
});
document.querySelectorAll(".filter-tag .filter-actions .delete").forEach(function (btn) {
    btn.addEventListener("click", function () {
        updateFilters("tag", "del");
        performSearch(PS_params, true);
        if (filterTagEl && !filterTagEl.classList.contains("filter-filled")) {
            filterTagEl.style.display = 'none';
            var tagsController = document.querySelector(".filter-manager-controller.tags");
            if (tagsController) tagsController.checked = false;
        }
    });
});

/**
 * Filter Date posted
 */
var filterDatePostedEl = document.querySelector(".filter-date_posted");
if (filterDatePostedEl) {
    filterDatePostedEl.addEventListener("click", function (e) {
        if (
            e.target.closest(".filter-form") !== null ||
            e.target.classList.contains("filter-form")
        ) {
            return;
        }
        var filterDateForm = document.querySelector(".filter-date_posted-form");
        if (!filterDateForm) return;
        toggleEl(filterDateForm, function () {
            if (isVisible(this)) {
                filterDatePostedEl.classList.add("show-filter-dropdown");
            } else {
                filterDatePostedEl.classList.remove("show-filter-dropdown");

                var dateChecked = document.querySelector(".date_posted-option input:checked");
                global_params.fields.date_posted = dateChecked ? dateChecked.value : '';

                PS_params.date_posted =
                    dateChecked ? dateChecked.value : "";
            }
        });
    });
}

document.querySelectorAll(".filter-date_posted .filter-validate").forEach(function (btn) {
    btn.addEventListener("click", function () {
        if (filterDatePostedEl) filterDatePostedEl.click();
        performSearch(PS_params, true);
    });
});
document.querySelectorAll(".filter-date_posted .filter-actions .delete").forEach(function (btn) {
    btn.addEventListener("click", function () {
        updateFilters("date_posted", "del");
        performSearch(PS_params, true);
        if (filterDatePostedEl && !filterDatePostedEl.classList.contains("filter-filled")) {
            filterDatePostedEl.style.display = 'none';
            var dateController = document.querySelector(".filter-manager-controller.date");
            if (dateController) dateController.checked = false;
        }
    });
});

/**
 * Filter Album
 */
var filterAlbumEl = document.querySelector(".filter-album");
if (filterAlbumEl) {
    filterAlbumEl.addEventListener("click", function (e) {
        if (
            e.target.closest(".filter-form") !== null ||
            e.target.classList.contains("filter-form") ||
            e.target.classList.contains("remove-item")
        ) {
            return;
        }
        var filterAlbumForm = document.querySelector(".filter-album-form");
        if (!filterAlbumForm) return;
        toggleEl(filterAlbumForm, function () {
            if (isVisible(this)) {
                filterAlbumEl.classList.add("show-filter-dropdown");
            } else {
                filterAlbumEl.classList.remove("show-filter-dropdown");
                global_params.fields.cat = {};
                global_params.fields.cat.words = related_categories_ids;
                var subCatsChecked = document.querySelector("input[name='search-sub-cats']:checked");
                global_params.fields.cat.sub_inc = subCatsChecked !== null;

                PS_params.categories =
                    related_categories_ids.length > 0
                        ? related_categories_ids
                        : "";
                PS_params.categories_withsubs = subCatsChecked !== null;
            }
        });
    });
}
document.querySelectorAll(".filter-album .filter-validate").forEach(function (btn) {
    btn.addEventListener("click", function () {
        if (filterAlbumEl) filterAlbumEl.click();
        performSearch(PS_params, true);
    });
});
document.querySelectorAll(".filter-album .filter-actions .delete").forEach(function (btn) {
    btn.addEventListener("click", function () {
        updateFilters("album", "del");
        performSearch(PS_params, true);
        if (filterAlbumEl && !filterAlbumEl.classList.contains("filter-filled")) {
            filterAlbumEl.style.display = 'none';
            var albumController = document.querySelector(".filter-manager-controller.album");
            if (albumController) albumController.checked = false;
        }
    });
});

document.querySelectorAll(".add-album-button").forEach(function (btn) {
    btn.addEventListener("click", function () {
        linked_albums_open();
        set_up_popin();
    });
});

var linkedAlbumSearchInput = document.querySelector("#linkedAlbumSearch .search-input");
if (linkedAlbumSearchInput) {
    linkedAlbumSearchInput.addEventListener("input", function () {
        var cancelBtn = document.querySelector("#linkedAlbumSearch .search-cancel-linked-album");
        if (this.value != 0) {
            if (cancelBtn) cancelBtn.style.display = '';
        } else {
            if (cancelBtn) cancelBtn.style.display = 'none';
        }

        // Search input value length required to start searching
        if (this.value.length > 0) {
            linked_albums_search(this.value);
        } else {
            var limitReached = document.querySelector(".limitReached");
            if (limitReached) limitReached.innerHTML = str_no_search_in_progress;
            var searchResult = document.getElementById("searchResult");
            if (searchResult) searchResult.innerHTML = '';
        }
    });
}

/**
 * Author Widget
 */
var filterAuthorsEl = document.querySelector(".filter-authors");
if (filterAuthorsEl) {
    filterAuthorsEl.addEventListener("click", function (e) {
        if (
            e.target.closest(".filter-form") !== null ||
            e.target.classList.contains("filter-form") ||
            e.target.classList.contains("remove")
        ) {
            return;
        }
        var filterAuthorForm = document.querySelector(".filter-author-form");
        if (!filterAuthorForm) return;
        toggleEl(filterAuthorForm, function () {
            if (isVisible(this)) {
                filterAuthorsEl.classList.add("show-filter-dropdown");
            } else {
                filterAuthorsEl.classList.remove("show-filter-dropdown");
                global_params.fields.author = {};
                global_params.fields.author.mode = "OR";
                var authorVals = authorsEl && authorsEl.tomselect ? authorsEl.tomselect.getValue() : [];
                global_params.fields.author.words = authorVals;

                PS_params.authors = authorVals.length > 0 ? authorVals : "";
            }
        });
    });
}
document.querySelectorAll(".filter-authors .filter-validate").forEach(function (btn) {
    btn.addEventListener("click", function () {
        if (filterAuthorsEl) filterAuthorsEl.click();
        performSearch(PS_params, true);
    });
});
document.querySelectorAll(".filter-authors .filter-actions .delete").forEach(function (btn) {
    btn.addEventListener("click", function () {
        updateFilters("authors", "del");
        performSearch(PS_params, true);
        if (filterAuthorsEl && !filterAuthorsEl.classList.contains("filter-filled")) {
            filterAuthorsEl.style.display = 'none';
            var authorController = document.querySelector(".filter-manager-controller.author");
            if (authorController) authorController.checked = false;
        }
    });
});

/**
 * Added by Widget
 */
var filterAddedByEl = document.querySelector(".filter-added_by");
if (filterAddedByEl) {
    filterAddedByEl.addEventListener("click", function (e) {
        if (
            e.target.closest(".filter-form") !== null ||
            e.target.classList.contains("filter-form") ||
            e.target.classList.contains("remove")
        ) {
            return;
        }
        var filterAddedByForm = document.querySelector(".filter-added_by-form");
        if (!filterAddedByForm) return;
        toggleEl(filterAddedByForm, function () {
            if (isVisible(this)) {
                filterAddedByEl.classList.add("show-filter-dropdown");
            } else {
                filterAddedByEl.classList.remove("show-filter-dropdown");
                global_params.fields.added_by = {};
                global_params.fields.added_by.mode = "OR";

                added_by_array = [];
                document.querySelectorAll(".added_by-option input:checked").forEach(function (inp) {
                    added_by_array.push(inp.getAttribute("name"));
                });

                global_params.fields.added_by.words = added_by_array;

                PS_params.added_by =
                    added_by_array.length > 0 ? added_by_array : "";
            }
        });
    });
}
document.querySelectorAll(".filter-added_by .filter-validate").forEach(function (btn) {
    btn.addEventListener("click", function () {
        if (filterAddedByEl) filterAddedByEl.click();
        performSearch(PS_params, true);
    });
});
document.querySelectorAll(".filter-added_by .filter-actions .delete").forEach(function (btn) {
    btn.addEventListener("click", function () {
        updateFilters("added_by", "del");
        performSearch(PS_params, true);
        if (filterAddedByEl && !filterAddedByEl.classList.contains("filter-filled")) {
            filterAddedByEl.style.display = 'none';
            var addedByController = document.querySelector(".filter-manager-controller.added_by");
            if (addedByController) addedByController.checked = false;
        }
    });
});

/**
 * File type Widget
 */
var filterFiletypesEl = document.querySelector(".filter-filetypes");
if (filterFiletypesEl) {
    filterFiletypesEl.addEventListener("click", function (e) {
        if (
            e.target.closest(".filter-form") !== null ||
            e.target.classList.contains("filter-form") ||
            e.target.classList.contains("remove")
        ) {
            return;
        }
        var filterFiletypesForm = document.querySelector(".filter-filetypes-form");
        if (!filterFiletypesForm) return;
        toggleEl(filterFiletypesForm, function () {
            if (isVisible(this)) {
                filterFiletypesEl.classList.add("show-filter-dropdown");
            } else {
                filterFiletypesEl.classList.remove("show-filter-dropdown");

                filetypes_array = [];
                document.querySelectorAll(".filetypes-option input:checked").forEach(function (inp) {
                    filetypes_array.push(inp.getAttribute("name"));
                });

                global_params.fields.filetypes = filetypes_array;

                PS_params.filetypes =
                    filetypes_array.length > 0 ? filetypes_array : "";
            }
        });
    });
}
document.querySelectorAll(".filter-filetypes .filter-validate").forEach(function (btn) {
    btn.addEventListener("click", function () {
        if (filterFiletypesEl) filterFiletypesEl.click();
        performSearch(PS_params, true);
    });
});
document.querySelectorAll(".filter-filetypes .filter-actions .delete").forEach(function (btn) {
    btn.addEventListener("click", function () {
        updateFilters("filetypes", "del");
        performSearch(PS_params, true);
        if (filterFiletypesEl && !filterFiletypesEl.classList.contains("filter-filled")) {
            filterFiletypesEl.style.display = 'none';
            var filetypesController = document.querySelector(".filter-manager-controller.filetypes");
            if (filetypesController) filetypesController.checked = false;
        }
    });
});
});

function serializeParams(params) {
var parts = [];
for (var key in params) {
    var val = params[key];
    if (Array.isArray(val)) {
        val.forEach(function (item) {
            parts.push(encodeURIComponent(key + "[]") + "=" + encodeURIComponent(item));
        });
    } else if (val !== undefined && val !== null) {
        parts.push(encodeURIComponent(key) + "=" + encodeURIComponent(val));
    }
}
return parts.join("&");
}

function performSearch(params, reload) {
reload = reload !== undefined ? reload : false;
fetch("ws.php?format=json&method=pwg.images.filteredSearch.create", {
    method: "POST",
    headers: { "Content-Type": "application/x-www-form-urlencoded" },
    body: serializeParams(params),
})
    .then(function (response) { return response.json(); })
    .then(function (data) {
        if (reload && data.result && typeof data.result.search_url !== "undefined") {
            reloadPage(data.result.search_url);
        }
        document.querySelectorAll(".filter-validate").forEach(function (el) {
            var validateText = el.querySelector(".validate-text");
            var loading = el.querySelector(".loading");
            if (validateText) validateText.style.display = 'block';
            if (loading) loading.style.display = 'none';
        });
        document.querySelectorAll(".remove-filter").forEach(function (el) {
            el.classList.remove(prefix_icon + "spin6", "animate-spin");
            el.classList.add(prefix_icon + "cancel");
        });
    })
    .catch(function (e) {
        console.log(e);
    });
}

function set_up_popin() {
document.querySelectorAll(".ClosePopIn").forEach(function (btn) {
    btn.addEventListener("click", function () {
        linked_albums_close();
    });
});

var addLinkedAlbum = document.getElementById("addLinkedAlbum");
if (addLinkedAlbum) {
    addLinkedAlbum.addEventListener("keyup", function (e) {
        // 27 is 'Escape'
        if (e.keyCode === 27) {
            linked_albums_close();
        }
    });
}
}

function linked_albums_close() {
var addLinkedAlbum = document.getElementById("addLinkedAlbum");
if (addLinkedAlbum) addLinkedAlbum.style.display = 'none';
}

function fill_results(cats) {
var searchResult = document.getElementById("searchResult");
if (!searchResult) return;
searchResult.innerHTML = '';
cats.forEach(function (cat) {
    if (!related_categories_ids.includes(cat.id)) {
        searchResult.insertAdjacentHTML(
            "beforeend",
            "<div class='search-result-item' id=" +
                cat.id +
                ">" +
                "<span class='search-result-path'>" +
                cat.name +
                "</span><span id=" +
                cat.id +
                " class='gallery-icon-plus-circled item-add'></span>" +
                "</div>",
        );

        var item = searchResult.querySelector(".search-result-item#" + cat.id);
        if (item) {
            item.addEventListener("click", function () {
                add_related_category(cat.id, cat.name);
            });
        }
    }
});
}

function add_related_category(cat_id, cat_link_path) {
var container = document.querySelector(".selected-categories-container");
if (container) {
    container.insertAdjacentHTML(
        "beforeend",
        "<div class='breadcrumb-item'>" +
            "<span class='link-path'>" +
            cat_link_path +
            "</span><span id=" +
            cat_id +
            " class='mcs-icon " +
            prefix_icon +
            "cancel remove-item'></span>" +
            "</div>",
    );
}

related_categories_ids.push(cat_id);
var invisibleSelect = document.querySelector(".invisible-related-categories-select");
if (invisibleSelect) {
    invisibleSelect.insertAdjacentHTML(
        "beforeend",
        "<option selected value=" + cat_id + "></option>",
    );
}

var removeBtn = document.getElementById("" + cat_id);
if (removeBtn) {
    removeBtn.addEventListener("click", function () {
        remove_related_category(this.getAttribute("id"));
    });
}

linked_albums_close();
}

function remove_related_category(cat_id) {
var el = document.getElementById(cat_id);
if (el && el.parentElement) {
    el.parentElement.remove();
}

var cat_to_remove_index = related_categories_ids.indexOf(parseInt(cat_id));
if (cat_to_remove_index > -1) {
    related_categories_ids.splice(cat_to_remove_index, 1);
}
if (related_categories_ids.length === 0) {
    related_categories_ids = [];
}
}

function updateFilters(filterName, mode) {
switch (filterName) {
    case "word":
        if (mode == "add") {
            global_params.fields.allwords = {};

            PS_params.allwords = "";
            PS_params.allwords_mode = "AND";
            PS_params.allwords_fields = [];
        } else if (mode == "del") {
            delete global_params.fields.allwords;

            delete PS_params.allwords;
            delete PS_params.allwords_mode;
            delete PS_params.allwords_fields;
        }
        break;

    case "tag":
        if (mode == "add") {
            global_params.fields.tags = {};

            PS_params.tags = "";
            PS_params.tags_mode = "AND";
        } else if (mode == "del") {
            delete global_params.fields.tags;

            delete PS_params.tags;
            delete PS_params.tags_mode;
        }
        break;

    case "album":
        if (mode == "add") {
            global_params.fields.cat = {};

            PS_params.categories = "";
            PS_params.categories_withsubs = false;
        } else if (mode == "del") {
            delete global_params.fields.cat;

            delete PS_params.categories;
            delete PS_params.categories_withsubs;
        }
        break;

    default:
        if (mode == "add") {
            global_params.fields[filterName] = {};

            PS_params[filterName] = "";
        } else if (mode == "del") {
            delete global_params.fields[filterName];

            delete PS_params[filterName];
        }
        break;
}
}

function reloadPage(url) {
window.location.href = url;
}

/**
 * Replace the filter_form elements if they exceed the window
 */
function resize_filter_form() {
document.querySelectorAll(".form_mobile_arrow").forEach(function (el) { el.remove(); });
document.querySelectorAll(".filter").forEach(function (filterEl) {
    var window_width = window.innerWidth;
    var rect = filterEl.getBoundingClientRect();
    var left_distance = rect.left + window.scrollX;
    var filter_form = filterEl.querySelector(".filter-form");
    if (!filter_form) return;
    var filter_form_width = filter_form.clientWidth;
    var too_left = left_distance + filterEl.clientWidth - filter_form_width;
    var is_desktop = window.matchMedia("(min-width: 600px)").matches;
    filter_form.style.left = "0px";
    var margin_left = is_desktop ? 15 : 0;

    if (left_distance + filter_form_width > window_width) {
        var check_left =
            too_left < 0 ? Math.abs(too_left - margin_left) : 0;
        var mobile_marg = is_desktop ? 0 : 2;
        var replace_form_width =
            -filter_form_width +
            filterEl.clientWidth +
            check_left -
            mobile_marg;
        filter_form.style.left = replace_form_width + "px";
    }
    if (!is_desktop) {
        var left_arrow = left_distance + filterEl.clientWidth / 2;
        filter_form.insertAdjacentHTML(
            "afterbegin",
            '<svg width="10" height="10" viewBox="0 0 14 14" class="form_mobile_arrow" style="left:' +
                left_arrow +
                'px"><polygon class="arrow-border" points="7,0 14,14 0,14"/><polygon class="arrow-fill" points="7,1 13.5,14 0.5,14"/></svg>',
        );
    }
});
}

window.addEventListener("load", function () {
resize_filter_form();
});
window.addEventListener("resize", function () {
resize_filter_form();
}

document.addEventListener("DOMContentLoaded", initMCS);
