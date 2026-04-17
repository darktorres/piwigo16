{combine_script id='common' load='footer' path='admin/themes/default/js/common.js'}
{combine_script id='dropzone' load='footer' path='node_modules/dropzone/dist/dropzone.js'}
{combine_script id='pwgConfirm' load='footer' path='admin/themes/default/js/pwgConfirm.js'}

{combine_css path='node_modules/dropzone/dist/basic.css'}

{if !$DISPLAY_FORMATS}
  {include file='inc/add_album.inc.tpl'}
{/if}

{combine_script id='LocalStorageCache' load='footer' path='admin/themes/default/js/LocalStorageCache.js'}

{combine_script id='tom-select' load='footer' path='node_modules/tom-select/dist/js/tom-select.complete.js'}
{combine_css path='node_modules/tom-select/dist/css/tom-select.default.css'}

{combine_script id='piecon' load='footer' path='node_modules/piecon/piecon.js'}

{html_style}<style>
  .addAlbumFormParent {
    display: none;
  }

  /* specific to this page, do not move in theme.css */
</style>{/html_style}

{footer_script}<script>
  const formatMode = {if $DISPLAY_FORMATS}true{else}false{/if};
  const haveFormatsOriginal = {if $HAVE_FORMATS_ORIGINAL}true{else}false{/if};
  const originalImageId = haveFormatsOriginal? '{if isset($FORMATS_ORIGINAL_INFO['id'])} {$FORMATS_ORIGINAL_INFO['id']} {else} -1 {/if}' : -1;

  {* <!-- CATEGORIES --> *}
  if (!formatMode) {
    var categoriesCache = new CategoriesCache({
      serverKey: '{$CACHE_KEYS.categories}',
      serverId: '{$CACHE_KEYS._hash}',
      rootUrl: '{$ROOT_URL}'
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
          var uploadForm = document.getElementById("uploadForm");
          if (uploadForm) uploadForm.style.display = '';
          document.querySelectorAll(".addAlbumEmptyCenter").forEach(function(el) { el.style.display = 'none'; });
          document.querySelectorAll(".addAlbumEmptyCenter").forEach(function(el) { el.style.height = "auto"; });
          document.querySelectorAll(".addAlbumFormParent").forEach(function(el) { el.setAttribute("style", "display: block !important;"); });

          var sel = document.querySelector("select[name=category]");
          var categorySelectedId = sel ? sel.value : '';
          var categorySelectedPath = '';
          if (sel && sel.tomselect) {
            var item = sel.tomselect.getItem(categorySelectedId);
            categorySelectedPath = item ? item.textContent : '';
          }
          var selectedAlbum = document.querySelector('.selectedAlbum');
          if (selectedAlbum) {
            selectedAlbum.style.display = '';
            var span = selectedAlbum.querySelector('span');
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

  var pwg_token = '{$pwg_token}';
  var photosUploaded_label = "{'%d photos uploaded'|translate}";
  var formatsUploaded_label = "{'%d formats uploaded for %d photos'|translate}";
  var batch_Label = "{'Manage this set of %d photos'|translate}";
  var albumSummary_label = "{'Album "%s" now contains %d photos'|translate|escape}";
  var str_format_warning = "{'Error when trying to detect formats'|translate}";
  var str_ok = "{'Ok'|translate}";
  var str_format_warning_multiple = "{'There is multiple image in the database with the following names : %s.'|translate}";
  var str_format_warning_notFound = "{'No picture found with the following name : %s.'|translate}";
  var str_and_X_others = "{'and %d more'|translate}";
  var file_ext = "{$file_exts}";
  var format_ext = "{$format_ext}";
  var uploadedPhotos = [];
  var uploadCategory = null;

  document.addEventListener('DOMContentLoaded', function() {
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
          var appsEl = document.querySelector(".promote-apps");
          if (appsEl) appsEl.style.display = 'none';
        });
      });
    });

    var showInfoEl = document.querySelector("#uploadWarningsSummary a.showInfo");
    if (showInfoEl) {
      showInfoEl.addEventListener('click', function(event) {
        event.preventDefault();
        var summary = document.getElementById("uploadWarningsSummary");
        var warnings = document.getElementById("uploadWarnings");
        if (summary) summary.style.display = 'none';
        if (warnings) warnings.style.display = '';
      });
    }

    var showPermsEl = document.getElementById("showPermissions");
    if (showPermsEl) {
      showPermsEl.addEventListener('click', function(event) {
        event.preventDefault();
        var parent = this.parentElement;
        if (parent && parent.matches(".showFieldset")) parent.style.display = 'none';
        var permissions = document.getElementById("permissions");
        if (permissions) permissions.style.display = '';
      });
    }

    Dropzone.autoDiscover = false;

    var beforeUnloadHandler = null;
    var uploadStarted = false;
    var chunkSizeBytes = {$chunk_size} > 0 ? ({$chunk_size} * 1024) : (100 * 1024 * 1024);
    var acceptedExtensions = (formatMode ? format_ext : file_ext).split(',').map(function(ext) {
      return '.' + ext.trim();
    }).join(',');

    var dz = new Dropzone('#uploader', {
      url: 'ws.php?method=pwg.images.upload&format=json',
      clickable: '#addFiles',
      acceptedFiles: acceptedExtensions,
      maxFilesize: {$max_file_size},
      chunking: true,
      forceChunking: false,
      chunkSize: chunkSizeBytes,
      parallelChunkUploads: false,
      autoProcessQueue: false,
      dictDefaultMessage: "{'Drop files here or click Add Photos'|translate}",
      addRemoveLinks: true,
      dictRemoveFile: "✕",
    });

    function updateQueueButtons() {
      var files = dz.files;
      var addFiles = document.getElementById('addFiles');
      var startUpload = document.getElementById('startUpload');
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
            pwg_token: pwg_token,
            image_id: uploadedPhotos.join(","),
            category_id: uploadCategory.id,
          }),
        });
      }

      document.querySelectorAll("#uploadForm, #permissions, .showFieldset").forEach(function(el) {
        el.style.display = 'none';
      });

      var infoText = formatMode ?
        sprintf(formatsUploaded_label, uploadedPhotos.length, [...new Set(dz.files.map(function(f) { return f.format_of; }))].length) :
        sprintf(photosUploaded_label, uploadedPhotos.length);

      var infosEl = document.querySelector(".infos");
      if (infosEl) infosEl.insertAdjacentHTML('beforeend', '<ul><li>' + infoText + '</li></ul>');

      if (!formatMode && uploadCategory) {
        var html = sprintf(
          albumSummary_label,
          '<a href="admin.php?page=album-' + uploadCategory.id + '">' + uploadCategory.label + '</a>',
          parseInt(uploadCategory.nb_photos)
        );
        var infosUl = document.querySelector(".infos ul");
        if (infosUl) infosUl.insertAdjacentHTML('beforeend', '<li>' + html + '</li>');
      }

      if (infosEl) infosEl.style.display = '';

      document.querySelectorAll(".batchLink").forEach(function(el) {
        el.href = "admin.php?page=photos_add&section=direct&batch=" + uploadedPhotos.join(",");
        el.innerHTML = sprintf(batch_Label, uploadedPhotos.length);
      });

      document.querySelectorAll(".afterUploadActions").forEach(function(el) { el.style.display = ''; });
      var uploadingActions = document.getElementById('uploadingActions');
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
        var fileNames = {};
        files.forEach(function(f) { fileNames[f.upload.uuid] = f.name; });

        var result = await fetch("ws.php?format=json&method=pwg.images.formats.searchImage", {
          method: "POST",
          body: new URLSearchParams({
            category_id: (document.querySelector("select[name=category]") || {}).value || '',
            filename_list: JSON.stringify(fileNames),
          }),
        }).then(function(r) { return r.json(); });

        var notFound = [];
        var multiple = [];

        files.forEach(function(f) {
          var search = result.result[f.upload.uuid];
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
          var processTab = function(tab) {
            tab = tab.map(function(f) { return f.slice(0, f.indexOf('.')); });
            tab = tab.filter(function(f, i) { return i === tab.indexOf(f); });
            if (tab.length > 5) { tab[5] = str_and_X_others.replace('%d', tab.length - 5); tab = tab.splice(0, 6); }
            return tab;
          };
          var notFoundStr = processTab(notFound);
          var multStr = processTab(multiple);
          pwgConfirm({
            title: str_format_warning,
            content: (notFound.length ? '<p>' + str_format_warning_notFound.replace('%s', notFoundStr.join(', ')) + '</p>' : '') +
                     (multiple.length ? '<p>' + str_format_warning_multiple.replace('%s', multStr.join(', ')) + '</p>' : ''),
            buttons: { ok: { text: 'OK' } }
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
      // Map Dropzone chunk params to plupload-compatible chunk/chunks for the PHP endpoint
      if (formData.get) {
        var chunkIdx = formData.get('dzchunkindex');
        var totalChunks = formData.get('dztotalchunkcount');
        if (chunkIdx !== null) {
          formData.set("chunk", parseInt(chunkIdx));
          formData.set("chunks", parseInt(totalChunks));
        }
      }
      formData.append("pwg_token", pwg_token);
      formData.append("name", file.name);
      if (formatMode) {
        if (file.format_of) formData.append("format_of", file.format_of);
      } else {
        var selCat = document.querySelector("select[name=category]");
        var selLevel = document.querySelector("select[name=level]");
        if (selCat) formData.append("category", selCat.value);
        if (selLevel) formData.append("level", selLevel.value);
      }
    });

    dz.on("processing", function(file) {
      if (!uploadStarted) {
        uploadStarted = true;
        document.querySelectorAll('#startUpload, .selectFilesButtonBlock, .selectAlbumBlock').forEach(function(el) { el.style.display = 'none'; });
        var uploadingActions = document.getElementById('uploadingActions');
        if (uploadingActions) uploadingActions.style.display = '';
        document.querySelectorAll('.format-mode-group-manager').forEach(function(el) { el.style.display = 'none'; });
        if (!formatMode) {
          var sel = document.querySelector("select[name=category]");
          var categorySelectedId = sel ? sel.value : '';
          var categorySelectedPath = '';
          if (sel && sel.tomselect) {
            var item = sel.tomselect.getItem(categorySelectedId);
            categorySelectedPath = item ? item.textContent : '';
          }
          var selectedAlbum = document.querySelector('.selectedAlbum');
          if (selectedAlbum) {
            selectedAlbum.style.display = '';
            var span = selectedAlbum.querySelector('span');
            if (span) span.innerHTML = categorySelectedPath;
          }
        }
        beforeUnloadHandler = function(e) {
          e.preventDefault();
          e.returnValue = "{'Upload in progress'|translate|escape}";
          return e.returnValue;
        };
        window.addEventListener('beforeunload', beforeUnloadHandler);
        var levelEl = document.querySelector("select[name=level]");
        if (levelEl) levelEl.setAttribute("disabled", "disabled");
      }
    });

    dz.on("totaluploadprogress", function(progress) {
      var progBar = document.querySelector('#uploadingActions .progressbar');
      if (progBar) progBar.style.width = progress + '%';
      Piecon.setProgress(progress);
    });

    dz.on("success", function(file, response) {
      var data = typeof response === 'object' ? response : null;
      if (!data) { try { data = JSON.parse(response); } catch(e) { return; } }

      if (data && data.stat === 'fail') {
        var errorsUl = document.querySelector(".errors ul");
        if (errorsUl) errorsUl.insertAdjacentHTML('beforeend', '<li>' + (data.message || 'Upload error') + '</li>');
        var errorsEl = document.querySelector(".errors");
        if (errorsEl) errorsEl.style.display = '';
        return;
      }

      if (!data || !data.result) return;

      if (file.previewElement) file.previewElement.style.display = 'none';

      var uploadedPhotosEl = document.getElementById("uploadedPhotos");
      if (uploadedPhotosEl) {
        var fieldset = uploadedPhotosEl.closest("fieldset");
        if (fieldset) fieldset.style.display = '';
      }

      var html = '<a href="admin.php?page=photo-' + data.result.image_id + '" style="position:relative" target="_blank">';
      html += '<img src="' + data.result.square_src + '" class="thumbnail" title="' + data.result.name + '">';
      if (formatMode) html += '<div class="format-ext-name" title="' + file.name + '"><span>' + file.name.slice(file.name.indexOf('.')) + '</span></div>';
      html += '</a> ';

      if (uploadedPhotosEl) uploadedPhotosEl.insertAdjacentHTML('afterbegin', html);
      uploadedPhotos.push(parseInt(data.result.image_id));
      if (!formatMode) uploadCategory = data.result.category;
    });

    dz.on("error", function(file, message, xhr) {
      var errMsg = typeof message === 'string' ? message : (message && message.message) || 'Upload error';
      if (xhr && xhr.responseText) {
        try { var parsed = JSON.parse(xhr.responseText); if (parsed.message) errMsg = parsed.message; } catch(e) {}
      }
      var errorsUl = document.querySelector(".errors ul");
      if (errorsUl) errorsUl.insertAdjacentHTML('beforeend', '<li>' + errMsg + '</li>');
      var errorsEl = document.querySelector(".errors");
      if (errorsEl) errorsEl.style.display = '';
    });

    dz.on("queuecomplete", function() {
      if (uploadStarted) handleUploadComplete();
    });

    var startUpload = document.getElementById('startUpload');
    if (startUpload) {
      startUpload.addEventListener('click', function(e) {
        e.preventDefault();
        dz.processQueue();
      });
    }

    var cancelUpload = document.getElementById('cancelUpload');
    if (cancelUpload) {
      cancelUpload.addEventListener('click', function(e) {
        e.preventDefault();
        dz.removeAllFiles(true);
        handleUploadComplete();
      });
    }
  });
</script>{/footer_script}

<div id="photosAddContent">

  {if count($setup_errors) > 0}
    <div class="errors">
      <ul>
        {foreach $setup_errors as $error}
          <li>{$error}</li>
        {/foreach}
      </ul>
    </div>
  {else}
    {if count($setup_warnings) > 0}
      <div class="warnings">
        <ul>
          {foreach $setup_warnings as $warning}
            <li>{$warning}</li>
          {/foreach}
        </ul>
        <div class="hideButton" style="text-align:center"><a href="{$hide_warnings_link}">{'Hide'|translate}</a></div>
      </div>
    {/if}
  {/if} {* $setup_errors *}

  {if $PROMOTE_MOBILE_APPS}
    <div class="promote-apps">
      <div class="promote-content">
        <div class="left-side">
          <img src="https://sandbox.piwigo.com/uploads/4/y/1/4y1zzhnrnw//2023/01/24/20230124175152-015bc1e3.png">
        </div>
        <div class="promote-text">
          <span>{"Piwigo is also on mobile."|translate|escape:javascript}</span>
          <span>{"Try now !"|translate|escape:javascript}</span>
        </div>
        <div class="right-side">
          <div>
            <a href="{$PHPWG_URL}/mobile-applications" target="_blank"><span
                class="go-to-porg icon-link-1">{"Discover"|translate|escape:javascript}</span></a>
          </div>
        </div>
      </div>
      <span class="dont-show-again icon-cancel tiptip"
        title="{'Understood, do not show again'|translate|escape:javascript}"></span>
    </div>
  {/if}

  {if $ENABLE_FORMATS}
    <div class="format-mode-group-manager">
      <label class="switch"
        onClick="window.location.replace('{$SWITCH_MODE_URL}'); document.querySelector('.switch .slider').classList.add('loading');">
        <input type="checkbox" id="toggleFormatMode" {if $DISPLAY_FORMATS}checked{/if}>
        <span class="slider round"></span>
      </label>
      <p>{'Upload Formats'|translate}</p>
    </div>
  {/if}

  {if !$DISPLAY_FORMATS}
    <div class="addAlbumEmptyCenter" {if $NB_ALBUMS > 0} style="display:none;" {/if}>
      <div class="addAlbumEmpty">
        <div class="addAlbumEmptyTitle">{'Welcome!'|translate}</div>
        <p class="addAlbumEmptyInfos">{'Piwigo requires an album to add photos.'|translate}</p>
        <a href="#" data-add-album="category" class="buttonLike">{'Create a first album'|translate}</a>
      </div>
    </div>
  {/if}

  <div class="infos" style="display:none"><i class="eiw-icon icon-ok"></i></div>
  <div class="errors" style="display:none"><i class="eiw-icon icon-cancel"></i>
    <ul></ul>
  </div>

  <p class="afterUploadActions" style="margin:10px; display:none;">
    {if !$DISPLAY_FORMATS}
      <a class="batchLink icon-pencil"></a><span class="buttonSeparator">{'or'|translate}</span><a
        href="admin.php?page=photos_add" class="icon-plus-circled">{'Add another set of photos'|translate}</a>
    {else}
      <a href="admin.php?page=photos_add&formats" class="icon-plus-circled">{'Add another set of formats'|translate}</a>
    {/if}
  </p>

  <form id="uploadForm" class="{if $DISPLAY_FORMATS}format-mode{/if}" enctype="multipart/form-data" method="post"
    action="{$form_action}" {if $NB_ALBUMS == 0} style="display:none;" {/if}>
    {if not $DISPLAY_FORMATS}
      <fieldset class="selectAlbum">
        <legend><span class="icon-folder-open icon-red"></span>{'Drop into album'|translate}</legend>
        <div class="selectedAlbum" {if !isset($ADD_TO_ALBUM)} style="display: none" {/if}><span
            class="icon-sitemap">{if isset($ADD_TO_ALBUM)}{$ADD_TO_ALBUM}{/if}</span></div>
        <div class="selectAlbumBlock" {if isset($ADD_TO_ALBUM)} style="display: none" {/if}>
          <span id="albumSelection">
            <select data-selectize="categories" data-value="{$selected_category|json_encode|escape:html}"
              data-default="first" name="category" style="width:600px"></select>
          </span>
          <span class="orChoice">{'... or '|translate} </span>
          <a href="#" data-add-album="category" class="orCreateAlbum icon-plus-circled">
            {'create a new album'|translate}</a>
        </div>
      </fieldset>
    {elseif $HAVE_FORMATS_ORIGINAL}
      <fieldset class="originalPicture">
        <legend><span class="icon-link-1 icon-red"></span>{'Picture to associate formats with'|translate}</legend>
        <a class='info-framed' href='{$FORMATS_ORIGINAL_INFO['u_edit']}' title='{'Edit photo'|translate}'>
          <div class='info-framed-icon'>
            <img src='{$FORMATS_ORIGINAL_INFO['src']}'></i>
          </div>
          <div class='info-framed-container'>
            <div class='info-framed-title'>{$FORMATS_ORIGINAL_INFO['name']}</div>
            {if isset($FORMATS_ORIGINAL_INFO['formats'])}<div>{$FORMATS_ORIGINAL_INFO['formats']}</div>{/if}
            <div>{$FORMATS_ORIGINAL_INFO.ext}</div>
          </div>
        </a>
      </fieldset>
    {/if}
    {*
    <p class="showFieldset"><a id="showPermissions" href="#">{'Manage Permissions'|translate}</a></p>

    <fieldset id="permissions" style="display:none">
      <legend>{'Who can see these photos?'|translate}</legend>

      <select name="level" size="1">
        {html_options options=$level_options selected=$level_options_selected}
      </select>
    </fieldset>
*}
    <fieldset class="selectFiles">
      <legend><span class="icon-file-image icon-yellow"></span>{'Select files'|translate}</legend>
      <div class="selectFilesButtonBlock">
        <button id="addFiles" type="button"
          class="buttonGradient">{if not $DISPLAY_FORMATS}{'Add Photos'|translate}{else}{'Add formats'|translate}{/if}<i
            class="icon-plus-circled"></i></button>
        <div class="selectFilesinfo">
          {if isset($original_resize_maxheight)}
            <p class="uploadInfo">
              {'The picture dimensions will be reduced to %dx%d pixels.'|translate:$original_resize_maxwidth:$original_resize_maxheight}
            </p>
          {/if}
          <p id="uploadWarningsSummary">
            {if not $DISPLAY_FORMATS}
              {'Allowed file types: %s.'|translate:$upload_file_types}
            {else}
              {'Allowed file types: %s.'|translate:$str_format_ext}
              {if !$HAVE_FORMATS_ORIGINAL}
            <p>{'The original picture will be detected with the filename (without extension).'|translate}</p>{/if}
          {/if}
          </p>
          </p>
          {if isset($max_upload_resolution)}
            {'Approximate maximum resolution: %dM pixels (that\'s %dx%d pixels).'|translate:$max_upload_resolution:$max_upload_width:$max_upload_height}
          {/if}
          </p>
        </div>
      </div>
      <div id="uploader"></div>

    </fieldset>

    <div id="uploadingActions" style="display:none">
      <div class="big-progressbar" style="max-width:98%;margin-bottom: 10px;">
        <div class="progressbar" style="width:0%"></div>
      </div>
      <button id="cancelUpload" type="button" class="buttonLike icon-cancel-circled">{'Cancel'|translate}</button>
    </div>

    <button id="startUpload" type="button" class="buttonGradient icon-upload" disabled>{'Start Upload'|translate}</button>

  </form>

  <fieldset style="display:none" class="Addedphotos">
    <div id="uploadedPhotos"></div>
  </fieldset>

</div> <!-- photosAddContent -->