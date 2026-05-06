import tippy from 'tippy.js';
import 'tippy.js/dist/tippy.css';
import { getPageData } from './page-data';
import { AlbumSelector } from './album_selector';
import { config } from './config';

interface CatModifyPageData {
    album_id: number;
    album_name: string;
    default_parent_album: number;
    is_visible: string;
    nb_sub_albums: number;
    parent_album: number;
    related_categories_ids: string[];
    u_delete: string;
    pwg_token: string;
    str_cancel: string;
    str_delete_album: string;
    str_delete_album_and_his_x_subalbums: string;
    str_just_now: string;
    str_dont_delete_photos: string;
    str_delete_orphans: string;
    str_delete_all_photos: string;
    str_album_comment_allow: string;
    str_album_comment_disallow: string;
    str_modal_ab: string;
}

const {
    album_id,
    album_name,
    str_cancel,
    str_delete_album,
    str_delete_album_and_his_x_subalbums,
    str_just_now,
    str_dont_delete_photos,
    str_delete_orphans,
    str_delete_all_photos,
    str_album_comment_allow,
    str_album_comment_disallow,
    str_modal_ab,
    u_delete,
    pwg_token,
    related_categories_ids,
    nb_sub_albums,
    is_visible: _is_visible,
    parent_album: _parent_album,
    default_parent_album: _default_parent_album,
} = getPageData<CatModifyPageData>();

// Mutable state
let is_visible = _is_visible;
let parent_album = _parent_album;
let default_parent_album = _default_parent_album;
let temp_txt = '';

function pwgPost(method: string, data: Record<string, any>): Promise<any> {
    return fetch(`${config.wsUrl}?format=json&method=${method}`, {
        method: 'POST',
        body: new URLSearchParams(
            Object.fromEntries(Object.entries(data).map(([k, v]) => [k, String(v ?? '')]))
        ),
    }).then((r) => r.json());
}

function pwgGet(method: string, data: Record<string, any>): Promise<any> {
    return fetch(
        `${config.wsUrl}?format=json&method=${method}&` +
            new URLSearchParams(
                Object.fromEntries(Object.entries(data).map(([k, v]) => [k, String(v ?? '')]))
            )
    ).then((r) => r.json());
}

function showInfo(show: boolean) {
    document.querySelectorAll<HTMLElement>('.info-message').forEach((el) => {
        el.style.display = show ? '' : 'none';
    });
    if (show)
        setTimeout(
            () =>
                document.querySelectorAll<HTMLElement>('.info-message').forEach((el) => {
                    el.style.display = 'none';
                }),
            5000
        );
}

function showError(show = true) {
    document.querySelectorAll<HTMLElement>('.info-error').forEach((el) => {
        el.style.display = show ? '' : 'none';
    });
    if (show)
        setTimeout(
            () =>
                document.querySelectorAll<HTMLElement>('.info-error').forEach((el) => {
                    el.style.display = 'none';
                }),
            5000
        );
}

function save_button_set_loading(state = true) {
    const icon = document.querySelector<HTMLElement>('#cat-properties-save i');
    if (icon) {
        icon.classList.toggle('icon-floppy', !state);
        icon.classList.toggle('icon-spin6', state);
        icon.classList.toggle('animate-spin', state);
    }
    (document.getElementById('cat-properties-save') as HTMLButtonElement).disabled = state;
}

function checkAlbumLock() {
    document.querySelectorAll<HTMLElement>('.warnings').forEach((el) => {
        el.style.display = is_visible == 'true' ? 'none' : 'flex';
    });
}

function add_related_category({
    album,
    newSelectedAlbum,
    getSelectedAlbum,
}: {
    album: any;
    newSelectedAlbum: any;
    getSelectedAlbum: any;
}) {
    if (parent_album != album.id) {
        document.getElementById('cat-parent')!.innerHTML =
            album.full_name_with_admin_links ?? album.root;
        document
            .querySelector<HTMLElement>(`#${album.id}.search-result-item > *`)
            ?.classList.add('notClickable');
        document
            .querySelector('.invisible-related-categories-select')
            ?.insertAdjacentHTML('beforeend', `<option selected value="${album.id}"></option>`);
        newSelectedAlbum();
        parent_album = getSelectedAlbum()[0];
    }
}

function activateCommentDropdown() {
    document
        .querySelectorAll<HTMLElement>('.toggle-comment-option .comment-option')
        .forEach((el) => {
            el.style.display = 'none';
        });
    document.querySelectorAll<HTMLElement>('.toggle-comment-option').forEach((el) => {
        el.addEventListener('click', () => {
            const opt = el.querySelector<HTMLElement>('.comment-option');
            if (opt) opt.style.display = opt.style.display === 'none' ? '' : 'none';
        });
    });
    document.addEventListener('mouseup', (e: MouseEvent) => {
        e.stopPropagation();
        const target = e.target as Element;
        let optionClicked = false;
        document.querySelectorAll('.comment-option span').forEach((span) => {
            if (span.contains(target)) optionClicked = true;
        });
        if (!optionClicked) {
            document
                .querySelectorAll<HTMLElement>('.toggle-comment-option .comment-option')
                .forEach((el) => {
                    el.style.display = 'none';
                });
        }
    });
}

function delete_album(photo_deletion_mode: any): Promise<void> {
    return pwgPost('pwg.categories.delete', {
        category_id: album_id,
        photo_deletion_mode,
        pwg_token,
    }).then((data) => {
        if (data.stat !== 'ok') throw new Error(data.message ?? 'delete failed');
    });
}

async function showDeleteAlbumDialog(): Promise<string | null> {
    let orphanData: any;
    try {
        const r = await pwgGet('pwg.categories.calculateOrphans', { category_id: album_id });
        orphanData = r.result[0];
    } catch (e) {
        console.log(e);
        return null;
    }

    return new Promise((resolve) => {
        let message = `<p>${str_delete_album_and_his_x_subalbums
            .replace('%s', `<strong>${album_name}</strong>`)
            .replace('%d', `<strong>${nb_sub_albums}</strong>`)}</p>`;
        message += `<div class="cat-delete-modes">`;
        if (orphanData.nb_images_recursive) {
            message += `<div><input type="radio" name="deletion-mode" value="no_delete" id="no_delete" checked>
                <label for="no_delete">${str_dont_delete_photos}</label></div>`;
            let t = 0;
            message += `<div><input type="radio" name="deletion-mode" value="force_delete" id="force_delete">
                <label for="force_delete">${str_delete_all_photos.replaceAll('%d', () => [orphanData.nb_images_recursive, orphanData.nb_images_associated_outside][t++])}</label></div>`;
            if (orphanData.nb_images_becoming_orphan) {
                message += `<div><input type="radio" name="deletion-mode" value="delete_orphans" id="delete_orphans">
                    <label for="delete_orphans">${str_delete_orphans.replace('%d', orphanData.nb_images_becoming_orphan)}</label></div>`;
            }
        }
        message += `</div>`;

        const dialog = document.createElement('dialog');
        dialog.innerHTML = `<h3>${str_delete_album}</h3>${message}
            <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:12px">
                <button class="cancel-btn">${str_cancel}</button>
                <button class="confirm-btn" style="background:#e74c3c;color:#fff;border:none;padding:8px 16px;cursor:pointer">${str_delete_album}</button>
            </div>`;
        document.body.appendChild(dialog);
        dialog.showModal();

        dialog.querySelector('.cancel-btn')?.addEventListener('click', () => {
            dialog.close();
            dialog.remove();
            resolve(null);
        });
        dialog.querySelector('.confirm-btn')?.addEventListener('click', async () => {
            const mode = dialog.querySelector<HTMLInputElement>(
                'input[name=deletion-mode]:checked'
            )?.value;
            const btn = dialog.querySelector<HTMLButtonElement>('.confirm-btn')!;
            btn.disabled = true;
            btn.innerHTML = '<i class="icon-spin6 animate-spin"></i>';
            try {
                await delete_album(mode);
                dialog.close();
                dialog.remove();
                window.location.href = u_delete;
            } catch (err) {
                console.log(err);
                dialog.close();
                dialog.remove();
                resolve(null);
            }
        });
    });
}

document.addEventListener('DOMContentLoaded', () => {
    checkAlbumLock();
    activateCommentDropdown();

    const ab = new AlbumSelector({
        selectedCategoriesIds: related_categories_ids,
        selectAlbum: add_related_category,
        showRootButton: true,
        adminMode: true,
        currentAlbumId: album_id,
        modalTitle: str_modal_ab,
    });

    tippy('.tiptip', { delay: [0, 0], duration: [200, 200] });

    document.querySelector('.unlock-album')?.addEventListener('click', () => {
        pwgPost('pwg.categories.setInfo', { category_id: album_id, visible: 'true' })
            .then((data) => {
                if (data.stat == 'ok') {
                    is_visible = 'true';
                    const catLocked = document.getElementById('cat-locked') as HTMLInputElement;
                    if (catLocked?.checked) catLocked.click();
                    checkAlbumLock();
                    showInfo(true);
                } else showError();
            })
            .catch((err) => {
                showError();
                console.log(err);
            });
    });

    document.getElementById('cat-properties-save')?.addEventListener('click', () => {
        save_button_set_loading(true);
        document.querySelectorAll<HTMLElement>('.info-error,.info-message').forEach((el) => {
            el.style.display = 'none';
        });
        const catLocked = (document.getElementById('cat-locked') as HTMLInputElement).checked;
        const catCommentable = (document.getElementById('cat-commentable') as HTMLInputElement)
            .checked;

        pwgPost('pwg.categories.setInfo', {
            category_id: album_id,
            name: (document.getElementById('cat-name') as HTMLInputElement).value,
            comment: (document.getElementById('cat-comment') as HTMLTextAreaElement).value,
            visible: catLocked ? 'false' : 'true',
            commentable: catCommentable ? 'true' : 'false',
            pwg_token,
        })
            .then((data) => {
                if (data.stat == 'ok') {
                    save_button_set_loading(false);
                    document
                        .querySelectorAll<HTMLElement>(
                            '.cat-modification .cat-modify-info-subcontent,.cat-modification .cat-modify-info-content'
                        )
                        .forEach((el) => {
                            el.innerHTML = str_just_now;
                        });
                    is_visible = catLocked ? 'false' : 'true';
                    checkAlbumLock();
                    showInfo(true);
                } else showError();
            })
            .catch((err) => {
                save_button_set_loading(false);
                showError();
                console.log(err);
            });

        if (parent_album != default_parent_album) {
            pwgPost('pwg.categories.move', {
                category_id: album_id,
                parent: parent_album,
                pwg_token,
            })
                .then((data) => {
                    if (data.stat === 'ok') {
                        document.querySelector<HTMLElement>('.cat-modify-ariane')!.innerHTML =
                            data.result.new_ariane_string;
                        default_parent_album = parent_album;
                    } else showError();
                })
                .catch((e) => console.log((e as any).message));
        }
    });

    document.querySelector('.deleteAlbum')?.addEventListener('click', () => {
        showDeleteAlbumDialog();
    });

    const refreshBtn = document.getElementById('refreshRepresentative');
    refreshBtn?.addEventListener('click', (e) => {
        e.preventDefault();
        const icon = refreshBtn.querySelector('i')!;
        icon.classList.replace('icon-ccw', 'icon-spin6');
        icon.classList.add('animate-spin');
        pwgPost('pwg.categories.refreshRepresentative', { category_id: album_id })
            .then((data) => {
                if (data.stat == 'ok') {
                    document.getElementById('deleteRepresentative')!.style.display = '';
                    const rep = document.querySelector<HTMLElement>('.cat-modify-representative');
                    if (rep) {
                        rep.style.backgroundImage = `url('${data.result.src}')`;
                        rep.classList.remove('icon-dice-solid');
                    }
                } else console.error(data);
                icon.classList.replace('icon-spin6', 'icon-ccw');
                icon.classList.remove('animate-spin');
            })
            .catch((err) => {
                console.error(err);
                icon.classList.replace('icon-spin6', 'icon-ccw');
                icon.classList.remove('animate-spin');
            });
    });

    const deleteRepBtn = document.getElementById('deleteRepresentative');
    deleteRepBtn?.addEventListener('click', (e) => {
        e.preventDefault();
        const icon = deleteRepBtn.querySelector('i')!;
        icon.classList.replace('icon-cancel', 'icon-spin6');
        icon.classList.add('animate-spin');
        pwgPost('pwg.categories.deleteRepresentative', { category_id: album_id })
            .then((data) => {
                if (data.stat == 'ok') {
                    deleteRepBtn.style.display = 'none';
                    const rep = document.querySelector<HTMLElement>('.cat-modify-representative');
                    if (rep) {
                        rep.removeAttribute('style');
                        rep.classList.add('icon-dice-solid');
                    }
                } else console.error(data);
                icon.classList.replace('icon-spin6', 'icon-cancel');
                icon.classList.remove('animate-spin');
            })
            .catch((err) => {
                console.error(err);
                icon.classList.replace('icon-spin6', 'icon-cancel');
                icon.classList.remove('animate-spin');
            });
    });

    document
        .getElementById('cat-parent')
        ?.querySelector('.icon-pencil')
        ?.addEventListener('click', (e) => {
            if ((e.target as HTMLElement).localName !== 'a') ab.open();
        });

    function handleCommentable(commentable: boolean) {
        save_button_set_loading(true);
        pwgPost('pwg.categories.setInfo', {
            category_id: album_id,
            commentable: String(commentable),
            apply_commentable_to_subalbums: 'true',
        })
            .then((data) => {
                if (data.stat == 'ok') {
                    save_button_set_loading(false);
                    const cb = document.getElementById('cat-commentable') as HTMLInputElement;
                    if (commentable !== cb.checked) cb.click();
                    const msg = document.querySelector<HTMLElement>('.info-message');
                    if (msg) {
                        temp_txt = msg.textContent;
                        msg.textContent = commentable
                            ? str_album_comment_allow
                            : str_album_comment_disallow;
                        msg.style.display = '';
                        setTimeout(() => {
                            msg.style.display = 'none';
                            msg.textContent = temp_txt;
                        }, 5000);
                    }
                } else showError();
            })
            .catch((e) => {
                console.log(e);
                save_button_set_loading(false);
            });
    }

    document
        .querySelector('.allow-comments')
        ?.addEventListener('click', () => handleCommentable(true));
    document
        .querySelector('.disallow-comments')
        ?.addEventListener('click', () => handleCommentable(false));

    // Textarea sync modal
    const descModal = document.getElementById('desc-modal') as HTMLElement;
    const textareas = document.querySelectorAll<HTMLTextAreaElement>('.sync-textarea');
    document.getElementById('desc-zoom-square')?.addEventListener('click', () => {
        descModal.style.display = descModal.style.display === 'none' ? '' : 'none';
    });
    document.getElementById('desc-modal-close')?.addEventListener('click', () => {
        descModal.style.display = descModal.style.display === 'none' ? '' : 'none';
    });
    textareas.forEach((ta) => {
        ta.addEventListener('keyup', function (this: HTMLTextAreaElement) {
            textareas.forEach((other) => {
                other.value = this.value;
            });
        });
    });
    window.addEventListener('click', (e) => {
        if (e.target === descModal) descModal.style.display = 'none';
    });
    document.addEventListener('keyup', (e: KeyboardEvent) => {
        if (e.key === 'Escape' && descModal.style.display !== 'none')
            descModal.style.display = 'none';
    });
});

export {};
