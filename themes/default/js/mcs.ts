import TomSelect from 'tom-select';

interface MCSFields {
    allwords?: { words: string[]; mode: string; fields: string[] | Record<string, string> };
    tags?: { words: string[]; mode: string };
    date_posted?: string | null;
    cat?: { words: (string | number)[]; sub_inc: boolean };
    author?: { words: string[]; mode?: string };
    added_by?: (string | number)[];
    filetypes?: string[];
    search_in_tags?: boolean;
    [key: string]: unknown;
}

interface SelectWithTomSelect extends HTMLSelectElement {
    tomselect?: InstanceType<typeof TomSelect>;
}

export function initMCS(): void {

let related_categories_ids: (string | number)[] = [];
let PS_params: Record<string, unknown> = {};
let empty_filters_list: unknown[] = [];
let word_search_str = '';
let word_search_words: string[] = [];
let word_search_mode = '';
let word_search_fields: string[] | Record<string, string> = {};
let tag_search_str = '';
let date_posted_str = '';
let album_widget_value = '';
let author_search_str = '';
let added_by_names: string[] = [];
let added_by_array: string[] = [];
let filetypes_search_str = '';
let filetypes_array: string[] = [];
let new_fields: string[] = [];
let str_no_search_in_progress = '';

const _albumSelectorDataEl = document.getElementById('pwg-album-selector-data');
const _albumSelectorData = _albumSelectorDataEl ? JSON.parse(_albumSelectorDataEl.textContent ?? '{}') as Record<string, string> : {} as Record<string, string>;
str_no_search_in_progress = _albumSelectorData['strNoSearchInProgress'] || '';

function toggleEl(el: HTMLElement, callback: (this: HTMLElement) => void): void {
    const isHidden = window.getComputedStyle(el).display === 'none';
    el.style.display = isHidden ? '' : 'none';
    if (callback) callback.call(el);
}

function isVisible(el: Element | null): boolean {
    return !!el && window.getComputedStyle(el as HTMLElement).display !== 'none';
}

related_categories_ids = [];

const fields = global_params["fields"] as MCSFields;

document.querySelectorAll<HTMLElement>(".linkedAlbumPopInContainer .ClosePopIn").forEach(function (el) {
    el.classList.add(prefix_icon + "cancel");
});
const linkedAlbumSearching = document.querySelector<HTMLElement>(".linkedAlbumPopInContainer .searching");
if (linkedAlbumSearching) linkedAlbumSearching.style.display = 'none';

document.querySelectorAll<HTMLButtonElement>(".filter-validate").forEach(function (btn) {
    btn.addEventListener("click", function () {
        const loading = this.querySelector<HTMLElement>(".loading");
        const validateText = this.querySelector<HTMLElement>(".validate-text");
        if (loading) loading.style.display = 'block';
        if (validateText) validateText.style.display = 'none';
    });
});

document.querySelectorAll<HTMLElement>("div.filter").forEach(function (filterEl) {
    filterEl.addEventListener("click", function () {
        if (!filterEl.parentElement) return;
        Array.from(filterEl.parentElement.children).forEach(function (sib) {
            if (sib === filterEl) return;
            sib.classList.remove("show-filter-dropdown");
            sib.querySelectorAll<HTMLElement>("div.filter-form").forEach(function (form) {
                form.style.display = 'none';
            });
        });
    });
});

document.querySelectorAll<HTMLElement>("div.filter-manager").forEach(function (el) {
    el.addEventListener("click", function () {
        document.querySelectorAll<HTMLElement>("div.filter div.filter-form").forEach(function (form) {
            form.style.display = 'none';
        });
    });
});

global_params["search_id"] = search_id;

if (!fields) {
    global_params["fields"] = {};
}

PS_params = {};
PS_params["search_id"] = search_id;
empty_filters_list = [];

if (fields.allwords) {
    const filterWord = document.querySelector<HTMLElement>(".filter-word");
    if (filterWord) filterWord.style.display = 'flex';
    const wordController = document.querySelector<HTMLInputElement>(".filter-manager-controller.word");
    if (wordController) wordController.checked = true;

    word_search_str = "";
    word_search_words =
        fields.allwords.words != null
            ? fields.allwords.words
            : [];
    word_search_words.forEach(function (word) { word_search_str += word + " "; });
    const wordSearchEl = document.getElementById("word-search") as HTMLInputElement | null;
    if (wordSearchEl) wordSearchEl.value = word_search_str.slice(0, -1);

    const filterWordSearchWords = document.querySelector<HTMLElement>(".filter-word .search-words");
    if (
        fields.allwords.words &&
        fields.allwords.words.length > 0
    ) {
        if (filterWord) filterWord.classList.add("filter-filled");
        if (filterWordSearchWords) filterWordSearchWords.innerHTML = word_search_str.slice(0, -1);
    } else {
        if (filterWordSearchWords) filterWordSearchWords.innerHTML = str_word_widget_label;
    }

    word_search_fields = fields.allwords.fields;
    const fieldsArr = Array.isArray(word_search_fields) ? word_search_fields : Object.values(word_search_fields);
    fieldsArr.forEach(function (field_val) {
        const el = document.getElementById(field_val);
        if (el) (el as HTMLInputElement).checked = true;
    });

    word_search_mode = fields.allwords.mode;
    const wordModeInput = document.querySelector<HTMLInputElement>(".word-search-options input[value=" + word_search_mode + "]");
    if (wordModeInput) wordModeInput.checked = true;

    if (fields.search_in_tags) {
        const tagsCheckbox = document.getElementById("tags") as HTMLInputElement | null;
        if (tagsCheckbox) tagsCheckbox.checked = true;
    }

    document.querySelectorAll<HTMLElement>(".filter-word .filter-actions .clear").forEach(function (btn) {
        btn.addEventListener("click", function () {
            const ws = document.querySelector<HTMLInputElement>(".filter-word #word-search");
            if (ws) ws.value = "";
            document.querySelectorAll<HTMLInputElement>(".filter-word .search-params input").forEach(function (inp) { inp.checked = true; });
            const andInput = document.querySelector<HTMLInputElement>(".filter-word .word-search-options input[value='AND']");
            if (andInput) andInput.checked = true;
        });
    });

    PS_params["allwords"] = word_search_str.slice(0, -1);
    PS_params["allwords_fields"] = word_search_fields;
    PS_params["allwords_mode"] = word_search_mode;

    empty_filters_list.push(PS_params["allwords"]);
}
document.querySelectorAll<HTMLElement>(".filter-spinner").forEach(function (el) { el.style.display = 'none'; });

const tagSearchEl = document.getElementById("tag-search") as SelectWithTomSelect | null;
if (tagSearchEl) {
    new TomSelect(tagSearchEl, {
        plugins: ["remove_button"],
        maxOptions: tagSearchEl.querySelectorAll("option").length,
        ...(fields.tags ? { items: fields.tags.words } : {}),
    });
}
if (fields.tags) {
    const filterTag = document.querySelector<HTMLElement>(".filter-tag");
    if (filterTag) filterTag.style.display = 'flex';
    const tagsController = document.querySelector<HTMLInputElement>(".filter-manager-controller.tags");
    if (tagsController) tagsController.checked = true;
    const tagsModeInput = document.querySelector<HTMLInputElement>(
        ".filter-tag-form .search-params input[value=" + fields.tags.mode + "]"
    );
    if (tagsModeInput) tagsModeInput.checked = true;

    tag_search_str = "";
    if (tagSearchEl && tagSearchEl.tomselect) {
        (tagSearchEl.tomselect.getValue() as string[]).forEach(function (id) {
            const item = tagSearchEl.tomselect!.getItem(id);
            const itemText = item ? (item as HTMLElement).textContent ?? '' : '';
            tag_search_str += itemText.replace(/\(\d+ \w+\)×/, "").trim() + ", ";
        });
    }
    const filterTagSearchWords = document.querySelector<HTMLElement>(".filter.filter-tag .search-words");
    if (
        fields.tags.words &&
        fields.tags.words.length > 0
    ) {
        if (filterTag) filterTag.classList.add("filter-filled");
        if (filterTagSearchWords) filterTagSearchWords.textContent = tag_search_str.slice(0, -2);
    } else {
        if (filterTagSearchWords) filterTagSearchWords.textContent = str_tags_widget_label;
    }

    document.querySelectorAll<HTMLElement>(".filter-tag .filter-actions .clear").forEach(function (btn) {
        btn.addEventListener("click", function () {
            if (tagSearchEl && tagSearchEl.tomselect) tagSearchEl.tomselect.clear();
            const andInput = document.querySelector<HTMLInputElement>(".filter-tag .search-params input[value='AND']");
            if (andInput) andInput.checked = true;
        });
    });

    PS_params["tags"] =
        fields.tags.words.length > 0
            ? fields.tags.words
            : "";
    PS_params["tags_mode"] = fields.tags.mode;

    empty_filters_list.push(PS_params["tags"]);
}

if (Object.prototype.hasOwnProperty.call(fields, "date_posted")) {
    const filterDatePosted = document.querySelector<HTMLElement>(".filter-date_posted");
    if (filterDatePosted) filterDatePosted.style.display = 'flex';
    const datePostedController = document.querySelector<HTMLInputElement>(".filter-manager-controller.date_posted");
    if (datePostedController) datePostedController.checked = true;

    if (
        fields.date_posted != null &&
        fields.date_posted != ""
    ) {
        const datePostedInput = document.getElementById("date_posted-" + fields.date_posted) as HTMLInputElement | null;
        if (datePostedInput) datePostedInput.checked = true;

        const datePeriodEl = document.querySelector<HTMLElement>(
            ".date_posted-option label#" + fields.date_posted + " .date-period"
        );
        date_posted_str = datePeriodEl ? datePeriodEl.textContent ?? "" : "";
        if (filterDatePosted) filterDatePosted.classList.add("filter-filled");
        const filterDatePostedSearchWords = document.querySelector<HTMLElement>(".filter.filter-date_posted .search-words");
        if (filterDatePostedSearchWords) filterDatePostedSearchWords.textContent = date_posted_str;
    }

    document.querySelectorAll<HTMLElement>(".filter-date_posted .filter-actions .clear").forEach(function (btn) {
        btn.addEventListener("click", function () {
            document.querySelectorAll<HTMLInputElement>(".date_posted-option input").forEach(function (inp) { inp.checked = false; });
        });
    });

    const dpVal = fields.date_posted;
    PS_params["date_posted"] =
        dpVal && String(dpVal).length > 0
            ? dpVal
            : "";

    empty_filters_list.push(PS_params["date_posted"]);
}

if (fields.cat) {
    const filterAlbum = document.querySelector<HTMLElement>(".filter-album");
    if (filterAlbum) filterAlbum.style.display = 'flex';
    const albumController = document.querySelector<HTMLInputElement>(".filter-manager-controller.album");
    if (albumController) albumController.checked = true;

    album_widget_value = "";
    fields.cat.words.forEach(function (cat_id) {
        add_related_category(cat_id, fullname_of_cat[cat_id] ?? '');
        album_widget_value += (fullname_of_cat[cat_id] ?? '') + ", ";
    });
    const filterAlbumSearchWords = document.querySelector<HTMLElement>(".filter-album .search-words");
    if (
        fields.cat.words &&
        fields.cat.words.length > 0
    ) {
        if (filterAlbum) filterAlbum.classList.add("filter-filled");
        if (filterAlbumSearchWords) filterAlbumSearchWords.innerHTML = album_widget_value.slice(0, -2);
    } else {
        if (filterAlbumSearchWords) filterAlbumSearchWords.innerHTML = str_album_widget_label;
    }

    if (fields.cat.sub_inc) {
        const searchSubCats = document.getElementById("search-sub-cats") as HTMLInputElement | null;
        if (searchSubCats) searchSubCats.checked = true;
    }

    document.querySelectorAll<HTMLElement>(".filter-album .filter-actions .clear").forEach(function (btn) {
        btn.addEventListener("click", function () {
            related_categories_ids = [];
            const selectedCatsContainer = document.querySelector<HTMLElement>(".selected-categories-container");
            if (selectedCatsContainer) selectedCatsContainer.innerHTML = '';
            const ssc = document.getElementById("search-sub-cats") as HTMLInputElement | null;
            if (ssc) ssc.checked = false;
        });
    });

    PS_params["categories"] =
        fields.cat.words.length > 0
            ? fields.cat.words
            : "";
    PS_params["categories_withsubs"] = fields.cat.sub_inc;

    empty_filters_list.push(PS_params["categories"]);
}

const authorsEl = document.getElementById("authors") as SelectWithTomSelect | null;
if (authorsEl) {
    new TomSelect(authorsEl, {
        plugins: ["remove_button"],
        maxOptions: authorsEl.querySelectorAll("option").length,
        ...(fields.author ? { items: fields.author.words } : {}),
    });
    if (fields.author) {
        const filterAuthors = document.querySelector<HTMLElement>(".filter-authors");
        if (filterAuthors) filterAuthors.style.display = 'flex';
        const authorController = document.querySelector<HTMLInputElement>(".filter-manager-controller.author");
        if (authorController) authorController.checked = true;

        author_search_str = "";
        if (authorsEl.tomselect) {
            (authorsEl.tomselect.getValue() as string[]).forEach(function (id) {
                const item = authorsEl.tomselect!.getItem(id);
                const itemText = item ? (item as HTMLElement).textContent ?? '' : '';
                author_search_str += itemText.replace(/\(\d+ \w+\)×/, "").trim() + ", ";
            });
        }

        const filterAuthorsSearchWords = document.querySelector<HTMLElement>(".filter.filter-authors .search-words");
        if (
            fields.author.words &&
            fields.author.words.length > 0
        ) {
            if (filterAuthors) filterAuthors.classList.add("filter-filled");
            if (filterAuthorsSearchWords) filterAuthorsSearchWords.textContent = author_search_str.slice(0, -2);
        } else {
            if (filterAuthorsSearchWords) filterAuthorsSearchWords.textContent = str_author_widget_label;
        }

        document.querySelectorAll<HTMLElement>(".filter-authors .filter-actions .clear").forEach(function (btn) {
            btn.addEventListener("click", function () {
                if (authorsEl.tomselect) authorsEl.tomselect.clear();
            });
        });

        PS_params["authors"] =
            fields.author.words.length > 0
                ? fields.author.words
                : "";

        empty_filters_list.push(PS_params["authors"]);
    }
}

if (fields.added_by) {
    const filterAddedBy = document.querySelector<HTMLElement>(".filter-added_by");
    if (filterAddedBy) filterAddedBy.style.display = 'flex';
    const addedByController = document.querySelector<HTMLInputElement>(".filter-manager-controller.added_by");
    if (addedByController) addedByController.checked = true;

    if (
        fields.added_by &&
        fields.added_by.length > 0
    ) {
        if (filterAddedBy) filterAddedBy.classList.add("filter-filled");

        added_by_names = [];

        document.querySelectorAll<HTMLElement>(".added_by-option").forEach(function (option) {
            const input = option.querySelector<HTMLInputElement>("input");
            if (!input) return;
            const added_by_id = parseInt(input.getAttribute("name") ?? '');
            if ((fields.added_by as (string|number)[]).includes(added_by_id)) {
                input.checked = true;
                const nameEl = option.querySelector(".added_by-name");
                if (nameEl) added_by_names.push(nameEl.textContent ?? '');
            }
        });

        const filterAddedBySearchWords = document.querySelector<HTMLElement>(".filter.filter-added_by .search-words");
        if (filterAddedBySearchWords) filterAddedBySearchWords.textContent = added_by_names.join(", ");
    } else {
        const filterAddedBySearchWords2 = document.querySelector<HTMLElement>(".filter.filter-added_by .search-words");
        if (filterAddedBySearchWords2) filterAddedBySearchWords2.textContent = str_added_by_widget_label;
    }

    document.querySelectorAll<HTMLElement>(".filter-added_by .filter-actions .clear").forEach(function (btn) {
        btn.addEventListener("click", function () {
            document.querySelectorAll<HTMLInputElement>(".filter-added_by .added_by-option input").forEach(function (inp) { inp.checked = false; });
        });
    });

    PS_params["added_by"] =
        (fields.added_by).length > 0
            ? fields.added_by
            : "";

    empty_filters_list.push(PS_params["added_by"]);
}

if (fields.filetypes) {
    const filterFiletypes = document.querySelector<HTMLElement>(".filter-filetypes");
    if (filterFiletypes) filterFiletypes.style.display = 'flex';
    const filetypesController = document.querySelector<HTMLInputElement>(".filter-manager-controller.filetypes");
    if (filetypesController) filetypesController.checked = true;

    filetypes_search_str = "";
    fields.filetypes.forEach(function (ft) { filetypes_search_str += ft + ", "; });

    const filterFiletypesSearchWords = document.querySelector<HTMLElement>(".filter.filter-filetypes .search-words");
    if (
        fields.filetypes &&
        fields.filetypes.length > 0
    ) {
        if (filterFiletypes) filterFiletypes.classList.add("filter-filled");
        if (filterFiletypesSearchWords)
            filterFiletypesSearchWords.textContent = filetypes_search_str.toUpperCase().slice(0, -2);

        document.querySelectorAll<HTMLInputElement>(".filetypes-option input").forEach(function (inp) {
            if (fields.filetypes!.includes(inp.getAttribute("name") ?? '')) {
                inp.checked = true;
            }
        });
    } else {
        if (filterFiletypesSearchWords) filterFiletypesSearchWords.textContent = str_filetypes_widget_label;
    }

    document.querySelectorAll<HTMLElement>(".filter-filetypes .filter-actions .clear").forEach(function (btn) {
        btn.addEventListener("click", function () {
            document.querySelectorAll<HTMLInputElement>(".filter-filetypes .filetypes-option input").forEach(function (inp) { inp.checked = false; });
        });
    });

    PS_params["filetypes"] =
        fields.filetypes.length > 0
            ? fields.filetypes
            : "";

    empty_filters_list.push(PS_params["filetypes"]);
}

if (document.querySelectorAll(".filter-filled").length === 0) {
    const noResultTop = document.querySelector<HTMLElement>(".mcs-no-result .text .top");
    const noResultBot = document.querySelector<HTMLElement>(".mcs-no-result .text .bot");
    if (noResultTop) noResultTop.innerHTML = str_empty_search_top_alt;
    if (noResultBot) noResultBot.innerHTML = str_empty_search_bot_alt;
}

if (!empty_filters_list.every(function (param) { return param === "" || param === null; })) {
    const clearAll = document.querySelector<HTMLElement>(".clear-all");
    if (clearAll) {
        clearAll.classList.add("clickable");
        document.querySelector<HTMLElement>(".clear-all.clickable")?.addEventListener("click", function () {
            const exclude_params = [
                "search_id",
                "allwords_mode",
                "allwords_fields",
                "tags_mode",
                "categories_withsubs",
            ];
            for (const key in PS_params) {
                if (!exclude_params.includes(key)) {
                    PS_params[key] = "";
                }
            }
            performSearch(PS_params, true);
        });
    }
}

document.querySelectorAll<HTMLElement>(".filter-manager").forEach(function (el) {
    el.addEventListener("click", function () {
        const popin = document.querySelector<HTMLElement>(".filter-manager-popin");
        if (popin) popin.style.display = 'block';
    });
});

document.addEventListener("keyup", function (e: KeyboardEvent) {
    if (e.keyCode === 27) {
        const fmClose = document.querySelector<HTMLElement>(".filter-manager-popin .filter-manager-close");
        if (fmClose) fmClose.click();
        const tagsClose = document.querySelector<HTMLElement>(".tags-found-popin .tags-found-close");
        if (tagsClose) tagsClose.click();
        const albumsClose = document.querySelector<HTMLElement>(".albums-found-popin .albums-found-close");
        if (albumsClose) albumsClose.click();
    }
    if (e.keyCode === 13) {
        document.querySelectorAll<HTMLElement>(".filter-form .filter-validate").forEach(function (el) {
            if (isVisible(el)) el.click();
        });
    }
});

const filterManagerPopin = document.querySelector<HTMLElement>(".filter-manager-popin");
if (filterManagerPopin) {
    filterManagerPopin.addEventListener("click", function (e) {
        if (e.target === this) {
            const fmClose = this.querySelector<HTMLElement>(".filter-manager-close");
            if (fmClose) fmClose.click();
        }
    });
}

document.querySelectorAll<HTMLElement>(
    ".filter-manager-popin .filter-cancel, .filter-manager-popin .filter-manager-close"
).forEach(function (btn) {
    btn.addEventListener("click", function () {
        if (filterManagerPopin) filterManagerPopin.style.display = 'none';
        document.querySelectorAll<HTMLInputElement>(".filter-manager-controller-container input").forEach(function (input) {
            const filterEl = document.querySelector<HTMLElement>(".filter.filter-" + input.dataset["wid"]);
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

const fmValidate = document.querySelector<HTMLElement>(".filter-manager-popin .filter-validate");
if (fmValidate) {
    fmValidate.addEventListener("click", function () {
        document.querySelectorAll<HTMLInputElement>(".filter-manager-controller-container input").forEach(function (input) {
            const filterEl = document.querySelector<HTMLElement>(".filter.filter-" + input.dataset["wid"]);
            if (input.checked) {
                if (filterEl && !isVisible(filterEl)) {
                    updateFilters(input.dataset["wid"] ?? '', "add");
                }
            } else {
                if (filterEl && isVisible(filterEl)) {
                    console.log(input.dataset["wid"]);
                    updateFilters(input.dataset["wid"] ?? '', "del");
                }
            }
        });
        performSearch(PS_params, true);
    });
}

document.querySelectorAll<HTMLElement>(".mcs-tags-found").forEach(function (el) {
    el.addEventListener("click", function () {
        const popin = document.querySelector<HTMLElement>(".tags-found-popin");
        if (popin) popin.style.display = 'block';
    });
});
document.querySelectorAll<HTMLElement>(".mcs-albums-found").forEach(function (el) {
    el.addEventListener("click", function () {
        const popin = document.querySelector<HTMLElement>(".albums-found-popin");
        if (popin) popin.style.display = 'block';
    });
});

const tagsFoundPopin = document.querySelector<HTMLElement>(".tags-found-popin");
if (tagsFoundPopin) {
    tagsFoundPopin.addEventListener("click", function (e) {
        if (e.target === this) {
            const closeBtn = this.querySelector<HTMLElement>(".tags-found-close");
            if (closeBtn) closeBtn.click();
        }
    });
}
document.querySelectorAll<HTMLElement>(".tags-found-close").forEach(function (btn) {
    btn.addEventListener("click", function () {
        if (tagsFoundPopin) tagsFoundPopin.style.display = 'none';
    });
});

const albumsFoundPopin = document.querySelector<HTMLElement>(".albums-found-popin");
if (albumsFoundPopin) {
    albumsFoundPopin.addEventListener("click", function (e) {
        if (e.target === this) {
            const closeBtn = this.querySelector<HTMLElement>(".albums-found-close");
            if (closeBtn) closeBtn.click();
        }
    });
}
document.querySelectorAll<HTMLElement>(".albums-found-close").forEach(function (btn) {
    btn.addEventListener("click", function () {
        if (albumsFoundPopin) albumsFoundPopin.style.display = 'none';
    });
});

const filterWordEl = document.querySelector<HTMLElement>(".filter-word");
if (filterWordEl) {
    filterWordEl.addEventListener("click", function (e) {
        if (
            (e.target as Element).closest(".filter-form") !== null ||
            (e.target as Element).classList.contains("filter-form")
        ) {
            return;
        }
        const filterWordForm = document.querySelector<HTMLElement>(".filter-word-form");
        if (!filterWordForm) return;
        toggleEl(filterWordForm, function () {
            if (isVisible(this)) {
                filterWordEl.classList.add("show-filter-dropdown");
                const wordSearchInput = document.getElementById("word-search") as HTMLInputElement | null;
                if (wordSearchInput) wordSearchInput.focus();
            } else {
                filterWordEl.classList.remove("show-filter-dropdown");

                fields.allwords = { words: [], mode: '', fields: [] };
                const wordSearchInput = document.getElementById("word-search") as HTMLInputElement | null;
                fields.allwords.words = wordSearchInput ? [wordSearchInput.value] : [];
                const wordModeChecked = document.querySelector<HTMLInputElement>(".word-search-options input:checked");
                fields.allwords.mode = wordModeChecked ? wordModeChecked.getAttribute("value") ?? '' : '';

                PS_params["allwords"] = wordSearchInput ? wordSearchInput.value : '';
                PS_params["allwords_mode"] = wordModeChecked ? wordModeChecked.getAttribute("value") : '';

                new_fields = [];
                document.querySelectorAll<HTMLInputElement>(".filter-word-form .search-params input:checked").forEach(function (inp) {
                    if (inp.getAttribute("name") == "tags") {
                        fields.search_in_tags = true;
                    }
                    new_fields.push(inp.getAttribute("name") ?? '');
                });
                if (
                    !document.querySelector(".filter-word-form .search-params input[name='tags']:checked")
                ) {
                    delete fields.search_in_tags;
                }
                fields.allwords.fields = new_fields;
                PS_params["allwords_fields"] =
                    new_fields.length > 0 ? new_fields : "";
            }
        });
    });
}
document.querySelectorAll<HTMLElement>(".filter-word .filter-validate").forEach(function (btn) {
    btn.addEventListener("click", function () {
        if (filterWordEl) filterWordEl.click();
        performSearch(PS_params, true);
    });
});
document.querySelectorAll<HTMLElement>(".filter-word .filter-actions .delete").forEach(function (btn) {
    btn.addEventListener("click", function () {
        updateFilters("word", "del");
        performSearch(PS_params, true);
        if (filterWordEl && !filterWordEl.classList.contains("filter-filled")) {
            filterWordEl.style.display = 'none';
            const wordController2 = document.querySelector<HTMLInputElement>(".filter-manager-controller.word");
            if (wordController2) wordController2.checked = false;
        }
    });
});

const filterTagEl = document.querySelector<HTMLElement>(".filter-tag");
if (filterTagEl) {
    filterTagEl.addEventListener("click", function (e) {
        if (
            (e.target as Element).closest(".filter-form") !== null ||
            (e.target as Element).classList.contains("filter-form") ||
            (e.target as Element).classList.contains("remove")
        ) {
            return;
        }
        const filterTagForm = document.querySelector<HTMLElement>(".filter-tag-form");
        if (!filterTagForm) return;
        toggleEl(filterTagForm, function () {
            if (isVisible(this)) {
                filterTagEl.classList.add("show-filter-dropdown");
            } else {
                filterTagEl.classList.remove("show-filter-dropdown");
                fields.tags = { mode: '', words: [] };
                const tagModeChecked = document.querySelector<HTMLInputElement>(".filter-tag-form .search-params input:checked");
                fields.tags.mode = tagModeChecked ? tagModeChecked.value : '';
                const tagVals = tagSearchEl && tagSearchEl.tomselect ? tagSearchEl.tomselect.getValue() as string[] : [];
                fields.tags.words = tagVals;

                PS_params["tags"] = tagVals.length > 0 ? tagVals : "";
                PS_params["tags_mode"] = tagModeChecked ? tagModeChecked.value : '';
            }
        });
    });
}
document.querySelectorAll<HTMLElement>(".filter-tag .filter-validate").forEach(function (btn) {
    btn.addEventListener("click", function () {
        if (filterTagEl) filterTagEl.click();
        performSearch(PS_params, true);
    });
});
document.querySelectorAll<HTMLElement>(".filter-tag .filter-actions .delete").forEach(function (btn) {
    btn.addEventListener("click", function () {
        updateFilters("tag", "del");
        performSearch(PS_params, true);
        if (filterTagEl && !filterTagEl.classList.contains("filter-filled")) {
            filterTagEl.style.display = 'none';
            const tagsController2 = document.querySelector<HTMLInputElement>(".filter-manager-controller.tags");
            if (tagsController2) tagsController2.checked = false;
        }
    });
});

const filterDatePostedEl = document.querySelector<HTMLElement>(".filter-date_posted");
if (filterDatePostedEl) {
    filterDatePostedEl.addEventListener("click", function (e) {
        if (
            (e.target as Element).closest(".filter-form") !== null ||
            (e.target as Element).classList.contains("filter-form")
        ) {
            return;
        }
        const filterDateForm = document.querySelector<HTMLElement>(".filter-date_posted-form");
        if (!filterDateForm) return;
        toggleEl(filterDateForm, function () {
            if (isVisible(this)) {
                filterDatePostedEl.classList.add("show-filter-dropdown");
            } else {
                filterDatePostedEl.classList.remove("show-filter-dropdown");

                const dateChecked = document.querySelector<HTMLInputElement>(".date_posted-option input:checked");
                fields.date_posted = dateChecked ? dateChecked.value : '';

                PS_params["date_posted"] =
                    dateChecked ? dateChecked.value : "";
            }
        });
    });
}

document.querySelectorAll<HTMLElement>(".filter-date_posted .filter-validate").forEach(function (btn) {
    btn.addEventListener("click", function () {
        if (filterDatePostedEl) filterDatePostedEl.click();
        performSearch(PS_params, true);
    });
});
document.querySelectorAll<HTMLElement>(".filter-date_posted .filter-actions .delete").forEach(function (btn) {
    btn.addEventListener("click", function () {
        updateFilters("date_posted", "del");
        performSearch(PS_params, true);
        if (filterDatePostedEl && !filterDatePostedEl.classList.contains("filter-filled")) {
            filterDatePostedEl.style.display = 'none';
            const dateController2 = document.querySelector<HTMLInputElement>(".filter-manager-controller.date");
            if (dateController2) dateController2.checked = false;
        }
    });
});

const filterAlbumEl = document.querySelector<HTMLElement>(".filter-album");
if (filterAlbumEl) {
    filterAlbumEl.addEventListener("click", function (e) {
        if (
            (e.target as Element).closest(".filter-form") !== null ||
            (e.target as Element).classList.contains("filter-form") ||
            (e.target as Element).classList.contains("remove-item")
        ) {
            return;
        }
        const filterAlbumForm = document.querySelector<HTMLElement>(".filter-album-form");
        if (!filterAlbumForm) return;
        toggleEl(filterAlbumForm, function () {
            if (isVisible(this)) {
                filterAlbumEl.classList.add("show-filter-dropdown");
            } else {
                filterAlbumEl.classList.remove("show-filter-dropdown");
                fields.cat = { words: [], sub_inc: false };
                fields.cat.words = related_categories_ids;
                const subCatsChecked = document.querySelector<HTMLInputElement>("input[name='search-sub-cats']:checked");
                fields.cat.sub_inc = subCatsChecked !== null;

                PS_params["categories"] =
                    related_categories_ids.length > 0
                        ? related_categories_ids
                        : "";
                PS_params["categories_withsubs"] = subCatsChecked !== null;
            }
        });
    });
}
document.querySelectorAll<HTMLElement>(".filter-album .filter-validate").forEach(function (btn) {
    btn.addEventListener("click", function () {
        if (filterAlbumEl) filterAlbumEl.click();
        performSearch(PS_params, true);
    });
});
document.querySelectorAll<HTMLElement>(".filter-album .filter-actions .delete").forEach(function (btn) {
    btn.addEventListener("click", function () {
        updateFilters("album", "del");
        performSearch(PS_params, true);
        if (filterAlbumEl && !filterAlbumEl.classList.contains("filter-filled")) {
            filterAlbumEl.style.display = 'none';
            const albumController2 = document.querySelector<HTMLInputElement>(".filter-manager-controller.album");
            if (albumController2) albumController2.checked = false;
        }
    });
});

document.querySelectorAll<HTMLElement>(".add-album-button").forEach(function (btn) {
    btn.addEventListener("click", function () {
        linked_albums_open();
        set_up_popin();
    });
});

const linkedAlbumSearchInput = document.querySelector<HTMLInputElement>("#linkedAlbumSearch .search-input");
if (linkedAlbumSearchInput) {
    linkedAlbumSearchInput.addEventListener("input", function () {
        const cancelBtn = document.querySelector<HTMLElement>("#linkedAlbumSearch .search-cancel-linked-album");
        if (this.value != '0') {
            if (cancelBtn) cancelBtn.style.display = '';
        } else {
            if (cancelBtn) cancelBtn.style.display = 'none';
        }

        if (this.value.length > 0) {
            linked_albums_search(this.value);
        } else {
            const limitReached = document.querySelector<HTMLElement>(".limitReached");
            if (limitReached) limitReached.innerHTML = str_no_search_in_progress;
            const searchResult = document.getElementById("searchResult");
            if (searchResult) searchResult.innerHTML = '';
        }
    });
}

const filterAuthorsEl = document.querySelector<HTMLElement>(".filter-authors");
if (filterAuthorsEl) {
    filterAuthorsEl.addEventListener("click", function (e) {
        if (
            (e.target as Element).closest(".filter-form") !== null ||
            (e.target as Element).classList.contains("filter-form") ||
            (e.target as Element).classList.contains("remove")
        ) {
            return;
        }
        const filterAuthorForm = document.querySelector<HTMLElement>(".filter-author-form");
        if (!filterAuthorForm) return;
        toggleEl(filterAuthorForm, function () {
            if (isVisible(this)) {
                filterAuthorsEl.classList.add("show-filter-dropdown");
            } else {
                filterAuthorsEl.classList.remove("show-filter-dropdown");
                const authorVals = authorsEl && authorsEl.tomselect ? authorsEl.tomselect.getValue() as string[] : [];
                fields.author = { mode: "OR", words: authorVals };

                PS_params["authors"] = authorVals.length > 0 ? authorVals : "";
            }
        });
    });
}
document.querySelectorAll<HTMLElement>(".filter-authors .filter-validate").forEach(function (btn) {
    btn.addEventListener("click", function () {
        if (filterAuthorsEl) filterAuthorsEl.click();
        performSearch(PS_params, true);
    });
});
document.querySelectorAll<HTMLElement>(".filter-authors .filter-actions .delete").forEach(function (btn) {
    btn.addEventListener("click", function () {
        updateFilters("authors", "del");
        performSearch(PS_params, true);
        if (filterAuthorsEl && !filterAuthorsEl.classList.contains("filter-filled")) {
            filterAuthorsEl.style.display = 'none';
            const authorController2 = document.querySelector<HTMLInputElement>(".filter-manager-controller.author");
            if (authorController2) authorController2.checked = false;
        }
    });
});

const filterAddedByEl = document.querySelector<HTMLElement>(".filter-added_by");
if (filterAddedByEl) {
    filterAddedByEl.addEventListener("click", function (e) {
        if (
            (e.target as Element).closest(".filter-form") !== null ||
            (e.target as Element).classList.contains("filter-form") ||
            (e.target as Element).classList.contains("remove")
        ) {
            return;
        }
        const filterAddedByForm = document.querySelector<HTMLElement>(".filter-added_by-form");
        if (!filterAddedByForm) return;
        toggleEl(filterAddedByForm, function () {
            if (isVisible(this)) {
                filterAddedByEl.classList.add("show-filter-dropdown");
            } else {
                filterAddedByEl.classList.remove("show-filter-dropdown");

                added_by_array = [];
                document.querySelectorAll<HTMLInputElement>(".added_by-option input:checked").forEach(function (inp) {
                    added_by_array.push(inp.getAttribute("name") ?? '');
                });

                fields.added_by = added_by_array;

                PS_params["added_by"] =
                    added_by_array.length > 0 ? added_by_array : "";
            }
        });
    });
}
document.querySelectorAll<HTMLElement>(".filter-added_by .filter-validate").forEach(function (btn) {
    btn.addEventListener("click", function () {
        if (filterAddedByEl) filterAddedByEl.click();
        performSearch(PS_params, true);
    });
});
document.querySelectorAll<HTMLElement>(".filter-added_by .filter-actions .delete").forEach(function (btn) {
    btn.addEventListener("click", function () {
        updateFilters("added_by", "del");
        performSearch(PS_params, true);
        if (filterAddedByEl && !filterAddedByEl.classList.contains("filter-filled")) {
            filterAddedByEl.style.display = 'none';
            const addedByController2 = document.querySelector<HTMLInputElement>(".filter-manager-controller.added_by");
            if (addedByController2) addedByController2.checked = false;
        }
    });
});

const filterFiletypesEl = document.querySelector<HTMLElement>(".filter-filetypes");
if (filterFiletypesEl) {
    filterFiletypesEl.addEventListener("click", function (e) {
        if (
            (e.target as Element).closest(".filter-form") !== null ||
            (e.target as Element).classList.contains("filter-form") ||
            (e.target as Element).classList.contains("remove")
        ) {
            return;
        }
        const filterFiletypesForm = document.querySelector<HTMLElement>(".filter-filetypes-form");
        if (!filterFiletypesForm) return;
        toggleEl(filterFiletypesForm, function () {
            if (isVisible(this)) {
                filterFiletypesEl.classList.add("show-filter-dropdown");
            } else {
                filterFiletypesEl.classList.remove("show-filter-dropdown");

                filetypes_array = [];
                document.querySelectorAll<HTMLInputElement>(".filetypes-option input:checked").forEach(function (inp) {
                    filetypes_array.push(inp.getAttribute("name") ?? '');
                });

                fields.filetypes = filetypes_array;

                PS_params["filetypes"] =
                    filetypes_array.length > 0 ? filetypes_array : "";
            }
        });
    });
}
document.querySelectorAll<HTMLElement>(".filter-filetypes .filter-validate").forEach(function (btn) {
    btn.addEventListener("click", function () {
        if (filterFiletypesEl) filterFiletypesEl.click();
        performSearch(PS_params, true);
    });
});
document.querySelectorAll<HTMLElement>(".filter-filetypes .filter-actions .delete").forEach(function (btn) {
    btn.addEventListener("click", function () {
        updateFilters("filetypes", "del");
        performSearch(PS_params, true);
        if (filterFiletypesEl && !filterFiletypesEl.classList.contains("filter-filled")) {
            filterFiletypesEl.style.display = 'none';
            const filetypesController2 = document.querySelector<HTMLInputElement>(".filter-manager-controller.filetypes");
            if (filetypesController2) filetypesController2.checked = false;
        }
    });
});

function serializeParams(params: Record<string, unknown>): string {
    const parts: string[] = [];
    for (const key in params) {
        const val = params[key];
        if (Array.isArray(val)) {
            val.forEach(function (item: unknown) {
                parts.push(encodeURIComponent(key + "[]") + "=" + encodeURIComponent(String(item)));
            });
        } else if (val !== undefined && val !== null) {
            parts.push(encodeURIComponent(key) + "=" + encodeURIComponent(String(val as string | number | boolean)));
        }
    }
    return parts.join("&");
}

function performSearch(params: Record<string, unknown>, reload: boolean): void {
    fetch("ws.php?format=json&method=pwg.images.filteredSearch.create", {
        method: "POST",
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
        body: serializeParams(params),
    })
        .then(function (response) { return response.json(); })
        .then(function (data: { result?: { search_url?: string } }) {
            if (reload && data.result && typeof data.result.search_url !== "undefined") {
                reloadPage(data.result.search_url);
            }
            document.querySelectorAll<HTMLElement>(".filter-validate").forEach(function (el) {
                const validateText = el.querySelector<HTMLElement>(".validate-text");
                const loading = el.querySelector<HTMLElement>(".loading");
                if (validateText) validateText.style.display = 'block';
                if (loading) loading.style.display = 'none';
            });
            document.querySelectorAll<HTMLElement>(".remove-filter").forEach(function (el) {
                el.classList.remove(prefix_icon + "spin6", "animate-spin");
                el.classList.add(prefix_icon + "cancel");
            });
        })
        .catch(function (e) {
            console.log(e);
        });
}

function linked_albums_open(): void {
    const addLinkedAlbum = document.getElementById('addLinkedAlbum');
    if (addLinkedAlbum) (addLinkedAlbum).style.display = '';
    const searchInput = document.querySelector<HTMLInputElement>('.search-input');
    if (searchInput) { searchInput.value = ''; searchInput.focus(); }
    const searchResult = document.getElementById('searchResult');
    if (searchResult) searchResult.innerHTML = '';
    const limitReached = document.querySelector<HTMLElement>('.limitReached');
    if (limitReached) limitReached.innerHTML = str_no_search_in_progress;
}

interface AlbumCategory {
    id: string | number;
    name: string;
}

function linked_albums_search(searchText: string): void {
    const apiMethod = _albumSelectorData['apiMethod'] || 'pwg.categories.getList';
    const params = new URLSearchParams({ cat_id: '0', recursive: 'true', fullname: 'true', search: searchText });
    const searching = document.querySelector<HTMLElement>('.linkedAlbumPopInContainer .searching');
    if (searching) searching.style.display = '';
    fetch('ws.php?format=json&method=' + apiMethod, {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params,
    })
        .then(r => r.json())
        .then((raw_data: { result: { categories: AlbumCategory[]; limit_reached?: boolean } }) => {
            if (searching) searching.style.display = 'none';
            fill_results(raw_data.result.categories);
            const limitReached = document.querySelector<HTMLElement>('.limitReached');
            if (limitReached) {
                const count = raw_data.result.categories.length;
                if (raw_data.result.limit_reached) {
                    limitReached.innerHTML = (_albumSelectorData['strResultLimit'] || '').replace('%d', String(count));
                } else {
                    limitReached.innerHTML = count === 1
                        ? (_albumSelectorData['strAlbumFound'] || '')
                        : (_albumSelectorData['strAlbumsFound'] || '').replace('%d', String(count));
                }
            }
        })
        .catch(() => { if (searching) searching.style.display = 'none'; });
}

function set_up_popin(): void {
    document.querySelectorAll<HTMLElement>(".ClosePopIn").forEach(function (btn) {
        btn.addEventListener("click", function () {
            linked_albums_close();
        });
    });

    const addLinkedAlbum = document.getElementById("addLinkedAlbum");
    if (addLinkedAlbum) {
        addLinkedAlbum.addEventListener("keyup", function (e: KeyboardEvent) {
            if (e.keyCode === 27) {
                linked_albums_close();
            }
        });
    }
}

function linked_albums_close(): void {
    const addLinkedAlbum = document.getElementById("addLinkedAlbum");
    if (addLinkedAlbum) (addLinkedAlbum).style.display = 'none';
}

function fill_results(cats: AlbumCategory[]): void {
    const searchResult = document.getElementById("searchResult");
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

            const item = searchResult.querySelector<HTMLElement>(".search-result-item#" + cat.id);
            if (item) {
                item.addEventListener("click", function () {
                    add_related_category(cat.id, cat.name);
                });
            }
        }
    });
}

function add_related_category(cat_id: string | number, cat_link_path: string): void {
    const container = document.querySelector<HTMLElement>(".selected-categories-container");
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
    const invisibleSelect = document.querySelector<HTMLElement>(".invisible-related-categories-select");
    if (invisibleSelect) {
        invisibleSelect.insertAdjacentHTML(
            "beforeend",
            "<option selected value=" + cat_id + "></option>",
        );
    }

    const removeBtn = document.getElementById("" + cat_id);
    if (removeBtn) {
        removeBtn.addEventListener("click", function () {
            remove_related_category(this.getAttribute("id") ?? '');
        });
    }

    linked_albums_close();
}

function remove_related_category(cat_id: string): void {
    const el = document.getElementById(cat_id);
    if (el && el.parentElement) {
        el.parentElement.remove();
    }

    const cat_to_remove_index = related_categories_ids.indexOf(parseInt(cat_id));
    if (cat_to_remove_index > -1) {
        related_categories_ids.splice(cat_to_remove_index, 1);
    }
    if (related_categories_ids.length === 0) {
        related_categories_ids = [];
    }
}

function updateFilters(filterName: string, mode: string): void {
    switch (filterName) {
        case "word":
            if (mode == "add") {
                fields.allwords = { words: [], mode: 'AND', fields: [] };
                PS_params["allwords"] = "";
                PS_params["allwords_mode"] = "AND";
                PS_params["allwords_fields"] = [];
            } else if (mode == "del") {
                delete fields.allwords;
                delete PS_params["allwords"];
                delete PS_params["allwords_mode"];
                delete PS_params["allwords_fields"];
            }
            break;

        case "tag":
            if (mode == "add") {
                fields.tags = { words: [], mode: 'AND' };
                PS_params["tags"] = "";
                PS_params["tags_mode"] = "AND";
            } else if (mode == "del") {
                delete fields.tags;
                delete PS_params["tags"];
                delete PS_params["tags_mode"];
            }
            break;

        case "album":
            if (mode == "add") {
                fields.cat = { words: [], sub_inc: false };
                PS_params["categories"] = "";
                PS_params["categories_withsubs"] = false;
            } else if (mode == "del") {
                delete fields.cat;
                delete PS_params["categories"];
                delete PS_params["categories_withsubs"];
            }
            break;

        default:
            if (mode == "add") {
                fields[filterName] = {};
                PS_params[filterName] = "";
            } else if (mode == "del") {
                delete fields[filterName];
                delete PS_params[filterName];
            }
            break;
    }
}

function reloadPage(url: string): void {
    window.location.href = url;
}

function resize_filter_form(): void {
    document.querySelectorAll<HTMLElement>(".form_mobile_arrow").forEach(function (el) { el.remove(); });
    document.querySelectorAll<HTMLElement>(".filter").forEach(function (filterEl) {
        const window_width = window.innerWidth;
        const rect = filterEl.getBoundingClientRect();
        const left_distance = rect.left + window.scrollX;
        const filter_form = filterEl.querySelector<HTMLElement>(".filter-form");
        if (!filter_form) return;
        const filter_form_width = filter_form.clientWidth;
        const too_left = left_distance + filterEl.clientWidth - filter_form_width;
        const is_desktop = window.matchMedia("(min-width: 600px)").matches;
        filter_form.style.left = "0px";
        const margin_left = is_desktop ? 15 : 0;

        if (left_distance + filter_form_width > window_width) {
            const check_left =
                too_left < 0 ? Math.abs(too_left - margin_left) : 0;
            const mobile_marg = is_desktop ? 0 : 2;
            const replace_form_width =
                -filter_form_width +
                filterEl.clientWidth +
                check_left -
                mobile_marg;
            filter_form.style.left = replace_form_width + "px";
        }
        if (!is_desktop) {
            const left_arrow = left_distance + filterEl.clientWidth / 2;
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
});

document.addEventListener("DOMContentLoaded", initMCS);
}
