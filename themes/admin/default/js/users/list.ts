import type {
  components,
  operations,
} from "../../../../../openapi/client/schema";
import { getRandomInt, sprintf } from "../../../../default/js/sprintf";
import { jConfirmConfirmOptions } from "../jconfirmPresets";

import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../../default/js/pageData";
import { ajax, AjaxError } from "../../../../default/js/vendor/utils/ajax";
import { confirm } from "../../../../default/js/vendor/widgets/jconfirm";
import {
  getSelectizeInstance,
  selectize as createSelectize,
} from "../../../../default/js/vendor/widgets/selectize";
import {
  slider,
  type SliderUIParams,
} from "../../../../default/js/vendor/widgets/slider";
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
} from "../../../../default/js/vendor/utils/dom";
import { tipTip } from "../../../../default/js/vendor/widgets/tiptip";

// The real `GET /api/v1/users` per-row shape (P47) -- `currentUsers`/
// `guestUser` are both populated straight from that endpoint's own
// response (`currentUsers = data.users` / `guestUser = data.users[0]`,
// below), and every `fillUserEdit*` helper reads real User schema
// fields (status/level/email/groups/enabledHigh/nbImagePage/theme/
// language/expand/showNbComments/showNbHits/registration*/lastVisit*).
type UserRow = components["schemas"]["User"];
type UserListResponse =
  operations["userList"]["responses"][200]["content"]["application/json"];

// `selectAllPage`/`selectInvert`'s own real pushed shape -- only `id`/
// `username` are ever read off a `selection` entry. `username` is
// genuinely optional: `selectWholeSet()` (below) pushes id-only
// entries for a whole-database selection (fetching every username
// upfront would be wasteful), lazily backfilled for just the first 50
// via `getFirstSelectionUsernames()` -- callers already defensively
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

const colorIcons = [
  "icon-red",
  "icon-blue",
  "icon-yellow",
  "icon-purple",
  "icon-green",
];
const statusArr = ["webmaster", "admin", "normal", "generic", "guest"];
const levelArr = ["0", "1", "2", "4", "8"];
const kingTemplate = '<p class="icon-king" id="the_king"></p>';
let currentUsers: UserRow[] = [];
let guestId = 0;
let guestUser: UserRow;
let connectedUser = 0;
let groupsArr: [number, string][] = [];
let nbDays = "";
let nbPhotos = "";
let lastUserIndex = -1;
let lastUserId = -1;
let pwgToken = "";
let selection: SelectionEntry[] = [];
let firstUpdate = true;
let totalUsers = 0;
let filterBy = "id DESC";
const pluginsSetFunctions: Record<string, (...args: unknown[]) => unknown> = {};
const pluginsGetFunctions: Record<string, (...args: unknown[]) => unknown> = {};
const pluginsLoad: string[] = [];
const pluginsUsersInfosTable: { content_id: string; users_table: string }[] =
  [];
let ownerUsername = "";
let ownerId = pwg_getPageData<number>("owner");
let perPage = 5;
// Assigned much further down, once groupsArr has its real value (the
// "Startup" sequence's own real dependency order) -- declared here so
// the functions that read it, defined earlier in this file, don't
// forward-reference it (they only ever run after Startup completes).
let groupOptions: GroupOption[] = [];
// Same reasoning as groupOptions above: assigned in the "Startup"
// sequence near the bottom of the file (registerDates.split(",")),
// but read by functions defined much earlier that only ever run once
// Startup has completed.
let registerDates: string[] = [];

const titleMsg = pwg_getPageString(
  'Are you sure you want to delete the user "%s"?',
);
const confirmMsg = pwg_getPageString("Yes, I am sure");
const cancelMsg = pwg_getPageString("No, I have changed my mind");
const strAndOthersTags = pwg_getPageString("and %s others");
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

const registeredStr = pwg_getPageString("Registered");
const lastVisitStr = pwg_getPageString("Last visit");
const datesInfos = pwg_getPageString("between %s and %s");
const userAddedStr = pwg_getPageString("User %s added");
const filteredUsers = pwg_getPageString("<b>%d</b> filtered users");
const filteredUser = pwg_getPageString("<b>%d</b> filtered user");
const historyBaseUrl = pwg_getPageData<string>("u_history");

const statusToStr: Record<string, string> = {
  webmaster: pwg_getPageString("user_status_webmaster"),
  admin: pwg_getPageString("user_status_admin"),
  normal: pwg_getPageString("user_status_normal"),
  generic: pwg_getPageString("user_status_generic"),
  guest: pwg_getPageString("user_status_guest"),
};

const viewSelector = pwg_getPageData<string>("view_selector");
const pagination = String(pwg_getPageData<number>("pagination"));
const connectedUserStatus = pwg_getPageData<string>("connected_user_status");
const hasGroup = pwg_getPageData<string | null>("filter_group");

/*----------------
Escape of pop-in
----------------*/

//get out of pop in via escape key
on(document, "keydown", function (e: Event) {
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- "keydown" always dispatches a real KeyboardEvent; on()'s own handler param is typed generically via the native EventListener interface.
  if ((e as KeyboardEvent).key === "Escape") {
    hideModals();
    closeUserList();
  }
});

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
function openUserList() {
  hideTemporaryMessages();
  fadeIn(document.querySelectorAll("#UserList"));
}

function closeUserList() {
  hideTemporaryMessages();
  setVal(document.querySelectorAll("#result_send_mail_copy_input"), "");
  fadeOut(document.querySelectorAll("#UserList"));
}

function openGuestUserList() {
  hideTemporaryMessages();
  fadeIn(document.querySelectorAll("#GuestUserList"));
}

function closeGuestUserList() {
  hideTemporaryMessages();
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
      resetPasswordModals();
      fadeIn(document.querySelectorAll(".user-property-password-change"));
    },
  );

  on(document.querySelectorAll(".edit-password-cancel"), "click", function () {
    fadeOut(document.querySelectorAll(".user-property-password-change"));
    resetInputPassword();
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
      hideErrorEditUser();
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
    closeUserList,
  );
  on(document.querySelectorAll(".CloseUserList"), "click", closeUserList);

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
    openGuestUserList,
  );
  on(
    document.querySelectorAll(".CloseGuestUserList"),
    "click",
    closeGuestUserList,
  );
  on(
    document.querySelectorAll("#GuestUserList .close-update-button"),
    "click",
    closeGuestUserList,
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
    const password = genPassword();
    setVal(document.querySelectorAll("#edit_user_password"), password);
    setVal(document.querySelectorAll("#edit_user_conf_password"), password);
    hideErrorEditUser();
  });
  on(
    document.querySelectorAll(".AddUserGenPassword span"),
    "click",
    function () {
      const password = genPassword();
      setVal(document.querySelectorAll("#add_user_pass"), password);
      setVal(document.querySelectorAll("#add_user_confpass"), password);
    },
  );
  on(
    document.querySelectorAll(".AddUserSubmit"),
    "click",
    () => void addUser(),
  );
  on(document.querySelectorAll(".AddUserCancel"), "click", addUserClose);
  on(document.querySelectorAll(".CloseAddUser"), "click", addUserClose);

  //open add user pop in
  on(document.querySelectorAll(".add-user-button"), "click", addUserOpen);

  /* Select */

  on(document.querySelectorAll("#selectSet"), "click", function (e: Event) {
    void selectWholeSet();
    e.preventDefault();
    return false;
  });

  on(document.querySelectorAll("#selectAllPage"), "click", function (e: Event) {
    const selectionIds = selection.map((x) => x.id);
    for (const user of currentUsers) {
      if (!selectionIds.includes(user.id)) {
        selection.push({
          id: user.id,
          username: user.username,
        });
      }
    }
    updateSelectionContent();
    e.preventDefault();
    return false;
  });

  on(document.querySelectorAll("#selectNone"), "click", function (e: Event) {
    selection = [];
    updateSelectionContent();
    e.preventDefault();
    return false;
  });

  on(document.querySelectorAll("#selectInvert"), "click", function (e: Event) {
    const selectionIds = selection.map((x) => x.id);
    for (const user of currentUsers) {
      if (selectionIds.includes(user.id)) {
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
    updateSelectionContent();
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
    advancedFilterButtonClick,
  );
  on(
    document.querySelectorAll(".advanced-filter span.icon-cancel"),
    "click",
    advancedFilterHide,
  );
  on(
    document.querySelectorAll(".advanced-filter-select"),
    "change",
    () => void updateUserList(),
  );
  on(
    document.querySelectorAll("#user_search"),
    "input",
    () => void updateUserList(),
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
    setViewSelector("compact");
  });

  on(document.querySelectorAll("#displayLine"), "change", function () {
    setDisplayLine();

    if (hasClass(document.querySelectorAll(".addAlbum"), "input-mode")) {
      hide(document.querySelectorAll(".addAlbum p"));
    }
    setViewSelector("line");
  });

  on(document.querySelectorAll("#displayTile"), "change", function () {
    setDisplayTile();

    if (hasClass(document.querySelectorAll(".addAlbum"), "input-mode")) {
      show(document.querySelectorAll(".addAlbum p"));
    }
    setViewSelector("tile");
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

  if (hasGroup !== null && hasGroup !== "") {
    advancedFilterButtonClick();
    setVal(document.querySelectorAll("select[name='filter_group']"), hasGroup);
    void updateUserList();
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
  const iconUser = document.querySelectorAll("#icon-usr-list-user");
  const iconRegistered = document.querySelectorAll("#icon-usr-list-registered");
  on(document.querySelectorAll("#usr-list-registered"), "click", function () {
    css(iconUser, "display", "none");
    css(iconRegistered, "display", "block");
    attr(iconUser, "class", "icon-down");
    switch (filterBy) {
      case "id DESC":
        attr(iconRegistered, "class", "icon-down");
        filterBy = "id ASC";
        break;
      case "id ASC":
      default:
        attr(iconRegistered, "class", "icon-up");
        filterBy = "id DESC";
        break;
    }
    void updateUserList();
  });
  on(document.querySelectorAll("#usr-list-user"), "click", function () {
    css(iconRegistered, "display", "none");
    css(iconUser, "display", "block");
    attr(iconRegistered, "class", "icon-up");
    switch (filterBy) {
      case "username ASC":
        attr(iconUser, "class", "icon-up");
        filterBy = "username DESC";
        break;
      case "username DESC":
      default:
        attr(iconUser, "class", "icon-down");
        filterBy = "username ASC";
        break;
    }
    void updateUserList();
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

function setViewSelector(view_type: string) {
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

  if (perPage < 10) {
    perPage = 10;
    updatePaginationMenu();
    void updateUserList();

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

function checkboxChange(this: Element) {
  if (attrOf(this, "data-selected") === "1") {
    hide(find(this, "i"));
  } else {
    show(find(this, "i"));
  }
}

function checkboxClick(this: Element) {
  if (attrOf(this, "data-selected") === "1") {
    attr(this, "data-selected", "0");
    hide(find(this, "i"));
  } else {
    attr(this, "data-selected", "1");
    show(find(this, "i"));
  }
}

off(document.querySelectorAll(".user-list-checkbox"), "change");
on(document.querySelectorAll(".user-list-checkbox"), "change", checkboxChange);
off(document.querySelectorAll(".user-list-checkbox"), "click");
on(document.querySelectorAll(".user-list-checkbox"), "click", checkboxClick);

/* ---------------
User edit sliders
----------------*/
const nbImagePageValues = [
  1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22,
  23, 24, 25, 26, 27, 28, 29, 30, 35, 40, 45, 50, 60, 70, 80, 90, 100, 200, 300,
  500, 999,
];
const recentPeriodValues = [
  0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 25,
  30, 40, 50, 60, 80, 99,
];
const recentPeriodInit = 0;
const nbImagePageInit = 0;

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
  return sprintf(nbPhotos, nbImagePageValues[idx]!);
}

function getRecentPeriodInfoFromIdx(idx: number) {
  return sprintf(nbDays, recentPeriodValues[idx]!);
}

/* Photos bar slider */
slider(
  document.querySelectorAll(
    "#UserList .photos-select-bar .slider-bar-container",
  ),
  {
    range: "min",
    min: 0,
    max: nbImagePageValues.length - 1,
    value: nbImagePageInit,
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
        String(nbImagePageValues[ui.value!]!),
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
    max: nbImagePageValues.length - 1,
    value: nbImagePageInit,
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
        String(nbImagePageValues[ui.value!]!),
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
    max: nbImagePageValues.length - 1,
    value: nbImagePageInit,
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
        String(nbImagePageValues[ui.value!]!),
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
    max: recentPeriodValues.length - 1,
    value: recentPeriodInit,
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
        String(recentPeriodValues[ui.value!]!),
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
    max: recentPeriodValues.length - 1,
    value: recentPeriodInit,
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
        String(recentPeriodValues[ui.value!]!),
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
    max: recentPeriodValues.length - 1,
    value: recentPeriodInit,
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
        String(recentPeriodValues[ui.value!]!),
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
const periodInfo = getRecentPeriodInfoFromIdx(0);
html(
  document.querySelectorAll(
    "#permitActionUserList .period-select-bar .recent_period_infos",
  ),
  periodInfo,
);

/* -----------
Pagination
------------*/

let actualPage = 1;
let maxPage = 1;
let nbFilteredUsers = 0;
const pageEllipsis = "<span>...</span>";
const pageItem = '<a data-page="%d">%d</a>';

function moveToPage(page: number) {
  if (page < 1 || page > maxPage) return;
  actualPage = page;
  updatePaginationMenu();
  void updateUserList();
}

on(document.querySelectorAll(".pagination-arrow.rigth"), "click", () => {
  moveToPage(actualPage + 1);
});

on(document.querySelectorAll(".pagination-arrow.left"), "click", () => {
  moveToPage(actualPage - 1);
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

    perPage = parseInt(htmlOf(this) ?? "0");
    actualPage = 1;
    updatePaginationMenu();
    void updateUserList();

    const parent = this.parentElement;
    if (parent !== null) {
      removeClass(find(parent, "a"), "selected-pagination");
    }

    addClass(this, "selected-pagination");
  },
);

function appendPaginationItem(page: number | null = null) {
  const container = document.querySelector(".pagination-item-container");
  if (container === null) return;
  if (page != null) {
    const newTag = parseHtml(pageItem.replace(/%d/g, String(page)))[0]!;
    container.appendChild(newTag);
    if (actualPage === page) {
      addClass(newTag, "actual");
    }
    on(newTag, "click", () => {
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- data() reads a data-* attribute this same app's own setData()/template writes, never adversarial input.
      moveToPage(data(newTag, "page") as number);
    });
  } else {
    container.appendChild(parseHtml(pageEllipsis)[0]!);
  }
}

function updatePaginationItems() {
  document.querySelectorAll(".pagination-item-container a").forEach((el) => {
    el.remove();
  });
  document.querySelectorAll(".pagination-item-container span").forEach((el) => {
    el.remove();
  });

  appendPaginationItem(1);

  if (actualPage > 2) {
    appendPaginationItem();
  }
  if (actualPage !== 1 && actualPage !== maxPage) {
    appendPaginationItem(actualPage);
  }
  if (actualPage < maxPage - 1) {
    appendPaginationItem();
  }
  appendPaginationItem(maxPage);
}

function updatePaginationMenu() {
  maxPage = Math.ceil(nbFilteredUsers / perPage);
  updateArrows();
  updatePaginationItems();
  if (maxPage <= 1) {
    hide(document.querySelectorAll(".pagination-container"));
  } else {
    show(document.querySelectorAll(".pagination-container"));
  }
}

function updateArrows() {
  if (actualPage === 1) {
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
  if (actualPage === maxPage) {
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

function advancedFilterButtonClick() {
  if (
    !hasClass(
      document.querySelectorAll(".advanced-filter"),
      "advanced-filter-open",
    )
  ) {
    advancedFilterShow();
  } else {
    advancedFilterHide();
  }
  // updateUserList();
}

function advancedFilterShow() {
  addClass(
    document.querySelectorAll(".advanced-filter-btn, .advanced-filter"),
    "advanced-filter-open",
  );
}

function advancedFilterHide() {
  removeClass(
    document.querySelectorAll(".advanced-filter-btn, .advanced-filter"),
    "advanced-filter-open",
  );
}

let months: string[] = [];

function getDateStr(date: string) {
  const dateArr = date.split("-");
  const currMonth = months[parseInt(dateArr[1]!) - 1] ?? "";
  return currMonth + " " + dateArr[0]!;
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
            datesInfos,
            getDateStr(registerDatesList[ui.values![0]!]!),
            getDateStr(registerDatesList[ui.values![1]!]!),
          ),
        );
      },
      slide: function (_event: Event, ui: SliderUIParams) {
        html(
          document.querySelectorAll(".advanced-filter .dates-infos"),
          sprintf(
            datesInfos,
            getDateStr(registerDatesList[ui.values![0]!]!),
            getDateStr(registerDatesList[ui.values![1]!]!),
          ),
        );
      },
      stop: function (_event: Event, ui: SliderUIParams) {
        html(
          document.querySelectorAll(".advanced-filter .dates-infos"),
          sprintf(
            datesInfos,
            getDateStr(registerDatesList[ui.values![0]!]!),
            getDateStr(registerDatesList[ui.values![1]!]!),
          ),
        );
        void updateUserList();
      },
    },
  );

  html(
    document.querySelectorAll(".advanced-filter .dates-infos"),
    sprintf(
      datesInfos,
      getDateStr(registerDatesList[0]!),
      getDateStr(registerDatesList[registerDatesList.length - 1]!),
    ),
  );
}
/*------------------
Add User
------------------*/

function genPassword() {
  const characterSet =
    "ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789";

  let i: number;
  let password: string;
  const length = getRandomInt(8, 15);

  password = "";
  for (i = 0; i < length; i++) {
    password += characterSet.charAt(getRandomInt(0, characterSet.length));
  }

  return password;
}

function addUserClose() {
  fadeOut(document.querySelectorAll("#AddUser"), () => {
    css(
      document.querySelectorAll("#AddUser .AddUserErrors"),
      "visibility",
      "hidden",
    );
  });
}

function addUserOpen() {
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
  fillNewUser();
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

function createUserSelectedItem(user: SelectionEntry): Element {
  const template = document.querySelector(".user-selected-item")!;
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- cloning an Element always produces an Element (real DOM guarantee); cloneNode()'s own lib.dom signature just isn't narrowed per-subtype.
  const newElem = template.cloneNode(true) as Element;
  attr(newElem, "data-id", user.id.toString());
  // Non-null: only ever called after the caller's own `typeof ...
  // !== "undefined"` guard confirms `username` is really set.
  html(find(newElem, "p"), user.username!);
  on(find(newElem, "a"), "click", () => {
    selection.splice(
      selection.findIndex((i) => i.id === user.id),
      1,
    );
    updateSelectionContent();
  });
  return newElem;
}

function generateUserSelectedItems() {
  let itemsCreated = 0;
  const others = selection.length - 5;
  document
    .querySelectorAll(".user-selected-list .user-selected-item")
    .forEach((el) => {
      el.remove();
    });
  const list = document.querySelector(".user-selected-list");
  for (let i = 0; i < selection.length && itemsCreated < 5; i++) {
    if (typeof selection[i]!.username !== "undefined") {
      list?.appendChild(createUserSelectedItem(selection[i]!));
      itemsCreated += 1;
    }
  }
  if (others >= 1) {
    html(
      document.querySelectorAll(".selection-other-users"),
      strAndOthersTags.replace("%s", String(others)),
    );
    show(document.querySelectorAll(".selection-other-users"));
  } else {
    hide(document.querySelectorAll(".selection-other-users"));
  }
}

function fillUserSelectedList() {
  let elemsWithUsername = 0;
  for (let i = 0; i < selection.length && elemsWithUsername < 5; i++) {
    if (typeof selection[i]!.username !== "undefined") {
      elemsWithUsername += 1;
    }
  }
  if (elemsWithUsername < 5 && elemsWithUsername !== selection.length) {
    void getFirstSelectionUsernames(generateUserSelectedItems);
  } else {
    generateUserSelectedItems();
  }
}

function updateSelectionContent() {
  const number = selection.length;
  fillUserSelectedList();
  if (number === 0) {
    show(document.querySelectorAll("#forbidAction"));
    hide(document.querySelectorAll(".selection-mode-ul"));
    hide(document.querySelectorAll("#permitActionUserList"));
  } else {
    hide(document.querySelectorAll("#forbidAction"));
    show(document.querySelectorAll("#permitActionUserList"));
    show(document.querySelectorAll(".selection-mode-ul"));
  }
  setSelectedToSelection();
  hide(document.querySelectorAll("#applyActionBlock .infos"));
}

function setSelectedToSelection() {
  if (!is(document.querySelectorAll("#toggleSelectionMode"), ":checked")) {
    return;
  }
  document
    .querySelectorAll(".user-container-wrapper .user-container")
    .forEach((container, index) => {
      const selectionIds = selection.map((x) => x.id);
      if (selectionIds.includes(currentUsers[index]!.id)) {
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
    setSelectedToSelection();
    show(document.querySelectorAll(".in-selection-mode"));
    hide(document.querySelectorAll(".not-in-selection-mode"));

    if (viewSelector === "tile") {
      show(document.querySelectorAll(".user-container-email"));
    }

    if (viewSelector === "compact") {
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

    if (viewSelector === "tile" || viewSelector === "line") {
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

function hideTemporaryMessages() {
  hide(document.querySelectorAll(".update-user-success"));
  hide(document.querySelectorAll("#AddUserSuccess"));
  hide(document.querySelectorAll(".error-msg"));
}

function getGroupNameFromId(id: number) {
  for (const [groupId, groupName] of groupsArr) {
    if (groupId === id) {
      return groupName;
    }
  }
  return "group_id error";
}

function getContainerIndexFromUid(uid: number) {
  for (let i = 0; i < currentUsers.length; i++) {
    if (currentUsers[i]!.id === uid) {
      return i;
    }
  }
  return -1;
}

function hideErrorEditUser() {
  animate(
    document.querySelectorAll("#UserList .EditUserErrors"),
    { opacity: 0 },
    100,
  );
}

function showErrorEditUser() {
  animate(
    document.querySelectorAll("#UserList .EditUserErrors"),
    { opacity: 1 },
    300,
  );
}

function resetInputPassword() {
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

function checkTabs(title_tab_name_id: string) {
  if (pluginsLoad.length > 2) {
    css(document.querySelectorAll(".edit-user-tab-title"), {
      gap: "0px",
      justifyContent: "space-between",
    });
    const countMoresPlugins = pluginsLoad.length - 2;
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
/** Part of `validateAndRegisterPluginTab()`'s own extraction, below. */
function validateContentId(content_id: string): void {
  if (typeof content_id !== "string") {
    throw new TypeError("content_id must be a string.");
  }
  if (!document.getElementById(content_id)) {
    throw new TypeError('HTML element "' + content_id + '" not found.');
  }
  if (document.querySelector(".edit-user-slides #" + content_id)) {
    throw new TypeError("An element with this content_id already exists.");
  }
}

/** Part of `validateAndRegisterPluginTab()`'s own extraction, below. */
function validateUsersTableExclusivity(
  users_table: string | null,
  set_data_function: ((...args: unknown[]) => unknown) | null,
  get_data_function: ((...args: unknown[]) => unknown) | null,
): void {
  if (users_table === null || users_table === "") {
    return;
  }
  if (typeof users_table !== "string") {
    throw new TypeError("users_table must be a string.");
  }
  if (set_data_function || get_data_function) {
    throw new TypeError(
      "users_table must be null if set_data_function or get_data_function is used.",
    );
  }
}

/** Part of `validateAndRegisterPluginTab()`'s own extraction, below. */
function registerPluginDataFunctions(
  name: string,
  set_data_function: ((...args: unknown[]) => unknown) | null,
  get_data_function: ((...args: unknown[]) => unknown) | null,
): void {
  if (set_data_function) {
    if (typeof set_data_function !== "function") {
      throw new TypeError("set_data_function must be a function.");
    }
    pluginsSetFunctions[name + "_set_function"] = set_data_function;
  }

  if (get_data_function) {
    if (typeof get_data_function !== "function") {
      throw new TypeError("get_data_function must be a function.");
    }
    pluginsGetFunctions[name + "_get_function"] = get_data_function;
  }
}

/**
 * `pluginAddTabInUserModal()`'s own upfront validation, extracted to
 * keep that function's cognitive complexity under the sonarjs limit --
 * a pure extraction (same checks, same order, same thrown messages),
 * plus the 2 real registration side effects (`pluginsSetFunctions`/
 * `pluginsGetFunctions`/`pluginsUsersInfosTable`) that only happen once
 * validation passes, exactly as before. Split further into 3 helpers
 * above since the combined body was itself still over the sonarjs
 * cognitive-complexity budget.
 */
function validateAndRegisterPluginTab(
  name: string,
  content_id: string,
  users_table: string | null,
  set_data_function: ((...args: unknown[]) => unknown) | null,
  get_data_function: ((...args: unknown[]) => unknown) | null,
): void {
  if (document.getElementById("name_tab_" + name)) {
    throw new TypeError("An element with this tab_name already exists.");
  }

  validateContentId(content_id);
  validateUsersTableExclusivity(
    users_table,
    set_data_function,
    get_data_function,
  );
  registerPluginDataFunctions(name, set_data_function, get_data_function);

  if (users_table !== null && users_table !== "") {
    pluginsUsersInfosTable.push({ content_id, users_table });
  }
}

function pluginAddTabInUserModal(
  tab_name: string,
  content_id: string,
  users_table: string | null = null,
  set_data_function: ((...args: unknown[]) => unknown) | null = null,
  get_data_function: ((...args: unknown[]) => unknown) | null = null,
) {
  if (typeof tab_name !== "string") {
    throw new TypeError("tab_name must be a string.");
  }
  const name = tab_name.replace(/ /g, "").toLowerCase();

  validateAndRegisterPluginTab(
    name,
    content_id,
    users_table,
    set_data_function,
    get_data_function,
  );

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

  pluginsLoad.push("name_tab_" + name);
  checkTabs("name_tab_" + name);

  if (pluginsLoad.length <= 2) {
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

function resetPasswordModals() {
  hide(document.querySelectorAll(".user-property-password-change"));
  hide(document.querySelectorAll(".user-property-password-change-inputs"));
  hide(document.querySelectorAll("#edit_password_success_change"));
  hide(document.querySelectorAll("#edit_password_result_mail"));
  hide(document.querySelectorAll("#edit_password_result_mail_copy"));
  show(document.querySelectorAll(".user-property-password-choice"));
}

function resetUsernameModals() {
  hide(document.querySelectorAll(".edit-username-success"));
  show(document.querySelectorAll(".user-property-username-change-input"));
}

function resetMainUserModals() {
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

function hideModals() {
  hide(document.querySelectorAll(".user-property-username-change"));
  hide(document.querySelectorAll(".user-property-password-change"));
  hide(document.querySelectorAll(".user-property-main-user-change"));
  resetPasswordModals();
  resetUsernameModals();
  resetMainUserModals();
}

function displayLongString(username: string) {
  const formatedUsername =
    username.length > 20 ? username.slice(0, 17) + "..." : username;
  return formatedUsername;
}

function generateRandomString() {
  const string =
    "abcdefghijkmlnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789";
  let result = "";
  const c = 5;
  for (let i = 0; i < c; i++) {
    // eslint-disable-next-line sonarjs/pseudo-random -- not security-sensitive: this generates a confirmation string the admin types back to confirm changing the main/webmaster user (a misclick guard, not a secret or token), reviewed per this rule's own "make sure it's safe here" intent.
    result += string.charAt(Math.floor(Math.random() * string.length));
  }
  return result;
}

/* ---------------
Who is the king functions (main user)
----------------*/
function openMainUserModal(userToEdit: UserRow) {
  const modal = document.querySelectorAll(".user-property-main-user-change");
  resetMainUserModals();
  html(
    document.querySelectorAll(".main-user-proceed-desc"),
    sprintf(
      mainUserContinue,
      `<b>${displayLongString(userToEdit.username)}</b>`,
      `<b>${displayLongString(ownerUsername)}</b>`,
    ),
  );
  fadeIn(modal);

  // set event
  const proceedBtn = document.querySelectorAll(".main-user-btn-proceed");
  off(proceedBtn, "click");
  on(proceedBtn, "click", function () {
    const genString = generateRandomString();
    html(
      document.querySelectorAll(".main-user-rewrite-desc"),
      sprintf(mainUserRewrite, `<b>${genString}</b>`) + " :",
    );
    hide(document.querySelectorAll(".main-user-proceed"));
    fadeIn(document.querySelectorAll(".main-user-rewrite"));
    eventCheckStringMainUser(userToEdit.username, userToEdit.id, genString);
  });

  const cancelBtn = document.querySelectorAll(
    ".user-property-main-user-cancel, #main_user_success_close",
  );
  off(cancelBtn, "click");
  on(cancelBtn, "click", function () {
    fadeOut(modal);
  });
}

function setMainUserSuccess() {
  const indexKey = currentUsers.findIndex((u) => u.id === ownerId);
  const [new_main] = find(
    document.querySelectorAll(
      '.user-container[key="' + String(indexKey) + '"]',
    ),
    ".user-container-username",
  );
  let king = document.querySelector("#the_king");
  if (king === null) {
    king = parseHtml(kingTemplate)[0]!;
    attr(king, "title", mainUserStr);
    tipTip(king);
  }
  new_main?.appendChild(king);
  hide(document.querySelectorAll(".delete-user-button"));
  hide(document.querySelectorAll(".main-user-validate"));
  fadeIn(document.querySelectorAll(".main-user-success"));
}

function eventCheckStringMainUser(
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
          `<b>${displayLongString(ownerUsername)}</b>`,
          `<b>${displayLongString(new_main_username)}</b>`,
        ),
      );
      html(
        document.querySelectorAll(".main-user-success-desc"),
        sprintf(mainUserSuccess, displayLongString(new_main_username)),
      );
      hide(document.querySelectorAll(".main-user-rewrite"));
      fadeIn(document.querySelectorAll(".main-user-validate"));
      eventValidateMainUser(new_main_username, new_main_id);
    }
  });
}

function eventValidateMainUser(new_main_username: string, user_id: number) {
  const validateBtn = document.querySelectorAll(".main-user-btn-validate");
  off(validateBtn, "click");
  on(validateBtn, "click", function () {
    void setMainUser(user_id, new_main_username);
  });
}

/*-----------------------
Generate User Containers
-----------------------*/
function userContainerClick(this: Element) {
  if (!isSelectionMode()) {
    return;
  }
  const containerCheckbox = find(this, ".user-list-checkbox")[0]!;
  // Non-null: `key` is always a real, in-bounds index into currentUsers
  // for a real .UsernameBlock container.
  const currUser = currentUsers[parseInt(attrOf(this, "key")!)]!;
  if (attrOf(containerCheckbox, "data-selected") === "1") {
    attr(containerCheckbox, "data-selected", "0");
    hide(find(containerCheckbox, "i"));
    removeClass(this, "container-selected");
    selection = selection.filter((elem) => elem.id !== currUser.id);
  } else {
    attr(containerCheckbox, "data-selected", "1");
    show(find(containerCheckbox, "i"));
    addClass(this, "container-selected");
    selection.push({ id: currUser.id, username: currUser.username });
  }
  updateSelectionContent();
}

function generateGroups(container: Element, groups: number[]) {
  const groupsContainer = find(container, ".user-container-groups")[0]!;
  html(groupsContainer, "");
  if (groups.length >= 1) {
    const template = document.querySelector("#template .group-primary")!;
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- cloning an Element always produces an Element (real DOM guarantee); cloneNode()'s own lib.dom signature just isn't narrowed per-subtype.
    const primaryGrp = template.cloneNode(true) as Element;
    html(primaryGrp, getGroupNameFromId(groups[0]!));
    addClass(primaryGrp, colorIcons[groups[0]! % 5]!);
    groupsContainer.appendChild(primaryGrp);
  }
  if (groups.length >= 2) {
    const template = document.querySelector("#template .group-primary")!;
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- cloning an Element always produces an Element (real DOM guarantee); cloneNode()'s own lib.dom signature just isn't narrowed per-subtype.
    const primaryGrp = template.cloneNode(true) as Element;
    html(primaryGrp, getGroupNameFromId(groups[1]!));
    addClass(primaryGrp, colorIcons[groups[1]! % 5]!);
    groupsContainer.appendChild(primaryGrp);
  }
  if (groups.length >= 3) {
    const template = document.querySelector("#template .group-bonus")!;
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- cloning an Element always produces an Element (real DOM guarantee); cloneNode()'s own lib.dom signature just isn't narrowed per-subtype.
    const bonusGrp = template.cloneNode(true) as Element;
    html(bonusGrp, "...");
    addClass(bonusGrp, colorIcons[groups[2]! % 5]!);
    addClass(bonusGrp, "tiptip");
    let groupsInTitle = "";
    for (let i = 2; i < groups.length; i++) {
      groupsInTitle += getGroupNameFromId(groups[i]!) + ", ";
    }
    groupsInTitle = groupsInTitle.substring(0, groupsInTitle.length - 2);
    attr(bonusGrp, "title", groupsInTitle);
    groupsContainer.appendChild(bonusGrp);
  }
}

function getInitials(username: string): string {
  const words = username.toUpperCase().split(" ");
  let res = words[0]![0]!;

  const [, secondWord] = words;
  if (secondWord !== undefined && secondWord.length > 0) {
    res += secondWord[0]!;
  }
  return res;
}

function fillContainerUserInfo(container: Element, user_index: number) {
  const user = currentUsers[user_index]!;
  const registrationDates = (user.registrationDate ?? "").split(" ");
  attr(container, "key", String(user_index));
  html(find(container, ".user-container-username span"), user.username);
  if (user.id === ownerId && document.getElementById("the_king") === null) {
    const kingToDisplay = parseHtml(kingTemplate)[0]!;
    attr(kingToDisplay, "title", mainUserStr);
    tipTip(kingToDisplay);
    find(container, ".user-container-username")[0]?.appendChild(kingToDisplay);
  }
  const initialToFill = getInitials(user.username);
  const initialSpan = find(container, ".user-container-initials span")[0]!;
  html(initialSpan, initialToFill);
  addClass(initialSpan, colorIcons[user.id % 5]!);
  if (initialToFill.length > 1) {
    addClass(initialSpan, "small");
  } else {
    removeClass(initialSpan, "small");
  }
  html(
    find(container, ".user-container-status span"),
    // Non-null: every real, listed user has a real status; `null` is
    // only a schema allowance for an edge case this admin listing
    // doesn't actually surface.
    statusToStr[user.status!]!,
  );
  html(find(container, ".user-container-email span"), user.email ?? "");
  generateGroups(container, user.groups);
  html(
    find(container, ".user-container-registration-date"),
    registrationDates[0] ?? "",
  );
  html(
    find(container, ".user-container-registration-time"),
    registrationDates[1] ?? "",
  );
  html(
    find(container, ".user-container-registration-date-since"),
    user.registrationDateSince,
  );
}

function generateUserList() {
  document
    .querySelectorAll("#user-table-content .user-container")
    .forEach((el) => {
      el.remove();
    });
  const wrapper = document.querySelector(
    "#user-table-content .user-container-wrapper",
  )!;
  const template = document.querySelector("#template .user-container")!;
  for (let i = 0; i < currentUsers.length; i++) {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- cloning an Element always produces an Element (real DOM guarantee); cloneNode()'s own lib.dom signature just isn't narrowed per-subtype.
    const newContainer = template.cloneNode(true) as Element;
    fillContainerUserInfo(newContainer, i);
    wrapper.appendChild(newContainer);
  }
  const checkboxes = document.querySelectorAll(
    ".user-container .user-list-checkbox",
  );
  off(checkboxes, "change");
  on(checkboxes, "change", checkboxChange);
  off(checkboxes, "click");
  on(document.querySelectorAll(".user-container"), "click", userContainerClick);
}

function copyToClipboard(toCopy: string) {
  void navigator.clipboard.writeText(toCopy);
}
/*---------------------
Fill the pop-in values
---------------------*/

function getStatusIndex(status: string | null) {
  for (let i = 0; i < statusArr.length; i++) {
    // eslint-disable-next-line sonarjs/different-types-comparison -- real bug in the rule, confirmed via a minimal repro: it flags any `T | undefined` (here, `statusArr[i]`, widened by `noUncheckedIndexedAccess`) compared against a `T | null` value as "always false", ignoring that the shared `string` branch genuinely overlaps and can match.
    if (statusArr[i] === status) {
      return i;
    }
  }
  return 0;
}

function getLevelIndex(level: number | null) {
  // levelArr holds string literals; /api/v1/users returns a real
  // JSON number for `level`, so this needs a loose match rather than
  // the strict one statusArr's own still-string `status` comparison
  // above can keep using.
  for (let i = 0; i < levelArr.length; i++) {
    const levelStr = levelArr[i]!;
    if (levelStr === String(level)) {
      return i;
    }
  }
  return 0;
}

function setSelectedGroups(groups: number[]) {
  for (const groupOption of groupOptions) {
    groupOption.isSelected = groups.includes(groupOption.value);
  }
}

function fillUserEditSummary(
  userToEdit: UserRow,
  popIn: Element,
  isGuest: boolean,
) {
  if (isGuest) {
    const initialSpan = find(popIn, ".user-property-initials span");
    removeClass(initialSpan, colorIcons.join(" "));
    addClass(initialSpan, colorIcons[userToEdit.id % 5]!);
  } else {
    const initialToFill = getInitials(userToEdit.username);
    const initialSpan = find(popIn, ".user-property-initials span");
    html(initialSpan, initialToFill);
    removeClass(initialSpan, colorIcons.join(" "));
    addClass(initialSpan, colorIcons[userToEdit.id % 5]!);
    if (initialToFill.length > 1) {
      addClass(initialSpan, "small");
    } else {
      removeClass(initialSpan, "small");
    }
  }
  const usernameSpan = find(popIn, ".user-property-username span:first-child");
  html(usernameSpan, userToEdit.username);
  tipTip(usernameSpan, { content: userToEdit.username });

  if (userToEdit.id === connectedUser || userToEdit.id === 1) {
    show(find(popIn, ".user-property-username .edit-username-specifier"));
  } else {
    hide(find(popIn, ".user-property-username .edit-username-specifier"));
  }
  setVal(
    find(popIn, ".user-property-username-change input"),
    userToEdit.username,
  );
  setVal(find(popIn, ".user-property-password-change input"), "");
  attr(
    find(popIn, ".user-property-permissions a"),
    "href",
    `admin.php?page=user_perm&user_id=${userToEdit.id}`,
  );
  html(
    find(popIn, ".user-property-register"),
    userToEdit.registrationDateString,
  );
  tipTip(find(popIn, ".user-property-register"), {
    content: `${registeredStr}<br />${userToEdit.registrationDateSince}`,
  });
  html(find(popIn, ".user-property-last-visit"), userToEdit.lastVisitString);
  tipTip(find(popIn, ".user-property-last-visit"), {
    content: `${lastVisitStr}<br />${userToEdit.lastVisitSince}`,
  });
  attr(
    find(popIn, ".user-property-history a"),
    "href",
    historyBaseUrl + String(userToEdit.id),
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

function fillUserEditProperties(userToEdit: UserRow, popIn: Element) {
  const statusIndex = getStatusIndex(userToEdit.status);
  const levelIndex = getLevelIndex(userToEdit.level);
  const currentGroupSelectize =
    userToEdit.id === guestId ? groupGuestSelectize : groupSelectize;

  setVal(find(popIn, ".user-property-email input"), userToEdit.email ?? "");
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- verified by the selector: only <option> elements can match "select option".
  const statusOption = find(popIn, ".user-property-status select option")[
    statusIndex
  ] as HTMLOptionElement | undefined;
  if (statusOption !== undefined) statusOption.selected = true;
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- verified by the selector: only <option> elements can match "select option".
  const levelOption = find(popIn, ".user-property-level select option")[
    levelIndex
  ] as HTMLOptionElement | undefined;
  if (levelOption !== undefined) levelOption.selected = true;
  setVal(
    find(popIn, ".photos-select-bar input"),
    String(userToEdit.recentPeriod ?? ""),
  );
  setSelectedGroups(userToEdit.groups);
  currentGroupSelectize.clear();
  currentGroupSelectize.load(function (
    callback: (data: GroupOption[]) => void,
  ) {
    callback(groupOptions);
  });
  groupOptions
    .filter((group) => group.isSelected)
    .forEach((group) => {
      currentGroupSelectize.addItem(group.value);
    });
  attr(
    find(popIn, '.user-list-checkbox[name="hd_enabled"]'),
    "data-selected",
    userToEdit.enabledHigh ? "1" : "0",
  );
}

function fillUserEditPreferences(userToEdit: UserRow, popIn: Element) {
  const sliderKeyPhotos = getSliderKeyFromValue(
    parseInt(String(userToEdit.nbImagePage)),
    nbImagePageValues,
  );
  const sliderKeyPeriod = getSliderKeyFromValue(
    parseInt(String(userToEdit.recentPeriod)),
    recentPeriodValues,
  );

  slider(
    find(popIn, ".photos-select-bar .slider-bar-container"),
    "option",
    "value",
    sliderKeyPhotos,
  );
  find(popIn, ".user-property-theme select option").forEach((option) => {
    // eslint-disable-next-line sonarjs/different-types-comparison -- same rule limitation as getStatusIndex() above: `val()`'s real `string | undefined` return type compared against a real `string | null` field is not "always false", the rule just mishandles the undefined/null mismatch.
    if (val(option) === userToEdit.theme) {
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- verified by the selector: only <option> elements can match "select option".
      (option as HTMLOptionElement).selected = true;
    }
  });
  find(popIn, ".user-property-lang select option").forEach((option) => {
    // eslint-disable-next-line sonarjs/different-types-comparison -- same rule limitation as getStatusIndex() above.
    if (val(option) === userToEdit.language) {
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- verified by the selector: only <option> elements can match "select option".
      (option as HTMLOptionElement).selected = true;
    }
  });
  slider(
    find(popIn, ".period-select-bar .slider-bar-container"),
    "option",
    "value",
    sliderKeyPeriod,
  );
  attr(
    find(popIn, '.user-list-checkbox[name="expand_all_albums"]'),
    "data-selected",
    userToEdit.expand ? "1" : "0",
  );
  attr(
    find(popIn, '.user-list-checkbox[name="show_nb_comments"]'),
    "data-selected",
    userToEdit.showNbComments ? "1" : "0",
  );
  attr(
    find(popIn, '.user-list-checkbox[name="show_nb_hits"]'),
    "data-selected",
    userToEdit.showNbHits ? "1" : "0",
  );
}

function fillUserEditUpdate(userToEdit: UserRow, popIn: Element) {
  const updateBtn = find(popIn, ".update-user-button");
  off(updateBtn, "click");
  on(
    updateBtn,
    "click",
    () =>
      void (userToEdit.id === guestId ? updateGuestInfo() : updateUserInfo()),
  );
  const usernameValidate = find(popIn, ".edit-username-validate");
  off(usernameValidate, "click");
  on(usernameValidate, "click", () => void updateUserUsername());
  const editModalPassword = find(popIn, "#edit_modal_password");
  off(editModalPassword, "click");
  on(editModalPassword, "click", function () {
    hide(document.querySelectorAll(".user-property-password-choice"));
    fadeIn(document.querySelectorAll(".user-property-password-change-inputs"));
  });
  if (userToEdit.email !== null && userToEdit.email !== "") {
    const sendPasswordLink = find(popIn, "#send_password_link");
    removeClass(sendPasswordLink, "unavailable tiptip");
    attr(sendPasswordLink, "title", "");
    off(sendPasswordLink, "click");
    on(sendPasswordLink, "click", function () {
      void sendLinkPassword(
        userToEdit.email,
        userToEdit.username,
        userToEdit.id,
        true,
      );
    });
    off(sendPasswordLink, "mouseenter mouseleave focus blur");
  } else {
    const sendPasswordLink = find(popIn, "#send_password_link");
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
  const copyPasswordLink = find(popIn, "#copy_password_link");
  off(copyPasswordLink, "click");
  on(copyPasswordLink, "click", function () {
    const inputValue = val(
      document.querySelectorAll("#result_send_mail_copy_input"),
    );
    if (inputValue === "") {
      void sendLinkPassword(
        userToEdit.email,
        userToEdit.username,
        userToEdit.id,
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
      resetPasswordModals();
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
  const passwordValidate = find(popIn, ".edit-password-validate");
  off(passwordValidate, "click");
  on(passwordValidate, "click", function () {
    const errDiv = document.querySelectorAll("#UserList .EditUserErrors");
    const inputPassword = val(document.querySelectorAll("#edit_user_password"));
    const inputConfirmPassword = val(
      document.querySelectorAll("#edit_user_conf_password"),
    );

    if (inputPassword === "" || inputConfirmPassword === "") {
      html(errDiv, missingField);
      showErrorEditUser();
    } else if (inputPassword !== inputConfirmPassword) {
      html(errDiv, noMatchPassword);
      showErrorEditUser();
    } else {
      void updateUserPassword();
    }
  });
  const deleteBtn = find(popIn, ".delete-user-button");
  off(deleteBtn, "click");
  on(deleteBtn, "click", function () {
    confirm({
      title: titleMsg.replace("%s", userToEdit.username),
      content: "",
      buttons: {
        confirm: {
          text: confirmMsg,
          btnClass: "btn-red",
          action: function () {
            void deleteUser(userToEdit.id);
          },
        },
        cancel: {
          text: cancelMsg,
        },
      },
      ...jConfirmConfirmOptions,
    });
  });
}

/** Part of `fillUserEditPermissions()`'s own extraction, below. */
function applyFullEditPermissions(popIn: Element): void {
  show(find(popIn, ".delete-user-button"));
  show(find(popIn, ".user-property-password.edit-password"));
  removeAttr(
    find(popIn, ".user-property-email .user-property-input"),
    "disabled",
  );
  removeClass(
    find(popIn, ".user-property-status .user-property-select"),
    "notClickable",
  );
  show(find(popIn, ".user-property-username .edit-username"));
}

/** Part of `fillUserEditPermissions()`'s own extraction, below. */
function applyNoEditPermissions(popIn: Element): void {
  hide(find(popIn, ".delete-user-button"));
  hide(find(popIn, ".user-property-password.edit-password"));
  attr(
    find(popIn, ".user-property-email .user-property-input"),
    "disabled",
    "disabled",
  );
  addClass(
    find(popIn, ".user-property-status .user-property-select"),
    "notClickable",
  );
  hide(find(popIn, ".user-property-username .edit-username"));
}

/**
 * Part of `fillUserEditPermissions()`'s own extraction, below --
 * the "I'm not the owner, you need to test my permissions" branch.
 */
function applyPermissionsWhenNotOwner(
  userToEdit: UserRow,
  popIn: Element,
): void {
  if (isOwner(userToEdit.id)) {
    // I want to edit the owner but I'm not the owner (No matter my status)
    applyNoEditPermissions(popIn);
  } else {
    applyFullEditPermissions(popIn);
  }

  if (
    (userToEdit.status === connectedUserStatus &&
      connectedUserStatus === "webmaster" &&
      !isOwner(userToEdit.id)) ||
    (userToEdit.status === "admin" && connectedUserStatus === "webmaster")
  ) {
    // I'm a webmaster editing an equal-or-lower-ranked user (same
    // status as me, or an admin) -- I can do whatever I want.
    applyFullEditPermissions(popIn);
  } else if (
    userToEdit.status === connectedUserStatus &&
    connectedUserStatus === "admin"
  ) {
    // I have the same status than the user I want to edit and I'm an admin, I can do whatever I want but edit the status
    hide(find(popIn, ".delete-user-button"));
    show(find(popIn, ".user-property-password.edit-password"));
    removeAttr(
      find(popIn, ".user-property-email .user-property-input"),
      "disabled",
    );
    removeClass(
      find(popIn, ".user-property-username .edit-username"),
      "notClickable",
    );
    addClass(
      find(popIn, ".user-property-status .user-property-select"),
      "notClickable",
    );
  } else if (
    userToEdit.status === "webmaster" &&
    connectedUserStatus === "admin"
  ) {
    // I'm admin and I want to edit webmaster
    applyNoEditPermissions(popIn);
  }
}

/**
 * Part of `fillUserEditPermissions()`'s own extraction, below --
 * "I'm not the connected user" (editing someone else's profile).
 */
function applyPermissionsForOtherUser(
  userToEdit: UserRow,
  popIn: Element,
): void {
  if (isOwner(connectedUser)) {
    // I'm the owner, I can do whatever I want. No need to test, I am GOD here
    applyFullEditPermissions(popIn);
  } else {
    applyPermissionsWhenNotOwner(userToEdit, popIn);
  }
}

function fillUserEditPermissions(userToEdit: UserRow, popIn: Element) {
  if (userToEdit.id !== connectedUser) {
    applyPermissionsForOtherUser(userToEdit, popIn);
  } else {
    // I'm the connected user, I can do whatever I want on my profile but kill myself (Suicide is not allowed) and edit my status
    hide(find(popIn, ".delete-user-button"));
    show(find(popIn, ".user-property-password.edit-password"));
    removeAttr(
      find(popIn, ".user-property-email .user-property-input"),
      "disabled",
    );
    addClass(
      find(popIn, ".user-property-status .user-property-select"),
      "notClickable",
    );
    show(find(popIn, ".user-property-username .edit-username"));
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

function isOwner(user_id: number) {
  return user_id === ownerId;
}

function fillUserEdit(userToEdit: UserRow) {
  const popIn = document.querySelector("#UserList")!;
  fillUserEditSummary(userToEdit, popIn, false);
  fillUserEditProperties(userToEdit, popIn);
  fillUserEditPreferences(userToEdit, popIn);
  fillUserEditUpdate(userToEdit, popIn);
  fillUserEditPermissions(userToEdit, popIn);
  fillWhoIsTheKing(userToEdit, popIn);

  // show/hide password button depending on permissions

  // plugins get function
  if (Object.keys(pluginsGetFunctions).length > 0) {
    Object.entries(pluginsGetFunctions).forEach((f) => {
      f[1]();
    });
  }
  const keyUserToEdit = Object.keys(userToEdit);
  // pluginAddTabInUserModal's own extension point needs dynamic-by-name field access into a real UserRow; the string keys it's called with come from each plugin's own registration, not user input.
  const userToEditRecord = userToEdit as Record<string, unknown>;
  pluginsUsersInfosTable.forEach((i) => {
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

function fillGuestEdit() {
  const userToEdit = guestUser;
  const popIn = document.querySelector(".GuestUserListPopInContainer")!;
  fillUserEditSummary(userToEdit, popIn, true);
  fillUserEditProperties(userToEdit, popIn);
  fillUserEditPreferences(userToEdit, popIn);
  fillUserEditUpdate(userToEdit, popIn);
}

function fillNewUser() {
  // When you want to add an user: privacy level and groups
  // by default are the same as Guest, not status
  const addUserPopIn = document.querySelector("#AddUser")!;
  const statusIndex = getStatusIndex("normal");
  const levelIndex = getLevelIndex(guestUser.level);
  setSelectedGroups(guestUser.groups);
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
  )[statusIndex] as HTMLOptionElement | undefined;
  if (statusOption !== undefined) statusOption.selected = true;
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- verified by the selector: only <option> elements can match "select option".
  const levelOption = find(addUserPopIn, ".user-property-level select option")[
    levelIndex
  ] as HTMLOptionElement | undefined;
  if (levelOption !== undefined) levelOption.selected = true;
  attr(
    find(addUserPopIn, '.user-list-checkbox[name="hd_enabled"]'),
    "data-selected",
    guestUser.enabledHigh ? "1" : "0",
  );
}

function fillWhoIsTheKing(userToEdit: UserRow, popIn: Element) {
  const whoIsTheKing = find(popIn, "#who_is_the_king")[0]!;
  // By default I'm an admin and I only see who is the Main User
  removeClass(
    whoIsTheKing,
    "princes-of-this-piwigo king-of-this-piwigo can-change",
  );
  addClass(whoIsTheKing, "royal-court-of-this-piwigo cannot-change");
  attr(whoIsTheKing, "title", mainAskWebmaster);
  tipTip(whoIsTheKing);
  off(whoIsTheKing, "click");
  // I'm an webmaster
  if (connectedUserStatus === "webmaster") {
    // check user to edit status
    if (userToEdit.status === "webmaster") {
      removeClass(
        whoIsTheKing,
        "royal-court-of-this-piwigo king-of-this-piwigo cannot-change",
      );
      addClass(whoIsTheKing, "princes-of-this-piwigo can-change");
      attr(whoIsTheKing, "title", mainUserSet);
      tipTip(whoIsTheKing);
      if (!isOwner(userToEdit.id)) {
        off(whoIsTheKing, "click");
        on(whoIsTheKing, "click", function () {
          openMainUserModal(userToEdit);
        });
      }
    } else {
      // user to edit is not a webmaster, so he cannot be set as a main user
      removeClass(whoIsTheKing, "princes-of-this-piwigo king-of-this-piwigo");
      addClass(whoIsTheKing, "royal-court-of-this-piwigo");
      attr(whoIsTheKing, "title", mainUserUpgradeWebmaster);
      tipTip(whoIsTheKing);
    }
  }

  // Main User also the King
  if (isOwner(userToEdit.id)) {
    removeClass(
      whoIsTheKing,
      "princes-of-this-piwigo royal-court-of-this-piwigo can-change",
    );
    addClass(whoIsTheKing, "king-of-this-piwigo cannot-change");
    attr(whoIsTheKing, "title", mainUserStr);
    tipTip(whoIsTheKing);
  }
}

/*-------------------
Fill data for setInfo
-------------------*/

function fillAjaxDataFromProperties(
  ajaxData: Record<string, unknown>,
  popIn: Element,
) {
  const groupsSelected = find(
    popIn,
    ".user-property-group .selectize-input .item",
  ).map((el) => parseInt(attrOf(el, "data-value")!));
  ajaxData["email"] = val(find(popIn, ".user-property-email input"));
  if (
    connectedUserStatus === "webmaster" ||
    (connectedUserStatus === "admin" &&
      val(find(popIn, ".user-property-status select")) !== "webmaster" &&
      val(find(popIn, ".user-property-status select")) !== "admin")
  ) {
    ajaxData["status"] = val(find(popIn, ".user-property-status select"));
  }
  ajaxData["level"] = Number(val(find(popIn, ".user-property-level select")));
  ajaxData["groupIds"] = groupsSelected.length === 0 ? [-1] : groupsSelected;
  ajaxData["enabledHigh"] =
    attrOf(
      find(popIn, '.user-list-checkbox[name="hd_enabled"]'),
      "data-selected",
    ) === "1"
      ? true
      : false;
  return ajaxData;
}

function fillAjaxDataFromPreferences(
  ajaxData: Record<string, unknown>,
  popIn: Element,
) {
  ajaxData["theme"] = val(find(popIn, ".user-property-theme select"));
  ajaxData["language"] = val(find(popIn, ".user-property-lang select"));
  ajaxData["nbImagePage"] =
    nbImagePageValues[
      slider(
        find(popIn, ".photos-select-bar .slider-bar-container"),
        "option",
        "value",
      )!
    ];
  ajaxData["recentPeriod"] =
    recentPeriodValues[
      slider(
        find(popIn, ".period-select-bar .slider-bar-container"),
        "option",
        "value",
      )!
    ];
  ajaxData["expand"] =
    attrOf(
      find(popIn, '.user-list-checkbox[name="expand_all_albums"]'),
      "data-selected",
    ) === "1"
      ? true
      : false;
  ajaxData["showNbComments"] =
    attrOf(
      find(popIn, '.user-list-checkbox[name="show_nb_comments"]'),
      "data-selected",
    ) === "1"
      ? true
      : false;
  ajaxData["showNbHits"] =
    attrOf(
      find(popIn, '.user-list-checkbox[name="show_nb_hits"]'),
      "data-selected",
    ) === "1"
      ? true
      : false;
  return ajaxData;
}

function fillAjaxDataFromContainer(
  ajaxData: Record<string, unknown>,
  popIn: Element,
) {
  const withProperties = fillAjaxDataFromProperties(ajaxData, popIn);
  return fillAjaxDataFromPreferences(withProperties, popIn);
}

/*----------------
Ajax Requests
----------------*/

async function getFirstSelectionUsernames(callback: () => void): Promise<void> {
  const firstIds = selection.slice(0, 50).map((x) => x.id);
  try {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/utils/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
    const response = (await ajax({
      url: "api/v1/users",
      type: "GET",
      data: {
        order: "id",
        userIds: firstIds,
        exclude: [guestId],
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

async function selectWholeSet(): Promise<void> {
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
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/utils/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
    const response = (await ajax({
      url: "api/v1/users",
      type: "GET",
      data: {
        order: "id",
        page: actualPage - 1,
        perPage: 0,
        exclude: [guestId],
        status:
          filterStatus !== undefined && filterStatus !== ""
            ? [filterStatus]
            : [],
        groupIds:
          filterGroup !== undefined && filterGroup !== "" ? [filterGroup] : [],
        minLevel: filterLevel,
        maxLevel: filterLevel,
        minRegister:
          registerDates[
            slider(
              document.querySelectorAll(
                ".dates-select-bar .slider-bar-container",
              ),
              "option",
              "values",
            )![0]!
          ],
        maxRegister:
          registerDates[
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
    updateSelectionContent();
  } catch {
    hide(document.querySelectorAll("#checkActions .loading"));
  }
}

async function updateUserUsername(): Promise<void> {
  const popInContainer = document.querySelector("#UserList")!;
  const ajaxData: Record<string, unknown> = {};
  const newUsername = String(
    val(find(popInContainer, ".user-property-input-username")),
  );
  ajaxData["username"] = newUsername;
  if (newUsername.replace(/\s/g, "").length === 0) {
    const failEl = document.querySelectorAll(".update-user-fail");
    html(failEl, fieldNotEmpty);
    fadeIn(failEl);
    delay(failEl, 1500);
    fadeOut(failEl, 2500);
    return;
  }
  try {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/utils/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
    const response = (await ajax({
      url: "api/v1/users/" + String(lastUserId),
      type: "PATCH",
      headers: { "X-CSRF-Token": pwgToken },
      json: ajaxData,
      dataType: "json",
    })) as operations["userUpdate"]["responses"][200]["content"]["application/json"];
    if (lastUserIndex !== -1) {
      // "username" may be missing from a defensive-fallback response
      // (the row couldn't be re-fetched right after the update) --
      // the value we just successfully submitted is still correct.
      const updatedUsername =
        "username" in response ? response.username : newUsername;
      currentUsers[lastUserIndex]!.username = updatedUsername;
      html(
        document.querySelectorAll(
          "#UserList .user-property-username .edit-username-title",
        ),
        updatedUsername,
      );
      html(
        document.querySelectorAll("#UserList .user-property-initials span"),
        getInitials(updatedUsername),
      );
      fillContainerUserInfo(
        document.querySelectorAll("#user-table-content .user-container")[
          lastUserIndex
        ]!,
        lastUserIndex,
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

async function updateUserPassword(): Promise<void> {
  const popInContainer = document.querySelector("#UserList")!;
  const ajaxData: Record<string, unknown> = {};
  const newPassword = String(
    val(find(popInContainer, ".user-property-input-password")),
  );
  ajaxData["password"] = newPassword;
  try {
    await ajax({
      url: "api/v1/users/" + String(lastUserId),
      type: "PATCH",
      headers: { "X-CSRF-Token": pwgToken },
      json: ajaxData,
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
        resetInputPassword();
      },
    );
  } catch {
    // No error handling before conversion either -- a failure here
    // silently leaves the password change form open.
  }
}

async function updateUserInfo(): Promise<void> {
  //Show spinner
  const btnIcon = document.querySelectorAll(".update-user-button i");
  removeClass(btnIcon, "icon-floppy");
  addClass(btnIcon, "icon-spin6 animate-spin");
  addClass(document.querySelectorAll(".update-user-button"), "unclickable");
  const popInContainer = document.querySelector(".UserListPopInContainer")!;
  let ajaxData: Record<string, unknown> = {};
  if (pluginsUsersInfosTable.length > 0) {
    const keyCurrentUsers = Object.keys(currentUsers[lastUserIndex]!);
    pluginsUsersInfosTable.forEach((i) => {
      if (keyCurrentUsers.includes(i.users_table)) {
        ajaxData[i.users_table] = val(
          document.querySelectorAll("#" + i.content_id),
        );
      } else {
        console.error(i.users_table, " doesn't exist in USER_INFOS_TABLE");
      }
    });
  }

  ajaxData = fillAjaxDataFromContainer(ajaxData, popInContainer);
  fadeOut(document.querySelectorAll("#UserList .update-user-fail"));
  fadeOut(document.querySelectorAll("#UserList .update-user-success"));
  try {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/utils/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
    const resultUser = (await ajax({
      url: "api/v1/users/" + String(lastUserId),
      type: "PATCH",
      headers: { "X-CSRF-Token": pwgToken },
      json: ajaxData,
      dataType: "json",
    })) as operations["userUpdate"]["responses"][200]["content"]["application/json"];
    if (lastUserIndex !== -1) {
      currentUsers[lastUserIndex] = {
        ...currentUsers[lastUserIndex]!,
        ...resultUser,
      };
      fillContainerUserInfo(
        document.querySelectorAll("#user-table-content .user-container")[
          lastUserIndex
        ]!,
        lastUserIndex,
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
    if (Object.keys(pluginsGetFunctions).length > 0) {
      Object.entries(pluginsSetFunctions).forEach((f) => {
        f[1]();
      });
    }

    // update who is the king -- `resultUser` may be a defensive
    // fallback (`{id}` only, missing `.status`); the merged
    // `currentUsers` entry (old data overlaid with whatever the
    // response did carry) is always a real, complete UserRow.
    fillWhoIsTheKing(
      lastUserIndex !== -1 ? currentUsers[lastUserIndex]! : guestUser,
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

async function getGuestInfo(): Promise<void> {
  try {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/utils/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
    const response = (await ajax({
      url: "api/v1/users",
      type: "GET",
      data: {
        userIds: [guestId],
      },
      dataType: "json",
    })) as UserListResponse;
    if (response.users.length) {
      guestUser = response.users[0]!;
      fillGuestEdit();
    }
  } catch {
    // No error handling before conversion either.
  }
}

async function getUserInfo(
  uid: number,
  callback: (() => void) | null = null,
): Promise<void> {
  try {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/utils/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
    const response = (await ajax({
      url: "api/v1/users",
      type: "GET",
      data: {
        userIds: [uid],
      },
      dataType: "json",
    })) as UserListResponse;
    if (response.users.length) {
      const resultUser = response.users[0]!;
      fillUserEdit(resultUser);
      callback?.();
    }
  } catch {
    // No error handling before conversion either.
  }
}

async function updateGuestInfo(): Promise<void> {
  //Show spinner
  const btnIcon = document.querySelectorAll(".update-user-button i");
  removeClass(btnIcon, "icon-floppy");
  addClass(btnIcon, "icon-spin6 animate-spin");
  addClass(document.querySelectorAll(".update-user-button"), "unclickable");

  const popInContainer = document.querySelector(
    ".GuestUserListPopInContainer",
  )!;
  let ajaxData: Record<string, unknown> = {};
  ajaxData = fillAjaxDataFromContainer(ajaxData, popInContainer);
  ajaxData["email"] = undefined;
  ajaxData["status"] = undefined;
  try {
    await ajax({
      url: "api/v1/users/" + String(guestId),
      type: "PATCH",
      headers: { "X-CSRF-Token": pwgToken },
      json: ajaxData,
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

/** Part of `updateUserList()`'s own extraction, below. */
function applyUserSearchFilter(updateData: Record<string, unknown>): void {
  const userSearchVal = val(document.querySelectorAll("#user_search"));
  if (userSearchVal === undefined || userSearchVal === "") {
    return;
  }
  const matches = /^id:(\d+)$/.exec(userSearchVal);
  if (matches) {
    updateData["userIds"] = [matches[1]];
  }
  // Free-text fuzzy search (username/email/group-name) has no
  // GET /api/v1/users equivalent -- UserListController's own
  // docblock already documents this as a deliberately deferred
  // filter, not a gap introduced by this conversion.
}

/** Part of `updateUserList()`'s own extraction, below. */
function applyAdvancedFilterData(updateData: Record<string, unknown>): void {
  if (
    !hasClass(
      document.querySelectorAll(".advanced-filter"),
      "advanced-filter-open",
    )
  ) {
    return;
  }

  const filterStatus = val(
    document.querySelectorAll(".advanced-filter-select[name=filter_status]"),
  );
  const filterGroup = val(
    document.querySelectorAll(".advanced-filter-select[name=filter_group]"),
  );
  const filterLevel = val(
    document.querySelectorAll(".advanced-filter-select[name=filter_level]"),
  );
  updateData["status"] =
    filterStatus !== undefined && filterStatus !== "" ? [filterStatus] : [];
  updateData["groupIds"] =
    filterGroup !== undefined && filterGroup !== "" ? [filterGroup] : [];
  updateData["minLevel"] = filterLevel;
  updateData["maxLevel"] = filterLevel;
  updateData["minRegister"] =
    registerDates[
      slider(
        document.querySelectorAll(".dates-select-bar .slider-bar-container"),
        "option",
        "values",
      )![0]!
    ];
  updateData["maxRegister"] =
    registerDates[
      slider(
        document.querySelectorAll(".dates-select-bar .slider-bar-container"),
        "option",
        "values",
      )![1]!
    ];
}

async function updateUserList(): Promise<void> {
  const updateData: Record<string, unknown> = {
    order: filterBy, // We want the most recent user first
    page: actualPage - 1,
    perPage: perPage,
    exclude: [guestId],
  };
  applyUserSearchFilter(updateData);
  applyAdvancedFilterData(updateData);
  show(document.querySelectorAll(".user-update-spinner"));
  try {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/utils/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
    const response = (await ajax({
      url: "api/v1/users",
      type: "GET",
      data: updateData,
      dataType: "json",
    })) as UserListResponse;
    totalUsers = response.totalCount;
    if (firstUpdate) {
      document
        .querySelector("h1")
        ?.insertAdjacentHTML(
          "beforeend",
          `<span class='badge-number'>${totalUsers}</span>`,
        );
      firstUpdate = false;
    }
    nbFilteredUsers = response.totalCount;
    updatePaginationMenu();
    currentUsers = response.users;
    generateUserList();
    bindUserEditClickHandler();
    setSelectedToSelection();

    hide(document.querySelectorAll(".user-update-spinner"));
    showFilterInfos(countActiveFilters());
  } catch {
    hide(document.querySelectorAll(".user-update-spinner"));
  }
}

/** Part of `updateUserList()`'s own extraction, below. */
function bindUserEditClickHandler(): void {
  on(
    document.querySelectorAll(".user-col.user-first-col.user-container-edit"),
    "click",
    function (this: Element) {
      const uidIndex = Number(attrOf(this.closest(".user-container")!, "key"));
      lastUserId = currentUsers[uidIndex]!.id;
      lastUserIndex = uidIndex;
      fillUserEdit(currentUsers[uidIndex]!);
      fadeIn(document.querySelectorAll("#UserList"));
      document.querySelector("#tab_properties")?.scrollIntoView({
        behavior: "instant",
      });
    },
  );
}

/** Part of `updateUserList()`'s own extraction, below. */
function countActiveFilters(): number {
  let nbFilters = 0;
  if (
    val(
      document.querySelectorAll(".advanced-filter-select[name=filter_status]"),
    ) !== ""
  ) {
    nbFilters += 1;
  }
  if (
    val(
      document.querySelectorAll(".advanced-filter-select[name=filter_group]"),
    ) !== ""
  ) {
    nbFilters += 1;
  }
  if (
    val(
      document.querySelectorAll(".advanced-filter-select[name=filter_level]"),
    ) !== ""
  ) {
    nbFilters += 1;
  }
  if (
    slider(
      document.querySelectorAll(".dates-select-bar .slider-bar-container"),
      "option",
      "values",
    )![0] !== 0
  ) {
    nbFilters += 1;
  }
  if (
    slider(
      document.querySelectorAll(".dates-select-bar .slider-bar-container"),
      "option",
      "values",
    )![1] !==
    registerDates.length - 1
  ) {
    nbFilters += 1;
  }
  return nbFilters;
}

async function addUser(): Promise<void> {
  const ajaxData: Record<string, unknown> = {};
  const groupsSelected = Array.from(
    document.querySelectorAll(
      ".AddUserInputContainer .user-property-group .selectize-input .item",
    ),
  ).map((el) => parseInt(attrOf(el, "data-value")!));
  ajaxData["username"] = val(
    document.querySelectorAll(".AddUserLabelUsername .user-property-input"),
  );
  ajaxData["email"] = val(
    document.querySelectorAll(".AddUserLabelEmail .user-property-input"),
  );
  ajaxData["status"] = val(
    document.querySelectorAll(
      ".AddUserInputContainer .user-property-status select",
    ),
  );
  ajaxData["level"] = Number(
    val(
      document.querySelectorAll(
        ".AddUserInputContainer .user-property-level select",
      ),
    ),
  );
  ajaxData["enabledHigh"] =
    attrOf(
      document.querySelectorAll(
        '.AddUserInputContainer .user-list-checkbox[name="hd_enabled"]',
      ),
      "data-selected",
    ) === "1"
      ? true
      : false;
  ajaxData["groupIds"] = groupsSelected;

  const payload: Record<string, unknown> = {
    username: ajaxData["username"],
    email: ajaxData["email"],
  };

  if ("generic" === ajaxData["status"]) {
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
  if ("generic" === ajaxData["status"]) {
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
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/utils/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
    const response = (await ajax({
      url: "api/v1/users",
      type: "POST",
      headers: { "X-CSRF-Token": pwgToken },
      json: payload,
      dataType: "json",
    })) as operations["userCreate"]["responses"][201]["content"]["application/json"];
    const newUserId = response.id;
    const defaultGroup = "groups" in response ? response.groups : [];
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajaxData["groupIds"] was set to groupsSelected (a real number[]) earlier in this same function, the only writer before this read.
    ajaxData["groupIds"] = (ajaxData["groupIds"] as number[]).concat(
      defaultGroup,
    );
    void addInfosToNewUser(newUserId, ajaxData);
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

async function addInfosToNewUser(
  user_id: number,
  ajaxData: Record<string, unknown>,
): Promise<void> {
  try {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/utils/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
    const response = (await ajax({
      url: "api/v1/users/" + String(user_id),
      type: "PATCH",
      headers: { "X-CSRF-Token": pwgToken },
      json: {
        status: ajaxData["status"],
        level: ajaxData["level"],
        groupIds: ajaxData["groupIds"],
        enabledHigh: ajaxData["enabledHigh"],
      },
      dataType: "json",
    })) as operations["userUpdate"]["responses"][200]["content"]["application/json"];
    const newUserId = response.id;
    void updateUserList();
    // addUserClose();
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
      userAddedStr.replace("%s", String(ajaxData["username"])),
    );
    const status = ["webmaster", "admin", "normal"];
    if (status.includes(String(ajaxData["status"]))) {
      void sendNewUserPassword(newUserId, String(ajaxData["email"]));
    } else {
      addUserClose();
    }
    setVal(document.querySelectorAll("#AddUser .user-property-input"), "");
    const editNow = document.querySelectorAll("#AddUserSuccess .edit-now");
    off(editNow, "click");
    on(editNow, "click", () => {
      lastUserId = newUserId;
      lastUserIndex = getContainerIndexFromUid(newUserId);
      if (lastUserIndex !== -1) {
        fillUserEdit(currentUsers[lastUserIndex]!);
        openUserList();
      } else {
        void getUserInfo(newUserId, openUserList);
      }
    });
    html(
      document.querySelectorAll("#AddUserSuccess label span:first-child"),
      userAddedStr.replace("%s", String(ajaxData["username"])),
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

async function sendNewUserPassword(
  user_id: number,
  mail: string,
): Promise<void> {
  const sendByMail = mail === "" ? false : true;
  try {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/utils/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
    const response = (await ajax({
      url:
        "api/v1/users/" + String(user_id) + "/actions/generate-password-link",
      dataType: "json",
      type: "POST",
      headers: { "X-CSRF-Token": pwgToken },
      json: {
        sendByMail: sendByMail,
      },
    })) as operations["userGeneratePasswordLink"]["responses"][200]["content"]["application/json"];
    const passwordContainer = document.querySelectorAll(
      "#AddUserPasswordInputContainer",
    );
    show(passwordContainer);
    hide(document.querySelectorAll("#AddUserFieldContainer"));
    fadeIn(document.querySelectorAll("#AddUserSuccessContainer"));
    setVal(
      document.querySelectorAll("#AddUserPasswordLink"),
      response.generatedLink,
    );
    trigger(document.querySelectorAll("#AddUserPasswordLink"), "focus");
    html(
      document.querySelectorAll("#AddUserTextField"),
      sendByMail
        ? sprintf(validLinkMail, response.timeValidation, `<b>${mail}</b>`)
        : sprintf(validLinkWithoutMail, response.timeValidation),
    );

    if (
      sendByMail &&
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
      sendByMail &&
      response.sendByMail !== false &&
      response.sendByMail !== null
    ) {
      hide(passwordContainer);
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
      addUserClose();
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

async function deleteUser(uid: number): Promise<void> {
  try {
    await ajax({
      url: "api/v1/users/" + String(uid),
      type: "DELETE",
      headers: { "X-CSRF-Token": pwgToken },
    });
    closeUserList();
    void updateUserList();
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

function showFilterInfos(nbFilters: number) {
  if (
    (val(document.querySelectorAll("#user_search")) ?? "").length !== 0 ||
    nbFilters !== 0
  ) {
    if (String(totalUsers) !== "1") {
      html(
        document.querySelectorAll(".filtered-users"),
        filteredUsers.replace(/%d/g, String(totalUsers)),
      );
    } else {
      html(
        document.querySelectorAll(".filtered-users"),
        filteredUser.replace(/%d/g, String(totalUsers)),
      );
    }
  } else {
    html(document.querySelectorAll(".filtered-users"), "");
  }

  if (nbFilters !== 0) {
    css(document.querySelectorAll(".advanced-filter-btn"), {
      width: "80px",
    });
    const counter = document.querySelectorAll(".filter-counter");
    html(counter, String(nbFilters));
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

type SendLinkPasswordResponse =
  operations["userGeneratePasswordLink"]["responses"][200]["content"]["application/json"];

/** Part of `sendLinkPassword()`'s own extraction, below. */
function bindClosePasswordMailButton(): void {
  const closeBtn = document.querySelectorAll("#close_password_mail_close");
  off(closeBtn, "click");
  on(closeBtn, "click", function () {
    resetPasswordModals();
  });
}

/** Part of `sendLinkPassword()`'s own extraction, below. */
function applyMailSendSuccessUi(username: string, email: string | null): void {
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
  const emailInputVal = val(
    document.querySelectorAll(".user-property-email .user-property-input"),
  );
  const currMail = (emailInputVal ?? "").length
    ? String(emailInputVal)
    : (email ?? "");
  html(
    document.querySelectorAll("#password_msg_result_mail"),
    sprintf(mailSentAt, username, currMail),
  );
}

/** Part of `sendLinkPassword()`'s own extraction, below. */
function applyMailSendFailureUi(): void {
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
  html(document.querySelectorAll("#password_msg_result_mail"), errorMailSent);
}

/** Part of `sendLinkPassword()`'s own extraction, below. */
function applyMailSendResultUi(
  response: SendLinkPasswordResponse,
  username: string,
  email: string | null,
): void {
  if (response.sendByMail !== false && response.sendByMail !== null) {
    applyMailSendSuccessUi(username, email);
  } else {
    applyMailSendFailureUi();
  }
  hide(document.querySelectorAll(".user-property-password-choice"));
  fadeIn(document.querySelectorAll("#edit_password_result_mail"));
  bindClosePasswordMailButton();
}

/** Part of `sendLinkPassword()`'s own extraction, below. */
function applyCopyLinkResultUi(generatedLink: string): void {
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
  addClass(document.querySelectorAll("#result_send_mail_copy_icon"), "icon-ok");
  html(document.querySelectorAll("#result_send_mail_copy_msg"), copyLinkStr);
  // eslint-disable-next-line @typescript-eslint/no-unnecessary-condition, @typescript-eslint/strict-boolean-expressions -- real feature-detection guard: lib.dom types navigator.clipboard as always-present, but it's genuinely absent on a non-secure origin or an older browser.
  if (window.isSecureContext && navigator.clipboard) {
    copyToClipboard(generatedLink);
  }
}

/** Part of `sendLinkPassword()`'s own extraction, below. */
function applySendLinkPasswordErrorUi(sendByMail: boolean): void {
  if (!sendByMail) {
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
    return;
  }
  applyMailSendFailureUi();
  hide(document.querySelectorAll(".user-property-password-choice"));
  fadeIn(document.querySelectorAll("#edit_password_result_mail"));
  bindClosePasswordMailButton();
}

async function sendLinkPassword(
  email: string | null,
  username: string,
  user_id: number,
  sendByMail: boolean,
): Promise<void> {
  try {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/utils/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
    const response = (await ajax({
      url:
        "api/v1/users/" + String(user_id) + "/actions/generate-password-link",
      dataType: "json",
      type: "POST",
      headers: { "X-CSRF-Token": pwgToken },
      json: {
        sendByMail: sendByMail,
      },
    })) as SendLinkPasswordResponse;
    setVal(
      document.querySelectorAll("#result_send_mail_copy_input"),
      response.generatedLink,
    );
    if (sendByMail) {
      applyMailSendResultUi(response, username, email);
    } else {
      applyCopyLinkResultUi(response.generatedLink);
    }
  } catch (err) {
    console.error("Error sendLinkPassword :", err);
    applySendLinkPasswordErrorUi(sendByMail);
  }
}

async function setMainUser(
  user_id: number,
  new_username: string,
): Promise<void> {
  try {
    await ajax({
      url: "api/v1/users/" + String(user_id) + "/actions/set-main-user",
      dataType: "json",
      type: "POST",
      contentType: "application/json",
      headers: { "X-CSRF-Token": pwgToken },
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

    ownerId = user_id;
    ownerUsername = new_username;
    setMainUserSuccess();
  } catch (err) {
    console.error(err);
  }
}

perPage = parseInt(pagination);

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
connectedUser = pwg_getPageData<number>("connected_user");
ownerUsername = pwg_getPageData<string>("owner_username");
const parsedGroupsArrName: unknown = JSON.parse(
  pwg_getPageData<string>("groups_arr_name"),
);
const groupsArrName = Array.isArray(parsedGroupsArrName)
  ? parsedGroupsArrName.filter((n): n is string => typeof n === "string")
  : [];
const groupsArrId: number[] = pwg_getPageData<string>("groups_arr_id")
  ? pwg_getPageData<string>("groups_arr_id").split(",").map(Number)
  : [];
groupsArr = groupsArrId.map((elem, index) => [elem, groupsArrName[index]!]);
guestId = pwg_getPageData<number>("guest_id");
nbDays = pwg_getPageString("%d days");
//per page is too long for the popin
nbPhotos = pwg_getPageString("%d photos");
pwgToken = pwg_getPageData<string>("csrf_token");
registerDates = pwg_getPageData<string>("register_dates").split(",");
groupOptions = groupsArr.map(function (x) {
  return { value: x[0], label: x[1], isSelected: false };
});

/* Startup */
setupRegisterDates(registerDates);
selectionMode(false);
void getGuestInfo();
void updateUserList();
updateSelectionContent();

tipTip(document.querySelectorAll(".icon-help-circled"), {
  maxWidth: "700px",
  fadeIn: "1000",
});

ready(function () {
  // Only webmaster can set admin or webmaster to others users
  if (connectedUserStatus !== "webmaster") {
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
            attrOf(
              document.querySelectorAll(
                "#permitActionUserList .user-list-checkbox[name=confirm_deletion]",
              ),
              "data-selected",
            ) !== "1"
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
            recentPeriodValues[
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
              headers: { "X-CSRF-Token": pwgToken },
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
          headers: { "X-CSRF-Token": pwgToken },
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
              headers: { "X-CSRF-Token": pwgToken },
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
        void updateUserList();
        if (action === "delete") {
          selection = [];
          updateSelectionContent();
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
// (see plugins/installedConfig.ts's own leading comment for the full
// explanation): `pluginAddTabInUserModal`'s own JSDoc documents it
// as a real public extension point third-party plugins call from their
// own, separately-loaded `<script>` tags -- this file's own top-level
// declarations are module-private otherwise (`export {}` above), so
// without this, no external plugin could actually reach it, unlike
// before this file became a real ES module.
window.plugin_add_tab_in_user_modal = pluginAddTabInUserModal;
