import type { operations } from "../../../../openapi/client/schema";
// Real consumer of album_selector.ts's own top-level `class
// AlbumSelector` (docs/PLAN.md P48 -- was a bare ambient-global read,
// see that file's own leading comment for the full real-consumer
// list, including the real, accepted "2 independent class copies on
// this page" consequence of batchManagerFilter.ts's own separate
// `?dup` import). `?dup` since album_selector.ts has several real
// registrant pages (Design §4).
import { AlbumSelector } from "./album_selector";
import { CategoriesCache, TagsCache } from "./LocalStorageCache";

import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../default/js/page-data";
export {};

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
  name?: string;
  author?: string;
  dateCreation?: string;
  comment?: string;
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
tagsCache.selectize(jQuery("[data-selectize=tags]"), {
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

categoriesCache.selectize(jQuery("[data-selectize=categories]"), {
  filter: function (
    this: { name?: string },
    categories: { id: string | number }[],
    options: { default?: string | number },
  ) {
    if (this.name === "dissociate") {
      const filtered = jQuery.grep(categories, function (cat) {
        return Boolean(associated_categories[cat.id]);
      });

      if (filtered.length > 0) {
        options.default = filtered[0]!.id;
      }

      return filtered;
    } else {
      return categories;
    }
  },
});

// onLoad needed to wait localization loads
jQuery(function () {
  jQuery("[data-datepicker]").pwgDatepicker({
    showTimepicker: true,
    cancelButton: pwg_getPageString("Cancel"),
  });
});

jQuery("a.preview-box").colorbox({
  photo: true,
});

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

$(document).ready(function () {
  // Detect unsaved changes on any inputs
  let user_interacted = false;

  $("input, textarea, select").on("focus", function () {
    user_interacted = true;
  });

  $("input, textarea").on("input", function () {
    const pictureId = $(this).parents("fieldset").data("image_id") as
      string | number;
    if (user_interacted == true) {
      showUnsavedLocalBadge(pictureId);
    }
  });

  // Specific handler for datepicker inputs
  $("input[data-datepicker]").on("change", function () {
    const pictureId = $(this).parents("fieldset").data("image_id") as
      string | number;
    if (user_interacted == true) {
      showUnsavedLocalBadge(pictureId);
    }
  });

  $("select").on("change", function () {
    const pictureId = $(this).parents("fieldset").data("image_id") as
      string | number;
    if (user_interacted == true) {
      showUnsavedLocalBadge(pictureId);
    }
  });

  $(".related-categories-container .remove-item, .datepickerDelete").on(
    "click",
    function () {
      user_interacted = true;
      const pictureId = $(this).parents("fieldset").data("image_id") as
        string | number;
      showUnsavedLocalBadge(pictureId);
    },
  );

  // METADATA SYNC
  $(".action-sync-metadata").on("click", function (_event) {
    const pictureId = $(this).parents("fieldset").data("image_id") as
      string | number;
    $.confirm({
      title: str_meta_warning,
      draggable: false,
      titleClass: "metadataSyncConfirm",
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
          text: str_meta_yes,
          btnClass: "btn-red",
          action: function () {
            disableLocalButton(pictureId);
            $.ajax({
              type: "POST",
              url: "api/v1/images/actions/sync-metadata",
              contentType: "application/json",
              headers: {
                "X-CSRF-Token": String(jQuery("input[name=pwg_token]").val()),
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
              error: function (_data: JQuery.jqXHR) {
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
  });
  // DELETE
  $(".action-delete-picture").on("click", function (_event) {
    const $fieldset = $(this).parents("fieldset");
    const pictureId = $fieldset.data("image_id") as string | number;
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
            const image_ids = [pictureId];
            (function (ids: (string | number)[]) {
              $.ajax({
                type: "POST",
                url: "api/v1/images/actions/delete",
                contentType: "application/json",
                headers: {
                  "X-CSRF-Token": String(jQuery("input[name=pwg_token]").val()),
                },
                data: JSON.stringify({
                  imageIds: ids.map(Number),
                }),
                dataType: "json",
                success: function (
                  _data: operations["imageDelete"]["responses"][200]["content"]["application/json"],
                ) {
                  $fieldset.remove();
                  $(".pagination-container").css({
                    "pointer-events": "none",
                    opacity: "0.5",
                  });
                  $(".button-reload").css("display", "block");
                  $('div[data-image_id="' + pictureId + '"]').css(
                    "display",
                    "flex",
                  );
                },
                error: function (_data: JQuery.jqXHR) {
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
  });
  // VALIDATION
  //Unit Save
  // eslint-disable-next-line @typescript-eslint/no-misused-promises -- fire-and-forget async click handler, same as the original .js: jQuery's .on() doesn't await a handler's return value either way.
  $(".action-save-picture").on("click", async function (_event) {
    const $fieldset = $(this).parents("fieldset");
    const pictureId = $fieldset.data("image_id") as string | number;
    await saveChanges(pictureId);
  });
  //Global Save
  $(".action-save-global").on("click", function (_event) {
    void saveAllChanges();
  });
  //Categories
  const ab = new AlbumSelector({
    selectedCategoriesIds: [],
    selectAlbum: add_related_category,
    adminMode: true,
    modalTitle: str_title_ab,
  });
  $(".linked-albums.add-item").on("click", function () {
    b_current_picture_id = $(this).parents("fieldset").data("image_id") as
      string | number;
    ab.hardUpdate(all_related_categories_ids[b_current_picture_id] ?? []);
    ab.open();
  });
  $(".related-categories-container").on("click", (e) => {
    if (e.target.classList.contains("remove-item")) {
      const cat_id = $(e.target).attr("id")!;
      const picture_id = $(e.target).parents("fieldset").data("image_id") as
        string | number;

      remove_selected_category(cat_id, picture_id);
      check_related_categories(
        picture_id,
        all_related_categories_ids[picture_id] ?? [],
      );
    }
  });
  pluginFunctionMapInit(activePlugins);
});

// Genuinely dead code (zero callers, confirmed via grep) whose own
// `.find(c => c.id == pictureId)?.cat_ids` logic never matched
// all_related_categories_ids's real shape anyway (a plain
// picture-id-keyed object of category-id arrays, not an array of
// `{id, cat_ids}` objects -- see RelatedCategoryIds above) -- fixed to
// the real access pattern used by every other function in this file
// rather than left uncompilable, since there's no real behavior to
// preserve here either way.
function get_related_category(pictureId: string | number) {
  return all_related_categories_ids[String(pictureId)] ?? [];
}

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

  $("#" + picture_id + " #" + cat_id)
    .parent()
    .remove();
}

function add_related_category({
  album,
  getSelectedAlbum,
  addSelectedAlbum,
}: AlbumSelectorCallbackArgs) {
  if (!getSelectedAlbum().includes(album.id)) {
    $("#" + b_current_picture_id + " .related-categories-container").append(
      `<div class="breadcrumb-item album-listed">
        <span class="link-path">${album.full_name_with_admin_links}</span><span id="${album.id}" class="icon-cancel-circled remove-item"></span>
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
  $("#picture-" + pictureId + " .linked-albums-badge").html(
    String(selectedAlbum.length),
  );
  if (selectedAlbum.length == 0) {
    $("#" + pictureId + " .linked-albums-badge").addClass("badge-red");
    $("#" + pictureId + " .add-item").addClass("highlight");
    $("#" + pictureId + " .orphan-photo")
      .html(str_orphan)
      .show();
  } else {
    $("#" + pictureId + " .linked-albums-badge.badge-red").removeClass(
      "badge-red",
    );
    $("#" + pictureId + " .add-item.highlight").removeClass("highlight");
    $("#" + pictureId + " .orphan-photo").hide();
  }
}

function updateUnsavedGlobalBadge() {
  const visibleLocalUnsavedCount = $(".local-unsaved-badge").filter(
    function () {
      return $(this).css("display") === "block";
    },
  ).length;
  if (visibleLocalUnsavedCount > 0) {
    $(".global-unsaved-badge").css("display", "block");
    $("#unsaved-count").text(String(visibleLocalUnsavedCount));
  } else {
    $(".global-unsaved-badge").css("display", "none");
    $("#unsaved-count").text("");
  }
}

function showUnsavedLocalBadge(pictureId: string | number) {
  hideSuccesLocalBadge(pictureId);
  hideErrorLocalBadge(pictureId);
  $("#picture-" + pictureId + " .local-unsaved-badge").css("display", "block");
  updateUnsavedGlobalBadge();
}

function hideUnsavedLocalBadge(pictureId: string | number) {
  $("#picture-" + pictureId + " .local-unsaved-badge").css("display", "none");
  updateUnsavedGlobalBadge();
}
// $(window).on('beforeunload', function() {
//   if (user_interacted) {
//     return "You have unsaved changes, are you sure you want to leave this page?";
//   }
// });
//Error badge
function showErrorLocalBadge(pictureId: string | number) {
  $("#picture-" + pictureId + " .local-error-badge").css("display", "block");
}

function hideErrorLocalBadge(pictureId: string | number) {
  $("#picture-" + pictureId + " .local-error-badge").css("display", "none");
}
//Succes badge
function updateSuccessGlobalBadge() {
  const visibleLocalSuccesCount = $(".local-success-badge").filter(function () {
    return $(this).css("display") === "block";
  }).length;
  if (visibleLocalSuccesCount > 0) {
    showSuccesGlobalBadge();
  } else {
    hideSuccesGlobalBadge();
  }
}

function showSuccessLocalBadge(pictureId: string | number) {
  const badge = $("#picture-" + pictureId + " .local-success-badge");
  badge.css({
    display: "block",
    opacity: 1,
  });
  setTimeout(() => {
    badge.fadeOut(1000, function () {
      badge.css("display", "none");
    });
  }, 3000);
}

function hideSuccesLocalBadge(pictureId: string | number) {
  $("#picture-" + pictureId + " .local-success-badge").css("display", "none");
}

function showSuccesGlobalBadge() {
  const badge = $(".global-succes-badge");
  badge.css({
    display: "block",
    opacity: 1,
  });
  setTimeout(() => {
    badge.fadeOut(1000, function () {
      badge.css("display", "none");
    });
  }, 3000);
}

function hideSuccesGlobalBadge() {
  $("global-succes-badge").css("display", "none");
}

function showMetasyncSuccesBadge(pictureId: string | number) {
  const badge = $("#picture-" + pictureId + " .metasync-success");
  badge.css({
    display: "block",
    opacity: 1,
  });
  setTimeout(() => {
    badge.fadeOut(1000, function () {
      badge.css("display", "none");
    });
  }, 3000);
}

function disableLocalButton(pictureId: string | number) {
  $("#picture-" + pictureId + " .action-save-picture").addClass("disabled");
  $("#picture-" + pictureId + " .action-save-picture i")
    .removeClass("icon-floppy")
    .addClass("icon-spin6 animate-spin");
  disableGlobalButton();
}

function enableLocalButton(pictureId: string | number) {
  $("#picture-" + pictureId + " .action-save-picture").removeClass("disabled");
  $("#picture-" + pictureId + " .action-save-picture i")
    .removeClass("icon-spin6 animate-spin")
    .addClass("icon-floppy");
}

function disableGlobalButton() {
  $(".action-save-global").addClass("disabled");
  $(".action-save-global i")
    .removeClass("icon-floppy")
    .addClass("icon-spin6 animate-spin");
}

function enableGlobalButton() {
  $(".action-save-global").removeClass("disabled");
  $(".action-save-global i")
    .removeClass("icon-spin6 animate-spin")
    .addClass("icon-floppy");
}

async function saveChanges(pictureId: string | number) {
  if (
    $("#picture-" + pictureId + " .local-unsaved-badge").css("display") ===
    "block"
  ) {
    disableLocalButton(pictureId);
    // Retrieve Infos
    const name = $("#picture-" + pictureId + " #name").val() as string;
    const author = $("#picture-" + pictureId + " #author").val() as string;
    const date_creation = $(
      "#picture-" + pictureId + " #date_creation",
    ).val() as string;
    const comment = $(
      "#picture-" + pictureId + " #description",
    ).val() as string;
    const level = $(
      "#picture-" + pictureId + " #level option:selected",
    ).val() as string;
    // Get Categories
    const categories = all_related_categories_ids[pictureId]!;
    const categoriesStr = categories.join(";");
    // Get Tags
    const tags: (string | number)[] = [];
    $("#picture-" + pictureId + " #tags option").each(function () {
      const tagId = $(this).val() as string;
      tags.push(tagId);
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
      const full_selector = $(
        "#picture-" + pictureId + " " + pluginValues_selector,
      );
      const pluginValues_value = full_selector.val();
      ajax_data[pluginValues[key_index]!.api_key] = pluginValues_value;
    }

    await $.ajax({
      url: "api/v1/images/" + pictureId,
      method: "PATCH",
      contentType: "application/json",
      headers: {
        "X-CSRF-Token": String(jQuery("input[name=pwg_token]").val()),
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
        pluginSaveLoop(activePlugins, pictureId);
      },
      error: function (xhr: JQuery.jqXHR, status: string, error: string) {
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
  const allField = $("fieldset").toArray();
  for (const field of allField) {
    const pictureId = $(field).data("image_id") as string | number;
    await saveChanges(pictureId);
  }
}
//PLUGINS SAVE METHOD
const pluginFunctionMap: Record<string, (pictureId: string | number) => void> =
  {};

function pluginFunctionMapInit(activePlugins: string[]) {
  activePlugins.forEach(function (pluginId) {
    const functionName = pluginId + "_batchManagerSave";
    // Genuinely dynamic third-party extension hook (Skeleton
    // extension's own convention: `<pluginId>_batchManagerSave`) --
    // no static type source for an arbitrary plugin-defined global.
    const fn = (window as unknown as Record<string, unknown>)[functionName];
    if (typeof fn === "function") {
      pluginFunctionMap[pluginId] = fn as (pictureId: string | number) => void;
    }
  });
}

function pluginSaveLoop(activePlugins: string[], pictureId: string | number) {
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
  $.ajax({
    url: "api/v1/images/" + pictureId,
    type: "GET",
    dataType: "json",
    success: function (
      response: operations["imageGet"]["responses"][200]["content"]["application/json"],
    ) {
      $("#picture-" + pictureId + " #name").val(response.name);
      $("#picture-" + pictureId + " #author").val(response.author ?? "");
      $("#picture-" + pictureId + " #date_creation").val(
        response.dateCreation ?? "",
      ); //TODO
      $("#picture-" + pictureId + " #description").val(response.comment);
      $("#picture-" + pictureId + " #level").val(response.level);
      $("#picture-" + pictureId + " #filename").text(response.file);
      $("#picture-" + pictureId + " #filesize").text(response.filesize ?? 0);
      $("#picture-" + pictureId + " #dimensions").text(
        (response.width ?? 0) + "x" + (response.height ?? 0),
      );
      // updateTags(response.tags, pictureId); //Yet to be implemented (TODO)
      showMetasyncSuccesBadge(pictureId);
      enableLocalButton(pictureId);
      enableGlobalButton();
    },
    error: function (xhr: JQuery.jqXHR, status: string, error: string) {
      console.error("Error:", status, error);
      showErrorLocalBadge(pictureId);
      enableLocalButton(pictureId);
    },
  });
}

const all_related_categories_ids = pwg_getPageData<RelatedCategoryIds>(
  "all_related_categories_ids",
);
pluginFunctionMapInit(activePlugins);

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
