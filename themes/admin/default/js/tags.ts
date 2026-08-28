import type { operations } from "../../../../openapi/client/schema";
import {
  jConfirm_alert_options,
  jConfirm_confirm_options,
  TemporaryState,
} from "./common";

import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../default/js/page-data";
import { ajax } from "../../../default/js/vendor/ajax";
export {};

// Real per-row shape (P47), traced to TagsPageRenderer.php's own
// `$all_tags` construction (`name`/`id`/`url_name`/`raw_name` always
// set; `counter`/`alt_names` only set when non-empty, hence optional).
interface TagRow {
  id: number;
  name: string;
  url_name: string;
  raw_name: string;
  counter?: number;
  alt_names?: string;
}

// Mixed at runtime -- most callers read a real numeric `TagRow.id`, but
// several DOM-attribute-sourced sites (`.attr("data-id")`, `.data("id")`)
// hand back a string form of the same value.
type TagId = string | number;

type TagCreateResponse =
  operations["tagCreate"]["responses"][201]["content"]["application/json"];
type TagRenameResponse =
  operations["tagRename"]["responses"][200]["content"]["application/json"];
type TagDeleteResponse =
  operations["tagDelete"]["responses"][200]["content"]["application/json"];
type TagDuplicateResponse =
  operations["tagDuplicate"]["responses"][201]["content"]["application/json"];
type TagMergeResponse =
  operations["tagMerge"]["responses"][200]["content"]["application/json"];

//Get the data
let dataTags = $(".tag-container").data("tags") as TagRow[];

//Initiate Select
$("#select-100").prop("checked", true);

//Orphan tags
$(".info-warning p a").on("click", () => {
  const url = $(".info-warning p a").data("url") as string;
  const tags = orphan_tag_names;
  const str_orphans = str_orphan_tags
    .replace("%s1", String(tags.length))
    .replace("%s2", tags.join(", "));
  $.confirm({
    content: str_orphans,
    title: str_delete_orphan_tags,
    draggable: false,
    theme: "modern",
    animation: "zoom",
    boxWidth: "30%",
    useBootstrap: false,
    type: "red",
    animateFromElement: false,
    backgroundDismiss: true,
    typeAnimated: false,
    buttons: {
      delete: {
        text: str_delete_them,
        btnClass: "btn-red",
        action: function () {
          window.location.href = String(url).replace(/amp;/g, "");
        },
      },
      keep: {
        text: str_keep_them,
        action: function () {
          $(".info-warning").hide();
        },
      },
    },
  });
});

//Create and recycle tag box
function createTagBox(
  id: number,
  name: string,
  url_name: string,
  count: number | undefined,
  raw_name: string | null = null,
) {
  if (raw_name === null) {
    raw_name = name;
  }
  const u_edit = "admin.php?page=batch_manager&filter=tag-" + id;
  const u_view = "index.php?/tags/" + id + "-" + url_name;
  let html = $(".tag-template")
    .html()
    .replace(/%name%/g, unescape(name))
    .replace("%U_VIEW%", u_view)
    .replace("%U_EDIT%", u_edit)
    .replace("%raw_name%", raw_name);
  if (name == raw_name) {
    html = html.replace("icon-globe", "");
  }
  const newTag = $(
    '<div class="tag-box test" data-id=' +
      id +
      ' data-selected="0">' +
      html +
      "</div>",
  );
  if ($("#toggleSelectionMode").is(":checked")) {
    newTag.addClass("selection");
    newTag.find(".in-selection-mode").show();
  }
  if (count !== undefined && count > 0) {
    newTag
      .find(".dropdown-option.view, .dropdown-option.manage")
      .css("display", "block");
    newTag
      .find(".tag-dropdown-header i")
      .html(str_number_photos.replace("%d", String(count)));
  } else {
    newTag.find(".tag-dropdown-header i").html(str_no_photos);
  }
  return newTag;
}

function recycleTagBox(
  tagBox: JQuery,
  id: number,
  name: string,
  url_name: string,
  count: number | undefined,
  raw_name: string | null = null,
) {
  if (raw_name === null) {
    raw_name = name;
  }
  tagBox = tagBox.first();
  tagBox.attr("data-id", id);
  tagBox.find(".tag-name, .tag-dropdown-header b").html(name);
  tagBox.find(".tag-name-editable").val(name);
  tagBox.attr("data-selected", 0);
  tagBox.find(".tag-name").data("rawname", raw_name);

  //Dropdown
  const u_edit = "admin.php?page=batch_manager&filter=tag-" + id;
  const u_view = "index.php?/tags/" + id + "-" + url_name;
  tagBox.find(".dropdown-option.view").attr("href", u_view);
  tagBox.find(".dropdown-option.manage").attr("href", u_edit);

  if (count !== undefined && count > 0) {
    tagBox
      .find(".dropdown-option.view, .dropdown-option.manage")
      .css("display", "block");
    tagBox
      .find(".tag-dropdown-header i")
      .html(str_number_photos.replace("%d", String(count)));
  } else {
    tagBox.find(".tag-dropdown-header i").html(str_no_photos);
  }
}

//Number On Badge
function updateBadge() {
  $(".badge-number").html(String(dataTags.length));
  if (dataTags.length == 0) {
    $(".tag-header #add-tag .add-tag-label").addClass("highlight");
  } else {
    $(".tag-header #add-tag .add-tag-label").removeClass("highlight");
  }
}

//Add a tag
$(".add-tag-container").on("click", function () {
  $("#add-tag").addClass("input-mode");
  $("#add-tag-input").focus();
  $(".tag-info").hide();
});

$("#add-tag .icon-cancel-circled").on("click", function () {
  $("#add-tag").removeClass("input-mode");
  $(".tag-info").hide();
});

//Display/Hide tag option
$(".tag-box").each(function () {
  setupTagbox($(this));
});

//Call the API when rename a tag
$(".TagSubmit").on("click", function () {
  $(".TagSubmit").hide();
  $(".TagLoading").show();
  // Non-null: set_up_popin() always sets this id before the form is
  // submittable.
  const $tagboxid = $(".RenameTagPopInContainer")
    .find(".tag-property-input")
    .attr("id")!;
  renameTag(
    $tagboxid,
    String($(".RenameTagPopInContainer").find(".tag-property-input").val()),
  )
    .then(() => {
      $(".TagSubmit").show();
      $(".TagLoading").hide();
      rename_tag_close();
      cleanCheckmark();
      $("[data-id=" + $tagboxid + "]").wrap('<div class="tag-changed"></div>');
      $(".tag-changed").prepend('<i class="icon-ok tag-checkmark"></i>');
    })
    .catch((message: Error) => {
      $(".TagSubmit").show();
      $(".TagLoading").hide();
      console.error(message);
    });
});

function cleanCheckmark() {
  $(".tag-changed > *").unwrap();
  $(".tag-checkmark").remove();
}

/*-------
 Add a tag
-------*/

$("#add-tag").submit(function (e) {
  e.preventDefault();
  if ($("#add-tag-input").val() != "") {
    const loadState = new TemporaryState();
    loadState.removeClass($("#add-tag .icon-validate"), "icon-plus");
    loadState.changeHTML(
      $("#add-tag .icon-validate"),
      "<i class='icon-spin6 animate-spin'> </i>",
    );
    loadState.changeAttribute(
      $("#add-tag .icon-validate"),
      "style",
      "pointer-event:none",
    );
    addTag(String($("#add-tag-input").val()))
      .then(function () {
        showMessage(
          str_tag_created.replace("%s", String($("#add-tag-input").val())),
        );
        $("#add-tag-input").val("");
        $("#add-tag").removeClass("input-mode");
        $("#search-tag .search-input").trigger("input");
        loadState.reverse();
      })
      .catch((message: Error) => {
        loadState.reverse();
        showError(message.message);
      });
  }
});

$("#add-tag .icon-validate").on("click", function () {
  if ($("#add-tag").hasClass("input-mode")) {
    $("#add-tag").submit();
  }
});

function addTag(name: string) {
  return new Promise<void>((resolve, reject) => {
    void ajax({
      url: "api/v1/tags",
      type: "POST",
      contentType: "application/json",
      headers: {
        "X-CSRF-Token": pwg_token,
      },
      data: JSON.stringify({
        name: name,
      }),
      dataType: "json",
      success: function (data: TagCreateResponse) {
        const newTag = createTagBox(data.id, data.name, data.urlName, 0);
        $(".tag-container").prepend(newTag);
        setupTagbox(newTag);
        updateSearchInfo();

        //Update the data
        dataTags.unshift({
          name: data.name,
          raw_name: data.name,
          id: data.id,
          url_name: data.urlName,
        });
        updateBadge();
        resolve();
      },
      error: function (err) {
        if (err.status === 422) {
          reject(new Error(str_already_exist.replace("%s", name)));
          return;
        }
        reject(new Error(err.statusText));
      },
    });
  });
}
/*-------
 Setup Tag Box
-------*/

function setupTagbox(tagBox: JQuery) {
  //Dropdown options
  tagBox.find(".showOptions").on("click", function () {
    tagBox.find(".tag-dropdown-block").css("display", "grid");
  });

  $(document).mouseup(function (e) {
    e.stopPropagation();
    let option_is_clicked = false;
    tagBox.find(".dropdown-option").each(function (this: HTMLElement) {
      if (!($(this).has(e.target as unknown as Element).length === 0)) {
        option_is_clicked = true;
      }
    });
    if (!option_is_clicked) {
      tagBox.find(".tag-dropdown-block").hide();
    }
  });

  // Selection behaviour
  tagBox.on("click", function () {
    if ($(".tag-container").hasClass("selection")) {
      if (tagBox.attr("data-selected") == "1") {
        tagBox.attr("data-selected", "0");
        removeSelectedItem(tagBox.attr("data-id")!);
      } else {
        tagBox.attr("data-selected", "1");
        addSelectedItem(tagBox.attr("data-id")!);
      }
      updateSelectionContent();
    }
  });

  //Edit Name
  tagBox
    .find(".dropdown-option.edit")
    .on("click", function (this: HTMLElement) {
      const id = $(this).closest(".tag-box").data("id") as TagId;
      const tagIndex = dataTags.findIndex((tag) => tag.id == id);
      // Non-null: `id` always comes from a real tag box, which was
      // itself rendered from this same `dataTags` array.
      const tagRawName =
        dataTags[tagIndex]!.raw_name ??
        tagBox.find(".tag-name").data("rawname");
      const tagName =
        dataTags[tagIndex]!.name ?? tagBox.find(".tag-name").html();
      set_up_popin(tagBox.data("id") as TagId, tagRawName, tagName);
      rename_tag_open();
    });

  //Delete Tag
  tagBox.find(".dropdown-option.delete").on("click", function () {
    $.confirm({
      title: str_delete.replace("%s", tagBox.find(".tag-name").html()),
      buttons: {
        confirm: {
          text: str_yes_delete_confirmation,
          btnClass: "btn-red",
          action: function () {
            removeTag(
              tagBox.data("id") as TagId,
              tagBox.find(".tag-name").html(),
            );
          },
        },
        cancel: {
          text: str_no_delete_confirmation,
        },
      },
      ...jConfirm_confirm_options,
    });
  });

  //Duplicate Tag
  tagBox.find(".dropdown-option.duplicate").on("click", function () {
    void duplicateTag(
      tagBox.data("id") as TagId,
      tagBox.find(".tag-name").data("rawname") as string,
    ).then((data) => {
      showMessage(str_tag_created.replace("%s", data.name));
    });
  });
}

function set_up_popin(id: TagId, tagRawName: string, tagName: string) {
  $(".RenameTagPopInContainer").find(".tag-property-input").attr("id", id);

  $(".AddIconTitle span").html(str_tag_rename.replace("%s", tagName));
  $(".ClosePopIn, .TagCancel").on("click", function () {
    rename_tag_close();
  });
  $(".TagSubmit").html(str_yes_rename_confirmation);
  $(".RenameTagPopInContainer").find(".tag-property-input").val(tagRawName);
}

function rename_tag_close() {
  $("#RenameTag").fadeOut();
}

function rename_tag_open() {
  $("#RenameTag").fadeIn();
  $(".tag-property-input").first().focus();
}

function removeTag(id: TagId, name: string) {
  $.alert({
    title: str_tag_deleted.replace("%s", name),
    content: function () {
      return ajax({
        url: "api/v1/tags/" + id,
        type: "DELETE",
        headers: {
          "X-CSRF-Token": pwg_token,
        },
        dataType: "json",
        success: function (_data: TagDeleteResponse) {
          $(".tag-box[data-id=" + id + "]").remove();
          //Update data
          dataTags = dataTags.filter((tag) => tag.id != id);
          showMessage(str_tag_deleted.replace("%s", name));
          updateBadge();
          updateSearchInfo();
          updatePaginationMenu();
        },
        error: function () {
          showError("A problem has occured");
        },
      });
    },
    ...jConfirm_alert_options,
  });
}

function renameTag(id: TagId, new_name: string) {
  return new Promise<TagRenameResponse>((resolve, reject) => {
    void ajax({
      url: "api/v1/tags/" + id,
      type: "PATCH",
      contentType: "application/json",
      headers: {
        "X-CSRF-Token": pwg_token,
      },
      data: JSON.stringify({
        name: new_name,
      }),
      dataType: "json",
      success: function (data: TagRenameResponse) {
        $(
          ".tag-box[data-id=" +
            id +
            "] p, .tag-box[data-id=" +
            id +
            "] .tag-dropdown-header b",
        ).html(data.name);
        $(".tag-box[data-id=" + id + "] .tag-name-editable").attr(
          "value",
          data.name,
        );
        $(".tag-box[data-id=" + id + "] .tag-name").attr(
          "data-rawname",
          data.nameRaw,
        );
        const u_view = "index.php?/tags/" + id + "-" + data.urlName;
        $(".dropdown-option.view").attr("href", u_view);

        //Update the data
        const index = dataTags.findIndex((tag) => tag.id == id);
        // Non-null: `id` always identifies a real, currently-rendered
        // tag box, which was itself rendered from this same array.
        dataTags[index]!.name = data.name;
        dataTags[index]!.raw_name = data.nameRaw;
        dataTags[index]!.url_name = data.urlName;

        resolve(data);
      },
      error: function (XMLHttpRequest) {
        if (XMLHttpRequest.status === 422) {
          reject(new Error(str_already_exist.replace("%s", new_name)));
          return;
        }
        reject(new Error(XMLHttpRequest.statusText));
      },
    });
  });
}

function duplicateTag(id: TagId, name: string) {
  return new Promise<TagDuplicateResponse>((resolve, reject) => {
    let copy_name = name + str_copy;

    const name_exist = function (name: string) {
      let exist = false;
      $(".tag-box .tag-name").each(function () {
        if ($(this).html() === name) exist = true;
      });
      return exist;
    };

    let i = 1;
    while (name_exist(copy_name)) {
      copy_name = name + str_other_copy.replace("%s", String(i++));
    }

    void ajax({
      url: "api/v1/tags/" + id + "/actions/duplicate",
      type: "POST",
      contentType: "application/json",
      headers: {
        "X-CSRF-Token": pwg_token,
      },
      data: JSON.stringify({
        name: copy_name,
      }),
      dataType: "json",
      success: function (data: TagDuplicateResponse) {
        const newTag = createTagBox(
          data.id,
          data.name,
          data.urlName,
          data.count,
        );
        newTag.insertAfter($(".tag-box[data-id=" + id + "]"));
        setupTagbox(newTag);

        //Update Data
        const index = dataTags.findIndex((tag) => tag.id == id);
        dataTags.splice(index + 1, 0, {
          name: data.name,
          // Was missing entirely -- `TagRow.raw_name` is a required
          // field, and `tagDuplicate`'s own response has no separate
          // raw-name field to source it from (same gap `tagCreate`'s
          // response has, worked around identically in addTag()'s own
          // success handler above: the rendered `name` is the best
          // available stand-in until a real page reload re-fetches the
          // true raw name).
          raw_name: data.name,
          id: data.id,
          url_name: data.urlName,
          counter: data.count,
        });
        updateBadge();
        updateSearchInfo();
        resolve(data);
      },
      error: function (XMLHttpRequest) {
        reject(new Error(XMLHttpRequest.statusText));
      },
    });
  });
}

/*-------
 Selection mode
-------*/
let selected: TagId[] = [];
const maxItemDisplayed = 5;

$("#toggleSelectionMode").prop("checked", false);
$("#toggleSelectionMode").click(function () {
  selectionMode($(this).is(":checked"));
  $(".tag-info").hide();
});

function selectionMode(isSelection: boolean) {
  if (isSelection) {
    $(".in-selection-mode").addClass("show");
    $(".not-in-selection-mode").addClass("hide");
    $(".tag-container").addClass("selection");
    $(".tag-box").removeClass("edit-name");
  } else {
    $(".in-selection-mode").removeClass("show");
    $(".not-in-selection-mode").removeClass("hide");
    $(".tag-container").removeClass("selection");
    $(".tag-box").attr("data-selected", "0");
    $(".tag-select-message").slideUp();
    clearSelection();
  }
}

function clearSelection() {
  selected = [];
  $(".selection-mode-tag .tag-list").html("");
  $(".selection-other-tags").hide();
  updateSelectionContent();
}

function addSelectedItem(id: TagId) {
  if (!selected.includes(id)) {
    selected.push(id);

    if (selected.length > maxItemDisplayed) {
      $(".selection-other-tags").show();
      const numberDisplayed = $(".selection-mode-tag .tag-list div").length;
      $(".selection-other-tags").html(
        str_and_others_tags.replace(
          "%s",
          String(selected.length - numberDisplayed),
        ),
      );
    } else {
      $(".selection-other-tags").hide();
      if (dataTags.findIndex((tag) => tag.id == id) > -1) {
        createSelectionItem(id, dataTags.find((tag) => tag.id == id)!.name);
      }
    }
  }
}

function createSelectionItem(id: TagId, name: string) {
  const newItemStructure = $(
    '<div data-id="' +
      id +
      '"><a class="icon-cancel"></a><p>' +
      name +
      "</p> </div>",
  );
  $(".selection-mode-tag .tag-list").prepend(newItemStructure);
  $(".selection-mode-tag .tag-list div[data-id=" + id + "] a").on(
    "click",
    function () {
      removeSelectedItem(id);
    },
  );
}

function removeSelectedItem(id: TagId) {
  if (selected.findIndex((tag) => tag == id) > -1) {
    selected = selected.filter((tag) => {
      return parseInt(String(tag)) != parseInt(String(id));
    });

    $(".tag-box[data-id=" + id + "]").attr("data-selected", "0");
    if (
      $(".selection-mode-tag .tag-list div[data-id=" + id + "]").length != 0
    ) {
      $(".selection-mode-tag .tag-list div[data-id=" + id + "]").remove();

      if (selected.length >= maxItemDisplayed) {
        let i = 0;
        let isNotCreate = true;
        while (i < selected.length && isNotCreate) {
          if (
            $(".selection-mode-tag .tag-list div[data-id=" + selected[i] + "]")
              .length == 0
          ) {
            isNotCreate = false;
            const indexOfTag = dataTags.findIndex(
              (tag) => tag.id == selected[i],
            );
            createSelectionItem(selected[i]!, dataTags[indexOfTag]!.name);
          }
          i++;
        }
      }
    }

    const numberDisplayed = $(".selection-mode-tag .tag-list div").length;
    $(".selection-other-tags").html(
      str_and_others_tags.replace(
        "%s",
        String(selected.length - numberDisplayed),
      ),
    );
    if (selected.length - numberDisplayed <= 0) {
      $(".selection-other-tags").hide();
    }

    //Remove the selection message
    $(".tag-select-message").slideUp();
  }
}

function updateMergeItems() {
  $("#MergeOptionsChoices").html("");
  selected.forEach((id) => {
    $("#MergeOptionsChoices").append(
      $(
        '<option value="' +
          id +
          '">' +
          dataTags.find((tag) => tag.id == id)!.name +
          "</option>",
      ),
    );
  });
}

let mergeOption = false;

function updateSelectionContent() {
  const number = selected.length;
  if (number == 0) {
    mergeOption = false;
    $("#nothing-selected").show();
    $(".selection-mode-tag").hide();
    $("#MergeOptionsBlock").hide();
  } else if (number == 1) {
    mergeOption = false;
    $("#nothing-selected").hide();
    $(".selection-mode-tag").show();
    $("#MergeOptionsBlock").hide();
    $("#MergeSelectionMode").addClass("unavailable");
  } else if (number > 1) {
    $("#nothing-selected").hide();
    $("#MergeSelectionMode").removeClass("unavailable");
    if (mergeOption) {
      $("#MergeOptionsBlock").show();
      $(".selection-mode-tag").hide();
      updateMergeItems();
    } else {
      $("#MergeOptionsBlock").hide();
      $(".selection-mode-tag").show();
    }
  }
}

$("#MergeSelectionMode").on("click", function () {
  mergeOption = true;
  updateSelectionContent();
});

$("#CancelMerge").on("click", function () {
  mergeOption = false;
  updateSelectionContent();
});

$("#selectAll").on("click", function () {
  void selectAll(tagToDisplay());
  updateSelectionContent();
  if (selected.length < dataTags.length) {
    showSelectMessage(
      str_selection_done.replace("%d", String($(".tag-box").length)),
      str_select_all_tag.replace("%d", String(dataTags.length)),
      function () {
        $(".tag-select-message a").html("");
        $(".tag-select-message div").html(
          "<i class='icon-spin6 animate-spin'> </i>",
        );
        setTimeout(() => {
          void selectAll(dataTags).then(() => {
            updateSelectionContent();
            showSelectMessage(
              str_tag_selected.replace(/%d/g, String(selected.length)),
              str_clear_selection,
              function () {
                selectNone();
                $(".tag-select-message").slideUp();
              },
            );
          });
        }, 5);
      },
    );
  }
});

function selectAll(data: TagRow[]) {
  const promises: Promise<void>[] = [];
  data.forEach((tag) => {
    promises.push(
      new Promise<void>((res, _rej) => {
        $(".tag-box[data-id=" + tag.id + "]").attr("data-selected", 1);
        addSelectedItem(tag.id);
        res();
      }),
    );
  });
  return Promise.all(promises);
}

function showSelectMessage(str1: string, str2: string, callback: () => void) {
  if (!$(".tag-select-message").is(":visible")) {
    $(".tag-select-message").slideDown({
      start: function () {
        $(this).css({
          display: "flex",
        });
      },
    });
  }

  $(".tag-select-message div").html(str1);
  $(".tag-select-message a").html(str2);
  $(".tag-select-message a").off("click");
  $(".tag-select-message a").on("click", callback);
}

$("#selectNone").on("click", function () {
  $(".tag-select-message").slideUp();
  selectNone();
});

function selectNone() {
  $(".tag-box").attr("data-selected", "0");
  clearSelection();
}

$("#selectInvert").on("click", function () {
  $(".tag-select-message").slideUp();
  selectInvert(tagToDisplay());
});

function selectInvert(data: TagRow[]) {
  data.forEach((tag) => {
    const tagBox = $(".tag-box[data-id=" + tag.id + "]");
    if (tagBox.attr("data-selected") == "1") {
      tagBox.attr("data-selected", "0");
      removeSelectedItem(tag.id);
    } else {
      tagBox.attr("data-selected", "1");
      addSelectedItem(tag.id);
    }
  });
  updateSelectionContent();
}

/*-------
 Actions in selection mode
-------*/

//Remove tags
$("#DeleteSelectionMode").on("click", function () {
  const names: string[] = [];
  selected.forEach(function (id) {
    names.push(dataTags.find((tag) => tag.id == id)!.name);
  });

  $.confirm({
    title: str_delete_tags.replace("%s", tagListToString(names)),
    buttons: {
      confirm: {
        text: str_yes_delete_confirmation,
        btnClass: "btn-red",
        action: function () {
          removeSelectedTags();
        },
      },
      cancel: {
        text: str_no_delete_confirmation,
      },
    },
    ...jConfirm_confirm_options,
  });
});

function removeSelectedTags() {
  const names: string[] = [];
  selected.forEach(function (id) {
    names.push(dataTags.find((tag) => tag.id == id)!.name);
  });

  $.alert({
    title: str_tags_deleted.replace("%s", tagListToString(names)),
    content: function () {
      // No bulk-delete endpoint (a REST single-resource DELETE per tag,
      // per P27's own design) -- fire one DELETE per selected tag.
      return Promise.all(
        selected.map(function (id) {
          return ajax({
            url: "api/v1/tags/" + id,
            type: "DELETE",
            headers: {
              "X-CSRF-Token": pwg_token,
            },
            dataType: "json",
          });
        }),
      ).then(function () {
        selected.forEach(function (id) {
          $(".tag-box[data-id=" + id + "]").remove();
        });

        // Update Data
        dataTags = dataTags.filter((tag) => !selected.includes(tag.id));

        clearSelection();
        updatePaginationMenu();
        updateBadge();
        updateSearchInfo();
      });
    },
    ...jConfirm_alert_options,
  });
}

//Merge Tags
$(".ConfirmMergeButton").on("click", () => {
  // Single-value <select>, never multi.
  const dest_id = $("#MergeOptionsChoices").val() as string;
  mergeGroups(dest_id, selected);
});

function mergeGroups(destination_id: TagId, merge_ids: TagId[]) {
  const destination_name = $(
    ".tag-box[data-id=" + destination_id + "] .tag-name",
  ).html();
  const merge_name: string[] = [];

  merge_ids.forEach((id) => {
    merge_name.push($(".tag-box[data-id=" + id + "] .tag-name").html());
  });

  const str_message = str_merged_into
    .replace("%s1", tagListToString(merge_name))
    .replace("%s2", destination_name);

  $.alert({
    title: str_message,
    content: function () {
      return ajax({
        url: "api/v1/tags/actions/merge",
        type: "POST",
        contentType: "application/json",
        headers: {
          "X-CSRF-Token": pwg_token,
        },
        data: JSON.stringify({
          destinationTagId: Number(destination_id),
          mergeTagIds: merge_ids,
        }),
        dataType: "json",
        success: function (data: TagMergeResponse) {
          data.deletedTagIds.forEach((id) => {
            if (data.destinationTagId != id) {
              $(".tag-box[data-id=" + id + "]").remove();
              // Update data
              dataTags = dataTags.filter((tag) => id != tag.id);
            }
          });
          if (data.imagesInMergedTag.length > 0) {
            const tagBox = $(".tag-box[data-id=" + data.destinationTagId + "]");
            tagBox
              .find(
                ".dropdown-option.view," +
                  ".dropdown-option.manage," +
                  ".tag-dropdown-header i",
              )
              .show();
            $(".tag-dropdown-header i").html(
              str_number_photos.replace(
                "%d",
                String(data.imagesInMergedTag.length),
              ),
            );

            // Update data
            const index = dataTags.findIndex(
              (tag) => tag.id == data.destinationTagId,
            );
            dataTags[index]!.counter = data.imagesInMergedTag.length;
          }
          $(".tag-box").attr("data-selected", "0");
          clearSelection();
          updatePaginationMenu();
          updateBadge();
          updateSearchInfo();
        },
      });
    },
    ...jConfirm_alert_options,
  });
}

function tagListToString(list: string[]) {
  if (list.length > 5) {
    return (
      list.slice(0, 5).join(", ") +
      " " +
      str_and_others_tags.replace("%s", String(list.length - 5))
    );
  } else {
    return list.join(", ");
  }
}

/*-------
 Filter research
-------*/

// `ReturnType<typeof setTimeout>`, not `number` -- this project's
// tsconfig `types` array includes `"node"`, so the ambient
// `setTimeout`/`clearTimeout` here resolve to Node's own
// `NodeJS.Timeout`-returning signatures, not the DOM lib's.
let searchTimeOut: ReturnType<typeof setTimeout> | undefined;
const delaySearchInput = 300;

$("#search-tag .search-input").on("input", function () {
  actualPage = 1;

  clearTimeout(searchTimeOut);
  searchTimeOut = setTimeout(() => {
    updatePaginationMenu();
    if (dataTags.filter(isDataSearched).length == 0) {
      $(".emptyResearch").show();
    } else {
      $(".emptyResearch").hide();
    }
  }, delaySearchInput);
});

// Genuinely dead code -- zero real callers found (confirmed via grep)
// -- typed rather than left broken, same policy as prior files this
// campaign.
function isDataSearched(tagObj: TagRow) {
  const name = tagObj.raw_name.toLowerCase();
  const stringSearch = String($("#search-tag .search-input").val());
  if (name.includes(stringSearch.toLowerCase())) {
    return true;
  } else {
    return false;
  }
}

/*-------
 Show Info
-------*/
function showError(message: string) {
  $(".info-error p").html(message);
  $(".info-error").attr("title", message);
  $(".info-info").hide();
  $(".info-error").css("display", "flex");
}

function showMessage(message: string) {
  $(".info-message p").html(message);
  $(".info-message").attr("title", message);
  $(".info-info").hide();
  $(".info-message").css("display", "flex");
}

/*-------
 Pagination
-------*/
let per_page = $(".tag-container").data("per_page") as number;
const pageItem = '<a data-page="%d">%d</a>';
const pageEllipsis = "<span>...</span>";
let promisePending = false;
let updateAsk = false;

let actualPage = 1;

//Avoid 2 update at the same time
function askUpdatePage() {
  if (!promisePending) {
    promisePending = true;
    void updatePage().then(promiseFinish);
  } else {
    updateAsk = true;
  }
}

function promiseFinish() {
  promisePending = false;
  if (updateAsk) {
    updateAsk = false;
    askUpdatePage();
  }
}

function updatePaginationMenu() {
  $(".pagination-item-container").html("");

  actualPage = Math.min(actualPage, getNumberPages());

  if (getNumberPages() > 1) {
    $(".pagination-container").show();
    createPaginationMenu();
  } else {
    $(".pagination-container").hide();
  }

  updateArrows();
  askUpdatePage();

  //Remove the selection message
  $(".tag-select-message").slideUp();
}

function createPaginationMenu() {
  const nbPage = getNumberPages();

  appendPaginationItem(1);

  if (actualPage > 2) {
    appendPaginationItem();
  }

  if (actualPage != 1 && actualPage != nbPage) {
    appendPaginationItem(actualPage);
  }

  if (actualPage < nbPage - 1) {
    appendPaginationItem();
  }

  appendPaginationItem(nbPage);
}

function appendPaginationItem(page: number | null = null) {
  if (page != null) {
    const newTag = $(pageItem.replace(/%d/g, String(page)));
    $(".pagination-item-container").append(newTag);
    if (actualPage == page) {
      newTag.addClass("actual");
    }
    newTag.on("click", () => {
      actualPage = newTag.data("page") as number;
      updatePaginationMenu();
    });
  } else {
    $(".pagination-item-container").append($(pageEllipsis));
  }
}

function updateArrows() {
  if (actualPage == 1) {
    $(".pagination-arrow.left").addClass("unavailable");
  } else {
    $(".pagination-arrow.left").removeClass("unavailable");
  }

  if (actualPage == getNumberPages()) {
    $(".pagination-arrow.rigth").addClass("unavailable");
  } else {
    $(".pagination-arrow.rigth").removeClass("unavailable");
  }
}

function getNumberPages() {
  const dataVisible = dataTags.filter(isDataSearched).length;
  return Math.floor((dataVisible - 1) / per_page) + 1;
}

function movePage(toRigth: boolean = true) {
  $(".tag-box").removeClass("edit-name");
  if (toRigth) {
    if (actualPage < getNumberPages()) {
      actualPage++;
      updatePaginationMenu();
    }
  } else {
    if (actualPage > 1) {
      actualPage--;
      updatePaginationMenu();
    }
  }
}

function updatePage() {
  return new Promise<void>((resolve, _reject) => {
    const dataToDisplay = tagToDisplay();
    const tagBoxes = $(".tag-box");
    cleanCheckmark();
    $(".pageLoad").fadeIn();
    $(".tag-box")
      .animate({ opacity: 0 }, 500)
      .promise()
      .then(() => {
        const displayTags: Promise<void> = new Promise((res, _rej) => {
          const boxToRecycle = Math.min(dataToDisplay.length, tagBoxes.length);

          for (let i = 0; i < boxToRecycle; i++) {
            const tag = dataToDisplay[i]!;
            recycleTagBox(
              $(tagBoxes[i]!),
              tag.id,
              tag.name,
              tag.url_name,
              tag.counter,
              tag.raw_name,
            );
          }

          if (dataToDisplay.length < tagBoxes.length) {
            for (let j = boxToRecycle; j < tagBoxes.length; j++) {
              $(tagBoxes[j]!).remove();
            }
          } else if (dataToDisplay.length > tagBoxes.length) {
            for (let j = boxToRecycle; j < dataToDisplay.length; j++) {
              const tag = dataToDisplay[j]!;
              const newTag = createTagBox(
                tag.id,
                tag.name,
                tag.url_name,
                tag.counter,
                tag.raw_name,
              );
              newTag.css("opacity", 0);
              $(".tag-container").append(newTag);
              setupTagbox(newTag);
            }
          }

          //Select selected tags
          selected.forEach((id) => {
            $(".tag-box[data-id=" + id + "]").attr("data-selected", 1);
          });

          res();
        });

        void displayTags.then(() => {
          $(".pageLoad").fadeOut();
          $(".tag-box").animate({ opacity: 1 }, 500);
          if (getNumberPages() > 1) {
            $(".tag-pagination").animate({ opacity: 1 }, 500);
          }
          updateSearchInfo();
          resolve();
        });
      });
  });
}

function tagToDisplay() {
  return dataTags
    .filter(isDataSearched)
    .slice((actualPage - 1) * per_page, actualPage * per_page);
}

$(".pagination-arrow.rigth").on("click", () => {
  movePage();
});

$(".pagination-arrow.left").on("click", () => {
  movePage(false);
});

if (getNumberPages() > 1) {
  $(".pagination-container").show();
  createPaginationMenu();
  updateArrows();
} else {
  $(".pagination-container").hide();
}

$(".pagination-per-page a").on("click", function () {
  per_page = parseInt($(this).html());
  updatePaginationMenu();
  $(".pagination-per-page .selected").removeClass("selected");
  $(this).addClass("selected");
  $.cookie("pwg_tags_per_page", per_page);
});

function updateSearchInfo() {
  if ($(".search-input").val() != "") {
    const number = dataTags.filter(isDataSearched).length;
    if (number > 1) {
      $(".search-info").html(str_tags_found.replace("%d", String(number)));
    } else {
      $(".search-info").html(str_tag_found.replace("%d", String(number)));
    }
  } else {
    $(".search-info").html("");
  }
}

const pwg_token = pwg_getPageData<string>("csrf_token");
const orphan_tag_names = JSON.parse(
  pwg_getPageData<string>("orphan_tag_names_array"),
) as string[];
const str_delete = pwg_getPageString('Delete tag "%s"?');
const str_delete_tags = pwg_getPageString("Delete tags {%s}?");
const str_yes_delete_confirmation = pwg_getPageString("Yes, delete");
const str_no_delete_confirmation = pwg_getPageString(
  "No, I have changed my mind",
);
const str_yes_rename_confirmation = pwg_getPageString("Yes, rename");
const str_tag_deleted = pwg_getPageString('Tag "%s" succesfully deleted');
const str_tags_deleted = pwg_getPageString("Tags {%s} succesfully deleted");
const str_already_exist = pwg_getPageString('Tag "%s" already exists');
const str_tag_created = pwg_getPageString('Tag "%s" created');
const str_tag_rename = pwg_getPageString('Rename "%s"');
const str_delete_orphan_tags = pwg_getPageString("Delete orphan tags ?");
const str_orphan_tags = pwg_getPageString("You have %s1 orphan : %s2");
const str_delete_them = pwg_getPageString("Delete them");
const str_keep_them = pwg_getPageString("Keep them");
const str_copy = pwg_getPageString(" (copy)");
const str_other_copy = pwg_getPageString(" (copy %s)");
const str_merged_into = pwg_getPageString(
  'Tag(s) {%s1} succesfully merged into "%s2"',
);
const str_and_others_tags = pwg_getPageString("and %s others");
const str_number_photos = pwg_getPageString("%d photos");
const str_no_photos = pwg_getPageString("no photo");
const str_select_all_tag = pwg_getPageString("Select all %d tags");
const str_clear_selection = pwg_getPageString("Clear Selection");
const str_selection_done = pwg_getPageString(
  "The %d tags on this page are selected",
);
const str_tag_selected = pwg_getPageString("<b>%d</b> tag selected");
const str_tags_found = pwg_getPageString("<b>%d</b> tags found");
const str_tag_found = pwg_getPageString("<b>%d</b> tag found");

$(document).ready(function () {
  $("h1").append(
    '<span class="badge-number">' +
      pwg_getPageData<number>("total") +
      "</span>",
  );
});

if (!$.cookie("pwg_tags_per_page")) {
  $.cookie("pwg_tags_per_page", "100");
}

$(function () {
  function setPagination() {
    const test = $.cookie("pwg_tags_per_page") as string | undefined;
    $(".pagination-per-page .selected").removeClass("selected");
    $("#" + test).trigger("click");
  }

  setPagination();
});
