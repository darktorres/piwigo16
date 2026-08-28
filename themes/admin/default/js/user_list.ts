import type { components, operations } from "../../../../openapi/client/schema";
import { getRandomInt, sprintf, jConfirm_confirm_options } from "./common";

import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../default/js/page-data";
import { ajax, type AjaxThenable } from "../../../default/js/vendor/ajax";
export {};

// The real `GET /api/v1/users` per-row shape (P47) -- `current_users`/
// `guest_user` are both populated straight from that endpoint's own
// response (`current_users = data.users` / `guest_user = data.users[0]`,
// below), and every `fill_user_edit_*` helper reads real User schema
// fields (status/level/email/groups/enabledHigh/nbImagePage/theme/
// language/expand/showNbComments/showNbHits/registration*/lastVisit*).
type UserRow = components["schemas"]["User"];
type UserListResponse =
  operations["userList"]["responses"][200]["content"]["application/json"];

// `selectAllPage`/`selectInvert`'s own real pushed shape -- only `id`/
// `username` are ever read off a `selection` entry. `username` is
// genuinely optional: `select_whole_set()` (below) pushes id-only
// entries for a whole-database selection (fetching every username
// upfront would be wasteful), lazily backfilled for just the first 50
// via `get_first_selection_usernames()` -- callers already defensively
// check `typeof ... !== "undefined"` before reading it.
interface SelectionEntry {
  id: number;
  username?: string;
}

// `groupOptions`'s own real per-item shape (below), fed to the 3 real
// group-picker Selectize instances.
interface GroupOption {
  value: number;
  label: string;
  isSelected: boolean;
}

const color_icons = [
  "icon-red",
  "icon-blue",
  "icon-yellow",
  "icon-purple",
  "icon-green",
];
const status_arr = ["webmaster", "admin", "normal", "generic", "guest"];
const level_arr = ["0", "1", "2", "4", "8"];
const king_template = '<p class="icon-king" id="the_king"></p>';
let current_users: UserRow[] = [];
let guest_id = 0;
let guest_user: UserRow;
let connected_user = 0;
let groups_arr: [number, string][] = [];
let nb_days = "";
let nb_photos = "";
let last_user_index = -1;
let last_user_id = -1;
let pwg_token = "";
let selection: SelectionEntry[] = [];
let first_update = true;
let total_users = 0;
let filter_by = "id DESC";
const plugins_set_functions: Record<string, (...args: unknown[]) => unknown> =
  {};
const plugins_get_functions: Record<string, (...args: unknown[]) => unknown> =
  {};
const plugins_load: string[] = [];
const plugins_users_infos_table: { content_id: string; users_table: string }[] =
  [];
let owner_username = "";
/*----------------
Escape of pop-in
----------------*/

//get out of pop in via escape key
$(document).on("keydown", function (e) {
  if (e.keyCode === 27) {
    // ESC button
    hide_modals();
    close_user_list();
  }
});

//get out of pop in via clicking outside pop in
//$(document).on('click', function (e) {
//    console.log($(e.target));
//    if ($(e.target) && $(e.target).closest(".UserListPopInContainer").length === 0) {
//        $("#UserList").fadeOut();
//    }
//});

/*----------------
Group Selectize
----------------*/

jQuery("[data-selectize=groups]").selectize({
  valueField: "value",
  labelField: "label",
  searchField: ["label"],
  plugins: ["remove_button"],
});

const groupSelectize = jQuery("[data-selectize=groups]")[0]!
  .selectize as Selectize.IApi<string | number, GroupOption>;
const groupGuestSelectize = jQuery("[data-selectize=groups]")[1]!
  .selectize as Selectize.IApi<string | number, GroupOption>;
const groupAddUserSelectize = $("[data-selectize=groups]")[2]!
  .selectize as Selectize.IApi<string | number, GroupOption>;

/*-----------------
OnClick functions
-----------------*/
function open_user_list() {
  hide_temporary_messages();
  $("#UserList").fadeIn();
}

function close_user_list() {
  hide_temporary_messages();
  $("#result_send_mail_copy_input").val("");
  $("#UserList").fadeOut();
}

function open_guest_user_list() {
  hide_temporary_messages();
  $("#GuestUserList").fadeIn();
}

function close_guest_user_list() {
  hide_temporary_messages();
  $("#GuestUserList").fadeOut();
}

function isSelectionMode() {
  return $("#toggleSelectionMode").is(":checked");
}

$(document).ready(function () {
  $(".user-property-register").tipTip({
    maxWidth: "300px",
    delay: 0,
    fadeIn: 200,
    fadeOut: 200,
  });
  $(".user-property-last-visit").tipTip({
    maxWidth: "300px",
    delay: 0,
    fadeIn: 200,
    fadeOut: 200,
  });
  $(".advanced-filter-level select option").eq(1).remove();

  /* Edit Password */
  $(".button-edit-password-icon").click(function () {
    reset_password_modals();
    $(".user-property-password-change").fadeIn();
  });

  $(".edit-password-cancel").click(function () {
    $(".user-property-password-change").fadeOut();
    reset_input_password();
  });

  $(".icon-show-password").on("click", function () {
    const icon = $(this);
    const closestInput = icon.siblings();
    if (closestInput.attr("type") === "password") {
      closestInput.attr("type", "text");
      icon.removeClass("icon-eye").addClass("icon-eye-off");
    } else {
      closestInput.attr("type", "password");
      icon.removeClass("icon-eye-off").addClass("icon-eye");
    }
  });

  // Password input onkeyup clear error
  $("#edit_user_password, #edit_user_conf_password").on("keyup", function () {
    hide_error_edit_user();
  });

  /* Edit Username */
  $(".edit-username").click(function () {
    $(".user-property-username-change").show();
  });

  $(".edit-username-cancel").click(function () {
    //possibly reset input value
    $(".user-property-username-change").hide();
  });

  $("#UserList .close-update-button").click(close_user_list);
  $(".CloseUserList").click(close_user_list);

  $("#toggleSelectionMode").prop("checked", false);
  $("#toggleSelectionMode").click(function () {
    const isSelection = $(this).is(":checked");
    selectionMode(isSelection);
  });
  $(".edit-guest-user-button").click(open_guest_user_list);
  $(".CloseGuestUserList").click(close_guest_user_list);
  $("#GuestUserList .close-update-button").click(close_guest_user_list);
  /* Action */
  jQuery("[id^=action_]").hide();

  jQuery("select[name=selectAction]").change(function () {
    jQuery("#applyActionBlock .infos").hide();
    jQuery("[id^=action_]").hide();
    jQuery("#action_" + $(this).prop("value")).show();
    if (jQuery(this).val() != -1) {
      jQuery("#applyActionBlock").show();
    } else {
      jQuery("#applyActionBlock").hide();
    }
  });
  $(".yes_no_radio .user-list-checkbox")
    .unbind("click")
    .click(function () {
      if ($(this).attr("data-selected") !== "1") {
        $(this).attr("data-selected", "1");
        $(this).siblings().attr("data-selected", "0");
      }
    });
  $(".EditUserGenPassword").on("click", function () {
    const password = gen_password();
    $("#edit_user_password").val(password);
    $("#edit_user_conf_password").val(password);
    hide_error_edit_user();
  });
  $(".AddUserGenPassword span").on("click", function () {
    const password = gen_password();
    $("#add_user_pass").val(password);
    $("#add_user_confpass").val(password);
  });
  $(".AddUserSubmit").click(add_user);
  $(".AddUserCancel").click(add_user_close);
  $(".CloseAddUser").click(add_user_close);

  //open add user pop in
  $(".add-user-button").click(add_user_open);

  /* Select */

  jQuery("#selectSet").click(function () {
    select_whole_set();
    return false;
  });

  jQuery("#selectAllPage").click(function () {
    const selection_ids = selection.map((x) => x.id);
    for (let i = 0; i < current_users.length; i++) {
      if (!selection_ids.includes(current_users[i]!.id)) {
        selection.push({
          id: current_users[i]!.id,
          username: current_users[i]!.username,
        });
      }
    }
    update_selection_content();
    return false;
  });

  jQuery("#selectNone").click(function () {
    selection = [];
    update_selection_content();
    return false;
  });

  jQuery("#selectInvert").click(function () {
    const selection_ids = selection.map((x) => x.id);
    for (let i = 0; i < current_users.length; i++) {
      if (selection_ids.includes(current_users[i]!.id)) {
        selection.splice(
          selection.findIndex((x) => x.id == current_users[i]!.id),
          1,
        );
      } else {
        selection.push({
          id: current_users[i]!.id,
          username: current_users[i]!.username,
        });
      }
    }
    update_selection_content();
    return false;
  });

  $("#permitActionUserList select[name=selectAction]").val("-1");

  $(".advanced-filter-btn").click(advanced_filter_button_click);
  $(".advanced-filter span.icon-cancel").click(advanced_filter_hide);
  $(".advanced-filter-select").change(update_user_list);
  $("#user_search").on("input", update_user_list);

  /*View manager*/

  if ($("#displayCompact").is(":checked")) {
    setDisplayCompact();
  }

  if ($("#displayLine").is(":checked")) {
    setDisplayLine();
  }

  if ($("#displayTile").is(":checked")) {
    setDisplayTile();
  }

  $("#displayCompact").change(function () {
    setDisplayCompact();

    if ($(".addAlbum").hasClass("input-mode")) {
      $(".addAlbum p").hide();
    }
    set_view_selector("compact");
  });

  $("#displayLine").change(function () {
    setDisplayLine();

    if ($(".addAlbum").hasClass("input-mode")) {
      $(".addAlbum p").hide();
    }
    set_view_selector("line");
  });

  $("#displayTile").change(function () {
    setDisplayTile();

    if ($(".addAlbum").hasClass("input-mode")) {
      $(".addAlbum p").show();
    }
    set_view_selector("tile");
  });

  /* Pagination */

  switch (pagination) {
    case "5":
      $("#pagination-per-page-5").addClass("selected-pagination");
      $("#pagination-per-page-10").removeClass("selected-pagination");
      $("#pagination-per-page-25").removeClass("selected-pagination");
      $("#pagination-per-page-50").removeClass("selected-pagination");
      break;
    case "10":
      $("#pagination-per-page-5").removeClass("selected-pagination");
      $("#pagination-per-page-10").addClass("selected-pagination");
      $("#pagination-per-page-25").removeClass("selected-pagination");
      $("#pagination-per-page-50").removeClass("selected-pagination");

      break;
    case "25":
      $("#pagination-per-page-5").removeClass("selected-pagination");
      $("#pagination-per-page-10").removeClass("selected-pagination");
      $("#pagination-per-page-25").addClass("selected-pagination");
      $("#pagination-per-page-50").removeClass("selected-pagination");

      break;
    case "50":
      $("#pagination-per-page-5").removeClass("selected-pagination");
      $("#pagination-per-page-10").removeClass("selected-pagination");
      $("#pagination-per-page-25").removeClass("selected-pagination");
      $("#pagination-per-page-50").addClass("selected-pagination");
      break;
    default:
      break;
  }

  //$("#pagination-per-page-"+pagination).trigger('click');

  if (has_group) {
    advanced_filter_button_click();
    $("select[name='filter_group']").val(has_group);
    update_user_list();
  }

  $(".search-cancel").on("click", function () {
    $(".search-input").val("");
    $(".search-input").trigger("input");
  });

  $(".search-input").on("input", function () {
    if ($(".search-input").val() == "") {
      $(".search-cancel").hide();
    } else {
      $(".search-cancel").show();
    }
  });
  // Filter by id (registered) and username
  const icon_user = $("#icon-usr-list-user");
  const icon_registered = $("#icon-usr-list-registered");
  $("#usr-list-registered").on("click", function () {
    icon_user.css("display", "none");
    icon_registered.css("display", "block");
    icon_user.attr("class", "icon-down");
    switch (filter_by) {
      case "id DESC":
        icon_registered.attr("class", "icon-down");
        filter_by = "id ASC";
        break;
      case "id ASC":
        icon_registered.attr("class", "icon-up");
        filter_by = "id DESC";
        break;
      default:
        icon_registered.attr("class", "icon-up");
        filter_by = "id DESC";
        break;
    }
    update_user_list();
  });
  $("#usr-list-user").on("click", function () {
    icon_registered.css("display", "none");
    icon_user.css("display", "block");
    icon_registered.attr("class", "icon-up");
    switch (filter_by) {
      case "username DESC":
        icon_user.attr("class", "icon-down");
        filter_by = "username ASC";
        break;
      case "username ASC":
        icon_user.attr("class", "icon-up");
        filter_by = "username DESC";
        break;
      default:
        icon_user.attr("class", "icon-down");
        filter_by = "username ASC";
        break;
    }
    update_user_list();
  });

  /* tabsheet pop in user */
  editTabsBind();

  $(".edit-user-slides").on("scroll", function () {
    const slides = $(".edit-user-slides").children();
    const rectView = this.getBoundingClientRect();
    $(".edit-user-tabsheet").removeClass("selected");
    // for debug
    // console.log('RECT REFERENCES left / right', rectView.left.toFixed(0), ' / ', rectView.right.toFixed(0));

    slides.each(function () {
      const rect = this.getBoundingClientRect();
      // for debug
      // console.log('rect',$(this).attr('id'), ' left / right : ', rect.left, ' / ', rect.right);
      if (
        +rect.left.toFixed(0) >= +rectView.left.toFixed(0) - 1 &&
        +rect.right.toFixed(0) <= +rectView.right.toFixed(0) + 1
      ) {
        const tabId = $(this).attr("id");
        $("#name_" + tabId).addClass("selected");
      }
    });
  });

  /* tabsheet pop in guest */
  $(".guest-edit-user-tabsheet")
    .off("click")
    .on("click", function () {
      const tabName = $(this).attr("id")!.split("_");
      const tabId = tabName[1] + "_" + tabName[2] + "_" + tabName[3];
      $("#" + tabId)[0]!.scrollIntoView({
        behavior: "smooth",
      });
    });
  $(".guest-edit-user-slides").on("scroll", function () {
    const slides = $(".guest-edit-user-slides").children();
    const rectView = this.getBoundingClientRect();
    $(".guest-edit-user-tabsheet").removeClass("selected");
    slides.each(function () {
      const rect = this.getBoundingClientRect();
      if (
        +rect.left.toFixed(0) >= +rectView.left.toFixed(0) - 1 &&
        +rect.right.toFixed(0) <= +rectView.right.toFixed(0) + 1
      ) {
        const tabId = $(this).attr("id");
        $("#name_" + tabId).addClass("selected");
      }
    });
  });
});

function set_view_selector(view_type: string) {
  void ajax({
    url: "api/v1/session/preferences/user-manager-view",
    type: "PUT",
    contentType: "application/json",
    dataType: "JSON",
    data: JSON.stringify({
      value: view_type,
    }),
  });
}

function setDisplayTile() {
  $(".user-container-wrapper")
    .removeClass("compactView")
    .removeClass("lineView")
    .addClass("tileView");
  $(".user-header-col").addClass("hide");
}

function setDisplayLine() {
  $(".user-container-wrapper")
    .removeClass("tileView")
    .removeClass("compactView")
    .addClass("lineView");
  $(".user-header-col").removeClass("hide");
}

function setDisplayCompact() {
  $(".user-container-wrapper")
    .removeClass("tileView")
    .removeClass("lineView")
    .addClass("compactView");
  $(".user-header-col").addClass("hide");

  if (per_page < 10) {
    per_page = 10;
    update_pagination_menu();
    update_user_list();

    $("#pagination-per-page-5").removeClass("selected-pagination");
    $("#pagination-per-page-10").addClass("selected-pagination");
    $("#pagination-per-page-25").removeClass("selected-pagination");
    $("#pagination-per-page-50").removeClass("selected-pagination");
  }
}

/*----------------
Checkboxes
----------------*/

function checkbox_change(this: HTMLElement) {
  if ($(this).attr("data-selected") == "1") {
    $(this).find("i").hide();
  } else {
    $(this).find("i").show();
  }
}

function checkbox_click(this: HTMLElement) {
  if ($(this).attr("data-selected") == "1") {
    $(this).attr("data-selected", "0");
    $(this).find("i").hide();
  } else {
    $(this).attr("data-selected", "1");
    $(this).find("i").show();
  }
}

$(".user-list-checkbox").unbind("change").change(checkbox_change);
$(".user-list-checkbox").unbind("click").click(checkbox_click);

/* ---------------
User edit sliders 
----------------*/
const nb_image_page_values = [
  1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22,
  23, 24, 25, 26, 27, 28, 29, 30, 35, 40, 45, 50, 60, 70, 80, 90, 100, 200, 300,
  500, 999,
];
const recent_period_values = [
  0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 25,
  30, 40, 50, 60, 80, 99,
];
const recent_period_init = 0;
//const recent_period_init = getSliderKeyFromValue($('.period-select-bar input[name=recent_period]').val(), recent_period_values);
const nb_image_page_init = 0;
//const nb_image_page_init = getSliderKeyFromValue($('.photos-select-bar input[name=nb_image_page]').val(), nb_image_page_values);

/**
 * find the key from a value in the startStopValues array
 */
// Was `for (const key in values)`, returning the for-in string key
// directly -- real strict typing (and eslint's no-for-in-array) reject
// that outright. Rewritten to iterate real numeric indices, so this
// always returns a genuine `number` (previously a numeric-looking
// *string* on every non-fallback path) to jQuery UI's own slider
// `value` option, which expects one.
function getSliderKeyFromValue(value: number, values: number[]): number {
  for (let key = 0; key < values.length; key++) {
    if (values[key]! >= value) {
      return key;
    }
  }
  return 0;
}

function getNbImagePageInfoFromIdx(idx: number) {
  return sprintf(nb_photos, nb_image_page_values[idx]!);
}

function getRecentPeriodInfoFromIdx(idx: number) {
  return sprintf(nb_days, recent_period_values[idx]!);
  //return recent_period_values[idx].toString();
}

/* Photos bar slider */
jQuery("#UserList .photos-select-bar .slider-bar-container").slider({
  range: "min",
  min: 0,
  max: nb_image_page_values.length - 1,
  value: nb_image_page_init,
  change: function (event: JQueryEventObject, ui: JQueryUI.SliderUIParams) {
    $("#UserList .photos-select-bar .nb-img-page-infos").html(
      getNbImagePageInfoFromIdx(ui.value!),
    );
  },
  slide: function (event: JQueryEventObject, ui: JQueryUI.SliderUIParams) {
    $("#UserList .photos-select-bar .nb-img-page-infos").html(
      getNbImagePageInfoFromIdx(ui.value!),
    );
  },
  stop: function (event: JQueryEventObject, ui: JQueryUI.SliderUIParams) {
    $("#UserList .photos-select-bar input[name=nb_image_page]")
      .val(nb_image_page_values[ui.value!]!)
      .trigger("change");
  },
});

jQuery("#GuestUserList .photos-select-bar .slider-bar-container").slider({
  range: "min",
  min: 0,
  max: nb_image_page_values.length - 1,
  value: nb_image_page_init,
  change: function (event: JQueryEventObject, ui: JQueryUI.SliderUIParams) {
    $("#GuestUserList .photos-select-bar .nb-img-page-infos").html(
      getNbImagePageInfoFromIdx(ui.value!),
    );
  },
  slide: function (event: JQueryEventObject, ui: JQueryUI.SliderUIParams) {
    $("#GuestUserList .photos-select-bar .nb-img-page-infos").html(
      getNbImagePageInfoFromIdx(ui.value!),
    );
  },
  stop: function (event: JQueryEventObject, ui: JQueryUI.SliderUIParams) {
    $("#GuestUserList .photos-select-bar input[name=nb_image_page]")
      .val(nb_image_page_values[ui.value!]!)
      .trigger("change");
  },
});

$("#permitActionUserList .photos-select-bar .nb-img-page-infos").html(
  getNbImagePageInfoFromIdx(0),
);
jQuery("#permitActionUserList .photos-select-bar .slider-bar-container").slider(
  {
    range: "min",
    min: 0,
    max: nb_image_page_values.length - 1,
    value: nb_image_page_init,
    change: function (event: JQueryEventObject, ui: JQueryUI.SliderUIParams) {
      $("#permitActionUserList .photos-select-bar .nb-img-page-infos").html(
        getNbImagePageInfoFromIdx(ui.value!),
      );
    },
    slide: function (event: JQueryEventObject, ui: JQueryUI.SliderUIParams) {
      $("#permitActionUserList .photos-select-bar .nb-img-page-infos").html(
        getNbImagePageInfoFromIdx(ui.value!),
      );
    },
    stop: function (event: JQueryEventObject, ui: JQueryUI.SliderUIParams) {
      $("#permitActionUserList .photos-select-bar input[name=nb_image_page]")
        .val(nb_image_page_values[ui.value!]!)
        .trigger("change");
    },
  },
);

/* recent_period slider */
$("#UserList .period-select-bar .slider-bar-container").slider({
  range: "min",
  min: 0,
  max: recent_period_values.length - 1,
  value: recent_period_init,
  change: function (event: JQueryEventObject, ui: JQueryUI.SliderUIParams) {
    $("#UserList .period-select-bar .recent_period_infos").html(
      getRecentPeriodInfoFromIdx(ui.value!),
    );
  },
  slide: function (event: JQueryEventObject, ui: JQueryUI.SliderUIParams) {
    $("#UserList .period-select-bar .recent_period_infos").html(
      getRecentPeriodInfoFromIdx(ui.value!),
    );
  },
  stop: function (event: JQueryEventObject, ui: JQueryUI.SliderUIParams) {
    $("#UserList .period-select-bar input[name=recent_period]")
      .val(recent_period_values[ui.value!]!)
      .trigger("change");
  },
});

$("#GuestUserList .period-select-bar .slider-bar-container").slider({
  range: "min",
  min: 0,
  max: recent_period_values.length - 1,
  value: recent_period_init,
  change: function (event: JQueryEventObject, ui: JQueryUI.SliderUIParams) {
    $("#GuestUserList .period-select-bar .recent_period_infos").html(
      getRecentPeriodInfoFromIdx(ui.value!),
    );
  },
  slide: function (event: JQueryEventObject, ui: JQueryUI.SliderUIParams) {
    $("#GuestUserList .period-select-bar .recent_period_infos").html(
      getRecentPeriodInfoFromIdx(ui.value!),
    );
  },
  stop: function (event: JQueryEventObject, ui: JQueryUI.SliderUIParams) {
    $("#GuestUserList .period-select-bar input[name=recent_period]")
      .val(recent_period_values[ui.value!]!)
      .trigger("change");
  },
});

$("#permitActionUserList .period-select-bar .slider-bar-container").slider({
  range: "min",
  min: 0,
  max: recent_period_values.length - 1,
  value: recent_period_init,
  change: function (event: JQueryEventObject, ui: JQueryUI.SliderUIParams) {
    $("#permitActionUserList .period-select-bar .recent_period_infos").html(
      getRecentPeriodInfoFromIdx(ui.value!),
    );
  },
  slide: function (event: JQueryEventObject, ui: JQueryUI.SliderUIParams) {
    $("#permitActionUserList .period-select-bar .recent_period_infos").html(
      getRecentPeriodInfoFromIdx(ui.value!),
    );
  },
  stop: function (event: JQueryEventObject, ui: JQueryUI.SliderUIParams) {
    $("#permitActionUserList .period-select-bar input[name=recent_period]")
      .val(recent_period_values[ui.value!]!)
      .trigger("change");
  },
});
$("#permitActionUserList .photos-select-bar .slider-bar-container").slider(
  "option",
  "value",
  0,
);
const period_info = getRecentPeriodInfoFromIdx(0);
$("#permitActionUserList .period-select-bar .recent_period_infos").html(
  period_info,
);

/* -----------
Pagination
------------*/

let per_page = 5;
let actual_page = 1;
let max_page = 1;
let nb_filtered_users = 0;
const page_ellipsis = "<span>...</span>";
const page_item = '<a data-page="%d">%d</a>';

function move_to_page(page: number) {
  if (page < 1 || page > max_page) return;
  actual_page = page;
  update_pagination_menu();
  update_user_list();
}

$(".pagination-arrow.rigth").on("click", () => {
  move_to_page(actual_page + 1);
});

$(".pagination-arrow.left").on("click", () => {
  move_to_page(actual_page - 1);
});

$(".pagination-per-page a").on("click", function () {
  void ajax({
    url: "api/v1/session/preferences/user-manager-pagination",
    type: "PUT",
    contentType: "application/json",
    data: JSON.stringify({
      value: String(parseInt($(this).html())),
      isJson: false,
    }),
  });

  per_page = parseInt($(this).html());
  actual_page = 1;
  update_pagination_menu();
  update_user_list();

  $(this).parent().children("a").removeClass("selected-pagination");

  $(this).addClass("selected-pagination");
});

function append_pagination_item(page: number | null = null) {
  if (page != null) {
    const new_tag = $(page_item.replace(/%d/g, String(page)));
    $(".pagination-item-container").append(new_tag);
    if (actual_page == page) {
      new_tag.addClass("actual");
    }
    new_tag.on("click", () => {
      move_to_page(new_tag.data("page") as number);
    });
  } else {
    $(".pagination-item-container").append($(page_ellipsis));
  }
}

function update_pagination_items() {
  $(".pagination-item-container a").remove();
  $(".pagination-item-container span").remove();

  append_pagination_item(1);

  if (actual_page > 2) {
    append_pagination_item();
  }
  if (actual_page != 1 && actual_page != max_page) {
    append_pagination_item(actual_page);
  }
  if (actual_page < max_page - 1) {
    append_pagination_item();
  }
  append_pagination_item(max_page);
}

function update_pagination_menu() {
  max_page = Math.ceil(nb_filtered_users / per_page);
  updateArrows();
  update_pagination_items();
  if (max_page <= 1) {
    $(".pagination-container").hide();
  } else {
    $(".pagination-container").show();
  }
}

function updateArrows() {
  if (actual_page == 1) {
    $(".pagination-arrow.left").addClass("unavailable");
  } else {
    $(".pagination-arrow.left").removeClass("unavailable");
  }
  if (actual_page == max_page) {
    $(".pagination-arrow.rigth").addClass("unavailable");
  } else {
    $(".pagination-arrow.rigth").removeClass("unavailable");
  }
}

/*------------------
Advanced filter
------------------*/

function advanced_filter_button_click() {
  if (!$(".advanced-filter").hasClass("advanced-filter-open")) {
    advanced_filter_show();
  } else {
    advanced_filter_hide();
  }
  // update_user_list();
}

function advanced_filter_show() {
  $(".advanced-filter-btn, .advanced-filter").addClass("advanced-filter-open");
}

function advanced_filter_hide() {
  $(".advanced-filter-btn, .advanced-filter").removeClass(
    "advanced-filter-open",
  );
}

let months: string[] = [];

function getDateStr(date: string) {
  const date_arr = date.split("-");
  const curr_month = months[parseInt(date_arr[1]!) - 1];
  return curr_month + " " + date_arr[0];
}

function setupRegisterDates(register_dates: string[]) {
  $(".advanced-filter .dates-select-bar .slider-bar-container").slider({
    range: true,
    min: 0,
    max: register_dates.length - 1,
    values: [0, register_dates.length - 1],
    change: function (event: JQueryEventObject, ui: JQueryUI.SliderUIParams) {
      $(".advanced-filter .dates-infos").html(
        sprintf(
          dates_infos,
          getDateStr(register_dates[ui.values![0]!]!),
          getDateStr(register_dates[ui.values![1]!]!),
        ),
      );
    },
    slide: function (event: JQueryEventObject, ui: JQueryUI.SliderUIParams) {
      $(".advanced-filter .dates-infos").html(
        sprintf(
          dates_infos,
          getDateStr(register_dates[ui.values![0]!]!),
          getDateStr(register_dates[ui.values![1]!]!),
        ),
      );
    },
    stop: function (event: JQueryEventObject, ui: JQueryUI.SliderUIParams) {
      $(".advanced-filter .dates-infos").html(
        sprintf(
          dates_infos,
          getDateStr(register_dates[ui.values![0]!]!),
          getDateStr(register_dates[ui.values![1]!]!),
        ),
      );
      update_user_list();
    },
  });

  $(".advanced-filter .dates-infos").html(
    sprintf(
      dates_infos,
      getDateStr(register_dates[0]!),
      getDateStr(register_dates[register_dates.length - 1]!),
    ),
  );
}
/*------------------
Add User
------------------*/

function gen_password() {
  const characterSet =
    "ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789";

  let i: number;
  let password: string;
  const length = getRandomInt(8, 15);

  password = "";
  for (i = 0; i < length; i++) {
    password += characterSet.charAt(
      Math.floor(Math.random() * characterSet.length),
    );
  }

  return password;
}

function add_user_close() {
  $("#AddUser").fadeOut(() => {
    $("#AddUser .AddUserErrors").css("visibility", "hidden");
  });
}

function add_user_open() {
  $("#AddUserSuccessContainer").hide();
  $("#AddUserFieldContainer").show();
  $("#AddUser :input").val("");
  $("#add_user_password").hide();
  fill_new_user();
  $("#AddUser").fadeIn();
  $(".AddUserLabelUsername input").first().focus();
  $("#AddUser .user-property-status .user-property-select")
    .off("change")
    .on("change", function () {
      const status = $(this).val();
      $("#add_user_pass ,#add_user_confpass").val("");
      if ("generic" === status) {
        $("#add_user_password").show();
        return;
      }
      $("#add_user_password").hide();
    });
}

/*------------------
Selection mode
------------------*/

function create_user_selected_item(user: SelectionEntry) {
  const new_elem = $("#template .user-selected-item").clone();
  new_elem.attr("data-id", user.id.toString());
  // Non-null: only ever called after the caller's own `typeof ...
  // !== "undefined"` guard confirms `username` is really set.
  new_elem.find("p").html(user.username!);
  new_elem.find("a").click(() => {
    selection.splice(
      selection.findIndex((i) => i.id == user.id),
      1,
    );
    update_selection_content();
  });
  return new_elem;
}

function generate_user_selected_items() {
  let items_created = 0;
  const others = selection.length - 5;
  $(".user-selected-list .user-selected-item").remove();
  for (let i = 0; i < selection.length && items_created < 5; i++) {
    if (typeof selection[i]!.username !== "undefined") {
      $(".user-selected-list").append(create_user_selected_item(selection[i]!));
      items_created += 1;
    }
  }
  if (others >= 1) {
    $(".selection-other-users").html(
      str_and_others_tags.replace("%s", String(others)),
    );
    $(".selection-other-users").show();
  } else {
    $(".selection-other-users").hide();
  }
  return;
}

function fill_user_selected_list() {
  let elems_with_username = 0;
  for (let i = 0; i < selection.length && elems_with_username < 5; i++) {
    if (typeof selection[i]!.username !== "undefined") {
      elems_with_username += 1;
    }
  }
  if (elems_with_username < 5 && elems_with_username != selection.length) {
    get_first_selection_usernames(generate_user_selected_items);
  } else {
    generate_user_selected_items();
  }
}

function update_selection_content() {
  const number = selection.length;
  fill_user_selected_list();
  if (number == 0) {
    $("#forbidAction").show();
    $(".selection-mode-ul").hide();
    $("#permitActionUserList").hide();
  } else {
    $("#forbidAction").hide();
    $("#permitActionUserList").show();
    $(".selection-mode-ul").show();
  }
  set_selected_to_selection();
  $("#applyActionBlock .infos").hide();
}

function set_selected_to_selection() {
  if (!$("#toggleSelectionMode").is(":checked")) {
    return;
  }
  $(".user-container-wrapper .user-container").each(function (index) {
    const selection_ids = selection.map((x) => x.id);
    if (selection_ids.includes(current_users[index]!.id)) {
      $(this).addClass("container-selected");
      $(this).find(".user-list-checkbox").attr("data-selected", "1");
      $(this).find(".user-list-checkbox i").show();
    } else {
      $(this).removeClass("container-selected");
      $(this).find(".user-list-checkbox").attr("data-selected", "0");
      $(this).find(".user-list-checkbox i").hide();
    }
  });
}

function selectionMode(isSelection: boolean) {
  $("#permitActionUserList select[name=selectAction]").val("-1");
  $("#permitActionUserList select[name=selectAction]").trigger("change");
  if (isSelection) {
    //resets the selection
    //selection = [];
    set_selected_to_selection();
    $(".in-selection-mode").show();
    $(".not-in-selection-mode").hide();

    if (view_selector === "tile") {
      $(".user-container-email").show();
    }

    if (view_selector === "compact") {
      $(".user-container-email").css({
        display: "none",
      });
    }

    $(".user-container").addClass("selectable");
  } else {
    $(".container-selected").removeClass("container-selected");
    $(".in-selection-mode").hide();
    $(".not-in-selection-mode").show();

    if (view_selector === "tile" || view_selector === "line") {
      $(".user-container-email").css({
        display: "flex",
      });
    }

    $(".user-container").removeClass("selectable");
  }
}

/*------------------
General functions
------------------*/

function hide_temporary_messages() {
  $(".update-user-success").hide();
  $("#AddUserSuccess").hide();
  $(".error-msg").hide();
}

function get_group_name_from_id(id: number) {
  for (let i = 0; i < groups_arr.length; i++) {
    if (groups_arr[i]![0] == id) {
      return groups_arr[i]![1];
    }
  }
  return "group_id error";
}

function get_container_index_from_uid(uid: number) {
  for (let i = 0; i < current_users.length; i++) {
    if (current_users[i]!.id == uid) {
      return i;
    }
  }
  return -1;
}

function hide_error_edit_user() {
  $("#UserList .EditUserErrors").animate({ opacity: 0 }, 100);
}

function show_error_edit_user() {
  $("#UserList .EditUserErrors").animate({ opacity: 1 }, 300);
}

function reset_input_password() {
  $("#edit_user_password, #edit_user_conf_password").val("");
}

function editTabsBind() {
  $(".edit-user-tabsheet")
    .off("click")
    .on("click", function () {
      const tabName = $(this).attr("id")!.split("_");
      const tabId = tabName[1] + "_" + tabName[2];

      $("#" + tabId)[0]!.scrollIntoView({
        behavior: "smooth",
      });
    });
}

function check_tabs(title_tab_name_id: string) {
  if (plugins_load.length > 2) {
    $(".edit-user-tab-title").css({
      gap: "0px",
      justifyContent: "space-between",
    });
    const countMoresPlugins = plugins_load.length - 2;
    if (!document.getElementById("mores_plugins_expand")) {
      $(".edit-user-tab-title").append(
        '<span class="close mores-plugins" id="mores_plugins_expand"></span>' +
          '<div class="dropdown-mores-plugins" id="dropdown_mores_plugins"></div>',
      );
    }
    $("#mores_plugins_expand").html("+" + countMoresPlugins);

    const dropdown = $("#dropdown_mores_plugins");
    const tabsheetTitle = $("#" + title_tab_name_id);
    $(".edit-user-tab-title").css("margin-top", "3px");
    $(".edit-user-tab-title p:lt(4)").css("padding-top", "5px");
    tabsheetTitle.css({ "max-width": "100%" });
    tabsheetTitle.appendTo(dropdown);

    if (1 === countMoresPlugins) {
      tabsheetTitle.css("border-radius", "10px");
    } else {
      dropdown.children().css("border-radius", "0");
      dropdown.children().first().css("border-radius", "10px 10px 0 0");
      dropdown.children().last().css("border-radius", "0 0 10px 10px");
    }

    $("#mores_plugins_expand")
      .off("click")
      .on("click", function () {
        const icon = $(this);
        if (icon.hasClass("open")) {
          icon.removeClass("open").addClass("close");
          dropdown.fadeOut();
        } else {
          icon.removeClass("close").addClass("open");
          dropdown.fadeIn();
          $(window)
            .off("click.hideUsersModal")
            .on("click.hideUsersModal", function (e) {
              if (
                !$(e.target).closest("#dropdown_mores_plugins").length &&
                !$(e.target).is("#mores_plugins_expand")
              ) {
                $("#dropdown_mores_plugins").fadeOut();
                icon.removeClass("open").addClass("close");
              }
            });
        }
      });
  }
}

/**
 * Adds a new tab to the user's modal
 * @param {string} tab_name - The tab name (will also be used for the new tab id)
 * @param {string} content_id - The id of the HTML element
 * @param {string | null} users_table  - The name of the column created in the users table (must be null if the set_data_function and get_data_function functions are used)
 * @param {() => {} | null} set_data_function - API call set function with ajax (must be null if users_table is used)
 * @param {() => {} | null} get_data_function - API call get function with ajax (must be null if users_table is used)
 * @returns {void} Displays the new tab in the user's modal
 */
function plugin_add_tab_in_user_modal(
  tab_name: string,
  content_id: string,
  users_table: string | null = null,
  set_data_function: ((...args: unknown[]) => unknown) | null = null,
  get_data_function: ((...args: unknown[]) => unknown) | null = null,
) {
  // verification
  if (typeof tab_name !== "string") {
    throw new TypeError("tab_name must be a string.");
  }
  const name = tab_name.replace(/ /g, "").toLowerCase();
  if (document.getElementById("name_tab_" + name)) {
    throw new TypeError("An element with this tab_name already exists.");
  }

  if (typeof content_id !== "string") {
    throw new TypeError("content_id must be a string.");
  } else if (!document.getElementById(content_id)) {
    throw new TypeError('HTML element "' + content_id + '" not found.');
  } else if (document.querySelector(".edit-user-slides #" + content_id)) {
    throw new TypeError("An element with this content_id already exists.");
  }

  if (users_table && typeof users_table !== "string") {
    throw new TypeError("users_table must be a string.");
  }
  if (users_table && (set_data_function || get_data_function)) {
    throw new TypeError(
      "users_table must be null if set_data_function or get_data_function is used.",
    );
  }

  if (set_data_function && typeof set_data_function !== "function") {
    throw new TypeError("set_data_function must be a function.");
  } else if (set_data_function && typeof set_data_function === "function") {
    plugins_set_functions[name + "_set_function"] = set_data_function;
  }

  if (get_data_function && typeof get_data_function !== "function") {
    throw new TypeError("get_data_function must be a function.");
  } else if (get_data_function && typeof get_data_function === "function") {
    plugins_get_functions[name + "_get_function"] = get_data_function;
  }

  if (users_table) {
    plugins_users_infos_table.push({ content_id, users_table });
  }

  // DOM modification
  const content = $("#" + content_id);

  $(".edit-user-tab-title").append(
    '<p class="edit-user-tabsheet" id="name_tab_' +
      name +
      '" title="' +
      tab_name +
      '">' +
      tab_name +
      "</p>",
  );

  $(".edit-user-slides").append(
    '<div class="edit-user-tabsheet-plugins ' +
      name +
      '-container" id="tab_' +
      name +
      '"></div>',
  );

  content.appendTo("#tab_" + name);
  content.show();

  editTabsBind();

  $(".edit-user-tabsheet").addClass("tab-with-plugin");

  plugins_load.push("name_tab_" + name);
  check_tabs("name_tab_" + name);

  if (plugins_load.length <= 2) {
    $("#name_tab_" + name).tipTip({
      delay: 0,
      fadeIn: 200,
      fadeOut: 200,
      edgeOffset: 3,
    });
  }
}

function reset_password_modals() {
  $(".user-property-password-change").hide();
  $(".user-property-password-change-inputs").hide();
  $("#edit_password_success_change").hide();
  $("#edit_password_result_mail").hide();
  $("#edit_password_result_mail_copy").hide();
  $(".user-property-password-choice").show();
}

function reset_username_modals() {
  $(".edit-username-success").hide();
  $(".user-property-username-change-input").show();
}

function reset_main_user_modals() {
  $("#main_user_rewrite").val("");
  $("#main_user_rewrite_icon").removeClass(
    "icon-ok icon-green icon-cancel icon-red",
  );
  $(".main-user-rewrite").hide();
  $(".main-user-validate").hide();
  $(".main-user-success").hide();
  $(".main-user-proceed").show();
}

function hide_modals() {
  $(".user-property-username-change").hide();
  $(".user-property-password-change").hide();
  $(".user-property-main-user-change").hide();
  reset_password_modals();
  reset_username_modals();
  reset_main_user_modals();
}

function display_long_string(username: string) {
  const formatedUsername =
    username.length > 20 ? username.slice(0, 17) + "..." : username;
  return formatedUsername;
}

function generate_random_string() {
  const string =
    "abcdefghijkmlnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
  let result = "";
  const c = 5;
  for (let i = 0; i < c; i++) {
    result += string.charAt(Math.floor(Math.random() * string.length));
  }
  return result;
}

/* ---------------
Who is the king functions (main user)
----------------*/
function open_main_user_modal(user_to_edit: UserRow) {
  const modal = $(".user-property-main-user-change");
  reset_main_user_modals();
  $(".main-user-proceed-desc").html(
    sprintf(
      mainUserContinue,
      `<b>${display_long_string(user_to_edit.username)}</b>`,
      `<b>${display_long_string(owner_username)}</b>`,
    ),
  );
  modal.fadeIn();

  // set event
  $(".main-user-btn-proceed")
    .off("click")
    .on("click", function () {
      const gen_string = generate_random_string();
      $(".main-user-rewrite-desc").html(
        sprintf(mainUserRewrite, `<b>${gen_string}</b>`) + " :",
      );
      $(".main-user-proceed").hide();
      $(".main-user-rewrite").fadeIn();
      event_check_string_main_user(
        user_to_edit.username,
        user_to_edit.id,
        gen_string,
      );
    });

  $(".user-property-main-user-cancel, #main_user_success_close")
    .off("click")
    .on("click", function () {
      modal.fadeOut();
    });
}

function set_main_user_success() {
  const indexKey = current_users.findIndex((u) => u.id === owner_id);
  const new_main = $('.user-container[key="' + indexKey + '"]').find(
    ".user-container-username",
  );
  let king = $("#the_king");
  if (!king.length) {
    king = $(king_template);
    king.attr("title", mainUserStr);
    king.tipTip();
  }
  king.appendTo(new_main);
  $(".delete-user-button").hide();
  $(".main-user-validate").hide();
  $(".main-user-success").fadeIn();
}

function event_check_string_main_user(
  new_main_username: string,
  new_main_id: number,
  stringToCheck: string,
) {
  const icon = $("#main_user_rewrite_icon");
  $("#main_user_rewrite")
    .off("keyup")
    .on("keyup", function () {
      icon.removeClass("icon-ok icon-green").addClass("icon-cancel icon-red");
      if (stringToCheck === $(this).val()) {
        icon.removeClass("icon-cancel icon-red").addClass("icon-ok icon-green");
        $(".main-user-validate-desc").html(
          sprintf(
            mainUserValidate,
            `<b>${display_long_string(owner_username)}</b>`,
            `<b>${display_long_string(new_main_username)}</b>`,
          ),
        );
        $(".main-user-success-desc").html(
          sprintf(mainUserSuccess, display_long_string(new_main_username)),
        );
        $(".main-user-rewrite").hide();
        $(".main-user-validate").fadeIn();
        event_validate_main_user(new_main_username, new_main_id);
      }
    });
}

function event_validate_main_user(new_main_username: string, user_id: number) {
  $(".main-user-btn-validate")
    .off("click")
    .on("click", function () {
      set_main_user(user_id, new_main_username);
    });
}

/*-----------------------
Generate User Containers
-----------------------*/
function user_container_click(this: HTMLElement) {
  if (!isSelectionMode()) {
    return;
  }
  const curr_container = $(this);
  const in_container = curr_container.length != 0;
  const container_checkbox = $(this).find(".user-list-checkbox");
  // Non-null: same "always a real, in-bounds index when in_container"
  // invariant as checkbox_container_click()'s own copy of this comment.
  const curr_user: { id: number; username: string } = in_container
    ? current_users[parseInt(curr_container.attr("key")!)]!
    : { id: -1, username: "" };
  if (container_checkbox.attr("data-selected") == "1") {
    container_checkbox.attr("data-selected", "0");
    container_checkbox.find("i").hide();
    if (in_container) {
      curr_container.removeClass("container-selected");
      selection = selection.filter((elem) => elem.id != curr_user.id);
    }
  } else {
    container_checkbox.attr("data-selected", "1");
    container_checkbox.find("i").show();
    if (in_container) {
      curr_container.addClass("container-selected");
      selection.push({ id: curr_user.id, username: curr_user.username });
    }
  }
  if (in_container) {
    update_selection_content();
  }
}

function generate_groups(container: JQuery, groups: number[]) {
  container.find(".user-container-groups").html("");
  if (groups.length >= 1) {
    const primary_grp = $("#template .group-primary").clone();
    primary_grp.html(get_group_name_from_id(groups[0]!));
    primary_grp.addClass(color_icons[groups[0]! % 5]!);
    container.find(".user-container-groups").append(primary_grp);
  }
  if (groups.length >= 2) {
    const primary_grp = $("#template .group-primary").clone();
    primary_grp.html(get_group_name_from_id(groups[1]!));
    primary_grp.addClass(color_icons[groups[1]! % 5]!);
    container.find(".user-container-groups").append(primary_grp);
  }
  if (groups.length >= 3) {
    const bonus_grp = $("#template .group-bonus").clone();
    bonus_grp.html("...");
    bonus_grp.addClass(color_icons[groups[2]! % 5]!);
    bonus_grp.addClass("tiptip");
    let groups_in_title = "";
    for (let i = 2; i < groups.length; i++) {
      groups_in_title += get_group_name_from_id(groups[i]!) + ", ";
    }
    groups_in_title = groups_in_title.substring(0, groups_in_title.length - 2);
    bonus_grp.prop("title", groups_in_title);
    container.find(".user-container-groups").append(bonus_grp);
  }
}

function get_initials(username: string): string {
  const words = username.toUpperCase().split(" ");
  let res = words[0]![0]!;

  if (words.length > 1 && words[1]![0] !== undefined) {
    res += words[1]![0];
  }
  return res;
}

function fill_container_user_info(container: JQuery, user_index: number) {
  const user = current_users[user_index]!;
  const registration_dates = (user.registrationDate ?? "").split(" ");
  container.attr("key", user_index);
  container.find(".user-container-username span").html(user.username);
  if (user.id === owner_id && !$("#the_king").length) {
    const kingToDisplay = $(king_template);
    kingToDisplay.attr("title", mainUserStr);
    kingToDisplay.tipTip();
    container.find(".user-container-username").append(kingToDisplay);
  }
  const initial_to_fill = get_initials(user.username);
  const initialSpan = container.find(".user-container-initials span");
  initialSpan.html(initial_to_fill).addClass(color_icons[user.id % 5]!);
  if (initial_to_fill.length > 1) {
    initialSpan.addClass("small");
  } else {
    initialSpan.removeClass("small");
  }
  container
    .find(".user-container-status span")
    // Non-null: every real, listed user has a real status; `null` is
    // only a schema allowance for an edge case this admin listing
    // doesn't actually surface.
    .html(status_to_str[user.status!]!);
  container.find(".user-container-email span").html(user.email ?? "");
  generate_groups(container, user.groups);
  container
    .find(".user-container-registration-date")
    .html(registration_dates[0] ?? "");
  container
    .find(".user-container-registration-time")
    .html(registration_dates[1] ?? "");
  container
    .find(".user-container-registration-date-since")
    .html(user.registrationDateSince);
}

function generate_user_list() {
  $("#user-table-content").find(".user-container").remove();
  for (let i = 0; i < current_users.length; i++) {
    const new_container = $("#template .user-container").clone();
    fill_container_user_info(new_container, i);
    $("#user-table-content .user-container-wrapper").append(new_container);
  }
  $(".user-container .user-list-checkbox")
    .unbind("change")
    .change(checkbox_change);
  $(".user-container .user-list-checkbox").unbind("click");
  $(".user-container").click(user_container_click);
}

function copyToClipboard(toCopy: string) {
  void navigator.clipboard.writeText(toCopy);
}
/*---------------------
Fill the pop-in values
---------------------*/

function get_status_index(status: string | null) {
  for (let i = 0; i < status_arr.length; i++) {
    if (status_arr[i] === status) {
      return i;
    }
  }
  return 0;
}

function get_level_index(level: number | null) {
  // level_arr holds string literals; /api/v1/users returns a real
  // JSON number for `level`, so this needs a loose match rather than
  // the strict one status_arr's own still-string `status` comparison
  // above can keep using.
  for (let i = 0; i < level_arr.length; i++) {
    if (level_arr[i]! == String(level)) {
      return i;
    }
  }
  return 0;
}

function set_selected_groups(groups: number[]) {
  for (let i = 0; i < groupOptions.length; i++) {
    groupOptions[i]!.isSelected = groups.includes(groupOptions[i]!.value);
  }
}

function fill_user_edit_summary(
  user_to_edit: UserRow,
  pop_in: JQuery,
  isGuest: boolean,
) {
  // console.log(isGuest);
  if (isGuest) {
    pop_in
      .find(".user-property-initials span")
      .removeClass(color_icons.join(" "))
      .addClass(color_icons[user_to_edit.id % 5]!);
  } else {
    const initial_to_fill = get_initials(user_to_edit.username);
    const initialSpan = pop_in.find(".user-property-initials span");
    initialSpan
      .html(initial_to_fill)
      .removeClass(color_icons.join(" "))
      .addClass(color_icons[user_to_edit.id % 5]!);
    if (initial_to_fill.length > 1) {
      initialSpan.addClass("small");
    } else {
      initialSpan.removeClass("small");
    }
  }
  pop_in
    .find(".user-property-username span:first")
    .html(user_to_edit.username)
    .tipTip({ content: user_to_edit.username });

  if (user_to_edit.id === connected_user || user_to_edit.id === 1) {
    pop_in.find(".user-property-username .edit-username-specifier").show();
  } else {
    pop_in.find(".user-property-username .edit-username-specifier").hide();
  }
  pop_in
    .find(".user-property-username-change input")
    .val(user_to_edit.username);
  pop_in.find(".user-property-password-change input").val("");
  pop_in
    .find(".user-property-permissions a")
    .attr("href", `admin.php?page=user_perm&user_id=${user_to_edit.id}`);
  pop_in
    .find(".user-property-register")
    .html(user_to_edit.registrationDateString);
  pop_in.find(".user-property-register").tipTip({
    content: `${registered_str}<br />${user_to_edit.registrationDateSince}`,
  });
  pop_in.find(".user-property-last-visit").html(user_to_edit.lastVisitString);
  pop_in.find(".user-property-last-visit").tipTip({
    content: `${last_visit_str}<br />${user_to_edit.lastVisitSince}`,
  });
  pop_in
    .find(".user-property-history a")
    .attr("href", history_base_url + user_to_edit.id);

  // Hide the copy password button and change modal copy password
  // if you are not using https
  if (!window.isSecureContext) {
    $("#copy_password").hide();
    $(".update-password-success").css("margin", "40px 0");
    $("#result_send_mail_copy").hide();
    $("#result_send_mail_copy_btn, #AddUserCopyPassword")
      .css({
        cursor: "not-allowed",
        "background-color": "grey",
        color: "#ffffff",
      })
      .attr("title", cantCopy)
      .on("mouseenter", function () {
        $(this).css("background-color", "grey");
      })
      .tipTip();
  }
}

function fill_user_edit_properties(user_to_edit: UserRow, pop_in: JQuery) {
  const status_index = get_status_index(user_to_edit.status);
  const level_index = get_level_index(user_to_edit.level);
  const current_group_selectize =
    user_to_edit.id === guest_id ? groupGuestSelectize : groupSelectize;

  pop_in.find(".user-property-email input").val(user_to_edit.email ?? "");
  pop_in
    .find(`.user-property-status select option:eq(${status_index})`)
    .prop("selected", true);
  pop_in
    .find(`.user-property-level select option:eq(${level_index})`)
    .prop("selected", true);
  pop_in.find(".photos-select-bar input").val(user_to_edit.recentPeriod ?? "");
  set_selected_groups(user_to_edit.groups);
  current_group_selectize.clear();
  current_group_selectize.load(function (
    callback: (data: GroupOption[]) => void,
  ) {
    callback(groupOptions);
  });
  jQuery.each(
    jQuery.grep(groupOptions, function (group) {
      return group.isSelected;
    }),
    function (i, group) {
      current_group_selectize.addItem(group.value);
    },
  );
  pop_in
    .find('.user-list-checkbox[name="hd_enabled"]')
    .attr("data-selected", user_to_edit.enabledHigh ? "1" : "0");
}

function fill_user_edit_preferences(user_to_edit: UserRow, pop_in: JQuery) {
  const slider_key_photos = getSliderKeyFromValue(
    parseInt(String(user_to_edit.nbImagePage)),
    nb_image_page_values,
  );
  const slider_key_period = getSliderKeyFromValue(
    parseInt(String(user_to_edit.recentPeriod)),
    recent_period_values,
  );

  pop_in
    .find(".photos-select-bar .slider-bar-container")
    .slider("option", "value", slider_key_photos);
  pop_in.find(".user-property-theme select option").each(function (
    this: HTMLElement,
  ) {
    if ($(this).val() == user_to_edit.theme) {
      $(this).prop("selected", true);
    }
  });
  pop_in.find(".user-property-lang select option").each(function (
    this: HTMLElement,
  ) {
    if ($(this).val() == user_to_edit.language) {
      $(this).prop("selected", true);
    }
  });
  pop_in
    .find(".period-select-bar .slider-bar-container")
    .slider("option", "value", slider_key_period);
  pop_in
    .find('.user-list-checkbox[name="expand_all_albums"]')
    .attr("data-selected", user_to_edit.expand ? "1" : "0");
  pop_in
    .find('.user-list-checkbox[name="show_nb_comments"]')
    .attr("data-selected", user_to_edit.showNbComments ? "1" : "0");
  pop_in
    .find('.user-list-checkbox[name="show_nb_hits"]')
    .attr("data-selected", user_to_edit.showNbHits ? "1" : "0");
}

function fill_user_edit_update(user_to_edit: UserRow, pop_in: JQuery) {
  pop_in
    .find(".update-user-button")
    .unbind("click")
    .click(user_to_edit.id === guest_id ? update_guest_info : update_user_info);
  pop_in
    .find(".edit-username-validate")
    .unbind("click")
    .click(update_user_username);
  pop_in
    .find("#edit_modal_password")
    .off("click")
    .on("click", function () {
      $(".user-property-password-choice").hide();
      $(".user-property-password-change-inputs").fadeIn();
    });
  if (user_to_edit.email) {
    pop_in
      .find("#send_password_link")
      .removeClass("unavailable tiptip")
      .attr("title", "")
      .off("click")
      .on("click", function () {
        send_link_password(
          user_to_edit.email,
          user_to_edit.username,
          user_to_edit.id,
          true,
        );
      });
    pop_in.find("#send_password_link").off("mouseenter mouseleave focus blur");
  } else {
    pop_in
      .find("#send_password_link")
      .addClass("unavailable tiptip")
      .off("click")
      .attr("title", cannotSendMail)
      .tipTip({
        delay: 0,
        fadeIn: 200,
        fadeOut: 200,
        edgeOffset: 3,
      });
  }
  pop_in
    .find("#copy_password_link")
    .off("click")
    .on("click", function () {
      const inputValue = $("#result_send_mail_copy_input").val();
      if (inputValue === "") {
        send_link_password(
          user_to_edit.email,
          user_to_edit.username,
          user_to_edit.id,
          false,
        );
      } else {
        if (window.isSecureContext && navigator.clipboard) {
          copyToClipboard(String(inputValue));
        }
      }
      $(".user-property-password-choice").hide();
      $("#edit_password_result_mail_copy").fadeIn();
      $("#close_password_mail_send_close")
        .off("click")
        .on("click", function () {
          reset_password_modals();
        });
      $("#result_send_mail_copy_btn")
        .off("click")
        .on("click", function () {
          if (window.isSecureContext && navigator.clipboard) {
            const success = $("#result_send_mail_copy");
            success.fadeOut(100);
            copyToClipboard(String($("#result_send_mail_copy_input").val()));
            success.fadeIn(100);
          }
        });
      $("#result_send_mail_copy_input").trigger("focus");
    });
  pop_in
    .find(".edit-password-validate")
    .unbind("click")
    .click(function () {
      const errDiv = $("#UserList .EditUserErrors");
      const inputPassword = $("#edit_user_password").val();
      const inputConfirmPassword = $("#edit_user_conf_password").val();

      if (inputPassword === "" || inputConfirmPassword === "") {
        errDiv.html(missingField);
        show_error_edit_user();
      } else if (inputPassword !== inputConfirmPassword) {
        errDiv.html(noMatchPassword);
        show_error_edit_user();
      } else {
        update_user_password();
      }
    });
  pop_in
    .find(".delete-user-button")
    .unbind("click")
    .click(function () {
      $.confirm({
        title: title_msg.replace("%s", user_to_edit.username),
        content: "",
        buttons: {
          confirm: {
            text: confirm_msg,
            btnClass: "btn-red",
            action: function () {
              delete_user(user_to_edit.id);
            },
          },
          cancel: {
            text: cancel_msg,
          },
        },
        ...jConfirm_confirm_options,
      });
    });
}

function fill_user_edit_permissions(user_to_edit: UserRow, pop_in: JQuery) {
  if (user_to_edit.id != connected_user) {
    // I'm not the connected user
    if (!is_owner(connected_user)) {
      // I'm not the owner, you need to test my permissions
      if (is_owner(user_to_edit.id)) {
        // I want to edit the owner but I'm not the owner (No matter my status)
        pop_in.find(".delete-user-button").hide();
        pop_in.find(".user-property-password.edit-password").hide();
        pop_in
          .find(".user-property-email .user-property-input")
          .attr("disabled", "disabled");
        pop_in
          .find(".user-property-status .user-property-select")
          .addClass("notClickable");
        pop_in.find(".user-property-username .edit-username").hide();
      } else {
        pop_in.find(".delete-user-button").show();
        pop_in.find(".user-property-password.edit-password").show();
        pop_in
          .find(".user-property-email .user-property-input")
          .removeAttr("disabled");
        pop_in
          .find(".user-property-status .user-property-select")
          .removeClass("notClickable");
        pop_in.find(".user-property-username .edit-username").show();
      }

      if (
        user_to_edit.status == connected_user_status &&
        connected_user_status == "webmaster" &&
        !is_owner(user_to_edit.id)
      ) {
        // I have the same status than the user I want to edit and I'm a webmaster, I can do whatever I want
        pop_in.find(".delete-user-button").show();
        pop_in.find(".user-property-password.edit-password").show();
        pop_in
          .find(".user-property-email .user-property-input")
          .removeAttr("disabled");
        pop_in
          .find(".user-property-status .user-property-select")
          .removeClass("notClickable");
        pop_in.find(".user-property-username .edit-username").show();
      } else if (
        user_to_edit.status == connected_user_status &&
        connected_user_status == "admin"
      ) {
        // I have the same status than the user I want to edit and I'm an admin, I can do whatever I want but edit the status
        pop_in.find(".delete-user-button").hide();
        pop_in.find(".user-property-password.edit-password").show();
        pop_in
          .find(".user-property-email .user-property-input")
          .removeAttr("disabled");
        pop_in
          .find(".user-property-username .edit-username")
          .removeClass("notClickable");
        pop_in
          .find(".user-property-status .user-property-select")
          .addClass("notClickable");
      } else if (
        user_to_edit.status == "webmaster" &&
        connected_user_status == "admin"
      ) {
        // I'm admin and I want to edit webmaster
        pop_in.find(".delete-user-button").hide();
        pop_in.find(".user-property-password.edit-password").hide();
        pop_in
          .find(".user-property-email .user-property-input")
          .attr("disabled", "disabled");
        pop_in
          .find(".user-property-status .user-property-select")
          .addClass("notClickable");
        pop_in.find(".user-property-username .edit-username").hide();
      } else if (
        user_to_edit.status == "admin" &&
        connected_user_status == "webmaster"
      ) {
        // I'm webmaster and I want to edit admin
        pop_in.find(".delete-user-button").show();
        pop_in.find(".user-property-password.edit-password").show();
        pop_in
          .find(".user-property-email .user-property-input")
          .removeAttr("disabled");
        pop_in
          .find(".user-property-status .user-property-select")
          .removeClass("notClickable");
        pop_in.find(".user-property-username .edit-username").show();
      }
    } else {
      // I'm the owner, I can do whatever I want. No need to test, I am GOD here
      pop_in.find(".delete-user-button").show();
      pop_in.find(".user-property-password.edit-password").show();
      pop_in
        .find(".user-property-email .user-property-input")
        .removeAttr("disabled");
      pop_in
        .find(".user-property-status .user-property-select")
        .removeClass("notClickable");
      pop_in.find(".user-property-username .edit-username").show();
    }
  } else {
    // I'm the connected user, I can do whatever I want on my profile but kill myself (Suicide is not allowed) and edit my status
    pop_in.find(".delete-user-button").hide();
    pop_in.find(".user-property-password.edit-password").show();
    pop_in
      .find(".user-property-email .user-property-input")
      .removeAttr("disabled");
    pop_in
      .find(".user-property-status .user-property-select")
      .addClass("notClickable");
    pop_in.find(".user-property-username .edit-username").show();
  }

  $(".notClickableBefore").removeClass("notClickableBefore");
  $(".notClickable").parent().addClass("notClickableBefore");
}

function is_owner(user_id: number) {
  return user_id === owner_id;
}

function fill_user_edit(user_to_edit: UserRow) {
  const pop_in = $("#UserList");
  fill_user_edit_summary(user_to_edit, pop_in, false);
  fill_user_edit_properties(user_to_edit, pop_in);
  fill_user_edit_preferences(user_to_edit, pop_in);
  fill_user_edit_update(user_to_edit, pop_in);
  fill_user_edit_permissions(user_to_edit, pop_in);
  fill_who_is_the_king(user_to_edit, pop_in);

  // show/hide password button depending on permissions

  // plugins get function
  if (Object.keys(plugins_get_functions).length > 0) {
    Object.entries(plugins_get_functions).forEach((f) => {
      f[1]();
    });
  }
  const keyUserToEdit = Object.keys(user_to_edit);
  const userToEditRecord = user_to_edit as unknown as Record<string, unknown>;
  plugins_users_infos_table.forEach((i) => {
    $("#" + i.content_id).val("");
    if (keyUserToEdit.includes(i.users_table)) {
      $("#" + i.content_id).val(String(userToEditRecord[i.users_table]));
    } else {
      console.error(i.users_table, " doesn't exist in USER_INFOS_TABLE");
    }
  });
}

function fill_guest_edit() {
  const user_to_edit = guest_user;
  const pop_in = $(".GuestUserListPopInContainer");
  fill_user_edit_summary(user_to_edit, pop_in, true);
  fill_user_edit_properties(user_to_edit, pop_in);
  fill_user_edit_preferences(user_to_edit, pop_in);
  fill_user_edit_update(user_to_edit, pop_in);
}

function fill_new_user() {
  // When you want to add an user: privacy level and groups
  // by default are the same as Guest, not status
  const addUserPopIn = $("#AddUser");
  const status_index = get_status_index("normal");
  const level_index = get_level_index(guest_user.level);
  set_selected_groups(guest_user.groups);
  groupAddUserSelectize.clear();
  groupAddUserSelectize.load(function (
    callback: (data: GroupOption[]) => void,
  ) {
    callback(groupOptions);
  });
  jQuery.each(
    jQuery.grep(groupOptions, function (group) {
      return group.isSelected;
    }),
    function (i, group) {
      groupAddUserSelectize.addItem(group.value);
    },
  );
  addUserPopIn
    .find(`.user-property-status select option:eq(${status_index})`)
    .prop("selected", true);
  addUserPopIn
    .find(`.user-property-level select option:eq(${level_index})`)
    .prop("selected", true);
  addUserPopIn
    .find('.user-list-checkbox[name="hd_enabled"]')
    .attr("data-selected", guest_user.enabledHigh ? "1" : "0");
}

function fill_who_is_the_king(user_to_edit: UserRow, pop_in: JQuery) {
  const who_is_the_king = pop_in.find("#who_is_the_king");
  // By default I'm an admin and I only see who is the Main User
  who_is_the_king
    .removeClass("princes-of-this-piwigo king-of-this-piwigo can-change")
    .addClass("royal-court-of-this-piwigo cannot-change")
    .attr("title", mainAskWebmaster)
    .tipTip();
  who_is_the_king.off("click");
  // I'm an webmaster
  if (connected_user_status === "webmaster") {
    // check user to edit status
    switch (user_to_edit.status) {
      // user to edit is an webmaster
      case "webmaster":
        who_is_the_king
          .removeClass(
            "royal-court-of-this-piwigo king-of-this-piwigo cannot-change",
          )
          .addClass("princes-of-this-piwigo can-change")
          .attr("title", mainUserSet)
          .tipTip();
        if (!is_owner(user_to_edit.id)) {
          who_is_the_king.off("click").on("click", function () {
            open_main_user_modal(user_to_edit);
          });
        }
        break;
      // if user to edit is not an webmaster he cannot be set as a main user
      default:
        who_is_the_king
          .removeClass("princes-of-this-piwigo king-of-this-piwigo")
          .addClass("royal-court-of-this-piwigo")
          .attr("title", mainUserUpgradeWebmaster)
          .tipTip();
        break;
    }
  }

  // Main User also the King
  if (is_owner(user_to_edit.id)) {
    who_is_the_king
      .removeClass(
        "princes-of-this-piwigo royal-court-of-this-piwigo can-change",
      )
      .addClass("king-of-this-piwigo cannot-change")
      .attr("title", mainUserStr)
      .tipTip();
  }
}

/*-------------------
Fill data for setInfo
-------------------*/

function fill_ajax_data_from_properties(
  ajax_data: Record<string, unknown>,
  pop_in: JQuery,
) {
  const groups_selected = pop_in
    .find(".user-property-group .selectize-input .item")
    .map(function (this: HTMLElement) {
      return parseInt($(this).attr("data-value")!);
    })
    .get();
  // console.log(groups_selected);
  ajax_data["email"] = pop_in.find(".user-property-email input").val();
  if (
    connected_user_status == "admin" &&
    pop_in.find(".user-property-status select").val() != "webmaster" &&
    pop_in.find(".user-property-status select").val() != "admin"
  ) {
    ajax_data["status"] = pop_in.find(".user-property-status select").val();
  } else if (connected_user_status == "webmaster") {
    ajax_data["status"] = pop_in.find(".user-property-status select").val();
  }
  // console.log(ajax_data['status']);
  ajax_data["level"] = Number(pop_in.find(".user-property-level select").val());
  ajax_data["groupIds"] = groups_selected.length == 0 ? [-1] : groups_selected;
  ajax_data["enabledHigh"] =
    pop_in
      .find('.user-list-checkbox[name="hd_enabled"]')
      .attr("data-selected") == "1"
      ? true
      : false;
  return ajax_data;
}

function fill_ajax_data_from_preferences(
  ajax_data: Record<string, unknown>,
  pop_in: JQuery,
) {
  ajax_data["theme"] = pop_in.find(".user-property-theme select").val();
  ajax_data["language"] = pop_in.find(".user-property-lang select").val();
  ajax_data["nbImagePage"] =
    nb_image_page_values[
      pop_in
        .find(".photos-select-bar .slider-bar-container")
        .slider("option", "value") as number
    ];
  ajax_data["recentPeriod"] =
    recent_period_values[
      pop_in
        .find(".period-select-bar .slider-bar-container")
        .slider("option", "value") as number
    ];
  ajax_data["expand"] =
    pop_in
      .find('.user-list-checkbox[name="expand_all_albums"]')
      .attr("data-selected") == "1"
      ? true
      : false;
  ajax_data["showNbComments"] =
    pop_in
      .find('.user-list-checkbox[name="show_nb_comments"]')
      .attr("data-selected") == "1"
      ? true
      : false;
  ajax_data["showNbHits"] =
    pop_in
      .find('.user-list-checkbox[name="show_nb_hits"]')
      .attr("data-selected") == "1"
      ? true
      : false;
  return ajax_data;
}

function fill_ajax_data_from_container(
  ajax_data: Record<string, unknown>,
  pop_in: JQuery,
) {
  ajax_data = fill_ajax_data_from_properties(ajax_data, pop_in);
  ajax_data = fill_ajax_data_from_preferences(ajax_data, pop_in);
  return ajax_data;
}

/*----------------
Ajax Requests
----------------*/

function get_first_selection_usernames(callback: () => void) {
  const first_ids = selection.slice(0, 50).map((x) => x.id);
  void ajax({
    url: "api/v1/users",
    type: "GET",
    data: {
      order: "id",
      userIds: first_ids,
      exclude: [guest_id],
    },
    dataType: "json",
    success: function (data: UserListResponse) {
      const result = data.users;
      for (let i = 0; i < result.length; i++) {
        const index = selection.findIndex((x) => x.id === result[i]!.id);
        if (index != -1) {
          selection[index]!.username = result[i]!.username;
        }
      }
      callback();
    },
  });
}

function select_whole_set() {
  const filterLevel = $(".advanced-filter-select[name=filter_level]").val();
  const filterGroup = $(".advanced-filter-select[name=filter_group]").val();
  const filterStatus = $(".advanced-filter-select[name=filter_status]").val();
  void ajax({
    url: "api/v1/users",
    type: "GET",
    data: {
      order: "id",
      page: actual_page - 1,
      perPage: 0,
      exclude: [guest_id],
      status: filterStatus ? [filterStatus] : [],
      groupIds: filterGroup ? [filterGroup] : [],
      minLevel: filterLevel,
      maxLevel: filterLevel,
      minRegister:
        register_dates[
          (
            $(".dates-select-bar .slider-bar-container").slider(
              "option",
              "values",
            ) as number[]
          )[0]!
        ],
      maxRegister:
        register_dates[
          (
            $(".dates-select-bar .slider-bar-container").slider(
              "option",
              "values",
            ) as number[]
          )[1]!
        ],
    },
    dataType: "json",
    beforeSend: function () {
      $("#checkActions .loading").show();
    },
    success: function (data: UserListResponse) {
      selection = data.users.map((x) => {
        return { id: x.id };
      });
      $("#checkActions .loading").hide();
      update_selection_content();
    },
    error: function () {
      $("#checkActions .loading").hide();
    },
  });
}

function update_user_username() {
  const pop_in_container = $("#UserList");
  const ajax_data: Record<string, unknown> = {};
  const newUsername = String(
    pop_in_container.find(".user-property-input-username").val(),
  );
  ajax_data["username"] = newUsername;
  if (newUsername.replace(/\s/g, "").length == 0) {
    $(".update-user-fail")
      .html(fieldNotEmpty)
      .fadeIn()
      .delay(1500)
      .fadeOut(2500);
    return;
  }
  void ajax({
    url: "api/v1/users/" + last_user_id,
    type: "PATCH",
    contentType: "application/json",
    headers: { "X-CSRF-Token": pwg_token },
    data: JSON.stringify(ajax_data),
    dataType: "json",
    success: (
      data: operations["userUpdate"]["responses"][200]["content"]["application/json"],
    ) => {
      if (last_user_index != -1) {
        // "username" may be missing from a defensive-fallback response
        // (the row couldn't be re-fetched right after the update) --
        // the value we just successfully submitted is still correct.
        const updatedUsername =
          "username" in data ? data.username : newUsername;
        current_users[last_user_index]!.username = updatedUsername;
        $("#UserList .user-property-username .edit-username-title").html(
          updatedUsername,
        );
        $("#UserList .user-property-initials span").html(
          get_initials(updatedUsername),
        );
        fill_container_user_info(
          $("#user-table-content .user-container").eq(last_user_index),
          last_user_index,
        );
      }
      $(".user-property-username-change-input").hide();
      $(".edit-username-success").fadeIn();
      $("#close_username_success").on("click", function () {
        $(".edit-username-success").hide();
        $(".user-property-username-change-input").show();
        $(".user-property-username-change").hide();
      });
    },
  });
}

function update_user_password() {
  const pop_in_container = $("#UserList");
  const ajax_data: Record<string, unknown> = {};
  const newPassword = String(
    pop_in_container.find(".user-property-input-password").val(),
  );
  ajax_data["password"] = newPassword;
  void ajax({
    url: "api/v1/users/" + last_user_id,
    type: "PATCH",
    contentType: "application/json",
    headers: { "X-CSRF-Token": pwg_token },
    data: JSON.stringify(ajax_data),
    dataType: "json",
    success: () => {
      $(".user-property-password-change-inputs").hide();
      $("#edit_password_success_change").fadeIn();

      if (window.isSecureContext && navigator.clipboard) {
        $("#copy_password").on("click", function () {
          copyToClipboard(newPassword);
          $("#password_msg_success").html(passwordCopied);
        });
      }

      $("#close_password_success").on("click", function () {
        $(".user-property-password-change").hide();
        $("#edit_password_success_change").hide();
        $(".user-property-password-change-inputs").show();
        reset_input_password();
      });
    },
  });
}

function update_user_info() {
  //Show spinner
  $(".update-user-button i")
    .removeClass("icon-floppy")
    .addClass("icon-spin6 animate-spin");
  $(".update-user-button").addClass("unclickable");
  const pop_in_container = $(".UserListPopInContainer");
  let ajax_data: Record<string, unknown> = {};
  if (plugins_users_infos_table.length > 0) {
    const keyCurrentUsers = Object.keys(current_users[last_user_index]!);
    plugins_users_infos_table.forEach((i) => {
      if (keyCurrentUsers.includes(i.users_table)) {
        ajax_data[i.users_table] = $("#" + i.content_id).val();
      } else {
        console.error(i.users_table, " doesn't exist in USER_INFOS_TABLE");
      }
    });
  }

  ajax_data = fill_ajax_data_from_container(ajax_data, pop_in_container);
  void ajax({
    url: "api/v1/users/" + last_user_id,
    type: "PATCH",
    contentType: "application/json",
    headers: { "X-CSRF-Token": pwg_token },
    data: JSON.stringify(ajax_data),
    dataType: "json",
    beforeSend: function () {
      $("#UserList .update-user-fail").fadeOut();
      $("#UserList .update-user-success").fadeOut();
    },
    success: function (
      result_user: operations["userUpdate"]["responses"][200]["content"]["application/json"],
    ) {
      if (last_user_index != -1) {
        current_users[last_user_index] = {
          ...current_users[last_user_index]!,
          ...result_user,
        };
        fill_container_user_info(
          $("#user-table-content .user-container").eq(last_user_index),
          last_user_index,
        );
      }
      $("#UserList .update-user-success").fadeIn().delay(1500).fadeOut(2500);

      //Hide spinner
      $(".update-user-button i")
        .removeClass("icon-spin6 animate-spin")
        .addClass("icon-floppy");
      $(".update-user-button").removeClass("unclickable");

      // update plugins
      if (Object.keys(plugins_get_functions).length > 0) {
        Object.entries(plugins_set_functions).forEach((f) => {
          f[1]();
        });
      }

      // update who is the king -- `result_user` may be a defensive
      // fallback (`{id}` only, missing `.status`); the merged
      // `current_users` entry (old data overlaid with whatever the
      // response did carry) is always a real, complete UserRow.
      fill_who_is_the_king(
        last_user_index != -1 ? current_users[last_user_index]! : guest_user,
        $("#UserList"),
      );
    },
    error: function (jqXHR) {
      const message =
        (jqXHR.responseJSON as { detail?: string } | undefined)?.detail ??
        errorStr;
      $("#UserList .update-user-fail").html(message);
      $("#UserList .update-user-fail").fadeIn();
      $(".update-user-button i")
        .addClass("icon-floppy")
        .removeClass("icon-spin6 animate-spin");
      $(".update-user-button").removeClass("unclickable");
      setTimeout(() => {
        $("#UserList .update-user-fail").fadeOut();
      }, 5000);
    },
  });
}

function get_guest_info() {
  void ajax({
    url: "api/v1/users",
    type: "GET",
    data: {
      userIds: [guest_id],
    },
    dataType: "json",
    success: (data: UserListResponse) => {
      if (data.users.length) {
        guest_user = data.users[0]!;
        fill_guest_edit();
      }
    },
  });
}

function get_user_info(uid: number, callback: (() => void) | null = null) {
  void ajax({
    url: "api/v1/users",
    type: "GET",
    data: {
      userIds: [uid],
    },
    dataType: "json",
    success: (data: UserListResponse) => {
      if (data.users.length) {
        const result_user = data.users[0]!;
        fill_user_edit(result_user);
        callback?.();
      }
    },
  });
}

function update_guest_info() {
  //Show spinner
  $(".update-user-button i")
    .removeClass("icon-floppy")
    .addClass("icon-spin6 animate-spin");
  $(".update-user-button").addClass("unclickable");

  const pop_in_container = $(".GuestUserListPopInContainer");
  let ajax_data: Record<string, unknown> = {};
  ajax_data = fill_ajax_data_from_container(ajax_data, pop_in_container);
  ajax_data.email = undefined;
  ajax_data.status = undefined;
  void ajax({
    url: "api/v1/users/" + guest_id,
    type: "PATCH",
    contentType: "application/json",
    headers: { "X-CSRF-Token": pwg_token },
    data: JSON.stringify(ajax_data),
    dataType: "json",
    success: function (
      _data: operations["userUpdate"]["responses"][200]["content"]["application/json"],
    ) {
      $("#GuestUserList .update-user-success")
        .fadeIn()
        .delay(1500)
        .fadeOut(2500);
      //Hide spinner
      $(".update-user-button i")
        .removeClass("icon-spin6 animate-spin")
        .addClass("icon-floppy");
      $(".update-user-button").removeClass("unclickable");
    },
    error: function () {
      $(".update-user-button i")
        .removeClass("icon-spin6 animate-spin")
        .addClass("icon-floppy");
      $(".update-user-button").removeClass("unclickable");
    },
  });
}

function update_user_list() {
  const update_data: Record<string, unknown> = {
    order: filter_by, // We want the most recent user first
    page: actual_page - 1,
    perPage: per_page,
    exclude: [guest_id],
  };
  const userSearchVal = $("#user_search").val();
  if (userSearchVal) {
    const matches = String(userSearchVal).match(/^id:(\d+)$/);
    if (matches) {
      update_data["userIds"] = [matches[1]];
    }
    // Free-text fuzzy search (username/email/group-name) has no
    // GET /api/v1/users equivalent -- UserListController's own
    // docblock already documents this as a deliberately deferred
    // filter, not a gap introduced by this conversion.
  }
  if ($(".advanced-filter").hasClass("advanced-filter-open")) {
    const filterStatus = $(".advanced-filter-select[name=filter_status]").val();
    const filterGroup = $(".advanced-filter-select[name=filter_group]").val();
    const filterLevel = $(".advanced-filter-select[name=filter_level]").val();
    update_data["status"] = filterStatus ? [filterStatus] : [];
    update_data["groupIds"] = filterGroup ? [filterGroup] : [];
    update_data["minLevel"] = filterLevel;
    update_data["maxLevel"] = filterLevel;
    update_data["minRegister"] =
      register_dates[
        (
          $(".dates-select-bar .slider-bar-container").slider(
            "option",
            "values",
          ) as number[]
        )[0]!
      ];
    update_data["maxRegister"] =
      register_dates[
        (
          $(".dates-select-bar .slider-bar-container").slider(
            "option",
            "values",
          ) as number[]
        )[1]!
      ];
  }
  void ajax({
    url: "api/v1/users",
    type: "GET",
    data: update_data,
    dataType: "json",
    beforeSend: function () {
      $(".user-update-spinner").show();
    },
    success: function (data: UserListResponse) {
      total_users = data.totalCount;
      if (first_update) {
        $("h1").append(`<span class='badge-number'>${total_users}</span>`);
        first_update = false;
      }
      nb_filtered_users = data.totalCount;
      update_pagination_menu();
      current_users = data.users;
      generate_user_list();
      $(".user-col.user-first-col.user-container-edit").click(function () {
        const uid_index = Number(
          $(this).closest(".user-container").attr("key"),
        );
        last_user_id = current_users[uid_index]!.id;
        last_user_index = uid_index;
        fill_user_edit(current_users[uid_index]!);
        $("#UserList").fadeIn();
        $("#tab_properties")[0]!.scrollIntoView({
          behavior: "instant",
        });
      });
      set_selected_to_selection();

      $(".user-update-spinner").hide();

      let nb_filters = 0;
      if ($(".advanced-filter-select[name=filter_status]").val() != "")
        nb_filters += 1;
      if ($(".advanced-filter-select[name=filter_group]").val() != "")
        nb_filters += 1;
      if ($(".advanced-filter-select[name=filter_level]").val() != "")
        nb_filters += 1;
      if (
        (
          $(".dates-select-bar .slider-bar-container").slider(
            "option",
            "values",
          ) as number[]
        )[0] != 0
      )
        nb_filters += 1;
      if (
        (
          $(".dates-select-bar .slider-bar-container").slider(
            "option",
            "values",
          ) as number[]
        )[1] !=
        register_dates.length - 1
      )
        nb_filters += 1;

      show_filter_infos(nb_filters);
    },
    error: () => {
      $(".user-update-spinner").hide();
    },
  });
}

function add_user() {
  const ajax_data: Record<string, unknown> = {};
  const groups_selected = $(
    ".AddUserInputContainer .user-property-group .selectize-input .item",
  )
    .map(function (this: HTMLElement) {
      return parseInt($(this).attr("data-value")!);
    })
    .get();
  ajax_data.username = $(".AddUserLabelUsername .user-property-input").val();
  ajax_data.email = $(".AddUserLabelEmail .user-property-input").val();
  ajax_data.status = $(
    ".AddUserInputContainer .user-property-status select",
  ).val();
  ajax_data.level = Number(
    $(".AddUserInputContainer .user-property-level select").val(),
  );
  ajax_data.enabledHigh =
    $('.AddUserInputContainer .user-list-checkbox[name="hd_enabled"]').attr(
      "data-selected",
    ) == "1"
      ? true
      : false;
  ajax_data.groupIds = groups_selected;

  // for debug
  // console.log(ajax_data);

  const data: Record<string, unknown> = {
    username: ajax_data.username,
    email: ajax_data.email,
  };

  if ("generic" === ajax_data.status) {
    data.password = $("#add_user_pass").val();
    data.passwordConfirm = $("#add_user_confpass").val();
  } else {
    data.autoPassword = true;
  }

  void ajax({
    url: "api/v1/users",
    type: "POST",
    contentType: "application/json",
    headers: { "X-CSRF-Token": pwg_token },
    data: JSON.stringify(data),
    dataType: "json",
    beforeSend: function () {
      $("#AddUser .AddUserErrors").css("visibility", "hidden");
      if ($(".AddUserLabelUsername .user-property-input").val() == "") {
        $("#AddUser .AddUserErrors").html(missingUsername);
        $("#AddUser .AddUserErrors").css("visibility", "visible");
        return false;
      }
      if ("generic" === ajax_data.status) {
        const pass = $("#add_user_pass").val();
        const confPass = $("#add_user_confpass").val();
        if ("" == pass) {
          $("#AddUser .AddUserErrors").html(missingPassword);
          $("#AddUser .AddUserErrors").css("visibility", "visible");
          return false;
        }
        if ("" == confPass) {
          $("#AddUser .AddUserErrors").html(missingConfPassword);
          $("#AddUser .AddUserErrors").css("visibility", "visible");
          return false;
        }
        if (pass !== confPass) {
          $("#AddUser .AddUserErrors").html(noMatchPassword);
          $("#AddUser .AddUserErrors").css("visibility", "visible");
          return false;
        }
      }
    },
    success: (
      data: operations["userCreate"]["responses"][201]["content"]["application/json"],
    ) => {
      const new_user_id = data.id;
      const default_group = "groups" in data ? data.groups : [];
      ajax_data.groupIds = (ajax_data.groupIds as number[]).concat(
        default_group,
      );
      add_infos_to_new_user(new_user_id, ajax_data);
    },
    error: (jqXHR) => {
      const message =
        (jqXHR.responseJSON as { detail?: string } | undefined)?.detail ??
        errorStr;
      $("#AddUser .AddUserErrors").html(message);
      $("#AddUser .AddUserErrors").css("visibility", "visible");
    },
  });
}

function add_infos_to_new_user(
  user_id: number,
  ajax_data: Record<string, unknown>,
) {
  void ajax({
    url: "api/v1/users/" + user_id,
    type: "PATCH",
    contentType: "application/json",
    headers: { "X-CSRF-Token": pwg_token },
    data: JSON.stringify({
      status: ajax_data.status,
      level: ajax_data.level,
      groupIds: ajax_data.groupIds,
      enabledHigh: ajax_data.enabledHigh,
    }),
    dataType: "json",
    success: function (
      data: operations["userUpdate"]["responses"][200]["content"]["application/json"],
    ) {
      const new_user_id = data.id;
      update_user_list();
      // add_user_close();
      $("#AddUserUpdated")
        .removeClass("icon-red icon-cancel")
        .addClass("icon-green border-green icon-ok");
      $("#AddUserUpdatedText").html(
        user_added_str.replace("%s", String(ajax_data.username)),
      );
      const status = ["webmaster", "admin", "normal"];
      if (status.includes(String(ajax_data.status))) {
        send_new_user_password(new_user_id, String(ajax_data.email));
      } else {
        add_user_close();
      }
      $("#AddUser .user-property-input").val("");
      $("#AddUserSuccess .edit-now")
        .off("click")
        .on("click", () => {
          last_user_id = new_user_id;
          last_user_index = get_container_index_from_uid(new_user_id);
          if (last_user_index != -1) {
            fill_user_edit(current_users[last_user_index]!);
            open_user_list();
          } else {
            get_user_info(new_user_id, open_user_list);
          }
        });
      $("#AddUserSuccess label span:first").html(
        user_added_str.replace("%s", String(ajax_data.username)),
      );
      $("#AddUserSuccess").css("display", "flex");
      $(".badge-number").html(String(+$(".badge-number").html() + 1));
    },
    error: function (jqXHR) {
      const message =
        (jqXHR.responseJSON as { detail?: string } | undefined)?.detail ??
        errorStr;
      $("#AddUser .AddUserErrors").html(message);
      $("#AddUser .AddUserErrors").css("visibility", "visible");
    },
  });
}

function send_new_user_password(user_id: number, mail: string) {
  const send_by_mail = mail === "" ? false : true;
  void ajax({
    url: "api/v1/users/" + user_id + "/actions/generate-password-link",
    dataType: "json",
    type: "POST",
    contentType: "application/json",
    headers: { "X-CSRF-Token": pwg_token },
    data: JSON.stringify({
      sendByMail: send_by_mail,
    }),
    success: function (
      response: operations["userGeneratePasswordLink"]["responses"][200]["content"]["application/json"],
    ) {
      const password_container = $("#AddUserPasswordInputContainer");
      password_container.show();
      $("#AddUserFieldContainer").hide();
      $("#AddUserSuccessContainer").fadeIn();
      $("#AddUserPasswordLink").val(response.generatedLink).trigger("focus");
      $("#AddUserTextField").html(
        send_by_mail
          ? sprintf(validLinkMail, response.timeValidation, `<b>${mail}</b>`)
          : sprintf(validLinkWithoutMail, response.timeValidation),
      );

      if (send_by_mail && !response.sendByMail) {
        $("#AddUserUpdated")
          .removeClass("icon-green border-green icon-ok")
          .addClass("icon-red-error icon-cancel");
        $("#AddUserUpdatedText").html(errorMailSent);
        $("#AddUserTextField").html(
          sprintf(errorMailSentMsg, response.timeValidation),
        );
      } else if (send_by_mail && response.sendByMail) {
        password_container.hide();
      }

      if (window.isSecureContext && navigator.clipboard) {
        $("#AddUserCopyPassword")
          .off("click")
          .on("click", function () {
            const successMsg = $("#AddUserUpdatedText");
            successMsg.fadeOut();
            copyToClipboard(response.generatedLink);
            $("#AddUserUpdated")
              .removeClass("icon-red icon-cancel")
              .addClass("icon-green border-green icon-ok");
            successMsg.html(copyLinkStr);
            successMsg.fadeIn();
          });
      }
      $("#AddUserButton")
        .off("click")
        .on("click", function () {
          add_user_close();
        });
    },
    error: function (jqXHR) {
      const message =
        (jqXHR.responseJSON as { detail?: string } | undefined)?.detail ??
        errorStr;
      $("#AddUserUpdated")
        .removeClass("icon-green border-green icon-ok")
        .addClass("icon-red-error icon-cancel");
      $("#AddUserUpdatedText").html(message);
    },
  });
}

function delete_user(uid: number) {
  void ajax({
    url: "api/v1/users/" + uid,
    type: "DELETE",
    headers: { "X-CSRF-Token": pwg_token },
    beforeSend: function () {
      //jQuery('#user'+uid+' .userDelete .loading').show();
    },
    success: function (
      _data: operations["userDelete"]["responses"][200]["content"]["application/json"],
    ) {
      close_user_list();
      update_user_list();
      $(".badge-number").html(String(+$(".badge-number").html() - 1));
      // msg where user was deleted
      //jQuery('#showAddUser .infos').html('&#x2714; User '+username+' deleted').show();
    },
    error: function () {
      //error just hide loading
      //jQuery('#user'+uid+' .userDelete .loading').hide();
    },
  });
}

function show_filter_infos(nb_filters: number) {
  if (String($("#user_search").val() ?? "").length != 0 || nb_filters != 0) {
    if (String(total_users) != "1") {
      $(".filtered-users").html(
        filtered_users.replace(/%d/g, String(total_users)),
      );
    } else {
      $(".filtered-users").html(
        filtered_user.replace(/%d/g, String(total_users)),
      );
    }
  } else {
    $(".filtered-users").html("");
  }

  if (nb_filters != 0) {
    $(".advanced-filter-btn").css({
      width: "80px",
    });
    $(".filter-counter").html(String(nb_filters)).css("display", "flex");
  } else {
    $(".advanced-filter-btn").css({
      width: "70px",
    });
    $(".filter-counter").css("display", "none").html("0");
  }
}

function send_link_password(
  email: string | null,
  username: string,
  user_id: number,
  send_by_mail: boolean,
) {
  void ajax({
    url: "api/v1/users/" + user_id + "/actions/generate-password-link",
    dataType: "json",
    type: "POST",
    contentType: "application/json",
    headers: { "X-CSRF-Token": pwg_token },
    data: JSON.stringify({
      sendByMail: send_by_mail,
    }),
    success: function (
      response: operations["userGeneratePasswordLink"]["responses"][200]["content"]["application/json"],
    ) {
      $("#result_send_mail_copy_input").val(response.generatedLink);
      if (send_by_mail) {
        if (response.sendByMail) {
          $("#result_send_mail")
            .removeClass("update-password-fail icon-red")
            .addClass("update-password-success icon-green");
          $("#icon_password_msg_result_mail")
            .removeClass("icon-cancel")
            .addClass("icon-ok");
          const curr_mail = String(
            $(".user-property-email .user-property-input").val() ?? "",
          ).length
            ? String($(".user-property-email .user-property-input").val())
            : (email ?? "");
          $("#password_msg_result_mail").html(
            sprintf(mailSentAt, username, curr_mail),
          );
        } else {
          $("#result_send_mail")
            .removeClass("update-password-success icon-green")
            .addClass("update-password-fail icon-red");
          $("#icon_password_msg_result_mail")
            .removeClass("icon-ok")
            .addClass("icon-cancel");
          $("#password_msg_result_mail").html(errorMailSent);
        }
        $(".user-property-password-choice").hide();
        $("#edit_password_result_mail").fadeIn();
        $("#close_password_mail_close")
          .off("click")
          .on("click", function () {
            reset_password_modals();
          });
      } else {
        $("#result_send_mail_copy")
          .removeClass("update-password-fail icon-red")
          .addClass("update-password-success icon-green");
        $("#result_send_mail_copy_icon")
          .removeClass("icon-cancel")
          .addClass("icon-ok");
        $("#result_send_mail_copy_msg").html(copyLinkStr);
        if (window.isSecureContext && navigator.clipboard) {
          copyToClipboard(response.generatedLink);
        }
      }
    },
    error: function (err) {
      console.log("Error send_link_password :", err);
      if (!send_by_mail) {
        $("#result_send_mail_copy")
          .removeClass("update-password-success icon-green")
          .addClass("update-password-fail icon-red");
        $("#result_send_mail_copy_icon")
          .removeClass("icon-ok")
          .addClass("icon-cancel");
        $("#result_send_mail_copy_msg").html(errorStr);
      } else {
        $("#result_send_mail")
          .removeClass("update-password-success icon-green")
          .addClass("update-password-fail icon-red");
        $("#icon_password_msg_result_mail")
          .removeClass("icon-ok")
          .addClass("icon-cancel");
        $("#password_msg_result_mail").html(errorMailSent);
        $(".user-property-password-choice").hide();
        $("#edit_password_result_mail").fadeIn();
        $("#close_password_mail_close")
          .off("click")
          .on("click", function () {
            reset_password_modals();
          });
      }
    },
  });
}

function set_main_user(user_id: number, new_username: string) {
  void ajax({
    url: "api/v1/users/" + user_id + "/actions/set-main-user",
    dataType: "json",
    type: "POST",
    contentType: "application/json",
    headers: { "X-CSRF-Token": pwg_token },
    success: function () {
      $("#who_is_the_king")
        .off("click")
        .removeClass(
          "princes-of-this-piwigo royal-court-of-this-piwigo can-change",
        )
        .addClass("king-of-this-piwigo cannot-change")
        .attr("title", mainUserStr)
        .tipTip();

      owner_id = user_id;
      owner_username = new_username;
      set_main_user_success();
    },
    error: function (err) {
      console.log(err);
    },
  });
}

const title_msg = pwg_getPageString(
  'Are you sure you want to delete the user "%s"?',
);
const confirm_msg = pwg_getPageString("Yes, I am sure");
const cancel_msg = pwg_getPageString("No, I have changed my mind");
const str_and_others_tags = pwg_getPageString("and %s others");
const missingUsername = pwg_getPageString("Please, enter a login");
const missingPassword = pwg_getPageString(
  "Password is missing. Please enter the password.",
);
const missingConfPassword = pwg_getPageString(
  "Password confirmation is missing. Please confirm the chosen password.",
);
const missingConfirm = pwg_getPageString("You need to confirm deletion");
const fieldNotEmpty = pwg_getPageString("Name field must not be empty");
const noMatchPassword = pwg_getPageString("The passwords do not match");
const missingField = pwg_getPageString("Please complete all fields");
const passwordCopied = pwg_getPageString("Password copied");
const mailSentAt = pwg_getPageString("Mail sent to %s [%s].");
const errorMailSent = pwg_getPageString("Error sending email");
const cannotSendMail = pwg_getPageString(
  "Cannot send an email to this user because he doesn't have an email address",
);
const mainUserContinue = pwg_getPageString(
  "You are about to set %s as main user instead of %s, do you wish to continue ?",
);
const mainUserRewrite = pwg_getPageString(
  "To be sure, please rewrite the word “%s” below",
);
const mainUserValidate = pwg_getPageString(
  "You can now change the main user from %s to %s.",
);
const mainUserSuccess = pwg_getPageString("%s is the new main user");
const mainUserStr = pwg_getPageString("Main user");
const mainAskWebmaster = pwg_getPageString(
  "You are not authorised to change the main user, please ask your webmaster",
);
const mainUserSet = pwg_getPageString("Set as main user");
const mainUserUpgradeWebmaster = pwg_getPageString(
  "This user must first be defined as the webmaster before it can be upgraded to the main user",
);
const errorStr = pwg_getPageString("an error happened");
const copyLinkStr = pwg_getPageString("Copied link");
const cantCopy = pwg_getPageString(
  "You cannot copy the password if the connection to this site is not secure.",
);
const validLinkMail = pwg_getPageString(
  'An activation link valid for %s has been sent to "%s". If the user doesn\'t receive the link, you can generate and copy a new one by editing the user and managing her password.',
);
const validLinkWithoutMail = pwg_getPageString(
  "Copy the link below and send it to the user so the password can be set.",
);
const errorMailSentMsg = pwg_getPageString(
  "An activation link valid for %s was created but could not be sent. You can now copy the link below and send it to the user.",
);

const registered_str = pwg_getPageString("Registered");
const last_visit_str = pwg_getPageString("Last visit");
const dates_infos = pwg_getPageString("between %s and %s");
const user_added_str = pwg_getPageString("User %s added");
const filtered_users = pwg_getPageString("<b>%d</b> filtered users");
const filtered_user = pwg_getPageString("<b>%d</b> filtered user");
const history_base_url = pwg_getPageData<string>("u_history");

const status_to_str: Record<string, string> = {
  webmaster: pwg_getPageString("user_status_webmaster"),
  admin: pwg_getPageString("user_status_admin"),
  normal: pwg_getPageString("user_status_normal"),
  generic: pwg_getPageString("user_status_generic"),
  guest: pwg_getPageString("user_status_guest"),
};

const view_selector = pwg_getPageData<string>("view_selector");
const pagination = String(pwg_getPageData<number>("pagination"));
per_page = parseInt(pagination);

months = [
  pwg_getPageString("Jan"),
  pwg_getPageString("Feb"),
  pwg_getPageString("Mar"),
  pwg_getPageString("Apr"),
  pwg_getPageString("May"),
  pwg_getPageString("Jun"),
  pwg_getPageString("Jul"),
  pwg_getPageString("Aug"),
  pwg_getPageString("Sep"),
  pwg_getPageString("Oct"),
  pwg_getPageString("Nov"),
  pwg_getPageString("Dec"),
];

/* Template variables */
connected_user = pwg_getPageData<number>("connected_user");
const connected_user_status = pwg_getPageData<string>("connected_user_status");
let owner_id = pwg_getPageData<number>("owner");
owner_username = pwg_getPageData<string>("owner_username");
const groups_arr_name = JSON.parse(
  pwg_getPageData<string>("groups_arr_name"),
) as string[];
const groups_arr_id: number[] = pwg_getPageData<string>("groups_arr_id")
  ? pwg_getPageData<string>("groups_arr_id").split(",").map(Number)
  : [];
groups_arr = groups_arr_id.map((elem, index) => [
  elem,
  groups_arr_name[index]!,
]);
guest_id = pwg_getPageData<number>("guest_id");
nb_days = pwg_getPageString("%d days");
//per page is too long for the popin
nb_photos = pwg_getPageString("%d photos");
pwg_token = pwg_getPageData<string>("csrf_token");
const has_group = pwg_getPageData<string | null>("filter_group");

const register_dates_str = pwg_getPageData<string>("register_dates");
const register_dates = register_dates_str.split(",");
const groupOptions: GroupOption[] = groups_arr.map(function (x) {
  return { value: x[0], label: x[1], isSelected: false };
});

/* Startup */
setupRegisterDates(register_dates);
selectionMode(false);
get_guest_info();
update_user_list();
update_selection_content();

$(".icon-help-circled").tipTip({
  maxWidth: "700px",
  fadeIn: "1000",
});

$(document).ready(function () {
  // Only webmaster can set admin or webmaster to others users
  if (connected_user_status !== "webmaster") {
    $(
      'select[name="status"] option[value="webmaster"], select[name="status"] option[value="admin"]',
    ).prop("disabled", true);
  }
  // We set the applyAction btn click event here so plugins can add cases to the list
  // which is not possible if this JS part is in a JS file
  // see #1571 on Github
  jQuery("#applyAction").click(function () {
    const action = jQuery("select[name=selectAction]").prop("value") as string;
    const data: Record<string, unknown> = {};
    switch (action) {
      case "delete":
        if (
          !(
            $(
              "#permitActionUserList .user-list-checkbox[name=confirm_deletion]",
            ).attr("data-selected") === "1"
          )
        ) {
          alert(missingConfirm);
          return false;
        }
        break;
      case "group_associate":
        data.group_id = jQuery(
          "#permitActionUserList select[name=associate]",
        ).prop("value");
        break;
      case "group_dissociate":
        data.group_id = jQuery(
          "#permitActionUserList select[name=dissociate]",
        ).prop("value");
        break;
      case "status":
        data.status = jQuery("#permitActionUserList select[name=status]").prop(
          "value",
        );
        break;
      case "enabled_high":
        data.enabled_high =
          $(
            "#permitActionUserList .user-list-checkbox[name=enabled_high_yes]",
          ).attr("data-selected") === "1"
            ? true
            : false;
        break;
      case "level":
        data.level = jQuery("#permitActionUserList select[name=level]").val();
        break;
      case "nb_image_page":
        data.nb_image_page = jQuery(
          "#permitActionUserList input[name=nb_image_page]",
        ).val();
        break;
      case "theme":
        data.theme = jQuery("#permitActionUserList select[name=theme]").val();
        break;
      case "language":
        data.language = jQuery(
          "#permitActionUserList select[name=language]",
        ).val();
        break;
      case "recent_period":
        data.recent_period =
          recent_period_values[
            $(
              "#permitActionUserList .period-select-bar .slider-bar-container",
            ).slider("option", "value") as number
          ];
        break;
      case "expand":
        data.expand =
          $("#permitActionUserList .user-list-checkbox[name=expand_yes]").attr(
            "data-selected",
          ) === "1"
            ? true
            : false;
        break;
      case "show_nb_comments":
        data.show_nb_comments =
          $(
            "#permitActionUserList .user-list-checkbox[name=show_nb_comments_yes]",
          ).attr("data-selected") === "1"
            ? true
            : false;
        break;
      case "show_nb_hits":
        data.show_nb_hits =
          $(
            "#permitActionUserList .user-list-checkbox[name=show_nb_hits_yes]",
          ).attr("data-selected") === "1"
            ? true
            : false;
        break;
      default:
        alert("Unexpected action");
        return false;
    }

    // Translate the `data` bag above into the real /api/v1 request(s)
    // -- one bulk group action, or one PATCH/DELETE per selected user
    // (there is no bulk-multi-id endpoint for Users).
    const userIds = selection.map((x) => x.id);
    const fieldByAction: Record<string, string> = {
      status: "status",
      enabled_high: "enabledHigh",
      level: "level",
      nb_image_page: "nbImagePage",
      theme: "theme",
      language: "language",
      recent_period: "recentPeriod",
      expand: "expand",
      show_nb_comments: "showNbComments",
      show_nb_hits: "showNbHits",
    };
    const numericFields = ["level", "nbImagePage", "recentPeriod"];

    let request: Promise<unknown> | AjaxThenable;
    jQuery("#applyActionLoading").show();
    jQuery("#applyActionBlock .infos").fadeOut();

    if (action === "delete") {
      request = Promise.all(
        userIds.map((id) =>
          ajax({
            url: "api/v1/users/" + id,
            method: "DELETE",
            headers: { "X-CSRF-Token": pwg_token },
          }),
        ),
      );
    } else if (action === "group_associate" || action === "group_dissociate") {
      request = ajax({
        url:
          "api/v1/groups/" +
          String(data.group_id) +
          "/actions/" +
          (action === "group_associate" ? "add-user" : "remove-user"),
        method: "POST",
        contentType: "application/json",
        data: JSON.stringify({ userIds: userIds }),
        headers: { "X-CSRF-Token": pwg_token },
      });
    } else {
      const field = fieldByAction[action]!;
      const value = numericFields.includes(field)
        ? Number(data[action])
        : data[action];
      request = Promise.all(
        userIds.map((id) =>
          ajax({
            url: "api/v1/users/" + id,
            method: "PATCH",
            contentType: "application/json",
            data: JSON.stringify({ [field]: value }),
            headers: { "X-CSRF-Token": pwg_token },
          }),
        ),
      );
    }

    Promise.resolve(request)
      .then(function () {
        jQuery("#applyActionLoading").hide();
        jQuery("#applyActionBlock .infos").fadeIn();
        jQuery("#applyActionBlock .infos").css("display", "inline-block");
        update_user_list();
        if (action == "delete") {
          selection = [];
          update_selection_content();
        }
      })
      .catch(function () {
        jQuery("#applyActionLoading").hide();
      });
    return false;
  });
});

// Explicit `window.` exposure -- required at runtime, not decorative
// (see plugins_installed_config.ts's own leading comment for the full
// explanation): `plugin_add_tab_in_user_modal`'s own JSDoc documents it
// as a real public extension point third-party plugins call from their
// own, separately-loaded `<script>` tags -- this file's own top-level
// declarations are module-private otherwise (`export {}` above), so
// without this, no external plugin could actually reach it, unlike
// before this file became a real ES module.
window.plugin_add_tab_in_user_modal = plugin_add_tab_in_user_modal;
