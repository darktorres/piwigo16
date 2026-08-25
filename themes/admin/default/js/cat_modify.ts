import type { operations } from "../../../../openapi/client/schema";

export {};

// Consumer of album_selector.ts's own real, top-level `class
// AlbumSelector` -- resolves directly via that file's own real
// declaration, same reasoning as picture_modify.ts/
// batchManagerFilter.ts's own copy of this comment.
//
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

jQuery(document).ready(function () {
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

  $(".unlock-album").on("click", function () {
    jQuery.ajax({
      url: "api/v1/categories/" + album_id,
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
        if ($("#cat-locked").is(":checked")) {
          $("input[id='cat-locked']").trigger("click");
        }
        checkAlbumLock();

        setTimeout(function () {
          $(".info-message").hide();
        }, 5000);
      },
      error: function (
        XMLHttpRequest: JQuery.jqXHR,
        textStatus: string,
        errorThrows: string,
      ) {
        save_button_set_loading(false);

        $(".info-error").show();
        setTimeout(function () {
          $(".info-error").hide();
        }, 5000);
        console.log(errorThrows);
      },
    });
  });

  jQuery(".tiptip").tipTip({
    delay: 0,
    fadeIn: 200,
    fadeOut: 200,
  });

  $("#cat-properties-save").click(() => {
    save_button_set_loading(true);
    $(".info-error,.info-message").hide();

    jQuery.ajax({
      url: "api/v1/categories/" + album_id,
      type: "PATCH",
      dataType: "json",
      contentType: "application/json",
      headers: { "X-CSRF-Token": pwg_token },
      data: JSON.stringify({
        name: $("#cat-name").val(),
        comment: $("#cat-comment").val(),
        visible: !$("#cat-locked").is(":checked"),
        commentable: $("#cat-commentable").is(":checked"),
      }),
      success: function (
        _data: operations["categoryUpdate"]["responses"][200]["content"]["application/json"],
      ) {
        save_button_set_loading(false);

        $(".info-message").show();
        $(".cat-modification .cat-modify-info-subcontent").html(str_just_now);
        $(".cat-modification .cat-modify-info-content").html(str_just_now);

        is_visible = $("#cat-locked").is(":checked") ? "false" : "true";
        checkAlbumLock();

        setTimeout(function () {
          $(".info-message").hide();
        }, 5000);
      },
      error: function (
        XMLHttpRequest: JQuery.jqXHR,
        textStatus: string,
        errorThrows: string,
      ) {
        save_button_set_loading(false);

        $(".info-error").show();
        setTimeout(function () {
          $(".info-error").hide();
        }, 5000);
        console.log(errorThrows);
      },
    });

    if (parent_album != default_parent_album) {
      jQuery.ajax({
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
          $(".cat-modify-ariane").html(data.newArianeString);
          default_parent_album = parent_album;
        },
        error: function (e: JQuery.jqXHR) {
          $(".info-error").show();
          setTimeout(function () {
            $(".info-error").hide();
          }, 5000);
          console.log(e.responseText);
        },
      });
    }
  });

  function save_button_set_loading(state: boolean = true) {
    if (state) {
      $("#cat-properties-save i").removeClass("icon-floppy");
      $("#cat-properties-save i").addClass("icon-spin6");
      $("#cat-properties-save i").addClass("animate-spin");
    } else {
      $("#cat-properties-save i").addClass("icon-floppy");
      $("#cat-properties-save i").removeClass("icon-spin6");
      $("#cat-properties-save i").removeClass("animate-spin");
    }

    $("#cat-properties-save").prop("disabled", state);
  }

  // jquery-confirm's own modal instance -- no real type source (this
  // campaign's own established list of loosely-typed vendor libraries),
  // narrowed to just the 3 real methods actually called on it here.
  interface JConfirmModalInstance {
    setContent(html: string): void;
    showLoading(): void;
    close(): void;
  }

  $(".deleteAlbum").on("click", function () {
    $.confirm({
      title: str_delete_album,
      content: function (this: JConfirmModalInstance) {
        // eslint-disable-next-line @typescript-eslint/no-this-alias -- the classic callback-closure idiom: `this` (the jquery-confirm modal instance) needs to stay reachable inside the nested `success`/`error` callbacks below, which have their own `this`.
        const self = this;
        return $.ajax({
          url: "api/v1/categories/" + album_id + "/orphan-impact",
          type: "GET",
          dataType: "json",
          success: function (
            data: operations["categoryOrphanImpact"]["responses"][200]["content"]["application/json"],
          ) {
            let message =
              "<p>" +
              str_delete_album_and_his_x_subalbums
                .replace("%s", "<strong>" + album_name + "</strong>")
                .replace("%d", "<strong>" + nb_sub_albums + "</strong>") +
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
                <label for="force_delete">${str_delete_all_photos.replaceAll("%d", (_) => String([data.nbImagesRecursive, data.nbImagesAssociatedOutside][t++]))}</label>
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
          error: function (message: JQuery.jqXHR) {
            console.log(message);
            self.setContent("An error has occured while calculating orphans");
          },
        });
      },
      buttons: {
        deleteAlbum: {
          text: str_delete_album,
          btnClass: "btn-red",
          action: function (this: JConfirmModalInstance) {
            this.showLoading();
            const deletionMode = String(
              $('input[name="deletion-mode"]:checked').val(),
            );
            delete_album(deletionMode)
              .then(() => (window.location.href = u_delete))
              .catch((err: JQuery.jqXHR) => {
                this.close();
                console.log(err);
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
      $.ajax({
        url: "api/v1/categories/" + album_id,
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
        error: function (message: JQuery.jqXHR) {
          // eslint-disable-next-line @typescript-eslint/prefer-promise-reject-errors -- rejects with the real jqXHR error object, matching the original .catch()'s own console.log(err) usage; not a new Error, same as pre-P46.
          rej(message);
        },
      });
    });
  }

  $("#refreshRepresentative").on("click", function (e) {
    $("#refreshRepresentative i")
      .removeClass("icon-ccw")
      .addClass("icon-spin6")
      .addClass("animate-spin");

    jQuery.ajax({
      url: "api/v1/categories/" + album_id + "/actions/refresh-representative",
      type: "POST",
      contentType: "application/json",
      headers: { "X-CSRF-Token": pwg_token },
      dataType: "json",
      success: function (
        data: operations["categoryRefreshRepresentative"]["responses"][200]["content"]["application/json"],
      ) {
        jQuery("#deleteRepresentative").show();

        jQuery(".cat-modify-representative")
          .attr("style", `background-image:url('${String(data.src)}')`)
          .removeClass("icon-dice-solid");

        $("#refreshRepresentative i")
          .addClass("icon-ccw")
          .removeClass("icon-spin6")
          .removeClass("animate-spin");
      },
      error: function (
        XMLHttpRequest: JQuery.jqXHR,
        textStatus: string,
        errorThrows: string,
      ) {
        console.error(errorThrows);
        $("#refreshRepresentative i")
          .addClass("icon-ccw")
          .removeClass("icon-spin6")
          .removeClass("animate-spin");
      },
    });

    e.preventDefault();
  });

  $("#deleteRepresentative").on("click", function (e) {
    $("#deleteRepresentative i")
      .removeClass("icon-cancel")
      .addClass("icon-spin6")
      .addClass("animate-spin");

    jQuery.ajax({
      url: "api/v1/categories/" + album_id + "/representative",
      type: "DELETE",
      contentType: "application/json",
      headers: { "X-CSRF-Token": pwg_token },
      dataType: "json",
      // 204 No Content -- categoryDeleteRepresentative's real response has no body.
      success: function (_data: unknown) {
        jQuery("#deleteRepresentative").hide();
        jQuery(".cat-modify-representative")
          .attr("style", ``)
          .addClass("icon-dice-solid");

        $("#deleteRepresentative i")
          .addClass("icon-cancel")
          .removeClass("icon-spin6")
          .removeClass("animate-spin");
      },
      error: function (
        XMLHttpRequest: JQuery.jqXHR,
        textStatus: string,
        errorThrows: string,
      ) {
        console.error(errorThrows);
        $("#deleteRepresentative i")
          .addClass("icon-cancel")
          .removeClass("icon-spin6")
          .removeClass("animate-spin");
      },
    });

    e.preventDefault();
  });

  // Parent album popin
  $("#cat-parent.icon-pencil").on("click", function (e) {
    // Don't open the popin if you click on the album link
    if (e.target.localName != "a") {
      ab.open();
    }
  });

  $(".allow-comments").on("click", function () {
    jQuery.ajax({
      url: "api/v1/categories/" + album_id,
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
        if (!$("#cat-commentable").is(":checked")) {
          $("#cat-commentable").trigger("click");
        }

        temp_txt = $(".info-message").text();
        $(".info-message").text(str_album_comment_allow);
        $(".info-message").show();

        setTimeout(function () {
          $(".info-message").hide();
          $(".info-message").text(temp_txt);
        }, 5000);
      },
      error: function (e: JQuery.jqXHR) {
        console.log(e);
        save_button_set_loading(false);
        $(".info-error").show();
        setTimeout(function () {
          $(".info-error").hide();
        }, 5000);
      },
    });
  });
  $(".disallow-comments").on("click", function () {
    jQuery.ajax({
      url: "api/v1/categories/" + album_id,
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
        if ($("#cat-commentable").is(":checked")) {
          $("#cat-commentable").trigger("click");
        }

        temp_txt = $(".info-message").text();
        $(".info-message").text(str_album_comment_disallow);
        $(".info-message").show();

        setTimeout(function () {
          $(".info-message").hide();
          $(".info-message").text(temp_txt);
        }, 5000);
      },
      error: function (e: JQuery.jqXHR) {
        console.log(e);
        save_button_set_loading(false);
        $(".info-error").show();
        setTimeout(function () {
          $(".info-error").hide();
        }, 5000);
      },
    });
  });

  // Modal description
  const form_unsaved = false;
  // Genuine pre-existing implicit-global bug (no `var`/`let`/`const`
  // anywhere) -- shared, harmlessly, between the allow-comments/
  // disallow-comments handlers above (each assigns before reading, so
  // sharing this declaration is behaviorally identical to the original
  // accidental `window.temp_txt`). Declared here, scoped to this ready
  // callback, since strict TS refuses a bare undeclared assignment.
  let temp_txt: string;
  const cat_modify = $("#cat-modify");
  const desc_modal = $("#desc-modal");
  const textareas = $(".sync-textarea");
  $("#desc-zoom-square, #desc-modal-close").on("click", function () {
    desc_modal.fadeToggle();
  });
  textareas.keyup(function () {
    textareas.val($(this).val() as string);
  });
  $(window).on("click", function (e) {
    if ((e.target as unknown as Element) == desc_modal[0]) {
      desc_modal.fadeToggle();
    }
  });
  $(document).on("keyup", function (e) {
    // 27 is 'Escape'
    if (e.keyCode === 27 && desc_modal.is(":visible")) {
      desc_modal.fadeToggle();
    }
  });
});

function checkAlbumLock() {
  if (is_visible == "true") {
    $(".warnings").hide();
  } else {
    $(".warnings").css("display", "flex");
  }
}

// Parent album popin functions

function add_related_category({
  album,
  newSelectedAlbum,
  getSelectedAlbum,
}: AlbumSelectorCallbackArgs) {
  if (parent_album != album.id) {
    $("#cat-parent").html(album.full_name_with_admin_links ?? album.root ?? "");

    $(".search-result-item #" + album.id).addClass("notClickable");
    $(".invisible-related-categories-select").append(
      "<option selected value=" + album.id + "></option>",
    );

    newSelectedAlbum();
    parent_album = getSelectedAlbum()[0]!;
  }
}

function activateCommentDropdown() {
  $(".toggle-comment-option").find(".comment-option").hide();

  /* Display the option on the click on "..." */
  $(".toggle-comment-option").on("click", function () {
    $(this).find(".comment-option").toggle();
  });

  /* Hide img options and rename field on click on the screen */

  $(document).mouseup(function (e) {
    e.stopPropagation();
    let option_is_clicked = false;
    $(".comment-option span").each(function () {
      if (!($(this).has(e.target as unknown as Element).length === 0)) {
        option_is_clicked = true;
      }
    });
    if (!option_is_clicked) {
      $(".toggle-comment-option").find(".comment-option").hide();
    }
  });
}
