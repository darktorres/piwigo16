{combine_script id='dropzone' load='footer' path='node_modules/dropzone/dist/dropzone.js'}

{combine_css path='node_modules/dropzone/dist/basic.css'}

{combine_script id='glightbox' load='footer' path='node_modules/glightbox/dist/js/glightbox.min.js'}
{combine_css path='node_modules/glightbox/dist/css/glightbox.min.css'}

{combine_script id='piecon' load='footer' path='node_modules/piecon/piecon.js'}

{footer_script}<script type="module">
  import { sprintf } from 'admin/themes/default/js/common.js';
  var rootUrl = "{get_absolute_root_url()}";
  document.addEventListener('DOMContentLoaded', function() {
    var dz; // populated by Dropzone init below

    function checkUploadStart() {
      var nbErrors = 0;
      var formErrors = document.getElementById("formErrors");
      if (formErrors) formErrors.style.display = 'none';
      document.querySelectorAll("#formErrors li").forEach(function(li) {
        li.style.display = 'none';
      });

      var selectedOptions = document.querySelectorAll("#albumSelect option:checked");
      if (selectedOptions.length == 0) {
        var noAlbumEl = document.querySelector("#formErrors #noAlbum");
        if (noAlbumEl) noAlbumEl.style.display = '';
        nbErrors++;
      }

      var nbFiles = dz ? dz.files.length : 0;

      if (nbFiles == 0) {
        var noPhotoEl = document.querySelector("#formErrors #noPhoto");
        if (noPhotoEl) noPhotoEl.style.display = '';
        nbErrors++;
      }

      if (nbErrors != 0) {
        if (formErrors) formErrors.style.display = '';
        return false;
      } else {
        return true;
      }

    }

    function humanReadableFileSize(bytes) {
      var byteSize = Math.round(bytes / 1024 * 100) * .01;
      var suffix = 'KB';

      if (byteSize > 1000) {
        byteSize = Math.round(byteSize * .001 * 100) * .01;
        suffix = 'MB';
      }

      var sizeParts = byteSize.toString().split('.');
      if (sizeParts.length > 1) {
        byteSize = sizeParts[0] + '.' + sizeParts[1].substr(0, 2);
      } else {
        byteSize = sizeParts[0];
      }

      return byteSize + suffix;
    }

    function fillCategoryListbox(selectId, selectedValue) {
      var params = new URLSearchParams({
        recursive: true,
        fullname: true,
        format: "json"
      });
      fetch(rootUrl + "/ws.php?format=json&method=pwg.categories.getList&" + params)
        .then(function(response) {
          return response.json();
        })
        .then(function(data) {
          var selectEl = document.getElementById(selectId);
          if (selectEl && data.result && data.result.categories) {
            data.result.categories.forEach(function(category) {
              var option = document.createElement('option');
              option.value = category.id;
              option.textContent = category.name;
              if (category.id == selectedValue) {
                option.selected = true;
              }
              selectEl.appendChild(option);
            });
          }
        });
    }

    document.querySelectorAll(".addAlbumOpen").forEach(function(btn) {
      btn.addEventListener('click', function(e) {
        e.preventDefault();
        var dlg = document.getElementById("addAlbumForm");
        if (dlg) {
          var categoryNameInput = dlg.querySelector("input[name=category_name]");
          if (categoryNameInput) categoryNameInput.focus();
          dlg.showModal();
        }
      });
    });

    var addAlbumForm = document.querySelector("#addAlbumForm form");
    if (addAlbumForm) {
      addAlbumForm.addEventListener('submit', function(e) {
        e.preventDefault();
        var categoryNameError = document.getElementById("categoryNameError");
        if (categoryNameError) categoryNameError.textContent = "";

        var parentSelect = document.querySelector("select[name=category_parent] option:checked");
        var categoryNameInput = document.querySelector("input[name=category_name]");
        var albumCreationLoading = document.getElementById("albumCreationLoading");

        if (albumCreationLoading) albumCreationLoading.style.display = '';

        var formData = new FormData();
        formData.append('parent', parentSelect ? parentSelect.value : '');
        formData.append('name', categoryNameInput ? categoryNameInput.value : '');

        fetch(rootUrl + "/ws.php?format=json&method=pwg.categories.add", {
          method: "POST",
          body: formData
        })
          .then(function(response) {
            return response.json();
          })
          .then(function(data) {
            if (albumCreationLoading) albumCreationLoading.style.display = 'none';

            var newAlbum = data.result.id;
            var addAlbumDlg = document.getElementById("addAlbumForm");
            if (addAlbumDlg) addAlbumDlg.close();

            var albumSelect = document.getElementById("albumSelect");
            if (albumSelect) {
              document.querySelectorAll("#albumSelect option").forEach(function(opt) {
                opt.remove();
              });
            }
            fillCategoryListbox("albumSelect", newAlbum);

            var albumSelection = document.querySelector(".albumSelection");
            if (albumSelection) albumSelection.style.display = '';

            var linkToCreate = document.getElementById("linkToCreate");
            if (linkToCreate) linkToCreate.style.display = 'none';

            return true;
          })
          .catch(function(error) {
            if (albumCreationLoading) albumCreationLoading.style.display = 'none';
            if (categoryNameError) {
              categoryNameError.textContent = error.message;
              categoryNameError.style.color = "red";
            }
          });

        return false;
      });
    }

    var hideErrorsBtn = document.getElementById("hideErrors");
    if (hideErrorsBtn) {
      hideErrorsBtn.addEventListener('click', function(e) {
        e.preventDefault();
        var formErrors = document.getElementById("formErrors");
        if (formErrors) formErrors.style.display = 'none';
        return false;
      });
    }

    var uploadWarningSummaryLinks = document.querySelectorAll("#uploadWarningsSummary a.showInfo");
    uploadWarningSummaryLinks.forEach(function(link) {
      link.addEventListener('click', function() {
        var summary = document.getElementById("uploadWarningsSummary");
        var warnings = document.getElementById("uploadWarnings");
        if (summary) summary.style.display = 'none';
        if (warnings) warnings.style.display = '';
      });
    });

    var showPhotoBtn = document.getElementById("showPhotoProperties");
    if (showPhotoBtn) {
      showPhotoBtn.addEventListener('click', function(e) {
        e.preventDefault();
        var parent = showPhotoBtn.closest(".showFieldset");
        if (parent) parent.style.display = 'none';
        var photoProps = document.getElementById("photoProperties");
        if (photoProps) photoProps.style.display = '';
        var propCheckbox = document.querySelector("input[name=set_photo_properties]");
        if (propCheckbox) propCheckbox.checked = true;
        return false;
      });
    }

    Piecon.setOptions({
      color: '#ff7700',
      background: '#bbb',
      shadow: '#fff',
      fallback: 'force'
    });


    var pwg_token = '{$pwg_token}';
    var photosUploaded_label = "{'%d photos uploaded into album "%s"'|translate|escape}";
    var moderation_Label = "{'Your photos are waiting for validation, administrators have been notified'|translate|escape}";
    var uploadedPhotos = [];
    var uploadCategory = null;

    var sizeLimit = Math.round({$upload_max_filesize} / 1024); /* in KBytes */
    var sumQueueFilesize = 0;
    {if isset($limit_storage)}
      var limit_storage = {$limit_storage};
    {/if}

    Dropzone.autoDiscover = false;

    var uploadStarted = false;
    var beforeUnloadHandler = null;
    var chunkSizeBytes = {$chunk_size} > 0 ? ({$chunk_size} * 1024) : (100 * 1024 * 1024);
    var acceptedExtensions = "{$file_exts}".split(',').map(function(ext) { return '.' + ext.trim(); }).join(',');

    dz = new Dropzone('#uploader', {
      url: rootUrl + 'ws.php?method=pwg.images.upload&format=json',
      clickable: '#addFiles',
      acceptedFiles: acceptedExtensions,
      maxFilesize: 1000,
      chunking: true,
      forceChunking: false,
      chunkSize: chunkSizeBytes,
      parallelChunkUploads: false,
      autoProcessQueue: false,
      dictDefaultMessage: "{'Drop files here or click Add Photos'|translate}",
      addRemoveLinks: true,
      dictRemoveFile: "✕",
    });

    dz.on("addedfile", function(file) {
      var startUploadBtn = document.getElementById('startUpload');
      if (startUploadBtn) startUploadBtn.disabled = (dz.files.length == 0);
    });

    dz.on("removedfile", function(file) {
      var startUploadBtn = document.getElementById('startUpload');
      if (startUploadBtn) startUploadBtn.disabled = (dz.files.length == 0);
    });

    dz.on("sending", function(file, xhr, formData) {
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
      var categorySelect = document.querySelector("select[name=category] option:checked");
      var levelOption = document.querySelector("select[name=level] option:checked");
      formData.append("category", categorySelect ? categorySelect.value : '');
      formData.append("level", levelOption ? levelOption.value : '');
    });

    dz.on("processing", function(file) {
      if (!uploadStarted) {
        uploadStarted = true;
        var startUploadBtn = document.getElementById('startUpload');
        var addFilesBtn = document.getElementById('addFiles');
        if (startUploadBtn) startUploadBtn.style.display = 'none';
        if (addFilesBtn) addFilesBtn.style.display = 'none';
        var uploadingActions = document.getElementById('uploadingActions');
        if (uploadingActions) uploadingActions.style.display = '';
        beforeUnloadHandler = function(e) {
          e.preventDefault();
          e.returnValue = "{'Upload in progress'|translate|escape}";
          return e.returnValue;
        };
        window.addEventListener('beforeunload', beforeUnloadHandler);
        var levelSelect = document.querySelector("select[name=level]");
        if (levelSelect) levelSelect.disabled = true;
      }
    });

    dz.on("totaluploadprogress", function(progress) {
      var progressBar = document.querySelector('#uploadingActions .progressbar');
      if (progressBar) progressBar.style.width = progress + '%';
      Piecon.setProgress(progress);
    });

    dz.on("success", function(file, response) {
      var data = typeof response === 'object' ? response : null;
      if (!data) { try { data = JSON.parse(response); } catch(e) { return; } }

      if (data && data.stat === 'fail') {
        var errorsList = document.querySelector(".errors ul");
        if (errorsList) errorsList.insertAdjacentHTML('beforeend', '<li>' + (data.message || 'Upload error') + '</li>');
        var errorsDiv = document.querySelector(".errors");
        if (errorsDiv) errorsDiv.style.display = '';
        return;
      }

      if (!data || !data.result) return;

      if (file.previewElement) file.previewElement.style.display = 'none';

      var uploadedPhotosContainer = document.getElementById("uploadedPhotos");
      if (uploadedPhotosContainer) {
        var fieldset = uploadedPhotosContainer.closest("fieldset");
        if (fieldset) fieldset.style.display = '';
        uploadedPhotosContainer.insertAdjacentHTML('afterbegin', '<img src="' + data.result.src + '" class="thumbnail" title="' + data.result.name + '">');
      }

      uploadedPhotos.push(parseInt(data.result.image_id));
      uploadCategory = data.result.category;

      var authorInput = document.querySelector("input[name=author]");
      var nameInput = document.querySelector("input[name=name]");
      var descriptionTextarea = document.querySelector("textarea[name=description]");

      var setInfoData = new FormData();
      setInfoData.append('single_value_mode', 'replace');
      setInfoData.append('image_id', data.result.image_id);
      setInfoData.append('author', authorInput ? authorInput.value : '');
      setInfoData.append('name', nameInput ? nameInput.value : '');
      setInfoData.append('comment', descriptionTextarea ? descriptionTextarea.value : '');
      fetch(rootUrl + "ws.php?format=json&method=pwg.images.setInfo", { method: "POST", body: setInfoData })
        .catch(function() {});
    });

    dz.on("error", function(file, message, xhr) {
      var errMsg = typeof message === 'string' ? message : (message && message.message) || 'Upload error';
      if (xhr && xhr.responseText) {
        try { var parsed = JSON.parse(xhr.responseText); if (parsed.message) errMsg = parsed.message; } catch(e) {}
      }
      var errorsList = document.querySelector(".errors ul");
      if (errorsList) errorsList.insertAdjacentHTML('beforeend', '<li>' + errMsg + '</li>');
      var errorsDiv = document.querySelector(".errors");
      if (errorsDiv) errorsDiv.style.display = '';
    });

    function handleUploadComplete() {
      Piecon.reset();

      document.querySelectorAll(".selectAlbum, .selectFiles, #photoProperties, .showFieldset").forEach(function(el) {
        el.style.display = 'none';
      });

      if (uploadCategory) {
        var infosDiv = document.querySelector(".infos");
        if (infosDiv) {
          infosDiv.insertAdjacentHTML('beforeend', '<ul><li>' + sprintf(photosUploaded_label, uploadedPhotos.length, uploadCategory.label) + '</li></ul>');
        }

        var uploadedFormData = new FormData();
        uploadedFormData.append('pwg_token', pwg_token);
        uploadedFormData.append('image_id', uploadedPhotos.join(","));
        uploadedFormData.append('category_id', uploadCategory.id);

        fetch(rootUrl + "ws.php?format=json&method=pwg.images.uploadCompleted", { method: "POST", body: uploadedFormData }).catch(function() {});

        fetch(rootUrl + "ws.php?format=json&method=community.images.uploadCompleted", {
          method: "POST",
          body: uploadedFormData
        })
          .then(function(r) { return r.json(); })
          .then(function(data) {
            if (data.result && data.result.pending && data.result.pending.length > 0) {
              var infosList = document.querySelector(".infos ul");
              if (infosList) infosList.insertAdjacentHTML('beforeend', '<li>' + moderation_Label + '</li>');
            }
          })
          .catch(function() {});

        if (infosDiv) infosDiv.style.display = '';
      }

      var afterUploadActions = document.querySelector(".afterUploadActions");
      if (afterUploadActions) afterUploadActions.style.display = '';
      var uploadingActions = document.getElementById('uploadingActions');
      if (uploadingActions) uploadingActions.style.display = 'none';

      if (beforeUnloadHandler) {
        window.removeEventListener('beforeunload', beforeUnloadHandler);
        beforeUnloadHandler = null;
      }
    }

    dz.on("queuecomplete", function() {
      if (uploadStarted) handleUploadComplete();
    });

    var startUploadBtn = document.getElementById('startUpload');
    if (startUploadBtn) {
      startUploadBtn.addEventListener('click', function(e) {
        e.preventDefault();
        if (!checkUploadStart()) return;
        dz.processQueue();
      });
    }

    var cancelUploadBtn = document.getElementById('cancelUpload');
    if (cancelUploadBtn) {
      cancelUploadBtn.addEventListener('click', function(e) {
        e.preventDefault();
        dz.removeAllFiles(true);
        handleUploadComplete();
      });
    }

    var buttons = document.querySelectorAll("input[type=button]");
    buttons.forEach(function(button) {
      button.addEventListener('click', function() {
        if (!checkUploadStart()) {
          return false;
        }
      });
    });
  });
</script>{/footer_script}

<style>
  /*
#photosAddContent form p {
  text-align:left;
}

#photosAddContent FIELDSET {
  width:650px;
  margin:20px auto;
}
*/

  #photosAddContent fieldset#photoProperties {
    padding-bottom: 0
  }

  #photosAddContent fieldset#photoProperties p {
    text-align: left;
    margin: 0 0 1em 0;
    line-height: 20px;
  }

  #photosAddContent fieldset#photoProperties input[type="text"] {
    width: 320px
  }

  #photosAddContent fieldset#photoProperties textarea {
    width: 500px;
    height: 100px
  }

  #photosAddContent P {
    margin: 0;
  }

  p#uploadWarningsSummary {
    text-align: left;
    margin-bottom: 1em;
    font-size: 90%;
    color: #999;
    line-height: 2.5em
  }

  p#uploadWarningsSummary .showInfo {
    position: static;
    display: inline;
    padding: 1px 6px;
    margin-left: 3px;
  }

  p#uploadWarnings {
    display: none;
    text-align: left;
    margin-bottom: 1em;
    font-size: 90%;
    color: #999;
  }

  p#uploadModeInfos {
    text-align: left;
    margin-top: 1em;
    font-size: 90%;
    color: #999;
  }

  #photosAddContent p.showFieldset {
    text-align: left;
    margin: 0 auto 10px auto;
  }

  #uploadProgress {
    width: 650px;
    margin: 10px auto;
    font-size: 90%;
  }

  #progressbar {
    border: 1px solid #ccc;
    background-color: #eee;
  }

  .ui-progressbar-value {
    background-image: url(admin/themes/default/images/pbar-ani.gif);
    height: 10px;
    margin: -1px;
    border: 1px solid #E78F08;
  }

  /* Upload Form */
  .plupload_header {
    display: none;
  }

  #uploadForm .plupload_container {
    padding: 0
  }

  #uploadForm .plupload_scroll .plupload_filelist {
    height: 250px;
  }

  #uploadForm li.plupload_droptext {
    line-height: 230px;
    font-size: 2em;
  }

  #uploadBoxes .file {
    margin-bottom: 5px;
    text-align: left;
  }

  #uploadBoxes {
    margin-top: 20px;
  }

  #addUploadBox {
    margin-bottom: 2em;
  }

  p.uploadInfo {
    text-align: left;
    font-size: 90%;
    color: #999;
  }

  p#uploadWarningsSummary {
    text-align: left;
    margin-bottom: 1em;
    font-size: 90%;
    color: #999;
  }

  p#uploadWarningsSummary .showInfo {
    margin-left: 3px;
  }

  p#uploadWarnings {
    display: none;
    text-align: left;
    margin-bottom: 1em;
    font-size: 90%;
    color: #999;
  }

  p#uploadModeInfos {
    text-align: left;
    margin-top: 1em;
    font-size: 90%;
    color: #999;
  }

  #photosAddContent p.showFieldset {
    text-align: left;
    margin: 1em;
  }

  #uploadForm .plupload_buttons,
  #uploadForm .plupload_progress {
    display: none !important;
  }

  #uploadForm #startUpload {
    margin: 5px 0 15px 15px;
    padding: 5px 10px;
    font-size: 1.1em;
  }

  #uploadForm #startUpload:before {
    margin-right: 0.5em;
  }

  #uploadForm #addFiles {
    margin-right: 10px;
    float: left;
  }

  #uploadForm #uploadingActions {
    margin: 10px 10px 10px 15px;
  }

  #uploadForm .big-progressbar {
    vertical-align: middle;
    display: inline-block;
    margin-left: 10px;
  }

  .big-progressbar {
    width: 100%;
    max-width: 600px;
    background: #fff;
    padding: 0;
    border-radius: 5px;
    position: relative;
    height: 18px;
  }

  @keyframes animatedBackground {
    from {
      background-position: 0 0;
    }

    to {
      background-position: 33px 0;
    }
  }

  @-webkit-keyframes animatedBackground {
    from {
      background-position: 0 0;
    }

    to {
      background-position: 33px 0;
    }
  }

  .big-progressbar .progressbar {
    height: 18px;
    min-width: 5px;
    background: #444;
    border-radius: 5px 0 0 5px;
    background-size: 33px 25px;
    animation: animatedBackground 1s linear infinite;
    -webkit-animation: animatedBackground 1s linear infinite;
  }
</style>

<div id="photosAddContent" class="col-lg-8 col-md-10 col-sm-12 col-centered">

  <div class="infos" style="display:none"><i class="eiw-icon icon-ok"></i></div>
  <div class="errors" style="display:none"><i class="eiw-icon icon-cancel"></i>
    <ul></ul>
  </div>

  <p class="afterUploadActions" style="margin:10px; display:none;"><a
      href="{$another_upload_link}">{'Add another set of photos'|translate}</a></p>

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


    {if !empty($thumbnails)}
      <fieldset>
        <legend>{'Uploaded Photos'|translate}</legend>
        <div>
          {foreach $thumbnails as $thumbnail}
            <a href="{$thumbnail.link}" class="{if isset($thumbnail.lightbox)}colorboxThumb{else}externalLink{/if}">
              <img src="{$thumbnail.src}" alt="{$thumbnail.file}" title="{$thumbnail.title}" class="thumbnail">
            </a>
          {/foreach}
        </div>
      </fieldset>
      <p style="margin:10px"><a href="{$another_upload_link}">{'Add another set of photos'|translate}</a></p>
    {else}

      <div id="formErrors" class="errors" style="display:none">
        <ul>
          <li id="noAlbum">{'Select an album'|translate}</li>
          <li id="noPhoto">{'Select at least one photo'|translate}</li>
        </ul>
        <div class="hideButton" style="text-align:center"><a href="#" id="hideErrors">{'Hide'|translate}</a></div>
      </div>

      <dialog id="addAlbumForm" style="text-align:left;padding:1em;">
        <form>
          {'Parent album'|translate}<br>
          <select id="category_parent" name="category_parent">
            {if $create_whole_gallery}
              <option value="0">------------</option>
            {/if}
            {html_options options=$category_parent_options selected=$category_parent_options_selected}
          </select>

          <br><br>{'Album name'|translate}<br><input name="category_name" type="text"> <span
            id="categoryNameError"></span>
          <br><br><br><input type="submit" value="{'Create'|translate}"> <span id="albumCreationLoading"
            style="display:none"><img src="themes/default/images/ajax-loader-small.gif"></span>
        </form>
      </dialog>

      <form id="uploadForm" enctype="multipart/form-data" method="post" action="{$form_action}" class="properties">
        {if $upload_mode eq 'multiple'}
          <input name="upload_id" value="{$upload_id}" type="hidden">
        {/if}

        <fieldset class="selectAlbum mb-3">
          <legend>{'Drop into album'|translate}</legend>

          <span class="albumSelection" {if count($category_options) == 0} style="display:none" {/if}>
            <select id="albumSelect" name="category" class="form-control">
              {html_options options=$category_options selected=$category_options_selected}
            </select>
          </span>
          {if $create_subcategories}
            <div id="linkToCreate">
              <span class="albumSelection">{'... or '|translate}</span>
              <a href="#" class="addAlbumOpen btn btn-sm btn-primary"
                title="{'create a new album'|translate}">{'create a new album'|translate}</a>
            </div>
          {/if}
        </fieldset>

        <fieldset class="selectFiles mb-3">
          <legend>{'Select files'|translate}</legend>
          <button id="addFiles" type="button" class="btn btn-sm btn-primary"><i class="fas fa-plus"></i> {'Add Photos'|translate}</button>

          <p id="uploadWarningsSummary">{$upload_max_filesize_shorthand}B. {$upload_file_types}.
            {if isset($max_upload_resolution)}{$max_upload_resolution}Mpx.{/if}
            {if isset($quota_summary)}{$quota_summary}{/if}
            <a class="showInfo" href="#" title="{'Learn more'|translate}"><i class="fas fa-info-circle"></i></a>
          </p>

          <p id="uploadWarnings">
            {'Maximum file size: %sB.'|translate|sprintf:$upload_max_filesize_shorthand}
            {'Allowed file types: %s.'|translate|sprintf:$upload_file_types}
            {if isset($max_upload_resolution)}
              {'Approximate maximum resolution: %dM pixels (that\'s %dx%d pixels).'|translate|sprintf:$max_upload_resolution:$max_upload_width:$max_upload_height}
            {/if}
            {$quota_details}
          </p>

          <div id="uploader"></div>
    </fieldset>

    <p class="showFieldset"><a id="showPhotoProperties" href="#">{'Set Photo Properties'|translate}</a></p>

    <fieldset id="photoProperties" class="mb-3" style="display:none">
      <legend>{'Photo Properties'|translate}</legend>

      <input type="checkbox" name="set_photo_properties" style="display:none">

      <p>
        {'Title'|translate}<br>
        <input type="text" class="large" name="name" value="">
      </p>

      <p>
        {'Author'|translate}<br>
        <input type="text" class="large" name="author" value="">
      </p>

      <p>
        {'Description'|translate}<br>
        <textarea name="description" id="description" class="description" style="margin:0"></textarea>
      </p>

    </fieldset>

    <div id="uploadingActions" style="display:none">
      <button id="cancelUpload" type="button" class="btn btn-primary"><i class="fas fa-ban"></i> {'Cancel'|translate}</button>

      <div class="big-progressbar">
        <div class="progressbar" style="width:0%"></div>
      </div>
    </div>

    <button id="startUpload" type="button" class="btn btn-primary" disabled><i class="fas fa-upload"></i>
      {'Start Upload'|translate}</button>

  </form>

  <fieldset class="mb-3" style="display:none">
    <legend>{'Uploaded Photos'|translate}</legend>
        <div id="uploadedPhotos"></div>
      </fieldset>

    {/if} {* empty($thumbnails) *}
  {/if} {* $setup_errors *}

</div> <!-- photosAddContent -->

{* Community specific *}
{footer_script}<script>
  document.addEventListener('DOMContentLoaded', function() {
    GLightbox({ selector: 'a.colorboxThumb' });

    var externalLinks = document.querySelectorAll("a.externalLink");
    externalLinks.forEach(function(link) {
      link.addEventListener('click', function(e) {
        e.preventDefault();
        window.open(this.getAttribute("href"));
        return false;
      });
    });
  });
</script>{/footer_script}