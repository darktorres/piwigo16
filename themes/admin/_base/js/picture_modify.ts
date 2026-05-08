import { AlbumSelector } from './album_selector';
import { TagsCache, CategoriesCache } from './LocalStorageCache';
import { getPageData } from './page-data';
import GLightbox from 'glightbox';
import 'glightbox/dist/css/glightbox.css';

interface PictureModifyPageData {
    CACHE_KEYS: { tags: string; categories: string; _hash: string };
    ROOT_URL: string;
    associated_albums: Record<string, unknown>;
    str_create: string;
    str_assoc_album_ab: string;
    related_categories_ids: (string | number)[];
    str_orphan: string;
    str_are_you_sure: string;
    str_yes: string;
    str_no: string;
    str_cancel: string;
    url_delete: string;
}

const pageData = getPageData<PictureModifyPageData>('pwg-picture-modify-data');

// Initialize caches
const categoriesCache = new CategoriesCache({
    serverKey: pageData.CACHE_KEYS.categories,
    serverId: pageData.CACHE_KEYS._hash,
    rootUrl: pageData.ROOT_URL,
});

const tagsCache = new TagsCache({
    serverKey: pageData.CACHE_KEYS.tags,
    serverId: pageData.CACHE_KEYS._hash,
    rootUrl: pageData.ROOT_URL,
});

categoriesCache.selectize(document.querySelector('[data-selectize=categories]'));

tagsCache.selectize(document.querySelector('[data-selectize=tags]'), {
    lang: {
        Add: pageData.str_create,
    },
});

document.addEventListener('DOMContentLoaded', () => {
    // Re-initialise datepickers with timepicker enabled (datepicker.ts auto-init
    // uses defaults; here we want time + a translated cancel button).
    document.querySelectorAll<HTMLElement>('[data-datepicker]').forEach((el) => {
        (
            window as { pwgDatepicker?: (el: HTMLElement, opts: Record<string, unknown>) => void }
        ).pwgDatepicker?.(el, {
            showTimepicker: true,
            cancelButton: pageData.str_cancel,
        });
    });

    GLightbox({ selector: 'a.preview-box' });

    document.getElementById('action-delete-picture')?.addEventListener('click', () => {
        if (window.confirm(pageData.str_are_you_sure)) {
            window.location.href = pageData.url_delete;
        }
    });

    const ab = new AlbumSelector({
        selectedCategoriesIds: pageData.related_categories_ids,
        selectAlbum: add_related_category,
        removeSelectedAlbum: remove_related_category,
        adminMode: true,
        modalTitle: pageData.str_assoc_album_ab,
    });

    document.querySelector('.linked-albums.add-item')?.addEventListener('click', () => ab.open());

    document
        .querySelector('.related-categories-container')
        ?.addEventListener('click', (e: Event) => {
            const target = e.target as HTMLElement;
            if (target.classList.contains('remove-item')) {
                ab.remove_selected_album(target.id);
            }
        });

    let form_unsaved = false;
    let user_interacted = false;

    document
        .querySelectorAll('#pictureModify input, #pictureModify textarea, #pictureModify select')
        .forEach((el) => {
            el.addEventListener('focus', () => {
                user_interacted = true;
            });
            el.addEventListener('change', () => {
                if (user_interacted) form_unsaved = true;
            });
        });

    window.addEventListener('beforeunload', (e) => {
        if (form_unsaved) {
            e.preventDefault();
            e.returnValue = 'Some changes are not registered';
        }
    });

    document.getElementById('pictureModify')?.addEventListener('submit', () => {
        form_unsaved = false;
    });
});

function remove_related_category({
    id_album,
    getSelectedAlbum,
}: {
    id_album: string | number;
    getSelectedAlbum: () => (string | number)[];
}): void {
    document
        .querySelector<HTMLOptionElement>(
            `.invisible-related-categories-select option[value="${id_album}"]`
        )
        ?.remove();
    document
        .querySelector('.invisible-related-categories-select')
        ?.dispatchEvent(new Event('change'));
    document.getElementById(String(id_album))?.parentElement?.remove();
    check_related_categories(getSelectedAlbum());
}

function add_related_category({
    album,
    addSelectedAlbum,
    getSelectedAlbum,
}: {
    album: { id: number | string; full_name_with_admin_links?: string };
    addSelectedAlbum: () => void;
    getSelectedAlbum: () => (string | number)[];
}): void {
    if (!getSelectedAlbum().includes(album.id)) {
        document
            .querySelector('.related-categories-container')
            ?.insertAdjacentHTML(
                'beforeend',
                `<div class="breadcrumb-item"><span class="link-path">${album.full_name_with_admin_links ?? ''}</span><span id="${album.id}" class="icon-cancel-circled remove-item"></span></div>`
            );
        document
            .querySelector<HTMLElement>(`.search-result-item #${album.id}`)
            ?.classList.add('notClickable');
        const sel = document.querySelector('.invisible-related-categories-select');
        sel?.insertAdjacentHTML('beforeend', `<option selected value="${album.id}"></option>`);
        sel?.dispatchEvent(new Event('change'));
        addSelectedAlbum();
    }
    check_related_categories(getSelectedAlbum());
}

function check_related_categories(selected_cat: (string | number)[]): void {
    document.querySelectorAll<HTMLElement>('.linked-albums-badge').forEach((el) => {
        el.innerHTML = String(selected_cat.length);
    });
    if (selected_cat.length === 0) {
        document
            .querySelectorAll('.linked-albums-badge')
            .forEach((el) => el.classList.add('badge-red'));
        document.querySelectorAll('.add-item').forEach((el) => el.classList.add('highlight'));
        document.querySelectorAll<HTMLElement>('.orphan-photo').forEach((el) => {
            el.innerHTML = pageData.str_orphan;
            el.style.display = '';
        });
    } else {
        document
            .querySelectorAll('.linked-albums-badge.badge-red')
            .forEach((el) => el.classList.remove('badge-red'));
        document
            .querySelectorAll('.add-item.highlight')
            .forEach((el) => el.classList.remove('highlight'));
        document.querySelectorAll<HTMLElement>('.orphan-photo').forEach((el) => {
            el.style.display = 'none';
        });
    }
}

export {};
