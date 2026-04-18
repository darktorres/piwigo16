import { initModule } from './moduleInit.js';
import { set_up_popin, linked_albums_open, linked_albums_close, linked_albums_search } from './album_selector.js';
import { CategoriesCache, TagsCache } from './LocalStorageCache.js';
import { pwgDatepicker } from './datepicker.js';
import { pwgConfirm } from './pwgConfirm.js';
import GLightbox from 'glightbox';

export function init(cfg) {
    const { CACHE_KEYS, ROOT_URL, str_create, str_cancel, str_are_you_sure, str_yes_delete, str_no_change, url_delete, str_albums_found, str_album_found, str_result_limit, str_orphan, str_no_search_in_progress, related_categories_ids, str_already_in_related_cats } = cfg;

    var categoriesCache = new CategoriesCache({
      serverKey: CACHE_KEYS?.categories || '',
      serverId: CACHE_KEYS?._hash || '',
      rootUrl: ROOT_URL || ''
    });
    categoriesCache.selectize(document.querySelectorAll('[data-selectize=categories]'));

    var tagsCache = new TagsCache({
      serverKey: CACHE_KEYS?.tags || '',
      serverId: CACHE_KEYS?._hash || '',
      rootUrl: ROOT_URL || ''
    });
    tagsCache.selectize(document.querySelectorAll('[data-selectize=tags]'), {
      lang: {
        'Add': str_create || 'Create'
      }
    });

    pwgDatepicker(document.querySelectorAll('[data-datepicker]'), {
      showTimepicker: true,
      cancelButton: str_cancel || 'Cancel'
    });

    GLightbox({ selector: 'a.preview-box' });

    var deleteBtn = document.getElementById('action-delete-picture');
    if (deleteBtn) {
      deleteBtn.addEventListener('click', function() {
        pwgConfirm({
          title: str_are_you_sure || 'Are you sure?',
          buttons: {
            confirm: {
              text: str_yes_delete || 'Yes, delete',
              btnClass: 'btn-red',
              action: function() {
                window.location.href = (url_delete || '').replaceAll('amp;', '');
              }
            },
            cancel: {
              text: str_no_change || 'No, I have changed my mind'
            }
          }
        });
      });
    }

    document.querySelectorAll(".linked-albums.add-item").forEach(function (btn) {
        btn.addEventListener("click", function () {
            linked_albums_open();
            set_up_popin();
        });
    });

    var limitReached = document.querySelector(".limitReached");
    if (limitReached) limitReached.innerHTML = str_no_search_in_progress;
    document.querySelectorAll(".search-cancel-linked-album").forEach(function (el) {
        el.style.display = 'none';
    });
    document.querySelectorAll(".linkedAlbumPopInContainer .searching").forEach(function (el) {
        el.style.display = 'none';
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

    document.querySelectorAll(".search-cancel-linked-album").forEach(function (btn) {
        btn.addEventListener("click", function () {
            var searchInput = document.querySelector("#linkedAlbumSearch .search-input");
            if (searchInput) {
                searchInput.value = "";
                searchInput.dispatchEvent(new Event("input"));
            }
        });
    });

    document.querySelectorAll(".related-categories-container .breadcrumb-item .remove-item").forEach(function (btn) {
        btn.addEventListener("click", function () {
            remove_related_category(this.getAttribute("id"));
        });
    });

    // Unsaved settings message before leave this page
    let form_unsaved = false;
    let user_interacted = false;
    var pictureModify = document.getElementById("pictureModify");
    if (pictureModify) {
        pictureModify.querySelectorAll("input, select, textarea, button").forEach(function (input) {
            input.addEventListener("focus", function () {
                user_interacted = true;
            });
            input.addEventListener("change", function () {
                if (user_interacted) {
                    form_unsaved = true;
                    console.log(this.name, this);
                }
            });
        });
        pictureModify.addEventListener("submit", function () {
            form_unsaved = false;
        });
    }
    window.addEventListener("beforeunload", function () {
        if (form_unsaved) {
            return "Some changes are not registered";
        }
    });
}

function fill_results(cats) {
    var searchResult = document.getElementById("searchResult");
    if (!searchResult) return;
    searchResult.innerHTML = '';
    cats.forEach(function (cat) {
        searchResult.insertAdjacentHTML(
            "beforeend",
            "<div class='search-result-item' id=" +
                cat.id +
                ">" +
                "<span class='search-result-path'>" +
                cat.fullname +
                "</span><span id=" +
                cat.id +
                " class='icon-plus-circled item-add'></span>" +
                "</div>",
        );

        if (related_categories_ids.includes(cat.id)) {
            var itemAdd = document.querySelector(".search-result-item #" + cat.id + ".item-add");
            if (itemAdd) {
                itemAdd.classList.add("notClickable");
                itemAdd.setAttribute("title", str_already_in_related_cats);
                itemAdd.addEventListener("click", function (event) {
                    event.preventDefault();
                });
            }
            document.querySelectorAll(".search-result-item").forEach(function (item) {
                item.classList.add("notClickable");
                item.setAttribute("title", str_already_in_related_cats);
                item.addEventListener("click", function (event) {
                    event.preventDefault();
                });
            });
        } else {
            var resultItem = searchResult.querySelector(".search-result-item#" + cat.id);
            if (resultItem) {
                resultItem.addEventListener("click", function () {
                    add_related_category(cat.id, cat.full_name_with_admin_links);
                });
            }
        }
    });
}

function remove_related_category(cat_id) {
    var option = document.querySelector(".invisible-related-categories-select option[value='" + cat_id + "']");
    if (option) option.remove();
    var invisibleSelect = document.querySelector(".invisible-related-categories-select");
    if (invisibleSelect) invisibleSelect.dispatchEvent(new Event("change"));
    var el = document.getElementById(cat_id);
    if (el && el.parentElement) el.parentElement.remove();

    var cat_to_remove_index = related_categories_ids.indexOf(cat_id);
    if (cat_to_remove_index > -1) {
        related_categories_ids.splice(cat_to_remove_index, 1);
    }

    check_related_categories();
}

function add_related_category(cat_id, cat_link_path) {
    if (!related_categories_ids.includes(cat_id)) {
        var container = document.querySelector(".related-categories-container");
        if (container) {
            container.insertAdjacentHTML(
                "beforeend",
                "<div class='breadcrumb-item'>" +
                    "<span class='link-path'>" +
                    cat_link_path +
                    "</span><span id=" +
                    cat_id +
                    " class='icon-cancel-circled remove-item'></span>" +
                    "</div>",
            );
        }

        var searchItemIcon = document.querySelector(".search-result-item #" + cat_id);
        if (searchItemIcon) searchItemIcon.classList.add("notClickable");

        related_categories_ids.push(cat_id);

        var invisibleSelect = document.querySelector(".invisible-related-categories-select");
        if (invisibleSelect) {
            invisibleSelect.insertAdjacentHTML("beforeend", "<option selected value=" + cat_id + "></option>");
            invisibleSelect.dispatchEvent(new Event("change"));
        }

        var removeBtn = document.getElementById("" + cat_id);
        if (removeBtn) {
            removeBtn.addEventListener("click", function () {
                remove_related_category(this.getAttribute("id"));
            });
        }

        linked_albums_close();
    }

    check_related_categories();
}

function check_related_categories() {
    document.querySelectorAll(".linked-albums-badge").forEach(function (el) {
        el.innerHTML = related_categories_ids.length;
    });

    if (related_categories_ids.length == 0) {
        document.querySelectorAll(".linked-albums-badge").forEach(function (el) {
            el.classList.add("badge-red");
        });
        document.querySelectorAll(".add-item").forEach(function (el) {
            el.classList.add("highlight");
        });
        document.querySelectorAll(".orphan-photo").forEach(function (el) {
            el.innerHTML = str_orphan;
            el.style.display = '';
        });
    } else {
        document.querySelectorAll(".linked-albums-badge.badge-red").forEach(function (el) {
            el.classList.remove("badge-red");
        });
        document.querySelectorAll(".add-item.highlight").forEach(function (el) {
            el.classList.remove("highlight");
        });
        document.querySelectorAll(".orphan-photo").forEach(function (el) {
            el.style.display = 'none';
        });
    }
}

initModule(init);
