// Real consumer of album_selector.ts's own top-level `class
// AlbumSelector` (docs/PLAN.md P48 -- was a bare ambient-global read,
// see that file's own leading comment for the full real-consumer
// list). `?dup` since album_selector.ts has several real registrant
// pages (Design §4).
import { AlbumSelector } from "./album_selector";
import { CategoriesCache, TagsCache } from "./LocalStorageCache";

import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../default/js/page-data";
export {};
//
// `add_related_category`/`remove_related_category` are declared here
// too, independently of the same-named functions in mcs.js/
// batchManagerUnit.js/cat_modify.js/photos_add_direct.js (docs/PLAN.md
// P46-B's own finding) -- safe since none of these pages ever co-load.
const related_categories_ids = pwg_getPageData<string[]>(
  "related_categories_ids",
);
const str_assoc_album_ab = pwg_getPageString("Associate to album");
const str_orphan = pwg_getPageString("This photo is an orphan");

(function () {
  // <!-- CATEGORIES -->
  const categoriesCache = new CategoriesCache({
    serverKey: pwg_getPageData<string>("cache_key_categories"),
    serverId: pwg_getPageData<string>("cache_key_hash"),
    rootUrl: pwg_getPageData<string>("root_url"),
  });

  categoriesCache.selectize(jQuery("[data-selectize=categories]"));

  // <!-- TAGS -->
  const tagsCache = new TagsCache({
    serverKey: pwg_getPageData<string>("cache_key_tags"),
    serverId: pwg_getPageData<string>("cache_key_hash"),
    rootUrl: pwg_getPageData<string>("root_url"),
  });

  tagsCache.selectize(jQuery("[data-selectize=tags]"), {
    lang: {
      Add: pwg_getPageString("Create"),
    },
  });

  // <!-- DATEPICKER -->
  jQuery(function () {
    // onLoad needed to wait localization loads
    jQuery("[data-datepicker]").pwgDatepicker({
      showTimepicker: true,
      cancelButton: pwg_getPageString("Cancel"),
    });
  });

  // <!-- THUMBNAILS -->
  jQuery("a.preview-box").colorbox({
    photo: true,
  });

  const str_are_you_sure = pwg_getPageString("Are you sure?");
  const str_yes = pwg_getPageString("Yes, delete");
  const str_no = pwg_getPageString("No, I have changed my mind");
  const url_delete = pwg_getPageData<string>("u_delete");

  $("#action-delete-picture").on("click", function () {
    $.confirm({
      title: str_are_you_sure,
      draggable: false,
      titleClass: "groupDeleteConfirm",
      theme: "modern",
      content: "",
      animation: "zoom",
      boxWidth: "30%",
      useBootstrap: false,
      type: "red",
      animateFromElement: false,
      backgroundDismiss: true,
      typeAnimated: false,
      buttons: {
        confirm: {
          text: str_yes,
          btnClass: "btn-red",
          action: function () {
            window.location.href = url_delete.replaceAll("amp;", "");
          },
        },
        cancel: {
          text: str_no,
        },
      },
    });
  });
})();

$(document).ready(function () {
  const ab = new AlbumSelector({
    selectedCategoriesIds: related_categories_ids,
    selectAlbum: add_related_category,
    removeSelectedAlbum: remove_related_category,
    adminMode: true,
    modalTitle: str_assoc_album_ab,
  });

  $(".linked-albums.add-item").on("click", function () {
    ab.open();
  });

  $(".related-categories-container").on("click", (e) => {
    if (e.target.classList.contains("remove-item")) {
      ab.remove_selected_album($(e.target).attr("id")!);
    }
  });

  // Unsaved settings message before leave this page
  let form_unsaved = false;
  let user_interacted = false;
  $("#pictureModify")
    .find(":input")
    .on("focus", function () {
      user_interacted = true;
    });
  $("#pictureModify")
    .find(":input")
    .on("change", function () {
      if (user_interacted) {
        form_unsaved = true;
        console.log(($(this)[0] as HTMLInputElement).name, $(this));
      }
    });
  $(window).on("beforeunload", function () {
    if (form_unsaved) {
      return "Somes changes are not registered";
    }
  });
  $("#pictureModify").on("submit", function () {
    form_unsaved = false;
  });
});

function remove_related_category({
  id_album,
  getSelectedAlbum,
}: AlbumSelectorRemoveCallbackArgs) {
  $(
    ".invisible-related-categories-select option[value=" + id_album + "]",
  ).remove();
  $(".invisible-related-categories-select").trigger("change");
  $("#" + id_album)
    .parent()
    .remove();
  check_related_categories(getSelectedAlbum());
}

function add_related_category({
  album,
  addSelectedAlbum,
  getSelectedAlbum,
}: AlbumSelectorCallbackArgs) {
  if (!getSelectedAlbum().includes(String(album.id))) {
    $(".related-categories-container").append(
      `<div class="breadcrumb-item">
        <span class="link-path">${album.full_name_with_admin_links}</span><span id="${album.id}" class="icon-cancel-circled remove-item"></span>
      </div>`,
    );

    $(".search-result-item #" + album.id).addClass("notClickable");
    $(".invisible-related-categories-select")
      .append("<option selected value=" + album.id + "></option>")
      .trigger("change");
    addSelectedAlbum();
  }

  check_related_categories(getSelectedAlbum());
}

function check_related_categories(selected_cat: (string | number)[]) {
  $(".linked-albums-badge").html(String(selected_cat.length));

  if (selected_cat.length == 0) {
    $(".linked-albums-badge").addClass("badge-red");
    $(".add-item").addClass("highlight");
    $(".orphan-photo").html(str_orphan).show();
  } else {
    $(".linked-albums-badge.badge-red").removeClass("badge-red");
    $(".add-item.highlight").removeClass("highlight");
    $(".orphan-photo").hide();
  }
}
