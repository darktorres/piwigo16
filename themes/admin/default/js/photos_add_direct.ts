import {
  Upload,
  type Upload as TusUpload,
  type DetailedError as TusDetailedError,
  type HttpResponse as TusHttpResponse,
} from "tus-js-client";
import type { operations, components } from "../../../../openapi/client/schema";
// Real consumer of album_selector.ts's own top-level `class
// AlbumSelector` (docs/PLAN.md P48 -- was a bare ambient-global read,
// see that file's own leading comment for the full real-consumer list).
import { AlbumSelector } from "./album_selector";
import { sprintf, jConfirm_warning_options } from "./common";

import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../default/js/page-data";
import { ajax } from "../../../default/js/vendor/ajax";
import { alert } from "../../../default/js/vendor/jconfirm";
import * as Piecon from "../../../default/js/vendor/piecon";
import {
  addClass,
  append,
  attr,
  css,
  escapeId,
  fadeIn,
  fadeOut,
  hide,
  html,
  off,
  on,
  prepend,
  ready,
  removeAttr,
  removeClass,
  setVal,
  show,
  slideToggle,
  trigger,
  val,
} from "../../../default/js/vendor/dom";
export {};

// `@types/plupload`'s own File shape is an untyped `TODO` (its own
// comment: "Make plupload.File typing" -- `Uploader.files: any[]`)
// -- these are the real properties/methods this file actually uses,
// including `format_of`, an app-specific field this file itself
// attaches (never part of plupload's own File shape).
interface PluploadFile {
  id: string;
  name: string;
  status: number;
  loaded?: number;
  size?: number;
  percent?: number;
  format_of?: string;
  getNative(): File;
}

type ImageFormatSearchResponse =
  operations["imageFormatSearch"]["responses"][200]["content"]["application/json"];

// The real payload FileUploaded/Error are triggered with here -- both
// hand-built in uploadNextTusFile() below, not plupload's own native
// event payloads (this app's tus-js-client transport stands in for
// plupload's real uploader entirely, see the comment further down).
interface TusUploadInfo {
  imageId: number | string;
  addStatus: string;
  squareSrc: string;
  name: string;
}

interface TusErrorInfo {
  message: string;
  file: PluploadFile;
}

interface TusOnSuccessPayload {
  lastResponse: TusHttpResponse;
}

interface MultipartParams {
  pwg_token: string;
  format_of?: string;
  category?: string | number;
  name?: string;
  update_mode?: boolean;
}

// `add_related_category` is declared here too, independently of the
// same-named functions in mcs.js/cat_modify.ts/batchManagerUnit.ts/
// picture_modify.ts (docs/PLAN.md P46-B's own finding) -- safe since
// these pages never co-load.
//
// tus-js-client is imported from its own npm package and bundled, not
// a CDN-supplied global. Piecon is a real native port now
// (`vendor/piecon.ts`, P49-C) -- no npm package at all any more.
// plupload still is a CDN-supplied global (typed by `@types/plupload`),
// and remains a jQuery-family CDN script pending its own removal batch.

/*--------------
Variables
--------------*/
const btnFirstAlbum = document.getElementById("btnFirstAlbum");
const modalFirstAlbum = document.getElementById("addFirstAlbum");
const closeModalFirstAlbum = document.getElementById("closeFirstAlbum");
const inputFirstAlbum = document.getElementById(
  "inputFirstAlbum",
) as HTMLInputElement | null;
const btnAddFirstAlbum = document.getElementById("btnAddFirstAlbum");
const firstAlbum = document.querySelectorAll(".addAlbumEmptyCenter");
const uploadForm = document.getElementById("uploadForm");
const addPhotosAS = document.getElementById("addPhotosAS");
const btnPhotosAS = document.getElementById("btnPhotosAS");
const selectedAlbum = document.getElementById("selectedAlbum");
const selectedAlbumName = document.getElementById("selectedAlbumName");
const selectedAlbumEdit = document.getElementById("selectedAlbumEdit");
const btnAddFiles = document.getElementById("addFiles");
const chooseAlbumFirst = document.getElementById("chooseAlbumFirst");
const uploaderPhotos = document.getElementById("uploader");
const formatsUpdated: string[] = [];
const formats: [string, string][] = [];

/*--------------
On DOM load
--------------*/
ready(function () {
  // Moved out of photos_add_direct.latte's own inline onClick, which both
  // navigated and set a loading class. The URL it interpolated now rides on
  // the label as data-switch-format-mode-url, since a real listener cannot
  // read a template variable.
  on(
    document.querySelectorAll(".format-mode-group-manager .switch"),
    "click",
    function (event: Event) {
      const url = (event.currentTarget as HTMLElement).dataset[
        "switchFormatModeUrl"
      ];
      if (url === undefined || url === "") {
        return;
      }
      addClass(document.querySelectorAll(".switch .slider"), "loading");
      window.location.replace(url);
    },
  );

  // First album event
  // Genuine pre-existing bug found via strict typing: PhotosAddDirectView.php's
  // own exposedPageData() exposes `nb_albums` as a *string* (`(string)
  // $this->nbAlbums`), so a brand-new gallery's real "0" value was
  // truthy (`!"0"` is `false`, unlike `!0`) -- the "add your first
  // album" onboarding flow this check exists for could never actually
  // trigger. Fixed to a real numeric comparison.
  if (Number(nb_albums) === 0) {
    if (btnFirstAlbum !== null) {
      on(btnFirstAlbum, "click", function () {
        open_new_album_modal();
      });
    }

    if (closeModalFirstAlbum !== null) {
      on(closeModalFirstAlbum, "click", function () {
        close_new_album_modal();
      });
    }

    if (btnAddFirstAlbum !== null) {
      on(btnAddFirstAlbum, "click", function () {
        add_first_album(ab.select_album.bind(ab));
      });
    }

    if (inputFirstAlbum !== null) {
      on(inputFirstAlbum, "keyup", function (e: Event) {
        if ((e as KeyboardEvent).key === "Enter" && btnAddFirstAlbum !== null) {
          trigger([btnAddFirstAlbum], "click");
        }
      });
    }
  }

  const ab = new AlbumSelector({
    selectedCategoriesIds: related_categories_ids,
    selectAlbum: add_related_category,
    adminMode: true,
    modalTitle: str_drop_album_ab,
  });

  // Open album selector event
  if (btnPhotosAS !== null) {
    on(btnPhotosAS, "click", function () {
      ab.open();
    });
  }
  if (selectedAlbumEdit !== null) {
    on(selectedAlbumEdit, "click", function () {
      ab.open();
    });
  }

  // Upload logics
  on(document.querySelectorAll(".dont-show-again"), "click", function () {
    void ajax({
      url: "api/v1/session/preferences/promote-mobile-apps",
      type: "PUT",
      contentType: "application/json",
      dataType: "JSON",
      data: JSON.stringify({
        value: "false",
      }),
      success: function (
        _res: operations["sessionPreferenceSet"]["responses"][200]["content"]["application/json"],
      ) {
        hide(document.querySelectorAll(".promote-apps"));
      },
    });
  });

  on(
    document.querySelectorAll("#uploadWarningsSummary a.showInfo"),
    "click",
    function () {
      hide(document.querySelectorAll("#uploadWarningsSummary"));
      show(document.querySelectorAll("#uploadWarnings"));
      return false;
    },
  );

  on(
    document.querySelectorAll("#showPermissions"),
    "click",
    function (event: Event) {
      const parent = (event.currentTarget as Element).parentElement;
      if (parent !== null && parent.matches(".showFieldset")) {
        hide(parent);
      }
      show(document.querySelectorAll("#permissions"));
      return false;
    },
  );

  hide(document.querySelectorAll("#uploadOptionsContent"));
  on(document.querySelectorAll("#uploadOptions"), "click", function () {
    slideToggle(document.querySelectorAll("#uploadOptionsContent"));
    document.querySelectorAll("#uploadOptions").forEach((el) => {
      el.classList.toggle("options-open");
    });
    css(document.querySelectorAll(".moxie-shim-html5"), "display", "none");
  });

  // Still jQuery: pluploadQueue is a library, ported as its own live
  // subset in P49-B group 7 -- only the DOM work inside its own
  // preinit/init callbacks (our own template elements, not plupload's
  // internal state) converted.
  $("#uploader").pluploadQueue({
    // General settings
    browse_button: "addFiles",
    container: "uploadForm",

    // runtimes : 'html5,flash,silverlight,html4',
    runtimes: "html5",

    // Plupload owns file selection/drag-drop/queue UI only -- this `url`
    // is never actually requested. The real transport is a tus.Upload
    // per file (see startTusUploads()/uploadNextTusFile() below), driven
    // through this same up.trigger() event pipeline so every handler in
    // `init` below keeps working exactly as if plupload's own uploader
    // had run.
    url: "api/v1/uploads",

    chunk_size,

    filters: {
      // Maximum file size
      max_file_size,
      // Specify what files to browse for
      mime_types: [
        {
          title: "Image files",
          extensions: formatMode ? format_ext : file_ext,
        },
      ],
    },

    // Rename files by clicking on their titles
    rename: formatMode,

    // Enable ability to drag'n'drop files onto the widget (currently only HTML5 supports that)
    dragdrop: true,

    preinit: {
      Init: function (up: plupload.Uploader, _info: unknown) {
        const uploaderContainer = document.getElementById("uploader_container");
        if (uploaderContainer !== null) {
          removeAttr(uploaderContainer, "title"); //remove the "using runtime" text
        }

        on(
          document.querySelectorAll("#startUpload"),
          "click",
          function (e: Event) {
            e.preventDefault();
            startTusUploads(up);
          },
        );

        on(
          document.querySelectorAll("#cancelUpload"),
          "click",
          function (e: Event) {
            e.preventDefault();
            cancelTusUploads();
            up.trigger("UploadComplete", up.files);
          },
        );
      },
    },

    init: {
      // update custom button state on queue change
      QueueChanged: function (up: plupload.Uploader) {
        if (btnAddFiles !== null) {
          addClass(btnAddFiles, "addFilesButtonChanged");
        }
        const startUpload = document.getElementById(
          "startUpload",
        ) as HTMLButtonElement | null;
        if (startUpload !== null) {
          startUpload.disabled = up.files.length == 0;
        }
        if (btnAddFiles !== null) {
          removeClass(btnAddFiles, "buttonLike");
          addClass(btnAddFiles, "buttonLike");
        }

        if (up.files.length > 0) {
          show(document.querySelectorAll(".plupload_filelist_footer"));
          css(
            document.querySelectorAll(".plupload_filelist"),
            "overflow-y",
            "scroll",
          );
        }

        if (up.files.length == 0) {
          if (btnAddFiles !== null) {
            removeClass(btnAddFiles, "addFilesButtonChanged");
            removeClass(btnAddFiles, "buttonLike");
            addClass(btnAddFiles, "buttonLike");
          }
          hide(document.querySelectorAll(".plupload_filelist_footer"));
          css(
            document.querySelectorAll(".plupload_filelist"),
            "overflow-y",
            "hidden",
          );
        }
      },

      FilesAdded: async function (
        up: plupload.Uploader,
        files: PluploadFile[],
      ) {
        // Création de la liste avec plupload_id : image_name
        const fileNames: Record<string, string> = {};
        const exts: Record<string, string> = {};
        files.forEach((file) => {
          fileNames[file.id] = file.name;
          exts[file.id] = file.name.substr(file.name.lastIndexOf(".") + 1);
        });

        if (formatMode) {
          formats.forEach((forms) => {
            append(
              document.querySelectorAll(
                "#" + escapeId(forms[0]) + " > .plupload_file_name",
              ),
              `
            <a target="_blank" href="admin.php?page=photo-${forms[1].trim()}-properties">
              <span class="icon-eye">
              </span>
            </a>`,
            );
            if (formatsUpdated.includes(forms[0])) {
              document
                .querySelectorAll(
                  "#" + escapeId(forms[0]) + " > .plupload_file_name",
                )
                .forEach((el) => {
                  el.insertAdjacentHTML(
                    "afterend",
                    `
              <a target="_blank" href="admin.php?page=photo-${forms[1].trim()}-formats">
                <span class="icon-attention update-warning">
                  ${format_update_warning}
                </span>
              </a>
              <a class="remove-format" id="remove_${forms[0]}">
                <span class = "icon-cancel-circled">
                </span>
                ${format_remove}
              </a>`,
                  );
                });
              on(
                document.querySelectorAll("#remove_" + escapeId(forms[0])),
                "click",
                function () {
                  up.removeFile(forms[0]);
                },
              );
            }
          });

          // If no original image is specified
          if (!haveFormatsOriginal) {
            const images_search: ImageFormatSearchResponse["results"] =
              await new Promise((res, _rej) => {
                //ajax qui renvois les id des images dans la gallerie.
                void ajax({
                  url: "api/v1/images/formats/actions/search",
                  type: "POST",
                  contentType: "application/json",
                  data: JSON.stringify({
                    filenames: fileNames,
                  }),
                  success: function (data: ImageFormatSearchResponse) {
                    res(data.results);
                  },
                });
              });

            const notFound: string[] = [];
            const multiple: string[] = [];

            files.forEach((f) => {
              const search = images_search[f.id]!;
              if (search.status == "found") {
                f.format_of = String(search.imageId);
                formats.push([f.id, f.format_of]);
                append(
                  document.querySelectorAll(
                    "#" + escapeId(f.id) + " > .plupload_file_name",
                  ),
                  `
                <a target="_blank" href="admin.php?page=photo-${f.format_of.trim()}-properties">
                  <span class="icon-eye">
                  </span>
                </a>`,
                );
                if (search.formatExists) {
                  document
                    .querySelectorAll(
                      "#" + escapeId(f.id) + " > .plupload_file_name",
                    )
                    .forEach((el) => {
                      el.insertAdjacentHTML(
                        "afterend",
                        `
                  <a target="_blank" href="admin.php?page=photo-${f.format_of!.trim()}-formats">
                    <span class="icon-attention update-warning">
                      ${format_update_warning}
                    </span>
                  </a>
                  <a class="remove-format" id="remove_${f.id}">
                    <span class = "icon-cancel-circled">
                    </span>
                    ${format_remove}
                  </a>`,
                      );
                    });
                  formatsUpdated.push(f.id);
                  on(
                    document.querySelectorAll("#remove_" + escapeId(f.id)),
                    "click",
                    function () {
                      up.removeFile(f.id);
                    },
                  );
                }
              } else {
                if (search.status == "multiple") multiple.push(f.name);
                else notFound.push(f.name);
                up.removeFile(f.id);
              }
            });

            files.filter((f) => images_search[f.id]!.status === "found");

            // If a file is not found or found more than one time
            if (notFound.length || multiple.length) {
              const [multStr, notFoundStr] = [multiple, notFound].map((tab) => {
                //Get names
                tab = tab.map((f) => f.slice(0, f.indexOf(".")));
                // Remove duplicates
                tab = tab.filter((f, i) => i === tab.indexOf(f));

                // Add "and X more" if necessary
                if (tab.length > 5) {
                  tab[5] = str_and_X_others.replace(
                    "%d",
                    String(tab.length - 5),
                  );
                  tab = tab.splice(0, 6);
                }
                return tab;
              });

              alert({
                title: str_format_warning,
                content:
                  (notFound.length
                    ? `<p>${str_format_warning_notFound.replace("%s", notFoundStr!.join(", "))}</p>`
                    : "") +
                  (multiple.length
                    ? `<p>${str_format_warning_multiple.replace("%s", multStr!.join(", "))}</p>`
                    : ""),
                ...jConfirm_warning_options,
              });
            }
          } else {
            let $forms_exts: string[];
            if (imageFormatsExtensions) {
              $forms_exts = JSON.parse(imageFormatsExtensions) as string[];
            } else {
              $forms_exts = [];
            }
            files.forEach((f) => {
              f.format_of = String(originalImageId);
              formats.push([f.id, f.format_of]);
              append(
                document.querySelectorAll(
                  "#" + escapeId(f.id) + " > .plupload_file_name",
                ),
                `
              <a target="_blank" href="admin.php?page=photo-${f.format_of.trim()}-properties">
                <span class="icon-eye">
                </span>
              </a>`,
              );
              if ($forms_exts.indexOf(exts[f.id]!) != -1) {
                document
                  .querySelectorAll(
                    "#" + escapeId(f.id) + " > .plupload_file_name",
                  )
                  .forEach((el) => {
                    el.insertAdjacentHTML(
                      "afterend",
                      `
                <a target="_blank" href="admin.php?page=photo-${String(originalImageId).trim()}-formats">
                  <span class="icon-attention update-warning">
                    ${format_update_warning}
                  </span>
                </a>
                <a class="remove-format" id="remove_${f.id}">
                  <span class = "icon-cancel-circled">
                  </span>
                  ${format_remove}
                </a>`,
                    );
                  });
                formatsUpdated.push(f.id);
                on(
                  document.querySelectorAll("#remove_" + escapeId(f.id)),
                  "click",
                  function () {
                    up.removeFile(f.id);
                  },
                );
              }
            });
          }
        }
      },

      FilesRemoved: function (up: plupload.Uploader, _file: PluploadFile) {
        formats.forEach((forms) => {
          append(
            document.querySelectorAll(
              "#" + escapeId(forms[0]) + " > .plupload_file_name",
            ),
            `
          <a target="_blank" href="admin.php?page=photo-${forms[1].trim()}-properties">
            <span class="icon-eye">
            </span>
          </a>`,
          );
          if (formatsUpdated.includes(forms[0])) {
            document
              .querySelectorAll(
                "#" + escapeId(forms[0]) + " > .plupload_file_name",
              )
              .forEach((el) => {
                el.insertAdjacentHTML(
                  "afterend",
                  `
            <a target="_blank" href="admin.php?page=photo-${forms[1].trim()}-formats">
              <span class="icon-attention update-warning">
                ${format_update_warning}
              </span>
            </a>
            <a class="remove-format" id="remove_${forms[0]}">
              <span class = "icon-cancel-circled">
              </span>
              ${format_remove}
            </a>`,
                );
              });
            on(
              document.querySelectorAll("#remove_" + escapeId(forms[0])),
              "click",
              function () {
                up.removeFile(forms[0]);
              },
            );
          }
        });
      },

      UploadProgress: function (up: plupload.Uploader, _file: PluploadFile) {
        css(
          document.querySelectorAll("#uploadingActions .progressbar"),
          "width",
          up.total.percent + "%",
        );
        Piecon.setProgress(up.total.percent);
      },

      BeforeUpload: function (up: plupload.Uploader, file: PluploadFile) {
        // hide buttons
        hide(
          document.querySelectorAll("#startUpload, .selectFilesButtonBlock"),
        );
        show(document.querySelectorAll("#uploadingActions"));
        hide(document.querySelectorAll(".format-mode-group-manager"));
        hide(document.querySelectorAll("#selectedAlbumEdit"));
        // if (!formatMode) {
        //   var categorySelectedId = $("select[name=category] option:selected").val();
        //   var categorySelectedPath = $("select[name=category]")[0].selectize.getItem(categorySelectedId).text();
        //   $('.selectedAlbum').show().find('span').html(categorySelectedPath);
        // }

        // warn user if she wants to leave page while upload is running
        on(window, "beforeunload", function (e: Event) {
          // eslint-disable-next-line @typescript-eslint/no-unnecessary-type-assertion -- a real narrowing tsc itself enforces (plain Event's own `returnValue` is typed `boolean`, BeforeUnloadEvent's own is `any`; without this cast the very next assignment fails to typecheck), the rule's own limitation with an `any`-typed target property.
          const beforeUnload = e as BeforeUnloadEvent;
          beforeUnload.preventDefault();
          beforeUnload.returnValue = str_upload_in_progress;
          return str_upload_in_progress;
        });

        // no more change on category/level
        attr(
          document.querySelectorAll("select[name=level]"),
          "disabled",
          "disabled",
        );

        // You can override settings before the file is uploaded
        const options: MultipartParams = {
          pwg_token: pwg_token,
        };

        if (formatMode) {
          options.format_of = file.format_of;
        } else {
          // options.category = $("select[name=category] option:selected").val();
          options.category = ab.get_selected_albums()[0];
          // options.level = $("select[name=level] option:selected").val();
          options.name = file.name;
        }

        const toggleUpdateMode = document.getElementById(
          "toggleUpdateMode",
        ) as HTMLInputElement | null;
        options.update_mode = toggleUpdateMode?.checked ?? false;

        up.setOption("multipart_params", options);
      },

      FileUploaded: function (
        up: plupload.Uploader,
        file: PluploadFile,
        info: TusUploadInfo,
      ) {
        // Called when file has finished uploading. Unlike a plain plupload
        // setup, `info` here is a plain object built in uploadNextTusFile()
        // below: imageId/addStatus from the tus completion response,
        // squareSrc/name from a follow-up GET /api/v1/images/{id}.

        // hide item line
        hide(document.querySelectorAll("#" + escapeId(file.id)));

        const uploadedPhotosEl = document.getElementById("uploadedPhotos");
        const uploadedPhotosParent = uploadedPhotosEl?.parentElement;
        if (
          uploadedPhotosParent !== undefined &&
          uploadedPhotosParent !== null &&
          uploadedPhotosParent.matches("fieldset")
        ) {
          show(uploadedPhotosParent);
        }

        let lineHtml =
          '<a href="admin.php?page=photo-' +
          info.imageId +
          '" style="position : relative" target="_blank">';
        lineHtml +=
          '<img src="' +
          info.squareSrc +
          '" class="thumbnail" title="' +
          info.name +
          '">';
        if (formatMode)
          lineHtml +=
            '<div class="format-ext-name" title="' +
            file.name +
            '"><span>' +
            file.name.slice(file.name.indexOf(".")) +
            "</span></div>";
        lineHtml += "</a> ";

        prepend(document.querySelectorAll("#uploadedPhotos"), lineHtml);

        // do not remove file, or it will reset the progress bar :-/
        // up.removeFile(file);
        uploadedPhotos.push(info.imageId);
        if (info.addStatus == "add") {
          addedPhotos.push(info.imageId);
        } else {
          updatedPhotos.push(info.imageId);
        }
      },

      Error: function (up: plupload.Uploader, error: TusErrorInfo) {
        // Called when file has finished uploading. `error` is a plain
        // {message, file} object built in uploadNextTusFile() below, from
        // a real HTTP status returned by the tus endpoint.
        append(
          document.querySelectorAll(".errors ul"),
          "<li>" + error.message + "</li>",
        );
        show(document.querySelectorAll(".errors"));
      },

      UploadComplete: function (
        _up: plupload.Uploader,
        _files: PluploadFile[],
      ) {
        // Called when all files are either uploaded or failed
        //console.log('[UploadComplete]');

        Piecon.reset();

        if (!formatMode && uploadCategory) {
          void ajax({
            url: "api/v1/uploads/actions/complete-batch",
            type: "POST",
            contentType: "application/json",
            headers: { "X-CSRF-Token": pwg_token },
            data: JSON.stringify({
              categoryId: Number(uploadCategory.id),
            }),
            dataType: "json",
            success: function (
              data: operations["uploadCompleteBatch"]["responses"][200]["content"]["application/json"],
            ) {
              // A real, fresh nb_photos/label straight from the server --
              // read here instead of a value captured mid-upload, since
              // that captured value would otherwise be stale by the time
              // this batch-complete summary line renders.
              const summaryHtml = sprintf(
                albumSummary_label,
                '<a href="admin.php?page=album-' +
                  data.category.id +
                  '">' +
                  data.category.label +
                  "</a>",
                data.category.nbPhotos,
              );
              append(
                document.querySelectorAll(".infos ul"),
                "<li>" + summaryHtml + "</li>",
              );
            },
          });
        }

        hide(
          document.querySelectorAll("#uploadForm, #permissions, .showFieldset"),
        );

        const infoTextAdd = formatMode
          ? sprintf(
              formatsAdded_label,
              addedPhotos.length,
              [...new Set(addedPhotos)].length,
            )
          : sprintf(photosAdded_label, addedPhotos.length);

        const infoTextUpdate = formatMode
          ? sprintf(
              formatsUpdated_label,
              updatedPhotos.length,
              [...new Set(updatedPhotos)].length,
            )
          : sprintf(photosUpdated_label, updatedPhotos.length);

        if (addedPhotos.length && updatedPhotos.length) {
          append(
            document.querySelectorAll(".infos"),
            "<ul><li>" + infoTextAdd + ", " + infoTextUpdate + "</li></ul>",
          );
        } else {
          const infoText = addedPhotos.length ? infoTextAdd : infoTextUpdate;
          append(
            document.querySelectorAll(".infos"),
            "<ul><li>" + infoText + "</li></ul>",
          );
        }

        show(document.querySelectorAll(".infos"));

        // TODO: use a new method pwg.caddie.empty +
        // pwg.caddie.add(uploadedPhotos) instead of relying on huge GET parameter
        // (and remove useless code from admin/photos_add_direct.php)

        attr(
          document.querySelectorAll(".batchLink"),
          "href",
          "admin.php?page=photos_add&section=direct&batch=" +
            [...new Set(uploadedPhotos)].join(",") +
            "&pwg_token=" +
            pwg_token,
        );
        html(
          document.querySelectorAll(".batchLink"),
          sprintf(batch_Label, uploadedPhotos.length),
        );

        show(document.querySelectorAll(".afterUploadActions"));
        hide(document.querySelectorAll("#uploadingActions"));
        show(document.querySelectorAll("#selectedAlbumEdit"));

        // user can safely leave page without warning
        off(window, "beforeunload");
      },
    },
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

let activeTusUpload: TusUpload | null = null;

function computeAggregatePercent(files: PluploadFile[]) {
  let totalLoaded = 0;
  let totalSize = 0;
  files.forEach(function (f) {
    totalSize += f.size || 0;
    totalLoaded += f.status === plupload.DONE ? f.size || 0 : f.loaded || 0;
  });
  return totalSize ? Math.round((totalLoaded / totalSize) * 100) : 0;
}

function extractTusErrorDetail(err: Error | TusDetailedError) {
  if (err && "originalResponse" in err && err.originalResponse) {
    try {
      const body = JSON.parse(
        err.originalResponse.getBody(),
      ) as components["schemas"]["Problem"];
      if (body?.detail) {
        return body.detail;
      }
    } catch (_e) {
      // Not a problem+json body (e.g. a network-level failure) -- fall
      // through to the generic message below.
    }
  }
  return err && err.message ? err.message : "Upload failed";
}

function startTusUploads(up: plupload.Uploader) {
  const pendingFiles = (up.files as PluploadFile[]).filter(function (f) {
    return f.status !== plupload.DONE;
  });

  if (pendingFiles.length === 0) {
    up.trigger("UploadComplete", up.files);
    return;
  }

  uploadNextTusFile(up, pendingFiles, 0);
}

function cancelTusUploads() {
  if (activeTusUpload) {
    // Fire-and-forget: real tus-js-client type, previously masked by
    // `any` -- cancelling is best-effort, the caller proceeds to
    // trigger UploadComplete immediately either way (same as before).
    void activeTusUpload.abort();
    activeTusUpload = null;
  }
}

function uploadNextTusFile(
  up: plupload.Uploader,
  files: PluploadFile[],
  index: number,
) {
  if (index >= files.length) {
    activeTusUpload = null;
    up.trigger("UploadComplete", up.files);
    return;
  }

  const file = files[index]!;
  file.status = plupload.UPLOADING;

  // Reuses BeforeUpload's own multipart_params-building logic verbatim
  // (album selector read, format_of/update_mode) -- only the destination
  // (tus metadata instead of plupload's native multipart form fields)
  // differs from here on.
  up.trigger("BeforeUpload", file);
  const options = (up.getOption("multipart_params") || {}) as MultipartParams;

  const metadata: Record<string, string> = { filename: file.name };
  if (formatMode) {
    metadata.formatOf = String(options.format_of);
  } else {
    metadata.category = String(options.category);
    metadata.name = options.name ?? "";
    if (!uploadCategory) {
      uploadCategory = { id: options.category };
    }
  }
  if (options.update_mode) {
    metadata.updateMode = "1";
  }

  activeTusUpload = new Upload(file.getNative(), {
    endpoint: "api/v1/uploads",
    chunkSize: parseInt(chunk_size) * 1024,
    retryDelays: [0, 1000, 3000, 5000],
    headers: { "X-CSRF-Token": pwg_token },
    metadata: metadata,
    onProgress: function (bytesUploaded: number, bytesTotal: number) {
      file.loaded = bytesUploaded;
      file.size = bytesTotal;
      file.percent = bytesTotal
        ? Math.round((bytesUploaded / bytesTotal) * 100)
        : 0;
      up.total.percent = computeAggregatePercent(up.files as PluploadFile[]);
      up.trigger("UploadProgress", file);
    },
    onError: function (error: Error | TusDetailedError) {
      file.status = plupload.FAILED;
      up.trigger("Error", {
        message: extractTusErrorDetail(error),
        file: file,
      });
      uploadNextTusFile(up, files, index + 1);
    },
    // eslint-disable-next-line @typescript-eslint/no-misused-promises -- fire-and-forget async completion handler, same as the click handlers elsewhere in this campaign: tus-js-client's own real onSuccess type expects void, but nothing here awaits or needs to await the returned promise.
    onSuccess: async function (payload: TusOnSuccessPayload) {
      file.status = plupload.DONE;
      file.percent = 100;

      let result: Partial<
        operations["uploadPatch"]["responses"][200]["content"]["application/json"]
      > = {};
      try {
        result = JSON.parse(payload.lastResponse.getBody()) as typeof result;
      } catch (_e) {
        // Falls through with result = {}; the !result.imageId check
        // below reports it.
      }

      if (!result.imageId) {
        up.trigger("Error", {
          message: "Upload finished but the server response was unreadable.",
          file: file,
        });
        uploadNextTusFile(up, files, index + 1);
        return;
      }

      let imageInfo: Partial<
        operations["imageGet"]["responses"][200]["content"]["application/json"]
      > = {};
      try {
        imageInfo = (await ajax({
          url: "api/v1/images/" + result.imageId,
          type: "GET",
          dataType: "json",
        })) as typeof imageInfo;
      } catch (_e) {
        // Enrichment fetch failed -- the photo itself was uploaded
        // successfully, so still report it as such, just with a
        // fallback thumbnail/name.
      }

      // @types/plupload's own Uploader.trigger(name, Multiple) is
      // 2-arity only -- a real gap in that package, not this code: the
      // FileUploaded event genuinely fires with 2 extra args (file,
      // info), matching plupload_event_FileUploaded's own 3-param
      // handler shape a few lines up in the same package.
      (up.trigger as (name: string, ...args: unknown[]) => unknown)(
        "FileUploaded",
        file,
        {
          imageId: result.imageId,
          addStatus: result.addStatus,
          squareSrc:
            imageInfo.derivatives && imageInfo.derivatives.square
              ? imageInfo.derivatives.square.url
              : "",
          name: imageInfo.name || file.name,
        },
      );
      uploadNextTusFile(up, files, index + 1);
    },
  });

  activeTusUpload.start();
}

/*--------------
General functions
--------------*/

function add_related_category({
  album,
  newSelectedAlbum,
}: AlbumSelectorCallbackArgs) {
  // Was: parse `full_name_with_admin_links` as HTML and concatenate the
  // inner HTML of each top-level node -- which turned
  // `<a>A</a><span> / </span><a>B</a>` into "A / B". The field does not
  // exist (CategoryListController dropped it), so this always produced ""
  // and blanked the label on every selection. `fullname` is that same
  // breadcrumb already HTML-stripped by the server, so the string is
  // identical and the unpacking is no longer needed.
  const text = album.fullname ?? "";
  newSelectedAlbum();

  if (selectedAlbumName !== null) {
    hide(selectedAlbumName);
    html([selectedAlbumName], text);
    fadeIn([selectedAlbumName]);
  }

  if (addPhotosAS !== null) hide(addPhotosAS);
  if (selectedAlbum !== null) fadeIn([selectedAlbum]);

  enable_uploader();
}

function enable_uploader() {
  if (btnAddFiles !== null) removeAttr(btnAddFiles, "disabled");
  if (chooseAlbumFirst !== null) hide(chooseAlbumFirst);
  if (uploaderPhotos !== null) show(uploaderPhotos);
}

/*-------------------
First album functions
-------------------*/

function open_new_album_modal() {
  if (inputFirstAlbum !== null) setVal([inputFirstAlbum], "");
  if (modalFirstAlbum !== null) fadeIn([modalFirstAlbum]);
  inputFirstAlbum?.focus();
}

function close_new_album_modal() {
  if (modalFirstAlbum !== null) fadeOut([modalFirstAlbum]);
}

function hide_first_album(cat_name: string) {
  if (modalFirstAlbum !== null) hide(modalFirstAlbum);
  hide(firstAlbum);

  if (addPhotosAS !== null) hide(addPhotosAS);
  if (selectedAlbumName !== null) html([selectedAlbumName], cat_name);
  if (selectedAlbum !== null) show(selectedAlbum);

  enable_uploader();
  if (uploadForm !== null) fadeIn([uploadForm]);
}

function add_first_album(add_cat: (id: string | number) => void) {
  const params = {
    name: inputFirstAlbum !== null ? (val([inputFirstAlbum]) ?? "") : "",
  };

  void ajax({
    url: "api/v1/categories",
    method: "POST",
    contentType: "application/json",
    headers: { "X-CSRF-Token": pwg_token },
    dataType: "json",
    data: JSON.stringify(params),
    success: function (
      res: operations["categoryCreate"]["responses"][201]["content"]["application/json"],
    ) {
      add_cat(res.id);
      hide_first_album(params.name);
    },
    error: function () {
      console.error("An error has occurred");
    },
  });
}

const formatMode = pwg_getPageData<boolean>("display_formats");
const haveFormatsOriginal = pwg_getPageData<boolean>("have_formats_original");
const originalImageId: string | number = haveFormatsOriginal
  ? pwg_getPageData<string>("original_image_id_str")
  : -1;
const imageFormatsExtensions =
  pwg_getPageData<string | false | null>("formats_ext_info") || "";
const nb_albums = pwg_getPageData<string>("nb_albums");
const chunk_size = pwg_getPageData<number>("chunk_size") + "kb";
const max_file_size = pwg_getPageData<number>("max_file_size") + "mb";
const format_update_warning = pwg_getPageString(
  "This format already exists, it will be overwritten !",
);
const format_remove = pwg_getPageString("Remove");
const pwg_token = pwg_getPageData<string>("csrf_token");
const photosAdded_label = pwg_getPageString("%d photos uploaded");
const photosUpdated_label = pwg_getPageString("%d photos updated");
const formatsAdded_label = pwg_getPageString("%d formats added for %d photos");
const formatsUpdated_label = pwg_getPageString(
  "%d formats updated for %d photos",
);
const batch_Label = pwg_getPageString("Manage this set of %d photos");
const albumSummary_label = pwg_getPageString(
  'Album "%s" now contains %d photos',
);
const str_format_warning = pwg_getPageString(
  "Error when trying to detect formats",
);
const str_format_warning_multiple = pwg_getPageString(
  "There is multiple image in the database with the following names : %s.",
);
const str_format_warning_notFound = pwg_getPageString(
  "No picture found with the following name : %s.",
);
const str_and_X_others = pwg_getPageString("and %d more");
const str_upload_in_progress = pwg_getPageString("Upload in progress");
const str_drop_album_ab = pwg_getPageString("Drop into album");
const file_ext = pwg_getPageData<string>("file_exts");
const format_ext = pwg_getPageData<string>("format_ext");
const uploadedPhotos: (number | string)[] = [];
let uploadCategory: { id: string | number | undefined } | null = null;
const addedPhotos: (number | string)[] = [];
const updatedPhotos: (number | string)[] = [];
const related_categories_ids = pwg_getPageData<number[]>(
  "related_categories_ids",
);
