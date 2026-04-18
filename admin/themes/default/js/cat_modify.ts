import { initModule } from './moduleInit.js';
import { pwgConfirm } from './pwgConfirm.js';
import tippy from 'tippy.js';
import { set_up_popin, linked_albums_open, linked_albums_close, linked_albums_search } from './album_selector.js';

interface CatModifyConfig {
    hasImagesAssociatedOutside?: boolean;
    hasImagesBecomingOrphans?: boolean;
    hasImagesRecursives?: boolean;
    catNav?: string;
    albumId?: string;
    parentAlbum?: string | number;
    defaultParentAlbum?: string | number;
    albumName?: string;
    nbSubAlbums?: number;
    pwgToken?: string;
    uDelete?: string;
    isVisible?: string;
    strCancel?: string;
    strDeleteAlbum?: string;
    strDeleteAlbumAndHisXSubalbums?: string;
    strJustNow?: string;
    strDontDeletePhotos?: string;
    strDeleteOrphans?: string;
    strDeleteAllPhotos?: string;
    strAlbumsFound?: string;
    strAlbumFound?: string;
    strResultLimit?: string;
    strOrphan?: string;
    strNoSearchInProgress?: string;
    strAlreadyInRelatedCats?: string;
    strAlbumCommentAllow?: string;
    strAlbumCommentDisallow?: string;
    strRoot?: string;
}

interface CatResult {
    id: string | number;
    fullname: string;
    full_name_with_admin_links: string;
    uppercats: string;
}

interface OrphanData {
    nb_images_recursive?: number;
    nb_images_associated_outside?: number;
    nb_images_becoming_orphan?: number;
}

// Module-level config - initialized by init()
let album_id = '';
let parent_album: string | number = 0;
let default_parent_album: string | number = 0;
let album_name = '';
let nb_sub_albums = 0;
let pwg_token = '';
let u_delete = '';
let is_visible = '';
let str_cancel = '';
let str_delete_album = '';
let str_delete_album_and_his_x_subalbums = '';
let str_just_now = '';
let str_dont_delete_photos = '';
let str_delete_orphans = '';
let str_delete_all_photos = '';
let str_no_search_in_progress = '';
let str_already_in_related_cats = '';
let str_album_comment_allow = '';
let str_album_comment_disallow = '';
let str_root = '';

export function init(cfg: CatModifyConfig): void {
    album_id = cfg.albumId ?? '';
    parent_album = cfg.parentAlbum ?? 0;
    default_parent_album = cfg.defaultParentAlbum ?? 0;
    album_name = cfg.albumName ?? '';
    nb_sub_albums = cfg.nbSubAlbums ?? 0;
    pwg_token = cfg.pwgToken ?? '';
    u_delete = cfg.uDelete ?? '';
    is_visible = cfg.isVisible ?? '';
    str_cancel = cfg.strCancel ?? '';
    str_delete_album = cfg.strDeleteAlbum ?? '';
    str_delete_album_and_his_x_subalbums = cfg.strDeleteAlbumAndHisXSubalbums ?? '';
    str_just_now = cfg.strJustNow ?? '';
    str_dont_delete_photos = cfg.strDontDeletePhotos ?? '';
    str_delete_orphans = cfg.strDeleteOrphans ?? '';
    str_delete_all_photos = cfg.strDeleteAllPhotos ?? '';
    str_no_search_in_progress = cfg.strNoSearchInProgress ?? '';
    str_already_in_related_cats = cfg.strAlreadyInRelatedCats ?? '';
    str_album_comment_allow = cfg.strAlbumCommentAllow ?? '';
    str_album_comment_disallow = cfg.strAlbumCommentDisallow ?? '';
    str_root = cfg.strRoot ?? '';

    activateCommentDropdown();
    checkAlbumLock();

    document.querySelector<HTMLElement>('.unlock-album')?.addEventListener('click', function () {
        fetch('ws.php?format=json&method=pwg.categories.setInfo', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ category_id: String(album_id), visible: 'true' }),
        })
            .then(r => r.json())
            .then(function (data: { stat: string }) {
                if (data.stat === 'ok') {
                    is_visible = 'true';
                    if ((document.getElementById('cat-locked') as HTMLInputElement | null)?.checked) {
                        document.querySelector<HTMLElement>("input[id='cat-locked']")?.click();
                    }
                    checkAlbumLock();
                    setTimeout(() => { document.querySelector<HTMLElement>('.info-message')!.style.display = 'none'; }, 5000);
                } else {
                    document.querySelector<HTMLElement>('.info-error')!.style.display = '';
                    setTimeout(() => { document.querySelector<HTMLElement>('.info-error')!.style.display = 'none'; }, 5000);
                }
            })
            .catch(function (error: unknown) {
                save_button_set_loading(false);
                document.querySelector<HTMLElement>('.info-error')!.style.display = '';
                setTimeout(() => { document.querySelector<HTMLElement>('.info-error')!.style.display = 'none'; }, 5000);
                console.log(error);
            });
    });

    tippy('.tiptip', { delay: 0, placement: 'top' });

    document.getElementById('cat-properties-save')?.addEventListener('click', function () {
        save_button_set_loading(true);
        document.querySelector<HTMLElement>('.info-error,.info-message')!.style.display = 'none';

        fetch('ws.php?format=json&method=pwg.categories.setInfo', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                category_id: String(album_id),
                name: (document.getElementById('cat-name') as HTMLInputElement).value,
                comment: (document.getElementById('cat-comment') as HTMLTextAreaElement).value,
                visible: (document.getElementById('cat-locked') as HTMLInputElement).checked ? 'false' : 'true',
                commentable: (document.getElementById('cat-commentable') as HTMLInputElement).checked ? 'true' : 'false',
                pwg_token,
            }),
        })
            .then(r => r.json())
            .then(function (data: { stat: string }) {
                if (data.stat === 'ok') {
                    save_button_set_loading(false);
                    document.querySelector<HTMLElement>('.info-message')!.style.display = '';
                    document.querySelector<HTMLElement>('.cat-modification .cat-modify-info-subcontent')!.innerHTML = str_just_now;
                    document.querySelector<HTMLElement>('.cat-modification .cat-modify-info-content')!.innerHTML = str_just_now;
                    is_visible = (document.getElementById('cat-locked') as HTMLInputElement).checked ? 'false' : 'true';
                    checkAlbumLock();
                    setTimeout(() => { document.querySelector<HTMLElement>('.info-message')!.style.display = 'none'; }, 5000);
                } else {
                    document.querySelector<HTMLElement>('.info-error')!.style.display = '';
                    setTimeout(() => { document.querySelector<HTMLElement>('.info-error')!.style.display = 'none'; }, 5000);
                }
            })
            .catch(function (error: unknown) {
                save_button_set_loading(false);
                document.querySelector<HTMLElement>('.info-error')!.style.display = '';
                setTimeout(() => { document.querySelector<HTMLElement>('.info-error')!.style.display = 'none'; }, 5000);
                console.log(error);
            });

        if (parent_album !== default_parent_album) {
            fetch('ws.php?format=json&method=pwg.categories.move', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    category_id: String(album_id),
                    parent: String(parent_album),
                    pwg_token,
                }),
            })
                .then(r => r.json())
                .then(function (data: { stat: string; result: { new_ariane_string: string } }) {
                    if (data.stat === 'ok') {
                        document.querySelector<HTMLElement>('.cat-modify-ariane')!.innerHTML = data.result.new_ariane_string;
                        default_parent_album = parent_album;
                    } else {
                        document.querySelector<HTMLElement>('.info-error')!.style.display = '';
                        setTimeout(() => { document.querySelector<HTMLElement>('.info-error')!.style.display = 'none'; }, 5000);
                    }
                })
                .catch(function (e: Error) { console.log(e.message); });
        }
    });

    function save_button_set_loading(state = true): void {
        const icon = document.querySelector<HTMLElement>('#cat-properties-save i')!;
        if (state) {
            icon.classList.remove('icon-floppy');
            icon.classList.add('icon-spin6', 'animate-spin');
        } else {
            icon.classList.add('icon-floppy');
            icon.classList.remove('icon-spin6', 'animate-spin');
        }
        (document.getElementById('cat-properties-save') as HTMLButtonElement).disabled = state;
    }

    document.querySelector<HTMLElement>('.deleteAlbum')?.addEventListener('click', function () {
        fetch('ws.php?format=json&method=pwg.categories.calculateOrphans&' + new URLSearchParams({ category_id: String(album_id) }))
            .then(r => r.json())
            .then(function (raw_json: { result: OrphanData[] }) {
                const orphan = raw_json.result[0];

                let message = '<p>' + str_delete_album_and_his_x_subalbums
                    .replace('%s', '<strong>' + album_name + '</strong>')
                    .replace('%d', '<strong>' + String(nb_sub_albums) + '</strong>') + '</p>';

                message += `<div class="cat-delete-modes">`;
                message += `<div ${orphan.nb_images_recursive ? '' : "style='display:none'"}>
                    <input type="radio" name="deletion-mode" value="no_delete" id="no_delete" checked>
                    <label for="no_delete">${str_dont_delete_photos}</label>
                  </div>`;

                if (orphan.nb_images_recursive) {
                    let t = 0;
                    message += `<div>
                    <input type="radio" name="deletion-mode" value="force_delete" id="force_delete">
                    <label for="force_delete">${str_delete_all_photos.replaceAll('%d', () => String([orphan.nb_images_recursive ?? 0, orphan.nb_images_associated_outside ?? 0][t++] ?? 0))}</label>
                  </div>`;
                }

                if (orphan.nb_images_becoming_orphan) {
                    message += `<div>
                    <input type="radio" name="deletion-mode" value="delete_orphans" id="delete_orphans">
                    <label for="delete_orphans">${str_delete_orphans.replace('%d', String(orphan.nb_images_becoming_orphan))}</label>
                  </div>`;
                }
                message += `</div>`;

                pwgConfirm({
                    title: str_delete_album,
                    content: message,
                    buttons: {
                        deleteAlbum: {
                            text: str_delete_album,
                            btnClass: 'btn-red',
                            action: function () {
                                const deletionMode = document.querySelector<HTMLInputElement>('input[name="deletion-mode"]:checked')?.value;
                                delete_album(deletionMode)
                                    .then(() => { window.location.href = u_delete; })
                                    .catch((err: unknown) => { console.log(err); });
                            },
                        },
                        cancel: { text: str_cancel },
                    },
                });
            })
            .catch(function () {
                pwgConfirm({
                    title: str_delete_album,
                    content: 'An error has occurred while calculating orphans',
                    buttons: { cancel: { text: str_cancel } },
                });
            });
    });

    function delete_album(photo_deletion_mode: string | undefined): Promise<void> {
        return new Promise((res, rej) => {
            fetch('ws.php?format=json&method=pwg.categories.delete', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    category_id: String(album_id),
                    photo_deletion_mode: photo_deletion_mode ?? '',
                    pwg_token,
                }),
            })
                .then(r => r.json())
                .then(function (_data: unknown) { res(); })
                .catch(function (error: unknown) { rej(error); });
        });
    }

    document.getElementById('refreshRepresentative')?.addEventListener('click', function (e) {
        const icon = document.querySelector<HTMLElement>('#refreshRepresentative i')!;
        icon.classList.remove('icon-ccw');
        icon.classList.add('icon-spin6', 'animate-spin');

        fetch('ws.php?format=json&method=pwg.categories.refreshRepresentative', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ category_id: String(album_id) }),
        })
            .then(r => r.json())
            .then(function (data: { stat: string; result: { src: string } }) {
                if (data.stat === 'ok') {
                    document.getElementById('deleteRepresentative')!.style.display = '';
                    document.querySelector<HTMLElement>('.cat-modify-representative')!.setAttribute('style', `background-image:url('${data.result.src}')`);
                    document.querySelector<HTMLElement>('.cat-modify-representative')!.classList.remove('icon-dice-solid');
                } else {
                    console.error(data);
                }
                document.querySelector<HTMLElement>('#refreshRepresentative i')!.classList.add('icon-ccw');
                document.querySelector<HTMLElement>('#refreshRepresentative i')!.classList.remove('icon-spin6', 'animate-spin');
            })
            .catch(function (error: unknown) {
                console.error(error);
                document.querySelector<HTMLElement>('#refreshRepresentative i')!.classList.add('icon-ccw');
                document.querySelector<HTMLElement>('#refreshRepresentative i')!.classList.remove('icon-spin6', 'animate-spin');
            });

        e.preventDefault();
    });

    document.getElementById('deleteRepresentative')?.addEventListener('click', function (e) {
        const icon = document.querySelector<HTMLElement>('#deleteRepresentative i')!;
        icon.classList.remove('icon-cancel');
        icon.classList.add('icon-spin6', 'animate-spin');

        fetch('ws.php?format=json&method=pwg.categories.deleteRepresentative', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({ category_id: String(album_id) }),
        })
            .then(r => r.json())
            .then(function (data: { stat: string }) {
                if (data.stat === 'ok') {
                    document.getElementById('deleteRepresentative')!.style.display = 'none';
                    document.querySelector<HTMLElement>('.cat-modify-representative')!.setAttribute('style', '');
                    document.querySelector<HTMLElement>('.cat-modify-representative')!.classList.add('icon-dice-solid');
                } else {
                    console.error(data);
                }
                document.querySelector<HTMLElement>('#deleteRepresentative i')!.classList.add('icon-cancel');
                document.querySelector<HTMLElement>('#deleteRepresentative i')!.classList.remove('icon-spin6', 'animate-spin');
            })
            .catch(function (error: unknown) {
                console.error(error);
                document.querySelector<HTMLElement>('#deleteRepresentative i')!.classList.add('icon-cancel');
                document.querySelector<HTMLElement>('#deleteRepresentative i')!.classList.remove('icon-spin6', 'animate-spin');
            });

        e.preventDefault();
    });

    document.querySelector<HTMLElement>('#cat-parent.icon-pencil')?.addEventListener('click', function (e) {
        if ((e.target as HTMLElement).localName !== 'a') {
            linked_albums_open();
            set_up_popin();

            if (parent_album !== 0) {
                document.querySelector<HTMLElement>('.put-to-root')?.classList.remove('notClickable');
                document.querySelector<HTMLElement>('.put-to-root')?.addEventListener('click', function () {
                    add_related_category(0, str_root);
                });
            } else {
                document.querySelector<HTMLElement>('.put-to-root')?.classList.add('notClickable');
            }
        }
    });

    document.querySelector<HTMLElement>('.limitReached')!.innerHTML = str_no_search_in_progress;
    document.querySelectorAll<HTMLElement>('.search-cancel-linked-album').forEach(el => { el.style.display = 'none'; });
    document.querySelectorAll<HTMLElement>('.linkedAlbumPopInContainer .searching').forEach(el => { el.style.display = 'none'; });

    const linkedAlbumSearchInput = document.querySelector<HTMLInputElement>('#linkedAlbumSearch .search-input');
    if (linkedAlbumSearchInput) {
        linkedAlbumSearchInput.addEventListener('input', function () {
            const cancelBtn = document.querySelector<HTMLElement>('#linkedAlbumSearch .search-cancel-linked-album');
            if (this.value !== '0') {
                if (cancelBtn) cancelBtn.style.display = '';
            } else {
                if (cancelBtn) cancelBtn.style.display = 'none';
            }
            if (this.value.length > 0) {
                linked_albums_search(this.value);
            } else {
                document.querySelector<HTMLElement>('.limitReached')!.innerHTML = str_no_search_in_progress;
                const searchResult = document.getElementById('searchResult');
                if (searchResult) searchResult.innerHTML = '';
            }
        });
    }

    document.querySelectorAll<HTMLElement>('.search-cancel-linked-album').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const searchInput = document.querySelector<HTMLInputElement>('#linkedAlbumSearch .search-input');
            if (searchInput) {
                searchInput.value = '';
                searchInput.dispatchEvent(new Event('input'));
            }
        });
    });

    document.querySelectorAll<HTMLElement>('.allow-comments').forEach(function (el) {
        el.addEventListener('click', function () {
            fetch('ws.php?format=json&method=pwg.categories.setInfo', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    category_id: String(album_id),
                    commentable: 'true',
                    apply_commentable_to_subalbums: 'true',
                }),
            })
                .then(r => r.json())
                .then(function (data: { stat: string }) {
                    if (data.stat === 'ok') {
                        save_button_set_loading(false);
                        if (!(document.getElementById('cat-commentable') as HTMLInputElement).checked) {
                            (document.getElementById('cat-commentable') as HTMLInputElement).click();
                        }
                        const infoMsg = document.querySelector<HTMLElement>('.info-message')!;
                        const temp_txt = infoMsg.textContent ?? '';
                        infoMsg.textContent = str_album_comment_allow;
                        infoMsg.style.display = '';
                        setTimeout(() => { infoMsg.style.display = 'none'; infoMsg.textContent = temp_txt; }, 5000);
                    } else {
                        document.querySelector<HTMLElement>('.info-error')!.style.display = '';
                        setTimeout(() => { document.querySelector<HTMLElement>('.info-error')!.style.display = 'none'; }, 5000);
                    }
                })
                .catch(function (e: unknown) { console.log(e); save_button_set_loading(false); });
        });
    });

    document.querySelectorAll<HTMLElement>('.disallow-comments').forEach(function (el) {
        el.addEventListener('click', function () {
            fetch('ws.php?format=json&method=pwg.categories.setInfo', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({
                    category_id: String(album_id),
                    commentable: 'false',
                    apply_commentable_to_subalbums: 'true',
                }),
            })
                .then(r => r.json())
                .then(function (data: { stat: string }) {
                    if (data.stat === 'ok') {
                        save_button_set_loading(false);
                        if ((document.getElementById('cat-commentable') as HTMLInputElement).checked) {
                            (document.getElementById('cat-commentable') as HTMLInputElement).click();
                        }
                        const infoMsg = document.querySelector<HTMLElement>('.info-message')!;
                        const temp_txt = infoMsg.textContent ?? '';
                        infoMsg.textContent = str_album_comment_disallow;
                        infoMsg.style.display = '';
                        setTimeout(() => { infoMsg.style.display = 'none'; infoMsg.textContent = temp_txt; }, 5000);
                    } else {
                        document.querySelector<HTMLElement>('.info-error')!.style.display = '';
                        setTimeout(() => { document.querySelector<HTMLElement>('.info-error')!.style.display = 'none'; }, 5000);
                    }
                })
                .catch(function (e: unknown) { console.log(e); save_button_set_loading(false); });
        });
    });

    const desc_modal = document.getElementById('desc-modal');
    const textareas = document.querySelectorAll<HTMLTextAreaElement>('.sync-textarea');

    document.getElementById('desc-zoom-square')?.addEventListener('click', function () {
        if (desc_modal) desc_modal.style.display = getComputedStyle(desc_modal).display === 'none' ? '' : 'none';
    });

    document.getElementById('desc-modal-close')?.addEventListener('click', function () {
        if (desc_modal) desc_modal.style.display = getComputedStyle(desc_modal).display === 'none' ? '' : 'none';
    });

    textareas.forEach(function (textarea) {
        textarea.addEventListener('keyup', function (this: HTMLTextAreaElement) {
            const val = this.value;
            textareas.forEach(function (ta) { ta.value = val; });
        });
    });

    window.addEventListener('click', function (e) {
        if (desc_modal && e.target === desc_modal) {
            desc_modal.style.display = getComputedStyle(desc_modal).display === 'none' ? '' : 'none';
        }
    });

    document.addEventListener('keyup', function (e) {
        if (e.keyCode === 27 && desc_modal && getComputedStyle(desc_modal).display !== 'none') {
            desc_modal.style.display = 'none';
        }
    });
}

function checkAlbumLock(): void {
    document.querySelectorAll<HTMLElement>('.warnings').forEach(function (el) {
        el.style.display = is_visible === 'true' ? 'none' : '';
    });
}

function fill_results(cats: CatResult[]): void {
    const searchResult = document.getElementById('searchResult');
    if (!searchResult) return;
    searchResult.innerHTML = '';
    cats.forEach((cat) => {
        searchResult.insertAdjacentHTML('beforeend',
            "<div class='search-result-item' id=" + String(cat.id) + ">"
            + "<span class='search-result-path'>" + cat.fullname + "</span>"
            + "<span class='icon-plus-circled item-add'></span></div>",
        );

        if (parent_album === cat.id || cat.uppercats.split(',').includes(String(album_id))) {
            const itemDiv = searchResult.querySelector<HTMLElement>('#' + String(cat.id) + '.search-result-item');
            if (itemDiv) {
                itemDiv.classList.add('notClickable');
                itemDiv.setAttribute('title', str_already_in_related_cats);
                itemDiv.addEventListener('click', function (event) { event.preventDefault(); });
            }
        } else {
            const resultItem = searchResult.querySelector<HTMLElement>('#' + String(cat.id) + '.search-result-item ');
            if (resultItem) {
                resultItem.addEventListener('click', function () {
                    add_related_category(cat.id, cat.full_name_with_admin_links);
                });
            }
        }
    });
}

function add_related_category(cat_id: string | number, cat_link_path: string): void {
    if (parent_album !== cat_id) {
        document.getElementById('cat-parent')!.innerHTML = cat_link_path;
        const searchItem = document.querySelector<HTMLElement>('.search-result-item #' + String(cat_id));
        if (searchItem) searchItem.classList.add('notClickable');
        parent_album = cat_id;
        const invisibleSelect = document.querySelector<HTMLElement>('.invisible-related-categories-select');
        if (invisibleSelect) {
            invisibleSelect.insertAdjacentHTML('beforeend', '<option selected value=' + String(cat_id) + '></option>');
        }
        linked_albums_close();
    }
}

function activateCommentDropdown(): void {
    document.querySelectorAll<HTMLElement>('.toggle-comment-option .comment-option').forEach(el => { el.style.display = 'none'; });

    document.querySelectorAll<HTMLElement>('.toggle-comment-option').forEach(function (el) {
        el.addEventListener('click', function () {
            const opt = this.querySelector<HTMLElement>('.comment-option');
            if (opt) opt.style.display = getComputedStyle(opt).display === 'none' ? '' : 'none';
        });
    });

    document.addEventListener('mouseup', function (e) {
        e.stopPropagation();
        let option_is_clicked = false;
        document.querySelectorAll<HTMLElement>('.comment-option span').forEach(function (el) {
            if (el.contains(e.target as Node)) option_is_clicked = true;
        });
        if (!option_is_clicked) {
            document.querySelectorAll<HTMLElement>('.toggle-comment-option .comment-option').forEach(el => { el.style.display = 'none'; });
        }
    });
}

void fill_results;

initModule(init as (cfg: Record<string, unknown>) => void);
