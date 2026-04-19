import { initModule } from './moduleInit.js';
import { set_up_popin, linked_albums_open, linked_albums_close, linked_albums_search } from './album_selector.js';
import { CategoriesCache, TagsCache } from './LocalStorageCache.js';
import { pwgDatepicker } from './datepicker.js';
import { pwgConfirm } from './pwgConfirm.js';
import GLightbox from 'glightbox';

interface CacheKeys {
    categories?: string;
    tags?: string;
    _hash?: string;
}

interface PictureModifyConfig {
    CACHE_KEYS?: CacheKeys;
    ROOT_URL?: string;
    str_create?: string;
    str_cancel?: string;
    str_are_you_sure?: string;
    str_yes_delete?: string;
    str_no_change?: string;
    url_delete?: string;
    str_albums_found?: string;
    str_album_found?: string;
    str_result_limit?: string;
    str_orphan?: string;
    str_no_search_in_progress?: string;
    related_categories_ids?: (string | number)[];
    str_already_in_related_cats?: string;
}

interface CatResult {
    id: string | number;
    fullname: string;
    full_name_with_admin_links: string;
    uppercats: string;
}

export function init(cfg: PictureModifyConfig): void {
    const {
        CACHE_KEYS, ROOT_URL = '', str_create = 'Create', str_cancel = 'Cancel',
        str_are_you_sure = 'Are you sure?', str_yes_delete = 'Yes, delete',
        str_no_change = 'No', url_delete = '', str_orphan = '',
        str_no_search_in_progress = '', str_already_in_related_cats = '',
        related_categories_ids = [],
    } = cfg;

    const mutableIds: (string | number)[] = [...related_categories_ids];

    function fill_results(cats: CatResult[]): void {
        const searchResult = document.getElementById('searchResult');
        if (!searchResult) return;
        searchResult.innerHTML = '';
        cats.forEach(function (cat) {
            searchResult.insertAdjacentHTML('beforeend',
                "<div class='search-result-item' id=" + cat.id + ">"
                + "<span class='search-result-path'>" + cat.fullname + "</span>"
                + "<span id=" + cat.id + " class='icon-plus-circled item-add'></span></div>",
            );

            if (mutableIds.includes(cat.id)) {
                const itemAdd = searchResult.querySelector<HTMLElement>('.search-result-item #' + cat.id + '.item-add');
                if (itemAdd) {
                    itemAdd.classList.add('notClickable');
                    itemAdd.setAttribute('title', str_already_in_related_cats);
                    itemAdd.addEventListener('click', e => e.preventDefault());
                }
                searchResult.querySelectorAll<HTMLElement>('.search-result-item').forEach(function (item) {
                    item.classList.add('notClickable');
                    item.setAttribute('title', str_already_in_related_cats);
                    item.addEventListener('click', e => e.preventDefault());
                });
            } else {
                const resultItem = searchResult.querySelector<HTMLElement>('.search-result-item#' + cat.id);
                if (resultItem) {
                    resultItem.addEventListener('click', function () {
                        add_related_category(cat.id, cat.full_name_with_admin_links);
                    });
                }
            }
        });
    }

    function remove_related_category(cat_id: string | number): void {
        const option = document.querySelector(".invisible-related-categories-select option[value='" + cat_id + "']");
        if (option) option.remove();
        const invisibleSelect = document.querySelector<HTMLElement>('.invisible-related-categories-select');
        if (invisibleSelect) invisibleSelect.dispatchEvent(new Event('change'));
        const el = document.getElementById(String(cat_id));
        if (el?.parentElement) el.parentElement.remove();

        const idx = mutableIds.indexOf(cat_id);
        if (idx > -1) mutableIds.splice(idx, 1);

        check_related_categories();
    }

    function add_related_category(cat_id: string | number, cat_link_path: string): void {
        if (!mutableIds.includes(cat_id)) {
            const container = document.querySelector<HTMLElement>('.related-categories-container');
            if (container) {
                container.insertAdjacentHTML('beforeend',
                    "<div class='breadcrumb-item'>"
                    + "<span class='link-path'>" + cat_link_path + "</span>"
                    + "<span id=" + cat_id + " class='icon-cancel-circled remove-item'></span></div>",
                );
            }
            const icon = document.querySelector<HTMLElement>('.search-result-item #' + cat_id);
            if (icon) icon.classList.add('notClickable');

            mutableIds.push(cat_id);

            const invisibleSelect = document.querySelector<HTMLElement>('.invisible-related-categories-select');
            if (invisibleSelect) {
                invisibleSelect.insertAdjacentHTML('beforeend', '<option selected value=' + cat_id + '></option>');
                invisibleSelect.dispatchEvent(new Event('change'));
            }

            const removeBtn = document.getElementById(String(cat_id));
            if (removeBtn) {
                removeBtn.addEventListener('click', function () {
                    remove_related_category(removeBtn.getAttribute('id') ?? '');
                });
            }

            linked_albums_close();
        }
        check_related_categories();
    }

    function check_related_categories(): void {
        document.querySelectorAll<HTMLElement>('.linked-albums-badge').forEach(el => { el.innerHTML = String(mutableIds.length); });

        if (mutableIds.length === 0) {
            document.querySelectorAll<HTMLElement>('.linked-albums-badge').forEach(el => el.classList.add('badge-red'));
            document.querySelectorAll<HTMLElement>('.add-item').forEach(el => el.classList.add('highlight'));
            document.querySelectorAll<HTMLElement>('.orphan-photo').forEach(el => { el.innerHTML = str_orphan; el.style.display = ''; });
        } else {
            document.querySelectorAll<HTMLElement>('.linked-albums-badge.badge-red').forEach(el => el.classList.remove('badge-red'));
            document.querySelectorAll<HTMLElement>('.add-item.highlight').forEach(el => el.classList.remove('highlight'));
            document.querySelectorAll<HTMLElement>('.orphan-photo').forEach(el => { el.style.display = 'none'; });
        }
    }

    const categoriesCache = new CategoriesCache({
        serverKey: CACHE_KEYS?.categories ?? '',
        serverId: CACHE_KEYS?._hash ?? '',
        rootUrl: ROOT_URL,
    });
    categoriesCache.selectize(document.querySelectorAll('[data-selectize=categories]'));

    const tagsCache = new TagsCache({
        serverKey: CACHE_KEYS?.tags ?? '',
        serverId: CACHE_KEYS?._hash ?? '',
        rootUrl: ROOT_URL,
    });
    tagsCache.selectize(document.querySelectorAll('[data-selectize=tags]'), {
        lang: { Add: str_create },
    });

    pwgDatepicker(document.querySelectorAll('[data-datepicker]'), {
        showTimepicker: true,
        cancelButton: str_cancel,
    });

    GLightbox({ selector: 'a.preview-box' });

    const deleteBtn = document.getElementById('action-delete-picture');
    if (deleteBtn) {
        deleteBtn.addEventListener('click', function () {
            pwgConfirm({
                title: str_are_you_sure,
                buttons: {
                    confirm: {
                        text: str_yes_delete,
                        btnClass: 'btn-red',
                        action: function () {
                            window.location.href = url_delete.replaceAll('amp;', '');
                        },
                    },
                    cancel: { text: str_no_change },
                },
            });
        });
    }

    document.querySelectorAll<HTMLElement>('.linked-albums.add-item').forEach(function (btn) {
        btn.addEventListener('click', function () { linked_albums_open(); set_up_popin(); });
    });

    const limitReached = document.querySelector<HTMLElement>('.limitReached');
    if (limitReached) limitReached.innerHTML = str_no_search_in_progress;
    document.querySelectorAll<HTMLElement>('.search-cancel-linked-album').forEach(el => { el.style.display = 'none'; });
    document.querySelectorAll<HTMLElement>('.linkedAlbumPopInContainer .searching').forEach(el => { el.style.display = 'none'; });

    const linkedAlbumSearchInput = document.querySelector<HTMLInputElement>('#linkedAlbumSearch .search-input');
    if (linkedAlbumSearchInput) {
        linkedAlbumSearchInput.addEventListener('input', function () {
            const cancelBtn = document.querySelector<HTMLElement>('#linkedAlbumSearch .search-cancel-linked-album');
            if (linkedAlbumSearchInput.value !== '0' && linkedAlbumSearchInput.value !== '') {
                if (cancelBtn) cancelBtn.style.display = '';
            } else {
                if (cancelBtn) cancelBtn.style.display = 'none';
            }
            if (linkedAlbumSearchInput.value.length > 0) {
                linked_albums_search(linkedAlbumSearchInput.value);
            } else {
                const lr = document.querySelector<HTMLElement>('.limitReached');
                if (lr) lr.innerHTML = str_no_search_in_progress;
                const searchResult = document.getElementById('searchResult');
                if (searchResult) searchResult.innerHTML = '';
            }
        });
    }

    document.querySelectorAll<HTMLButtonElement>('.search-cancel-linked-album').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const searchInput = document.querySelector<HTMLInputElement>('#linkedAlbumSearch .search-input');
            if (searchInput) { searchInput.value = ''; searchInput.dispatchEvent(new Event('input')); }
        });
    });

    document.querySelectorAll<HTMLElement>('.related-categories-container .breadcrumb-item .remove-item').forEach(function (btn) {
        btn.addEventListener('click', function () {
            remove_related_category(btn.getAttribute('id') ?? '');
        });
    });

    let form_unsaved = false;
    let user_interacted = false;
    const pictureModify = document.getElementById('pictureModify');
    if (pictureModify) {
        pictureModify.querySelectorAll<HTMLElement>('input, select, textarea, button').forEach(function (input) {
            input.addEventListener('focus', function () { user_interacted = true; });
            input.addEventListener('change', function () {
                if (user_interacted) form_unsaved = true;
            });
        });
        pictureModify.addEventListener('submit', function () { form_unsaved = false; });
    }
    window.addEventListener('beforeunload', function () {
        if (form_unsaved) return 'Some changes are not registered';
        return undefined;
    });

    void fill_results;
}

initModule(init as (cfg: Record<string, unknown>) => void);
