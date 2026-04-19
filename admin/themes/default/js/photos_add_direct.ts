import { CategoriesCache } from './LocalStorageCache.js';
import { pwgAddAlbum } from './addAlbum.js';
import { pwgConfirm } from './pwgConfirm.js';
import { sprintf } from './common.js';
import { initModule } from './moduleInit.js';
import DropzoneImport from 'dropzone';
const Dropzone: typeof DropzoneImport = (DropzoneImport as unknown as { default?: typeof DropzoneImport }).default ?? DropzoneImport;
import Piecon from 'piecon';

interface PwigoDropzoneFile extends Dropzone.DropzoneFile {
    format_of?: number;
}

interface PhotosAddDirectConfig {
    formatMode?: boolean;
    haveFormatsOriginal?: boolean;
    originalImageId?: number;
    pwgToken?: string;
    photosUploadedLabel?: string;
    formatsUploadedLabel?: string;
    batchLabel?: string;
    albumSummaryLabel?: string;
    strFormatWarning?: string;
    strOk?: string;
    strFormatWarningMultiple?: string;
    strFormatWarningNotFound?: string;
    strAndXOthers?: string;
    fileExt?: string;
    formatExt?: string;
    chunkSize?: number;
    maxFileSize?: number;
    categoriesServerKey?: string;
    categoriesServerId?: string;
    rootUrl?: string;
    dropzoneMsg?: string;
    strUploadInProgress?: string;
}

export function init(cfg: PhotosAddDirectConfig): void {
    const {
        formatMode = false,
        haveFormatsOriginal = false,
        originalImageId = -1,
        pwgToken = '',
        photosUploadedLabel = '%d photos uploaded',
        formatsUploadedLabel = '%d formats uploaded for %d photos',
        batchLabel = 'Manage this set of %d photos',
        albumSummaryLabel = 'Album "%s" now contains %d photos',
        strFormatWarning = 'Error when trying to detect formats',
        strOk = 'Ok',
        strFormatWarningMultiple = 'There is multiple image in the database with the following names : %s.',
        strFormatWarningNotFound = 'No picture found with the following name : %s.',
        strAndXOthers = 'and %d more',
        fileExt = '',
        formatExt = '',
        chunkSize = 0,
        maxFileSize = 0,
        categoriesServerKey = '',
        categoriesServerId = '',
        rootUrl = '',
    } = cfg;

    const uploadedPhotos: number[] = [];
    let uploadCategory: { id: string | number; label: string; nb_photos: string } | null = null;

    if (!formatMode) {
        const categoriesCache = new CategoriesCache({ serverKey: categoriesServerKey, serverId: categoriesServerId, rootUrl });

        categoriesCache.selectize(document.querySelectorAll('[data-selectize=categories]'), {
            filter: function (categories) {
                if (categories.length > 0) {
                    document.querySelectorAll<HTMLElement>('.addAlbumEmptyCenter').forEach(el => { el.style.height = 'auto'; });
                    document.querySelectorAll<HTMLElement>('.addAlbumFormParent').forEach(el => { el.setAttribute('style', 'display: block !important;'); });
                }
                return categories;
            },
        });

        document.querySelectorAll<HTMLElement>('[data-add-album]').forEach(function (btn) {
            pwgAddAlbum(btn, {
                afterSelect: function () {
                    const uploadForm = document.getElementById('uploadForm');
                    if (uploadForm) uploadForm.style.display = '';
                    document.querySelectorAll<HTMLElement>('.addAlbumEmptyCenter').forEach(el => { el.style.display = 'none'; el.style.height = 'auto'; });
                    document.querySelectorAll<HTMLElement>('.addAlbumFormParent').forEach(el => { el.setAttribute('style', 'display: block !important;'); });

                    const sel = document.querySelector<HTMLSelectElement & { tomselect?: { getItem(v: string): HTMLElement | null } }>('select[name=category]');
                    const categorySelectedId = sel?.value ?? '';
                    let categorySelectedPath = '';
                    if (sel?.tomselect) {
                        const item = sel.tomselect.getItem(categorySelectedId);
                        categorySelectedPath = item ? item.textContent || '' : '';
                    }
                    const selectedAlbum = document.querySelector<HTMLElement>('.selectedAlbum');
                    if (selectedAlbum) {
                        selectedAlbum.style.display = '';
                        const span = selectedAlbum.querySelector('span');
                        if (span) span.innerHTML = categorySelectedPath;
                    }
                    document.querySelectorAll<HTMLElement>('.selectAlbumBlock').forEach(el => { el.style.display = 'none'; });
                },
            });
        });

        Piecon.setOptions({ color: '#ff7700', background: '#bbb', shadow: '#fff', fallback: 'force' });
    }

    document.querySelectorAll<HTMLElement>('.dont-show-again').forEach(function (el) {
        el.addEventListener('click', function () {
            void fetch('ws.php?format=json&method=pwg.users.preferences.set', {
                method: 'POST',
                body: new URLSearchParams({ param: 'promote-mobile-apps', value: 'false' }),
            })
                .then(r => r.json())
                .then(function () {
                    const appsEl = document.querySelector<HTMLElement>('.promote-apps');
                    if (appsEl) appsEl.style.display = 'none';
                });
        });
    });

    const showInfoEl = document.querySelector<HTMLElement>('#uploadWarningsSummary a.showInfo');
    if (showInfoEl) {
        showInfoEl.addEventListener('click', function (event) {
            event.preventDefault();
            const summary = document.getElementById('uploadWarningsSummary');
            const warnings = document.getElementById('uploadWarnings');
            if (summary) summary.style.display = 'none';
            if (warnings) warnings.style.display = '';
        });
    }

    const showPermsEl = document.getElementById('showPermissions');
    if (showPermsEl) {
        showPermsEl.addEventListener('click', function (event) {
            event.preventDefault();
            const parent = showPermsEl.parentElement;
            if (parent?.matches('.showFieldset')) parent.style.display = 'none';
            const permissions = document.getElementById('permissions');
            if (permissions) permissions.style.display = '';
        });
    }

    Dropzone.autoDiscover = false;

    let beforeUnloadHandler: ((e: BeforeUnloadEvent) => void) | null = null;
    let uploadStarted = false;
    const chunkSizeBytes = chunkSize > 0 ? (chunkSize * 1024) : (100 * 1024 * 1024);
    const acceptedExtensions = (formatMode ? formatExt : fileExt).split(',').map(ext => '.' + ext.trim()).join(',');

    const dz = new Dropzone('#uploader', {
        url: 'ws.php?method=pwg.images.upload&format=json',
        clickable: '#addFiles',
        acceptedFiles: acceptedExtensions,
        maxFilesize: maxFileSize,
        chunking: true,
        forceChunking: false,
        chunkSize: chunkSizeBytes,
        parallelChunkUploads: false,
        autoProcessQueue: false,
        dictDefaultMessage: cfg.dropzoneMsg ?? 'Drop files here or click Add Photos',
        addRemoveLinks: true,
        dictRemoveFile: '✕',
    });

    function updateQueueButtons(): void {
        const files = dz.files as PwigoDropzoneFile[];
        const addFiles = document.getElementById('addFiles');
        const startUpload = document.getElementById('startUpload') as HTMLButtonElement | null;
        if (files.length > 0) {
            if (addFiles) { addFiles.classList.add('addFilesButtonChanged'); addFiles.classList.remove('buttonGradient'); addFiles.classList.add('buttonLike'); }
            if (startUpload) startUpload.disabled = false;
        } else {
            if (addFiles) { addFiles.classList.remove('addFilesButtonChanged', 'buttonLike'); addFiles.classList.add('buttonGradient'); }
            if (startUpload) startUpload.disabled = true;
        }
    }

    function handleUploadComplete(): void {
        Piecon.reset();

        if (!formatMode && uploadCategory) {
            void fetch('ws.php?format=json&method=pwg.images.uploadCompleted', {
                method: 'POST',
                body: new URLSearchParams({ pwgToken, image_id: uploadedPhotos.join(','), category_id: String(uploadCategory.id) }),
            });
        }

        document.querySelectorAll<HTMLElement>('#uploadForm, #permissions, .showFieldset').forEach(el => { el.style.display = 'none'; });

        const infoText = formatMode
            ? sprintf(formatsUploadedLabel, uploadedPhotos.length, [...new Set((dz.files as PwigoDropzoneFile[]).map(f => f.format_of))].length)
            : sprintf(photosUploadedLabel, uploadedPhotos.length);

        const infosEl = document.querySelector<HTMLElement>('.infos');
        if (infosEl) infosEl.insertAdjacentHTML('beforeend', '<ul><li>' + infoText + '</li></ul>');

        if (!formatMode && uploadCategory) {
            const html = sprintf(
                albumSummaryLabel,
                '<a href="admin.php?page=album-' + uploadCategory.id + '">' + uploadCategory.label + '</a>',
                parseInt(uploadCategory.nb_photos),
            );
            document.querySelector('.infos ul')?.insertAdjacentHTML('beforeend', '<li>' + html + '</li>');
        }

        if (infosEl) infosEl.style.display = '';

        document.querySelectorAll<HTMLAnchorElement>('.batchLink').forEach(function (el) {
            el.href = 'admin.php?page=photos_add&section=direct&batch=' + uploadedPhotos.join(',');
            el.innerHTML = sprintf(batchLabel, uploadedPhotos.length);
        });

        document.querySelectorAll<HTMLElement>('.afterUploadActions').forEach(el => { el.style.display = ''; });
        const uploadingActions = document.getElementById('uploadingActions');
        if (uploadingActions) uploadingActions.style.display = 'none';

        if (beforeUnloadHandler) {
            window.removeEventListener('beforeunload', beforeUnloadHandler);
            beforeUnloadHandler = null;
        }
    }

    dz.on('addedfile', function (_file: Dropzone.DropzoneFile) { updateQueueButtons(); });

    dz.on('addedfiles', function (files: PwigoDropzoneFile[]) { void (async () => {
        if (formatMode && !haveFormatsOriginal) {
            const fileNames: Record<string, string> = {};
            files.forEach((f) => { fileNames[f.upload?.uuid ?? ''] = f.name; });

            const result = await fetch('ws.php?format=json&method=pwg.images.formats.searchImage', {
                method: 'POST',
                body: new URLSearchParams({
                    category_id: (document.querySelector<HTMLSelectElement>('select[name=category]'))?.value ?? '',
                    filename_list: JSON.stringify(fileNames),
                }),
            }).then(r => r.json()) as { result: Record<string, { status: string; image_id?: number }> };

            const notFound: string[] = [];
            const multiple: string[] = [];

            files.forEach((f) => {
                const search = result.result[f.upload?.uuid ?? ''];
                if (search?.status === 'found') {
                    if (search.image_id !== undefined) f.format_of = search.image_id;
                } else {
                    if (search?.status === 'multiple') multiple.push(f.name);
                    else notFound.push(f.name);
                    dz.removeFile(f);
                }
            });

            updateQueueButtons();

            if (notFound.length || multiple.length) {
                const processTab = function (tab: string[]): string[] {
                    tab = tab.map(f => f.slice(0, f.indexOf('.')));
                    tab = tab.filter((f, i) => i === tab.indexOf(f));
                    if (tab.length > 5) { tab[5] = strAndXOthers.replace('%d', String(tab.length - 5)); tab = tab.splice(0, 6); }
                    return tab;
                };
                pwgConfirm({
                    title: strFormatWarning,
                    content: (notFound.length ? '<p>' + strFormatWarningNotFound.replace('%s', processTab(notFound).join(', ')) + '</p>' : '') +
                        (multiple.length ? '<p>' + strFormatWarningMultiple.replace('%s', processTab(multiple).join(', ')) + '</p>' : ''),
                    buttons: { ok: { text: strOk } },
                });
            }
        } else if (formatMode && haveFormatsOriginal) {
            files.forEach((f) => { f.format_of = originalImageId; });
        }
    })(); });

    dz.on('removedfile', function (_file: Dropzone.DropzoneFile) { updateQueueButtons(); });

    dz.on('sending', function (file: PwigoDropzoneFile, _xhr: unknown, formData: FormData) {
        const chunkIdx = formData.get('dzchunkindex');
        const totalChunks = formData.get('dztotalchunkcount');
        if (chunkIdx !== null) {
            formData.set('chunk', String(parseInt(chunkIdx as string)));
            formData.set('chunks', String(parseInt((totalChunks ?? '0') as string)));
        }
        formData.append('pwgToken', pwgToken);
        formData.append('name', file.name);
        if (formatMode) {
            if (file.format_of) formData.append('format_of', String(file.format_of));
        } else {
            const selCat = document.querySelector<HTMLSelectElement>('select[name=category]');
            const selLevel = document.querySelector<HTMLSelectElement>('select[name=level]');
            if (selCat) formData.append('category', selCat.value);
            if (selLevel) formData.append('level', selLevel.value);
        }
    });

    dz.on('processing', function (_file: Dropzone.DropzoneFile) {
        if (!uploadStarted) {
            uploadStarted = true;
            document.querySelectorAll<HTMLElement>('#startUpload, .selectFilesButtonBlock, .selectAlbumBlock').forEach(el => { el.style.display = 'none'; });
            const uploadingActions = document.getElementById('uploadingActions');
            if (uploadingActions) uploadingActions.style.display = '';
            document.querySelectorAll<HTMLElement>('.format-mode-group-manager').forEach(el => { el.style.display = 'none'; });
            if (!formatMode) {
                const sel = document.querySelector<HTMLSelectElement & { tomselect?: { getItem(v: string): HTMLElement | null } }>('select[name=category]');
                const categorySelectedId = sel?.value ?? '';
                let categorySelectedPath = '';
                if (sel?.tomselect) {
                    const item = sel.tomselect.getItem(categorySelectedId);
                    categorySelectedPath = item ? item.textContent || '' : '';
                }
                const selectedAlbum = document.querySelector<HTMLElement>('.selectedAlbum');
                if (selectedAlbum) {
                    selectedAlbum.style.display = '';
                    const span = selectedAlbum.querySelector('span');
                    if (span) span.innerHTML = categorySelectedPath;
                }
            }
            beforeUnloadHandler = function (e: BeforeUnloadEvent) {
                e.preventDefault();
            };
            window.addEventListener('beforeunload', beforeUnloadHandler!);
            const levelEl = document.querySelector<HTMLSelectElement>('select[name=level]');
            if (levelEl) levelEl.setAttribute('disabled', 'disabled');
        }
    });

    dz.on('totaluploadprogress', function (progress: number) {
        const progBar = document.querySelector<HTMLElement>('#uploadingActions .progressbar');
        if (progBar) progBar.style.width = progress + '%';
        Piecon.setProgress(progress);
    });

    type UploadResponseData = { stat?: string; message?: string; result?: { image_id: string; square_src: string; name: string; category: { id: string; label: string; nb_photos: string } } } | null;
    dz.on('success', function (file: Dropzone.DropzoneFile, response: unknown) {
        let data: UploadResponseData = null;
        if (typeof response === 'object') {
            data = response as UploadResponseData;
        } else {
            try { data = JSON.parse(response as string) as UploadResponseData; } catch (_e) { return; }
        }

        if (data?.stat === 'fail') {
            const errorsUl = document.querySelector('.errors ul');
            if (errorsUl) errorsUl.insertAdjacentHTML('beforeend', '<li>' + (data.message ?? 'Upload error') + '</li>');
            const errorsEl = document.querySelector<HTMLElement>('.errors');
            if (errorsEl) errorsEl.style.display = '';
            return;
        }

        if (!data?.result) return;

        file.previewElement.style.display = 'none';

        const uploadedPhotosEl = document.getElementById('uploadedPhotos');
        if (uploadedPhotosEl) {
            const fieldset = uploadedPhotosEl.closest('fieldset') as HTMLElement | null;
            if (fieldset) fieldset.style.display = '';
        }

        let html = '<a href="admin.php?page=photo-' + data.result.image_id + '" style="position:relative" target="_blank">';
        html += '<img src="' + data.result.square_src + '" class="thumbnail" title="' + data.result.name + '">';
        if (formatMode) html += '<div class="format-ext-name" title="' + file.name + '"><span>' + file.name.slice(file.name.indexOf('.')) + '</span></div>';
        html += '</a> ';

        if (uploadedPhotosEl) uploadedPhotosEl.insertAdjacentHTML('afterbegin', html);
        uploadedPhotos.push(parseInt(data.result.image_id));
        if (!formatMode) uploadCategory = data.result.category;
    });

    dz.on('error', function (_file: Dropzone.DropzoneFile, message: unknown, xhr: XMLHttpRequest | null) {
        let errMsg = typeof message === 'string' ? message : ((message as { message?: string }).message ?? 'Upload error');
        if (xhr?.responseText) {
            try { const parsed = JSON.parse(xhr.responseText) as { message?: string }; if (parsed.message) errMsg = parsed.message; } catch (_e) { /* ignore */ }
        }
        document.querySelector('.errors ul')?.insertAdjacentHTML('beforeend', '<li>' + errMsg + '</li>');
        const errEl = document.querySelector<HTMLElement>('.errors');
        if (errEl) errEl.style.display = '';
    });

    dz.on('queuecomplete', function () {
        if (uploadStarted) handleUploadComplete();
    });

    document.getElementById('startUpload')?.addEventListener('click', function (e) {
        e.preventDefault();
        dz.processQueue();
    });

    document.getElementById('cancelUpload')?.addEventListener('click', function (e) {
        e.preventDefault();
        dz.removeAllFiles(true);
        handleUploadComplete();
    });
}

initModule(init as (cfg: Record<string, unknown>) => void);
