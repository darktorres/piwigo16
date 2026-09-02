import type { operations } from "../../../../openapi/client/schema";
// Real consumer of album_selector.ts's own top-level `class
// AlbumSelector` (docs/PLAN.md P48 -- was a bare ambient-global read,
// see that file's own leading comment for the full real-consumer list).
import { AlbumSelector } from "./album_selector";
import { jConfirm_confirm_options } from "./common";

import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../default/js/page-data";
import {
  addClass,
  albumBreadcrumbHtml,
  append,
  attr,
  escapeId,
  fadeToggle,
  find,
  hide,
  html,
  isVisible,
  on,
  ready,
  removeClass,
  setVal,
  show,
  text,
  textOf,
  toggle,
  trigger,
  val,
} from "../../../default/js/vendor/dom";
import { ajax } from "../../../default/js/vendor/ajax";
import {
  confirm,
  type JConfirmInstance,
} from "../../../default/js/vendor/jconfirm";
import { tipTip } from "../../../default/js/vendor/tiptip";

// `add_related_category` is declared here too, independently of the
// same-named functions in mcs.js/batchManagerUnit.js/
// batchManagerFilter.ts(no)/photos_add_direct.js/picture_modify.ts
// (docs/PLAN.md P46-B's own finding) -- safe since these pages never
// co-load, and this file's own `export {}` module isolation makes the
// safety even more robust than before.
const album_id = pwg_getPageData<number>("cat_id");
let parent_album = pwg_getPageData<number | string>("parent_cat_id");
let default_parent_album = pwg_getPageData<number | string>("parent_cat_id");
const album_name = pwg_getPageData<string>("cat_name");
const nb_sub_albums = pwg_getPageData<number>("nb_subcats");
const pwg_token = pwg_getPageData<string>("csrf_token");
const u_delete = pwg_getPageData<string>("u_delete");
let is_visible: string = pwg_getPageData<boolean>("is_visible")
  ? "true"
  : "false";
const related_categories_ids = [
  String(pwg_getPageData<number>("cat_id")),
  String(pwg_getPageData<number | string>("parent_cat_id")),
];

const str_cancel = pwg_getPageString("No, I have changed my mind");
const str_delete_album = pwg_getPageString("Delete album");
const str_delete_album_and_his_x_subalbums = pwg_getPageString(
  'Delete album "%s" and its %d sub-albums.',
);
const str_just_now = pwg_getPageString("Just now");
const str_dont_delete_photos = pwg_getPageString(
  "delete only album, not photos",
);
const str_delete_orphans = pwg_getPageString(
  "delete album and the %d orphan photos",
);
const str_delete_all_photos = pwg_getPageString(
  "delete album and all %d photos, even the %d associated to other albums",
);
const str_album_comment_allow = pwg_getPageString(
  "Comments allowed for sub-albums",
);
const str_album_comment_disallow = pwg_getPageString(
  "Comments disallowed for sub-albums",
);
const str_modal_ab = pwg_getPageString("New parent album");

ready(function () {
  activateCommentDropdown();
  checkAlbumLock();
  const ab = new AlbumSelector({
    selectedCategoriesIds: related_categories_ids,
    selectAlbum: add_related_category,
    showRootButton: true,
    adminMode: true,
    currentAlbumId: album_id,
    modalTitle: str_modal_ab,
  });

  on(document.querySelectorAll(".unlock-album"), "click", function () {
    void ajax({
      url: "api/v1/categories/" + String(album_id),
      type: "PATCH",
      dataType: "json",
      contentType: "application/json",
      headers: { "X-CSRF-Token": pwg_token },
      data: JSON.stringify({
        visible: true,
      }),
      success: function (
        _data: operations["categoryUpdate"]["responses"][200]["content"]["application/json"],
      ) {
        is_visible = "true";
        const catLocked = document.getElementById(
          "cat-locked",
        ) as HTMLInputElement | null;
        if (catLocked?.checked === true) {
          trigger([catLocked], "click");
        }
        checkAlbumLock();

        setTimeout(function () {
          hide(document.querySelectorAll(".info-message"));
        }, 5000);
      },
      error: function (
        _XMLHttpRequest,
        _textStatus: string,
        errorThrows: string,
      ) {
        save_button_set_loading(false);

        show(document.querySelectorAll(".info-error"));
        setTimeout(function () {
          hide(document.querySelectorAll(".info-error"));
        }, 5000);
        console.error(errorThrows);
      },
    });
  });

  tipTip(document.querySelectorAll(".tiptip"), {
    delay: 0,
    fadeIn: 200,
    fadeOut: 200,
  });

  on(document.querySelectorAll("#cat-properties-save"), "click", () => {
    save_button_set_loading(true);
    hide(document.querySelectorAll(".info-error,.info-message"));

    void ajax({
      url: "api/v1/categories/" + String(album_id),
      type: "PATCH",
      dataType: "json",
      contentType: "application/json",
      headers: { "X-CSRF-Token": pwg_token },
      data: JSON.stringify({
        name: val(document.querySelectorAll("#cat-name")),
        comment: val(document.querySelectorAll("#cat-comment")),
        visible: !(document.getElementById("cat-locked") as HTMLInputElement)
          .checked,
        commentable: (
          document.getElementById("cat-commentable") as HTMLInputElement
        ).checked,
      }),
      success: function (
        _data: operations["categoryUpdate"]["responses"][200]["content"]["application/json"],
      ) {
        save_button_set_loading(false);

        show(document.querySelectorAll(".info-message"));
        html(
          document.querySelectorAll(
            ".cat-modification .cat-modify-info-subcontent",
          ),
          str_just_now,
        );
        html(
          document.querySelectorAll(
            ".cat-modification .cat-modify-info-content",
          ),
          str_just_now,
        );

        is_visible = (document.getElementById("cat-locked") as HTMLInputElement)
          .checked
          ? "false"
          : "true";
        checkAlbumLock();

        setTimeout(function () {
          hide(document.querySelectorAll(".info-message"));
        }, 5000);
      },
      error: function (
        _XMLHttpRequest,
        _textStatus: string,
        errorThrows: string,
      ) {
        save_button_set_loading(false);

        show(document.querySelectorAll(".info-error"));
        setTimeout(function () {
          hide(document.querySelectorAll(".info-error"));
        }, 5000);
        console.error(errorThrows);
      },
    });

    if (parent_album !== default_parent_album) {
      void ajax({
        url: "api/v1/categories/actions/move",
        type: "POST",
        dataType: "json",
        contentType: "application/json",
        headers: { "X-CSRF-Token": pwg_token },
        data: JSON.stringify({
          categoryIds: [album_id],
          parentId: parent_album,
        }),
        success: function (
          data: operations["categoryMove"]["responses"][200]["content"]["application/json"],
        ) {
          html(
            document.querySelectorAll(".cat-modify-ariane"),
            data.newArianeString,
          );
          default_parent_album = parent_album;
        },
        error: function (e) {
          show(document.querySelectorAll(".info-error"));
          setTimeout(function () {
            hide(document.querySelectorAll(".info-error"));
          }, 5000);
          console.error(e.responseText);
        },
      });
    }
  });

  function save_button_set_loading(state: boolean = true) {
    const icon = document.querySelector("#cat-properties-save i");
    if (icon !== null) {
      if (state) {
        removeClass(icon, "icon-floppy");
        addClass(icon, "icon-spin6");
        addClass(icon, "animate-spin");
      } else {
        addClass(icon, "icon-floppy");
        removeClass(icon, "icon-spin6");
        removeClass(icon, "animate-spin");
      }
    }

    const button = document.getElementById(
      "cat-properties-save",
    ) as HTMLButtonElement | null;
    if (button !== null) button.disabled = state;
  }

  on(document.querySelectorAll(".deleteAlbum"), "click", function () {
    confirm({
      title: str_delete_album,
      content: function (this: JConfirmInstance) {
        // eslint-disable-next-line @typescript-eslint/no-this-alias -- the classic callback-closure idiom: `this` (the jquery-confirm modal instance) needs to stay reachable inside the nested `success`/`error` callbacks below, which have their own `this`.
        const self = this;
        return ajax({
          url: "api/v1/categories/" + String(album_id) + "/orphan-impact",
          type: "GET",
          dataType: "json",
          success: function (
            data: operations["categoryOrphanImpact"]["responses"][200]["content"]["application/json"],
          ) {
            let message =
              "<p>" +
              str_delete_album_and_his_x_subalbums
                .replace("%s", "<strong>" + album_name + "</strong>")
                .replace(
                  "%d",
                  "<strong>" + String(nb_sub_albums) + "</strong>",
                ) +
              "</p>";

            message += `<div class="cat-delete-modes">`;
            message += `<div  ${data.nbImagesRecursive ? "" : "style='display:none'"}>
                <input type="radio" name="deletion-mode" value="no_delete" id="no_delete" checked>
                <label for="no_delete">${str_dont_delete_photos}</label>
              </div>`;

            if (data.nbImagesRecursive) {
              let t = 0;
              message += `<div>
                <input type="radio" name="deletion-mode" value="force_delete" id="force_delete">
                <label for="force_delete">${str_delete_all_photos.replaceAll("%d", (_: string) => String([data.nbImagesRecursive, data.nbImagesAssociatedOutside][t++]))}</label>
              </div>`;
            }

            if (data.nbImagesBecomingOrphan)
              message += `<div>
                <input type="radio" name="deletion-mode" value="delete_orphans" id="delete_orphans">
                <label for="delete_orphans">${str_delete_orphans.replace("%d", String(data.nbImagesBecomingOrphan))}</label>
              </div>`;
            message += `</div>`;

            self.setContent(message);
          },
          error: function (message) {
            console.error(message);
            self.setContent("An error has occured while calculating orphans");
          },
        });
      },
      buttons: {
        deleteAlbum: {
          text: str_delete_album,
          btnClass: "btn-red",
          action: function (this: JConfirmInstance) {
            this.showLoading();
            const checked = document.querySelector(
              'input[name="deletion-mode"]:checked',
            );
            const deletionMode = String(checked !== null ? val([checked]) : "");
            delete_album(deletionMode)
              .then(() => (window.location.href = u_delete))
              .catch((err: unknown) => {
                this.close();
                console.error(err);
              });
            return false;
          },
        },
        cancel: {
          text: str_cancel,
        },
      },
      ...jConfirm_confirm_options,
    });
  });

  function delete_album(photo_deletion_mode: string) {
    return new Promise<void>((res, rej) => {
      void ajax({
        url: "api/v1/categories/" + String(album_id),
        type: "DELETE",
        contentType: "application/json",
        headers: { "X-CSRF-Token": pwg_token },
        data: JSON.stringify({
          photoDeletionMode: photo_deletion_mode,
        }),
        success: function (
          _raw_data: operations["categoryDelete"]["responses"][200]["content"]["application/json"],
        ) {
          res();
        },
        error: function (message) {
          // eslint-disable-next-line @typescript-eslint/prefer-promise-reject-errors -- rejects with the real jqXHR error object, matching the original .catch()'s own console.log(err) usage; not a new Error, same as pre-P46.
          rej(message);
        },
      });
    });
  }

  on(
    document.querySelectorAll("#refreshRepresentative"),
    "click",
    function (e) {
      const icon = document.querySelector("#refreshRepresentative i");
      if (icon !== null) {
        removeClass(icon, "icon-ccw");
        addClass(icon, "icon-spin6");
        addClass(icon, "animate-spin");
      }

      void ajax({
        url:
          "api/v1/categories/" +
          String(album_id) +
          "/actions/refresh-representative",
        type: "POST",
        contentType: "application/json",
        headers: { "X-CSRF-Token": pwg_token },
        dataType: "json",
        success: function (
          data: operations["categoryRefreshRepresentative"]["responses"][200]["content"]["application/json"],
        ) {
          show(document.querySelectorAll("#deleteRepresentative"));

          attr(
            document.querySelectorAll(".cat-modify-representative"),
            "style",
            `background-image:url('${String(data.src)}')`,
          );
          removeClass(
            document.querySelectorAll(".cat-modify-representative"),
            "icon-dice-solid",
          );

          const doneIcon = document.querySelector("#refreshRepresentative i");
          if (doneIcon !== null) {
            addClass(doneIcon, "icon-ccw");
            removeClass(doneIcon, "icon-spin6");
            removeClass(doneIcon, "animate-spin");
          }
        },
        error: function (
          _XMLHttpRequest,
          _textStatus: string,
          errorThrows: string,
        ) {
          console.error(errorThrows);
          const errorIcon = document.querySelector("#refreshRepresentative i");
          if (errorIcon !== null) {
            addClass(errorIcon, "icon-ccw");
            removeClass(errorIcon, "icon-spin6");
            removeClass(errorIcon, "animate-spin");
          }
        },
      });

      e.preventDefault();
    },
  );

  on(document.querySelectorAll("#deleteRepresentative"), "click", function (e) {
    const icon = document.querySelector("#deleteRepresentative i");
    if (icon !== null) {
      removeClass(icon, "icon-cancel");
      addClass(icon, "icon-spin6");
      addClass(icon, "animate-spin");
    }

    void ajax({
      url: "api/v1/categories/" + String(album_id) + "/representative",
      type: "DELETE",
      contentType: "application/json",
      headers: { "X-CSRF-Token": pwg_token },
      dataType: "json",
      // 204 No Content -- categoryDeleteRepresentative's real response has no body.
      success: function (_data: unknown) {
        hide(document.querySelectorAll("#deleteRepresentative"));
        attr(
          document.querySelectorAll(".cat-modify-representative"),
          "style",
          ``,
        );
        addClass(
          document.querySelectorAll(".cat-modify-representative"),
          "icon-dice-solid",
        );

        const doneIcon = document.querySelector("#deleteRepresentative i");
        if (doneIcon !== null) {
          addClass(doneIcon, "icon-cancel");
          removeClass(doneIcon, "icon-spin6");
          removeClass(doneIcon, "animate-spin");
        }
      },
      error: function (
        _XMLHttpRequest,
        _textStatus: string,
        errorThrows: string,
      ) {
        console.error(errorThrows);
        const errorIcon = document.querySelector("#deleteRepresentative i");
        if (errorIcon !== null) {
          addClass(errorIcon, "icon-cancel");
          removeClass(errorIcon, "icon-spin6");
          removeClass(errorIcon, "animate-spin");
        }
      },
    });

    e.preventDefault();
  });

  // Parent album popin
  on(
    document.querySelectorAll("#cat-parent.icon-pencil"),
    "click",
    function (e) {
      // Don't open the popin if you click on the album link
      if ((e.target as Element).localName !== "a") {
        ab.open();
      }
    },
  );

  on(document.querySelectorAll(".allow-comments"), "click", function () {
    void ajax({
      url: "api/v1/categories/" + String(album_id),
      type: "PATCH",
      dataType: "json",
      contentType: "application/json",
      headers: { "X-CSRF-Token": pwg_token },
      data: JSON.stringify({
        commentable: true,
        applyCommentableToSubalbums: true,
      }),
      beforeSend: function () {
        save_button_set_loading(true);
      },
      success: function (
        _data: operations["categoryUpdate"]["responses"][200]["content"]["application/json"],
      ) {
        save_button_set_loading(false);
        const commentable = document.getElementById(
          "cat-commentable",
        ) as HTMLInputElement | null;
        if (commentable !== null && !commentable.checked) {
          trigger([commentable], "click");
        }

        temp_txt = textOf(document.querySelectorAll(".info-message"));
        text(
          document.querySelectorAll(".info-message"),
          str_album_comment_allow,
        );
        show(document.querySelectorAll(".info-message"));

        setTimeout(function () {
          hide(document.querySelectorAll(".info-message"));
          text(document.querySelectorAll(".info-message"), temp_txt);
        }, 5000);
      },
      error: function (e) {
        console.error(e);
        save_button_set_loading(false);
        show(document.querySelectorAll(".info-error"));
        setTimeout(function () {
          hide(document.querySelectorAll(".info-error"));
        }, 5000);
      },
    });
  });
  on(document.querySelectorAll(".disallow-comments"), "click", function () {
    void ajax({
      url: "api/v1/categories/" + String(album_id),
      type: "PATCH",
      dataType: "json",
      contentType: "application/json",
      headers: { "X-CSRF-Token": pwg_token },
      data: JSON.stringify({
        commentable: false,
        applyCommentableToSubalbums: true,
      }),
      beforeSend: function () {
        save_button_set_loading(true);
      },
      success: function (
        _data: operations["categoryUpdate"]["responses"][200]["content"]["application/json"],
      ) {
        save_button_set_loading(false);
        const commentable = document.getElementById(
          "cat-commentable",
        ) as HTMLInputElement | null;
        if (commentable?.checked === true) {
          trigger([commentable], "click");
        }

        temp_txt = textOf(document.querySelectorAll(".info-message"));
        text(
          document.querySelectorAll(".info-message"),
          str_album_comment_disallow,
        );
        show(document.querySelectorAll(".info-message"));

        setTimeout(function () {
          hide(document.querySelectorAll(".info-message"));
          text(document.querySelectorAll(".info-message"), temp_txt);
        }, 5000);
      },
      error: function (e) {
        console.error(e);
        save_button_set_loading(false);
        show(document.querySelectorAll(".info-error"));
        setTimeout(function () {
          hide(document.querySelectorAll(".info-error"));
        }, 5000);
      },
    });
  });

  // Modal description
  // Genuine pre-existing implicit-global bug (no `var`/`let`/`const`
  // anywhere) -- shared, harmlessly, between the allow-comments/
  // disallow-comments handlers above (each assigns before reading, so
  // sharing this declaration is behaviorally identical to the original
  // accidental `window.temp_txt`). Declared here, scoped to this ready
  // callback, since strict TS refuses a bare undeclared assignment.
  let temp_txt: string;
  const descModal = document.getElementById("desc-modal");
  const textareas = document.querySelectorAll(".sync-textarea");
  on(
    document.querySelectorAll("#desc-zoom-square, #desc-modal-close"),
    "click",
    function () {
      if (descModal !== null) fadeToggle([descModal]);
    },
  );
  on(textareas, "keyup", function (event: Event) {
    const value = val([event.currentTarget as Element]) ?? "";
    setVal(textareas, value);
  });
  on(window, "click", function (e: Event) {
    if (e.target === descModal) {
      if (descModal !== null) fadeToggle([descModal]);
    }
  });
  on(document, "keyup", function (e: Event) {
    if (
      (e as KeyboardEvent).key === "Escape" &&
      descModal !== null &&
      isVisible(descModal)
    ) {
      fadeToggle([descModal]);
    }
  });
});

function checkAlbumLock() {
  if (is_visible === "true") {
    hide(document.querySelectorAll(".warnings"));
  } else {
    document.querySelectorAll(".warnings").forEach((el) => {
      (el as HTMLElement).style.display = "flex";
    });
  }
}

// Parent album popin functions

function add_related_category({
  album,
  levelSeparator,
  newSelectedAlbum,
  getSelectedAlbum,
}: AlbumSelectorCallbackArgs) {
  if (parent_album !== album.id) {
    // Linked, matching what the server renders into this same element on
    // page load (`getCatDisplayNameCache($uppercats, 'admin.php?page=album-')`
    // via `categoriesParentNav`). `album.root` is the "Root" label the
    // put-at-root button passes instead of an album.
    html(
      document.querySelectorAll("#cat-parent"),
      album.breadcrumb !== undefined
        ? albumBreadcrumbHtml(album.breadcrumb, levelSeparator)
        : (album.root ?? ""),
    );

    addClass(
      document.querySelectorAll(".search-result-item #" + escapeId(album.id)),
      "notClickable",
    );
    append(
      document.querySelectorAll(".invisible-related-categories-select"),
      "<option selected value=" + String(album.id) + "></option>",
    );

    newSelectedAlbum();
    parent_album = getSelectedAlbum()[0]!;
  }
}

function activateCommentDropdown() {
  hide(
    find(
      document.querySelectorAll(".toggle-comment-option"),
      ".comment-option",
    ),
  );

  /* Display the option on the click on "..." */
  on(
    document.querySelectorAll(".toggle-comment-option"),
    "click",
    function (event: Event) {
      toggle(find([event.currentTarget as Element], ".comment-option"));
    },
  );

  /* Hide img options and rename field on click on the screen */

  on(document, "mouseup", function (e: Event) {
    e.stopPropagation();
    let option_is_clicked = false;
    document.querySelectorAll(".comment-option span").forEach((el) => {
      if (el.contains(e.target as Node)) {
        option_is_clicked = true;
      }
    });
    // eslint-disable-next-line @typescript-eslint/no-unnecessary-condition -- real false positive from closure mutation: option_is_clicked is set inside the forEach callback above, which the rule doesn't track (same class as dom.ts's stopped).
    if (!option_is_clicked) {
      hide(
        find(
          document.querySelectorAll(".toggle-comment-option"),
          ".comment-option",
        ),
      );
    }
  });
}
