/*--------------
Variables
--------------*/
const btnFirstAlbum = $('#btnFirstAlbum');
const modalFirstAlbum = $('#addFirstAlbum');
const closeModalFirstAlbum = $('#closeFirstAlbum');
const inputFirstAlbum = $('#inputFirstAlbum');
const btnAddFirstAlbum = $('#btnAddFirstAlbum');
const firstAlbum = $('.addAlbumEmptyCenter');
const uploadForm = $('#uploadForm');
const addPhotosAS = $('#addPhotosAS');
const btnPhotosAS = $('#btnPhotosAS');
const selectedAlbum = $('#selectedAlbum');
const selectedAlbumName = $('#selectedAlbumName');
const selectedAlbumEdit = $('#selectedAlbumEdit');
const btnAddFiles = $('#addFiles');
const chooseAlbumFirst = $('#chooseAlbumFirst');
const uploaderPhotos = $('#uploader');
const formatsUpdated = [];
const formats = [];

/*--------------
On DOM load
--------------*/
$(function () {
  // First album event
  if (!nb_albums) {
    btnFirstAlbum.on('click', function () {
      open_new_album_modal();
    });

    closeModalFirstAlbum.on('click', function () {
      close_new_album_modal();
    });

    btnAddFirstAlbum.on('click', function () {
      add_first_album(ab.select_album.bind(ab));
    });

    inputFirstAlbum.on('keyup', function(e) {
      if (e.key === 'Enter') {
        btnAddFirstAlbum.trigger('click');
      }
    });
  }

  const ab = new AlbumSelector({
    selectedCategoriesIds: related_categories_ids,
    selectAlbum: add_related_category,
    adminMode: true,
    modalTitle: str_drop_album_ab,
  });

  // Open album selector event
  btnPhotosAS.on('click', function () {
    ab.open();
  });
  selectedAlbumEdit.on('click', function () {
    ab.open();
  });

  // Upload logics
  $(".dont-show-again").on("click", function () {
    $.ajax({
      url: "api/v1/session/preferences/promote-mobile-apps",
      type: "PUT",
      contentType: "application/json",
      dataType: "JSON",
      data: JSON.stringify({
        value: "false",
      }),
      success: function (res) {
        jQuery(".promote-apps").hide();
      }
    })
  });

  $("#uploadWarningsSummary a.showInfo").on('click', function () {
    $("#uploadWarningsSummary").hide();
    $("#uploadWarnings").show();
    return false;
  });

  $("#showPermissions").on('click', function () {
    $(this).parent(".showFieldset").hide();
    $("#permissions").show();
    return false;
  });

  $("#uploadOptionsContent").hide();
  $("#uploadOptions").on("click", function(){
    $("#uploadOptionsContent").slideToggle();
    $("#uploadOptions").toggleClass('options-open');
    $(".moxie-shim-html5").css("display", "none");
  })

  $("#uploader").pluploadQueue({
    // General settings
    browse_button: 'addFiles',
    container: 'uploadForm',

    // runtimes : 'html5,flash,silverlight,html4',
    runtimes: 'html5',

    // Plupload owns file selection/drag-drop/queue UI only -- this `url`
    // is never actually requested. The real transport is a tus.Upload
    // per file (see startTusUploads()/uploadNextTusFile() below), driven
    // through this same up.trigger() event pipeline so every handler in
    // `init` below keeps working exactly as if plupload's own uploader
    // had run.
    url: 'api/v1/uploads',

    chunk_size,

    filters: {
      // Maximum file size
      max_file_size,
      // Specify what files to browse for
      mime_types: [
        { title: "Image files", extensions: formatMode ? format_ext : file_ext }
      ]
    },

    // Rename files by clicking on their titles
    rename: formatMode,

    // Enable ability to drag'n'drop files onto the widget (currently only HTML5 supports that)
    dragdrop: true,

    preinit: {
      Init: function (up, info) {
        $('#uploader_container').removeAttr("title"); //remove the "using runtime" text

        $('#startUpload').on('click', function (e) {
          e.preventDefault();
          startTusUploads(up);
        });

        $('#cancelUpload').on('click', function (e) {
          e.preventDefault();
          cancelTusUploads();
          up.trigger('UploadComplete', up.files);
        });
      }
    },

    init: {
      // update custom button state on queue change
      QueueChanged: function (up) {
        $('#addFiles').addClass("addFilesButtonChanged");
        $('#startUpload').prop('disabled', up.files.length == 0);
        $("#addFiles").removeClass('buttonLike').addClass('buttonLike');

        if (up.files.length > 0) {
          $('.plupload_filelist_footer').show();
          $('.plupload_filelist').css("overflow-y", "scroll");
        }

        if (up.files.length == 0) {
          $('#addFiles').removeClass("addFilesButtonChanged");
          $("#addFiles").removeClass('buttonLike').addClass('buttonLike');
          $('.plupload_filelist_footer').hide();
          $('.plupload_filelist').css("overflow-y", "hidden");
        }
      },

      FilesAdded: async function (up, files) {
        // Création de la liste avec plupload_id : image_name
        fileNames = {};
        exts = {};
        files.forEach((file) => {
          fileNames[file.id] = file.name;
          exts[file.id] = file.name.substr(file.name.lastIndexOf('.') + 1);
        });

        if (formatMode) {
          formats.forEach((forms) => {
            $("#"+forms[0]+" > .plupload_file_name").append(`
            <a target=\"_blank\" href=\"admin.php?page=photo-${forms[1].trim()}-properties\">
              <span class=\"icon-eye\">
              </span>
            </a>`);
            if(formatsUpdated.includes(forms[0])){
              $("#"+forms[0]+" > .plupload_file_name").after(`
              <a target=\"_blank\" href=\"admin.php?page=photo-${forms[1].trim()}-formats\">
                <span class=\"icon-attention update-warning\">
                  ${format_update_warning}
                </span>
              </a>
              <a class="remove-format" id=\"remove_${forms[0]}\">
                <span class = \"icon-cancel-circled\">
                </span>
                ${format_remove}
              </a>`);
              $("#remove_"+forms[0]).on("click", function(){
                up.removeFile(forms[0]);
              });
            }
          });
          
          // If no original image is specified
          if (!haveFormatsOriginal) {
            const images_search = await new Promise((res, rej) => {
              //ajax qui renvois les id des images dans la gallerie.
              $.ajax({
                url: "api/v1/images/formats/actions/search",
                type: "POST",
                contentType: "application/json",
                data: JSON.stringify({
                  filenames: fileNames,
                }),
                success: function (data) {
                  res(data.results)
                }
              })
            })

            const notFound = [];
            const multiple = [];

            files.forEach((f) => {
              const search = images_search[f.id];
              if (search.status == "found"){
                f.format_of = String(search.imageId);
                formats.push([f.id,f.format_of]);
                $("#"+f.id+" > .plupload_file_name").append(`
                <a target=\"_blank\" href=\"admin.php?page=photo-${f.format_of.trim()}-properties\">
                  <span class=\"icon-eye\">
                  </span>
                </a>`);
                if (search.formatExists)
                {
                  $("#"+f.id+" > .plupload_file_name").after(`
                  <a target=\"_blank\" href=\"admin.php?page=photo-${f.format_of.trim()}-formats\">
                    <span class=\"icon-attention update-warning\">
                      ${format_update_warning}
                    </span>
                  </a>
                  <a class="remove-format" id=\"remove_${f.id}\">
                    <span class = \"icon-cancel-circled\">
                    </span>
                    ${format_remove}
                  </a>`);
                  formatsUpdated.push(f.id);
                  $("#remove_"+f.id).on("click", function(){
                    up.removeFile(f.id);
                  });
                }
              }
              else {
                if (search.status == "multiple")
                  multiple.push(f.name);
                else
                  notFound.push(f.name);
                up.removeFile(f.id);
              }
            })

            files.filter(f => images_search[f.id].status === "found");

            // If a file is not found or found more than one time
            if (notFound.length || multiple.length) {
              const [multStr, notFoundStr] = [multiple, notFound].map((tab) => {
                //Get names
                tab = tab.map(f => f.slice(0, f.indexOf('.')))
                // Remove duplicates
                tab = tab.filter((f, i) => i === tab.indexOf(f))

                // Add "and X more" if necessary
                if (tab.length > 5) {
                  tab[5] = str_and_X_others.replace('%d', tab.length - 5);
                  tab = tab.splice(0, 6);
                }
                return tab;
              })

              $.alert({
                title: str_format_warning,
                content: (notFound.length ? `<p>${str_format_warning_notFound.replace('%s', notFoundStr.join(', '))}</p>` : "")
                  + (multiple.length ? `<p>${str_format_warning_multiple.replace('%s', multStr.join(', '))}</p>` : ""),
                ...jConfirm_warning_options
              })
            }
          } else {
            if (imageFormatsExtensions)
            {
              $forms_exts = JSON.parse(imageFormatsExtensions);
            }
            else
            {
              $forms_exts = [];
            }
            files.forEach((f) => {
              f.format_of = originalImageId;
              formats.push([f.id,f.format_of]);
              $("#"+f.id+" > .plupload_file_name").append(`
              <a target=\"_blank\" href=\"admin.php?page=photo-${f.format_of.trim()}-properties\">
                <span class=\"icon-eye\">
                </span>
              </a>`);
              if ($forms_exts.indexOf(exts[f.id]) != -1)
              {
                $("#"+f.id+" > .plupload_file_name").after(`
                <a target=\"_blank\" href=\"admin.php?page=photo-${originalImageId.trim()}-formats\">
                  <span class=\"icon-attention update-warning\">
                    ${format_update_warning}
                  </span>
                </a>
                <a class="remove-format" id=\"remove_${f.id}\">
                  <span class = \"icon-cancel-circled\">
                  </span>
                  ${format_remove}
                </a>`);
                formatsUpdated.push(f.id);
                $("#remove_"+f.id).on("click", function(){
                  up.removeFile(f.id);
                });
              }
            })
          }
        }
      },

      FilesRemoved: function(up, file){ 
        formats.forEach((forms) => {
          $("#"+forms[0]+" > .plupload_file_name").append(`
          <a target=\"_blank\" href=\"admin.php?page=photo-${forms[1].trim()}-properties\">
            <span class=\"icon-eye\">
            </span>
          </a>`);
          if(formatsUpdated.includes(forms[0])){
            $("#"+forms[0]+" > .plupload_file_name").after(`
            <a target=\"_blank\" href=\"admin.php?page=photo-${forms[1].trim()}-formats\">
              <span class=\"icon-attention update-warning\">
                ${format_update_warning}
              </span>
            </a>
            <a class="remove-format" id=\"remove_${forms[0]}\">
              <span class = \"icon-cancel-circled\">
              </span>
              ${format_remove}
            </a>`);
            $("#remove_"+forms[0]).on("click", function(){
              up.removeFile(forms[0]);
            });
          }
        });
      },

      UploadProgress: function (up, file) {
        $('#uploadingActions .progressbar').width(up.total.percent + '%');
        Piecon.setProgress(up.total.percent);
      },

      BeforeUpload: function (up, file) {
        // hide buttons
        $('#startUpload, .selectFilesButtonBlock').hide();
        $('#uploadingActions').show();
        $('.format-mode-group-manager').hide();
        $('#selectedAlbumEdit').hide();
        // if (!formatMode) {
        //   var categorySelectedId = $("select[name=category] option:selected").val();
        //   var categorySelectedPath = $("select[name=category]")[0].selectize.getItem(categorySelectedId).text();
        //   $('.selectedAlbum').show().find('span').html(categorySelectedPath);
        // }

        // warn user if she wants to leave page while upload is running
        $(window).bind('beforeunload', function () {
          return str_upload_in_progress;
        });

        // no more change on category/level
        $("select[name=level]").attr("disabled", "disabled");

        // You can override settings before the file is uploaded
        var options = {
          pwg_token: pwg_token
        };

        if (formatMode) {
          options.format_of = file.format_of;
        } else {
          // options.category = $("select[name=category] option:selected").val();
          options.category = ab.get_selected_albums()[0];
          // options.level = $("select[name=level] option:selected").val();
          options.name = file.name;
        }

        options.update_mode = $('#toggleUpdateMode').is(':checked');

        up.setOption('multipart_params', options);
      },

      FileUploaded: function (up, file, info) {
        // Called when file has finished uploading. Unlike a plain plupload
        // setup, `info` here is a plain object built in uploadNextTusFile()
        // below: imageId/addStatus from the tus completion response,
        // squareSrc/name from a follow-up GET /api/v1/images/{id}.

        // hide item line
        $('#' + file.id).hide();

        $("#uploadedPhotos").parent("fieldset").show();

        html = '<a href="admin.php?page=photo-' + info.imageId + '" style="position : relative" target="_blank">';
        html += '<img src="' + info.squareSrc + '" class="thumbnail" title="' + info.name + '">';
        if (formatMode) html += '<div class="format-ext-name" title="' + file.name + '"><span>' + file.name.slice(file.name.indexOf('.')) + '</span></div>';
        html += '</a> ';

        $("#uploadedPhotos").prepend(html);

        // do not remove file, or it will reset the progress bar :-/
        // up.removeFile(file);
        uploadedPhotos.push(info.imageId);
        if(info.addStatus=="add"){
          addedPhotos.push(info.imageId);
        }
        else{
          updatedPhotos.push(info.imageId);
        }
      },

      Error: function (up, error) {
        // Called when file has finished uploading. `error` is a plain
        // {message, file} object built in uploadNextTusFile() below, from
        // a real HTTP status returned by the tus endpoint.
        $(".errors ul").append('<li>' + error.message + '</li>');
        $(".errors").show();
      },

      UploadComplete: function (up, files) {
        // Called when all files are either uploaded or failed
        //console.log('[UploadComplete]');

        Piecon.reset();

        if (!formatMode && uploadCategory) {
          $.ajax({
            url: "api/v1/uploads/actions/complete-batch",
            type: "POST",
            contentType: "application/json",
            headers: {'X-CSRF-Token': pwg_token},
            data: JSON.stringify({
              categoryId: Number(uploadCategory.id),
            }),
            dataType: "json",
            success: function (data) {
              // A real, fresh nb_photos/label straight from the server --
              // read here instead of a value captured mid-upload, since
              // that captured value would otherwise be stale by the time
              // this batch-complete summary line renders.
              const summaryHtml = sprintf(
                albumSummary_label,
                '<a href="admin.php?page=album-' + data.category.id + '">' + data.category.label + '</a>',
                data.category.nbPhotos
              );
              $(".infos ul").append('<li>' + summaryHtml + '</li>');
            }
          });
        }

        $("#uploadForm, #permissions, .showFieldset").hide();

        const infoTextAdd = formatMode ?
          sprintf(formatsAdded_label, addedPhotos.length, [...new Set(addedPhotos)].length)
          : sprintf(photosAdded_label, addedPhotos.length);

        const infoTextUpdate = formatMode ?
          sprintf(formatsUpdated_label, updatedPhotos.length, [...new Set(updatedPhotos)].length)
          : sprintf(photosUpdated_label, updatedPhotos.length);

        if (addedPhotos.length && updatedPhotos.length)
        {
          $(".infos").append( '<ul><li>' + infoTextAdd + ', ' + infoTextUpdate + '</li></ul>');
        }
        else
        {
          const infoText = addedPhotos.length ? infoTextAdd : infoTextUpdate;
          $(".infos").append('<ul><li>' + infoText + '</li></ul>');
        }

        $(".infos").show();

        // TODO: use a new method pwg.caddie.empty +
        // pwg.caddie.add(uploadedPhotos) instead of relying on huge GET parameter
        // (and remove useless code from admin/photos_add_direct.php)

        $(".batchLink").attr("href", "admin.php?page=photos_add&section=direct&batch=" + [...new Set(uploadedPhotos)].join(",") + "&pwg_token=" + pwg_token);
        $(".batchLink").html(sprintf(batch_Label, uploadedPhotos.length));

        $(".afterUploadActions").show();
        $('#uploadingActions').hide();
        $('#selectedAlbumEdit').show();

        // user can safely leave page without warning
        $(window).unbind('beforeunload');
      }
    }
  });
});

/*--------------
tus upload transport

Plupload's own queue widget provides file selection/drag-drop/rename/
progress-bar UI only -- pwg.images.upload (and the whole base64/
multipart chunk-upload protocol behind it) was replaced by a real tus
1.0.0 server earlier in this campaign (/api/v1/uploads). Plupload itself
has never supported tus at any version, so the real byte transfer here
goes through tus-js-client (vendored, themes/default/js/plugins/
tus-js-client/) instead of plupload's own uploader, one file at a time
(matching plupload's own default non-parallel behavior). Every step is
still announced through plupload's own up.trigger() so every handler in
the `init` block above keeps working exactly as if plupload's native
uploader had run -- BeforeUpload still builds `multipart_params` via the
album selector, UploadProgress still reads up.total.percent, FileUploaded/
Error/UploadComplete still update the same DOM.
--------------*/

let activeTusUpload = null;

function computeAggregatePercent(files) {
  let totalLoaded = 0;
  let totalSize = 0;
  files.forEach(function (f) {
    totalSize += f.size || 0;
    totalLoaded += (f.status === plupload.DONE) ? (f.size || 0) : (f.loaded || 0);
  });
  return totalSize ? Math.round(totalLoaded / totalSize * 100) : 0;
}

function extractTusErrorDetail(err) {
  if (err && err.originalResponse) {
    try {
      const body = JSON.parse(err.originalResponse.getBody());
      if (body && body.detail) {
        return body.detail;
      }
    } catch (e) {
      // Not a problem+json body (e.g. a network-level failure) -- fall
      // through to the generic message below.
    }
  }
  return (err && err.message) ? err.message : 'Upload failed';
}

function startTusUploads(up) {
  const pendingFiles = up.files.filter(function (f) {
    return f.status !== plupload.DONE;
  });

  if (pendingFiles.length === 0) {
    up.trigger('UploadComplete', up.files);
    return;
  }

  uploadNextTusFile(up, pendingFiles, 0);
}

function cancelTusUploads() {
  if (activeTusUpload) {
    activeTusUpload.abort();
    activeTusUpload = null;
  }
}

function uploadNextTusFile(up, files, index) {
  if (index >= files.length) {
    activeTusUpload = null;
    up.trigger('UploadComplete', up.files);
    return;
  }

  const file = files[index];
  file.status = plupload.UPLOADING;

  // Reuses BeforeUpload's own multipart_params-building logic verbatim
  // (album selector read, format_of/update_mode) -- only the destination
  // (tus metadata instead of plupload's native multipart form fields)
  // differs from here on.
  up.trigger('BeforeUpload', file);
  const options = up.getOption('multipart_params') || {};

  const metadata = { filename: file.name };
  if (formatMode) {
    metadata.formatOf = String(options.format_of);
  } else {
    metadata.category = String(options.category);
    metadata.name = options.name;
    if (!uploadCategory) {
      uploadCategory = { id: options.category };
    }
  }
  if (options.update_mode) {
    metadata.updateMode = '1';
  }

  activeTusUpload = new tus.Upload(file.getNative(), {
    endpoint: 'api/v1/uploads',
    chunkSize: parseInt(chunk_size) * 1024,
    retryDelays: [0, 1000, 3000, 5000],
    headers: {'X-CSRF-Token': pwg_token},
    metadata: metadata,
    onProgress: function (bytesUploaded, bytesTotal) {
      file.loaded = bytesUploaded;
      file.size = bytesTotal;
      file.percent = bytesTotal ? Math.round(bytesUploaded / bytesTotal * 100) : 0;
      up.total.percent = computeAggregatePercent(up.files);
      up.trigger('UploadProgress', file);
    },
    onError: function (error) {
      file.status = plupload.FAILED;
      up.trigger('Error', { message: extractTusErrorDetail(error), file: file });
      uploadNextTusFile(up, files, index + 1);
    },
    onSuccess: async function (payload) {
      file.status = plupload.DONE;
      file.percent = 100;

      let result = {};
      try {
        result = JSON.parse(payload.lastResponse.getBody());
      } catch (e) {
        // Falls through with result = {}; the !result.imageId check
        // below reports it.
      }

      if (!result.imageId) {
        up.trigger('Error', { message: 'Upload finished but the server response was unreadable.', file: file });
        uploadNextTusFile(up, files, index + 1);
        return;
      }

      let imageInfo = {};
      try {
        imageInfo = await $.ajax({ url: 'api/v1/images/' + result.imageId, type: 'GET', dataType: 'json' });
      } catch (e) {
        // Enrichment fetch failed -- the photo itself was uploaded
        // successfully, so still report it as such, just with a
        // fallback thumbnail/name.
      }

      up.trigger('FileUploaded', file, {
        imageId: result.imageId,
        addStatus: result.addStatus,
        squareSrc: (imageInfo.derivatives && imageInfo.derivatives.square) ? imageInfo.derivatives.square.url : '',
        name: imageInfo.name || file.name
      });
      uploadNextTusFile(up, files, index + 1);
    }
  });

  activeTusUpload.start();
}

/*--------------
General functions
--------------*/

function add_related_category({ album, newSelectedAlbum }) {
  let text = '';
  $(album.full_name_with_admin_links).each(function (i, s) {
    if ($(s).html()) { text += $(s).html() }
  });
  newSelectedAlbum();

  selectedAlbumName.hide();
  selectedAlbumName.html(text);
  selectedAlbumName.fadeIn();

  addPhotosAS.hide();
  selectedAlbum.fadeIn();

  enable_uploader();
}

function enable_uploader() {
  btnAddFiles.removeAttr('disabled');
  chooseAlbumFirst.hide();
  uploaderPhotos.show();
}

/*-------------------
First album functions
-------------------*/

function open_new_album_modal() {
  inputFirstAlbum.val('');
  modalFirstAlbum.fadeIn();
  inputFirstAlbum.trigger('focus');
}

function close_new_album_modal() {
  modalFirstAlbum.fadeOut();
}

function hide_first_album(cat_name) {
  modalFirstAlbum.hide();
  firstAlbum.hide();

  addPhotosAS.hide();
  selectedAlbumName.html(cat_name);
  selectedAlbum.show();

  enable_uploader();
  uploadForm.fadeIn();
}

function add_first_album(add_cat) {
  const params = {
    name: inputFirstAlbum.val().toString(),
  }

  $.ajax({
    url: 'api/v1/categories',
    method: 'POST',
    contentType: 'application/json',
    headers: {'X-CSRF-Token': pwg_token},
    dataType: 'json',
    data: JSON.stringify(params),
    success: function (res) {
      add_cat(res.id);
      hide_first_album(params.name);
    },
    error: function() {
      console.error('An error has occurred');
    }
  });
}