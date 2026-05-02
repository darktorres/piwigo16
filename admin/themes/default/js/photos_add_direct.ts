import Uppy from '@uppy/core';
import Dashboard from '@uppy/dashboard';
import XHRUpload from '@uppy/xhr-upload';
import '@uppy/core/css/style.css';
import '@uppy/dashboard/css/style.css';
import { getPageData } from './page-data';
import { AlbumSelector } from './album_selector';
import Piecon from '../../../../themes/default/js/plugins/piecon';
declare function sprintf(fmt: string, ...args: unknown[]): string;

interface PhotosAddDirectPageData {
    pwg_token: string;
    chunk_size: string;
    max_file_size: string;
    albumSummary_label: string;
    batch_Label: string;
    file_ext: string;
    formatMode: boolean;
    format_ext: string;
    format_remove: string;
    format_update_warning: string;
    formatsAdded_label: string;
    formatsUpdated_label: string;
    haveFormatsOriginal: boolean;
    imageFormatsExtensions: string;
    nb_albums: number;
    originalImageId: number | string;
    photosAdded_label: string;
    photosUpdated_label: string;
    related_categories_ids: any[];
    str_and_X_others: string;
    str_drop_album_ab: string;
    str_format_warning: string;
    str_format_warning_multiple: string;
    str_format_warning_notFound: string;
    str_upload_in_progress: string;
}

const {
    pwg_token,
    chunk_size,
    max_file_size,
    albumSummary_label,
    batch_Label,
    file_ext,
    formatMode,
    format_ext,
    format_remove,
    format_update_warning,
    formatsAdded_label,
    formatsUpdated_label,
    haveFormatsOriginal,
    imageFormatsExtensions,
    nb_albums,
    originalImageId,
    photosAdded_label,
    photosUpdated_label,
    related_categories_ids,
    str_and_X_others,
    str_drop_album_ab,
    str_format_warning,
    str_format_warning_multiple,
    str_format_warning_notFound,
    str_upload_in_progress,
} = getPageData<PhotosAddDirectPageData>();

const addedPhotos: any[] = [];
let exts: any = {};
let fileNames: any = {};
let html: string = '';
const updatedPhotos: any[] = [];
let uploadCategory: any = null;
const uploadedPhotos: any[] = [];

const qs = <T extends HTMLElement = HTMLElement>(sel: string) => document.querySelector<T>(sel);
const qsa = <T extends HTMLElement = HTMLElement>(sel: string) =>
    Array.from(document.querySelectorAll<T>(sel));
const show = (el: Element | null | undefined) => {
    if (el) (el as HTMLElement).style.display = '';
};
const hide = (el: Element | null | undefined) => {
    if (el) (el as HTMLElement).style.display = 'none';
};

const btnFirstAlbum = qs('#btnFirstAlbum');
const modalFirstAlbum = qs('#addFirstAlbum');
const closeModalFirstAlbum = qs('#closeFirstAlbum');
const inputFirstAlbum = qs<HTMLInputElement>('#inputFirstAlbum');
const btnAddFirstAlbum = qs('#btnAddFirstAlbum');
const firstAlbum = qs('.addAlbumEmptyCenter');
const uploadForm = qs('#uploadForm');
const addPhotosAS = qs('#addPhotosAS');
const btnPhotosAS = qs('#btnPhotosAS');
const selectedAlbum = qs('#selectedAlbum');
const selectedAlbumName = qs('#selectedAlbumName');
const selectedAlbumEdit = qs('#selectedAlbumEdit');
const btnAddFiles = qs<HTMLButtonElement>('#addFiles');
const chooseAlbumFirst = qs('#chooseAlbumFirst');
const uploaderPhotos = qs('#uploader');
const formatsUpdated: string[] = [];
const formats: any[] = [];
let ab: any = null;

/*---- DOMContentLoaded ----*/
document.addEventListener('DOMContentLoaded', () => {
    if (!nb_albums) {
        btnFirstAlbum?.addEventListener('click', () => open_new_album_modal());
        closeModalFirstAlbum?.addEventListener('click', () => close_new_album_modal());
        btnAddFirstAlbum?.addEventListener('click', () =>
            add_first_album((id: any) => ab?.select_album(id))
        );
        inputFirstAlbum?.addEventListener('keyup', (e: KeyboardEvent) => {
            if (e.key === 'Enter') btnAddFirstAlbum?.click();
        });
    }

    ab = new AlbumSelector({
        selectedCategoriesIds: related_categories_ids,
        selectAlbum: add_related_category,
        adminMode: true,
        modalTitle: str_drop_album_ab,
    });

    btnPhotosAS?.addEventListener('click', () => ab.open());
    selectedAlbumEdit?.addEventListener('click', () => ab.open());

    qs('.dont-show-again')?.addEventListener('click', () => {
        fetch('ws.php?format=json&method=pwg.users.preferences.set', {
            method: 'POST',
            body: new URLSearchParams({ param: 'promote-mobile-apps', value: 'false' }),
        }).then(() => {
            hide(qs('.promote-apps'));
        });
    });

    qs('#uploadWarningsSummary a.showInfo')?.addEventListener('click', (e) => {
        e.preventDefault();
        hide(qs('#uploadWarningsSummary'));
        show(qs('#uploadWarnings'));
    });

    qs('#showPermissions')?.addEventListener('click', (e) => {
        e.preventDefault();
        hide(qs('#showPermissions')?.closest('.showFieldset'));
        show(qs('#permissions'));
    });

    hide(qs('#uploadOptionsContent'));
    qs('#uploadOptions')?.addEventListener('click', () => {
        const content = qs<HTMLElement>('#uploadOptionsContent');
        if (content) content.style.display = content.style.display === 'none' ? '' : 'none';
        qs('#uploadOptions')?.classList.toggle('options-open');
    });

    initUppy();
});

/*---- Uppy uploader ----*/
function initUppy() {
    const uppyContainer = qs('#uploader');
    if (!uppyContainer) return;

    const allowedTypes =
        (formatMode ? format_ext : file_ext)?.split(',').map((e: string) => '.' + e.trim()) ?? [];

    const uppy = new Uppy({
        autoProceed: false,
        restrictions: {
            maxFileSize: parseInt(String(max_file_size)) || undefined,
            allowedFileTypes: allowedTypes.length ? allowedTypes : undefined,
        },
    })
        .use(Dashboard, {
            target: '#uploadForm',
            inline: true,
            proudlyDisplayPoweredByUppy: false,
        } as any)
        .use(XHRUpload, {
            endpoint: 'ws.php?method=pwg.images.upload&format=json',
            formData: true,
            fieldName: 'file',
            allowedMetaFields: ['pwg_token', 'category', 'name', 'format_of', 'update_mode'],
        } as any);

    // Start/Cancel buttons
    qs('#startUpload')?.addEventListener('click', (e) => {
        e.preventDefault();
        uppy.upload();
    });
    qs('#cancelUpload')?.addEventListener('click', (e) => {
        e.preventDefault();
        uppy.cancelAll();
    });

    uppy.on('files-added', async (files) => {
        fileNames = {};
        exts = {};
        files.forEach((file) => {
            fileNames[file.id] = file.name;
            exts[file.id] = file.name.slice(file.name.lastIndexOf('.') + 1);
        });

        if (formatMode) {
            if (!haveFormatsOriginal) {
                // Search for original images
                const body = new URLSearchParams({ filename_list: JSON.stringify(fileNames) });
                const result = await fetch(
                    'ws.php?format=json&method=pwg.images.formats.searchImage',
                    { method: 'POST', body }
                )
                    .then((r) => r.json())
                    .catch(() => ({ result: {} }));
                const images_search = result.result ?? {};

                const notFound: string[] = [],
                    multiple: string[] = [];
                files.forEach((f) => {
                    const search = images_search[f.id];
                    if (search?.status === 'found') {
                        uppy.setFileMeta(f.id, { format_of: search.image_id });
                        formats.push([f.id, search.image_id]);
                        if (search.format_exist) formatsUpdated.push(f.id);
                    } else {
                        if (search?.status === 'multiple') multiple.push(f.name);
                        else notFound.push(f.name);
                        uppy.removeFile(f.id);
                    }
                });

                if (notFound.length || multiple.length) {
                    const fmt = (tab: string[]) => {
                        tab = tab
                            .map((f) => f.slice(0, f.indexOf('.')))
                            .filter((f, i, a) => i === a.indexOf(f));
                        if (tab.length > 5) {
                            tab[5] = str_and_X_others.replace('%d', String(tab.length - 5));
                            tab = tab.slice(0, 6);
                        }
                        return tab;
                    };
                    window.alert(
                        str_format_warning +
                            '\n' +
                            (notFound.length
                                ? str_format_warning_notFound.replace(
                                      '%s',
                                      fmt(notFound).join(', ')
                                  )
                                : '') +
                            (multiple.length
                                ? '\n' +
                                  str_format_warning_multiple.replace(
                                      '%s',
                                      fmt(multiple).join(', ')
                                  )
                                : '')
                    );
                }
            } else {
                const forms_exts = imageFormatsExtensions ? JSON.parse(imageFormatsExtensions) : [];
                files.forEach((f) => {
                    uppy.setFileMeta(f.id, { format_of: originalImageId });
                    formats.push([f.id, originalImageId]);
                    if (forms_exts.includes(exts[f.id])) formatsUpdated.push(f.id);
                });
            }
        }
    });

    uppy.on('upload-progress', (_file, progress) => {
        const pb = qs<HTMLElement>('#uploadingActions .progressbar');
        if (pb) pb.style.width = (progress.percentage ?? 0) + '%';
        Piecon.setProgress(progress.percentage ?? 0);
    });

    uppy.on('upload', () => {
        hide(qs('#startUpload'));
        hide(qs('.selectFilesButtonBlock'));
        show(qs('#uploadingActions'));
        hide(qs('.format-mode-group-manager'));
        hide(selectedAlbumEdit);
        window.addEventListener('beforeunload', onBeforeUnload);
        qs<HTMLSelectElement>('select[name=level]')?.setAttribute('disabled', 'disabled');
    });

    uppy.on('upload-success', (file, response) => {
        if (!file) return;
        const data = response.body as any;
        hide(document.getElementById(file.id));
        show(qs('#uploadedPhotos')?.closest('fieldset'));

        html =
            '<a href="admin.php?page=photo-' +
            data.result.image_id +
            '" style="position:relative" target="_blank">';
        html +=
            '<img src="' +
            data.result.square_src +
            '" class="thumbnail" title="' +
            data.result.name +
            '">';
        if (formatMode)
            html +=
                '<div class="format-ext-name" title="' +
                file.name +
                '"><span>' +
                file.name.slice(file.name.indexOf('.')) +
                '</span></div>';
        html += '</a> ';
        qs('#uploadedPhotos')?.insertAdjacentHTML('afterbegin', html);

        uploadedPhotos.push(parseInt(data.result.image_id));
        if (data.result.add_status === 'add') addedPhotos.push(parseInt(data.result.image_id));
        else updatedPhotos.push(parseInt(data.result.image_id));
        if (!formatMode) uploadCategory = data.result.category;
    });

    uppy.on('upload-error', (_file, _error, response) => {
        let msg = 'Upload error';
        try {
            msg = (response as any)?.body?.message ?? msg;
        } catch {
            /* noop */
        }
        qs('.errors ul')?.insertAdjacentHTML('beforeend', '<li>' + msg + '</li>');
        show(qs('.errors'));
    });

    uppy.on('complete', () => {
        Piecon.reset();

        if (!formatMode) {
            fetch('ws.php?format=json&method=pwg.images.uploadCompleted', {
                method: 'POST',
                body: new URLSearchParams({
                    pwg_token,
                    image_id: uploadedPhotos.join(','),
                    category_id: String(uploadCategory?.id ?? ''),
                }),
            });
        }

        qsa('#uploadForm, #permissions, .showFieldset').forEach(hide);

        const infoTextAdd = formatMode
            ? sprintf(formatsAdded_label, addedPhotos.length, [...new Set(addedPhotos)].length)
            : sprintf(photosAdded_label, addedPhotos.length);
        const infoTextUpdate = formatMode
            ? sprintf(
                  formatsUpdated_label,
                  updatedPhotos.length,
                  [...new Set(updatedPhotos)].length
              )
            : sprintf(photosUpdated_label, updatedPhotos.length);

        const infoText =
            addedPhotos.length && updatedPhotos.length
                ? infoTextAdd + ', ' + infoTextUpdate
                : addedPhotos.length
                  ? infoTextAdd
                  : infoTextUpdate;
        qs('.infos')?.insertAdjacentHTML('beforeend', '<ul><li>' + infoText + '</li></ul>');

        if (!formatMode) {
            html = sprintf(
                albumSummary_label,
                '<a href="admin.php?page=album-' +
                    uploadCategory.id +
                    '">' +
                    uploadCategory.label +
                    '</a>',
                parseInt(uploadCategory.nb_photos)
            );
            qs('.infos ul')?.insertAdjacentHTML('beforeend', '<li>' + html + '</li>');
        }

        show(qs('.infos'));
        const batchSet = [...new Set<number>(uploadedPhotos)];
        const batchLink = qs<HTMLAnchorElement>('.batchLink');
        if (batchLink) {
            batchLink.href = 'admin.php?page=photos_add&section=direct&batch=' + batchSet.join(',');
            batchLink.innerHTML = sprintf(batch_Label, uploadedPhotos.length);
        }
        show(qs('.afterUploadActions'));
        hide(qs('#uploadingActions'));
        show(selectedAlbumEdit);
        window.removeEventListener('beforeunload', onBeforeUnload);
    });

    // Set per-file metadata before upload
    uppy.on('upload', (data: any) => {
        (data.fileIDs ?? []).forEach((fileId: string) => {
            const file = uppy.getFile(fileId);
            const opts: Record<string, any> = {
                pwg_token,
                update_mode: String(qs<HTMLInputElement>('#toggleUpdateMode')?.checked ?? false),
            };
            if (formatMode) {
                opts.format_of = file.meta.format_of ?? '';
            } else {
                opts.category = ab?.get_selected_albums()[0] ?? '';
                opts.name = file.name;
            }
            uppy.setFileMeta(fileId, opts);
        });
    });
}

function onBeforeUnload(e: BeforeUnloadEvent) {
    e.preventDefault();
    e.returnValue = str_upload_in_progress;
}

/*---- General functions ----*/
function add_related_category({ album, newSelectedAlbum }: { album: any; newSelectedAlbum: any }) {
    const div = document.createElement('div');
    div.innerHTML = album.full_name_with_admin_links ?? '';
    let text = '';
    Array.from(div.children).forEach((s) => {
        if ((s as HTMLElement).innerHTML) text += (s as HTMLElement).innerHTML;
    });
    if (!text) text = album.full_name_with_admin_links ?? album.name ?? '';
    newSelectedAlbum();
    if (selectedAlbumName) {
        selectedAlbumName.innerHTML = text;
    }
    hide(selectedAlbumName);
    show(selectedAlbumName);
    hide(addPhotosAS);
    show(selectedAlbum);
    enable_uploader();
}

function enable_uploader() {
    if (btnAddFiles) btnAddFiles.removeAttribute('disabled');
    hide(chooseAlbumFirst);
    show(uploaderPhotos);
}

/*---- First album functions ----*/
function open_new_album_modal() {
    if (inputFirstAlbum) inputFirstAlbum.value = '';
    show(modalFirstAlbum);
    inputFirstAlbum?.focus();
}

function close_new_album_modal() {
    hide(modalFirstAlbum);
}

function hide_first_album(cat_name: string) {
    hide(modalFirstAlbum);
    hide(firstAlbum);
    hide(addPhotosAS);
    if (selectedAlbumName) selectedAlbumName.innerHTML = cat_name;
    show(selectedAlbum);
    enable_uploader();
    show(uploadForm);
}

function add_first_album(add_cat: any) {
    const name = inputFirstAlbum?.value ?? '';
    fetch('ws.php?format=json&method=pwg.categories.add', {
        method: 'POST',
        body: new URLSearchParams({ name, pwg_token }),
    })
        .then((r) => r.json())
        .then((res) => {
            if (res.stat === 'ok') {
                add_cat(res.result.id);
                hide_first_album(name);
            } else console.error('An error has occurred');
        })
        .catch(() => console.error('An error has occurred'));
}

export {};
