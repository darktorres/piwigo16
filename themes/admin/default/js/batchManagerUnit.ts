import type { operations } from "../../../../openapi/client/schema";
// Real consumer of album_selector.ts's own top-level `class
// AlbumSelector` (docs/PLAN.md P48 -- was a bare ambient-global read,
// see that file's own leading comment for the full real-consumer list,
// including the real, accepted "2 independent class copies on this
// page" consequence of batchManagerFilter.ts's own separate direct
// import).
import { AlbumSelector } from "./album_selector";
import { CategoriesCache, TagsCache } from "./LocalStorageCache";

import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../default/js/page-data";
import {
  addClass,
  albumBreadcrumbHtml,
  append,
  attrOf,
  css,
  cssValue,
  dataId,
  escapeId,
  fadeOut,
  hide,
  html,
  on,
  ready,
  remove,
  removeClass,
  setVal,
  show,
  text,
  val,
} from "../../../default/js/vendor/dom";
import { ajax, AjaxError } from "../../../default/js/vendor/ajax";
import { colorbox } from "../../../default/js/vendor/colorbox";
import { confirm } from "../../../default/js/vendor/jconfirm";
import { pwgDatepicker } from "../../../default/js/vendor/datepicker";

// Real shape confirmed via BatchManagerUnitPageRenderer.php's own
// `$related_category_ids[] = $item_category_id; ... json_encode(...)`
// -- a plain array of category ids per picture id, not wrapped in any
// object.
type RelatedCategoryIds = Record<string, (string | number)[]>;

interface PluginValueEntry {
  selector: string;
  api_key: string;
}

interface ImageUpdateBody {
  name?: string | undefined;
  author?: string | undefined;
  dateCreation?: string | undefined;
  comment?: string | undefined;
  categories?: string;
  tagIds?: string;
  level?: number;
  singleValueMode?: string;
  multipleValueMode?: string;
  // Plugin extension fields (Skeleton extension's own
  // `pluginValues`-driven save method), genuinely dynamic per active
  // plugin -- see pluginValues below.
  [key: string]: unknown;
}

// `addRelatedCategory` is declared here too, independently of the
// same-named functions in mcs.js/cat_modify.ts/photos_add_direct.js/
// picture_modify.ts (docs/PLAN.md P46-B's own finding) -- safe since
// these pages never co-load.
const activePlugins = pwg_getPageData<string[]>("active_plugins");

const allRelatedCategoriesIds = pwg_getPageData<RelatedCategoryIds>(
  "all_related_categories_ids",
);

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

const categoriesCache = new CategoriesCache({
  serverKey: pwg_getPageData<string>("cache_key_categories"),
  serverId: pwg_getPageData<string>("cache_key_hash"),
  rootUrl: pwg_getPageData<string>("root_url"),
});

const associatedCategories = pwg_getPageData<Record<string, unknown>>(
  "associated_categories",
);

categoriesCache.selectize(
  document.querySelectorAll("[data-selectize=categories]"),
  {
    filter: function (
      this: { name?: string },
      categories: { id: string | number; [key: string]: unknown }[],
      options: { default?: string | number | undefined },
    ) {
      if (this.name === "dissociate") {
        const filtered = categories.filter((cat) =>
          Boolean(associatedCategories[cat.id]),
        );

        if (filtered.length > 0) {
          options.default = filtered[0]!.id;
        }

        return filtered;
      } else {
        return categories;
      }
    },
  },
);

// onLoad needed to wait localization loads
ready(function () {
  pwgDatepicker(document.querySelectorAll("[data-datepicker]"), {
    showTimepicker: true,
    cancelButton: pwg_getPageString("Cancel"),
    jqueryCode: pwg_getPageData<string | undefined>("jquery_code"),
  });
});

colorbox(document.querySelectorAll("a.preview-box"), { photo: true });

const strAreYouSure = pwg_getPageString("Are you sure?");
const strYes = pwg_getPageString("Yes, delete");
const strNo = pwg_getPageString("No, I have changed my mind");
const strOrphan = pwg_getPageString("This photo is an orphan");
const strMetaWarning = pwg_getPageString(
  "Warning ! Unsaved changes will be lost",
);
const strMetaYes = pwg_getPageString("I want to continue");
const strTitleAb = pwg_getPageString("Associate to album");

let bCurrentPictureId: number | undefined;
// Check Skeleton extension for more details about extensibility
const pluginValues: PluginValueEntry[] = [];

ready(function () {
  // Detect unsaved changes on any inputs
  let userInteracted = false;

  on(document.querySelectorAll("input, textarea, select"), "focus", () => {
    userInteracted = true;
  });

  on(
    document.querySelectorAll("input, textarea"),
    "input",
    function (this: Element) {
      // This selector is page-wide, unlike every other handler below --
      // it also matches inputs with no enclosing fieldset at all (the
      // album selector popup's own #search-input-ab), where jQuery's
      // `.parents("fieldset").data(...)` quietly returned `undefined`
      // from an empty set. `closest()` returns `null` there instead, and
      // dom.ts's `data()` throws on a null element rather than silently
      // matching jQuery's tolerance -- so this one call site needs its
      // own guard that the others (all scoped to per-photo classes that
      // only ever render inside a fieldset) don't.
      const fieldset = this.closest("fieldset");
      if (fieldset === null) {
        return;
      }
      const pictureId = dataId(fieldset, "image_id");
      if (userInteracted) {
        showUnsavedLocalBadge(pictureId);
      }
    },
  );

  // Specific handler for datepicker inputs: `vendor/datepicker.ts`'s
  // own native port dispatches a real native "change" event on the
  // visible field (matching the original's own real
  // `this.$input.trigger("change")`), so a native listener sees it.
  on(
    document.querySelectorAll("input[data-datepicker]"),
    "change",
    function (this: Element) {
      const pictureId = dataId(this.closest("fieldset")!, "image_id");
      if (userInteracted) {
        showUnsavedLocalBadge(pictureId);
      }
    },
  );

  // `vendor/selectize.ts`'s own `triggerChange()` now dispatches a real
  // native "change" event on the original (hidden) <select> (P49-B group
  // 6), so a native listener sees it just like it always did for every
  // plain, non-selectized <select> on the page.
  on(document.querySelectorAll("select"), "change", function (this: Element) {
    const pictureId = dataId(this.closest("fieldset")!, "image_id");
    if (userInteracted) {
      showUnsavedLocalBadge(pictureId);
    }
  });

  on(
    document.querySelectorAll(
      ".related-categories-container .remove-item, .datepickerDelete",
    ),
    "click",
    function (this: Element) {
      userInteracted = true;
      const fieldset = this.closest("fieldset")!;
      const pictureId = dataId(fieldset, "image_id");
      showUnsavedLocalBadge(pictureId);
    },
  );

  // METADATA SYNC
  on(
    document.querySelectorAll(".action-sync-metadata"),
    "click",
    function (this: Element) {
      const fieldset = this.closest("fieldset")!;
      const pictureId = dataId(fieldset, "image_id");
      confirm({
        title: strMetaWarning,
        titleClass: "metadataSyncConfirm",
        content: "",
        boxWidth: "30%",
        type: "red",
        buttons: {
          confirm: {
            text: strMetaYes,
            btnClass: "btn-red",
            action: async function () {
              disableLocalButton(pictureId);
              try {
                await ajax({
                  type: "POST",
                  url: "api/v1/images/actions/sync-metadata",
                  json: {
                    imageIds: [pictureId],
                  },
                  headers: {
                    "X-CSRF-Token": String(
                      val(document.querySelectorAll("input[name=pwg_token]")),
                    ),
                  },
                  dataType: "json",
                });

                void updateBlock(pictureId);
              } catch (e) {
                console.error(e instanceof AjaxError ? e.responseText : e);
                showErrorLocalBadge(pictureId);
                enableLocalButton(pictureId);
              }
            },
          },
          cancel: {
            text: strNo,
          },
        },
      });
    },
  );
  // DELETE
  on(
    document.querySelectorAll(".action-delete-picture"),
    "click",
    function (this: Element) {
      const fieldset = this.closest("fieldset")!;
      const pictureId = dataId(fieldset, "image_id");
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
            action: async function () {
              const imageIds = [pictureId];
              try {
                await ajax({
                  type: "POST",
                  url: "api/v1/images/actions/delete",
                  json: {
                    imageIds: imageIds.map(Number),
                  },
                  headers: {
                    "X-CSRF-Token": String(
                      val(document.querySelectorAll("input[name=pwg_token]")),
                    ),
                  },
                  dataType: "json",
                });

                remove(fieldset);
                css(document.querySelectorAll(".pagination-container"), {
                  "pointer-events": "none",
                  opacity: "0.5",
                });
                css(
                  document.querySelectorAll(".button-reload"),
                  "display",
                  "block",
                );
                css(
                  document.querySelectorAll(
                    'div[data-image_id="' + String(pictureId) + '"]',
                  ),
                  "display",
                  "flex",
                );
              } catch (e) {
                console.error(e instanceof AjaxError ? e.responseText : e);
                showErrorLocalBadge(pictureId);
              }
            },
          },
          cancel: {
            text: strNo,
          },
        },
      });
    },
  );
  // VALIDATION
  //Unit Save
  on(
    document.querySelectorAll(".action-save-picture"),
    "click",
    // eslint-disable-next-line @typescript-eslint/no-misused-promises -- fire-and-forget async click handler, same as the original .js: dom.ts's on() doesn't await a handler's return value either way.
    async function (this: Element) {
      const fieldset = this.closest("fieldset")!;
      const pictureId = dataId(fieldset, "image_id");
      await saveChanges(pictureId);
    },
  );
  //Global Save
  on(document.querySelectorAll(".action-save-global"), "click", function () {
    void saveAllChanges();
  });
  //Categories
  const ab = new AlbumSelector({
    selectedCategoriesIds: [],
    selectAlbum: addRelatedCategory,
    adminMode: true,
    modalTitle: strTitleAb,
  });
  on(
    document.querySelectorAll(".linked-albums.add-item"),
    "click",
    function (this: Element) {
      const fieldset = this.closest("fieldset")!;
      bCurrentPictureId = dataId(fieldset, "image_id");
      ab.hardUpdate(allRelatedCategoriesIds[bCurrentPictureId] ?? []);
      ab.open();
    },
  );
  on(
    document.querySelectorAll(".related-categories-container"),
    "click",
    (e: Event) => {
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- a real click inside the document always targets an Element (or null), never a bare EventTarget with no Element interface.
      const eventTarget = e.target as Element;
      if (eventTarget.classList.contains("remove-item")) {
        const catId = attrOf(eventTarget, "id")!;
        const fieldset = eventTarget.closest("fieldset")!;
        const pictureId = dataId(fieldset, "image_id");

        removeSelectedCategory(catId, pictureId);
        checkRelatedCategories(
          pictureId,
          allRelatedCategoriesIds[pictureId] ?? [],
        );
      }
    },
  );
  pluginFunctionMapInit();
});

// Stale comment corrected (P51-D): this has a real caller (the
// ".related-categories-container" click handler above, on a real
// ".remove-item" click) -- not dead code. Its own former
// `.find(c => c.id == pictureId)?.catIds` logic never matched
// allRelatedCategoriesIds's real shape anyway (a plain
// picture-id-keyed object of category-id arrays, not an array of
// `{id, catIds}` objects -- see RelatedCategoryIds above); already
// fixed to the real access pattern used by every other function in
// this file.
function removeSelectedCategory(catId: string | number, pictureId: number) {
  const catToRemoveIndex = allRelatedCategoriesIds[pictureId]!.indexOf(catId);
  if (catToRemoveIndex > -1) {
    allRelatedCategoriesIds[pictureId]!.splice(catToRemoveIndex, 1);
    showUnsavedLocalBadge(pictureId);
  }

  document
    .querySelector("#" + escapeId(pictureId) + " #" + escapeId(catId))
    ?.parentElement?.remove();
}

function addRelatedCategory({
  album,
  levelSeparator,
  getSelectedAlbum,
  addSelectedAlbum,
}: AlbumSelectorCallbackArgs) {
  if (!getSelectedAlbum().includes(album.id)) {
    append(
      document.querySelectorAll(
        "#" + escapeId(bCurrentPictureId!) + " .related-categories-container",
      ),
      `<div class="breadcrumb-item album-listed">
        <span class="link-path">${albumBreadcrumbHtml(album.breadcrumb, levelSeparator)}</span><span id="${album.id}" class="icon-cancel-circled remove-item"></span>
      </div>`,
    );

    showUnsavedLocalBadge(bCurrentPictureId!);
    addSelectedAlbum();
    // Genuine pre-existing bug found via strict typing: this assigned
    // to a `.catIds` property that doesn't exist on the real value
    // (a plain array, not `{catIds: [...]}` -- see RelatedCategoryIds
    // above), silently attaching a stray property to the array object
    // while leaving its actual elements (what every other reader here
    // -- removeSelectedCategory, the "reopen picker" hardUpdate call,
    // and saveChanges's own `.join(";")` -- indexes/mutates/reads
    // directly) untouched. Newly-added albums were therefore never
    // actually reflected in the tracked state: lost on save, and absent
    // when the picker was reopened. Fixed to a real replacement.
    allRelatedCategoriesIds[bCurrentPictureId!] = getSelectedAlbum();
  }
  checkRelatedCategories(bCurrentPictureId!, getSelectedAlbum());
}

function checkRelatedCategories(
  pictureId: number,
  selectedAlbum: (string | number)[],
) {
  html(
    document.querySelectorAll(
      "#" + escapeId("picture-" + String(pictureId)) + " .linked-albums-badge",
    ),
    String(selectedAlbum.length),
  );
  if (selectedAlbum.length === 0) {
    addClass(
      document.querySelectorAll(
        "#" + escapeId(pictureId) + " .linked-albums-badge",
      ),
      "badge-red",
    );
    addClass(
      document.querySelectorAll("#" + escapeId(pictureId) + " .add-item"),
      "highlight",
    );
    const orphan = document.querySelectorAll(
      "#" + escapeId(pictureId) + " .orphan-photo",
    );
    html(orphan, strOrphan);
    show(orphan);
  } else {
    removeClass(
      document.querySelectorAll(
        "#" + escapeId(pictureId) + " .linked-albums-badge.badge-red",
      ),
      "badge-red",
    );
    removeClass(
      document.querySelectorAll(
        "#" + escapeId(pictureId) + " .add-item.highlight",
      ),
      "highlight",
    );
    hide(
      document.querySelectorAll("#" + escapeId(pictureId) + " .orphan-photo"),
    );
  }
}

function updateUnsavedGlobalBadge() {
  const visibleLocalUnsavedCount = Array.from(
    document.querySelectorAll(".local-unsaved-badge"),
  ).filter((el) => cssValue(el, "display") === "block").length;
  if (visibleLocalUnsavedCount > 0) {
    css(document.querySelectorAll(".global-unsaved-badge"), "display", "block");
    text(
      document.querySelectorAll("#unsaved-count"),
      String(visibleLocalUnsavedCount),
    );
  } else {
    css(document.querySelectorAll(".global-unsaved-badge"), "display", "none");
    text(document.querySelectorAll("#unsaved-count"), "");
  }
}

function showUnsavedLocalBadge(pictureId: number) {
  hideSuccesLocalBadge(pictureId);
  hideErrorLocalBadge(pictureId);
  css(
    document.querySelectorAll(
      "#" + escapeId("picture-" + String(pictureId)) + " .local-unsaved-badge",
    ),
    "display",
    "block",
  );
  updateUnsavedGlobalBadge();
}

function hideUnsavedLocalBadge(pictureId: number) {
  css(
    document.querySelectorAll(
      "#" + escapeId("picture-" + String(pictureId)) + " .local-unsaved-badge",
    ),
    "display",
    "none",
  );
  updateUnsavedGlobalBadge();
}
// on(window, 'beforeunload', function() {
//   if (userInteracted) {
//     return "You have unsaved changes, are you sure you want to leave this page?";
//   }
// });
//Error badge
function showErrorLocalBadge(pictureId: number) {
  css(
    document.querySelectorAll(
      "#" + escapeId("picture-" + String(pictureId)) + " .local-error-badge",
    ),
    "display",
    "block",
  );
}

function hideErrorLocalBadge(pictureId: number) {
  css(
    document.querySelectorAll(
      "#" + escapeId("picture-" + String(pictureId)) + " .local-error-badge",
    ),
    "display",
    "none",
  );
}
//Succes badge
function updateSuccessGlobalBadge() {
  const visibleLocalSuccesCount = Array.from(
    document.querySelectorAll(".local-success-badge"),
  ).filter((el) => cssValue(el, "display") === "block").length;
  if (visibleLocalSuccesCount > 0) {
    showSuccesGlobalBadge();
  } else {
    hideSuccesGlobalBadge();
  }
}

function showSuccessLocalBadge(pictureId: number) {
  const badge = document.querySelectorAll(
    "#" + escapeId("picture-" + String(pictureId)) + " .local-success-badge",
  );
  css(badge, {
    display: "block",
    opacity: 1,
  });
  setTimeout(() => {
    fadeOut(badge, 1000, function () {
      css(badge, "display", "none");
    });
  }, 3000);
}

function hideSuccesLocalBadge(pictureId: number) {
  css(
    document.querySelectorAll(
      "#" + escapeId("picture-" + String(pictureId)) + " .local-success-badge",
    ),
    "display",
    "none",
  );
}

function showSuccesGlobalBadge() {
  const badge = document.querySelectorAll(".global-succes-badge");
  css(badge, {
    display: "block",
    opacity: 1,
  });
  setTimeout(() => {
    fadeOut(badge, 1000, function () {
      css(badge, "display", "none");
    });
  }, 3000);
}

function hideSuccesGlobalBadge() {
  // Pre-existing bug, left as-is: missing "." on a class selector that
  // targets nothing, same as the original jQuery -- not this phase's job.
  css(document.querySelectorAll("global-succes-badge"), "display", "none");
}

function showMetasyncSuccesBadge(pictureId: number) {
  const badge = document.querySelectorAll(
    "#" + escapeId("picture-" + String(pictureId)) + " .metasync-success",
  );
  css(badge, {
    display: "block",
    opacity: 1,
  });
  setTimeout(() => {
    fadeOut(badge, 1000, function () {
      css(badge, "display", "none");
    });
  }, 3000);
}

function disableLocalButton(pictureId: number) {
  addClass(
    document.querySelectorAll(
      "#" + escapeId("picture-" + String(pictureId)) + " .action-save-picture",
    ),
    "disabled",
  );
  const icon = document.querySelectorAll(
    "#" + escapeId("picture-" + String(pictureId)) + " .action-save-picture i",
  );
  removeClass(icon, "icon-floppy");
  addClass(icon, "icon-spin6 animate-spin");
  disableGlobalButton();
}

function enableLocalButton(pictureId: number) {
  removeClass(
    document.querySelectorAll(
      "#" + escapeId("picture-" + String(pictureId)) + " .action-save-picture",
    ),
    "disabled",
  );
  const icon = document.querySelectorAll(
    "#" + escapeId("picture-" + String(pictureId)) + " .action-save-picture i",
  );
  removeClass(icon, "icon-spin6 animate-spin");
  addClass(icon, "icon-floppy");
}

function disableGlobalButton() {
  addClass(document.querySelectorAll(".action-save-global"), "disabled");
  const icon = document.querySelectorAll(".action-save-global i");
  removeClass(icon, "icon-floppy");
  addClass(icon, "icon-spin6 animate-spin");
}

function enableGlobalButton() {
  removeClass(document.querySelectorAll(".action-save-global"), "disabled");
  const icon = document.querySelectorAll(".action-save-global i");
  removeClass(icon, "icon-spin6 animate-spin");
  addClass(icon, "icon-floppy");
}

async function saveChanges(pictureId: number) {
  const unsavedBadge = document.querySelector(
    "#" + escapeId("picture-" + String(pictureId)) + " .local-unsaved-badge",
  );
  if (unsavedBadge !== null && cssValue(unsavedBadge, "display") === "block") {
    disableLocalButton(pictureId);
    // Retrieve Infos
    const name = val(
      document.querySelectorAll(
        "#" + escapeId("picture-" + String(pictureId)) + " #name",
      ),
    );
    const author = val(
      document.querySelectorAll(
        "#" + escapeId("picture-" + String(pictureId)) + " #author",
      ),
    );
    const dateCreation = val(
      document.querySelectorAll(
        "#" + escapeId("picture-" + String(pictureId)) + " #dateCreation",
      ),
    );
    const comment = val(
      document.querySelectorAll(
        "#" + escapeId("picture-" + String(pictureId)) + " #description",
      ),
    );
    // `option:selected` is jQuery/Sizzle's own pseudo-selector, not real
    // CSS -- querySelectorAll throws on it. Reading the <select>'s own
    // `.value` gets the selected option's value directly.
    const level = document.querySelector<HTMLSelectElement>(
      "#" + escapeId("picture-" + String(pictureId)) + " #level",
    )?.value;
    // Get Categories
    const categories = allRelatedCategoriesIds[pictureId]!;
    const categoriesStr = categories.join(";");
    // Get Tags
    const tags: (string | number)[] = [];
    document
      .querySelectorAll<HTMLOptionElement>(
        "#" + escapeId("picture-" + String(pictureId)) + " #tags option",
      )
      .forEach((option) => {
        tags.push(option.value);
      });
    const tagsStr = tags.join(",");
    const ajaxData: ImageUpdateBody = {
      name: name,
      author: author,
      dateCreation: dateCreation,
      comment: comment,
      categories: categoriesStr,
      tagIds: tagsStr,
      level: Number(level),
      singleValueMode: "replace",
      multipleValueMode: "replace",
    };

    for (const key_index of pluginValues.keys()) {
      const pluginValuesSelector = pluginValues[key_index]!.selector;
      const pluginValuesValue = val(
        document.querySelectorAll(
          "#" +
            escapeId("picture-" + String(pictureId)) +
            " " +
            pluginValuesSelector,
        ),
      );
      ajaxData[pluginValues[key_index]!.api_key] = pluginValuesValue;
    }

    try {
      await ajax({
        url: "api/v1/images/" + String(pictureId),
        method: "PATCH",
        json: ajaxData,
        headers: {
          "X-CSRF-Token": String(
            val(document.querySelectorAll("input[name=pwg_token]")),
          ),
        },
        dataType: "json",
      });

      enableLocalButton(pictureId);
      enableGlobalButton();
      hideUnsavedLocalBadge(pictureId);
      showSuccessLocalBadge(pictureId);
      updateSuccessGlobalBadge();
      // Method 1 for extension's save (see Skeleton extension for more details)
      pluginSaveLoop(pictureId);
    } catch (e) {
      enableLocalButton(pictureId);
      enableGlobalButton();
      hideUnsavedLocalBadge(pictureId);
      showErrorLocalBadge(pictureId);
      updateSuccessGlobalBadge();
      console.error("Error:", e instanceof AjaxError ? e.responseText : e);
    }
  }
}

async function saveAllChanges() {
  const allField = Array.from(document.querySelectorAll("fieldset"));
  for (const field of allField) {
    const pictureId = dataId(field, "image_id");
    await saveChanges(pictureId);
  }
}
//PLUGINS SAVE METHOD
const pluginFunctionMap: Record<string, (pictureId: number) => void> = {};

function pluginFunctionMapInit() {
  activePlugins.forEach(function (pluginId) {
    const functionName = pluginId + "_batchManagerSave";
    // Genuinely dynamic third-party extension hook (Skeleton
    // extension's own convention: `<pluginId>_batchManagerSave`) --
    // no static type source for an arbitrary plugin-defined global.
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- genuinely dynamic third-party extension hook; window has no static index signature for an arbitrary plugin-defined global.
    const fn = (window as unknown as Record<string, unknown>)[functionName];
    if (typeof fn === "function") {
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- same dynamic extension hook: the Skeleton extension convention guarantees this real signature, but nothing statically enforces it.
      pluginFunctionMap[pluginId] = fn as (pictureId: number) => void;
    }
  });
}

function pluginSaveLoop(pictureId: number) {
  if (activePlugins.length === 0) {
    return;
  }
  activePlugins.forEach(function (pluginId) {
    const saveFunction = pluginFunctionMap[pluginId];
    if (typeof saveFunction === "function") {
      saveFunction(pictureId);
    }
  });
}
// UPDATE BLOCKS
async function updateBlock(pictureId: number): Promise<void> {
  try {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
    const response = (await ajax({
      url: "api/v1/images/" + String(pictureId),
      type: "GET",
      dataType: "json",
    })) as operations["imageGet"]["responses"][200]["content"]["application/json"];

    setVal(
      document.querySelectorAll(
        "#" + escapeId("picture-" + String(pictureId)) + " #name",
      ),
      response.name,
    );
    setVal(
      document.querySelectorAll(
        "#" + escapeId("picture-" + String(pictureId)) + " #author",
      ),
      response.author ?? "",
    );
    setVal(
      document.querySelectorAll(
        "#" + escapeId("picture-" + String(pictureId)) + " #dateCreation",
      ),
      response.dateCreation ?? "",
    ); //TODO
    setVal(
      document.querySelectorAll(
        "#" + escapeId("picture-" + String(pictureId)) + " #description",
      ),
      response.comment,
    );
    setVal(
      document.querySelectorAll(
        "#" + escapeId("picture-" + String(pictureId)) + " #level",
      ),
      String(response.level),
    );
    text(
      document.querySelectorAll(
        "#" + escapeId("picture-" + String(pictureId)) + " #filename",
      ),
      response.file,
    );
    text(
      document.querySelectorAll(
        "#" + escapeId("picture-" + String(pictureId)) + " #filesize",
      ),
      String(response.filesize ?? 0),
    );
    text(
      document.querySelectorAll(
        "#" + escapeId("picture-" + String(pictureId)) + " #dimensions",
      ),
      String(response.width ?? 0) + "x" + String(response.height ?? 0),
    );
    // updateTags(response.tags, pictureId); //Yet to be implemented (TODO)
    showMetasyncSuccesBadge(pictureId);
    enableLocalButton(pictureId);
    enableGlobalButton();
  } catch (e) {
    console.error("Error:", e instanceof AjaxError ? e.responseText : e);
    showErrorLocalBadge(pictureId);
    enableLocalButton(pictureId);
  }
}

pluginFunctionMapInit();

// TAGS UPDATE Yet to be implemented
// function updateTags(tagsData, pictureId) {
//   const $tagsUpdate = $('#tags-'+pictureId).selectize({
//     create: true,
//     persist: false
// });
//   const selectizeTags = $tagsUpdate[0].selectize;
//   const transformedData = tagsData.map(function(item) {
//       return {
//           value: item.id,
//           text: item.name
//       };
//   })
//   console.log(transformedData);
//   selectizeTags.clearOptions();
//   selectizeTags.addOption(transformedData);
//   selectizeTags.refreshOptions(true);
// };
