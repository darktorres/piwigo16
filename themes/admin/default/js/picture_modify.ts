// Real consumer of album_selector.ts's own top-level `class
// AlbumSelector` (docs/PLAN.md P48 -- was a bare ambient-global read,
// see that file's own leading comment for the full real-consumer list).
import { AlbumSelector } from "./album_selector";
import { CategoriesCache, TagsCache } from "./LocalStorageCache";

import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../default/js/page-data";
import { confirm } from "../../../default/js/vendor/jconfirm";
import {
  addClass,
  albumBreadcrumbHtml,
  append,
  attrOf,
  escapeId,
  hide,
  html,
  on,
  ready,
  remove,
  removeClass,
  show,
} from "../../../default/js/vendor/dom";
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

  // Still jQuery: selectize() takes a JQuery object, ported in P49-B
  // group 6.
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
  ready(function () {
    // onLoad needed to wait localization loads
    // Still jQuery: pwgDatepicker wraps jQuery-UI datepicker +
    // timepicker-addon, ported in P49-B group 5.
    jQuery("[data-datepicker]").pwgDatepicker({
      showTimepicker: true,
      cancelButton: pwg_getPageString("Cancel"),
    });
  });

  // <!-- THUMBNAILS -->
  // Still jQuery: colorbox is a library, ported in P49-B group 3.
  jQuery("a.preview-box").colorbox({
    photo: true,
  });

  const str_are_you_sure = pwg_getPageString("Are you sure?");
  const str_yes = pwg_getPageString("Yes, delete");
  const str_no = pwg_getPageString("No, I have changed my mind");
  const url_delete = pwg_getPageData<string>("u_delete");

  on(
    document.querySelectorAll("#action-delete-picture"),
    "click",
    function (): void {
      confirm({
        title: str_are_you_sure,
        titleClass: "groupDeleteConfirm",
        content: "",
        boxWidth: "30%",
        type: "red",
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
    },
  );
})();

ready(function () {
  const ab = new AlbumSelector({
    selectedCategoriesIds: related_categories_ids,
    selectAlbum: add_related_category,
    removeSelectedAlbum: remove_related_category,
    adminMode: true,
    modalTitle: str_assoc_album_ab,
  });

  on(
    document.querySelectorAll(".linked-albums.add-item"),
    "click",
    function (): void {
      ab.open();
    },
  );

  on(
    document.querySelectorAll(".related-categories-container"),
    "click",
    (e: Event) => {
      if ((e.target as Element).classList.contains("remove-item")) {
        ab.remove_selected_album(attrOf(e.target as Element, "id")!);
      }
    },
  );

  // `:input` is jQuery/Sizzle's own pseudo-selector (matches input,
  // select, textarea and button elements) -- not real CSS, and
  // querySelectorAll throws on it.
  const inputSelector =
    "#pictureModify input, #pictureModify select, #pictureModify textarea, #pictureModify button";

  // Unsaved settings message before leave this page
  let form_unsaved = false;
  let user_interacted = false;
  on(document.querySelectorAll(inputSelector), "focus", function (): void {
    user_interacted = true;
  });
  on(
    document.querySelectorAll(inputSelector),
    "change",
    function (event: Event): void {
      if (user_interacted) {
        form_unsaved = true;
        console.log(
          (event.currentTarget as HTMLInputElement).name,
          event.currentTarget,
        );
      }
    },
  );
  on(window, "beforeunload", function (): string | undefined {
    if (form_unsaved) {
      return "Somes changes are not registered";
    }

    return undefined;
  });
  on(document.querySelectorAll("#pictureModify"), "submit", function (): void {
    form_unsaved = false;
  });
});

function remove_related_category({
  id_album,
  getSelectedAlbum,
}: AlbumSelectorRemoveCallbackArgs) {
  remove(
    document.querySelectorAll(
      '.invisible-related-categories-select option[value="' + id_album + '"]',
    ),
  );
  document
    .querySelectorAll(".invisible-related-categories-select")
    .forEach((select) => {
      select.dispatchEvent(new Event("change"));
    });
  const el = document.getElementById(String(id_album));
  if (el?.parentElement) {
    el.parentElement.remove();
  }
  check_related_categories(getSelectedAlbum());
}

function add_related_category({
  album,
  levelSeparator,
  addSelectedAlbum,
  getSelectedAlbum,
}: AlbumSelectorCallbackArgs) {
  if (!getSelectedAlbum().includes(String(album.id))) {
    append(
      document.querySelectorAll(".related-categories-container"),
      `<div class="breadcrumb-item">
        <span class="link-path">${albumBreadcrumbHtml(album.breadcrumb, levelSeparator)}</span><span id="${album.id}" class="icon-cancel-circled remove-item"></span>
      </div>`,
    );

    addClass(
      document.querySelectorAll(".search-result-item #" + escapeId(album.id)),
      "notClickable",
    );
    append(
      document.querySelectorAll(".invisible-related-categories-select"),
      "<option selected value=" + album.id + "></option>",
    );
    document
      .querySelectorAll(".invisible-related-categories-select")
      .forEach((select) => {
        select.dispatchEvent(new Event("change"));
      });
    addSelectedAlbum();
  }

  check_related_categories(getSelectedAlbum());
}

function check_related_categories(selected_cat: (string | number)[]) {
  html(
    document.querySelectorAll(".linked-albums-badge"),
    String(selected_cat.length),
  );

  if (selected_cat.length == 0) {
    addClass(document.querySelectorAll(".linked-albums-badge"), "badge-red");
    addClass(document.querySelectorAll(".add-item"), "highlight");
    html(document.querySelectorAll(".orphan-photo"), str_orphan);
    show(document.querySelectorAll(".orphan-photo"));
  } else {
    removeClass(
      document.querySelectorAll(".linked-albums-badge.badge-red"),
      "badge-red",
    );
    removeClass(document.querySelectorAll(".add-item.highlight"), "highlight");
    hide(document.querySelectorAll(".orphan-photo"));
  }
}
