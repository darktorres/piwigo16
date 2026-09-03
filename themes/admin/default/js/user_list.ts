import type { components, operations } from "../../../../openapi/client/schema";
import { getRandomInt, sprintf, jConfirm_confirm_options } from "./common";

import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../default/js/page-data";
import { ajax, AjaxError } from "../../../default/js/vendor/ajax";
import { confirm } from "../../../default/js/vendor/jconfirm";
import {
  getSelectizeInstance,
  selectize as createSelectize,
} from "../../../default/js/vendor/selectize";
import { slider, type SliderUIParams } from "../../../default/js/vendor/slider";
import {
  addClass,
  animate,
  append,
  attr,
  attrOf,
  css,
  data,
  delay,
  fadeIn,
  fadeOut,
  find,
  hasClass,
  hide,
  html,
  htmlOf,
  is,
  off,
  on,
  parseHtml,
  ready,
  removeAttr,
  removeClass,
  setChecked,
  setVal,
  show,
  trigger,
  val,
} from "../../../default/js/vendor/dom";
import { tipTip } from "../../../default/js/vendor/tiptip";

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
interface GroupOption extends Record<string, unknown> {
  value: number;
  label: string;
  isSelected: boolean;
}

function siblingsOf(el: Element): Element[] {
  const parent = el.parentElement;
  if (parent === null) return [];
  return Array.from(parent.children).filter((child) => child !== el);
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
let owner_id = pwg_getPageData<number>("owner");
let per_page = 5;
// Assigned much further down, once groups_arr has its real value (the
// "Startup" sequence's own real dependency order) -- declared here so
// the functions that read it, defined earlier in this file, don't
// forward-reference it (they only ever run after Startup completes).
let groupOptions: GroupOption[] = [];
// Same reasoning as groupOptions above: assigned in the "Startup"
// sequence near the bottom of the file (register_dates_str.split(",")),
// but read by functions defined much earlier that only ever run once
// Startup has completed.
let register_dates: string[] = [];

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
const connected_user_status = pwg_getPageData<string>("connected_user_status");
const has_group = pwg_getPageData<string | null>("filter_group");

/*----------------
Escape of pop-in
----------------*/

//get out of pop in via escape key
on(document, "keydown", function (e: Event) {
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- "keydown" always dispatches a real KeyboardEvent; on()'s own handler param is typed generically via the native EventListener interface.
  if ((e as KeyboardEvent).key === "Escape") {
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

const groupSelectizeTargets = document.querySelectorAll<HTMLSelectElement>(
  "[data-selectize=groups]",
);
groupSelectizeTargets.forEach((el) => {
  createSelectize<string | number, GroupOption>(el, {
    valueField: "value",
    labelField: "label",
    searchField: ["label"],
    plugins: ["remove_button"],
  });
});

const groupSelectize = getSelectizeInstance<string | number, GroupOption>(
  groupSelectizeTargets[0]!,
)!;
const groupGuestSelectize = getSelectizeInstance<string | number, GroupOption>(
  groupSelectizeTargets[1]!,
)!;
const groupAddUserSelectize = getSelectizeInstance<
  string | number,
  GroupOption
>(groupSelectizeTargets[2]!)!;

/*-----------------
OnClick functions
-----------------*/
function open_user_list() {
  hide_temporary_messages();
  fadeIn(document.querySelectorAll("#UserList"));
}

function close_user_list() {
  hide_temporary_messages();
  setVal(document.querySelectorAll("#result_send_mail_copy_input"), "");
  fadeOut(document.querySelectorAll("#UserList"));
}

function open_guest_user_list() {
  hide_temporary_messages();
  fadeIn(document.querySelectorAll("#GuestUserList"));
}

function close_guest_user_list() {
  hide_temporary_messages();
  fadeOut(document.querySelectorAll("#GuestUserList"));
}

function isSelectionMode() {
  return is(document.querySelectorAll("#toggleSelectionMode"), ":checked");
}

ready(function () {
  tipTip(document.querySelectorAll(".user-property-register"), {
    maxWidth: "300px",
    delay: 0,
    fadeIn: 200,
    fadeOut: 200,
  });
  tipTip(document.querySelectorAll(".user-property-last-visit"), {
    maxWidth: "300px",
    delay: 0,
    fadeIn: 200,
    fadeOut: 200,
  });
  document
    .querySelectorAll(".advanced-filter-level select option")
    .item(1)
    // eslint-disable-next-line @typescript-eslint/no-unnecessary-condition -- real runtime guard: NodeListOf.item() is typed non-nullable but really returns null for an out-of-range index (fewer than 2 options).
    ?.remove();

  /* Edit Password */
  on(
    document.querySelectorAll(".button-edit-password-icon"),
    "click",
    function () {
      reset_password_modals();
      fadeIn(document.querySelectorAll(".user-property-password-change"));
    },
  );

  on(document.querySelectorAll(".edit-password-cancel"), "click", function () {
    fadeOut(document.querySelectorAll(".user-property-password-change"));
    reset_input_password();
  });

  on(
    document.querySelectorAll(".icon-show-password"),
    "click",
    function (this: Element) {
      const [closestInput] = siblingsOf(this);
      if (closestInput === undefined) return;
      if (attrOf(closestInput, "type") === "password") {
        attr(closestInput, "type", "text");
        removeClass(this, "icon-eye");
        addClass(this, "icon-eye-off");
      } else {
        attr(closestInput, "type", "password");
        removeClass(this, "icon-eye-off");
        addClass(this, "icon-eye");
      }
    },
  );

  // Password input onkeyup clear error
  on(
    document.querySelectorAll("#edit_user_password, #edit_user_conf_password"),
    "keyup",
    function () {
      hide_error_edit_user();
    },
  );

  /* Edit Username */
  on(document.querySelectorAll(".edit-username"), "click", function () {
    show(document.querySelectorAll(".user-property-username-change"));
  });

  on(document.querySelectorAll(".edit-username-cancel"), "click", function () {
    //possibly reset input value
    hide(document.querySelectorAll(".user-property-username-change"));
  });

  on(
    document.querySelectorAll("#UserList .close-update-button"),
    "click",
    close_user_list,
  );
  on(document.querySelectorAll(".CloseUserList"), "click", close_user_list);

  setChecked(document.querySelectorAll("#toggleSelectionMode"), false);
  on(document.querySelectorAll("#toggleSelectionMode"), "click", function () {
    const isSelection = is(
      document.querySelectorAll("#toggleSelectionMode"),
      ":checked",
    );
    selectionMode(isSelection);
  });
  on(
    document.querySelectorAll(".edit-guest-user-button"),
    "click",
    open_guest_user_list,
  );
  on(
    document.querySelectorAll(".CloseGuestUserList"),
    "click",
    close_guest_user_list,
  );
  on(
    document.querySelectorAll("#GuestUserList .close-update-button"),
    "click",
    close_guest_user_list,
  );
  /* Action */
  hide(document.querySelectorAll("[id^=action_]"));

  on(
    document.querySelectorAll("select[name=selectAction]"),
    "change",
    function (this: HTMLSelectElement) {
      hide(document.querySelectorAll("#applyActionBlock .infos"));
      hide(document.querySelectorAll("[id^=action_]"));
      show(document.querySelectorAll("#action_" + this.value));
      if (val(this) !== "-1") {
        show(document.querySelectorAll("#applyActionBlock"));
      } else {
        hide(document.querySelectorAll("#applyActionBlock"));
      }
    },
  );
  const yesNoCheckboxes = document.querySelectorAll(
    ".yes_no_radio .user-list-checkbox",
  );
  off(yesNoCheckboxes, "click");
  on(yesNoCheckboxes, "click", function (this: Element) {
    if (attrOf(this, "data-selected") !== "1") {
      attr(this, "data-selected", "1");
      siblingsOf(this).forEach((sibling) => {
        attr(sibling, "data-selected", "0");
      });
    }
  });
  on(document.querySelectorAll(".EditUserGenPassword"), "click", function () {
    const password = gen_password();
    setVal(document.querySelectorAll("#edit_user_password"), password);
    setVal(document.querySelectorAll("#edit_user_conf_password"), password);
    hide_error_edit_user();
  });
  on(
    document.querySelectorAll(".AddUserGenPassword span"),
    "click",
    function () {
      const password = gen_password();
      setVal(document.querySelectorAll("#add_user_pass"), password);
      setVal(document.querySelectorAll("#add_user_confpass"), password);
    },
  );
  on(
    document.querySelectorAll(".AddUserSubmit"),
    "click",
    () => void add_user(),
  );
  on(document.querySelectorAll(".AddUserCancel"), "click", add_user_close);
  on(document.querySelectorAll(".CloseAddUser"), "click", add_user_close);

  //open add user pop in
  on(document.querySelectorAll(".add-user-button"), "click", add_user_open);

  /* Select */

  on(document.querySelectorAll("#selectSet"), "click", function (e: Event) {
    void select_whole_set();
    e.preventDefault();
    return false;
  });

  on(document.querySelectorAll("#selectAllPage"), "click", function (e: Event) {
    const selection_ids = selection.map((x) => x.id);
    for (const user of current_users) {
      if (!selection_ids.includes(user.id)) {
        selection.push({
          id: user.id,
          username: user.username,
        });
      }
    }
    update_selection_content();
    e.preventDefault();
    return false;
  });

  on(document.querySelectorAll("#selectNone"), "click", function (e: Event) {
    selection = [];
    update_selection_content();
    e.preventDefault();
    return false;
  });

  on(document.querySelectorAll("#selectInvert"), "click", function (e: Event) {
    const selection_ids = selection.map((x) => x.id);
    for (const user of current_users) {
      if (selection_ids.includes(user.id)) {
        selection.splice(
          selection.findIndex((x) => x.id === user.id),
          1,
        );
      } else {
        selection.push({
          id: user.id,
          username: user.username,
        });
      }
    }
    update_selection_content();
    e.preventDefault();
    return false;
  });

  setVal(
    document.querySelectorAll(
      "#permitActionUserList select[name=selectAction]",
    ),
    "-1",
  );

  on(
    document.querySelectorAll(".advanced-filter-btn"),
    "click",
    advanced_filter_button_click,
  );
  on(
    document.querySelectorAll(".advanced-filter span.icon-cancel"),
    "click",
    advanced_filter_hide,
  );
  on(
    document.querySelectorAll(".advanced-filter-select"),
    "change",
    () => void update_user_list(),
  );
  on(
    document.querySelectorAll("#user_search"),
    "input",
    () => void update_user_list(),
  );

  /*View manager*/

  if (is(document.querySelectorAll("#displayCompact"), ":checked")) {
    setDisplayCompact();
  }

  if (is(document.querySelectorAll("#displayLine"), ":checked")) {
    setDisplayLine();
  }

  if (is(document.querySelectorAll("#displayTile"), ":checked")) {
    setDisplayTile();
  }

  on(document.querySelectorAll("#displayCompact"), "change", function () {
    setDisplayCompact();

    if (hasClass(document.querySelectorAll(".addAlbum"), "input-mode")) {
      hide(document.querySelectorAll(".addAlbum p"));
    }
    set_view_selector("compact");
  });

  on(document.querySelectorAll("#displayLine"), "change", function () {
    setDisplayLine();

    if (hasClass(document.querySelectorAll(".addAlbum"), "input-mode")) {
      hide(document.querySelectorAll(".addAlbum p"));
    }
    set_view_selector("line");
  });

  on(document.querySelectorAll("#displayTile"), "change", function () {
    setDisplayTile();

    if (hasClass(document.querySelectorAll(".addAlbum"), "input-mode")) {
      show(document.querySelectorAll(".addAlbum p"));
    }
    set_view_selector("tile");
  });

  /* Pagination */

  switch (pagination) {
    case "5":
      addClass(
        document.querySelectorAll("#pagination-per-page-5"),
        "selected-pagination",
      );
      removeClass(
        document.querySelectorAll("#pagination-per-page-10"),
        "selected-pagination",
      );
      removeClass(
        document.querySelectorAll("#pagination-per-page-25"),
        "selected-pagination",
      );
      removeClass(
        document.querySelectorAll("#pagination-per-page-50"),
        "selected-pagination",
      );
      break;
    case "10":
      removeClass(
        document.querySelectorAll("#pagination-per-page-5"),
        "selected-pagination",
      );
      addClass(
        document.querySelectorAll("#pagination-per-page-10"),
        "selected-pagination",
      );
      removeClass(
        document.querySelectorAll("#pagination-per-page-25"),
        "selected-pagination",
      );
      removeClass(
        document.querySelectorAll("#pagination-per-page-50"),
        "selected-pagination",
      );

      break;
    case "25":
      removeClass(
        document.querySelectorAll("#pagination-per-page-5"),
        "selected-pagination",
      );
      removeClass(
        document.querySelectorAll("#pagination-per-page-10"),
        "selected-pagination",
      );
      addClass(
        document.querySelectorAll("#pagination-per-page-25"),
        "selected-pagination",
      );
      removeClass(
        document.querySelectorAll("#pagination-per-page-50"),
        "selected-pagination",
      );

      break;
    case "50":
      removeClass(
        document.querySelectorAll("#pagination-per-page-5"),
        "selected-pagination",
      );
      removeClass(
        document.querySelectorAll("#pagination-per-page-10"),
        "selected-pagination",
      );
      removeClass(
        document.querySelectorAll("#pagination-per-page-25"),
        "selected-pagination",
      );
      addClass(
        document.querySelectorAll("#pagination-per-page-50"),
        "selected-pagination",
      );
      break;
    default:
      break;
  }

  //$("#pagination-per-page-"+pagination).trigger('click');

  if (has_group !== null && has_group !== "") {
    advanced_filter_button_click();
    setVal(document.querySelectorAll("select[name='filter_group']"), has_group);
    void update_user_list();
  }

  on(document.querySelectorAll(".search-cancel"), "click", function () {
    setVal(document.querySelectorAll(".search-input"), "");
    trigger(document.querySelectorAll(".search-input"), "input");
  });

  on(document.querySelectorAll(".search-input"), "input", function () {
    if (val(document.querySelectorAll(".search-input")) === "") {
      hide(document.querySelectorAll(".search-cancel"));
    } else {
      show(document.querySelectorAll(".search-cancel"));
    }
  });
  // Filter by id (registered) and username
  const icon_user = document.querySelectorAll("#icon-usr-list-user");
  const icon_registered = document.querySelectorAll(
    "#icon-usr-list-registered",
  );
  on(document.querySelectorAll("#usr-list-registered"), "click", function () {
    css(icon_user, "display", "none");
    css(icon_registered, "display", "block");
    attr(icon_user, "class", "icon-down");
    switch (filter_by) {
      case "id DESC":
        attr(icon_registered, "class", "icon-down");
        filter_by = "id ASC";
        break;
      case "id ASC":
        attr(icon_registered, "class", "icon-up");
        filter_by = "id DESC";
        break;
      default:
        attr(icon_registered, "class", "icon-up");
        filter_by = "id DESC";
        break;
    }
    void update_user_list();
  });
  on(document.querySelectorAll("#usr-list-user"), "click", function () {
    css(icon_registered, "display", "none");
    css(icon_user, "display", "block");
    attr(icon_registered, "class", "icon-up");
    switch (filter_by) {
      case "username DESC":
        attr(icon_user, "class", "icon-down");
        filter_by = "username ASC";
        break;
      case "username ASC":
        attr(icon_user, "class", "icon-up");
        filter_by = "username DESC";
        break;
      default:
        attr(icon_user, "class", "icon-down");
        filter_by = "username ASC";
        break;
    }
    void update_user_list();
  });

  /* tabsheet pop in user */
  editTabsBind();

  on(
    document.querySelectorAll(".edit-user-slides"),
    "scroll",
    function (this: Element) {
      const slides = Array.from(this.children);
      const rectView = this.getBoundingClientRect();
      removeClass(document.querySelectorAll(".edit-user-tabsheet"), "selected");
      // for debug
      // console.log('RECT REFERENCES left / right', rectView.left.toFixed(0), ' / ', rectView.right.toFixed(0));

      slides.forEach((slide) => {
        const rect = slide.getBoundingClientRect();
        // for debug
        // console.log('rect',$(this).attr('id'), ' left / right : ', rect.left, ' / ', rect.right);
        if (
          Number(rect.left.toFixed(0)) >=
            Number(rectView.left.toFixed(0)) - 1 &&
          Number(rect.right.toFixed(0)) <= Number(rectView.right.toFixed(0)) + 1
        ) {
          const tabId = attrOf(slide, "id") ?? "";
          addClass(document.querySelectorAll("#name_" + tabId), "selected");
        }
      });
    },
  );

  /* tabsheet pop in guest */
  const guestEditTabsheet = document.querySelectorAll(
    ".guest-edit-user-tabsheet",
  );
  off(guestEditTabsheet, "click");
  on(guestEditTabsheet, "click", function (this: Element) {
    const tabName = attrOf(this, "id")!.split("_");
    const tabId = tabName[1]! + "_" + tabName[2]! + "_" + tabName[3]!;
    document.querySelector("#" + tabId)?.scrollIntoView({
      behavior: "smooth",
    });
  });
  on(
    document.querySelectorAll(".guest-edit-user-slides"),
    "scroll",
    function (this: Element) {
      const slides = Array.from(this.children);
      const rectView = this.getBoundingClientRect();
      removeClass(
        document.querySelectorAll(".guest-edit-user-tabsheet"),
        "selected",
      );
      slides.forEach((slide) => {
        const rect = slide.getBoundingClientRect();
        if (
          Number(rect.left.toFixed(0)) >=
            Number(rectView.left.toFixed(0)) - 1 &&
          Number(rect.right.toFixed(0)) <= Number(rectView.right.toFixed(0)) + 1
        ) {
          const tabId = attrOf(slide, "id") ?? "";
          addClass(document.querySelectorAll("#name_" + tabId), "selected");
        }
      });
    },
  );
});

function set_view_selector(view_type: string) {
  void ajax({
    url: "api/v1/session/preferences/user-manager-view",
    type: "PUT",
    dataType: "JSON",
    json: {
      value: view_type,
    },
  });
}

function setDisplayTile() {
  const wrapper = document.querySelectorAll(".user-container-wrapper");
  removeClass(wrapper, "compactView");
  removeClass(wrapper, "lineView");
  addClass(wrapper, "tileView");
  addClass(document.querySelectorAll(".user-header-col"), "hide");
}

function setDisplayLine() {
  const wrapper = document.querySelectorAll(".user-container-wrapper");
  removeClass(wrapper, "tileView");
  removeClass(wrapper, "compactView");
  addClass(wrapper, "lineView");
  removeClass(document.querySelectorAll(".user-header-col"), "hide");
}

function setDisplayCompact() {
  const wrapper = document.querySelectorAll(".user-container-wrapper");
  removeClass(wrapper, "tileView");
  removeClass(wrapper, "lineView");
  addClass(wrapper, "compactView");
  addClass(document.querySelectorAll(".user-header-col"), "hide");

  if (per_page < 10) {
    per_page = 10;
    update_pagination_menu();
    void update_user_list();

    removeClass(
      document.querySelectorAll("#pagination-per-page-5"),
      "selected-pagination",
    );
    addClass(
      document.querySelectorAll("#pagination-per-page-10"),
      "selected-pagination",
    );
    removeClass(
      document.querySelectorAll("#pagination-per-page-25"),
      "selected-pagination",
    );
    removeClass(
      document.querySelectorAll("#pagination-per-page-50"),
      "selected-pagination",
    );
  }
}

/*----------------
Checkboxes
----------------*/

function checkbox_change(this: Element) {
  if (attrOf(this, "data-selected") === "1") {
    hide(find(this, "i"));
  } else {
    show(find(this, "i"));
  }
}

function checkbox_click(this: Element) {
  if (attrOf(this, "data-selected") === "1") {
    attr(this, "data-selected", "0");
    hide(find(this, "i"));
  } else {
    attr(this, "data-selected", "1");
    show(find(this, "i"));
  }
}

off(document.querySelectorAll(".user-list-checkbox"), "change");
on(document.querySelectorAll(".user-list-checkbox"), "change", checkbox_change);
off(document.querySelectorAll(".user-list-checkbox"), "click");
on(document.querySelectorAll(".user-list-checkbox"), "click", checkbox_click);

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
slider(
  document.querySelectorAll(
    "#UserList .photos-select-bar .slider-bar-container",
  ),
  {
    range: "min",
    min: 0,
    max: nb_image_page_values.length - 1,
    value: nb_image_page_init,
    change: function (_event: Event, ui: SliderUIParams) {
      html(
        document.querySelectorAll(
          "#UserList .photos-select-bar .nb-img-page-infos",
        ),
        getNbImagePageInfoFromIdx(ui.value!),
      );
    },
    slide: function (_event: Event, ui: SliderUIParams) {
      html(
        document.querySelectorAll(
          "#UserList .photos-select-bar .nb-img-page-infos",
        ),
        getNbImagePageInfoFromIdx(ui.value!),
      );
    },
    stop: function (_event: Event, ui: SliderUIParams) {
      setVal(
        document.querySelectorAll(
          "#UserList .photos-select-bar input[name=nb_image_page]",
        ),
        String(nb_image_page_values[ui.value!]!),
      );
      trigger(
        document.querySelectorAll(
          "#UserList .photos-select-bar input[name=nb_image_page]",
        ),
        "change",
      );
    },
  },
);

slider(
  document.querySelectorAll(
    "#GuestUserList .photos-select-bar .slider-bar-container",
  ),
  {
    range: "min",
    min: 0,
    max: nb_image_page_values.length - 1,
    value: nb_image_page_init,
    change: function (_event: Event, ui: SliderUIParams) {
      html(
        document.querySelectorAll(
          "#GuestUserList .photos-select-bar .nb-img-page-infos",
        ),
        getNbImagePageInfoFromIdx(ui.value!),
      );
    },
    slide: function (_event: Event, ui: SliderUIParams) {
      html(
        document.querySelectorAll(
          "#GuestUserList .photos-select-bar .nb-img-page-infos",
        ),
        getNbImagePageInfoFromIdx(ui.value!),
      );
    },
    stop: function (_event: Event, ui: SliderUIParams) {
      setVal(
        document.querySelectorAll(
          "#GuestUserList .photos-select-bar input[name=nb_image_page]",
        ),
        String(nb_image_page_values[ui.value!]!),
      );
      trigger(
        document.querySelectorAll(
          "#GuestUserList .photos-select-bar input[name=nb_image_page]",
        ),
        "change",
      );
    },
  },
);

html(
  document.querySelectorAll(
    "#permitActionUserList .photos-select-bar .nb-img-page-infos",
  ),
  getNbImagePageInfoFromIdx(0),
);
slider(
  document.querySelectorAll(
    "#permitActionUserList .photos-select-bar .slider-bar-container",
  ),
  {
    range: "min",
    min: 0,
    max: nb_image_page_values.length - 1,
    value: nb_image_page_init,
    change: function (_event: Event, ui: SliderUIParams) {
      html(
        document.querySelectorAll(
          "#permitActionUserList .photos-select-bar .nb-img-page-infos",
        ),
        getNbImagePageInfoFromIdx(ui.value!),
      );
    },
    slide: function (_event: Event, ui: SliderUIParams) {
      html(
        document.querySelectorAll(
          "#permitActionUserList .photos-select-bar .nb-img-page-infos",
        ),
        getNbImagePageInfoFromIdx(ui.value!),
      );
    },
    stop: function (_event: Event, ui: SliderUIParams) {
      setVal(
        document.querySelectorAll(
          "#permitActionUserList .photos-select-bar input[name=nb_image_page]",
        ),
        String(nb_image_page_values[ui.value!]!),
      );
      trigger(
        document.querySelectorAll(
          "#permitActionUserList .photos-select-bar input[name=nb_image_page]",
        ),
        "change",
      );
    },
  },
);

/* recent_period slider */
slider(
  document.querySelectorAll(
    "#UserList .period-select-bar .slider-bar-container",
  ),
  {
    range: "min",
    min: 0,
    max: recent_period_values.length - 1,
    value: recent_period_init,
    change: function (_event: Event, ui: SliderUIParams) {
      html(
        document.querySelectorAll(
          "#UserList .period-select-bar .recent_period_infos",
        ),
        getRecentPeriodInfoFromIdx(ui.value!),
      );
    },
    slide: function (_event: Event, ui: SliderUIParams) {
      html(
        document.querySelectorAll(
          "#UserList .period-select-bar .recent_period_infos",
        ),
        getRecentPeriodInfoFromIdx(ui.value!),
      );
    },
    stop: function (_event: Event, ui: SliderUIParams) {
      setVal(
        document.querySelectorAll(
          "#UserList .period-select-bar input[name=recent_period]",
        ),
        String(recent_period_values[ui.value!]!),
      );
      trigger(
        document.querySelectorAll(
          "#UserList .period-select-bar input[name=recent_period]",
        ),
        "change",
      );
    },
  },
);

slider(
  document.querySelectorAll(
    "#GuestUserList .period-select-bar .slider-bar-container",
  ),
  {
    range: "min",
    min: 0,
    max: recent_period_values.length - 1,
    value: recent_period_init,
    change: function (_event: Event, ui: SliderUIParams) {
      html(
        document.querySelectorAll(
          "#GuestUserList .period-select-bar .recent_period_infos",
        ),
        getRecentPeriodInfoFromIdx(ui.value!),
      );
    },
    slide: function (_event: Event, ui: SliderUIParams) {
      html(
        document.querySelectorAll(
          "#GuestUserList .period-select-bar .recent_period_infos",
        ),
        getRecentPeriodInfoFromIdx(ui.value!),
      );
    },
    stop: function (_event: Event, ui: SliderUIParams) {
      setVal(
        document.querySelectorAll(
          "#GuestUserList .period-select-bar input[name=recent_period]",
        ),
        String(recent_period_values[ui.value!]!),
      );
      trigger(
        document.querySelectorAll(
          "#GuestUserList .period-select-bar input[name=recent_period]",
        ),
        "change",
      );
    },
  },
);

slider(
  document.querySelectorAll(
    "#permitActionUserList .period-select-bar .slider-bar-container",
  ),
  {
    range: "min",
    min: 0,
    max: recent_period_values.length - 1,
    value: recent_period_init,
    change: function (_event: Event, ui: SliderUIParams) {
      html(
        document.querySelectorAll(
          "#permitActionUserList .period-select-bar .recent_period_infos",
        ),
        getRecentPeriodInfoFromIdx(ui.value!),
      );
    },
    slide: function (_event: Event, ui: SliderUIParams) {
      html(
        document.querySelectorAll(
          "#permitActionUserList .period-select-bar .recent_period_infos",
        ),
        getRecentPeriodInfoFromIdx(ui.value!),
      );
    },
    stop: function (_event: Event, ui: SliderUIParams) {
      setVal(
        document.querySelectorAll(
          "#permitActionUserList .period-select-bar input[name=recent_period]",
        ),
        String(recent_period_values[ui.value!]!),
      );
      trigger(
        document.querySelectorAll(
          "#permitActionUserList .period-select-bar input[name=recent_period]",
        ),
        "change",
      );
    },
  },
);
slider(
  document.querySelectorAll(
    "#permitActionUserList .photos-select-bar .slider-bar-container",
  ),
  "option",
  "value",
  0,
);
const period_info = getRecentPeriodInfoFromIdx(0);
html(
  document.querySelectorAll(
    "#permitActionUserList .period-select-bar .recent_period_infos",
  ),
  period_info,
);

/* -----------
Pagination
------------*/

let actual_page = 1;
let max_page = 1;
let nb_filtered_users = 0;
const page_ellipsis = "<span>...</span>";
const page_item = '<a data-page="%d">%d</a>';

function move_to_page(page: number) {
  if (page < 1 || page > max_page) return;
  actual_page = page;
  update_pagination_menu();
  void update_user_list();
}

on(document.querySelectorAll(".pagination-arrow.rigth"), "click", () => {
  move_to_page(actual_page + 1);
});

on(document.querySelectorAll(".pagination-arrow.left"), "click", () => {
  move_to_page(actual_page - 1);
});

on(
  document.querySelectorAll(".pagination-per-page a"),
  "click",
  function (this: Element) {
    void ajax({
      url: "api/v1/session/preferences/user-manager-pagination",
      type: "PUT",
      json: {
        value: String(parseInt(htmlOf(this) ?? "0")),
        isJson: false,
      },
    });

    per_page = parseInt(htmlOf(this) ?? "0");
    actual_page = 1;
    update_pagination_menu();
    void update_user_list();

    const parent = this.parentElement;
    if (parent !== null) {
      removeClass(find(parent, "a"), "selected-pagination");
    }

    addClass(this, "selected-pagination");
  },
);

function append_pagination_item(page: number | null = null) {
  const container = document.querySelector(".pagination-item-container");
  if (container === null) return;
  if (page != null) {
    const new_tag = parseHtml(page_item.replace(/%d/g, String(page)))[0]!;
    container.appendChild(new_tag);
    if (actual_page === page) {
      addClass(new_tag, "actual");
    }
    on(new_tag, "click", () => {
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- data() reads a data-* attribute this same app's own setData()/template writes, never adversarial input.
      move_to_page(data(new_tag, "page") as number);
    });
  } else {
    container.appendChild(parseHtml(page_ellipsis)[0]!);
  }
}

function update_pagination_items() {
  document.querySelectorAll(".pagination-item-container a").forEach((el) => {
    el.remove();
  });
  document.querySelectorAll(".pagination-item-container span").forEach((el) => {
    el.remove();
  });

  append_pagination_item(1);

  if (actual_page > 2) {
    append_pagination_item();
  }
  if (actual_page !== 1 && actual_page !== max_page) {
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
    hide(document.querySelectorAll(".pagination-container"));
  } else {
    show(document.querySelectorAll(".pagination-container"));
  }
}

function updateArrows() {
  if (actual_page === 1) {
    addClass(
      document.querySelectorAll(".pagination-arrow.left"),
      "unavailable",
    );
  } else {
    removeClass(
      document.querySelectorAll(".pagination-arrow.left"),
      "unavailable",
    );
  }
  if (actual_page === max_page) {
    addClass(
      document.querySelectorAll(".pagination-arrow.rigth"),
      "unavailable",
    );
  } else {
    removeClass(
      document.querySelectorAll(".pagination-arrow.rigth"),
      "unavailable",
    );
  }
}

/*------------------
Advanced filter
------------------*/

function advanced_filter_button_click() {
  if (
    !hasClass(
      document.querySelectorAll(".advanced-filter"),
      "advanced-filter-open",
    )
  ) {
    advanced_filter_show();
  } else {
    advanced_filter_hide();
  }
  // update_user_list();
}

function advanced_filter_show() {
  addClass(
    document.querySelectorAll(".advanced-filter-btn, .advanced-filter"),
    "advanced-filter-open",
  );
}

function advanced_filter_hide() {
  removeClass(
    document.querySelectorAll(".advanced-filter-btn, .advanced-filter"),
    "advanced-filter-open",
  );
}

let months: string[] = [];

function getDateStr(date: string) {
  const date_arr = date.split("-");
  const curr_month = months[parseInt(date_arr[1]!) - 1] ?? "";
  return curr_month + " " + date_arr[0]!;
}

function setupRegisterDates(registerDatesList: string[]) {
  slider(
    document.querySelectorAll(
      ".advanced-filter .dates-select-bar .slider-bar-container",
    ),
    {
      range: true,
      min: 0,
      max: registerDatesList.length - 1,
      values: [0, registerDatesList.length - 1],
      change: function (_event: Event, ui: SliderUIParams) {
        html(
          document.querySelectorAll(".advanced-filter .dates-infos"),
          sprintf(
            dates_infos,
            getDateStr(registerDatesList[ui.values![0]!]!),
            getDateStr(registerDatesList[ui.values![1]!]!),
          ),
        );
      },
      slide: function (_event: Event, ui: SliderUIParams) {
        html(
          document.querySelectorAll(".advanced-filter .dates-infos"),
          sprintf(
            dates_infos,
            getDateStr(registerDatesList[ui.values![0]!]!),
            getDateStr(registerDatesList[ui.values![1]!]!),
          ),
        );
      },
      stop: function (_event: Event, ui: SliderUIParams) {
        html(
          document.querySelectorAll(".advanced-filter .dates-infos"),
          sprintf(
            dates_infos,
            getDateStr(registerDatesList[ui.values![0]!]!),
            getDateStr(registerDatesList[ui.values![1]!]!),
          ),
        );
        void update_user_list();
      },
    },
  );

  html(
    document.querySelectorAll(".advanced-filter .dates-infos"),
    sprintf(
      dates_infos,
      getDateStr(registerDatesList[0]!),
      getDateStr(registerDatesList[registerDatesList.length - 1]!),
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
  fadeOut(document.querySelectorAll("#AddUser"), () => {
    css(
      document.querySelectorAll("#AddUser .AddUserErrors"),
      "visibility",
      "hidden",
    );
  });
}

function add_user_open() {
  hide(document.querySelectorAll("#AddUserSuccessContainer"));
  show(document.querySelectorAll("#AddUserFieldContainer"));
  // `:input` is jQuery/Sizzle's own pseudo-selector (matches input,
  // select, textarea and button elements) -- not real CSS, and
  // querySelectorAll throws on it.
  setVal(
    document.querySelectorAll(
      "#AddUser input, #AddUser select, #AddUser textarea, #AddUser button",
    ),
    "",
  );
  hide(document.querySelectorAll("#add_user_password"));
  fill_new_user();
  fadeIn(document.querySelectorAll("#AddUser"));
  document.querySelector<HTMLElement>(".AddUserLabelUsername input")?.focus();
  const statusSelect = document.querySelectorAll(
    "#AddUser .user-property-status .user-property-select",
  );
  off(statusSelect, "change");
  on(statusSelect, "change", function (this: Element) {
    const status = val(this);
    setVal(document.querySelectorAll("#add_user_pass ,#add_user_confpass"), "");
    if ("generic" === status) {
      show(document.querySelectorAll("#add_user_password"));
      return;
    }
    hide(document.querySelectorAll("#add_user_password"));
  });
}

/*------------------
Selection mode
------------------*/

function create_user_selected_item(user: SelectionEntry): Element {
  const template = document.querySelector(".user-selected-item")!;
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- cloning an Element always produces an Element (real DOM guarantee); cloneNode()'s own lib.dom signature just isn't narrowed per-subtype.
  const new_elem = template.cloneNode(true) as Element;
  attr(new_elem, "data-id", user.id.toString());
  // Non-null: only ever called after the caller's own `typeof ...
  // !== "undefined"` guard confirms `username` is really set.
  html(find(new_elem, "p"), user.username!);
  on(find(new_elem, "a"), "click", () => {
    selection.splice(
      selection.findIndex((i) => i.id === user.id),
      1,
    );
    update_selection_content();
  });
  return new_elem;
}

function generate_user_selected_items() {
  let items_created = 0;
  const others = selection.length - 5;
  document
    .querySelectorAll(".user-selected-list .user-selected-item")
    .forEach((el) => {
      el.remove();
    });
  const list = document.querySelector(".user-selected-list");
  for (let i = 0; i < selection.length && items_created < 5; i++) {
    if (typeof selection[i]!.username !== "undefined") {
      list?.appendChild(create_user_selected_item(selection[i]!));
      items_created += 1;
    }
  }
  if (others >= 1) {
    html(
      document.querySelectorAll(".selection-other-users"),
      str_and_others_tags.replace("%s", String(others)),
    );
    show(document.querySelectorAll(".selection-other-users"));
  } else {
    hide(document.querySelectorAll(".selection-other-users"));
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
  if (elems_with_username < 5 && elems_with_username !== selection.length) {
    void get_first_selection_usernames(generate_user_selected_items);
  } else {
    generate_user_selected_items();
  }
}

function update_selection_content() {
  const number = selection.length;
  fill_user_selected_list();
  if (number === 0) {
    show(document.querySelectorAll("#forbidAction"));
    hide(document.querySelectorAll(".selection-mode-ul"));
    hide(document.querySelectorAll("#permitActionUserList"));
  } else {
    hide(document.querySelectorAll("#forbidAction"));
    show(document.querySelectorAll("#permitActionUserList"));
    show(document.querySelectorAll(".selection-mode-ul"));
  }
  set_selected_to_selection();
  hide(document.querySelectorAll("#applyActionBlock .infos"));
}

function set_selected_to_selection() {
  if (!is(document.querySelectorAll("#toggleSelectionMode"), ":checked")) {
    return;
  }
  document
    .querySelectorAll(".user-container-wrapper .user-container")
    .forEach((container, index) => {
      const selection_ids = selection.map((x) => x.id);
      if (selection_ids.includes(current_users[index]!.id)) {
        addClass(container, "container-selected");
        attr(find(container, ".user-list-checkbox"), "data-selected", "1");
        show(find(container, ".user-list-checkbox i"));
      } else {
        removeClass(container, "container-selected");
        attr(find(container, ".user-list-checkbox"), "data-selected", "0");
        hide(find(container, ".user-list-checkbox i"));
      }
    });
}

function selectionMode(isSelection: boolean) {
  setVal(
    document.querySelectorAll(
      "#permitActionUserList select[name=selectAction]",
    ),
    "-1",
  );
  // This dispatch must reach `dom.ts`'s own `on()`-registered "change"
  // listener at `select[name=selectAction]` (hides `#applyActionBlock`),
  // which uses a real `addEventListener` -- jQuery's own `.trigger()`
  // can't invoke a native method for "change" (unlike "click"/"focus"/
  // "submit"), so it only replays handlers in jQuery's own internal
  // registry, never one registered outside it.
  trigger(
    document.querySelectorAll(
      "#permitActionUserList select[name=selectAction]",
    ),
    "change",
  );
  if (isSelection) {
    //resets the selection
    //selection = [];
    set_selected_to_selection();
    show(document.querySelectorAll(".in-selection-mode"));
    hide(document.querySelectorAll(".not-in-selection-mode"));

    if (view_selector === "tile") {
      show(document.querySelectorAll(".user-container-email"));
    }

    if (view_selector === "compact") {
      css(document.querySelectorAll(".user-container-email"), {
        display: "none",
      });
    }

    addClass(document.querySelectorAll(".user-container"), "selectable");
  } else {
    removeClass(
      document.querySelectorAll(".container-selected"),
      "container-selected",
    );
    hide(document.querySelectorAll(".in-selection-mode"));
    show(document.querySelectorAll(".not-in-selection-mode"));

    if (view_selector === "tile" || view_selector === "line") {
      css(document.querySelectorAll(".user-container-email"), {
        display: "flex",
      });
    }

    removeClass(document.querySelectorAll(".user-container"), "selectable");
  }
}

/*------------------
General functions
------------------*/

function hide_temporary_messages() {
  hide(document.querySelectorAll(".update-user-success"));
  hide(document.querySelectorAll("#AddUserSuccess"));
  hide(document.querySelectorAll(".error-msg"));
}

function get_group_name_from_id(id: number) {
  for (const [groupId, groupName] of groups_arr) {
    if (groupId === id) {
      return groupName;
    }
  }
  return "group_id error";
}

function get_container_index_from_uid(uid: number) {
  for (let i = 0; i < current_users.length; i++) {
    if (current_users[i]!.id === uid) {
      return i;
    }
  }
  return -1;
}

function hide_error_edit_user() {
  animate(
    document.querySelectorAll("#UserList .EditUserErrors"),
    { opacity: 0 },
    100,
  );
}

function show_error_edit_user() {
  animate(
    document.querySelectorAll("#UserList .EditUserErrors"),
    { opacity: 1 },
    300,
  );
}

function reset_input_password() {
  setVal(
    document.querySelectorAll("#edit_user_password, #edit_user_conf_password"),
    "",
  );
}

function editTabsBind() {
  const tabsheets = document.querySelectorAll(".edit-user-tabsheet");
  off(tabsheets, "click");
  on(tabsheets, "click", function (this: Element) {
    const tabName = attrOf(this, "id")!.split("_");
    const tabId = tabName[1]! + "_" + tabName[2]!;

    document.querySelector("#" + tabId)?.scrollIntoView({
      behavior: "smooth",
    });
  });
}

function check_tabs(title_tab_name_id: string) {
  if (plugins_load.length > 2) {
    css(document.querySelectorAll(".edit-user-tab-title"), {
      gap: "0px",
      justifyContent: "space-between",
    });
    const countMoresPlugins = plugins_load.length - 2;
    if (!document.getElementById("mores_plugins_expand")) {
      append(
        document.querySelectorAll(".edit-user-tab-title"),
        '<span class="close mores-plugins" id="mores_plugins_expand"></span>' +
          '<div class="dropdown-mores-plugins" id="dropdown_mores_plugins"></div>',
      );
    }
    html(
      document.querySelectorAll("#mores_plugins_expand"),
      "+" + String(countMoresPlugins),
    );

    const dropdown = document.querySelector("#dropdown_mores_plugins")!;
    const tabsheetTitle = document.querySelector("#" + title_tab_name_id)!;
    css(document.querySelectorAll(".edit-user-tab-title"), "margin-top", "3px");
    css(
      document.querySelectorAll(".edit-user-tab-title p:nth-child(-n+4)"),
      "padding-top",
      "5px",
    );
    css(tabsheetTitle, "max-width", "100%");
    dropdown.appendChild(tabsheetTitle);

    if (1 === countMoresPlugins) {
      css(tabsheetTitle, "border-radius", "10px");
    } else {
      const dropdownChildren = Array.from(dropdown.children);
      css(dropdownChildren, "border-radius", "0");
      if (dropdownChildren[0] !== undefined) {
        css(dropdownChildren[0], "border-radius", "10px 10px 0 0");
      }
      if (dropdownChildren[dropdownChildren.length - 1] !== undefined) {
        css(
          dropdownChildren[dropdownChildren.length - 1]!,
          "border-radius",
          "0 0 10px 10px",
        );
      }
    }

    const moresExpand = document.querySelectorAll("#mores_plugins_expand");
    off(moresExpand, "click");
    on(moresExpand, "click", function (this: Element) {
      // Captured, not read via `this` inside the nested window handler
      // below: that handler's own `this` is `window`, not this icon --
      // same reason the original captured `const icon = $(this)` in its
      // own enclosing scope rather than reading `this` twice.
      // eslint-disable-next-line @typescript-eslint/no-this-alias -- the classic callback-closure idiom: `this` needs to stay reachable inside the nested window "click.hideUsersModal" handler below, which has its own `this`.
      const icon = this;
      if (hasClass(icon, "open")) {
        removeClass(icon, "open");
        addClass(icon, "close");
        fadeOut(dropdown);
      } else {
        removeClass(icon, "close");
        addClass(icon, "open");
        fadeIn(dropdown);
        off(window, "click.hideUsersModal");
        on(window, "click.hideUsersModal", function (e: Event) {
          // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- a real click inside the document always targets an Element.
          const target = e.target as Element;
          if (
            target.closest("#dropdown_mores_plugins") === null &&
            !target.matches("#mores_plugins_expand")
          ) {
            fadeOut(document.querySelectorAll("#dropdown_mores_plugins"));
            removeClass(icon, "open");
            addClass(icon, "close");
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

  if (
    users_table !== null &&
    users_table !== "" &&
    typeof users_table !== "string"
  ) {
    throw new TypeError("users_table must be a string.");
  }
  if (
    users_table !== null &&
    users_table !== "" &&
    (set_data_function || get_data_function)
  ) {
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

  if (users_table !== null && users_table !== "") {
    plugins_users_infos_table.push({ content_id, users_table });
  }

  // DOM modification
  const content = document.getElementById(content_id)!;

  append(
    document.querySelectorAll(".edit-user-tab-title"),
    '<p class="edit-user-tabsheet" id="name_tab_' +
      name +
      '" title="' +
      tab_name +
      '">' +
      tab_name +
      "</p>",
  );

  append(
    document.querySelectorAll(".edit-user-slides"),
    '<div class="edit-user-tabsheet-plugins ' +
      name +
      '-container" id="tab_' +
      name +
      '"></div>',
  );

  document.getElementById("tab_" + name)?.appendChild(content);
  show(content);

  editTabsBind();

  addClass(document.querySelectorAll(".edit-user-tabsheet"), "tab-with-plugin");

  plugins_load.push("name_tab_" + name);
  check_tabs("name_tab_" + name);

  if (plugins_load.length <= 2) {
    const nameTab = document.getElementById("name_tab_" + name);
    if (nameTab !== null) {
      tipTip(nameTab, {
        delay: 0,
        fadeIn: 200,
        fadeOut: 200,
        edgeOffset: 3,
      });
    }
  }
}

function reset_password_modals() {
  hide(document.querySelectorAll(".user-property-password-change"));
  hide(document.querySelectorAll(".user-property-password-change-inputs"));
  hide(document.querySelectorAll("#edit_password_success_change"));
  hide(document.querySelectorAll("#edit_password_result_mail"));
  hide(document.querySelectorAll("#edit_password_result_mail_copy"));
  show(document.querySelectorAll(".user-property-password-choice"));
}

function reset_username_modals() {
  hide(document.querySelectorAll(".edit-username-success"));
  show(document.querySelectorAll(".user-property-username-change-input"));
}

function reset_main_user_modals() {
  setVal(document.querySelectorAll("#main_user_rewrite"), "");
  removeClass(
    document.querySelectorAll("#main_user_rewrite_icon"),
    "icon-ok icon-green icon-cancel icon-red",
  );
  hide(document.querySelectorAll(".main-user-rewrite"));
  hide(document.querySelectorAll(".main-user-validate"));
  hide(document.querySelectorAll(".main-user-success"));
  show(document.querySelectorAll(".main-user-proceed"));
}

function hide_modals() {
  hide(document.querySelectorAll(".user-property-username-change"));
  hide(document.querySelectorAll(".user-property-password-change"));
  hide(document.querySelectorAll(".user-property-main-user-change"));
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
  const modal = document.querySelectorAll(".user-property-main-user-change");
  reset_main_user_modals();
  html(
    document.querySelectorAll(".main-user-proceed-desc"),
    sprintf(
      mainUserContinue,
      `<b>${display_long_string(user_to_edit.username)}</b>`,
      `<b>${display_long_string(owner_username)}</b>`,
    ),
  );
  fadeIn(modal);

  // set event
  const proceedBtn = document.querySelectorAll(".main-user-btn-proceed");
  off(proceedBtn, "click");
  on(proceedBtn, "click", function () {
    const gen_string = generate_random_string();
    html(
      document.querySelectorAll(".main-user-rewrite-desc"),
      sprintf(mainUserRewrite, `<b>${gen_string}</b>`) + " :",
    );
    hide(document.querySelectorAll(".main-user-proceed"));
    fadeIn(document.querySelectorAll(".main-user-rewrite"));
    event_check_string_main_user(
      user_to_edit.username,
      user_to_edit.id,
      gen_string,
    );
  });

  const cancelBtn = document.querySelectorAll(
    ".user-property-main-user-cancel, #main_user_success_close",
  );
  off(cancelBtn, "click");
  on(cancelBtn, "click", function () {
    fadeOut(modal);
  });
}

function set_main_user_success() {
  const indexKey = current_users.findIndex((u) => u.id === owner_id);
  const [new_main] = find(
    document.querySelectorAll(
      '.user-container[key="' + String(indexKey) + '"]',
    ),
    ".user-container-username",
  );
  let king = document.querySelector("#the_king");
  if (king === null) {
    king = parseHtml(king_template)[0]!;
    attr(king, "title", mainUserStr);
    tipTip(king);
  }
  new_main?.appendChild(king);
  hide(document.querySelectorAll(".delete-user-button"));
  hide(document.querySelectorAll(".main-user-validate"));
  fadeIn(document.querySelectorAll(".main-user-success"));
}

function event_check_string_main_user(
  new_main_username: string,
  new_main_id: number,
  stringToCheck: string,
) {
  const icon = document.querySelectorAll("#main_user_rewrite_icon");
  const rewriteInput = document.querySelectorAll("#main_user_rewrite");
  off(rewriteInput, "keyup");
  on(rewriteInput, "keyup", function (this: Element) {
    removeClass(icon, "icon-ok icon-green");
    addClass(icon, "icon-cancel icon-red");
    if (stringToCheck === val(this)) {
      removeClass(icon, "icon-cancel icon-red");
      addClass(icon, "icon-ok icon-green");
      html(
        document.querySelectorAll(".main-user-validate-desc"),
        sprintf(
          mainUserValidate,
          `<b>${display_long_string(owner_username)}</b>`,
          `<b>${display_long_string(new_main_username)}</b>`,
        ),
      );
      html(
        document.querySelectorAll(".main-user-success-desc"),
        sprintf(mainUserSuccess, display_long_string(new_main_username)),
      );
      hide(document.querySelectorAll(".main-user-rewrite"));
      fadeIn(document.querySelectorAll(".main-user-validate"));
      event_validate_main_user(new_main_username, new_main_id);
    }
  });
}

function event_validate_main_user(new_main_username: string, user_id: number) {
  const validateBtn = document.querySelectorAll(".main-user-btn-validate");
  off(validateBtn, "click");
  on(validateBtn, "click", function () {
    void set_main_user(user_id, new_main_username);
  });
}

/*-----------------------
Generate User Containers
-----------------------*/
function user_container_click(this: Element) {
  if (!isSelectionMode()) {
    return;
  }
  const container_checkbox = find(this, ".user-list-checkbox")[0]!;
  // Non-null: `key` is always a real, in-bounds index into current_users
  // for a real .UsernameBlock container.
  const curr_user = current_users[parseInt(attrOf(this, "key")!)]!;
  if (attrOf(container_checkbox, "data-selected") === "1") {
    attr(container_checkbox, "data-selected", "0");
    hide(find(container_checkbox, "i"));
    removeClass(this, "container-selected");
    selection = selection.filter((elem) => elem.id !== curr_user.id);
  } else {
    attr(container_checkbox, "data-selected", "1");
    show(find(container_checkbox, "i"));
    addClass(this, "container-selected");
    selection.push({ id: curr_user.id, username: curr_user.username });
  }
  update_selection_content();
}

function generate_groups(container: Element, groups: number[]) {
  const groupsContainer = find(container, ".user-container-groups")[0]!;
  html(groupsContainer, "");
  if (groups.length >= 1) {
    const template = document.querySelector("#template .group-primary")!;
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- cloning an Element always produces an Element (real DOM guarantee); cloneNode()'s own lib.dom signature just isn't narrowed per-subtype.
    const primary_grp = template.cloneNode(true) as Element;
    html(primary_grp, get_group_name_from_id(groups[0]!));
    addClass(primary_grp, color_icons[groups[0]! % 5]!);
    groupsContainer.appendChild(primary_grp);
  }
  if (groups.length >= 2) {
    const template = document.querySelector("#template .group-primary")!;
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- cloning an Element always produces an Element (real DOM guarantee); cloneNode()'s own lib.dom signature just isn't narrowed per-subtype.
    const primary_grp = template.cloneNode(true) as Element;
    html(primary_grp, get_group_name_from_id(groups[1]!));
    addClass(primary_grp, color_icons[groups[1]! % 5]!);
    groupsContainer.appendChild(primary_grp);
  }
  if (groups.length >= 3) {
    const template = document.querySelector("#template .group-bonus")!;
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- cloning an Element always produces an Element (real DOM guarantee); cloneNode()'s own lib.dom signature just isn't narrowed per-subtype.
    const bonus_grp = template.cloneNode(true) as Element;
    html(bonus_grp, "...");
    addClass(bonus_grp, color_icons[groups[2]! % 5]!);
    addClass(bonus_grp, "tiptip");
    let groups_in_title = "";
    for (let i = 2; i < groups.length; i++) {
      groups_in_title += get_group_name_from_id(groups[i]!) + ", ";
    }
    groups_in_title = groups_in_title.substring(0, groups_in_title.length - 2);
    attr(bonus_grp, "title", groups_in_title);
    groupsContainer.appendChild(bonus_grp);
  }
}

function get_initials(username: string): string {
  const words = username.toUpperCase().split(" ");
  let res = words[0]![0]!;

  const [, secondWord] = words;
  if (secondWord !== undefined && secondWord.length > 0) {
    res += secondWord[0]!;
  }
  return res;
}

function fill_container_user_info(container: Element, user_index: number) {
  const user = current_users[user_index]!;
  const registration_dates = (user.registrationDate ?? "").split(" ");
  attr(container, "key", String(user_index));
  html(find(container, ".user-container-username span"), user.username);
  if (user.id === owner_id && document.getElementById("the_king") === null) {
    const kingToDisplay = parseHtml(king_template)[0]!;
    attr(kingToDisplay, "title", mainUserStr);
    tipTip(kingToDisplay);
    find(container, ".user-container-username")[0]?.appendChild(kingToDisplay);
  }
  const initial_to_fill = get_initials(user.username);
  const initialSpan = find(container, ".user-container-initials span")[0]!;
  html(initialSpan, initial_to_fill);
  addClass(initialSpan, color_icons[user.id % 5]!);
  if (initial_to_fill.length > 1) {
    addClass(initialSpan, "small");
  } else {
    removeClass(initialSpan, "small");
  }
  html(
    find(container, ".user-container-status span"),
    // Non-null: every real, listed user has a real status; `null` is
    // only a schema allowance for an edge case this admin listing
    // doesn't actually surface.
    status_to_str[user.status!]!,
  );
  html(find(container, ".user-container-email span"), user.email ?? "");
  generate_groups(container, user.groups);
  html(
    find(container, ".user-container-registration-date"),
    registration_dates[0] ?? "",
  );
  html(
    find(container, ".user-container-registration-time"),
    registration_dates[1] ?? "",
  );
  html(
    find(container, ".user-container-registration-date-since"),
    user.registrationDateSince,
  );
}

function generate_user_list() {
  document
    .querySelectorAll("#user-table-content .user-container")
    .forEach((el) => {
      el.remove();
    });
  const wrapper = document.querySelector(
    "#user-table-content .user-container-wrapper",
  )!;
  const template = document.querySelector("#template .user-container")!;
  for (let i = 0; i < current_users.length; i++) {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- cloning an Element always produces an Element (real DOM guarantee); cloneNode()'s own lib.dom signature just isn't narrowed per-subtype.
    const new_container = template.cloneNode(true) as Element;
    fill_container_user_info(new_container, i);
    wrapper.appendChild(new_container);
  }
  const checkboxes = document.querySelectorAll(
    ".user-container .user-list-checkbox",
  );
  off(checkboxes, "change");
  on(checkboxes, "change", checkbox_change);
  off(checkboxes, "click");
  on(
    document.querySelectorAll(".user-container"),
    "click",
    user_container_click,
  );
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
    const levelStr = level_arr[i]!;
    if (levelStr === String(level)) {
      return i;
    }
  }
  return 0;
}

function set_selected_groups(groups: number[]) {
  for (const groupOption of groupOptions) {
    groupOption.isSelected = groups.includes(groupOption.value);
  }
}

function fill_user_edit_summary(
  user_to_edit: UserRow,
  pop_in: Element,
  isGuest: boolean,
) {
  // console.log(isGuest);
  if (isGuest) {
    const initialSpan = find(pop_in, ".user-property-initials span");
    removeClass(initialSpan, color_icons.join(" "));
    addClass(initialSpan, color_icons[user_to_edit.id % 5]!);
  } else {
    const initial_to_fill = get_initials(user_to_edit.username);
    const initialSpan = find(pop_in, ".user-property-initials span");
    html(initialSpan, initial_to_fill);
    removeClass(initialSpan, color_icons.join(" "));
    addClass(initialSpan, color_icons[user_to_edit.id % 5]!);
    if (initial_to_fill.length > 1) {
      addClass(initialSpan, "small");
    } else {
      removeClass(initialSpan, "small");
    }
  }
  const usernameSpan = find(pop_in, ".user-property-username span:first-child");
  html(usernameSpan, user_to_edit.username);
  tipTip(usernameSpan, { content: user_to_edit.username });

  if (user_to_edit.id === connected_user || user_to_edit.id === 1) {
    show(find(pop_in, ".user-property-username .edit-username-specifier"));
  } else {
    hide(find(pop_in, ".user-property-username .edit-username-specifier"));
  }
  setVal(
    find(pop_in, ".user-property-username-change input"),
    user_to_edit.username,
  );
  setVal(find(pop_in, ".user-property-password-change input"), "");
  attr(
    find(pop_in, ".user-property-permissions a"),
    "href",
    `admin.php?page=user_perm&user_id=${user_to_edit.id}`,
  );
  html(
    find(pop_in, ".user-property-register"),
    user_to_edit.registrationDateString,
  );
  tipTip(find(pop_in, ".user-property-register"), {
    content: `${registered_str}<br />${user_to_edit.registrationDateSince}`,
  });
  html(find(pop_in, ".user-property-last-visit"), user_to_edit.lastVisitString);
  tipTip(find(pop_in, ".user-property-last-visit"), {
    content: `${last_visit_str}<br />${user_to_edit.lastVisitSince}`,
  });
  attr(
    find(pop_in, ".user-property-history a"),
    "href",
    history_base_url + String(user_to_edit.id),
  );

  // Hide the copy password button and change modal copy password
  // if you are not using https
  if (!window.isSecureContext) {
    hide(document.querySelectorAll("#copy_password"));
    css(
      document.querySelectorAll(".update-password-success"),
      "margin",
      "40px 0",
    );
    hide(document.querySelectorAll("#result_send_mail_copy"));
    const copyBtns = document.querySelectorAll(
      "#result_send_mail_copy_btn, #AddUserCopyPassword",
    );
    css(copyBtns, {
      cursor: "not-allowed",
      "background-color": "grey",
      color: "#ffffff",
    });
    attr(copyBtns, "title", cantCopy);
    on(copyBtns, "mouseenter", function (this: Element) {
      css(this, "background-color", "grey");
    });
    tipTip(copyBtns);
  }
}

function fill_user_edit_properties(user_to_edit: UserRow, pop_in: Element) {
  const status_index = get_status_index(user_to_edit.status);
  const level_index = get_level_index(user_to_edit.level);
  const current_group_selectize =
    user_to_edit.id === guest_id ? groupGuestSelectize : groupSelectize;

  setVal(find(pop_in, ".user-property-email input"), user_to_edit.email ?? "");
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- verified by the selector: only <option> elements can match "select option".
  const statusOption = find(pop_in, ".user-property-status select option")[
    status_index
  ] as HTMLOptionElement | undefined;
  if (statusOption !== undefined) statusOption.selected = true;
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- verified by the selector: only <option> elements can match "select option".
  const levelOption = find(pop_in, ".user-property-level select option")[
    level_index
  ] as HTMLOptionElement | undefined;
  if (levelOption !== undefined) levelOption.selected = true;
  setVal(
    find(pop_in, ".photos-select-bar input"),
    String(user_to_edit.recentPeriod ?? ""),
  );
  set_selected_groups(user_to_edit.groups);
  current_group_selectize.clear();
  current_group_selectize.load(function (
    callback: (data: GroupOption[]) => void,
  ) {
    callback(groupOptions);
  });
  groupOptions
    .filter((group) => group.isSelected)
    .forEach((group) => {
      current_group_selectize.addItem(group.value);
    });
  attr(
    find(pop_in, '.user-list-checkbox[name="hd_enabled"]'),
    "data-selected",
    user_to_edit.enabledHigh ? "1" : "0",
  );
}

function fill_user_edit_preferences(user_to_edit: UserRow, pop_in: Element) {
  const slider_key_photos = getSliderKeyFromValue(
    parseInt(String(user_to_edit.nbImagePage)),
    nb_image_page_values,
  );
  const slider_key_period = getSliderKeyFromValue(
    parseInt(String(user_to_edit.recentPeriod)),
    recent_period_values,
  );

  slider(
    find(pop_in, ".photos-select-bar .slider-bar-container"),
    "option",
    "value",
    slider_key_photos,
  );
  find(pop_in, ".user-property-theme select option").forEach((option) => {
    if (val(option) === user_to_edit.theme) {
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- verified by the selector: only <option> elements can match "select option".
      (option as HTMLOptionElement).selected = true;
    }
  });
  find(pop_in, ".user-property-lang select option").forEach((option) => {
    if (val(option) === user_to_edit.language) {
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- verified by the selector: only <option> elements can match "select option".
      (option as HTMLOptionElement).selected = true;
    }
  });
  slider(
    find(pop_in, ".period-select-bar .slider-bar-container"),
    "option",
    "value",
    slider_key_period,
  );
  attr(
    find(pop_in, '.user-list-checkbox[name="expand_all_albums"]'),
    "data-selected",
    user_to_edit.expand ? "1" : "0",
  );
  attr(
    find(pop_in, '.user-list-checkbox[name="show_nb_comments"]'),
    "data-selected",
    user_to_edit.showNbComments ? "1" : "0",
  );
  attr(
    find(pop_in, '.user-list-checkbox[name="show_nb_hits"]'),
    "data-selected",
    user_to_edit.showNbHits ? "1" : "0",
  );
}

function fill_user_edit_update(user_to_edit: UserRow, pop_in: Element) {
  const updateBtn = find(pop_in, ".update-user-button");
  off(updateBtn, "click");
  on(
    updateBtn,
    "click",
    () =>
      void (user_to_edit.id === guest_id
        ? update_guest_info()
        : update_user_info()),
  );
  const usernameValidate = find(pop_in, ".edit-username-validate");
  off(usernameValidate, "click");
  on(usernameValidate, "click", () => void update_user_username());
  const editModalPassword = find(pop_in, "#edit_modal_password");
  off(editModalPassword, "click");
  on(editModalPassword, "click", function () {
    hide(document.querySelectorAll(".user-property-password-choice"));
    fadeIn(document.querySelectorAll(".user-property-password-change-inputs"));
  });
  if (user_to_edit.email !== null && user_to_edit.email !== "") {
    const sendPasswordLink = find(pop_in, "#send_password_link");
    removeClass(sendPasswordLink, "unavailable tiptip");
    attr(sendPasswordLink, "title", "");
    off(sendPasswordLink, "click");
    on(sendPasswordLink, "click", function () {
      void send_link_password(
        user_to_edit.email,
        user_to_edit.username,
        user_to_edit.id,
        true,
      );
    });
    off(sendPasswordLink, "mouseenter mouseleave focus blur");
  } else {
    const sendPasswordLink = find(pop_in, "#send_password_link");
    addClass(sendPasswordLink, "unavailable tiptip");
    off(sendPasswordLink, "click");
    attr(sendPasswordLink, "title", cannotSendMail);
    tipTip(sendPasswordLink, {
      delay: 0,
      fadeIn: 200,
      fadeOut: 200,
      edgeOffset: 3,
    });
  }
  const copyPasswordLink = find(pop_in, "#copy_password_link");
  off(copyPasswordLink, "click");
  on(copyPasswordLink, "click", function () {
    const inputValue = val(
      document.querySelectorAll("#result_send_mail_copy_input"),
    );
    if (inputValue === "") {
      void send_link_password(
        user_to_edit.email,
        user_to_edit.username,
        user_to_edit.id,
        false,
      );
    } else {
      // eslint-disable-next-line @typescript-eslint/no-unnecessary-condition, @typescript-eslint/strict-boolean-expressions -- real feature-detection guard: lib.dom types navigator.clipboard as always-present, but it's genuinely absent on a non-secure origin or an older browser.
      if (window.isSecureContext && navigator.clipboard) {
        copyToClipboard(String(inputValue));
      }
    }
    hide(document.querySelectorAll(".user-property-password-choice"));
    fadeIn(document.querySelectorAll("#edit_password_result_mail_copy"));
    const closeBtn = document.querySelectorAll(
      "#close_password_mail_send_close",
    );
    off(closeBtn, "click");
    on(closeBtn, "click", function () {
      reset_password_modals();
    });
    const copyResultBtn = document.querySelectorAll(
      "#result_send_mail_copy_btn",
    );
    off(copyResultBtn, "click");
    on(copyResultBtn, "click", function () {
      // eslint-disable-next-line @typescript-eslint/no-unnecessary-condition, @typescript-eslint/strict-boolean-expressions -- real feature-detection guard: lib.dom types navigator.clipboard as always-present, but it's genuinely absent on a non-secure origin or an older browser.
      if (window.isSecureContext && navigator.clipboard) {
        const success = document.querySelectorAll("#result_send_mail_copy");
        fadeOut(success, 100);
        copyToClipboard(
          String(
            val(document.querySelectorAll("#result_send_mail_copy_input")),
          ),
        );
        fadeIn(success, 100);
      }
    });
    document
      .querySelector<HTMLElement>("#result_send_mail_copy_input")
      ?.focus();
  });
  const passwordValidate = find(pop_in, ".edit-password-validate");
  off(passwordValidate, "click");
  on(passwordValidate, "click", function () {
    const errDiv = document.querySelectorAll("#UserList .EditUserErrors");
    const inputPassword = val(document.querySelectorAll("#edit_user_password"));
    const inputConfirmPassword = val(
      document.querySelectorAll("#edit_user_conf_password"),
    );

    if (inputPassword === "" || inputConfirmPassword === "") {
      html(errDiv, missingField);
      show_error_edit_user();
    } else if (inputPassword !== inputConfirmPassword) {
      html(errDiv, noMatchPassword);
      show_error_edit_user();
    } else {
      void update_user_password();
    }
  });
  const deleteBtn = find(pop_in, ".delete-user-button");
  off(deleteBtn, "click");
  on(deleteBtn, "click", function () {
    confirm({
      title: title_msg.replace("%s", user_to_edit.username),
      content: "",
      buttons: {
        confirm: {
          text: confirm_msg,
          btnClass: "btn-red",
          action: function () {
            void delete_user(user_to_edit.id);
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

function fill_user_edit_permissions(user_to_edit: UserRow, pop_in: Element) {
  if (user_to_edit.id !== connected_user) {
    // I'm not the connected user
    if (!is_owner(connected_user)) {
      // I'm not the owner, you need to test my permissions
      if (is_owner(user_to_edit.id)) {
        // I want to edit the owner but I'm not the owner (No matter my status)
        hide(find(pop_in, ".delete-user-button"));
        hide(find(pop_in, ".user-property-password.edit-password"));
        attr(
          find(pop_in, ".user-property-email .user-property-input"),
          "disabled",
          "disabled",
        );
        addClass(
          find(pop_in, ".user-property-status .user-property-select"),
          "notClickable",
        );
        hide(find(pop_in, ".user-property-username .edit-username"));
      } else {
        show(find(pop_in, ".delete-user-button"));
        show(find(pop_in, ".user-property-password.edit-password"));
        removeAttr(
          find(pop_in, ".user-property-email .user-property-input"),
          "disabled",
        );
        removeClass(
          find(pop_in, ".user-property-status .user-property-select"),
          "notClickable",
        );
        show(find(pop_in, ".user-property-username .edit-username"));
      }

      if (
        user_to_edit.status === connected_user_status &&
        connected_user_status === "webmaster" &&
        !is_owner(user_to_edit.id)
      ) {
        // I have the same status than the user I want to edit and I'm a webmaster, I can do whatever I want
        show(find(pop_in, ".delete-user-button"));
        show(find(pop_in, ".user-property-password.edit-password"));
        removeAttr(
          find(pop_in, ".user-property-email .user-property-input"),
          "disabled",
        );
        removeClass(
          find(pop_in, ".user-property-status .user-property-select"),
          "notClickable",
        );
        show(find(pop_in, ".user-property-username .edit-username"));
      } else if (
        user_to_edit.status === connected_user_status &&
        connected_user_status === "admin"
      ) {
        // I have the same status than the user I want to edit and I'm an admin, I can do whatever I want but edit the status
        hide(find(pop_in, ".delete-user-button"));
        show(find(pop_in, ".user-property-password.edit-password"));
        removeAttr(
          find(pop_in, ".user-property-email .user-property-input"),
          "disabled",
        );
        removeClass(
          find(pop_in, ".user-property-username .edit-username"),
          "notClickable",
        );
        addClass(
          find(pop_in, ".user-property-status .user-property-select"),
          "notClickable",
        );
      } else if (
        user_to_edit.status === "webmaster" &&
        connected_user_status === "admin"
      ) {
        // I'm admin and I want to edit webmaster
        hide(find(pop_in, ".delete-user-button"));
        hide(find(pop_in, ".user-property-password.edit-password"));
        attr(
          find(pop_in, ".user-property-email .user-property-input"),
          "disabled",
          "disabled",
        );
        addClass(
          find(pop_in, ".user-property-status .user-property-select"),
          "notClickable",
        );
        hide(find(pop_in, ".user-property-username .edit-username"));
      } else if (
        user_to_edit.status === "admin" &&
        connected_user_status === "webmaster"
      ) {
        // I'm webmaster and I want to edit admin
        show(find(pop_in, ".delete-user-button"));
        show(find(pop_in, ".user-property-password.edit-password"));
        removeAttr(
          find(pop_in, ".user-property-email .user-property-input"),
          "disabled",
        );
        removeClass(
          find(pop_in, ".user-property-status .user-property-select"),
          "notClickable",
        );
        show(find(pop_in, ".user-property-username .edit-username"));
      }
    } else {
      // I'm the owner, I can do whatever I want. No need to test, I am GOD here
      show(find(pop_in, ".delete-user-button"));
      show(find(pop_in, ".user-property-password.edit-password"));
      removeAttr(
        find(pop_in, ".user-property-email .user-property-input"),
        "disabled",
      );
      removeClass(
        find(pop_in, ".user-property-status .user-property-select"),
        "notClickable",
      );
      show(find(pop_in, ".user-property-username .edit-username"));
    }
  } else {
    // I'm the connected user, I can do whatever I want on my profile but kill myself (Suicide is not allowed) and edit my status
    hide(find(pop_in, ".delete-user-button"));
    show(find(pop_in, ".user-property-password.edit-password"));
    removeAttr(
      find(pop_in, ".user-property-email .user-property-input"),
      "disabled",
    );
    addClass(
      find(pop_in, ".user-property-status .user-property-select"),
      "notClickable",
    );
    show(find(pop_in, ".user-property-username .edit-username"));
  }

  removeClass(
    document.querySelectorAll(".notClickableBefore"),
    "notClickableBefore",
  );
  document.querySelectorAll(".notClickable").forEach((el) => {
    if (el.parentElement !== null) {
      addClass(el.parentElement, "notClickableBefore");
    }
  });
}

function is_owner(user_id: number) {
  return user_id === owner_id;
}

function fill_user_edit(user_to_edit: UserRow) {
  const pop_in = document.querySelector("#UserList")!;
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
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- plugin_add_tab_in_user_modal's own extension point needs dynamic-by-name field access into a real UserRow; the string keys it's called with come from each plugin's own registration, not user input.
  const userToEditRecord = user_to_edit as unknown as Record<string, unknown>;
  plugins_users_infos_table.forEach((i) => {
    setVal(document.querySelectorAll("#" + i.content_id), "");
    if (keyUserToEdit.includes(i.users_table)) {
      setVal(
        document.querySelectorAll("#" + i.content_id),
        String(userToEditRecord[i.users_table]),
      );
    } else {
      console.error(i.users_table, " doesn't exist in USER_INFOS_TABLE");
    }
  });
}

function fill_guest_edit() {
  const user_to_edit = guest_user;
  const pop_in = document.querySelector(".GuestUserListPopInContainer")!;
  fill_user_edit_summary(user_to_edit, pop_in, true);
  fill_user_edit_properties(user_to_edit, pop_in);
  fill_user_edit_preferences(user_to_edit, pop_in);
  fill_user_edit_update(user_to_edit, pop_in);
}

function fill_new_user() {
  // When you want to add an user: privacy level and groups
  // by default are the same as Guest, not status
  const addUserPopIn = document.querySelector("#AddUser")!;
  const status_index = get_status_index("normal");
  const level_index = get_level_index(guest_user.level);
  set_selected_groups(guest_user.groups);
  groupAddUserSelectize.clear();
  groupAddUserSelectize.load(function (
    callback: (data: GroupOption[]) => void,
  ) {
    callback(groupOptions);
  });
  groupOptions
    .filter((group) => group.isSelected)
    .forEach((group) => {
      groupAddUserSelectize.addItem(group.value);
    });
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- verified by the selector: only <option> elements can match "select option".
  const statusOption = find(
    addUserPopIn,
    ".user-property-status select option",
  )[status_index] as HTMLOptionElement | undefined;
  if (statusOption !== undefined) statusOption.selected = true;
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- verified by the selector: only <option> elements can match "select option".
  const levelOption = find(addUserPopIn, ".user-property-level select option")[
    level_index
  ] as HTMLOptionElement | undefined;
  if (levelOption !== undefined) levelOption.selected = true;
  attr(
    find(addUserPopIn, '.user-list-checkbox[name="hd_enabled"]'),
    "data-selected",
    guest_user.enabledHigh ? "1" : "0",
  );
}

function fill_who_is_the_king(user_to_edit: UserRow, pop_in: Element) {
  const who_is_the_king = find(pop_in, "#who_is_the_king")[0]!;
  // By default I'm an admin and I only see who is the Main User
  removeClass(
    who_is_the_king,
    "princes-of-this-piwigo king-of-this-piwigo can-change",
  );
  addClass(who_is_the_king, "royal-court-of-this-piwigo cannot-change");
  attr(who_is_the_king, "title", mainAskWebmaster);
  tipTip(who_is_the_king);
  off(who_is_the_king, "click");
  // I'm an webmaster
  if (connected_user_status === "webmaster") {
    // check user to edit status
    switch (user_to_edit.status) {
      // user to edit is an webmaster
      case "webmaster":
        removeClass(
          who_is_the_king,
          "royal-court-of-this-piwigo king-of-this-piwigo cannot-change",
        );
        addClass(who_is_the_king, "princes-of-this-piwigo can-change");
        attr(who_is_the_king, "title", mainUserSet);
        tipTip(who_is_the_king);
        if (!is_owner(user_to_edit.id)) {
          off(who_is_the_king, "click");
          on(who_is_the_king, "click", function () {
            open_main_user_modal(user_to_edit);
          });
        }
        break;
      // if user to edit is not an webmaster he cannot be set as a main user
      default:
        removeClass(
          who_is_the_king,
          "princes-of-this-piwigo king-of-this-piwigo",
        );
        addClass(who_is_the_king, "royal-court-of-this-piwigo");
        attr(who_is_the_king, "title", mainUserUpgradeWebmaster);
        tipTip(who_is_the_king);
        break;
    }
  }

  // Main User also the King
  if (is_owner(user_to_edit.id)) {
    removeClass(
      who_is_the_king,
      "princes-of-this-piwigo royal-court-of-this-piwigo can-change",
    );
    addClass(who_is_the_king, "king-of-this-piwigo cannot-change");
    attr(who_is_the_king, "title", mainUserStr);
    tipTip(who_is_the_king);
  }
}

/*-------------------
Fill data for setInfo
-------------------*/

function fill_ajax_data_from_properties(
  ajax_data: Record<string, unknown>,
  pop_in: Element,
) {
  const groups_selected = find(
    pop_in,
    ".user-property-group .selectize-input .item",
  ).map((el) => parseInt(attrOf(el, "data-value")!));
  // console.log(groups_selected);
  ajax_data["email"] = val(find(pop_in, ".user-property-email input"));
  if (
    connected_user_status === "admin" &&
    val(find(pop_in, ".user-property-status select")) !== "webmaster" &&
    val(find(pop_in, ".user-property-status select")) !== "admin"
  ) {
    ajax_data["status"] = val(find(pop_in, ".user-property-status select"));
  } else if (connected_user_status === "webmaster") {
    ajax_data["status"] = val(find(pop_in, ".user-property-status select"));
  }
  // console.log(ajax_data['status']);
  ajax_data["level"] = Number(val(find(pop_in, ".user-property-level select")));
  ajax_data["groupIds"] = groups_selected.length === 0 ? [-1] : groups_selected;
  ajax_data["enabledHigh"] =
    attrOf(
      find(pop_in, '.user-list-checkbox[name="hd_enabled"]'),
      "data-selected",
    ) === "1"
      ? true
      : false;
  return ajax_data;
}

function fill_ajax_data_from_preferences(
  ajax_data: Record<string, unknown>,
  pop_in: Element,
) {
  ajax_data["theme"] = val(find(pop_in, ".user-property-theme select"));
  ajax_data["language"] = val(find(pop_in, ".user-property-lang select"));
  ajax_data["nbImagePage"] =
    nb_image_page_values[
      slider(
        find(pop_in, ".photos-select-bar .slider-bar-container"),
        "option",
        "value",
      )!
    ];
  ajax_data["recentPeriod"] =
    recent_period_values[
      slider(
        find(pop_in, ".period-select-bar .slider-bar-container"),
        "option",
        "value",
      )!
    ];
  ajax_data["expand"] =
    attrOf(
      find(pop_in, '.user-list-checkbox[name="expand_all_albums"]'),
      "data-selected",
    ) === "1"
      ? true
      : false;
  ajax_data["showNbComments"] =
    attrOf(
      find(pop_in, '.user-list-checkbox[name="show_nb_comments"]'),
      "data-selected",
    ) === "1"
      ? true
      : false;
  ajax_data["showNbHits"] =
    attrOf(
      find(pop_in, '.user-list-checkbox[name="show_nb_hits"]'),
      "data-selected",
    ) === "1"
      ? true
      : false;
  return ajax_data;
}

function fill_ajax_data_from_container(
  ajax_data: Record<string, unknown>,
  pop_in: Element,
) {
  const withProperties = fill_ajax_data_from_properties(ajax_data, pop_in);
  return fill_ajax_data_from_preferences(withProperties, pop_in);
}

/*----------------
Ajax Requests
----------------*/

async function get_first_selection_usernames(
  callback: () => void,
): Promise<void> {
  const first_ids = selection.slice(0, 50).map((x) => x.id);
  try {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
    const response = (await ajax({
      url: "api/v1/users",
      type: "GET",
      data: {
        order: "id",
        userIds: first_ids,
        exclude: [guest_id],
      },
      dataType: "json",
    })) as UserListResponse;
    const result = response.users;
    for (const resultUser of result) {
      const index = selection.findIndex((x) => x.id === resultUser.id);
      if (index !== -1) {
        selection[index]!.username = resultUser.username;
      }
    }
    callback();
  } catch {
    // No error handling before conversion either -- a failure here
    // silently leaves the selection's usernames unset.
  }
}

async function select_whole_set(): Promise<void> {
  const filterLevel = val(
    document.querySelectorAll(".advanced-filter-select[name=filter_level]"),
  );
  const filterGroup = val(
    document.querySelectorAll(".advanced-filter-select[name=filter_group]"),
  );
  const filterStatus = val(
    document.querySelectorAll(".advanced-filter-select[name=filter_status]"),
  );
  show(document.querySelectorAll("#checkActions .loading"));
  try {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
    const response = (await ajax({
      url: "api/v1/users",
      type: "GET",
      data: {
        order: "id",
        page: actual_page - 1,
        perPage: 0,
        exclude: [guest_id],
        status:
          filterStatus !== undefined && filterStatus !== ""
            ? [filterStatus]
            : [],
        groupIds:
          filterGroup !== undefined && filterGroup !== "" ? [filterGroup] : [],
        minLevel: filterLevel,
        maxLevel: filterLevel,
        minRegister:
          register_dates[
            slider(
              document.querySelectorAll(
                ".dates-select-bar .slider-bar-container",
              ),
              "option",
              "values",
            )![0]!
          ],
        maxRegister:
          register_dates[
            slider(
              document.querySelectorAll(
                ".dates-select-bar .slider-bar-container",
              ),
              "option",
              "values",
            )![1]!
          ],
      },
      dataType: "json",
    })) as UserListResponse;
    selection = response.users.map((x) => {
      return { id: x.id };
    });
    hide(document.querySelectorAll("#checkActions .loading"));
    update_selection_content();
  } catch {
    hide(document.querySelectorAll("#checkActions .loading"));
  }
}

async function update_user_username(): Promise<void> {
  const pop_in_container = document.querySelector("#UserList")!;
  const ajax_data: Record<string, unknown> = {};
  const newUsername = String(
    val(find(pop_in_container, ".user-property-input-username")),
  );
  ajax_data["username"] = newUsername;
  if (newUsername.replace(/\s/g, "").length === 0) {
    const failEl = document.querySelectorAll(".update-user-fail");
    html(failEl, fieldNotEmpty);
    fadeIn(failEl);
    delay(failEl, 1500);
    fadeOut(failEl, 2500);
    return;
  }
  try {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
    const response = (await ajax({
      url: "api/v1/users/" + String(last_user_id),
      type: "PATCH",
      headers: { "X-CSRF-Token": pwg_token },
      json: ajax_data,
      dataType: "json",
    })) as operations["userUpdate"]["responses"][200]["content"]["application/json"];
    if (last_user_index !== -1) {
      // "username" may be missing from a defensive-fallback response
      // (the row couldn't be re-fetched right after the update) --
      // the value we just successfully submitted is still correct.
      const updatedUsername =
        "username" in response ? response.username : newUsername;
      current_users[last_user_index]!.username = updatedUsername;
      html(
        document.querySelectorAll(
          "#UserList .user-property-username .edit-username-title",
        ),
        updatedUsername,
      );
      html(
        document.querySelectorAll("#UserList .user-property-initials span"),
        get_initials(updatedUsername),
      );
      fill_container_user_info(
        document.querySelectorAll("#user-table-content .user-container")[
          last_user_index
        ]!,
        last_user_index,
      );
    }
    hide(document.querySelectorAll(".user-property-username-change-input"));
    fadeIn(document.querySelectorAll(".edit-username-success"));
    on(
      document.querySelectorAll("#close_username_success"),
      "click",
      function () {
        hide(document.querySelectorAll(".edit-username-success"));
        show(document.querySelectorAll(".user-property-username-change-input"));
        hide(document.querySelectorAll(".user-property-username-change"));
      },
    );
  } catch {
    // No error handling before conversion either -- a failure here
    // silently leaves the username change form open.
  }
}

async function update_user_password(): Promise<void> {
  const pop_in_container = document.querySelector("#UserList")!;
  const ajax_data: Record<string, unknown> = {};
  const newPassword = String(
    val(find(pop_in_container, ".user-property-input-password")),
  );
  ajax_data["password"] = newPassword;
  try {
    await ajax({
      url: "api/v1/users/" + String(last_user_id),
      type: "PATCH",
      headers: { "X-CSRF-Token": pwg_token },
      json: ajax_data,
      dataType: "json",
    });
    hide(document.querySelectorAll(".user-property-password-change-inputs"));
    fadeIn(document.querySelectorAll("#edit_password_success_change"));

    // eslint-disable-next-line @typescript-eslint/no-unnecessary-condition, @typescript-eslint/strict-boolean-expressions -- real feature-detection guard: lib.dom types navigator.clipboard as always-present, but it's genuinely absent on a non-secure origin or an older browser.
    if (window.isSecureContext && navigator.clipboard) {
      on(document.querySelectorAll("#copy_password"), "click", function () {
        copyToClipboard(newPassword);
        html(
          document.querySelectorAll("#password_msg_success"),
          passwordCopied,
        );
      });
    }

    on(
      document.querySelectorAll("#close_password_success"),
      "click",
      function () {
        hide(document.querySelectorAll(".user-property-password-change"));
        hide(document.querySelectorAll("#edit_password_success_change"));
        show(
          document.querySelectorAll(".user-property-password-change-inputs"),
        );
        reset_input_password();
      },
    );
  } catch {
    // No error handling before conversion either -- a failure here
    // silently leaves the password change form open.
  }
}

async function update_user_info(): Promise<void> {
  //Show spinner
  const btnIcon = document.querySelectorAll(".update-user-button i");
  removeClass(btnIcon, "icon-floppy");
  addClass(btnIcon, "icon-spin6 animate-spin");
  addClass(document.querySelectorAll(".update-user-button"), "unclickable");
  const pop_in_container = document.querySelector(".UserListPopInContainer")!;
  let ajax_data: Record<string, unknown> = {};
  if (plugins_users_infos_table.length > 0) {
    const keyCurrentUsers = Object.keys(current_users[last_user_index]!);
    plugins_users_infos_table.forEach((i) => {
      if (keyCurrentUsers.includes(i.users_table)) {
        ajax_data[i.users_table] = val(
          document.querySelectorAll("#" + i.content_id),
        );
      } else {
        console.error(i.users_table, " doesn't exist in USER_INFOS_TABLE");
      }
    });
  }

  ajax_data = fill_ajax_data_from_container(ajax_data, pop_in_container);
  fadeOut(document.querySelectorAll("#UserList .update-user-fail"));
  fadeOut(document.querySelectorAll("#UserList .update-user-success"));
  try {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
    const result_user = (await ajax({
      url: "api/v1/users/" + String(last_user_id),
      type: "PATCH",
      headers: { "X-CSRF-Token": pwg_token },
      json: ajax_data,
      dataType: "json",
    })) as operations["userUpdate"]["responses"][200]["content"]["application/json"];
    if (last_user_index !== -1) {
      current_users[last_user_index] = {
        ...current_users[last_user_index]!,
        ...result_user,
      };
      fill_container_user_info(
        document.querySelectorAll("#user-table-content .user-container")[
          last_user_index
        ]!,
        last_user_index,
      );
    }
    const successEl = document.querySelectorAll(
      "#UserList .update-user-success",
    );
    fadeIn(successEl);
    delay(successEl, 1500);
    fadeOut(successEl, 2500);

    //Hide spinner
    removeClass(btnIcon, "icon-spin6 animate-spin");
    addClass(btnIcon, "icon-floppy");
    removeClass(
      document.querySelectorAll(".update-user-button"),
      "unclickable",
    );

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
      last_user_index !== -1 ? current_users[last_user_index]! : guest_user,
      document.querySelector("#UserList")!,
    );
  } catch (e) {
    const message =
      (e instanceof AjaxError
        ? // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- safe regardless of the real shape: a malformed/non-object responseJSON just makes `.detail` read undefined, falling back below.
          (e.responseJSON as { detail?: string } | undefined)?.detail
        : undefined) ?? errorStr;
    html(document.querySelectorAll("#UserList .update-user-fail"), message);
    fadeIn(document.querySelectorAll("#UserList .update-user-fail"));
    addClass(btnIcon, "icon-floppy");
    removeClass(btnIcon, "icon-spin6 animate-spin");
    removeClass(
      document.querySelectorAll(".update-user-button"),
      "unclickable",
    );
    setTimeout(() => {
      fadeOut(document.querySelectorAll("#UserList .update-user-fail"));
    }, 5000);
  }
}

async function get_guest_info(): Promise<void> {
  try {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
    const response = (await ajax({
      url: "api/v1/users",
      type: "GET",
      data: {
        userIds: [guest_id],
      },
      dataType: "json",
    })) as UserListResponse;
    if (response.users.length) {
      guest_user = response.users[0]!;
      fill_guest_edit();
    }
  } catch {
    // No error handling before conversion either.
  }
}

async function get_user_info(
  uid: number,
  callback: (() => void) | null = null,
): Promise<void> {
  try {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
    const response = (await ajax({
      url: "api/v1/users",
      type: "GET",
      data: {
        userIds: [uid],
      },
      dataType: "json",
    })) as UserListResponse;
    if (response.users.length) {
      const result_user = response.users[0]!;
      fill_user_edit(result_user);
      callback?.();
    }
  } catch {
    // No error handling before conversion either.
  }
}

async function update_guest_info(): Promise<void> {
  //Show spinner
  const btnIcon = document.querySelectorAll(".update-user-button i");
  removeClass(btnIcon, "icon-floppy");
  addClass(btnIcon, "icon-spin6 animate-spin");
  addClass(document.querySelectorAll(".update-user-button"), "unclickable");

  const pop_in_container = document.querySelector(
    ".GuestUserListPopInContainer",
  )!;
  let ajax_data: Record<string, unknown> = {};
  ajax_data = fill_ajax_data_from_container(ajax_data, pop_in_container);
  ajax_data["email"] = undefined;
  ajax_data["status"] = undefined;
  try {
    await ajax({
      url: "api/v1/users/" + String(guest_id),
      type: "PATCH",
      headers: { "X-CSRF-Token": pwg_token },
      json: ajax_data,
      dataType: "json",
    });
    const successEl = document.querySelectorAll(
      "#GuestUserList .update-user-success",
    );
    fadeIn(successEl);
    delay(successEl, 1500);
    fadeOut(successEl, 2500);
    //Hide spinner
    removeClass(btnIcon, "icon-spin6 animate-spin");
    addClass(btnIcon, "icon-floppy");
    removeClass(
      document.querySelectorAll(".update-user-button"),
      "unclickable",
    );
  } catch {
    removeClass(btnIcon, "icon-spin6 animate-spin");
    addClass(btnIcon, "icon-floppy");
    removeClass(
      document.querySelectorAll(".update-user-button"),
      "unclickable",
    );
  }
}

async function update_user_list(): Promise<void> {
  const update_data: Record<string, unknown> = {
    order: filter_by, // We want the most recent user first
    page: actual_page - 1,
    perPage: per_page,
    exclude: [guest_id],
  };
  const userSearchVal = val(document.querySelectorAll("#user_search"));
  if (userSearchVal !== undefined && userSearchVal !== "") {
    const matches = /^id:(\d+)$/.exec(userSearchVal);
    if (matches) {
      update_data["userIds"] = [matches[1]];
    }
    // Free-text fuzzy search (username/email/group-name) has no
    // GET /api/v1/users equivalent -- UserListController's own
    // docblock already documents this as a deliberately deferred
    // filter, not a gap introduced by this conversion.
  }
  if (
    hasClass(
      document.querySelectorAll(".advanced-filter"),
      "advanced-filter-open",
    )
  ) {
    const filterStatus = val(
      document.querySelectorAll(".advanced-filter-select[name=filter_status]"),
    );
    const filterGroup = val(
      document.querySelectorAll(".advanced-filter-select[name=filter_group]"),
    );
    const filterLevel = val(
      document.querySelectorAll(".advanced-filter-select[name=filter_level]"),
    );
    update_data["status"] =
      filterStatus !== undefined && filterStatus !== "" ? [filterStatus] : [];
    update_data["groupIds"] =
      filterGroup !== undefined && filterGroup !== "" ? [filterGroup] : [];
    update_data["minLevel"] = filterLevel;
    update_data["maxLevel"] = filterLevel;
    update_data["minRegister"] =
      register_dates[
        slider(
          document.querySelectorAll(".dates-select-bar .slider-bar-container"),
          "option",
          "values",
        )![0]!
      ];
    update_data["maxRegister"] =
      register_dates[
        slider(
          document.querySelectorAll(".dates-select-bar .slider-bar-container"),
          "option",
          "values",
        )![1]!
      ];
  }
  show(document.querySelectorAll(".user-update-spinner"));
  try {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
    const response = (await ajax({
      url: "api/v1/users",
      type: "GET",
      data: update_data,
      dataType: "json",
    })) as UserListResponse;
    total_users = response.totalCount;
    if (first_update) {
      document
        .querySelector("h1")
        ?.insertAdjacentHTML(
          "beforeend",
          `<span class='badge-number'>${total_users}</span>`,
        );
      first_update = false;
    }
    nb_filtered_users = response.totalCount;
    update_pagination_menu();
    current_users = response.users;
    generate_user_list();
    on(
      document.querySelectorAll(".user-col.user-first-col.user-container-edit"),
      "click",
      function (this: Element) {
        const uid_index = Number(
          attrOf(this.closest(".user-container")!, "key"),
        );
        last_user_id = current_users[uid_index]!.id;
        last_user_index = uid_index;
        fill_user_edit(current_users[uid_index]!);
        fadeIn(document.querySelectorAll("#UserList"));
        document.querySelector("#tab_properties")?.scrollIntoView({
          behavior: "instant",
        });
      },
    );
    set_selected_to_selection();

    hide(document.querySelectorAll(".user-update-spinner"));

    let nb_filters = 0;
    if (
      val(
        document.querySelectorAll(
          ".advanced-filter-select[name=filter_status]",
        ),
      ) !== ""
    )
      nb_filters += 1;
    if (
      val(
        document.querySelectorAll(".advanced-filter-select[name=filter_group]"),
      ) !== ""
    )
      nb_filters += 1;
    if (
      val(
        document.querySelectorAll(".advanced-filter-select[name=filter_level]"),
      ) !== ""
    )
      nb_filters += 1;
    if (
      slider(
        document.querySelectorAll(".dates-select-bar .slider-bar-container"),
        "option",
        "values",
      )![0] !== 0
    )
      nb_filters += 1;
    if (
      slider(
        document.querySelectorAll(".dates-select-bar .slider-bar-container"),
        "option",
        "values",
      )![1] !==
      register_dates.length - 1
    )
      nb_filters += 1;

    show_filter_infos(nb_filters);
  } catch {
    hide(document.querySelectorAll(".user-update-spinner"));
  }
}

async function add_user(): Promise<void> {
  const ajax_data: Record<string, unknown> = {};
  const groups_selected = Array.from(
    document.querySelectorAll(
      ".AddUserInputContainer .user-property-group .selectize-input .item",
    ),
  ).map((el) => parseInt(attrOf(el, "data-value")!));
  ajax_data["username"] = val(
    document.querySelectorAll(".AddUserLabelUsername .user-property-input"),
  );
  ajax_data["email"] = val(
    document.querySelectorAll(".AddUserLabelEmail .user-property-input"),
  );
  ajax_data["status"] = val(
    document.querySelectorAll(
      ".AddUserInputContainer .user-property-status select",
    ),
  );
  ajax_data["level"] = Number(
    val(
      document.querySelectorAll(
        ".AddUserInputContainer .user-property-level select",
      ),
    ),
  );
  ajax_data["enabledHigh"] =
    attrOf(
      document.querySelectorAll(
        '.AddUserInputContainer .user-list-checkbox[name="hd_enabled"]',
      ),
      "data-selected",
    ) === "1"
      ? true
      : false;
  ajax_data["groupIds"] = groups_selected;

  // for debug
  // console.log(ajax_data);

  const payload: Record<string, unknown> = {
    username: ajax_data["username"],
    email: ajax_data["email"],
  };

  if ("generic" === ajax_data["status"]) {
    payload["password"] = val(document.querySelectorAll("#add_user_pass"));
    payload["passwordConfirm"] = val(
      document.querySelectorAll("#add_user_confpass"),
    );
  } else {
    payload["autoPassword"] = true;
  }

  css(
    document.querySelectorAll("#AddUser .AddUserErrors"),
    "visibility",
    "hidden",
  );
  if (
    val(
      document.querySelectorAll(".AddUserLabelUsername .user-property-input"),
    ) === ""
  ) {
    html(document.querySelectorAll("#AddUser .AddUserErrors"), missingUsername);
    css(
      document.querySelectorAll("#AddUser .AddUserErrors"),
      "visibility",
      "visible",
    );
    // Real, previously-live bug fixed here: the old `beforeSend`
    // callback's `return false` was meant to cancel the request (real
    // jQuery `beforeSend` semantics), but this app's own `ajax()` never
    // checked `beforeSend`'s return value at all -- the POST fired
    // regardless of this validation failing. A real early `return` here
    // is the first time this validation actually prevents the request.
    return;
  }
  if ("generic" === ajax_data["status"]) {
    const pass = val(document.querySelectorAll("#add_user_pass"));
    const confPass = val(document.querySelectorAll("#add_user_confpass"));
    if ("" === pass) {
      html(
        document.querySelectorAll("#AddUser .AddUserErrors"),
        missingPassword,
      );
      css(
        document.querySelectorAll("#AddUser .AddUserErrors"),
        "visibility",
        "visible",
      );
      return;
    }
    if ("" === confPass) {
      html(
        document.querySelectorAll("#AddUser .AddUserErrors"),
        missingConfPassword,
      );
      css(
        document.querySelectorAll("#AddUser .AddUserErrors"),
        "visibility",
        "visible",
      );
      return;
    }
    if (pass !== confPass) {
      html(
        document.querySelectorAll("#AddUser .AddUserErrors"),
        noMatchPassword,
      );
      css(
        document.querySelectorAll("#AddUser .AddUserErrors"),
        "visibility",
        "visible",
      );
      return;
    }
  }

  try {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
    const response = (await ajax({
      url: "api/v1/users",
      type: "POST",
      headers: { "X-CSRF-Token": pwg_token },
      json: payload,
      dataType: "json",
    })) as operations["userCreate"]["responses"][201]["content"]["application/json"];
    const new_user_id = response.id;
    const default_group = "groups" in response ? response.groups : [];
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax_data["groupIds"] was set to groups_selected (a real number[]) earlier in this same function, the only writer before this read.
    ajax_data["groupIds"] = (ajax_data["groupIds"] as number[]).concat(
      default_group,
    );
    void add_infos_to_new_user(new_user_id, ajax_data);
  } catch (e) {
    const message =
      (e instanceof AjaxError
        ? // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- safe regardless of the real shape: a malformed/non-object responseJSON just makes `.detail` read undefined, falling back below.
          (e.responseJSON as { detail?: string } | undefined)?.detail
        : undefined) ?? errorStr;
    html(document.querySelectorAll("#AddUser .AddUserErrors"), message);
    css(
      document.querySelectorAll("#AddUser .AddUserErrors"),
      "visibility",
      "visible",
    );
  }
}

async function add_infos_to_new_user(
  user_id: number,
  ajax_data: Record<string, unknown>,
): Promise<void> {
  try {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
    const response = (await ajax({
      url: "api/v1/users/" + String(user_id),
      type: "PATCH",
      headers: { "X-CSRF-Token": pwg_token },
      json: {
        status: ajax_data["status"],
        level: ajax_data["level"],
        groupIds: ajax_data["groupIds"],
        enabledHigh: ajax_data["enabledHigh"],
      },
      dataType: "json",
    })) as operations["userUpdate"]["responses"][200]["content"]["application/json"];
    const new_user_id = response.id;
    void update_user_list();
    // add_user_close();
    removeClass(
      document.querySelectorAll("#AddUserUpdated"),
      "icon-red icon-cancel",
    );
    addClass(
      document.querySelectorAll("#AddUserUpdated"),
      "icon-green border-green icon-ok",
    );
    html(
      document.querySelectorAll("#AddUserUpdatedText"),
      user_added_str.replace("%s", String(ajax_data["username"])),
    );
    const status = ["webmaster", "admin", "normal"];
    if (status.includes(String(ajax_data["status"]))) {
      void send_new_user_password(new_user_id, String(ajax_data["email"]));
    } else {
      add_user_close();
    }
    setVal(document.querySelectorAll("#AddUser .user-property-input"), "");
    const editNow = document.querySelectorAll("#AddUserSuccess .edit-now");
    off(editNow, "click");
    on(editNow, "click", () => {
      last_user_id = new_user_id;
      last_user_index = get_container_index_from_uid(new_user_id);
      if (last_user_index !== -1) {
        fill_user_edit(current_users[last_user_index]!);
        open_user_list();
      } else {
        void get_user_info(new_user_id, open_user_list);
      }
    });
    html(
      document.querySelectorAll("#AddUserSuccess label span:first-child"),
      user_added_str.replace("%s", String(ajax_data["username"])),
    );
    css(document.querySelectorAll("#AddUserSuccess"), "display", "flex");
    html(
      document.querySelectorAll(".badge-number"),
      String(
        Number(htmlOf(document.querySelectorAll(".badge-number")) ?? "0") + 1,
      ),
    );
  } catch (e) {
    const message =
      (e instanceof AjaxError
        ? // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- safe regardless of the real shape: a malformed/non-object responseJSON just makes `.detail` read undefined, falling back below.
          (e.responseJSON as { detail?: string } | undefined)?.detail
        : undefined) ?? errorStr;
    html(document.querySelectorAll("#AddUser .AddUserErrors"), message);
    css(
      document.querySelectorAll("#AddUser .AddUserErrors"),
      "visibility",
      "visible",
    );
  }
}

async function send_new_user_password(
  user_id: number,
  mail: string,
): Promise<void> {
  const send_by_mail = mail === "" ? false : true;
  try {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
    const response = (await ajax({
      url:
        "api/v1/users/" + String(user_id) + "/actions/generate-password-link",
      dataType: "json",
      type: "POST",
      headers: { "X-CSRF-Token": pwg_token },
      json: {
        sendByMail: send_by_mail,
      },
    })) as operations["userGeneratePasswordLink"]["responses"][200]["content"]["application/json"];
    const password_container = document.querySelectorAll(
      "#AddUserPasswordInputContainer",
    );
    show(password_container);
    hide(document.querySelectorAll("#AddUserFieldContainer"));
    fadeIn(document.querySelectorAll("#AddUserSuccessContainer"));
    setVal(
      document.querySelectorAll("#AddUserPasswordLink"),
      response.generatedLink,
    );
    trigger(document.querySelectorAll("#AddUserPasswordLink"), "focus");
    html(
      document.querySelectorAll("#AddUserTextField"),
      send_by_mail
        ? sprintf(validLinkMail, response.timeValidation, `<b>${mail}</b>`)
        : sprintf(validLinkWithoutMail, response.timeValidation),
    );

    if (
      send_by_mail &&
      (response.sendByMail === false || response.sendByMail === null)
    ) {
      removeClass(
        document.querySelectorAll("#AddUserUpdated"),
        "icon-green border-green icon-ok",
      );
      addClass(
        document.querySelectorAll("#AddUserUpdated"),
        "icon-red-error icon-cancel",
      );
      html(document.querySelectorAll("#AddUserUpdatedText"), errorMailSent);
      html(
        document.querySelectorAll("#AddUserTextField"),
        sprintf(errorMailSentMsg, response.timeValidation),
      );
    } else if (
      send_by_mail &&
      response.sendByMail !== false &&
      response.sendByMail !== null
    ) {
      hide(password_container);
    }

    // eslint-disable-next-line @typescript-eslint/no-unnecessary-condition, @typescript-eslint/strict-boolean-expressions -- real feature-detection guard: lib.dom types navigator.clipboard as always-present, but it's genuinely absent on a non-secure origin or an older browser.
    if (window.isSecureContext && navigator.clipboard) {
      const copyBtn = document.querySelectorAll("#AddUserCopyPassword");
      off(copyBtn, "click");
      on(copyBtn, "click", function () {
        const successMsg = document.querySelectorAll("#AddUserUpdatedText");
        fadeOut(successMsg);
        copyToClipboard(response.generatedLink);
        removeClass(
          document.querySelectorAll("#AddUserUpdated"),
          "icon-red icon-cancel",
        );
        addClass(
          document.querySelectorAll("#AddUserUpdated"),
          "icon-green border-green icon-ok",
        );
        html(successMsg, copyLinkStr);
        fadeIn(successMsg);
      });
    }
    const addUserButton = document.querySelectorAll("#AddUserButton");
    off(addUserButton, "click");
    on(addUserButton, "click", function () {
      add_user_close();
    });
  } catch (e) {
    const message =
      (e instanceof AjaxError
        ? // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- safe regardless of the real shape: a malformed/non-object responseJSON just makes `.detail` read undefined, falling back below.
          (e.responseJSON as { detail?: string } | undefined)?.detail
        : undefined) ?? errorStr;
    removeClass(
      document.querySelectorAll("#AddUserUpdated"),
      "icon-green border-green icon-ok",
    );
    addClass(
      document.querySelectorAll("#AddUserUpdated"),
      "icon-red-error icon-cancel",
    );
    html(document.querySelectorAll("#AddUserUpdatedText"), message);
  }
}

async function delete_user(uid: number): Promise<void> {
  try {
    await ajax({
      url: "api/v1/users/" + String(uid),
      type: "DELETE",
      headers: { "X-CSRF-Token": pwg_token },
    });
    close_user_list();
    void update_user_list();
    html(
      document.querySelectorAll(".badge-number"),
      String(
        Number(htmlOf(document.querySelectorAll(".badge-number")) ?? "0") - 1,
      ),
    );
  } catch {
    // No error handling before conversion either.
  }
}

function show_filter_infos(nb_filters: number) {
  if (
    (val(document.querySelectorAll("#user_search")) ?? "").length !== 0 ||
    nb_filters !== 0
  ) {
    if (String(total_users) !== "1") {
      html(
        document.querySelectorAll(".filtered-users"),
        filtered_users.replace(/%d/g, String(total_users)),
      );
    } else {
      html(
        document.querySelectorAll(".filtered-users"),
        filtered_user.replace(/%d/g, String(total_users)),
      );
    }
  } else {
    html(document.querySelectorAll(".filtered-users"), "");
  }

  if (nb_filters !== 0) {
    css(document.querySelectorAll(".advanced-filter-btn"), {
      width: "80px",
    });
    const counter = document.querySelectorAll(".filter-counter");
    html(counter, String(nb_filters));
    css(counter, "display", "flex");
  } else {
    css(document.querySelectorAll(".advanced-filter-btn"), {
      width: "70px",
    });
    const counter = document.querySelectorAll(".filter-counter");
    css(counter, "display", "none");
    html(counter, "0");
  }
}

async function send_link_password(
  email: string | null,
  username: string,
  user_id: number,
  send_by_mail: boolean,
): Promise<void> {
  try {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
    const response = (await ajax({
      url:
        "api/v1/users/" + String(user_id) + "/actions/generate-password-link",
      dataType: "json",
      type: "POST",
      headers: { "X-CSRF-Token": pwg_token },
      json: {
        sendByMail: send_by_mail,
      },
    })) as operations["userGeneratePasswordLink"]["responses"][200]["content"]["application/json"];
    setVal(
      document.querySelectorAll("#result_send_mail_copy_input"),
      response.generatedLink,
    );
    if (send_by_mail) {
      if (response.sendByMail !== false && response.sendByMail !== null) {
        removeClass(
          document.querySelectorAll("#result_send_mail"),
          "update-password-fail icon-red",
        );
        addClass(
          document.querySelectorAll("#result_send_mail"),
          "update-password-success icon-green",
        );
        removeClass(
          document.querySelectorAll("#icon_password_msg_result_mail"),
          "icon-cancel",
        );
        addClass(
          document.querySelectorAll("#icon_password_msg_result_mail"),
          "icon-ok",
        );
        const curr_mail = (
          val(
            document.querySelectorAll(
              ".user-property-email .user-property-input",
            ),
          ) ?? ""
        ).length
          ? String(
              val(
                document.querySelectorAll(
                  ".user-property-email .user-property-input",
                ),
              ),
            )
          : (email ?? "");
        html(
          document.querySelectorAll("#password_msg_result_mail"),
          sprintf(mailSentAt, username, curr_mail),
        );
      } else {
        removeClass(
          document.querySelectorAll("#result_send_mail"),
          "update-password-success icon-green",
        );
        addClass(
          document.querySelectorAll("#result_send_mail"),
          "update-password-fail icon-red",
        );
        removeClass(
          document.querySelectorAll("#icon_password_msg_result_mail"),
          "icon-ok",
        );
        addClass(
          document.querySelectorAll("#icon_password_msg_result_mail"),
          "icon-cancel",
        );
        html(
          document.querySelectorAll("#password_msg_result_mail"),
          errorMailSent,
        );
      }
      hide(document.querySelectorAll(".user-property-password-choice"));
      fadeIn(document.querySelectorAll("#edit_password_result_mail"));
      const closeBtn = document.querySelectorAll("#close_password_mail_close");
      off(closeBtn, "click");
      on(closeBtn, "click", function () {
        reset_password_modals();
      });
    } else {
      removeClass(
        document.querySelectorAll("#result_send_mail_copy"),
        "update-password-fail icon-red",
      );
      addClass(
        document.querySelectorAll("#result_send_mail_copy"),
        "update-password-success icon-green",
      );
      removeClass(
        document.querySelectorAll("#result_send_mail_copy_icon"),
        "icon-cancel",
      );
      addClass(
        document.querySelectorAll("#result_send_mail_copy_icon"),
        "icon-ok",
      );
      html(
        document.querySelectorAll("#result_send_mail_copy_msg"),
        copyLinkStr,
      );
      // eslint-disable-next-line @typescript-eslint/no-unnecessary-condition, @typescript-eslint/strict-boolean-expressions -- real feature-detection guard: lib.dom types navigator.clipboard as always-present, but it's genuinely absent on a non-secure origin or an older browser.
      if (window.isSecureContext && navigator.clipboard) {
        copyToClipboard(response.generatedLink);
      }
    }
  } catch (err) {
    console.error("Error send_link_password :", err);
    if (!send_by_mail) {
      removeClass(
        document.querySelectorAll("#result_send_mail_copy"),
        "update-password-success icon-green",
      );
      addClass(
        document.querySelectorAll("#result_send_mail_copy"),
        "update-password-fail icon-red",
      );
      removeClass(
        document.querySelectorAll("#result_send_mail_copy_icon"),
        "icon-ok",
      );
      addClass(
        document.querySelectorAll("#result_send_mail_copy_icon"),
        "icon-cancel",
      );
      html(document.querySelectorAll("#result_send_mail_copy_msg"), errorStr);
    } else {
      removeClass(
        document.querySelectorAll("#result_send_mail"),
        "update-password-success icon-green",
      );
      addClass(
        document.querySelectorAll("#result_send_mail"),
        "update-password-fail icon-red",
      );
      removeClass(
        document.querySelectorAll("#icon_password_msg_result_mail"),
        "icon-ok",
      );
      addClass(
        document.querySelectorAll("#icon_password_msg_result_mail"),
        "icon-cancel",
      );
      html(
        document.querySelectorAll("#password_msg_result_mail"),
        errorMailSent,
      );
      hide(document.querySelectorAll(".user-property-password-choice"));
      fadeIn(document.querySelectorAll("#edit_password_result_mail"));
      const closeBtn = document.querySelectorAll("#close_password_mail_close");
      off(closeBtn, "click");
      on(closeBtn, "click", function () {
        reset_password_modals();
      });
    }
  }
}

async function set_main_user(
  user_id: number,
  new_username: string,
): Promise<void> {
  try {
    await ajax({
      url: "api/v1/users/" + String(user_id) + "/actions/set-main-user",
      dataType: "json",
      type: "POST",
      contentType: "application/json",
      headers: { "X-CSRF-Token": pwg_token },
    });
    const king = document.querySelectorAll("#who_is_the_king");
    off(king, "click");
    removeClass(
      king,
      "princes-of-this-piwigo royal-court-of-this-piwigo can-change",
    );
    addClass(king, "king-of-this-piwigo cannot-change");
    attr(king, "title", mainUserStr);
    tipTip(king);

    owner_id = user_id;
    owner_username = new_username;
    set_main_user_success();
  } catch (err) {
    console.error(err);
  }
}

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
owner_username = pwg_getPageData<string>("owner_username");
const parsedGroupsArrName: unknown = JSON.parse(
  pwg_getPageData<string>("groups_arr_name"),
);
const groups_arr_name = Array.isArray(parsedGroupsArrName)
  ? parsedGroupsArrName.filter((n): n is string => typeof n === "string")
  : [];
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
register_dates = pwg_getPageData<string>("register_dates").split(",");
groupOptions = groups_arr.map(function (x) {
  return { value: x[0], label: x[1], isSelected: false };
});

/* Startup */
setupRegisterDates(register_dates);
selectionMode(false);
void get_guest_info();
void update_user_list();
update_selection_content();

tipTip(document.querySelectorAll(".icon-help-circled"), {
  maxWidth: "700px",
  fadeIn: "1000",
});

ready(function () {
  // Only webmaster can set admin or webmaster to others users
  if (connected_user_status !== "webmaster") {
    const disabledOptions = document.querySelectorAll(
      'select[name="status"] option[value="webmaster"], select[name="status"] option[value="admin"]',
    );
    disabledOptions.forEach((option) => {
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- verified by the selector: only <option> elements can match "select[...] option[...]".
      (option as HTMLOptionElement).disabled = true;
    });
  }
  // We set the applyAction btn click event here so plugins can add cases to the list
  // which is not possible if this JS part is in a JS file
  // see #1571 on Github
  on(document.querySelectorAll("#applyAction"), "click", function (e: Event) {
    void (async () => {
      const action = val(
        document.querySelectorAll("select[name=selectAction]"),
      );
      const actionData: Record<string, unknown> = {};
      switch (action) {
        case "delete":
          if (
            !(
              attrOf(
                document.querySelectorAll(
                  "#permitActionUserList .user-list-checkbox[name=confirm_deletion]",
                ),
                "data-selected",
              ) === "1"
            )
          ) {
            alert(missingConfirm);
            e.preventDefault();
            return;
          }
          break;
        case "group_associate":
          actionData["group_id"] = val(
            document.querySelectorAll(
              "#permitActionUserList select[name=associate]",
            ),
          );
          break;
        case "group_dissociate":
          actionData["group_id"] = val(
            document.querySelectorAll(
              "#permitActionUserList select[name=dissociate]",
            ),
          );
          break;
        case "status":
          actionData["status"] = val(
            document.querySelectorAll(
              "#permitActionUserList select[name=status]",
            ),
          );
          break;
        case "enabled_high":
          actionData["enabled_high"] =
            attrOf(
              document.querySelectorAll(
                "#permitActionUserList .user-list-checkbox[name=enabled_high_yes]",
              ),
              "data-selected",
            ) === "1"
              ? true
              : false;
          break;
        case "level":
          actionData["level"] = val(
            document.querySelectorAll(
              "#permitActionUserList select[name=level]",
            ),
          );
          break;
        case "nb_image_page":
          actionData["nb_image_page"] = val(
            document.querySelectorAll(
              "#permitActionUserList input[name=nb_image_page]",
            ),
          );
          break;
        case "theme":
          actionData["theme"] = val(
            document.querySelectorAll(
              "#permitActionUserList select[name=theme]",
            ),
          );
          break;
        case "language":
          actionData["language"] = val(
            document.querySelectorAll(
              "#permitActionUserList select[name=language]",
            ),
          );
          break;
        case "recent_period":
          actionData["recent_period"] =
            recent_period_values[
              slider(
                document.querySelectorAll(
                  "#permitActionUserList .period-select-bar .slider-bar-container",
                ),
                "option",
                "value",
              )!
            ];
          break;
        case "expand":
          actionData["expand"] =
            attrOf(
              document.querySelectorAll(
                "#permitActionUserList .user-list-checkbox[name=expand_yes]",
              ),
              "data-selected",
            ) === "1"
              ? true
              : false;
          break;
        case "show_nb_comments":
          actionData["show_nb_comments"] =
            attrOf(
              document.querySelectorAll(
                "#permitActionUserList .user-list-checkbox[name=show_nb_comments_yes]",
              ),
              "data-selected",
            ) === "1"
              ? true
              : false;
          break;
        case "show_nb_hits":
          actionData["show_nb_hits"] =
            attrOf(
              document.querySelectorAll(
                "#permitActionUserList .user-list-checkbox[name=show_nb_hits_yes]",
              ),
              "data-selected",
            ) === "1"
              ? true
              : false;
          break;
        default:
          alert("Unexpected action");
          e.preventDefault();
          return;
      }

      // Translate the `actionData` bag above into the real /api/v1 request(s)
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

      let request: Promise<unknown>;
      show(document.querySelectorAll("#applyActionLoading"));
      fadeOut(document.querySelectorAll("#applyActionBlock .infos"));

      if (action === "delete") {
        request = Promise.all(
          userIds.map(async (id) =>
            ajax({
              url: "api/v1/users/" + String(id),
              method: "DELETE",
              headers: { "X-CSRF-Token": pwg_token },
            }),
          ),
        );
      } else if (
        action === "group_associate" ||
        action === "group_dissociate"
      ) {
        request = ajax({
          url:
            "api/v1/groups/" +
            String(actionData["group_id"]) +
            "/actions/" +
            (action === "group_associate" ? "add-user" : "remove-user"),
          method: "POST",
          json: { userIds: userIds },
          headers: { "X-CSRF-Token": pwg_token },
        });
      } else {
        const field = fieldByAction[action]!;
        const value = numericFields.includes(field)
          ? Number(actionData[action])
          : actionData[action];
        request = Promise.all(
          userIds.map(async (id) =>
            ajax({
              url: "api/v1/users/" + String(id),
              method: "PATCH",
              json: { [field]: value },
              headers: { "X-CSRF-Token": pwg_token },
            }),
          ),
        );
      }

      try {
        await request;
        hide(document.querySelectorAll("#applyActionLoading"));
        fadeIn(document.querySelectorAll("#applyActionBlock .infos"));
        css(
          document.querySelectorAll("#applyActionBlock .infos"),
          "display",
          "inline-block",
        );
        void update_user_list();
        if (action === "delete") {
          selection = [];
          update_selection_content();
        }
      } catch {
        hide(document.querySelectorAll("#applyActionLoading"));
      }
    })();
    e.preventDefault();
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
