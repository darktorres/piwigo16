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
  data,
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
import { ajax } from "../../../default/js/vendor/ajax";
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

// `add_related_category` is declared here too, independently of the
// same-named functions in mcs.js/cat_modify.ts/photos_add_direct.js/
// picture_modify.ts (docs/PLAN.md P46-B's own finding) -- safe since
// these pages never co-load.
const activePlugins = pwg_getPageData<string[]>("active_plugins");

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

const associated_categories = pwg_getPageData<Record<string, unknown>>(
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
          Boolean(associated_categories[cat.id]),
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

const str_are_you_sure = pwg_getPageString("Are you sure?");
const str_yes = pwg_getPageString("Yes, delete");
const str_no = pwg_getPageString("No, I have changed my mind");
const str_orphan = pwg_getPageString("This photo is an orphan");
const str_meta_warning = pwg_getPageString(
  "Warning ! Unsaved changes will be lost",
);
const str_meta_yes = pwg_getPageString("I want to continue");
const str_title_ab = pwg_getPageString("Associate to album");

let b_current_picture_id: string | number | undefined;
// Check Skeleton extension for more details about extensibility
const pluginValues: PluginValueEntry[] = [];

ready(function () {
  // Detect unsaved changes on any inputs
  let user_interacted = false;

  on(document.querySelectorAll("input, textarea, select"), "focus", () => {
    user_interacted = true;
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
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- data() reads a data-* attribute this same app's own setData()/template writes, never adversarial input.
      const pictureId = data(fieldset, "image_id") as string | number;
      if (user_interacted) {
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
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- data() reads a data-* attribute this same app's own setData()/template writes, never adversarial input.
      const pictureId = data(this.closest("fieldset")!, "image_id") as
        string | number;
      if (user_interacted) {
        showUnsavedLocalBadge(pictureId);
      }
    },
  );

  // `vendor/selectize.ts`'s own `triggerChange()` now dispatches a real
  // native "change" event on the original (hidden) <select> (P49-B group
  // 6), so a native listener sees it just like it always did for every
  // plain, non-selectized <select> on the page.
  on(document.querySelectorAll("select"), "change", function (this: Element) {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- data() reads a data-* attribute this same app's own setData()/template writes, never adversarial input.
    const pictureId = data(this.closest("fieldset")!, "image_id") as
      string | number;
    if (user_interacted) {
      showUnsavedLocalBadge(pictureId);
    }
  });

  on(
    document.querySelectorAll(
      ".related-categories-container .remove-item, .datepickerDelete",
    ),
    "click",
    function (this: Element) {
      user_interacted = true;
      const fieldset = this.closest("fieldset")!;
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- data() reads a data-* attribute this same app's own setData()/template writes, never adversarial input.
      const pictureId = data(fieldset, "image_id") as string | number;
      showUnsavedLocalBadge(pictureId);
    },
  );

  // METADATA SYNC
  on(
    document.querySelectorAll(".action-sync-metadata"),
    "click",
    function (this: Element) {
      const fieldset = this.closest("fieldset")!;
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- data() reads a data-* attribute this same app's own setData()/template writes, never adversarial input.
      const pictureId = data(fieldset, "image_id") as string | number;
      confirm({
        title: str_meta_warning,
        titleClass: "metadataSyncConfirm",
        content: "",
        boxWidth: "30%",
        type: "red",
        buttons: {
          confirm: {
            text: str_meta_yes,
            btnClass: "btn-red",
            action: function () {
              disableLocalButton(pictureId);
              void ajax({
                type: "POST",
                url: "api/v1/images/actions/sync-metadata",
                contentType: "application/json",
                headers: {
                  "X-CSRF-Token": String(
                    val(document.querySelectorAll("input[name=pwg_token]")),
                  ),
                },
                data: JSON.stringify({
                  imageIds: [pictureId],
                }),
                dataType: "json",
                success: function (
                  _data: operations["imageSyncMetadata"]["responses"][200]["content"]["application/json"],
                ) {
                  updateBlock(pictureId);
                },
                error: function (_data) {
                  console.error("Error occurred");
                  showErrorLocalBadge(pictureId);
                  enableLocalButton(pictureId);
                },
              });
            },
          },
          cancel: {
            text: str_no,
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
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- data() reads a data-* attribute this same app's own setData()/template writes, never adversarial input.
      const pictureId = data(fieldset, "image_id") as string | number;
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
              const image_ids = [pictureId];
              (function (ids: (string | number)[]) {
                void ajax({
                  type: "POST",
                  url: "api/v1/images/actions/delete",
                  contentType: "application/json",
                  headers: {
                    "X-CSRF-Token": String(
                      val(document.querySelectorAll("input[name=pwg_token]")),
                    ),
                  },
                  data: JSON.stringify({
                    imageIds: ids.map(Number),
                  }),
                  dataType: "json",
                  success: function (
                    _data: operations["imageDelete"]["responses"][200]["content"]["application/json"],
                  ) {
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
                  },
                  error: function (_data) {
                    console.error("Error occurred");
                    showErrorLocalBadge(pictureId);
                  },
                });
              })(image_ids);
            },
          },
          cancel: {
            text: str_no,
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
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- data() reads a data-* attribute this same app's own setData()/template writes, never adversarial input.
      const pictureId = data(fieldset, "image_id") as string | number;
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
    selectAlbum: add_related_category,
    adminMode: true,
    modalTitle: str_title_ab,
  });
  on(
    document.querySelectorAll(".linked-albums.add-item"),
    "click",
    function (this: Element) {
      const fieldset = this.closest("fieldset")!;
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- data() reads a data-* attribute this same app's own setData()/template writes, never adversarial input.
      b_current_picture_id = data(fieldset, "image_id") as string | number;
      ab.hardUpdate(all_related_categories_ids[b_current_picture_id] ?? []);
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
        const cat_id = attrOf(eventTarget, "id")!;
        const fieldset = eventTarget.closest("fieldset")!;
        // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- data() reads a data-* attribute this same app's own setData()/template writes, never adversarial input.
        const picture_id = data(fieldset, "image_id") as string | number;

        remove_selected_category(cat_id, picture_id);
        check_related_categories(
          picture_id,
          all_related_categories_ids[picture_id] ?? [],
        );
      }
    },
  );
  pluginFunctionMapInit();
});

// Genuinely dead code (zero callers, confirmed via grep) whose own
// `.find(c => c.id == pictureId)?.cat_ids` logic never matched
// all_related_categories_ids's real shape anyway (a plain
// picture-id-keyed object of category-id arrays, not an array of
// `{id, cat_ids}` objects -- see RelatedCategoryIds above) -- fixed to
// the real access pattern used by every other function in this file
// rather than left uncompilable, since there's no real behavior to
// preserve here either way.
function remove_selected_category(
  cat_id: string | number,
  picture_id: string | number,
) {
  const cat_to_remove_index =
    all_related_categories_ids[picture_id]!.indexOf(cat_id);
  if (cat_to_remove_index > -1) {
    all_related_categories_ids[picture_id]!.splice(cat_to_remove_index, 1);
    showUnsavedLocalBadge(picture_id);
  }

  document
    .querySelector("#" + escapeId(picture_id) + " #" + escapeId(cat_id))
    ?.parentElement?.remove();
}

function add_related_category({
  album,
  levelSeparator,
  getSelectedAlbum,
  addSelectedAlbum,
}: AlbumSelectorCallbackArgs) {
  if (!getSelectedAlbum().includes(album.id)) {
    append(
      document.querySelectorAll(
        "#" +
          escapeId(b_current_picture_id!) +
          " .related-categories-container",
      ),
      `<div class="breadcrumb-item album-listed">
        <span class="link-path">${albumBreadcrumbHtml(album.breadcrumb, levelSeparator)}</span><span id="${album.id}" class="icon-cancel-circled remove-item"></span>
      </div>`,
    );

    showUnsavedLocalBadge(b_current_picture_id!);
    addSelectedAlbum();
    // Genuine pre-existing bug found via strict typing: this assigned
    // to a `.cat_ids` property that doesn't exist on the real value
    // (a plain array, not `{cat_ids: [...]}` -- see RelatedCategoryIds
    // above), silently attaching a stray property to the array object
    // while leaving its actual elements (what every other reader here
    // -- remove_selected_category, the "reopen picker" hardUpdate call,
    // and saveChanges's own `.join(";")` -- indexes/mutates/reads
    // directly) untouched. Newly-added albums were therefore never
    // actually reflected in the tracked state: lost on save, and absent
    // when the picker was reopened. Fixed to a real replacement.
    all_related_categories_ids[b_current_picture_id!] = getSelectedAlbum();
  }
  check_related_categories(b_current_picture_id!, getSelectedAlbum());
}

function check_related_categories(
  pictureId: string | number,
  selectedAlbum: (string | number)[],
) {
  html(
    document.querySelectorAll(
      "#picture-" + String(pictureId) + " .linked-albums-badge",
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
    html(orphan, str_orphan);
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

function showUnsavedLocalBadge(pictureId: string | number) {
  hideSuccesLocalBadge(pictureId);
  hideErrorLocalBadge(pictureId);
  css(
    document.querySelectorAll(
      "#picture-" + String(pictureId) + " .local-unsaved-badge",
    ),
    "display",
    "block",
  );
  updateUnsavedGlobalBadge();
}

function hideUnsavedLocalBadge(pictureId: string | number) {
  css(
    document.querySelectorAll(
      "#picture-" + String(pictureId) + " .local-unsaved-badge",
    ),
    "display",
    "none",
  );
  updateUnsavedGlobalBadge();
}
// on(window, 'beforeunload', function() {
//   if (user_interacted) {
//     return "You have unsaved changes, are you sure you want to leave this page?";
//   }
// });
//Error badge
function showErrorLocalBadge(pictureId: string | number) {
  css(
    document.querySelectorAll(
      "#picture-" + String(pictureId) + " .local-error-badge",
    ),
    "display",
    "block",
  );
}

function hideErrorLocalBadge(pictureId: string | number) {
  css(
    document.querySelectorAll(
      "#picture-" + String(pictureId) + " .local-error-badge",
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

function showSuccessLocalBadge(pictureId: string | number) {
  const badge = document.querySelectorAll(
    "#picture-" + String(pictureId) + " .local-success-badge",
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

function hideSuccesLocalBadge(pictureId: string | number) {
  css(
    document.querySelectorAll(
      "#picture-" + String(pictureId) + " .local-success-badge",
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

function showMetasyncSuccesBadge(pictureId: string | number) {
  const badge = document.querySelectorAll(
    "#picture-" + String(pictureId) + " .metasync-success",
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

function disableLocalButton(pictureId: string | number) {
  addClass(
    document.querySelectorAll(
      "#picture-" + String(pictureId) + " .action-save-picture",
    ),
    "disabled",
  );
  const icon = document.querySelectorAll(
    "#picture-" + String(pictureId) + " .action-save-picture i",
  );
  removeClass(icon, "icon-floppy");
  addClass(icon, "icon-spin6 animate-spin");
  disableGlobalButton();
}

function enableLocalButton(pictureId: string | number) {
  removeClass(
    document.querySelectorAll(
      "#picture-" + String(pictureId) + " .action-save-picture",
    ),
    "disabled",
  );
  const icon = document.querySelectorAll(
    "#picture-" + String(pictureId) + " .action-save-picture i",
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

async function saveChanges(pictureId: string | number) {
  const unsavedBadge = document.querySelector(
    "#picture-" + String(pictureId) + " .local-unsaved-badge",
  );
  if (unsavedBadge !== null && cssValue(unsavedBadge, "display") === "block") {
    disableLocalButton(pictureId);
    // Retrieve Infos
    const name = val(
      document.querySelectorAll("#picture-" + String(pictureId) + " #name"),
    );
    const author = val(
      document.querySelectorAll("#picture-" + String(pictureId) + " #author"),
    );
    const date_creation = val(
      document.querySelectorAll(
        "#picture-" + String(pictureId) + " #date_creation",
      ),
    );
    const comment = val(
      document.querySelectorAll(
        "#picture-" + String(pictureId) + " #description",
      ),
    );
    // `option:selected` is jQuery/Sizzle's own pseudo-selector, not real
    // CSS -- querySelectorAll throws on it. Reading the <select>'s own
    // `.value` gets the selected option's value directly.
    const level = document.querySelector<HTMLSelectElement>(
      "#picture-" + String(pictureId) + " #level",
    )?.value;
    // Get Categories
    const categories = all_related_categories_ids[pictureId]!;
    const categoriesStr = categories.join(";");
    // Get Tags
    const tags: (string | number)[] = [];
    document
      .querySelectorAll<HTMLOptionElement>(
        "#picture-" + String(pictureId) + " #tags option",
      )
      .forEach((option) => {
        tags.push(option.value);
      });
    const tagsStr = tags.join(",");
    const ajax_data: ImageUpdateBody = {
      name: name,
      author: author,
      dateCreation: date_creation,
      comment: comment,
      categories: categoriesStr,
      tagIds: tagsStr,
      level: Number(level),
      singleValueMode: "replace",
      multipleValueMode: "replace",
    };

    for (const key_index of pluginValues.keys()) {
      const pluginValues_selector = pluginValues[key_index]!.selector;
      const pluginValues_value = val(
        document.querySelectorAll(
          "#picture-" + String(pictureId) + " " + pluginValues_selector,
        ),
      );
      ajax_data[pluginValues[key_index]!.api_key] = pluginValues_value;
    }

    await ajax({
      url: "api/v1/images/" + String(pictureId),
      method: "PATCH",
      contentType: "application/json",
      headers: {
        "X-CSRF-Token": String(
          val(document.querySelectorAll("input[name=pwg_token]")),
        ),
      },
      dataType: "json",
      data: JSON.stringify(ajax_data),
      success: function (
        _data: operations["imageUpdate"]["responses"][200]["content"]["application/json"],
      ) {
        enableLocalButton(pictureId);
        enableGlobalButton();
        hideUnsavedLocalBadge(pictureId);
        showSuccessLocalBadge(pictureId);
        updateSuccessGlobalBadge();
        // Method 1 for extension's save (see Skeleton extension for more details)
        pluginSaveLoop(pictureId);
      },
      error: function (_xhr, _status: string, error: string) {
        enableLocalButton(pictureId);
        enableGlobalButton();
        hideUnsavedLocalBadge(pictureId);
        showErrorLocalBadge(pictureId);
        updateSuccessGlobalBadge();
        console.error("Error:", error);
      },
    });
  }
}

async function saveAllChanges() {
  const allField = Array.from(document.querySelectorAll("fieldset"));
  for (const field of allField) {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- data() reads a data-* attribute this same app's own setData()/template writes, never adversarial input.
    const pictureId = data(field, "image_id") as string | number;
    await saveChanges(pictureId);
  }
}
//PLUGINS SAVE METHOD
const pluginFunctionMap: Record<string, (pictureId: string | number) => void> =
  {};

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
      pluginFunctionMap[pluginId] = fn as (pictureId: string | number) => void;
    }
  });
}

function pluginSaveLoop(pictureId: string | number) {
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
function updateBlock(pictureId: string | number) {
  void ajax({
    url: "api/v1/images/" + String(pictureId),
    type: "GET",
    dataType: "json",
    success: function (
      response: operations["imageGet"]["responses"][200]["content"]["application/json"],
    ) {
      setVal(
        document.querySelectorAll("#picture-" + String(pictureId) + " #name"),
        response.name,
      );
      setVal(
        document.querySelectorAll("#picture-" + String(pictureId) + " #author"),
        response.author ?? "",
      );
      setVal(
        document.querySelectorAll(
          "#picture-" + String(pictureId) + " #date_creation",
        ),
        response.dateCreation ?? "",
      ); //TODO
      setVal(
        document.querySelectorAll(
          "#picture-" + String(pictureId) + " #description",
        ),
        response.comment,
      );
      setVal(
        document.querySelectorAll("#picture-" + String(pictureId) + " #level"),
        String(response.level),
      );
      text(
        document.querySelectorAll(
          "#picture-" + String(pictureId) + " #filename",
        ),
        response.file,
      );
      text(
        document.querySelectorAll(
          "#picture-" + String(pictureId) + " #filesize",
        ),
        String(response.filesize ?? 0),
      );
      text(
        document.querySelectorAll(
          "#picture-" + String(pictureId) + " #dimensions",
        ),
        String(response.width ?? 0) + "x" + String(response.height ?? 0),
      );
      // updateTags(response.tags, pictureId); //Yet to be implemented (TODO)
      showMetasyncSuccesBadge(pictureId);
      enableLocalButton(pictureId);
      enableGlobalButton();
    },
    error: function (_xhr, status: string, error: string) {
      console.error("Error:", status, error);
      showErrorLocalBadge(pictureId);
      enableLocalButton(pictureId);
    },
  });
}

const all_related_categories_ids = pwg_getPageData<RelatedCategoryIds>(
  "all_related_categories_ids",
);
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
