// Real consumer of album_selector.ts's own top-level `class
// AlbumSelector` (docs/PLAN.md P48 -- was a bare ambient-global read,
// see that file's own leading comment for the full real-consumer list).
import { AlbumSelector } from "../../../default/js/album_selector";
import { CategoriesCache, TagsCache } from "./LocalStorageCache";

import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../default/js/pageData";
import { colorbox } from "../../../default/js/vendor/widgets/colorbox";
import { confirm } from "../../../default/js/vendor/widgets/jconfirm";
import { pwgDatepicker } from "../../../default/js/vendor/widgets/datepicker";
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
} from "../../../default/js/vendor/utils/dom";

//
// `addRelatedCategory`/`removeRelatedCategory` are declared here
// too, independently of the same-named functions in mcs.js/
// batchManagerUnit.js/cat_modify.js/photos_add_direct.js (docs/PLAN.md
// P46-B's own finding) -- safe since none of these pages ever co-load.
const relatedCategoriesIds = pwg_getPageData<string[]>(
  "related_categories_ids",
);
const strAssocAlbumAb = pwg_getPageString("Associate to album");
const strOrphan = pwg_getPageString("This photo is an orphan");

(function () {
  // <!-- CATEGORIES -->
  const categoriesCache = new CategoriesCache({
    serverKey: pwg_getPageData<string>("cache_key_categories"),
    serverId: pwg_getPageData<string>("cache_key_hash"),
    rootUrl: pwg_getPageData<string>("root_url"),
  });

  categoriesCache.selectize(
    document.querySelectorAll("[data-selectize=categories]"),
  );

  // <!-- TAGS -->
  const tagsCache = new TagsCache({
    serverKey: pwg_getPageData<string>("cache_key_tags"),
    serverId: pwg_getPageData<string>("cache_key_hash"),
    rootUrl: pwg_getPageData<string>("root_url"),
  });

  tagsCache.selectize(document.querySelectorAll("[data-selectize=tags]"), {
    lang: {
      Add: pwg_getPageString("Create"),
    },
  });

  // <!-- DATEPICKER -->
  ready(function () {
    // onLoad needed to wait localization loads
    pwgDatepicker(document.querySelectorAll("[data-datepicker]"), {
      showTimepicker: true,
      cancelButton: pwg_getPageString("Cancel"),
      jqueryCode: pwg_getPageData<string | undefined>("jquery_code"),
    });
  });

  // <!-- THUMBNAILS -->
  colorbox(document.querySelectorAll("a.preview-box"), { photo: true });

  const strAreYouSure = pwg_getPageString("Are you sure?");
  const strYes = pwg_getPageString("Yes, delete");
  const strNo = pwg_getPageString("No, I have changed my mind");
  const urlDelete = pwg_getPageData<string>("u_delete");

  on(
    document.querySelectorAll("#action-delete-picture"),
    "click",
    function (): void {
      confirm({
        title: strAreYouSure,
        titleClass: "groupDeleteConfirm",
        content: "",
        boxWidth: "30%",
        type: "red",
        buttons: {
          confirm: {
            text: strYes,
            btnClass: "btn-red",
            action: function () {
              window.location.href = urlDelete.replaceAll("amp;", "");
            },
          },
          cancel: {
            text: strNo,
          },
        },
      });
    },
  );
})();

ready(function () {
  const ab = new AlbumSelector({
    selectedCategoriesIds: relatedCategoriesIds,
    selectAlbum: addRelatedCategory,
    removeSelectedAlbum: removeRelatedCategory,
    adminMode: true,
    modalTitle: strAssocAlbumAb,
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
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- a real click inside the document always targets an Element (or null), never a bare EventTarget with no Element interface.
      const target = e.target as Element;
      if (target.classList.contains("remove-item")) {
        // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- every real ".remove-item" here always renders a real id (the related album's own id).
        ab.removeSelectedAlbum(attrOf(target, "id")!);
      }
    },
  );

  // `:input` is jQuery/Sizzle's own pseudo-selector (matches input,
  // select, textarea and button elements) -- not real CSS, and
  // querySelectorAll throws on it.
  const inputSelector =
    "#pictureModify input, #pictureModify select, #pictureModify textarea, #pictureModify button";

  // Unsaved settings message before leave this page
  let formUnsaved = false;
  let userInteracted = false;
  on(document.querySelectorAll(inputSelector), "focus", function (): void {
    userInteracted = true;
  });
  on(document.querySelectorAll(inputSelector), "change", function (): void {
    if (userInteracted) {
      formUnsaved = true;
    }
  });
  on(window, "beforeunload", function (): string | undefined {
    if (formUnsaved) {
      return "Somes changes are not registered";
    }

    return undefined;
  });
  on(document.querySelectorAll("#pictureModify"), "submit", function (): void {
    formUnsaved = false;
  });
});

function removeRelatedCategory({
  id_album,
  getSelectedAlbum,
}: AlbumSelectorRemoveCallbackArgs) {
  remove(
    document.querySelectorAll(
      '.invisible-related-categories-select option[value="' +
        String(id_album) +
        '"]',
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
  checkRelatedCategories(getSelectedAlbum());
}

function addRelatedCategory({
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
      "<option selected value=" + String(album.id) + "></option>",
    );
    document
      .querySelectorAll(".invisible-related-categories-select")
      .forEach((select) => {
        select.dispatchEvent(new Event("change"));
      });
    addSelectedAlbum();
  }

  checkRelatedCategories(getSelectedAlbum());
}

function checkRelatedCategories(selected_cat: (string | number)[]) {
  html(
    document.querySelectorAll(".linked-albums-badge"),
    String(selected_cat.length),
  );

  if (selected_cat.length === 0) {
    addClass(document.querySelectorAll(".linked-albums-badge"), "badge-red");
    addClass(document.querySelectorAll(".add-item"), "highlight");
    html(document.querySelectorAll(".orphan-photo"), strOrphan);
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
