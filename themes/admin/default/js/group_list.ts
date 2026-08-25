import type { components, operations } from "../../../../openapi/client/schema";

export {};

// `UserEntity`/`EntityCacheInstance<T>` (below, `usersCache`'s own real
// type) are LocalStorageCache.ts's own top-level ambient types -- that
// file is a genuinely non-module IIFE, same cross-file ambient-global
// technique as cat_modify.ts's own `AlbumSelectorCallbackArgs` reuse.
type Group = components["schemas"]["Group"];
// The real `GET /api/v1/users?groupIds[]=...` response shape (same
// underlying schema LocalStorageCache.ts's own `UserEntity` alias
// already resolves to, kept as a separate local alias here since this
// file reads the raw API response directly, not through UsersCache).
type GroupUserListResponse =
  operations["userList"]["responses"][200]["content"]["application/json"];
// The user-manager popup's own `usersInGroup` list holds either a real
// fetched user row, or a locally-synthesized partial one appended right
// after a successful add-user call (before any refetch) -- only `id`/
// `username` are ever read from either shape throughout this file.
interface GroupMemberDisplay {
  id: string | number;
  username: string;
}
interface UserSelectOption {
  value: string | number;
  text: string;
}

const DELAY_FEEDBACK = 3000;
/*-------
Group Popin
-------*/

$(".group_details_popup_trigger").click(function () {
  $(".Group_details-popup-container").show();
});

$(".CloseGroupPopup").click(function () {
  $(".Group_details-popup-container").hide();
});

//Number On Badge
function updateBadge() {
  $(".badge-number").html(String($(".GroupContainer").length - 2)); //Less the add group div and the template
}

/*-------
 Add User toggle and reduces height of user list when add user form is visible
 -------*/

$("#form-btn").click(function () {
  $("#cancel").show();
  $("#addUserLabel").show();
  $(".UserSearch").show();
  $("#UserSubmit").show();
  $("#form-btn").hide();
  $(".groups .list_user").css("max-height", "100px");
});

$("#cancel").click(function () {
  $("#cancel").hide();
  $("#addUserLabel").hide();
  $(".UserSearch").hide();
  $("#UserSubmit").hide();
  $("#form-btn").show();
  $(".groups .list_user").css("max-height", "200px");
});

/*-------
 Add Group toggle
 -------*/
let isToggle = true;
$(".addGroupBlock").on("click", function () {
  if (isToggle) {
    deployAddGroupForm();
  } else {
    hideAddGroupForm();
  }
});

const deployAddGroupForm = function () {
  $(".addGroupBlock").animate(
    {
      top: "20%",
      padding: "0px",
    },
    400,
    function () {
      $("#addGroupForm form").fadeIn();
      $("#addGroupNameInput").focus();
    },
  );
  isToggle = false;
};

const hideAddGroupForm = function () {
  $("#addGroupForm form").fadeOut(function () {
    $(".addGroupBlock").animate(
      {
        top: "50%",
        padding: "100px 0",
      },
      400,
    );
  });
  isToggle = true;
};

/*-------
 Add Group Submit
 -------*/

jQuery(document).ready(function () {
  $("#addGroupForm form").on("submit", function (e) {
    e.preventDefault();
    const name = String($("#addGroupForm input[type=text]").val());
    const loadState = new window.TemporaryState();
    loadState.changeHTML(
      $(".actionButtons button"),
      "<i class='icon-spin6 animate-spin'> </i>",
    );
    loadState.changeAttribute(
      $(".actionButtons button"),
      "style",
      "pointer-events: none",
    );
    loadState.changeAttribute(
      $(".actionButtons a"),
      "style",
      "pointer-events: none",
    );

    if (name.replace(/\s/g, "").length != 0) {
      jQuery.ajax({
        url: "api/v1/groups",
        type: "POST",
        contentType: "application/json",
        headers: {
          "X-CSRF-Token": pwg_token,
        },
        data: JSON.stringify({
          name: name,
        }),
        dataType: "json",
        success: function (
          data: operations["groupCreate"]["responses"][201]["content"]["application/json"],
        ) {
          loadState.reverse();
          $(".addGroupFormLabelAndInput input").val("");
          const group = data;
          const groupBox = createGroup(group);
          $("#addGroupForm").after(groupBox);
          setupGroupBox(groupBox);
          updateBadge();
        },
        error: function (_err: JQuery.jqXHR) {
          loadState.reverse();
          $("#addGroupForm .groupError").html(str_name_not_empty);
          $("#addGroupForm .groupError").fadeIn();
          $("#addGroupForm .groupError").delay(DELAY_FEEDBACK).fadeOut();
        },
      });
    } else {
      loadState.reverse();
      $("#addGroupForm .groupError").html(str_name_not_empty);
      $("#addGroupForm .groupError").fadeIn();
      $("#addGroupForm .groupError").delay(DELAY_FEEDBACK).fadeOut();
    }
  });
});

const createGroup = function (group: Group) {
  //Setup the group
  const newgroup = $("#group-template")
    .clone()
    .attr("id", "group-" + group.id);
  newgroup.attr("data-id", group.id);
  newgroup.find("#group_name").html(group.name);
  newgroup.find(".group_name-editable").val(group.name);
  newgroup
    .find(".Group-checkbox label")
    .attr("for", "Group-Checkbox-selection-" + group.id);
  newgroup
    .find(".Group-checkbox input")
    .attr("id", "Group-Checkbox-selection-" + group.id);
  newgroup.find(".input-edit-group-name").attr("placeholder", group.name);
  newgroup
    .find(".group_number_users")
    .html(
      group.nbUsers +
        " " +
        (group.nbUsers > 1 ? str_members_default : str_member_default),
    );
  newgroup.find(".group_name-editable").html(group.name);
  newgroup
    .find(".manage-permissions")
    .attr("href", "admin.php?page=group_perm&group_id=" + group.id);
  hideAddGroupForm();

  //Setup the icon color
  const colors = [
    "icon-red",
    "icon-blue",
    "icon-yellow",
    "icon-purple",
    "icon-green",
  ];
  const colorId = Number(group.id) % 5;
  newgroup.find(".icon-users-1").addClass(colors[colorId]!);

  //Place group in first Place
  newgroup.find(".groupMessage").html(str_group_created);
  newgroup.find(".groupMessage").fadeIn();
  newgroup.find(".groupMessage").delay(DELAY_FEEDBACK).fadeOut();
  return newgroup;
};

/*-------
 SETUP JS ON GROUP BOX
 -------*/
jQuery(document).ready(function () {
  $(".GroupContainer").each(function () {
    if ($(this).attr("id") != "group-template") setupGroupBox($(this));
  });
});
const setupGroupBox = function (groupBox: JQuery) {
  const id = groupBox.data("id") as string | number;

  /* Change background color of group block if checked in selection mode */
  groupBox.find(".Group-checkbox input[type='checkbox']").change(function () {
    toogleSelection(
      id,
      groupBox.find(".Group-checkbox input[type='checkbox']").is(":checked"),
    );
  });
  // Was `.attr("checked", false)` -- jQuery's `.attr()` setter never
  // accepted a boolean (masked by this file's pre-P47 `any` typing);
  // `.prop("checked", ...)` is the correct API for a checkbox's boolean
  // state, matching every other checked-state toggle in this same file
  // (e.g. `toogleSelection`'s own `.prop("checked", true/false)` below).
  groupBox
    .find(".Group-checkbox input[type='checkbox']")
    .prop("checked", false);

  /* Display the option on the click on "..." */
  groupBox.find(".group-dropdown-options").click(function GroupOptions(
    this: HTMLElement,
  ) {
    $(this).find("#GroupOptions").toggle();
  });

  /* Set the delete action */
  groupBox.find("#GroupDelete").on("click", function () {
    deleteGroup(id);
  });

  /* Set the rename action */
  groupBox
    .find(".Group-name .icon-pencil, #GroupEdit")
    .on("click", function () {
      displayRenameForm(true, id);
      setTimeout(() => {
        groupBox.find("#GroupOptions").hide();
      }, 10);
    });

  groupBox.find(".group-rename .validate").on("click", function () {
    groupBox.find(".group-rename form").trigger("submit");
  });

  groupBox.find(".group-rename form").on("submit", function (e) {
    e.preventDefault();
    renameGroup(id, String(groupBox.find(".group_name-editable").val()));
  });

  groupBox.find(".group-rename .icon-cancel").on("click", function () {
    displayRenameForm(false, id);
    groupBox
      .find(".group_name-editable")
      .val(groupBox.find(".Group-name-container p").html());
  });

  /* Hide group options and rename field on click on the screen */

  $(document).mouseup(function (e) {
    e.stopPropagation();
    let option_is_clicked = false;
    $("#GroupOptions div").each(function () {
      if (!($(this).has(e.target as unknown as Element).length === 0)) {
        option_is_clicked = true;
      }
    });
    if (!option_is_clicked) {
      groupBox.find("#GroupOptions").hide();
    }
  });

  /* Setup the default action */
  if (groupBox.data("default") == 1) {
    setupDefaultActions(id, true);
  } else if (groupBox.data("default") == 0) {
    setupDefaultActions(id, false);
  }

  groupBox.find(".manage-users").on("click", function () {
    openUserManager(id);
  });

  groupBox.find("#GroupDuplicate").on("click", function () {
    duplicateAction(id);
  });
};

const toogleSelection = function (group_id: string | number, toggle: boolean) {
  const groupBox = $("#group-" + group_id);
  if (toggle) {
    groupBox.find(".Group-checkbox input").prop("checked", true);
    groupBox.addClass("GroupBackgroudSelected");
    groupBox.find(".icon-users-1").addClass("OrangeIcon");
    groupBox.find(".group_number_users").addClass("OrangeFont");

    //Display item selection on selection panel
    const item = $(
      "<div data-id=" +
        group_id +
        ">" +
        "<a class='icon-cancel'></a>" +
        "<p>" +
        groupBox.find("#group_name").html() +
        "</p> </div>",
    );
    item.appendTo(".DeleteGroupList");
    item.find("a").on("click", function () {
      groupBox.find(".Group-checkbox input").prop("checked", false);
      toogleSelection(group_id, false);
    });
    updateSelectionPanel();
    const option = $(
      '<option value="' +
        group_id +
        '">' +
        groupBox.find("#group_name").html() +
        "</option>",
    );
    option.appendTo("#MergeOptionsChoices");
  } else {
    groupBox.find(".Group-checkbox input").prop("checked", false);
    groupBox.removeClass("GroupBackgroudSelected");
    groupBox.find(".icon-users-1").removeClass("OrangeIcon");
    groupBox.find(".group_number_users").removeClass("OrangeFont");
    $(".DeleteGroupList div").each(function () {
      if ($(this).data("id") == group_id) {
        $(this).remove();
      }
    });
    updateSelectionPanel();
    $("#MergeOptionsChoices option").each(function () {
      if ($(this).attr("value") == group_id) {
        $(this).remove();
      }
    });
  }
};

/* Group Ajax and Display Functions */
const deleteGroup = function (id: string | number) {
  $.confirm({
    title: str_delete.replace("%s", $("#group-" + id + " #group_name").html()),
    draggable: false,
    titleClass: "jconfirmDeleteConfirm",
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
        text: str_yes_delete_confirmation,
        btnClass: "btn-red",
        action: function () {
          const groupName = $(
            "#group-" + id + " .Group-name-container p",
          ).html();
          $.alert({
            ...{
              title: str_group_deleted.replace("%s", groupName),
              content: function () {
                return jQuery.ajax({
                  url: "api/v1/groups/" + id,
                  type: "DELETE",
                  headers: {
                    "X-CSRF-Token": pwg_token,
                  },
                  dataType: "json",
                  success: function (
                    _data: operations["groupDelete"]["responses"][200]["content"]["application/json"],
                  ) {
                    $("#group-" + id).remove();
                    $(".DeleteGroupList div[data-id=" + id + "]").remove();
                    $("#MergeOptionsChoices option[value=" + id + "]").remove();
                    updateBadge();
                  },
                  error: function (err: JQuery.jqXHR) {
                    console.log(err);
                  },
                });
              },
            },
            ...jConfirm_alert_options,
          });
        },
      },
      cancel: {
        text: str_no_delete_confirmation,
      },
    },
  });
};

const renameGroup = function (id: string | number, newName: string) {
  const loadState = new window.TemporaryState();
  loadState.changeHTML(
    $("#group-" + id + " .group-rename .validate"),
    "<i class='animate-spin icon-spin6'></i>",
  );
  loadState.removeClass(
    $("#group-" + id + " .group-rename .validate"),
    "icon-ok",
  );
  loadState.changeAttribute(
    $("#group-" + id + " .group-rename span"),
    "style",
    "pointer-events: none",
  );

  if (newName.replace(/\s/g, "").length != 0) {
    jQuery.ajax({
      url: "api/v1/groups/" + id,
      type: "PATCH",
      contentType: "application/json",
      headers: {
        "X-CSRF-Token": pwg_token,
      },
      data: JSON.stringify({
        name: newName,
      }),
      dataType: "json",
      success: function (
        data: operations["groupUpdate"]["responses"][200]["content"]["application/json"],
      ) {
        loadState.reverse();
        newName = data.name;
        //Display message
        $("#group-" + id)
          .find(".groupMessage")
          .html(str_renaming_done);
        $("#group-" + id)
          .find(".groupMessage")
          .fadeIn();
        $("#group-" + id)
          .find(".groupMessage")
          .delay(DELAY_FEEDBACK)
          .fadeOut();
        $("#group-" + id)
          .find("#group_name")
          .html(newName);

        //Hide editable field
        displayRenameForm(false, id);
      },
      error: function (_err: JQuery.jqXHR) {
        loadState.reverse();
        //Display error message
        $("#group-" + id)
          .find(".groupError")
          .html(str_name_taken);
        $("#group-" + id)
          .find(".groupError")
          .fadeIn();
        $("#group-" + id)
          .find(".groupError")
          .delay(DELAY_FEEDBACK)
          .fadeOut();
      },
    });
  } else {
    loadState.reverse();
    $("#group-" + id)
      .find(".groupError")
      .html(str_name_not_empty);
    $("#group-" + id)
      .find(".groupError")
      .fadeIn();
    $("#group-" + id)
      .find(".groupError")
      .delay(DELAY_FEEDBACK)
      .fadeOut();
  }
};

// Hide or display rename form
const displayRenameForm = function (
  doDisplay: boolean,
  grp_id: string | number,
) {
  if (doDisplay) {
    $("#group-" + grp_id)
      .find(".group-rename")
      .css("display", "flex");
    $("#group-" + grp_id)
      .find(".Group-name-container .icon-pencil")
      .hide();
    $("#group-" + grp_id)
      .find(".Group-name-container p")
      .css("opacity", 0);
  } else {
    $("#group-" + grp_id)
      .find(".group-rename")
      .hide();
    $("#group-" + grp_id)
      .find(".Group-name-container .icon-pencil")
      .removeAttr("style");
    $("#group-" + grp_id)
      .find(".Group-name-container p")
      .css("opacity", 1);
  }
};

const setDefaultGroup = function (id: string | number, is_default: boolean) {
  $("#group-" + id + " #GroupDefault").css(
    "width",
    $("#group-" + id + " #GroupDefault").width()!,
  );
  $("#group-" + id + " #GroupDefault").html(
    "<i class='icon-spin6 animate-spin'> </i>",
  );
  $("#group-" + id + " #GroupDefault").removeClass("icon-star");
  $("#group-" + id + " #GroupDefault").attr(
    "style",
    "pointer-events: none; text-align: center;",
  );
  $("#group-" + id)
    .find(".is-default-token")
    .addClass("icon-spin6")
    .addClass("animate-spin")
    .removeClass("icon-star");
  jQuery.ajax({
    url: "api/v1/groups/" + id,
    type: "PATCH",
    contentType: "application/json",
    headers: {
      "X-CSRF-Token": pwg_token,
    },
    data: JSON.stringify({
      isDefault: is_default,
    }),
    dataType: "json",
    success: function (
      _data: operations["groupUpdate"]["responses"][200]["content"]["application/json"],
    ) {
      $("#group-" + id + " #GroupOptions").hide();
      if (is_default) {
        setupDefaultActions(id, true);
      } else {
        setupDefaultActions(id, false);
      }
    },
    error: function (err: JQuery.jqXHR) {
      console.log(err);
    },
  });
};

const setupDefaultActions = function (
  id: string | number,
  is_default: boolean,
) {
  $("#group-" + id + " #GroupDefault").attr("style", "");
  $("#group-" + id + " #GroupDefault").addClass("icon-star");
  $("#group-" + id)
    .find(".is-default-token")
    .removeClass("icon-spin6")
    .removeClass("animate-spin")
    .addClass("icon-star");
  if (is_default) {
    $("#group-" + id)
      .find("#GroupDefault")
      .html(str_unset_default);
    $("#group-" + id)
      .find(".is-default-token")
      .attr("title", str_unset_default);
    $("#group-" + id)
      .find("#GroupDefault")
      .unbind("click");
    $("#group-" + id)
      .find(".is-default-token")
      .removeClass("deactivate");
    $("#group-" + id)
      .find("#GroupDefault")
      .on("click", function () {
        setDefaultGroup(id, false);
      });
    $("#group-" + id)
      .find(".is-default-token")
      .on("click", function () {
        setDefaultGroup(id, false);
      });
  } else {
    $("#group-" + id)
      .find("#GroupDefault")
      .html(str_set_default);
    $("#group-" + id)
      .find(".is-default-token")
      .attr("title", str_set_default);
    $("#group-" + id)
      .find(".is-default-token")
      .addClass("deactivate");
    $("#group-" + id)
      .find("#GroupDefault")
      .on("click", function () {
        setDefaultGroup(id, true);
      });
    $("#group-" + id)
      .find(".is-default-token")
      .unbind("click");
  }
};

const duplicateAction = function (id: string | number) {
  const loadState = new window.TemporaryState();
  loadState.changeHTML(
    $("#group-" + id + " #GroupDuplicate"),
    "<i class='icon-spin6 animate-spin'> </i>",
  );
  loadState.removeClass($("#group-" + id + " #GroupDuplicate"), "icon-docs");
  loadState.changeAttribute(
    $("#group-" + id + " #GroupDuplicate"),
    "style",
    "pointer-events: none; text-align: center;",
  );
  let copy_name = $("#group-" + id + " #group_name").html() + str_copy;

  const name_exist = function (name: string) {
    let exist = false;
    $(".Group-name-container p").each(function () {
      if ($(this).html() === name) exist = true;
    });
    return exist;
  };

  let i = 1;
  while (name_exist(copy_name)) {
    copy_name =
      $("#group-" + id + " #group_name").html() +
      str_other_copy.replace("%s", String(i++));
  }

  jQuery.ajax({
    url: "api/v1/groups/" + id + "/actions/duplicate",
    type: "POST",
    contentType: "application/json",
    headers: {
      "X-CSRF-Token": pwg_token,
    },
    data: JSON.stringify({
      name: copy_name,
    }),
    dataType: "json",
    success: function (
      data: operations["groupDuplicate"]["responses"][201]["content"]["application/json"],
    ) {
      loadState.reverse();
      $("#group-" + id + " #GroupOptions").hide();
      const group = data;
      const groupbox = createGroup(group);
      groupbox.insertAfter($("#group-" + id));
      setupGroupBox(groupbox);
      updateBadge();

      if (data.isDefault === true) {
        setupDefaultActions(data.id, true);
      }
    },
    error: function (err: JQuery.jqXHR) {
      loadState.reverse();
      console.log(err);
    },
  });
};

/*-------
 Selection mode toggle actions,  
 changes "..." in group block to checkbox,
 disables group actions in selection mode
 -------*/

$(function () {
  $("#toggleSelectionMode").prop("checked", false);
  $("#toggleSelectionMode").click(function () {
    if ($(this).is(":checked")) {
      $(".in-selection-mode").show();
      $(".not-in-selection-mode").hide();
      $(".GroupManagerButtons").removeClass("visible");
    } else {
      $(".in-selection-mode").hide();
      $(".not-in-selection-mode").removeAttr("style");
      $(".Group-checkbox input").prop("checked", false);
      $(".Group-checkbox input[type='checkbox']").trigger("change");
    }
  });
});

/*-------
 Update Selection Panel
 -------*/
let state = "NoSelection";

const updateSelectionPanel = function (changedState: string = "") {
  const numSelect = $(".DeleteGroupList div").length;

  if (numSelect == 0) {
    updateStatePanel("NoSelection");
  } else if (changedState == "") {
    if (numSelect == 1 && state != "ConfirmDeletion")
      updateStatePanel("OneSelected");
    if (numSelect > 1 && state == "OneSelected") updateStatePanel("Selection");
  } else {
    if (changedState == "Selection" && numSelect == 1)
      updateStatePanel("OneSelected");
    else updateStatePanel(changedState);
  }

  $(".number-Selected").html(numSelect + "");
};

/*Update the state of the panel in 5 states :
 NoSelection, OneSelected, ConfirmDeletion, Selection, OptionMerge
 */
const updateStatePanel = function (newState: string = "Selection") {
  state = newState;
  switch (newState) {
    case "OneSelected":
      $("#DeleteSelectionMode").show();
      $("#MergeSelectionMode").show();
      buttonUnavailable($("#MergeSelectionMode"));
      buttonAvailable(
        $("#DeleteSelectionMode"),
        "updateSelectionPanel('ConfirmDeletion')",
      );
      $("#MergeOptionsBlock").hide();
      $("#ConfirmGroupAction").hide();
      break;
    case "ConfirmDeletion":
      $("#DeleteSelectionMode").hide();
      $("#MergeSelectionMode").hide();
      $("#MergeOptionsBlock").hide();
      $("#ConfirmGroupAction").show();
      break;
    case "Selection":
      $("#DeleteSelectionMode").show();
      $("#MergeSelectionMode").show();
      buttonAvailable(
        $("#MergeSelectionMode"),
        "updateSelectionPanel('OptionMerge')",
      );
      buttonAvailable(
        $("#DeleteSelectionMode"),
        "updateSelectionPanel('ConfirmDeletion')",
      );
      $("#MergeOptionsBlock").hide();
      $("#ConfirmGroupAction").hide();
      break;
    case "OptionMerge":
      $("#DeleteSelectionMode").hide();
      $("#MergeSelectionMode").hide();
      $("#MergeOptionsBlock").show();
      $("#ConfirmGroupAction").hide();
      break;
  }
  if (newState == "NoSelection") {
    $("#DeleteSelectionMode").show();
    $("#MergeSelectionMode").show();
    buttonUnavailable($("#MergeSelectionMode"));
    buttonUnavailable($("#DeleteSelectionMode"));
    $(".SelectionModeGroup").hide();
    $("#nothing-selected").show();
    $("#MergeOptionsBlock").hide();
    $("#ConfirmGroupAction").hide();
  } else {
    $(".SelectionModeGroup").show();
    $("#nothing-selected").hide();
  }
};

const buttonAvailable = function (button: JQuery, onClick: string) {
  button.removeClass("unavailable");
  button.attr("OnClick", onClick);
};

const buttonUnavailable = function (button: JQuery) {
  button.addClass("unavailable");
  button.removeAttr("OnClick");
};

/*-------
 Merge function on button's pannel
 -------*/

$(".ConfirmMergeButton").on("click", function () {
  const loadState = new window.TemporaryState();
  loadState.changeAttribute(
    $(".ConfirmMergeButton"),
    "style",
    "pointer-events: none",
  );
  loadState.changeHTML(
    $(".ConfirmMergeButton"),
    "<i class='icon-spin6 animate-spin'> </i>",
  );
  loadState.removeClass($(".ConfirmMergeButton"), "icon-ok");
  const merge_group: number[] = [];
  const name_merge: string[] = [];
  // Always overwritten inside the loop below before use (`dest_grp`
  // always matches exactly one selected group's data-id) -- typed as a
  // string, not the original `[]` placeholder, which never matched the
  // value actually assigned or read.
  let name_dest = "";
  // Single-value <select>, never multi -- `.val()`'s generic
  // `JQuery<HTMLElement>` overload would otherwise widen to
  // `string | number | string[] | undefined`.
  const dest_grp = $("#MergeOptionsChoices").val() as string;

  $(".DeleteGroupList div").each(function () {
    if (dest_grp != $(this).attr("data-id")) {
      merge_group.push(Number($(this).attr("data-id")));
      name_merge.push($(this).find("p").html());
    } else {
      name_dest = $(this).find("p").html();
    }
  });

  jQuery.ajax({
    url: "api/v1/groups/actions/merge",
    type: "POST",
    contentType: "application/json",
    headers: {
      "X-CSRF-Token": pwg_token,
    },
    data: JSON.stringify({
      destinationGroupId: Number(dest_grp),
      mergeGroupIds: merge_group,
    }),
    dataType: "json",
    success: function (
      _data: operations["groupMerge"]["responses"][200]["content"]["application/json"],
    ) {
      loadState.reverse();
      updateSelectionPanel("Selection");
      merge_group.forEach(function (id: number) {
        $("#group-" + id).fadeOut(function () {
          $(this).remove();
        });
      });
      toogleSelection(dest_grp, false);
      $(".DeleteGroupList").html("");
      $("#MergeOptionsChoices").html("");

      $.alert({
        ...{
          title: str_merged_into
            .replace("%s1", name_merge.toString())
            .replace("%s2", name_dest),
          content: "",
        },
        ...jConfirm_alert_options,
      });

      $("#group-" + String(dest_grp) + " .group_number_users").html(
        "<i class='icon-spin6 animate-spin'> </i>",
      );
      jQuery.ajax({
        url: "api/v1/users",
        type: "GET",
        data: {
          groupIds: [dest_grp],
        },
        dataType: "json",
        success: function (data: GroupUserListResponse) {
          const number = data.users.length;
          $("#group-" + String(dest_grp) + " .group_number_users").html(
            number +
              " " +
              (number > 1 ? str_members_default : str_member_default),
          );
          updateBadge();
        },
      });
    },
    error: function (err: JQuery.jqXHR) {
      loadState.reverse();
      console.log(err);
    },
  });
});

/*-------
 Delete function on button's pannel
 -------*/

$(".ConfirmDeleteButton").on("click", function () {
  const names: string[] = [];
  const ids: (string | number)[] = [];
  $(".DeleteGroupList div").each(function () {
    const id = $(this).data("id") as string | number;
    names.push($("#group-" + id + " #group_name").html());
    ids.push(id);
  });

  const loadState = new window.TemporaryState();
  loadState.changeAttribute(
    $(".ConfirmDeleteButton"),
    "style",
    "pointer-events: none",
  );
  loadState.changeHTML(
    $(".ConfirmDeleteButton"),
    "<i class='icon-spin6 animate-spin'> </i>",
  );
  loadState.removeClass($(".ConfirmDeleteButton"), "icon-ok");

  // No bulk-delete endpoint (a REST single-resource DELETE per group,
  // per P27's own design) -- fire one DELETE per selected group.
  Promise.all(
    ids.map(function (id: string | number) {
      return jQuery.ajax({
        url: "api/v1/groups/" + id,
        type: "DELETE",
        headers: {
          "X-CSRF-Token": pwg_token,
        },
        dataType: "json",
      });
    }),
  )
    .then(function () {
      $(".DeleteGroupList div").each(function () {
        $(this).remove();
        $("#group-" + $(this).attr("data-id")).remove();
        $(
          "#MergeOptionsChoices option[value=" + $(this).attr("data-id") + "]",
        ).remove();
      });

      loadState.reverse();
      updateSelectionPanel("NoSelection");
      $.alert({
        ...{
          title: str_groups_deleted.replace("%s", names.toString()),
          content: "",
        },
        ...jConfirm_alert_options,
      });
      updateBadge();
    })
    .catch(function (err: JQuery.jqXHR) {
      loadState.reverse();
      console.log(err);
    });
});

/*-------
 Manage User Part
 -------*/

// Initialize the research user bar
let selectize: Selectize.IApi<string | number, UserSelectOption>;

// Initialize the cache -- placeholder cast, real init happens via
// `new window.UsersCache(...)` inside updateUserSearch()/at module load
// (below) before any handler that reads it can actually run.
let usersCache = {} as EntityCacheInstance<UserEntity>;

let usersInGroup: GroupMemberDisplay[] = [];

// Max offset of the user container (322 = 6 lines)
const maxOffsetUserCont = 322;

const dissociateUserInfo = $(
  "<div class='ValidationUserDissociated'>" +
    "<p class='icon-ok'></p>" +
    "</div>",
)
  .appendTo(".group-name-block")
  .hide();

const associateUserInfo = $(
  "<div class='ValidationUserAssociated'>" +
    "<p class='icon-ok'></p>" +
    "</div>",
);

// Setup the user research bar
// Declared here, at module scope (not inside the ready callback below), because getUserDisplay()/the .input-user-name handler further down -- both textually outside this IIFE -- call it too. The real function body still closes over idSearch/selectize/usersCache exactly as before: JS closures capture their enclosing lexical scope by reference regardless of where the containing variable itself is declared.
let updateUserSearch: () => void;

$(function () {
  // initialize the Selectize control
  const $select = $(".AddUserBlock select").selectize({});

  // fetch the instance -- ambient `HTMLElement.selectize` (from
  // @types/selectize) ships as `Selectize.IApi<any, any>`; narrowed to
  // this file's real value/option shape.
  selectize = $select[0]!.selectize as Selectize.IApi<
    string | number,
    UserSelectOption
  >;

  let idSearch = "";
  $(".UserSearch input").on("focus", function () {
    if (idSearch != $("#UserList").attr("data-group_id")) {
      updateUserSearch();
    }
  });

  // Update User search bar (remove group users in selection)
  updateUserSearch = function () {
    selectize.clear();
    // Was `if (usersCache = {}) {...}` -- a genuine pre-existing typo
    // (assignment, not comparison), always-truthy so the branch always
    // ran anyway; TS-forced fix via `no-cond-assign`, not just a type
    // gap. Same observable behavior: usersCache is unconditionally
    // rebuilt on every call.
    usersCache = new window.UsersCache({
      serverKey: serverKey,
      serverId: serverId,
      rootUrl: rootUrl,
    });
    // Non-null: `new window.UsersCache(...)` above synchronously
    // seeds this storage slot before any handler that reads it can run
    // (the P46-preserved "temporary fix for #1283" behavior, see the
    // module-load call at the bottom of this file).
    const cached: { data: UserEntity[] } = JSON.parse(
      (usersCache.storage as unknown as Record<string, string>)[
        usersCache.key
      ]!,
    );
    cached.data.forEach(function (u) {
      selectize.addOption({ value: u.id, text: u.username });
    });
    idSearch = $("#UserList").attr("data-group_id") ?? "";
    // Was `value.username` -- no such field exists on the actual
    // selectize option data (`addOption({value, text})` above only ever
    // sets `value`/`text`), so this comparison was always false and the
    // guest user was never really filtered out of the search dropdown.
    for (const [_key, value] of Object.entries(selectize.options)) {
      if (value.text === "guest") {
        selectize.removeOption(value.value);
      }
    }
    $(".UsernameBlock").each(function () {
      selectize.removeOption($(this).data("id") as string | number);
    });
  };
});

// Display the user manager for a specific group
const openUserManager = function (grp_id: string | number) {
  const loadState = new window.TemporaryState();
  loadState.removeClass(
    $("#group-" + grp_id + " #UserListTrigger"),
    "icon-user-1",
  );
  loadState.changeAttribute(
    $("#group-" + grp_id + " #UserListTrigger"),
    "style",
    "pointer-events: none",
  );
  loadState.changeHTML(
    $("#group-" + grp_id + " #UserListTrigger"),
    "<i class='icon-spin6 animate-spin'> </i>",
  );
  jQuery.ajax({
    url: "api/v1/users",
    type: "GET",
    data: {
      groupIds: [grp_id],
    },
    dataType: "json",
    success: function (data: GroupUserListResponse) {
      loadState.reverse();
      //Set the popin name
      $(".group-name-block p").html(
        $("#group-" + grp_id + " #group_name").html() + " / " + str_user_list,
      );
      $(".UsersInGroupList").html("");

      //Display the popin
      $("#UserList").fadeIn();

      //Fill with user blocks
      usersInGroup = data.users;
      // Sort in alphabetic order
      usersInGroup.sort(function (a, b) {
        if (a.username.toLowerCase() < b.username.toLowerCase()) {
          return -1;
        } else return 1;
      });
      let i = 0;
      while (
        $(".UsersInGroupList").outerHeight()! <= maxOffsetUserCont &&
        usersInGroup[i] != undefined
      ) {
        getUserDisplay(
          usersInGroup[i]!.username,
          usersInGroup[i]!.id,
          grp_id,
        ).appendTo(".UsersInGroupList");
        i++;
      }
      while ($(".UsersInGroupList").height()! > maxOffsetUserCont) {
        $(".UsernameBlock").last().remove();
      }
      updateMembernumber(usersInGroup.length, grp_id);
      //Attribute the group id to the div
      $("#UserList").attr("data-group_id", grp_id);

      $(".LinkUserManager a").attr(
        "href",
        "admin.php?page=user_list&group=" + grp_id,
      );
    },
    error: function (err: JQuery.jqXHR) {
      loadState.reverse();
      console.log(err);
    },
  });
};

//Add a user block
const getUserDisplay = function (
  username: string,
  user_id: string | number,
  grp_id: string | number,
) {
  const userBlock = $(
    '<div class="UsernameBlock" data-id=' +
      user_id +
      ">" +
      '<span class="icon-user-1"></span>' +
      "<p>" +
      username +
      "</p>" +
      '<div class="Tooltip">' +
      '<span class="icon-cancel"></span>' +
      '<p class="TooltipText">' +
      str_user_dissociate +
      "</p>" +
      "</div>" +
      "</div>",
  );

  while ($(".UsersInGroupList")[0]!.offsetHeight > maxOffsetUserCont) {
    $(".UsernameBlock").last().remove();
  }

  //Setup the delete action
  userBlock.find(".icon-cancel").on("click", function () {
    userBlock.find(".icon-cancel").addClass("icon-spin6");
    userBlock.find(".icon-cancel").addClass("animate-spin");
    userBlock.find(".icon-cancel").css("pointer-events", "none");
    userBlock.find(".icon-cancel").removeClass("icon-cancel");
    jQuery.ajax({
      url: "api/v1/groups/" + grp_id + "/actions/remove-user",
      type: "POST",
      contentType: "application/json",
      headers: {
        "X-CSRF-Token": pwg_token,
      },
      data: JSON.stringify({
        userIds: [Number(user_id)],
      }),
      dataType: "json",
      success: function (
        _data: operations["groupRemoveUser"]["responses"][200]["content"]["application/json"],
      ) {
        const str = str_user_dissociated.replace("%s", username);
        associateUserInfo.fadeOut();
        dissociateUserInfo.find("p").html(str);
        dissociateUserInfo.fadeIn();

        $(".UsernameBlock").css("margin-right", "10px").css("border", "none");
        userBlock.remove();

        updateUserSearch();

        while ($(".UsersInGroupList").height()! > maxOffsetUserCont) {
          $(".UsernameBlock").last().remove();
        }

        usersInGroup = usersInGroup.filter((u) => u.id != user_id);

        //Update member number
        updateMembernumber(parseInt($(".UserNumberBadge").html()) - 1, grp_id);
      },
    });
  });
  return userBlock;
};

//Update member number function
function updateMembernumber(number: number, grp_id: string | number) {
  $(".GroupContainer[data-id=" + grp_id + "] .group_number_users").html(
    number + " " + (number > 1 ? str_members_default : str_member_default),
  );
  $(".UserNumberBadge").html(String(number));
  $(".AmountOfUsersShown strong:nth-child(2)").html(String(number));
  $(".AmountOfUsersShown strong:nth-child(1)").html(
    String($(".UsernameBlock").length),
  );
}

// Close pop-up on cross click
$(".CloseUserList").on("click", function () {
  $("#UserList").fadeOut();
});

// Adding Group Action
$(".AddUserBlock button").on("click", function () {
  const grp_id = $("#UserList").attr("data-group_id") ?? "";
  // Get selected ids -- `@types/selectize`'s own `getValue(): any` is a
  // real, incomplete vendor declaration (same gap as `.storage`
  // indexing above), narrowed to this file's real value shape.
  const id = selectize.getValue() as string | number;

  if (id != "") {
    const loadState = new window.TemporaryState();
    loadState.changeHTML(
      $("#UserSubmit"),
      "<i class='icon-spin6 animate-spin'> </i>",
    );
    loadState.removeClass($("#UserSubmit"), "icon-user-add");
    loadState.changeAttribute($("#UserSubmit"), "css", "pointer-events:none");
    jQuery.ajax({
      url: "api/v1/groups/" + grp_id + "/actions/add-user",
      type: "POST",
      contentType: "application/json",
      headers: {
        "X-CSRF-Token": pwg_token,
      },
      data: JSON.stringify({
        userIds: [Number(id)],
      }),
      dataType: "json",
      success: function (
        _data: operations["groupAddUser"]["responses"][200]["content"]["application/json"],
      ) {
        loadState.reverse();

        // Get the username
        let username = "undefined";
        // Non-null: same "always-seeded by now" invariant as
        // updateUserSearch()'s own identical read, above.
        const cached: { data: UserEntity[] } = JSON.parse(
          (usersCache.storage as unknown as Record<string, string>)[
            usersCache.key
          ]!,
        );
        cached.data.forEach(function (u) {
          if (u.id == id) {
            username = u.username;
          }
        });
        const userBlock = getUserDisplay(username, id, grp_id).prependTo(
          ".UsersInGroupList",
        );

        dissociateUserInfo.fadeOut();

        $(".UsernameBlock:first").addClass("success_message");
        $(".UsernameBlock")
          .slice(1)
          .css("margin-right", "10px")
          .css("border", "none");
        associateUserInfo.remove();
        associateUserInfo.insertAfter(userBlock);
        associateUserInfo.find("p").html(str_user_associated);
        associateUserInfo.fadeIn();

        updateUserSearch();

        usersInGroup.push({ username: username, id: id });

        while ($(".UsersInGroupList").height()! > maxOffsetUserCont) {
          $(".UsernameBlock").last().remove();
        }

        //Update member number
        updateMembernumber(parseInt($(".UserNumberBadge").html()) + 1, grp_id);
      },
      error: function (err: JQuery.jqXHR) {
        loadState.reverse();
        console.log(err);
      },
    });
  }
});

$(".input-user-name").on("input", function () {
  const searchString = String($(this).val()).toLowerCase();
  const grp_id = $(".UserListPopIn").data("group_id") as string | number;
  if (searchString != "") {
    $(".UsersInGroupListContainer").css(
      "min-height",
      $(".UsersInGroupListContainer").height()!,
    );
    usersInGroup.forEach(function (u) {
      const isSearched = u.username.toLowerCase().includes(searchString);
      if ($(".UsernameBlock[data-id=" + u.id + "]").length != 0) {
        if (!isSearched) {
          $(".UsernameBlock[data-id=" + u.id + "]").remove();
        }
      } else if (isSearched) {
        getUserDisplay(u.username, u.id, grp_id).prependTo(".UsersInGroupList");
      }
    });
  } else {
    $(".UsersInGroupListContainer").css("min-height", "");
    $(".UsersInGroupList").html("");
    let i = 0;
    while (
      $(".UsersInGroupList").outerHeight()! <= maxOffsetUserCont &&
      usersInGroup[i] != undefined
    ) {
      getUserDisplay(
        usersInGroup[i]!.username,
        usersInGroup[i]!.id,
        grp_id,
      ).appendTo(".UsersInGroupList");
      i++;
    }
  }
  $(".AmountOfUsersShown strong:nth-child(1)").html(
    String($(".UsernameBlock").length),
  );
  while ($(".UsersInGroupList").height()! > maxOffsetUserCont) {
    $(".UsernameBlock").last().remove();
  }
});

const pwg_token = pwg_getPageData<string>("csrf_token");
const str_member_default = pwg_getPageString("member");
const str_members_default = pwg_getPageString("members");
const str_group_created = pwg_getPageString("Group added");
const str_renaming_done = pwg_getPageString("Group renamed");
const str_name_taken = pwg_getPageString("Name is already taken");
const str_name_not_empty = pwg_getPageString("Name field must not be empty");
const str_group_deleted = pwg_getPageString('Group "%s" succesfully deleted');
const str_groups_deleted = pwg_getPageString("Groups {%s} succesfully deleted");
const str_set_default = pwg_getPageString("Set as group for new users");
const str_unset_default = pwg_getPageString("Unset as group for new users");
const str_delete = pwg_getPageString(
  'Are you sure you want to delete group "%s"?',
);
const str_yes_delete_confirmation = pwg_getPageString("Yes, delete");
const str_no_delete_confirmation = pwg_getPageString(
  "No, I have changed my mind",
);
const str_user_associated = pwg_getPageString("User associated");
const str_user_dissociate = pwg_getPageString(
  "Dissociate user from this group",
);
const str_user_dissociated = pwg_getPageString(
  'User "%s" dissociated from this group',
);
const str_user_list = pwg_getPageString("Manage the members");
const str_merged_into = pwg_getPageString(
  'Group(s) {%s1} succesfully merged into "%s2"',
);
const str_copy = pwg_getPageString(" (copy)");
const str_other_copy = pwg_getPageString(" (copy %s)");

const serverKey = pwg_getPageData<string>("cache_key_users");
const serverId = pwg_getPageData<string>("cache_key_hash");
const rootUrl = pwg_getPageData<string>("root_url");

$(document).on("keydown", function (e) {
  if (e.keyCode === 27) {
    // ESC button
    $("#UserList").fadeOut();
  }
});
$(document).on("click", function (e) {
  if (
    $(e.target as unknown as Element).closest(".UserListPopInContainer")
      .length === 0
  ) {
    $("#UserList").fadeOut();
  }
});

// temporary fix for #1283 (begin) : force user local storage cache on page load.
usersCache = new window.UsersCache({
  serverKey: serverKey,
  serverId: serverId,
  rootUrl: rootUrl,
});

usersCache.selectize(jQuery("select.UserSearch"));
// temporary fix for #1283 (end)

// Explicit `window.` exposure -- required at runtime, not decorative
// (see plugins_installed_config.ts's own leading comment for the full
// explanation). `hideAddGroupForm` is called from group_list.latte's
// own `onclick="hideAddGroupForm()"` attribute; `updateSelectionPanel`
// is called from a dynamically-set `onclick`/`OnClick` HTML attribute
// (buttonAvailable()'s own `button.attr("OnClick", onClick)`, a string
// like `"updateSelectionPanel('Selection')"`) -- the exact same
// javascript:/onclick= exposure requirement, just set via JS instead
// of a static Latte attribute.
window.hideAddGroupForm = hideAddGroupForm;
window.updateSelectionPanel = updateSelectionPanel;
