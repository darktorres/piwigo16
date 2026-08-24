export {};

// Consumer of album_selector.ts's own real, top-level `class
// AlbumSelector` -- resolves directly via that file's own real
// declaration, same reasoning as every other AlbumSelector consumer
// this batch. `CategoriesCache`/`TagsCache` are different:
// LocalStorageCache.ts wraps them in its own real, pre-existing IIFE,
// so they're only reachable via `window.` -- same prefixing already
// used in batch_manager_global.ts/picture_modify.ts.
//
// `add_related_category` is declared here too, independently of the
// same-named functions in mcs.js/cat_modify.ts/photos_add_direct.js/
// picture_modify.ts (docs/PLAN.md P46-B's own finding) -- safe since
// these pages never co-load.
const activePlugins = pwg_getPageData("active_plugins");

const tagsCache = new window.TagsCache({
  serverKey: pwg_getPageData("cache_key_tags"),
  serverId: pwg_getPageData("cache_key_hash"),
  rootUrl: pwg_getPageData("root_url"),
});
tagsCache.selectize(jQuery("[data-selectize=tags]"), {
  lang: {
    Add: pwg_getPageString("Create"),
  },
});

const categoriesCache = new window.CategoriesCache({
  serverKey: pwg_getPageData("cache_key_categories"),
  serverId: pwg_getPageData("cache_key_hash"),
  rootUrl: pwg_getPageData("root_url"),
});

const associated_categories = pwg_getPageData("associated_categories");

categoriesCache.selectize(jQuery("[data-selectize=categories]"), {
  filter: function (this: any, categories: any[], options: any) {
    if (this.name === "dissociate") {
      const filtered = jQuery.grep(categories, function (cat: any) {
        return Boolean(associated_categories[cat.id]);
      });

      if (filtered.length > 0) {
        options.default = filtered[0].id;
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

let b_current_picture_id: any;
// Check Skeleton extension for more details about extensibility
const pluginValues: any[] = [];

$(document).ready(function () {
  // Detect unsaved changes on any inputs
  let user_interacted = false;

  $("input, textarea, select").on("focus", function () {
    user_interacted = true;
  });

  $("input, textarea").on("input", function () {
    const pictureId = $(this).parents("fieldset").data("image_id");
    if (user_interacted == true) {
      showUnsavedLocalBadge(pictureId);
    }
  });

  // Specific handler for datepicker inputs
  $("input[data-datepicker]").on("change", function () {
    const pictureId = $(this).parents("fieldset").data("image_id");
    if (user_interacted == true) {
      showUnsavedLocalBadge(pictureId);
    }
  });

  $("select").on("change", function () {
    const pictureId = $(this).parents("fieldset").data("image_id");
    if (user_interacted == true) {
      showUnsavedLocalBadge(pictureId);
    }
  });

  $(".related-categories-container .remove-item, .datepickerDelete").on(
    "click",
    function () {
      user_interacted = true;
      const pictureId = $(this).parents("fieldset").data("image_id");
      showUnsavedLocalBadge(pictureId);
    },
  );

  // METADATA SYNC
  $(".action-sync-metadata").on("click", function (_event) {
    const pictureId = $(this).parents("fieldset").data("image_id");
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
              success: function (_data: any) {
                updateBlock(pictureId);
              },
              error: function (_data: any) {
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
    const pictureId = $fieldset.data("image_id");
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
            (function (ids: any[]) {
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
                success: function (_data: any) {
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
                error: function (_data: any) {
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
    const pictureId = $fieldset.data("image_id");
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
    b_current_picture_id = $(this).parents("fieldset").data("image_id");
    ab.hardUpdate(all_related_categories_ids[b_current_picture_id]);
    ab.open();
  });
  $(".related-categories-container").on("click", (e) => {
    if (e.target.classList.contains("remove-item")) {
      const cat_id = $(e.target).attr("id");
      const picture_id = $(e.target).parents("fieldset").data("image_id");

      remove_selected_category(cat_id, picture_id);
      check_related_categories(
        picture_id,
        all_related_categories_ids[picture_id],
      );
    }
  });
  pluginFunctionMapInit(activePlugins);
});

function get_related_category(pictureId: any) {
  return (
    all_related_categories_ids.find((c: any) => c.id == pictureId)?.cat_ids ??
    []
  );
}

function remove_selected_category(cat_id: any, picture_id: any) {
  const cat_to_remove_index =
    all_related_categories_ids[picture_id].indexOf(cat_id);
  if (cat_to_remove_index > -1) {
    all_related_categories_ids[picture_id].splice(cat_to_remove_index, 1);
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
}: any) {
  if (!getSelectedAlbum().includes(album.id)) {
    $("#" + b_current_picture_id + " .related-categories-container").append(
      `<div class="breadcrumb-item album-listed">
        <span class="link-path">${album.full_name_with_admin_links}</span><span id="${album.id}" class="icon-cancel-circled remove-item"></span>
      </div>`,
    );

    showUnsavedLocalBadge(b_current_picture_id);
    addSelectedAlbum();
    all_related_categories_ids[b_current_picture_id].cat_ids =
      getSelectedAlbum();
  }
  check_related_categories(b_current_picture_id, getSelectedAlbum());
}

function check_related_categories(pictureId: any, selectedAlbum: any) {
  $("#picture-" + pictureId + " .linked-albums-badge").html(
    selectedAlbum.length,
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

function showUnsavedLocalBadge(pictureId: any) {
  hideSuccesLocalBadge(pictureId);
  hideErrorLocalBadge(pictureId);
  $("#picture-" + pictureId + " .local-unsaved-badge").css("display", "block");
  updateUnsavedGlobalBadge();
}

function hideUnsavedLocalBadge(pictureId: any) {
  $("#picture-" + pictureId + " .local-unsaved-badge").css("display", "none");
  updateUnsavedGlobalBadge();
}
// $(window).on('beforeunload', function() {
//   if (user_interacted) {
//     return "You have unsaved changes, are you sure you want to leave this page?";
//   }
// });
//Error badge
function showErrorLocalBadge(pictureId: any) {
  $("#picture-" + pictureId + " .local-error-badge").css("display", "block");
}

function hideErrorLocalBadge(pictureId: any) {
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

function showSuccessLocalBadge(pictureId: any) {
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

function hideSuccesLocalBadge(pictureId: any) {
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

function showMetasyncSuccesBadge(pictureId: any) {
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

function disableLocalButton(pictureId: any) {
  $("#picture-" + pictureId + " .action-save-picture").addClass("disabled");
  $("#picture-" + pictureId + " .action-save-picture i")
    .removeClass("icon-floppy")
    .addClass("icon-spin6 animate-spin");
  disableGlobalButton();
}

function enableLocalButton(pictureId: any) {
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

async function saveChanges(pictureId: any) {
  if (
    $("#picture-" + pictureId + " .local-unsaved-badge").css("display") ===
    "block"
  ) {
    disableLocalButton(pictureId);
    // Retrieve Infos
    const name = $("#picture-" + pictureId + " #name").val();
    const author = $("#picture-" + pictureId + " #author").val();
    const date_creation = $("#picture-" + pictureId + " #date_creation").val();
    const comment = $("#picture-" + pictureId + " #description").val();
    const level = $("#picture-" + pictureId + " #level option:selected").val();
    // Get Categories
    const categories = all_related_categories_ids[pictureId];
    const categoriesStr = categories.join(";");
    // Get Tags
    const tags: any[] = [];
    $("#picture-" + pictureId + " #tags option").each(function () {
      const tagId = $(this).val();
      tags.push(tagId);
    });
    const tagsStr = tags.join(",");
    const ajax_data: Record<string, any> = {
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
      const pluginValues_selector = pluginValues[key_index].selector;
      const full_selector = $(
        "#picture-" + pictureId + " " + pluginValues_selector,
      );
      const pluginValues_value = full_selector.val();
      ajax_data[pluginValues[key_index].api_key] = pluginValues_value;
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
      success: function (_data: any) {
        enableLocalButton(pictureId);
        enableGlobalButton();
        hideUnsavedLocalBadge(pictureId);
        showSuccessLocalBadge(pictureId);
        updateSuccessGlobalBadge();
        // Method 1 for extension's save (see Skeleton extension for more details)
        pluginSaveLoop(activePlugins, pictureId);
      },
      error: function (xhr: any, status: any, error: any) {
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
    const pictureId = $(field).data("image_id");
    await saveChanges(pictureId);
  }
}
//PLUGINS SAVE METHOD
const pluginFunctionMap: Record<string, any> = {};

function pluginFunctionMapInit(activePlugins: any[]) {
  activePlugins.forEach(function (pluginId) {
    const functionName = pluginId + "_batchManagerSave";
    if (typeof (window as any)[functionName] === "function") {
      pluginFunctionMap[pluginId] = (window as any)[functionName];
    }
  });
}

function pluginSaveLoop(activePlugins: any[], pictureId: any) {
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
function updateBlock(pictureId: any) {
  $.ajax({
    url: "api/v1/images/" + pictureId,
    type: "GET",
    dataType: "json",
    success: function (response: any) {
      $("#picture-" + pictureId + " #name").val(response.name);
      $("#picture-" + pictureId + " #author").val(response.author);
      $("#picture-" + pictureId + " #date_creation").val(response.dateCreation); //TODO
      $("#picture-" + pictureId + " #description").val(response.comment);
      $("#picture-" + pictureId + " #level").val(response.level);
      $("#picture-" + pictureId + " #filename").text(response.file);
      $("#picture-" + pictureId + " #filesize").text(response.filesize);
      $("#picture-" + pictureId + " #dimensions").text(
        response.width + "x" + response.height,
      );
      // updateTags(response.tags, pictureId); //Yet to be implemented (TODO)
      showMetasyncSuccesBadge(pictureId);
      enableLocalButton(pictureId);
      enableGlobalButton();
    },
    error: function (xhr: any, status: any, error: any) {
      console.error("Error:", status, error);
      showErrorLocalBadge(pictureId);
      enableLocalButton(pictureId);
    },
  });
}

const all_related_categories_ids = pwg_getPageData(
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
