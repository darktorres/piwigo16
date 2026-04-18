import { CategoriesCache } from './LocalStorageCache.js';
import { pwgAddAlbum } from './addAlbum.js';
import { pwgConfirm } from './pwgConfirm.js';
import { sprintf } from './common.js';
import { initModule } from './moduleInit.js';
import Dropzone from 'dropzone';
import Piecon from 'piecon';

export function init(cfg) {
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
    rootUrl = ''
  } = cfg;

  let uploadedPhotos = [];
  let uploadCategory = null;

  // Initialize categories cache if not in format mode
  if (!formatMode) {
    const categoriesCache = new CategoriesCache({
      serverKey: categoriesServerKey,
      serverId: categoriesServerId,
      rootUrl: rootUrl
    });

    categoriesCache.selectize(document.querySelectorAll('[data-selectize=categories]'), {
      filter: function(categories, options) {
        if (categories.length > 0) {
          document.querySelectorAll(".addAlbumEmptyCenter").forEach(function(el) { el.style.height = "auto"; });
          document.querySelectorAll(".addAlbumFormParent").forEach(function(el) { el.setAttribute("style", "display: block !important;"); });
        }
        return categories;
      }
    });

    document.querySelectorAll('[data-add-album]').forEach(function(btn) {
      pwgAddAlbum(btn, {
        afterSelect: function() {
          const uploadForm = document.getElementById("uploadForm");
          if (uploadForm) uploadForm.style.display = '';
          document.querySelectorAll(".addAlbumEmptyCenter").forEach(function(el) { el.style.display = 'none'; el.style.height = "auto"; });
          document.querySelectorAll(".addAlbumFormParent").forEach(function(el) { el.setAttribute("style", "display: block !important;"); });

          const sel = document.querySelector("select[name=category]");
          const categorySelectedId = sel ? sel.value : '';
          let categorySelectedPath = '';
          if (sel && sel.tomselect) {
            const item = sel.tomselect.getItem(categorySelectedId);
            categorySelectedPath = item ? item.textContent : '';
          }
          const selectedAlbum = document.querySelector('.selectedAlbum');
          if (selectedAlbum) {
            selectedAlbum.style.display = '';
            const span = selectedAlbum.querySelector('span');
            if (span) span.innerHTML = categorySelectedPath;
          }
          document.querySelectorAll('.selectAlbumBlock').forEach(function(el) { el.style.display = 'none'; });
        }
      });
    });

    Piecon.setOptions({
      color: '#ff7700',
      background: '#bbb',
      shadow: '#fff',
      fallback: 'force'
    });
  }

  // Mobile apps promo dismiss
  document.querySelectorAll(".dont-show-again").forEach(function(el) {
    el.addEventListener("click", function() {
      fetch("ws.php?format=json&method=pwg.users.preferences.set", {
        method: "POST",
        body: new URLSearchParams({
          param: 'promote-mobile-apps',
          value: false,
        }),
      })
      .then(function(r) { return r.json(); })
      .then(function(res) {
        const appsEl = document.querySelector(".promote-apps");
        if (appsEl) appsEl.style.display = 'none';
      });
    });
  });

  // Upload warnings toggle
  const showInfoEl = document.querySelector("#uploadWarningsSummary a.showInfo");
  if (showInfoEl) {
    showInfoEl.addEventListener('click', function(event) {
      event.preventDefault();
      const summary = document.getElementById("uploadWarningsSummary");
      const warnings = document.getElementById("uploadWarnings");
      if (summary) summary.style.display = 'none';
      if (warnings) warnings.style.display = '';
    });
  }

  // Permissions toggle
  const showPermsEl = document.getElementById("showPermissions");
  if (showPermsEl) {
    showPermsEl.addEventListener('click', function(event) {
      event.preventDefault();
      const parent = this.parentElement;
      if (parent && parent.matches(".showFieldset")) parent.style.display = 'none';
      const permissions = document.getElementById("permissions");
      if (permissions) permissions.style.display = '';
    });
  }

  // Dropzone initialization
  Dropzone.autoDiscover = false;

  let beforeUnloadHandler = null;
  let uploadStarted = false;
  const chunkSizeBytes = chunkSize > 0 ? (chunkSize * 1024) : (100 * 1024 * 1024);
  const acceptedExtensions = (formatMode ? formatExt : fileExt).split(',').map(function(ext) {
    return '.' + ext.trim();
  }).join(',');

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
    dictDefaultMessage: cfg.dropzoneMsg || "Drop files here or click Add Photos",
    addRemoveLinks: true,
    dictRemoveFile: "✕",
  });

  function updateQueueButtons() {
    const files = dz.files;
    const addFiles = document.getElementById('addFiles');
    const startUpload = document.getElementById('startUpload');
    if (files.length > 0) {
      if (addFiles) { addFiles.classList.add('addFilesButtonChanged'); addFiles.classList.remove('buttonGradient'); addFiles.classList.add('buttonLike'); }
      if (startUpload) startUpload.disabled = false;
    } else {
      if (addFiles) { addFiles.classList.remove('addFilesButtonChanged', 'buttonLike'); addFiles.classList.add('buttonGradient'); }
      if (startUpload) startUpload.disabled = true;
    }
  }

  function handleUploadComplete() {
    Piecon.reset();

    if (!formatMode && uploadCategory) {
      fetch("ws.php?format=json&method=pwg.images.uploadCompleted", {
        method: "POST",
        body: new URLSearchParams({
          pwgToken: pwgToken,
          image_id: uploadedPhotos.join(","),
          category_id: uploadCategory.id,
        }),
      });
    }

    document.querySelectorAll("#uploadForm, #permissions, .showFieldset").forEach(function(el) {
      el.style.display = 'none';
    });

    const infoText = formatMode ?
      sprintf(formatsUploadedLabel, uploadedPhotos.length, [...new Set(dz.files.map(function(f) { return f.format_of; }))].length) :
      sprintf(photosUploadedLabel, uploadedPhotos.length);

    const infosEl = document.querySelector(".infos");
    if (infosEl) infosEl.insertAdjacentHTML('beforeend', '<ul><li>' + infoText + '</li></ul>');

    if (!formatMode && uploadCategory) {
      const html = sprintf(
        albumSummaryLabel,
        '<a href="admin.php?page=album-' + uploadCategory.id + '">' + uploadCategory.label + '</a>',
        parseInt(uploadCategory.nb_photos)
      );
      const infosUl = document.querySelector(".infos ul");
      if (infosUl) infosUl.insertAdjacentHTML('beforeend', '<li>' + html + '</li>');
    }

    if (infosEl) infosEl.style.display = '';

    document.querySelectorAll(".batchLink").forEach(function(el) {
      el.href = "admin.php?page=photos_add&section=direct&batch=" + uploadedPhotos.join(",");
      el.innerHTML = sprintf(batchLabel, uploadedPhotos.length);
    });

    document.querySelectorAll(".afterUploadActions").forEach(function(el) { el.style.display = ''; });
    const uploadingActions = document.getElementById('uploadingActions');
    if (uploadingActions) uploadingActions.style.display = 'none';

    if (beforeUnloadHandler) {
      window.removeEventListener('beforeunload', beforeUnloadHandler);
      beforeUnloadHandler = null;
    }
  }

  dz.on("addedfile", function(file) {
    updateQueueButtons();
  });

  dz.on("addedfiles", async function(files) {
    if (formatMode && !haveFormatsOriginal) {
      const fileNames = {};
      files.forEach(function(f) { fileNames[f.upload.uuid] = f.name; });

      const result = await fetch("ws.php?format=json&method=pwg.images.formats.searchImage", {
        method: "POST",
        body: new URLSearchParams({
          category_id: (document.querySelector("select[name=category]") || {}).value || '',
          filename_list: JSON.stringify(fileNames),
        }),
      }).then(function(r) { return r.json(); });

      const notFound = [];
      const multiple = [];

      files.forEach(function(f) {
        const search = result.result[f.upload.uuid];
        if (search && search.status == "found") {
          f.format_of = search.image_id;
        } else {
          if (search && search.status == "multiple") multiple.push(f.name);
          else notFound.push(f.name);
          dz.removeFile(f);
        }
      });

      updateQueueButtons();

      if (notFound.length || multiple.length) {
        const processTab = function(tab) {
          tab = tab.map(function(f) { return f.slice(0, f.indexOf('.')); });
          tab = tab.filter(function(f, i) { return i === tab.indexOf(f); });
          if (tab.length > 5) { tab[5] = str_and_X_others.replace('%d', tab.length - 5); tab = tab.splice(0, 6); }
          return tab;
        };
        const notFoundStr = processTab(notFound);
        const multStr = processTab(multiple);
        pwgConfirm({
          title: strFormatWarning,
          content: (notFound.length ? '<p>' + strFormatWarning_notFound.replace('%s', notFoundStr.join(', ')) + '</p>' : '') +
                   (multiple.length ? '<p>' + strFormatWarning_multiple.replace('%s', multStr.join(', ')) + '</p>' : ''),
          buttons: { ok: { text: strOk } }
        });
      }
    } else if (formatMode && haveFormatsOriginal) {
      files.forEach(function(f) { f.format_of = originalImageId; });
    }
  });

  dz.on("removedfile", function(file) {
    updateQueueButtons();
  });

  dz.on("sending", function(file, xhr, formData) {
    if (formData.get) {
      const chunkIdx = formData.get('dzchunkindex');
      const totalChunks = formData.get('dztotalchunkcount');
      if (chunkIdx !== null) {
        formData.set("chunk", parseInt(chunkIdx));
        formData.set("chunks", parseInt(totalChunks));
      }
    }
    formData.append("pwgToken", pwgToken);
    formData.append("name", file.name);
    if (formatMode) {
      if (file.format_of) formData.append("format_of", file.format_of);
    } else {
      const selCat = document.querySelector("select[name=category]");
      const selLevel = document.querySelector("select[name=level]");
      if (selCat) formData.append("category", selCat.value);
      if (selLevel) formData.append("level", selLevel.value);
    }
  });

  dz.on("processing", function(file) {
    if (!uploadStarted) {
      uploadStarted = true;
      document.querySelectorAll('#startUpload, .selectFilesButtonBlock, .selectAlbumBlock').forEach(function(el) { el.style.display = 'none'; });
      const uploadingActions = document.getElementById('uploadingActions');
      if (uploadingActions) uploadingActions.style.display = '';
      document.querySelectorAll('.format-mode-group-manager').forEach(function(el) { el.style.display = 'none'; });
      if (!formatMode) {
        const sel = document.querySelector("select[name=category]");
        const categorySelectedId = sel ? sel.value : '';
        let categorySelectedPath = '';
        if (sel && sel.tomselect) {
          const item = sel.tomselect.getItem(categorySelectedId);
          categorySelectedPath = item ? item.textContent : '';
        }
        const selectedAlbum = document.querySelector('.selectedAlbum');
        if (selectedAlbum) {
          selectedAlbum.style.display = '';
          const span = selectedAlbum.querySelector('span');
          if (span) span.innerHTML = categorySelectedPath;
        }
      }
      beforeUnloadHandler = function(e) {
        e.preventDefault();
        e.returnValue = cfg.strUploadInProgress || "Upload in progress";
        return e.returnValue;
      };
      window.addEventListener('beforeunload', beforeUnloadHandler);
      const levelEl = document.querySelector("select[name=level]");
      if (levelEl) levelEl.setAttribute("disabled", "disabled");
    }
  });

  dz.on("totaluploadprogress", function(progress) {
    const progBar = document.querySelector('#uploadingActions .progressbar');
    if (progBar) progBar.style.width = progress + '%';
    Piecon.setProgress(progress);
  });

  dz.on("success", function(file, response) {
    let data = typeof response === 'object' ? response : null;
    if (!data) { try { data = JSON.parse(response); } catch(e) { return; } }

    if (data && data.stat === 'fail') {
      const errorsUl = document.querySelector(".errors ul");
      if (errorsUl) errorsUl.insertAdjacentHTML('beforeend', '<li>' + (data.message || 'Upload error') + '</li>');
      const errorsEl = document.querySelector(".errors");
      if (errorsEl) errorsEl.style.display = '';
      return;
    }

    if (!data || !data.result) return;

    if (file.previewElement) file.previewElement.style.display = 'none';

    const uploadedPhotosEl = document.getElementById("uploadedPhotos");
    if (uploadedPhotosEl) {
      const fieldset = uploadedPhotosEl.closest("fieldset");
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

  dz.on("error", function(file, message, xhr) {
    let errMsg = typeof message === 'string' ? message : (message && message.message) || 'Upload error';
    if (xhr && xhr.responseText) {
      try { const parsed = JSON.parse(xhr.responseText); if (parsed.message) errMsg = parsed.message; } catch(e) {}
    }
    const errorsUl = document.querySelector(".errors ul");
    if (errorsUl) errorsUl.insertAdjacentHTML('beforeend', '<li>' + errMsg + '</li>');
    const errorsEl = document.querySelector(".errors");
    if (errorsEl) errorsEl.style.display = '';
  });

  dz.on("queuecomplete", function() {
    if (uploadStarted) handleUploadComplete();
  });

  const startUpload = document.getElementById('startUpload');
  if (startUpload) {
    startUpload.addEventListener('click', function(e) {
      e.preventDefault();
      dz.processQueue();
    });
  }

  const cancelUpload = document.getElementById('cancelUpload');
  if (cancelUpload) {
    cancelUpload.addEventListener('click', function(e) {
      e.preventDefault();
      dz.removeAllFiles(true);
      handleUploadComplete();
    });
  }
}

initModule(init);
