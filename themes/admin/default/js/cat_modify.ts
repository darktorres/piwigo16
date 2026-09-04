import type { operations } from "../../../../openapi/client/schema";
// Real consumer of album_selector.ts's own top-level `class
// AlbumSelector` (docs/PLAN.md P48 -- was a bare ambient-global read,
// see that file's own leading comment for the full real-consumer list).
import { AlbumSelector } from "./album_selector";
import { jConfirmConfirmOptions } from "./common";

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
import { ajax, AjaxError } from "../../../default/js/vendor/ajax";
import {
  confirm,
  type JConfirmInstance,
} from "../../../default/js/vendor/jconfirm";
import { tipTip } from "../../../default/js/vendor/tiptip";

// `addRelatedCategory` is declared here too, independently of the
// same-named functions in mcs.js/batchManagerUnit.js/
// batchManagerFilter.ts(no)/photos_add_direct.js/picture_modify.ts
// (docs/PLAN.md P46-B's own finding) -- safe since these pages never
// co-load, and this file's own `export {}` module isolation makes the
// safety even more robust than before.
const albumId = pwg_getPageData<number>("cat_id");
let parentAlbum = pwg_getPageData<number | string>("parent_cat_id");
let defaultParentAlbum = pwg_getPageData<number | string>("parent_cat_id");
const albumName = pwg_getPageData<string>("cat_name");
const nbSubAlbums = pwg_getPageData<number>("nb_subcats");
const pwgToken = pwg_getPageData<string>("csrf_token");
const uDelete = pwg_getPageData<string>("u_delete");
let albumIsVisible: string = pwg_getPageData<boolean>("is_visible")
  ? "true"
  : "false";
const relatedCategoriesIds = [
  String(pwg_getPageData<number>("cat_id")),
  String(pwg_getPageData<number | string>("parent_cat_id")),
];

const strCancel = pwg_getPageString("No, I have changed my mind");
const strDeleteAlbum = pwg_getPageString("Delete album");
const strDeleteAlbumAndHisXSubalbums = pwg_getPageString(
  'Delete album "%s" and its %d sub-albums.',
);
const strJustNow = pwg_getPageString("Just now");
const strDontDeletePhotos = pwg_getPageString("delete only album, not photos");
const strDeleteOrphans = pwg_getPageString(
  "delete album and the %d orphan photos",
);
const strDeleteAllPhotos = pwg_getPageString(
  "delete album and all %d photos, even the %d associated to other albums",
);
const strAlbumCommentAllow = pwg_getPageString(
  "Comments allowed for sub-albums",
);
const strAlbumCommentDisallow = pwg_getPageString(
  "Comments disallowed for sub-albums",
);
const strModalAb = pwg_getPageString("New parent album");

ready(function () {
  // Modal description
  // Genuine pre-existing implicit-global bug (no `var`/`let`/`const`
  // anywhere) -- shared, harmlessly, between the allow-comments/
  // disallow-comments handlers below (each assigns before reading, so
  // sharing this declaration is behaviorally identical to the original
  // accidental `window.tempTxt`). Declared here, scoped to this ready
  // callback, since strict TS refuses a bare undeclared assignment.
  let tempTxt: string;

  activateCommentDropdown();
  checkAlbumLock();
  const ab = new AlbumSelector({
    selectedCategoriesIds: relatedCategoriesIds,
    selectAlbum: addRelatedCategory,
    showRootButton: true,
    adminMode: true,
    currentAlbumId: albumId,
    modalTitle: strModalAb,
  });

  on(document.querySelectorAll(".unlock-album"), "click", function () {
    void (async () => {
      try {
        await ajax({
          url: "api/v1/categories/" + String(albumId),
          type: "PATCH",
          dataType: "json",
          json: {
            visible: true,
          },
          headers: { "X-CSRF-Token": pwgToken },
        });
        albumIsVisible = "true";
        const catLocked =
          document.querySelector<HTMLInputElement>("#cat-locked");
        if (catLocked?.checked === true) {
          trigger([catLocked], "click");
        }
        checkAlbumLock();

        setTimeout(function () {
          hide(document.querySelectorAll(".info-message"));
        }, 5000);
      } catch (err) {
        saveButtonSetLoading(false);

        show(document.querySelectorAll(".info-error"));
        setTimeout(function () {
          hide(document.querySelectorAll(".info-error"));
        }, 5000);
        console.error(err);
      }
    })();
  });

  tipTip(document.querySelectorAll(".tiptip"), {
    delay: 0,
    fadeIn: 200,
    fadeOut: 200,
  });

  on(document.querySelectorAll("#cat-properties-save"), "click", () => {
    saveButtonSetLoading(true);
    hide(document.querySelectorAll(".info-error,.info-message"));

    // These 2 requests are independent (different endpoints, different
    // success/error handling) and were fire-and-forget in the original
    // -- each gets its own async IIFE rather than sequential awaits, to
    // keep them firing concurrently instead of one waiting on the other.
    void (async () => {
      try {
        await ajax({
          url: "api/v1/categories/" + String(albumId),
          type: "PATCH",
          dataType: "json",
          json: {
            name: val(document.querySelectorAll("#cat-name")),
            comment: val(document.querySelectorAll("#cat-comment")),
            visible:
              !document.querySelector<HTMLInputElement>("#cat-locked")!.checked,
            commentable:
              document.querySelector<HTMLInputElement>("#cat-commentable")!
                .checked,
          },
          headers: { "X-CSRF-Token": pwgToken },
        });
        saveButtonSetLoading(false);

        show(document.querySelectorAll(".info-message"));
        html(
          document.querySelectorAll(
            ".cat-modification .cat-modify-info-subcontent",
          ),
          strJustNow,
        );
        html(
          document.querySelectorAll(
            ".cat-modification .cat-modify-info-content",
          ),
          strJustNow,
        );

        albumIsVisible = document.querySelector<HTMLInputElement>(
          "#cat-locked",
        )!.checked
          ? "false"
          : "true";
        checkAlbumLock();

        setTimeout(function () {
          hide(document.querySelectorAll(".info-message"));
        }, 5000);
      } catch (err) {
        saveButtonSetLoading(false);

        show(document.querySelectorAll(".info-error"));
        setTimeout(function () {
          hide(document.querySelectorAll(".info-error"));
        }, 5000);
        console.error(err);
      }
    })();

    if (parentAlbum !== defaultParentAlbum) {
      void (async () => {
        try {
          // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
          const data = (await ajax({
            url: "api/v1/categories/actions/move",
            type: "POST",
            dataType: "json",
            json: {
              categoryIds: [albumId],
              parentId: parentAlbum,
            },
            headers: { "X-CSRF-Token": pwgToken },
          })) as operations["categoryMove"]["responses"][200]["content"]["application/json"];
          html(
            document.querySelectorAll(".cat-modify-ariane"),
            data.newArianeString,
          );
          defaultParentAlbum = parentAlbum;
        } catch (e) {
          show(document.querySelectorAll(".info-error"));
          setTimeout(function () {
            hide(document.querySelectorAll(".info-error"));
          }, 5000);
          console.error(e instanceof AjaxError ? e.responseText : e);
        }
      })();
    }
  });

  function saveButtonSetLoading(state = true) {
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

    const button = document.querySelector<HTMLButtonElement>(
      "#cat-properties-save",
    );
    if (button !== null) button.disabled = state;
  }

  on(document.querySelectorAll(".deleteAlbum"), "click", function () {
    confirm({
      title: strDeleteAlbum,
      // eslint-disable-next-line @typescript-eslint/promise-function-async -- must return ajax()'s own AjaxThenable (jconfirm.ts's `isThenable()` checks for its real `.always()`); `async` would re-wrap it through `Promise.resolve()` and lose that method.
      content: function (this: JConfirmInstance) {
        // eslint-disable-next-line @typescript-eslint/no-this-alias -- the classic callback-closure idiom: `this` (the jquery-confirm modal instance) needs to stay reachable inside the nested `success`/`error` callbacks below, which have their own `this`.
        const self = this;
        return ajax({
          url: "api/v1/categories/" + String(albumId) + "/orphan-impact",
          type: "GET",
          dataType: "json",
          success: function (
            data: operations["categoryOrphanImpact"]["responses"][200]["content"]["application/json"],
          ) {
            let message =
              "<p>" +
              strDeleteAlbumAndHisXSubalbums
                .replace("%s", "<strong>" + albumName + "</strong>")
                .replace("%d", "<strong>" + String(nbSubAlbums) + "</strong>") +
              "</p>";

            message += `<div class="cat-delete-modes">`;
            message += `<div  ${data.nbImagesRecursive ? "" : "style='display:none'"}>
                <input type="radio" name="deletion-mode" value="no_delete" id="no_delete" checked>
                <label for="no_delete">${strDontDeletePhotos}</label>
              </div>`;

            if (data.nbImagesRecursive) {
              let t = 0;
              message += `<div>
                <input type="radio" name="deletion-mode" value="force_delete" id="force_delete">
                <label for="force_delete">${strDeleteAllPhotos.replaceAll("%d", (_: string) => String([data.nbImagesRecursive, data.nbImagesAssociatedOutside][t++]))}</label>
              </div>`;
            }

            if (data.nbImagesBecomingOrphan)
              message += `<div>
                <input type="radio" name="deletion-mode" value="delete_orphans" id="delete_orphans">
                <label for="delete_orphans">${strDeleteOrphans.replace("%d", String(data.nbImagesBecomingOrphan))}</label>
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
          text: strDeleteAlbum,
          btnClass: "btn-red",
          action: function (this: JConfirmInstance) {
            this.showLoading();
            const checked = document.querySelector(
              'input[name="deletion-mode"]:checked',
            );
            const deletionMode = String(checked !== null ? val([checked]) : "");
            deleteAlbum(deletionMode)
              .then(() => (window.location.href = uDelete))
              .catch((err: unknown) => {
                this.close();
                console.error(err);
              });
            return false;
          },
        },
        cancel: {
          text: strCancel,
        },
      },
      ...jConfirmConfirmOptions,
    });
  });

  async function deleteAlbum(photo_deletion_mode: string): Promise<void> {
    // ajax() already resolves/rejects a real promise -- the old
    // manual `new Promise((res, rej) => ...)` wrapper here was purely
    // replicating that, not adding anything.
    await ajax({
      url: "api/v1/categories/" + String(albumId),
      type: "DELETE",
      json: {
        photoDeletionMode: photo_deletion_mode,
      },
      headers: { "X-CSRF-Token": pwgToken },
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

      void (async () => {
        try {
          // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
          const data = (await ajax({
            url:
              "api/v1/categories/" +
              String(albumId) +
              "/actions/refresh-representative",
            type: "POST",
            contentType: "application/json",
            headers: { "X-CSRF-Token": pwgToken },
            dataType: "json",
          })) as operations["categoryRefreshRepresentative"]["responses"][200]["content"]["application/json"];

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
        } catch (err) {
          console.error(err instanceof AjaxError ? err.responseText : err);
          const errorIcon = document.querySelector("#refreshRepresentative i");
          if (errorIcon !== null) {
            addClass(errorIcon, "icon-ccw");
            removeClass(errorIcon, "icon-spin6");
            removeClass(errorIcon, "animate-spin");
          }
        }
      })();

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

    void (async () => {
      try {
        // 204 No Content -- categoryDeleteRepresentative's real response has no body.
        await ajax({
          url: "api/v1/categories/" + String(albumId) + "/representative",
          type: "DELETE",
          contentType: "application/json",
          headers: { "X-CSRF-Token": pwgToken },
          dataType: "json",
        });

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
      } catch (err) {
        console.error(err instanceof AjaxError ? err.responseText : err);
        const errorIcon = document.querySelector("#deleteRepresentative i");
        if (errorIcon !== null) {
          addClass(errorIcon, "icon-cancel");
          removeClass(errorIcon, "icon-spin6");
          removeClass(errorIcon, "animate-spin");
        }
      }
    })();

    e.preventDefault();
  });

  // Parent album popin
  on(
    document.querySelectorAll("#cat-parent.icon-pencil"),
    "click",
    function (e) {
      // Don't open the popin if you click on the album link
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- a real click inside the document always targets an Element (or null), never a bare EventTarget with no Element interface.
      if ((e.target as Element).localName !== "a") {
        ab.open();
      }
    },
  );

  on(document.querySelectorAll(".allow-comments"), "click", function () {
    saveButtonSetLoading(true);

    void (async () => {
      try {
        await ajax({
          url: "api/v1/categories/" + String(albumId),
          type: "PATCH",
          dataType: "json",
          json: {
            commentable: true,
            applyCommentableToSubalbums: true,
          },
          headers: { "X-CSRF-Token": pwgToken },
        });

        saveButtonSetLoading(false);
        const commentable =
          document.querySelector<HTMLInputElement>("#cat-commentable");
        if (commentable !== null && !commentable.checked) {
          trigger([commentable], "click");
        }

        tempTxt = textOf(document.querySelectorAll(".info-message"));
        text(document.querySelectorAll(".info-message"), strAlbumCommentAllow);
        show(document.querySelectorAll(".info-message"));

        setTimeout(function () {
          hide(document.querySelectorAll(".info-message"));
          text(document.querySelectorAll(".info-message"), tempTxt);
        }, 5000);
      } catch (e) {
        console.error(e instanceof AjaxError ? e.responseText : e);
        saveButtonSetLoading(false);
        show(document.querySelectorAll(".info-error"));
        setTimeout(function () {
          hide(document.querySelectorAll(".info-error"));
        }, 5000);
      }
    })();
  });
  on(document.querySelectorAll(".disallow-comments"), "click", function () {
    saveButtonSetLoading(true);

    void (async () => {
      try {
        await ajax({
          url: "api/v1/categories/" + String(albumId),
          type: "PATCH",
          dataType: "json",
          json: {
            commentable: false,
            applyCommentableToSubalbums: true,
          },
          headers: { "X-CSRF-Token": pwgToken },
        });

        saveButtonSetLoading(false);
        const commentable =
          document.querySelector<HTMLInputElement>("#cat-commentable");
        if (commentable?.checked === true) {
          trigger([commentable], "click");
        }

        tempTxt = textOf(document.querySelectorAll(".info-message"));
        text(
          document.querySelectorAll(".info-message"),
          strAlbumCommentDisallow,
        );
        show(document.querySelectorAll(".info-message"));

        setTimeout(function () {
          hide(document.querySelectorAll(".info-message"));
          text(document.querySelectorAll(".info-message"), tempTxt);
        }, 5000);
      } catch (e) {
        console.error(e instanceof AjaxError ? e.responseText : e);
        saveButtonSetLoading(false);
        show(document.querySelectorAll(".info-error"));
        setTimeout(function () {
          hide(document.querySelectorAll(".info-error"));
        }, 5000);
      }
    })();
  });

  const descModal = document.getElementById("desc-modal");
  const textareas = document.querySelectorAll(".sync-textarea");
  on(
    document.querySelectorAll("#desc-zoom-square, #desc-modal-close"),
    "click",
    function () {
      if (descModal !== null) fadeToggle([descModal]);
    },
  );
  on(textareas, "keyup", function (this: Element) {
    const value = val([this]) ?? "";
    setVal(textareas, value);
  });
  on(window, "click", function (e: Event) {
    if (e.target === descModal) {
      if (descModal !== null) fadeToggle([descModal]);
    }
  });
  on(document, "keyup", function (e: Event) {
    if (
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- "keyup" always dispatches a real KeyboardEvent; on()'s own handler param is typed generically via the native EventListener interface.
      (e as KeyboardEvent).key === "Escape" &&
      descModal !== null &&
      isVisible(descModal)
    ) {
      fadeToggle([descModal]);
    }
  });
});

function checkAlbumLock() {
  if (albumIsVisible === "true") {
    hide(document.querySelectorAll(".warnings"));
  } else {
    document.querySelectorAll<HTMLElement>(".warnings").forEach((el) => {
      el.style.display = "flex";
    });
  }
}

// Parent album popin functions

function addRelatedCategory({
  album,
  levelSeparator,
  newSelectedAlbum,
  getSelectedAlbum,
}: AlbumSelectorCallbackArgs) {
  if (parentAlbum !== album.id) {
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
    parentAlbum = getSelectedAlbum()[0]!;
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
    function (this: Element) {
      toggle(find([this], ".comment-option"));
    },
  );

  /* Hide img options and rename field on click on the screen */

  on(document, "mouseup", function (e: Event) {
    e.stopPropagation();
    let optionIsClicked = false;
    document.querySelectorAll(".comment-option span").forEach((el) => {
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- a real mouseup event's own target inside the document is always a Node (or null), never a bare EventTarget with no Node interface.
      if (el.contains(e.target as Node)) {
        optionIsClicked = true;
      }
    });
    // eslint-disable-next-line @typescript-eslint/no-unnecessary-condition -- real false positive from closure mutation: optionIsClicked is set inside the forEach callback above, which the rule doesn't track (same class as dom.ts's stopped).
    if (!optionIsClicked) {
      hide(
        find(
          document.querySelectorAll(".toggle-comment-option"),
          ".comment-option",
        ),
      );
    }
  });
}
