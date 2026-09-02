import type { components, operations } from "../../../../openapi/client/schema";
import { jConfirm_alert_options, TemporaryState } from "./common";
import { UsersCache } from "./LocalStorageCache";
// Type-only -- erased at compile time, so it never reaches Rollup's
// module graph at all.
import type { EntityCacheInstance, UserEntity } from "./LocalStorageCache";

import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../default/js/page-data";
import { ajax } from "../../../default/js/vendor/ajax";
import { alert, confirm } from "../../../default/js/vendor/jconfirm";
import {
  selectize as createSelectize,
  type SelectizeInstance,
} from "../../../default/js/vendor/selectize";
import {
  addClass,
  animate,
  attr,
  attrOf,
  css,
  cssValue,
  data,
  fadeIn,
  fadeOut,
  find,
  hide,
  html,
  htmlOf,
  is,
  off,
  on,
  outerHeight,
  parseHtml,
  ready,
  removeAttr,
  removeClass,
  setChecked,
  setVal,
  show,
  toggle,
  trigger,
  val,
} from "../../../default/js/vendor/dom";

// `UserEntity`/`EntityCacheInstance<T>` are LocalStorageCache.ts's own
// real exported types now (docs/PLAN.md P48, that file's own module
// conversion -- was a bare ambient-global read before that).
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
interface UserSelectOption extends Record<string, unknown> {
  value: string | number;
  text: string;
}

const DELAY_FEEDBACK = 3000;

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

/*-------
Group Popin
-------*/

on(
  document.querySelectorAll(".group_details_popup_trigger"),
  "click",
  function () {
    show(document.querySelectorAll(".Group_details-popup-container"));
  },
);

on(document.querySelectorAll(".CloseGroupPopup"), "click", function () {
  hide(document.querySelectorAll(".Group_details-popup-container"));
});

//Number On Badge
function updateBadge() {
  html(
    document.querySelectorAll(".badge-number"),
    String(document.querySelectorAll(".GroupContainer").length - 2),
  ); //Less the add group div and the template
}

/*-------
 Add User toggle and reduces height of user list when add user form is visible
 -------*/

on(document.querySelectorAll("#form-btn"), "click", function () {
  show(document.querySelectorAll("#cancel"));
  show(document.querySelectorAll("#addUserLabel"));
  show(document.querySelectorAll(".UserSearch"));
  show(document.querySelectorAll("#UserSubmit"));
  hide(document.querySelectorAll("#form-btn"));
  css(document.querySelectorAll(".groups .list_user"), "max-height", "100px");
});

on(document.querySelectorAll("#cancel"), "click", function () {
  hide(document.querySelectorAll("#cancel"));
  hide(document.querySelectorAll("#addUserLabel"));
  hide(document.querySelectorAll(".UserSearch"));
  hide(document.querySelectorAll("#UserSubmit"));
  show(document.querySelectorAll("#form-btn"));
  css(document.querySelectorAll(".groups .list_user"), "max-height", "200px");
});

/*-------
 Add Group toggle
 -------*/
let isToggle = true;
on(document.querySelectorAll(".addGroupBlock"), "click", function () {
  if (isToggle) {
    deployAddGroupForm();
  } else {
    hideAddGroupForm();
  }
});

function deployAddGroupForm() {
  animate(
    document.querySelectorAll(".addGroupBlock"),
    {
      top: "20%",
      padding: "0px",
    },
    400,
    function () {
      fadeIn(document.querySelectorAll("#addGroupForm form"));
      document.querySelector<HTMLElement>("#addGroupNameInput")?.focus();
    },
  );
  isToggle = false;
}

function hideAddGroupForm() {
  fadeOut(document.querySelectorAll("#addGroupForm form"), function () {
    animate(
      document.querySelectorAll(".addGroupBlock"),
      {
        top: "50%",
        padding: "100px 0",
      },
      400,
    );
  });
  isToggle = true;
}

/*-------
 Add Group Submit
 -------*/

ready(function () {
  on(
    document.querySelectorAll("#addGroupForm form"),
    "submit",
    function (e: Event) {
      e.preventDefault();
      const name = String(
        val(document.querySelectorAll("#addGroupForm input[type=text]")),
      );
      const loadState = new TemporaryState();
      loadState.changeHTML(
        document.querySelectorAll(".actionButtons button"),
        "<i class='icon-spin6 animate-spin'> </i>",
      );
      loadState.changeAttribute(
        document.querySelectorAll(".actionButtons button"),
        "style",
        "pointer-events: none",
      );
      loadState.changeAttribute(
        document.querySelectorAll(".actionButtons a"),
        "style",
        "pointer-events: none",
      );

      if (name.replace(/\s/g, "").length !== 0) {
        void ajax({
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
            response: operations["groupCreate"]["responses"][201]["content"]["application/json"],
          ) {
            loadState.reverse();
            setVal(
              document.querySelectorAll(".addGroupFormLabelAndInput input"),
              "",
            );
            const group = response;
            const groupBox = createGroup(group);
            document.querySelector("#addGroupForm")?.after(groupBox);
            setupGroupBox(groupBox);
            updateBadge();
          },
          error: function (_err) {
            loadState.reverse();
            html(
              document.querySelectorAll("#addGroupForm .groupError"),
              str_name_not_empty,
            );
            fadeIn(document.querySelectorAll("#addGroupForm .groupError"));
            const errorEl = document.querySelectorAll(
              "#addGroupForm .groupError",
            );
            setTimeout(() => {
              fadeOut(errorEl);
            }, DELAY_FEEDBACK);
          },
        });
      } else {
        loadState.reverse();
        html(
          document.querySelectorAll("#addGroupForm .groupError"),
          str_name_not_empty,
        );
        fadeIn(document.querySelectorAll("#addGroupForm .groupError"));
        const errorEl = document.querySelectorAll("#addGroupForm .groupError");
        setTimeout(() => {
          fadeOut(errorEl);
        }, DELAY_FEEDBACK);
      }
    },
  );
});

function createGroup(group: Group): Element {
  //Setup the group
  const template = document.getElementById("group-template")!;
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- cloning #group-template, itself a real HTMLElement, always produces an HTMLElement (real DOM guarantee); cloneNode()'s own lib.dom signature just isn't narrowed per-subtype.
  const newgroup = template.cloneNode(true) as HTMLElement;
  newgroup.id = "group-" + String(group.id);
  attr(newgroup, "data-id", String(group.id));
  html(find(newgroup, "#group_name"), group.name);
  setVal(find(newgroup, ".group_name-editable"), group.name);
  attr(
    find(newgroup, ".Group-checkbox label"),
    "for",
    "Group-Checkbox-selection-" + String(group.id),
  );
  attr(
    find(newgroup, ".Group-checkbox input"),
    "id",
    "Group-Checkbox-selection-" + String(group.id),
  );
  attr(find(newgroup, ".input-edit-group-name"), "placeholder", group.name);
  html(
    find(newgroup, ".group_number_users"),
    String(group.nbUsers) +
      " " +
      (group.nbUsers > 1 ? str_members_default : str_member_default),
  );
  html(find(newgroup, ".group_name-editable"), group.name);
  attr(
    find(newgroup, ".manage-permissions"),
    "href",
    "admin.php?page=group_perm&group_id=" + String(group.id),
  );
  hideAddGroupForm();

  //Setup the icon color
  const colors = [
    "icon-red",
    "icon-blue",
    "icon-yellow",
    "icon-purple",
    "icon-green",
  ];
  const colorId = group.id % 5;
  addClass(find(newgroup, ".icon-users-1"), colors[colorId]!);

  //Place group in first Place
  html(find(newgroup, ".groupMessage"), str_group_created);
  fadeIn(find(newgroup, ".groupMessage"));
  const groupMessage = find(newgroup, ".groupMessage");
  setTimeout(() => {
    fadeOut(groupMessage);
  }, DELAY_FEEDBACK);
  return newgroup;
}

/*-------
 SETUP JS ON GROUP BOX
 -------*/
ready(function () {
  document.querySelectorAll(".GroupContainer").forEach((groupBox) => {
    if (attrOf(groupBox, "id") !== "group-template") setupGroupBox(groupBox);
  });
});
function setupGroupBox(groupBox: Element) {
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- data() reads a data-* attribute this same app's own setData()/template writes, never adversarial input.
  const id = data(groupBox, "id") as string | number;

  /* Change background color of group block if checked in selection mode */
  on(
    find(groupBox, ".Group-checkbox input[type='checkbox']"),
    "change",
    function () {
      toogleSelection(
        id,
        is(
          find(groupBox, ".Group-checkbox input[type='checkbox']"),
          ":checked",
        ),
      );
    },
  );
  // Was `.attr("checked", false)` -- jQuery's `.attr()` setter never
  // accepted a boolean (masked by this file's pre-P47 `any` typing);
  // `.prop("checked", ...)` is the correct API for a checkbox's boolean
  // state, matching every other checked-state toggle in this same file
  // (e.g. `toogleSelection`'s own `setChecked(..., true/false)` below).
  setChecked(find(groupBox, ".Group-checkbox input[type='checkbox']"), false);

  /* Display the option on the click on "..." */
  on(
    find(groupBox, ".group-dropdown-options"),
    "click",
    function (this: Element) {
      toggle(find(this, "#GroupOptions"));
    },
  );

  /* Set the delete action */
  on(find(groupBox, "#GroupDelete"), "click", function () {
    deleteGroup(id);
  });

  /* Set the rename action */
  on(
    find(groupBox, ".Group-name .icon-pencil, #GroupEdit"),
    "click",
    function () {
      displayRenameForm(true, id);
      setTimeout(() => {
        hide(find(groupBox, "#GroupOptions"));
      }, 10);
    },
  );

  on(find(groupBox, ".group-rename .validate"), "click", function () {
    // Not `.trigger("submit")`: `HTMLFormElement.submit()` deliberately
    // does not fire a "submit" event (bypasses validation/listeners by
    // spec), so it would never reach the native "submit" listener
    // registered below. `requestSubmit()` is the real equivalent of
    // "submit this form as if the user had".
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- verified by the selector: only <form> elements can match ".group-rename form".
    const renameForm = find(groupBox, ".group-rename form")[0] as
      HTMLFormElement | undefined;
    renameForm?.requestSubmit();
  });

  on(find(groupBox, ".group-rename form"), "submit", function (e: Event) {
    e.preventDefault();
    renameGroup(id, String(val(find(groupBox, ".group_name-editable"))));
  });

  on(find(groupBox, ".group-rename .icon-cancel"), "click", function () {
    displayRenameForm(false, id);
    setVal(
      find(groupBox, ".group_name-editable"),
      htmlOf(find(groupBox, ".Group-name-container p")) ?? "",
    );
  });

  /* Hide group options and rename field on click on the screen */

  on(document, "mouseup", function (e: Event) {
    e.stopPropagation();
    let option_is_clicked = false;
    document.querySelectorAll("#GroupOptions div").forEach((option) => {
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- a real mouseup event's own target inside the document is always a Node (or null), never a bare EventTarget with no Node interface.
      if (option.contains(e.target as Node | null)) {
        option_is_clicked = true;
      }
    });
    // eslint-disable-next-line @typescript-eslint/no-unnecessary-condition -- real false positive from closure mutation: option_is_clicked is set inside the forEach callback above, which the rule doesn't track (same class as dom.ts's stopped).
    if (!option_is_clicked) {
      hide(find(groupBox, "#GroupOptions"));
    }
  });

  /* Setup the default action */
  if (data(groupBox, "default") === 1) {
    setupDefaultActions(id, true);
  } else if (data(groupBox, "default") === 0) {
    setupDefaultActions(id, false);
  }

  on(find(groupBox, ".manage-users"), "click", function () {
    openUserManager(id);
  });

  on(find(groupBox, "#GroupDuplicate"), "click", function () {
    duplicateAction(id);
  });
}

function toogleSelection(group_id: string | number, toggleOn: boolean) {
  const groupBox = document.querySelectorAll("#group-" + String(group_id));
  if (toggleOn) {
    setChecked(find(groupBox, ".Group-checkbox input"), true);
    addClass(groupBox, "GroupBackgroudSelected");
    addClass(find(groupBox, ".icon-users-1"), "OrangeIcon");
    addClass(find(groupBox, ".group_number_users"), "OrangeFont");

    //Display item selection on selection panel
    const item = parseHtml(
      "<div data-id=" +
        String(group_id) +
        ">" +
        "<a class='icon-cancel'></a>" +
        "<p>" +
        (htmlOf(find(groupBox, "#group_name")) ?? "") +
        "</p> </div>",
    )[0]!;
    document.querySelector(".DeleteGroupList")?.appendChild(item);
    on(find(item, "a"), "click", function () {
      setChecked(find(groupBox, ".Group-checkbox input"), false);
      toogleSelection(group_id, false);
    });
    updateSelectionPanel();
    const option = parseHtml(
      '<option value="' +
        String(group_id) +
        '">' +
        (htmlOf(find(groupBox, "#group_name")) ?? "") +
        "</option>",
    )[0]!;
    document.querySelector("#MergeOptionsChoices")?.appendChild(option);
  } else {
    setChecked(find(groupBox, ".Group-checkbox input"), false);
    removeClass(groupBox, "GroupBackgroudSelected");
    removeClass(find(groupBox, ".icon-users-1"), "OrangeIcon");
    removeClass(find(groupBox, ".group_number_users"), "OrangeFont");
    document.querySelectorAll(".DeleteGroupList div").forEach((el) => {
      if (attrOf(el, "data-id") === String(group_id)) {
        el.remove();
      }
    });
    updateSelectionPanel();
    document.querySelectorAll("#MergeOptionsChoices option").forEach((el) => {
      if (attrOf(el, "value") === String(group_id)) {
        el.remove();
      }
    });
  }
}

/* Group Ajax and Display Functions */
function deleteGroup(id: string | number) {
  confirm({
    title: str_delete.replace(
      "%s",
      htmlOf(
        document.querySelectorAll("#group-" + String(id) + " #group_name"),
      )!,
    ),
    titleClass: "jconfirmDeleteConfirm",
    content: "",
    boxWidth: "30%",
    type: "red",
    buttons: {
      confirm: {
        text: str_yes_delete_confirmation,
        btnClass: "btn-red",
        action: function () {
          const groupName = htmlOf(
            document.querySelectorAll(
              "#group-" + String(id) + " .Group-name-container p",
            ),
          )!;
          alert({
            ...{
              title: str_group_deleted.replace("%s", groupName),
              content: function () {
                return ajax({
                  url: "api/v1/groups/" + String(id),
                  type: "DELETE",
                  headers: {
                    "X-CSRF-Token": pwg_token,
                  },
                  dataType: "json",
                  success: function (
                    _data: operations["groupDelete"]["responses"][200]["content"]["application/json"],
                  ) {
                    document.querySelector("#group-" + String(id))?.remove();
                    document
                      .querySelector(
                        '.DeleteGroupList div[data-id="' + String(id) + '"]',
                      )
                      ?.remove();
                    document
                      .querySelector(
                        '#MergeOptionsChoices option[value="' +
                          String(id) +
                          '"]',
                      )
                      ?.remove();
                    updateBadge();
                  },
                  error: function (err) {
                    console.error(err);
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
}

function renameGroup(id: string | number, newName: string) {
  const loadState = new TemporaryState();
  loadState.changeHTML(
    document.querySelectorAll(
      "#group-" + String(id) + " .group-rename .validate",
    ),
    "<i class='animate-spin icon-spin6'></i>",
  );
  loadState.removeClass(
    document.querySelectorAll(
      "#group-" + String(id) + " .group-rename .validate",
    ),
    "icon-ok",
  );
  loadState.changeAttribute(
    document.querySelectorAll("#group-" + String(id) + " .group-rename span"),
    "style",
    "pointer-events: none",
  );

  if (newName.replace(/\s/g, "").length !== 0) {
    void ajax({
      url: "api/v1/groups/" + String(id),
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
        response: operations["groupUpdate"]["responses"][200]["content"]["application/json"],
      ) {
        loadState.reverse();
        const confirmedName = response.name;
        //Display message
        html(
          find(
            document.querySelectorAll("#group-" + String(id)),
            ".groupMessage",
          ),
          str_renaming_done,
        );
        const groupMessage = find(
          document.querySelectorAll("#group-" + String(id)),
          ".groupMessage",
        );
        fadeIn(groupMessage);
        setTimeout(() => {
          fadeOut(groupMessage);
        }, DELAY_FEEDBACK);
        html(
          find(
            document.querySelectorAll("#group-" + String(id)),
            "#group_name",
          ),
          confirmedName,
        );

        //Hide editable field
        displayRenameForm(false, id);
      },
      error: function (_err) {
        loadState.reverse();
        //Display error message
        html(
          find(
            document.querySelectorAll("#group-" + String(id)),
            ".groupError",
          ),
          str_name_taken,
        );
        const groupError = find(
          document.querySelectorAll("#group-" + String(id)),
          ".groupError",
        );
        fadeIn(groupError);
        setTimeout(() => {
          fadeOut(groupError);
        }, DELAY_FEEDBACK);
      },
    });
  } else {
    loadState.reverse();
    html(
      find(document.querySelectorAll("#group-" + String(id)), ".groupError"),
      str_name_not_empty,
    );
    const groupError = find(
      document.querySelectorAll("#group-" + String(id)),
      ".groupError",
    );
    fadeIn(groupError);
    setTimeout(() => {
      fadeOut(groupError);
    }, DELAY_FEEDBACK);
  }
}

// Hide or display rename form
function displayRenameForm(doDisplay: boolean, grp_id: string | number) {
  if (doDisplay) {
    css(
      find(
        document.querySelectorAll("#group-" + String(grp_id)),
        ".group-rename",
      ),
      "display",
      "flex",
    );
    hide(
      find(
        document.querySelectorAll("#group-" + String(grp_id)),
        ".Group-name-container .icon-pencil",
      ),
    );
    css(
      find(
        document.querySelectorAll("#group-" + String(grp_id)),
        ".Group-name-container p",
      ),
      "opacity",
      0,
    );
  } else {
    hide(
      find(
        document.querySelectorAll("#group-" + String(grp_id)),
        ".group-rename",
      ),
    );
    removeAttr(
      find(
        document.querySelectorAll("#group-" + String(grp_id)),
        ".Group-name-container .icon-pencil",
      ),
      "style",
    );
    css(
      find(
        document.querySelectorAll("#group-" + String(grp_id)),
        ".Group-name-container p",
      ),
      "opacity",
      1,
    );
  }
}

const setDefaultGroup = function (id: string | number, is_default: boolean) {
  const groupDefault = document.querySelectorAll(
    "#group-" + String(id) + " #GroupDefault",
  );
  css(
    groupDefault,
    "width",
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- #GroupDefault is always a real HTMLElement (a <span>/<div> in this app's own markup, never SVG); the selector guarantees it exists here.
    outerHeight(groupDefault[0] as HTMLElement),
  );
  html(groupDefault, "<i class='icon-spin6 animate-spin'> </i>");
  removeClass(groupDefault, "icon-star");
  attr(groupDefault, "style", "pointer-events: none; text-align: center;");
  addClass(
    find(
      document.querySelectorAll("#group-" + String(id)),
      ".is-default-token",
    ),
    "icon-spin6",
  );
  addClass(
    find(
      document.querySelectorAll("#group-" + String(id)),
      ".is-default-token",
    ),
    "animate-spin",
  );
  removeClass(
    find(
      document.querySelectorAll("#group-" + String(id)),
      ".is-default-token",
    ),
    "icon-star",
  );
  void ajax({
    url: "api/v1/groups/" + String(id),
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
      hide(
        document.querySelectorAll("#group-" + String(id) + " #GroupOptions"),
      );
      if (is_default) {
        setupDefaultActions(id, true);
      } else {
        setupDefaultActions(id, false);
      }
    },
    error: function (err) {
      console.error(err);
    },
  });
};

function setupDefaultActions(id: string | number, is_default: boolean) {
  attr(
    document.querySelectorAll("#group-" + String(id) + " #GroupDefault"),
    "style",
    "",
  );
  addClass(
    document.querySelectorAll("#group-" + String(id) + " #GroupDefault"),
    "icon-star",
  );
  const token = find(
    document.querySelectorAll("#group-" + String(id)),
    ".is-default-token",
  );
  removeClass(token, "icon-spin6");
  removeClass(token, "animate-spin");
  addClass(token, "icon-star");
  if (is_default) {
    html(
      find(document.querySelectorAll("#group-" + String(id)), "#GroupDefault"),
      str_unset_default,
    );
    attr(
      find(
        document.querySelectorAll("#group-" + String(id)),
        ".is-default-token",
      ),
      "title",
      str_unset_default,
    );
    // `.unbind("click")` removes every click handler this element has,
    // regardless of which closure added it -- dom.ts's own `off(target,
    // "click")` (no handler argument) matches that, over its own
    // registry of `on()`-added listeners.
    off(
      document.querySelectorAll("#group-" + String(id) + " #GroupDefault"),
      "click",
    );
    removeClass(
      find(
        document.querySelectorAll("#group-" + String(id)),
        ".is-default-token",
      ),
      "deactivate",
    );
    on(
      document.querySelectorAll("#group-" + String(id) + " #GroupDefault"),
      "click",
      function () {
        setDefaultGroup(id, false);
      },
    );
    on(
      find(
        document.querySelectorAll("#group-" + String(id)),
        ".is-default-token",
      ),
      "click",
      function () {
        setDefaultGroup(id, false);
      },
    );
  } else {
    html(
      find(document.querySelectorAll("#group-" + String(id)), "#GroupDefault"),
      str_set_default,
    );
    attr(
      find(
        document.querySelectorAll("#group-" + String(id)),
        ".is-default-token",
      ),
      "title",
      str_set_default,
    );
    addClass(
      find(
        document.querySelectorAll("#group-" + String(id)),
        ".is-default-token",
      ),
      "deactivate",
    );
    on(
      document.querySelectorAll("#group-" + String(id) + " #GroupDefault"),
      "click",
      function () {
        setDefaultGroup(id, true);
      },
    );
    off(
      find(
        document.querySelectorAll("#group-" + String(id)),
        ".is-default-token",
      ),
      "click",
    );
  }
}

function duplicateAction(id: string | number) {
  const loadState = new TemporaryState();
  loadState.changeHTML(
    document.querySelectorAll("#group-" + String(id) + " #GroupDuplicate"),
    "<i class='icon-spin6 animate-spin'> </i>",
  );
  loadState.removeClass(
    document.querySelectorAll("#group-" + String(id) + " #GroupDuplicate"),
    "icon-docs",
  );
  loadState.changeAttribute(
    document.querySelectorAll("#group-" + String(id) + " #GroupDuplicate"),
    "style",
    "pointer-events: none; text-align: center;",
  );
  let copy_name =
    (htmlOf(
      document.querySelectorAll("#group-" + String(id) + " #group_name"),
    ) ?? "") + str_copy;

  const name_exist = function (name: string) {
    let exist = false;
    document.querySelectorAll(".Group-name-container p").forEach((el) => {
      if (htmlOf(el) === name) exist = true;
    });
    return exist;
  };

  let i = 1;
  while (name_exist(copy_name)) {
    copy_name =
      (htmlOf(
        document.querySelectorAll("#group-" + String(id) + " #group_name"),
      ) ?? "") + str_other_copy.replace("%s", String(i++));
  }

  void ajax({
    url: "api/v1/groups/" + String(id) + "/actions/duplicate",
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
      response: operations["groupDuplicate"]["responses"][201]["content"]["application/json"],
    ) {
      loadState.reverse();
      hide(
        document.querySelectorAll("#group-" + String(id) + " #GroupOptions"),
      );
      const group = response;
      const groupbox = createGroup(group);
      document.querySelector("#group-" + String(id))?.after(groupbox);
      setupGroupBox(groupbox);
      updateBadge();

      if (response.isDefault) {
        setupDefaultActions(response.id, true);
      }
    },
    error: function (err) {
      loadState.reverse();
      console.error(err);
    },
  });
}

/*-------
 Selection mode toggle actions,
 changes "..." in group block to checkbox,
 disables group actions in selection mode
 -------*/

ready(function () {
  setChecked(document.querySelectorAll("#toggleSelectionMode"), false);
  on(document.querySelectorAll("#toggleSelectionMode"), "click", function () {
    if (is(document.querySelectorAll("#toggleSelectionMode"), ":checked")) {
      show(document.querySelectorAll(".in-selection-mode"));
      hide(document.querySelectorAll(".not-in-selection-mode"));
      removeClass(document.querySelectorAll(".GroupManagerButtons"), "visible");
    } else {
      hide(document.querySelectorAll(".in-selection-mode"));
      removeAttr(document.querySelectorAll(".not-in-selection-mode"), "style");
      setChecked(document.querySelectorAll(".Group-checkbox input"), false);
      trigger(
        document.querySelectorAll(".Group-checkbox input[type='checkbox']"),
        "change",
      );
    }
  });
});

/*-------
 Update Selection Panel
 -------*/
let state = "NoSelection";

function updateSelectionPanel(changedState: string = "") {
  const numSelect = document.querySelectorAll(".DeleteGroupList div").length;

  if (numSelect === 0) {
    updateStatePanel("NoSelection");
  } else if (changedState === "") {
    if (numSelect === 1 && state !== "ConfirmDeletion")
      updateStatePanel("OneSelected");
    if (numSelect > 1 && state === "OneSelected") updateStatePanel("Selection");
  } else {
    if (changedState === "Selection" && numSelect === 1)
      updateStatePanel("OneSelected");
    else updateStatePanel(changedState);
  }

  html(document.querySelectorAll(".number-Selected"), String(numSelect));
}

/*Update the state of the panel in 5 states :
 NoSelection, OneSelected, ConfirmDeletion, Selection, OptionMerge
 */
function updateStatePanel(newState: string = "Selection") {
  state = newState;
  switch (newState) {
    case "OneSelected":
      show(document.querySelectorAll("#DeleteSelectionMode"));
      show(document.querySelectorAll("#MergeSelectionMode"));
      buttonUnavailable(document.querySelectorAll("#MergeSelectionMode"));
      buttonAvailable(
        document.querySelectorAll("#DeleteSelectionMode"),
        "updateSelectionPanel('ConfirmDeletion')",
      );
      hide(document.querySelectorAll("#MergeOptionsBlock"));
      hide(document.querySelectorAll("#ConfirmGroupAction"));
      break;
    case "ConfirmDeletion":
      hide(document.querySelectorAll("#DeleteSelectionMode"));
      hide(document.querySelectorAll("#MergeSelectionMode"));
      hide(document.querySelectorAll("#MergeOptionsBlock"));
      show(document.querySelectorAll("#ConfirmGroupAction"));
      break;
    case "Selection":
      show(document.querySelectorAll("#DeleteSelectionMode"));
      show(document.querySelectorAll("#MergeSelectionMode"));
      buttonAvailable(
        document.querySelectorAll("#MergeSelectionMode"),
        "updateSelectionPanel('OptionMerge')",
      );
      buttonAvailable(
        document.querySelectorAll("#DeleteSelectionMode"),
        "updateSelectionPanel('ConfirmDeletion')",
      );
      hide(document.querySelectorAll("#MergeOptionsBlock"));
      hide(document.querySelectorAll("#ConfirmGroupAction"));
      break;
    case "OptionMerge":
      hide(document.querySelectorAll("#DeleteSelectionMode"));
      hide(document.querySelectorAll("#MergeSelectionMode"));
      show(document.querySelectorAll("#MergeOptionsBlock"));
      hide(document.querySelectorAll("#ConfirmGroupAction"));
      break;
  }
  if (newState === "NoSelection") {
    show(document.querySelectorAll("#DeleteSelectionMode"));
    show(document.querySelectorAll("#MergeSelectionMode"));
    buttonUnavailable(document.querySelectorAll("#MergeSelectionMode"));
    buttonUnavailable(document.querySelectorAll("#DeleteSelectionMode"));
    hide(document.querySelectorAll(".SelectionModeGroup"));
    show(document.querySelectorAll("#nothing-selected"));
    hide(document.querySelectorAll("#MergeOptionsBlock"));
    hide(document.querySelectorAll("#ConfirmGroupAction"));
  } else {
    show(document.querySelectorAll(".SelectionModeGroup"));
    hide(document.querySelectorAll("#nothing-selected"));
  }
}

function buttonAvailable(
  button: Element | ArrayLike<Element>,
  onClick: string,
) {
  removeClass(button, "unavailable");
  attr(button, "onclick", onClick);
}

function buttonUnavailable(button: Element | ArrayLike<Element>) {
  addClass(button, "unavailable");
  removeAttr(button, "onclick");
}

/*-------
 Merge function on button's pannel
 -------*/

on(document.querySelectorAll(".ConfirmMergeButton"), "click", function () {
  const loadState = new TemporaryState();
  loadState.changeAttribute(
    document.querySelectorAll(".ConfirmMergeButton"),
    "style",
    "pointer-events: none",
  );
  loadState.changeHTML(
    document.querySelectorAll(".ConfirmMergeButton"),
    "<i class='icon-spin6 animate-spin'> </i>",
  );
  loadState.removeClass(
    document.querySelectorAll(".ConfirmMergeButton"),
    "icon-ok",
  );
  const merge_group: number[] = [];
  const name_merge: string[] = [];
  // Always overwritten inside the loop below before use (`dest_grp`
  // always matches exactly one selected group's data-id) -- typed as a
  // string, not the original `[]` placeholder, which never matched the
  // value actually assigned or read.
  let name_dest = "";
  // Single-value <select>, never multi.
  const dest_grp = val(document.querySelectorAll("#MergeOptionsChoices")) ?? "";

  document.querySelectorAll(".DeleteGroupList div").forEach((el) => {
    if (dest_grp !== attrOf(el, "data-id")) {
      merge_group.push(Number(attrOf(el, "data-id")));
      name_merge.push(htmlOf(find(el, "p"))!);
    } else {
      name_dest = htmlOf(find(el, "p"))!;
    }
  });

  void ajax({
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
        const el = document.querySelectorAll("#group-" + String(id));
        fadeOut(el, () => {
          el.forEach((one) => {
            one.remove();
          });
        });
      });
      toogleSelection(dest_grp, false);
      html(document.querySelectorAll(".DeleteGroupList"), "");
      html(document.querySelectorAll("#MergeOptionsChoices"), "");

      alert({
        ...{
          title: str_merged_into
            .replace("%s1", name_merge.toString())
            .replace("%s2", name_dest),
          content: "",
        },
        ...jConfirm_alert_options,
      });

      html(
        document.querySelectorAll(
          "#group-" + dest_grp + " .group_number_users",
        ),
        "<i class='icon-spin6 animate-spin'> </i>",
      );
      void ajax({
        url: "api/v1/users",
        type: "GET",
        data: {
          groupIds: [dest_grp],
        },
        dataType: "json",
        success: function (response: GroupUserListResponse) {
          const number = response.users.length;
          html(
            document.querySelectorAll(
              "#group-" + dest_grp + " .group_number_users",
            ),
            String(number) +
              " " +
              (number > 1 ? str_members_default : str_member_default),
          );
          updateBadge();
        },
      });
    },
    error: function (err) {
      loadState.reverse();
      console.error(err);
    },
  });
});

/*-------
 Delete function on button's pannel
 -------*/

on(document.querySelectorAll(".ConfirmDeleteButton"), "click", function () {
  const names: string[] = [];
  const ids: (string | number)[] = [];
  document.querySelectorAll(".DeleteGroupList div").forEach((el) => {
    const id = attrOf(el, "data-id") ?? "";
    names.push(
      htmlOf(document.querySelectorAll("#group-" + id + " #group_name"))!,
    );
    ids.push(id);
  });

  const loadState = new TemporaryState();
  loadState.changeAttribute(
    document.querySelectorAll(".ConfirmDeleteButton"),
    "style",
    "pointer-events: none",
  );
  loadState.changeHTML(
    document.querySelectorAll(".ConfirmDeleteButton"),
    "<i class='icon-spin6 animate-spin'> </i>",
  );
  loadState.removeClass(
    document.querySelectorAll(".ConfirmDeleteButton"),
    "icon-ok",
  );

  // No bulk-delete endpoint (a REST single-resource DELETE per group,
  // per P27's own design) -- fire one DELETE per selected group.
  Promise.all(
    ids.map(function (id: string | number) {
      return ajax({
        url: "api/v1/groups/" + String(id),
        type: "DELETE",
        headers: {
          "X-CSRF-Token": pwg_token,
        },
        dataType: "json",
      });
    }),
  )
    .then(function () {
      document.querySelectorAll(".DeleteGroupList div").forEach((el) => {
        const dataId = attrOf(el, "data-id");
        el.remove();
        document.querySelector("#group-" + String(dataId))?.remove();
        document
          .querySelector(
            '#MergeOptionsChoices option[value="' + String(dataId) + '"]',
          )
          ?.remove();
      });

      loadState.reverse();
      updateSelectionPanel("NoSelection");
      alert({
        ...{
          title: str_groups_deleted.replace("%s", names.toString()),
          content: "",
        },
        ...jConfirm_alert_options,
      });
      updateBadge();
    })
    .catch(function (err: unknown) {
      loadState.reverse();
      console.error(err);
    });
});

/*-------
 Manage User Part
 -------*/

// Initialize the research user bar
let selectize: SelectizeInstance<string | number, UserSelectOption>;

// Initialize the cache -- placeholder cast, real init happens via
// `new UsersCache(...)` inside updateUserSearch()/at module load
// (below) before any handler that reads it can actually run.
// eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- deliberate placeholder, replaced by a real `new UsersCache(...)` before any handler that reads it can actually run.
let usersCache = {} as EntityCacheInstance<UserEntity>;

let usersInGroup: GroupMemberDisplay[] = [];

// Max offset of the user container (322 = 6 lines)
const maxOffsetUserCont = 322;

const dissociateUserInfo = parseHtml(
  "<div class='ValidationUserDissociated'>" +
    "<p class='icon-ok'></p>" +
    "</div>",
)[0]!;
document.querySelector(".group-name-block")?.appendChild(dissociateUserInfo);
hide(dissociateUserInfo);

const associateUserInfo = parseHtml(
  "<div class='ValidationUserAssociated'>" +
    "<p class='icon-ok'></p>" +
    "</div>",
)[0]!;

// Setup the user research bar
// Declared here, at module scope (not inside the ready callback below), because getUserDisplay()/the .input-user-name handler further down -- both textually outside this IIFE -- call it too. The real function body still closes over idSearch/selectize/usersCache exactly as before: JS closures capture their enclosing lexical scope by reference regardless of where the containing variable itself is declared.
let updateUserSearch: () => void;

ready(function () {
  selectize = createSelectize<string | number, UserSelectOption>(
    document.querySelector<HTMLSelectElement>(".AddUserBlock select")!,
    {},
  );

  let idSearch = "";
  on(document.querySelectorAll(".UserSearch input"), "focus", function () {
    if (
      idSearch !==
      attrOf(document.querySelectorAll("#UserList"), "data-group_id")
    ) {
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
    usersCache = new UsersCache({
      serverKey: serverKey,
      serverId: serverId,
      rootUrl: rootUrl,
    });
    // Non-null: `new UsersCache(...)` above synchronously
    // seeds this storage slot before any handler that reads it can run
    // (the P46-preserved "temporary fix for #1283" behavior, see the
    // module-load call at the bottom of this file).
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- self-write/self-read cache: usersCache's own constructor just seeded this slot synchronously (see comment above).
    const cached = JSON.parse(usersCache.storage.getItem(usersCache.key)!) as {
      data: UserEntity[];
    };
    cached.data.forEach(function (u) {
      selectize.addOption({ value: u.id, text: u.username });
    });
    idSearch =
      attrOf(document.querySelectorAll("#UserList"), "data-group_id") ?? "";
    // Was `value.username` -- no such field exists on the actual
    // selectize option data (`addOption({value, text})` above only ever
    // sets `value`/`text`), so this comparison was always false and the
    // guest user was never really filtered out of the search dropdown.
    for (const [_key, value] of Object.entries(selectize.options)) {
      if (value.text === "guest") {
        selectize.removeOption(value.value);
      }
    }
    document.querySelectorAll(".UsernameBlock").forEach((el) => {
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- data() reads a data-* attribute this same app's own setData()/template writes, never adversarial input.
      selectize.removeOption(data(el, "id") as string | number);
    });
  };
});

// Display the user manager for a specific group
function openUserManager(grp_id: string | number) {
  const loadState = new TemporaryState();
  loadState.removeClass(
    document.querySelectorAll("#group-" + String(grp_id) + " #UserListTrigger"),
    "icon-user-1",
  );
  loadState.changeAttribute(
    document.querySelectorAll("#group-" + String(grp_id) + " #UserListTrigger"),
    "style",
    "pointer-events: none",
  );
  loadState.changeHTML(
    document.querySelectorAll("#group-" + String(grp_id) + " #UserListTrigger"),
    "<i class='icon-spin6 animate-spin'> </i>",
  );
  void ajax({
    url: "api/v1/users",
    type: "GET",
    data: {
      groupIds: [grp_id],
    },
    dataType: "json",
    success: function (response: GroupUserListResponse) {
      loadState.reverse();
      //Set the popin name
      html(
        document.querySelectorAll(".group-name-block p"),
        (htmlOf(
          document.querySelectorAll(
            "#group-" + String(grp_id) + " #group_name",
          ),
        ) ?? "") +
          " / " +
          str_user_list,
      );
      html(document.querySelectorAll(".UsersInGroupList"), "");

      //Display the popin
      fadeIn(document.querySelectorAll("#UserList"));

      //Fill with user blocks
      usersInGroup = response.users;
      // Sort in alphabetic order
      usersInGroup.sort(function (a, b) {
        if (a.username.toLowerCase() < b.username.toLowerCase()) {
          return -1;
        } else return 1;
      });
      let i = 0;
      const usersInGroupList =
        document.querySelector<HTMLElement>(".UsersInGroupList")!;
      while (
        outerHeight(usersInGroupList) <= maxOffsetUserCont &&
        usersInGroup[i] !== undefined
      ) {
        usersInGroupList.appendChild(
          getUserDisplay(
            usersInGroup[i]!.username,
            usersInGroup[i]!.id,
            grp_id,
          ),
        );
        i++;
      }
      while (usersInGroupList.offsetHeight > maxOffsetUserCont) {
        document
          .querySelectorAll(".UsernameBlock")
          .item(document.querySelectorAll(".UsernameBlock").length - 1)
          // eslint-disable-next-line @typescript-eslint/no-unnecessary-condition -- real runtime guard: NodeListOf.item() is typed non-nullable but really returns null for an out-of-range index (the list can be empty here).
          ?.remove();
      }
      updateMembernumber(usersInGroup.length, grp_id);
      //Attribute the group id to the div
      attr(
        document.querySelectorAll("#UserList"),
        "data-group_id",
        String(grp_id),
      );

      attr(
        document.querySelectorAll(".LinkUserManager a"),
        "href",
        "admin.php?page=user_list&group=" + String(grp_id),
      );
    },
    error: function (err) {
      loadState.reverse();
      console.error(err);
    },
  });
}

//Add a user block
function getUserDisplay(
  username: string,
  user_id: string | number,
  grp_id: string | number,
): Element {
  const userBlock = parseHtml(
    '<div class="UsernameBlock" data-id=' +
      String(user_id) +
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
  )[0]!;

  const usersInGroupList =
    document.querySelector<HTMLElement>(".UsersInGroupList")!;
  while (usersInGroupList.offsetHeight > maxOffsetUserCont) {
    const blocks = document.querySelectorAll(".UsernameBlock");
    // eslint-disable-next-line @typescript-eslint/no-unnecessary-condition -- real runtime guard: NodeListOf.item() is typed non-nullable but really returns null for an out-of-range index (the list can be empty here).
    blocks.item(blocks.length - 1)?.remove();
  }

  //Setup the delete action
  on(find(userBlock, ".icon-cancel"), "click", function () {
    addClass(find(userBlock, ".icon-cancel"), "icon-spin6");
    addClass(find(userBlock, ".icon-cancel"), "animate-spin");
    css(find(userBlock, ".icon-cancel"), "pointer-events", "none");
    removeClass(find(userBlock, ".icon-cancel"), "icon-cancel");
    void ajax({
      url: "api/v1/groups/" + String(grp_id) + "/actions/remove-user",
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
        fadeOut(associateUserInfo);
        html(find(dissociateUserInfo, "p"), str);
        fadeIn(dissociateUserInfo);

        const usernameBlocks = document.querySelectorAll(".UsernameBlock");
        css(usernameBlocks, "margin-right", "10px");
        css(usernameBlocks, "border", "none");
        userBlock.remove();

        updateUserSearch();

        while (usersInGroupList.offsetHeight > maxOffsetUserCont) {
          const blocks = document.querySelectorAll(".UsernameBlock");
          // eslint-disable-next-line @typescript-eslint/no-unnecessary-condition -- real runtime guard: NodeListOf.item() is typed non-nullable but really returns null for an out-of-range index (the list can be empty here).
          blocks.item(blocks.length - 1)?.remove();
        }

        usersInGroup = usersInGroup.filter((u) => u.id !== Number(user_id));

        //Update member number
        updateMembernumber(
          parseInt(
            htmlOf(document.querySelectorAll(".UserNumberBadge")) ?? "0",
          ) - 1,
          grp_id,
        );
      },
    });
  });
  return userBlock;
}

//Update member number function
function updateMembernumber(number: number, grp_id: string | number) {
  html(
    document.querySelectorAll(
      '.GroupContainer[data-id="' + String(grp_id) + '"] .group_number_users',
    ),
    String(number) +
      " " +
      (number > 1 ? str_members_default : str_member_default),
  );
  html(document.querySelectorAll(".UserNumberBadge"), String(number));
  html(
    document.querySelectorAll(".AmountOfUsersShown strong:nth-child(2)"),
    String(number),
  );
  html(
    document.querySelectorAll(".AmountOfUsersShown strong:nth-child(1)"),
    String(document.querySelectorAll(".UsernameBlock").length),
  );
}

// Close pop-up on cross click
on(document.querySelectorAll(".CloseUserList"), "click", function () {
  fadeOut(document.querySelectorAll("#UserList"));
});

// Adding Group Action
on(document.querySelectorAll(".AddUserBlock button"), "click", function () {
  const grp_id =
    attrOf(document.querySelectorAll("#UserList"), "data-group_id") ?? "";
  // This instance is created without `multiple: true` (see
  // createSelectize() call above), so its own `getValue(): T | T[]`
  // signature -- shared with the multi-select case -- only ever returns
  // a single T here, never an array.
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- verified above: a non-multiple selectize instance's getValue() never returns an array.
  const id = selectize.getValue() as string | number;

  if (String(id) !== "") {
    const loadState = new TemporaryState();
    loadState.changeHTML(
      document.querySelectorAll("#UserSubmit"),
      "<i class='icon-spin6 animate-spin'> </i>",
    );
    loadState.removeClass(
      document.querySelectorAll("#UserSubmit"),
      "icon-user-add",
    );
    loadState.changeAttribute(
      document.querySelectorAll("#UserSubmit"),
      "css",
      "pointer-events:none",
    );
    void ajax({
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
        // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- self-write/self-read cache, same reasoning as updateUserSearch()'s own identical read above.
        const cached = JSON.parse(
          usersCache.storage.getItem(usersCache.key)!,
        ) as {
          data: UserEntity[];
        };
        cached.data.forEach(function (u) {
          if (u.id === Number(id)) {
            username = u.username;
          }
        });
        const userBlock = getUserDisplay(username, id, grp_id);
        document.querySelector(".UsersInGroupList")?.prepend(userBlock);

        fadeOut(dissociateUserInfo);

        const firstUsernameBlock = document.querySelector(".UsernameBlock");
        if (firstUsernameBlock !== null) {
          addClass(firstUsernameBlock, "success_message");
        }
        const restUsernameBlocks = Array.from(
          document.querySelectorAll(".UsernameBlock"),
        ).slice(1);
        css(restUsernameBlocks, "margin-right", "10px");
        css(restUsernameBlocks, "border", "none");
        associateUserInfo.remove();
        userBlock.after(associateUserInfo);
        html(find(associateUserInfo, "p"), str_user_associated);
        fadeIn(associateUserInfo);

        updateUserSearch();

        usersInGroup.push({ username: username, id: id });

        const usersInGroupList =
          document.querySelector<HTMLElement>(".UsersInGroupList")!;
        while (usersInGroupList.offsetHeight > maxOffsetUserCont) {
          const blocks = document.querySelectorAll(".UsernameBlock");
          // eslint-disable-next-line @typescript-eslint/no-unnecessary-condition -- real runtime guard: NodeListOf.item() is typed non-nullable but really returns null for an out-of-range index (the list can be empty here).
          blocks.item(blocks.length - 1)?.remove();
        }

        //Update member number
        updateMembernumber(
          parseInt(
            htmlOf(document.querySelectorAll(".UserNumberBadge")) ?? "0",
          ) + 1,
          grp_id,
        );
      },
      error: function (err) {
        loadState.reverse();
        console.error(err);
      },
    });
  }
});

on(document.querySelectorAll(".input-user-name"), "input", function () {
  const searchString = String(
    val(document.querySelectorAll(".input-user-name")),
  ).toLowerCase();
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- data() reads a data-* attribute this same app's own setData()/template writes, never adversarial input.
  const grp_id = data(document.querySelector(".UserListPopIn")!, "group_id") as
    string | number;
  const container = document.querySelector(".UsersInGroupListContainer")!;
  const usersInGroupList =
    document.querySelector<HTMLElement>(".UsersInGroupList")!;
  if (searchString !== "") {
    css(container, "min-height", cssValue(container, "height"));
    usersInGroup.forEach(function (u) {
      const isSearched = u.username.toLowerCase().includes(searchString);
      const existing = document.querySelector(
        '.UsernameBlock[data-id="' + String(u.id) + '"]',
      );
      if (existing !== null) {
        if (!isSearched) {
          existing.remove();
        }
      } else if (isSearched) {
        usersInGroupList.prepend(getUserDisplay(u.username, u.id, grp_id));
      }
    });
  } else {
    css(container, "min-height", "");
    html(document.querySelectorAll(".UsersInGroupList"), "");
    let i = 0;
    while (
      outerHeight(usersInGroupList) <= maxOffsetUserCont &&
      usersInGroup[i] !== undefined
    ) {
      usersInGroupList.appendChild(
        getUserDisplay(usersInGroup[i]!.username, usersInGroup[i]!.id, grp_id),
      );
      i++;
    }
  }
  html(
    document.querySelectorAll(".AmountOfUsersShown strong:nth-child(1)"),
    String(document.querySelectorAll(".UsernameBlock").length),
  );
  while (usersInGroupList.offsetHeight > maxOffsetUserCont) {
    const blocks = document.querySelectorAll(".UsernameBlock");
    // eslint-disable-next-line @typescript-eslint/no-unnecessary-condition -- real runtime guard: NodeListOf.item() is typed non-nullable but really returns null for an out-of-range index (the list can be empty here).
    blocks.item(blocks.length - 1)?.remove();
  }
});

on(document, "keydown", function (e: Event) {
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- "keydown" always dispatches a real KeyboardEvent; on()'s own handler param is typed generically via the native EventListener interface.
  if ((e as KeyboardEvent).key === "Escape") {
    fadeOut(document.querySelectorAll("#UserList"));
  }
});
on(document, "click", function (e: Event) {
  if (
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- a real click inside the document always targets an Element (or null), never a bare EventTarget with no Element interface.
    (e.target as Element | null)?.closest(".UserListPopInContainer") === null
  ) {
    fadeOut(document.querySelectorAll("#UserList"));
  }
});

// temporary fix for #1283 (begin) : force user local storage cache on page load.
usersCache = new UsersCache({
  serverKey: serverKey,
  serverId: serverId,
  rootUrl: rootUrl,
});

usersCache.selectize(document.querySelectorAll("select.UserSearch"));
// temporary fix for #1283 (end)

// Explicit `window.` exposure -- required at runtime, not decorative
// (see plugins_installed_config.ts's own leading comment for the full
// explanation). `hideAddGroupForm` is called from group_list.latte's
// own `onclick="hideAddGroupForm()"` attribute; `updateSelectionPanel`
// is called from a dynamically-set `onclick` HTML attribute
// (buttonAvailable()'s own `attr(button, "onclick", onClick)`, a string
// like `"updateSelectionPanel('Selection')"`) -- the exact same
// javascript:/onclick= exposure requirement, just set via JS instead
// of a static Latte attribute.
window.hideAddGroupForm = hideAddGroupForm;
window.updateSelectionPanel = updateSelectionPanel;
