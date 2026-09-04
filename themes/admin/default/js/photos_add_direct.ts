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
import { AlbumSelector } from "../../../default/js/album_selector";
import { sprintf } from "../../../default/js/sprintf";
import { jConfirmWarningOptions } from "./jconfirmPresets";

import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../default/js/page-data";
import { ajax, AjaxError } from "../../../default/js/vendor/ajax";
import { alert } from "../../../default/js/vendor/jconfirm";
import * as Piecon from "../../../default/js/vendor/piecon";
import {
  DONE,
  FAILED,
  UPLOADING,
  uploadQueue,
  type UploadQueue,
  type UploadQueueFile,
} from "../../../default/js/vendor/uploadQueue";
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
  file: UploadQueueFile;
}

interface TusOnSuccessPayload {
  lastResponse: TusHttpResponse;
}

interface MultipartParams {
  pwg_token: string;
  format_of?: string | undefined;
  category?: string | number | undefined;
  name?: string;
  update_mode?: boolean;
}

// `addRelatedCategory` is declared here too, independently of the
// same-named functions in mcs.js/cat_modify.ts/batchManagerUnit.ts/
// picture_modify.ts (docs/PLAN.md P46-B's own finding) -- safe since
// these pages never co-load.
//
// tus-js-client is imported from its own npm package and bundled, not
// a CDN-supplied global. Piecon and plupload are both real native ports
// now (`vendor/piecon.ts`/`vendor/uploadQueue.ts`, P49-C) -- no npm
// package, no CDN script, and no jQuery dependency for either any more.

/*--------------
Variables
--------------*/
const btnFirstAlbum = document.getElementById("btnFirstAlbum");
const modalFirstAlbum = document.getElementById("addFirstAlbum");
const closeModalFirstAlbum = document.getElementById("closeFirstAlbum");
const inputFirstAlbum =
  document.querySelector<HTMLInputElement>("#inputFirstAlbum");
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

const formatMode = pwg_getPageData<boolean>("display_formats");
const haveFormatsOriginal = pwg_getPageData<boolean>("have_formats_original");
const originalImageId: string | number = haveFormatsOriginal
  ? pwg_getPageData<string>("original_image_id_str")
  : -1;
const rawImageFormatsExtensions = pwg_getPageData<string | false | null>(
  "formats_ext_info",
);
const imageFormatsExtensions =
  rawImageFormatsExtensions !== false && rawImageFormatsExtensions !== null
    ? rawImageFormatsExtensions
    : "";
const nbAlbums = pwg_getPageData<string>("nb_albums");
const chunkSize = String(pwg_getPageData<number>("chunk_size")) + "kb";
const maxFileSize = String(pwg_getPageData<number>("max_file_size")) + "mb";
const formatUpdateWarning = pwg_getPageString(
  "This format already exists, it will be overwritten !",
);
const formatRemove = pwg_getPageString("Remove");
const pwgToken = pwg_getPageData<string>("csrf_token");
const photosAddedLabel = pwg_getPageString("%d photos uploaded");
const photosUpdatedLabel = pwg_getPageString("%d photos updated");
const formatsAddedLabel = pwg_getPageString("%d formats added for %d photos");
const formatsUpdatedLabel = pwg_getPageString(
  "%d formats updated for %d photos",
);
const batchLabel = pwg_getPageString("Manage this set of %d photos");
const albumSummaryLabel = pwg_getPageString(
  'Album "%s" now contains %d photos',
);
const strFormatWarning = pwg_getPageString(
  "Error when trying to detect formats",
);
const strFormatWarningMultiple = pwg_getPageString(
  "There is multiple image in the database with the following names : %s.",
);
const strFormatWarningNotFound = pwg_getPageString(
  "No picture found with the following name : %s.",
);
const strAndXOthers = pwg_getPageString("and %d more");
const strUploadInProgress = pwg_getPageString("Upload in progress");
const strDropAlbumAb = pwg_getPageString("Drop into album");
const fileExt = pwg_getPageData<string>("file_exts");
const formatExt = pwg_getPageData<string>("format_ext");
const uploadedPhotos: (number | string)[] = [];
let uploadCategory: { id: string | number | undefined } | null = null;
const addedPhotos: (number | string)[] = [];
const updatedPhotos: (number | string)[] = [];
const relatedCategoriesIds = pwg_getPageData<number[]>(
  "related_categories_ids",
);

/*--------------
On DOM load
--------------*/
ready(function () {
  const ab = new AlbumSelector({
    selectedCategoriesIds: relatedCategoriesIds,
    selectAlbum: addRelatedCategory,
    adminMode: true,
    modalTitle: strDropAlbumAb,
  });

  // Moved out of photos_add_direct.latte's own inline onClick, which both
  // navigated and set a loading class. The URL it interpolated now rides on
  // the label as data-switch-format-mode-url, since a real listener cannot
  // read a template variable.
  on(
    document.querySelectorAll(".format-mode-group-manager .switch"),
    "click",
    function (this: HTMLElement) {
      const url = this.dataset["switchFormatModeUrl"];
      if (url === undefined || url === "") {
        return;
      }
      addClass(document.querySelectorAll(".switch .slider"), "loading");
      window.location.replace(url);
    },
  );

  // First album event
  // Genuine pre-existing bug found via strict typing: PhotosAddDirectView.php's
  // own exposedPageData() exposes `nbAlbums` as a *string* (`(string)
  // $this->nbAlbums`), so a brand-new gallery's real "0" value was
  // truthy (`!"0"` is `false`, unlike `!0`) -- the "add your first
  // album" onboarding flow this check exists for could never actually
  // trigger. Fixed to a real numeric comparison.
  if (Number(nbAlbums) === 0) {
    if (btnFirstAlbum !== null) {
      on(btnFirstAlbum, "click", function () {
        openNewAlbumModal();
      });
    }

    if (closeModalFirstAlbum !== null) {
      on(closeModalFirstAlbum, "click", function () {
        closeNewAlbumModal();
      });
    }

    if (btnAddFirstAlbum !== null) {
      on(btnAddFirstAlbum, "click", function () {
        void addFirstAlbum(ab.selectAlbum.bind(ab));
      });
    }

    if (inputFirstAlbum !== null) {
      on(inputFirstAlbum, "keyup", function (e: Event) {
        // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- "keyup" always dispatches a real KeyboardEvent; on()'s own handler param is typed generically via the native EventListener interface.
        if ((e as KeyboardEvent).key === "Enter" && btnAddFirstAlbum !== null) {
          trigger([btnAddFirstAlbum], "click");
        }
      });
    }
  }

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
    void (async () => {
      try {
        await ajax({
          url: "api/v1/session/preferences/promote-mobile-apps",
          type: "PUT",
          dataType: "JSON",
          json: {
            value: "false",
          },
        });

        hide(document.querySelectorAll(".promote-apps"));
      } catch (e) {
        console.error(e instanceof AjaxError ? e.responseText : e);
      }
    })();
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
    function (this: Element) {
      const parent = this.parentElement;
      if (parent?.matches(".showFieldset") === true) {
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

  // Native port now (P49-C, `vendor/uploadQueue.ts`) -- `browse_button`/
  // `filters`/`rename`/`dragdrop`/`preinit`/`init` are this file's own
  // real, unmodified original options; `container`/`runtimes`/`url`/
  // `chunkSize` are dropped (see that module's own leading comment for
  // why each is real but dead here -- `chunkSize` in particular still
  // drives the real tus chunk size directly, in uploadNextTusFile()
  // below, just no longer duplicated into this config too).
  uploadQueue(uploaderPhotos!, {
    browse_button: "addFiles",

    filters: {
      // Maximum file size
      max_file_size: maxFileSize,
      // Specify what files to browse for
      mime_types: [
        {
          title: "Image files",
          extensions: formatMode ? formatExt : fileExt,
        },
      ],
    },

    // Rename files by clicking on their titles
    rename: formatMode,

    // Enable ability to drag'n'drop files onto the widget (currently only HTML5 supports that)
    dragdrop: true,

    preinit: {
      Init: function (up: UploadQueue, _info: unknown) {
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
      QueueChanged: function (up: UploadQueue) {
        if (btnAddFiles !== null) {
          addClass(btnAddFiles, "addFilesButtonChanged");
        }
        const startUpload =
          document.querySelector<HTMLButtonElement>("#startUpload");
        if (startUpload !== null) {
          startUpload.disabled = up.files.length === 0;
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

        if (up.files.length === 0) {
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

      FilesAdded: async function (up: UploadQueue, files: UploadQueueFile[]) {
        // Création de la liste avec plupload_id : image_name
        const fileNames: Record<string, string> = {};
        const exts: Record<string, string> = {};
        files.forEach((file) => {
          fileNames[file.id] = file.name;
          exts[file.id] = file.name.slice(file.name.lastIndexOf(".") + 1);
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
                  ${formatUpdateWarning}
                </span>
              </a>
              <a class="remove-format" id="remove_${forms[0]}">
                <span class = "icon-cancel-circled">
                </span>
                ${formatRemove}
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
            //ajax qui renvois les id des images dans la gallerie.
            // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
            const searchResponse = (await ajax({
              url: "api/v1/images/formats/actions/search",
              type: "POST",
              json: {
                filenames: fileNames,
              },
            })) as ImageFormatSearchResponse;
            const imagesSearch: ImageFormatSearchResponse["results"] =
              searchResponse.results;

            const notFound: string[] = [];
            const multiple: string[] = [];

            files.forEach((f) => {
              const search = imagesSearch[f.id]!;
              if (search.status === "found") {
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
                      ${formatUpdateWarning}
                    </span>
                  </a>
                  <a class="remove-format" id="remove_${f.id}">
                    <span class = "icon-cancel-circled">
                    </span>
                    ${formatRemove}
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
                if (search.status === "multiple") multiple.push(f.name);
                else notFound.push(f.name);
                up.removeFile(f.id);
              }
            });

            files.filter((f) => imagesSearch[f.id]!.status === "found");

            // If a file is not found or found more than one time
            if (notFound.length || multiple.length) {
              const [multStr, notFoundStr] = [multiple, notFound].map(
                (names) => {
                  //Get names
                  let tab = names.map((f) => f.slice(0, f.indexOf(".")));
                  // Remove duplicates
                  tab = tab.filter((f, i) => i === tab.indexOf(f));

                  // Add "and X more" if necessary
                  if (tab.length > 5) {
                    tab[5] = strAndXOthers.replace(
                      "%d",
                      String(tab.length - 5),
                    );
                    tab = tab.splice(0, 6);
                  }
                  return tab;
                },
              );

              alert({
                title: strFormatWarning,
                content:
                  (notFound.length
                    ? `<p>${strFormatWarningNotFound.replace("%s", notFoundStr!.join(", "))}</p>`
                    : "") +
                  (multiple.length
                    ? `<p>${strFormatWarningMultiple.replace("%s", multStr!.join(", "))}</p>`
                    : ""),
                ...jConfirmWarningOptions,
              });
            }
          } else {
            let $forms_exts: string[];
            if (imageFormatsExtensions) {
              const parsedExts: unknown = JSON.parse(imageFormatsExtensions);
              $forms_exts = Array.isArray(parsedExts)
                ? parsedExts.filter((n): n is string => typeof n === "string")
                : [];
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
              if ($forms_exts.includes(exts[f.id]!)) {
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
                    ${formatUpdateWarning}
                  </span>
                </a>
                <a class="remove-format" id="remove_${f.id}">
                  <span class = "icon-cancel-circled">
                  </span>
                  ${formatRemove}
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

      FilesRemoved: function (up: UploadQueue, _file: UploadQueueFile) {
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
                ${formatUpdateWarning}
              </span>
            </a>
            <a class="remove-format" id="remove_${forms[0]}">
              <span class = "icon-cancel-circled">
              </span>
              ${formatRemove}
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

      UploadProgress: function (up: UploadQueue, _file: UploadQueueFile) {
        css(
          document.querySelectorAll("#uploadingActions .progressbar"),
          "width",
          String(up.total.percent) + "%",
        );
        Piecon.setProgress(up.total.percent);
      },

      BeforeUpload: function (up: UploadQueue, file: UploadQueueFile) {
        // hide buttons
        hide(
          document.querySelectorAll("#startUpload, .selectFilesButtonBlock"),
        );
        show(document.querySelectorAll("#uploadingActions"));
        hide(document.querySelectorAll(".format-mode-group-manager"));
        hide(document.querySelectorAll("#selectedAlbumEdit"));

        // warn user if she wants to leave page while upload is running
        on(window, "beforeunload", function (e: Event) {
          e.preventDefault();
          return strUploadInProgress;
        });

        // no more change on category/level
        attr(
          document.querySelectorAll("select[name=level]"),
          "disabled",
          "disabled",
        );

        // You can override settings before the file is uploaded
        const options: MultipartParams = {
          pwg_token: pwgToken,
        };

        if (formatMode) {
          options.format_of = file.format_of;
        } else {
          // options.category = $("select[name=category] option:selected").val();
          [options.category] = ab.getSelectedAlbums();
          // options.level = $("select[name=level] option:selected").val();
          options.name = file.name;
        }

        const toggleUpdateMode =
          document.querySelector<HTMLInputElement>("#toggleUpdateMode");
        options.update_mode = toggleUpdateMode?.checked ?? false;

        up.setOption("multipart_params", options);
      },

      FileUploaded: function (
        _up: UploadQueue,
        file: UploadQueueFile,
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
        if (uploadedPhotosParent?.matches("fieldset") === true) {
          show(uploadedPhotosParent);
        }

        let lineHtml =
          '<a href="admin.php?page=photo-' +
          String(info.imageId) +
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
        if (info.addStatus === "add") {
          addedPhotos.push(info.imageId);
        } else {
          updatedPhotos.push(info.imageId);
        }
      },

      Error: function (_up: UploadQueue, error: TusErrorInfo) {
        // Called when file has finished uploading. `error` is a plain
        // {message, file} object built in uploadNextTusFile() below, from
        // a real HTTP status returned by the tus endpoint.
        append(
          document.querySelectorAll(".errors ul"),
          "<li>" + error.message + "</li>",
        );
        show(document.querySelectorAll(".errors"));
      },

      UploadComplete: function (_up: UploadQueue, _files: UploadQueueFile[]) {
        // Called when all files are either uploaded or failed
        Piecon.reset();

        if (!formatMode && uploadCategory) {
          void (async () => {
            try {
              // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
              const data = (await ajax({
                url: "api/v1/uploads/actions/complete-batch",
                type: "POST",
                json: {
                  categoryId: Number(uploadCategory.id),
                },
                headers: { "X-CSRF-Token": pwgToken },
                dataType: "json",
              })) as operations["uploadCompleteBatch"]["responses"][200]["content"]["application/json"];

              // A real, fresh nb_photos/label straight from the server --
              // read here instead of a value captured mid-upload, since
              // that captured value would otherwise be stale by the time
              // this batch-complete summary line renders.
              const summaryHtml = sprintf(
                albumSummaryLabel,
                '<a href="admin.php?page=album-' +
                  String(data.category.id) +
                  '">' +
                  data.category.label +
                  "</a>",
                data.category.nbPhotos,
              );
              append(
                document.querySelectorAll(".infos ul"),
                "<li>" + summaryHtml + "</li>",
              );
            } catch (e) {
              console.error(e instanceof AjaxError ? e.responseText : e);
            }
          })();
        }

        hide(
          document.querySelectorAll("#uploadForm, #permissions, .showFieldset"),
        );

        const infoTextAdd = formatMode
          ? sprintf(
              formatsAddedLabel,
              addedPhotos.length,
              [...new Set(addedPhotos)].length,
            )
          : sprintf(photosAddedLabel, addedPhotos.length);

        const infoTextUpdate = formatMode
          ? sprintf(
              formatsUpdatedLabel,
              updatedPhotos.length,
              [...new Set(updatedPhotos)].length,
            )
          : sprintf(photosUpdatedLabel, updatedPhotos.length);

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
            pwgToken,
        );
        html(
          document.querySelectorAll(".batchLink"),
          sprintf(batchLabel, uploadedPhotos.length),
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

function computeAggregatePercent(files: UploadQueueFile[]) {
  let totalLoaded = 0;
  let totalSize = 0;
  files.forEach(function (f) {
    totalSize += f.size || 0;
    totalLoaded += f.status === DONE ? f.size || 0 : f.loaded || 0;
  });
  return totalSize ? Math.round((totalLoaded / totalSize) * 100) : 0;
}

function extractTusErrorDetail(err: Error | TusDetailedError) {
  if ("originalResponse" in err && err.originalResponse) {
    try {
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- untrusted parsed JSON, verified downstream instead (see the guard right below).
      const body = JSON.parse(
        err.originalResponse.getBody(),
      ) as components["schemas"]["Problem"];
      // eslint-disable-next-line @typescript-eslint/no-unnecessary-condition, @typescript-eslint/strict-boolean-expressions -- real runtime guard: `body` is an `as`-cast of untrusted parsed JSON, not a real guarantee it matches Problem's shape.
      if (body?.detail) {
        return body.detail;
      }
    } catch (_e) {
      // Not a problem+json body (e.g. a network-level failure) -- fall
      // through to the generic message below.
    }
  }
  return err.message ? err.message : "Upload failed";
}

function startTusUploads(up: UploadQueue) {
  const pendingFiles = up.files.filter(function (f) {
    return f.status !== DONE;
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
  up: UploadQueue,
  files: UploadQueueFile[],
  index: number,
) {
  if (index >= files.length) {
    activeTusUpload = null;
    up.trigger("UploadComplete", up.files);
    return;
  }

  const file = files[index]!;
  file.status = UPLOADING;

  // Reuses BeforeUpload's own multipart_params-building logic verbatim
  // (album selector read, format_of/update_mode) -- only the destination
  // (tus metadata instead of plupload's native multipart form fields)
  // differs from here on.
  up.trigger("BeforeUpload", file);
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- getOption()'s own real vendor signature returns unknown (a generic options bag); BeforeUpload above this same call always seeds it with this exact real shape.
  const options = (up.getOption("multipart_params") ?? {}) as MultipartParams;

  const metadata: Record<string, string> = { filename: file.name };
  if (formatMode) {
    metadata["formatOf"] = String(options.format_of);
  } else {
    metadata["category"] = String(options.category);
    metadata["name"] = options.name ?? "";
    uploadCategory ??= { id: options.category };
  }
  if (options.update_mode === true) {
    metadata["updateMode"] = "1";
  }

  activeTusUpload = new Upload(file.getNative(), {
    endpoint: "api/v1/uploads",
    chunkSize: parseInt(chunkSize) * 1024,
    retryDelays: [0, 1000, 3000, 5000],
    headers: { "X-CSRF-Token": pwgToken },
    metadata: metadata,
    onProgress: function (bytesUploaded: number, bytesTotal: number) {
      file.loaded = bytesUploaded;
      file.size = bytesTotal;
      file.percent = bytesTotal
        ? Math.round((bytesUploaded / bytesTotal) * 100)
        : 0;
      up.total.percent = computeAggregatePercent(up.files);
      up.trigger("UploadProgress", file);
    },
    onError: function (error: Error | TusDetailedError) {
      file.status = FAILED;
      up.trigger("Error", {
        message: extractTusErrorDetail(error),
        file: file,
      });
      uploadNextTusFile(up, files, index + 1);
    },
    // eslint-disable-next-line @typescript-eslint/no-misused-promises -- fire-and-forget async completion handler, same as the click handlers elsewhere in this campaign: tus-js-client's own real onSuccess type expects void, but nothing here awaits or needs to await the returned promise.
    onSuccess: async function (payload: TusOnSuccessPayload) {
      file.status = DONE;
      file.percent = 100;

      let result: Partial<
        operations["uploadPatch"]["responses"][200]["content"]["application/json"]
      > = {};
      try {
        // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- verified downstream instead (the `result.imageId === undefined` check right below).
        result = JSON.parse(payload.lastResponse.getBody()) as typeof result;
      } catch (_e) {
        // Falls through with result = {}; the !result.imageId check
        // below reports it.
      }

      if (result.imageId === undefined) {
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
        // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
        imageInfo = (await ajax({
          url: "api/v1/images/" + String(result.imageId),
          type: "GET",
          dataType: "json",
        })) as typeof imageInfo;
      } catch (_e) {
        // Enrichment fetch failed -- the photo itself was uploaded
        // successfully, so still report it as such, just with a
        // fallback thumbnail/name.
      }

      up.trigger("FileUploaded", file, {
        imageId: result.imageId,
        addStatus: result.addStatus,
        squareSrc: imageInfo.derivatives?.["square"]
          ? imageInfo.derivatives["square"].url
          : "",
        name: imageInfo.name ?? file.name,
      });
      uploadNextTusFile(up, files, index + 1);
    },
  });

  activeTusUpload.start();
}

/*--------------
General functions
--------------*/

function addRelatedCategory({
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

  enableUploader();
}

function enableUploader() {
  if (btnAddFiles !== null) removeAttr(btnAddFiles, "disabled");
  if (chooseAlbumFirst !== null) hide(chooseAlbumFirst);
  if (uploaderPhotos !== null) show(uploaderPhotos);
}

/*-------------------
First album functions
-------------------*/

function openNewAlbumModal() {
  if (inputFirstAlbum !== null) setVal([inputFirstAlbum], "");
  if (modalFirstAlbum !== null) fadeIn([modalFirstAlbum]);
  inputFirstAlbum?.focus();
}

function closeNewAlbumModal() {
  if (modalFirstAlbum !== null) fadeOut([modalFirstAlbum]);
}

function hideFirstAlbum(cat_name: string) {
  if (modalFirstAlbum !== null) hide(modalFirstAlbum);
  hide(firstAlbum);

  if (addPhotosAS !== null) hide(addPhotosAS);
  if (selectedAlbumName !== null) html([selectedAlbumName], cat_name);
  if (selectedAlbum !== null) show(selectedAlbum);

  enableUploader();
  if (uploadForm !== null) fadeIn([uploadForm]);
}

async function addFirstAlbum(
  add_cat: (id: string | number) => void,
): Promise<void> {
  const params = {
    name: inputFirstAlbum !== null ? (val([inputFirstAlbum]) ?? "") : "",
  };

  try {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
    const res = (await ajax({
      url: "api/v1/categories",
      method: "POST",
      json: params,
      headers: { "X-CSRF-Token": pwgToken },
      dataType: "json",
    })) as operations["categoryCreate"]["responses"][201]["content"]["application/json"];

    add_cat(res.id);
    hideFirstAlbum(params.name);
  } catch (e) {
    console.error("An error has occurred", e);
  }
}
