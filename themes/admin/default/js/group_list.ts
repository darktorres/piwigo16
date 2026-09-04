import type { components, operations } from "../../../../openapi/client/schema";
// common.ts's own side effects (font-checkbox init, search-cancel
// bindings) -- group_list.latte's own generic
// `.search-cancel`/`.search-input` pair needs the shared wiring; this
// page used to get it incidentally, as a side effect of importing from
// what was then the same file (common.ts); the P51-I split made that
// dependency explicit instead of leaving it accidental.
import "./common";
import { jConfirmAlertOptions } from "./jconfirmPresets";
import { TemporaryState } from "./TemporaryState";
// See groupUserManager.ts's own leading comment for why this popup is
// its own file (docs/PLAN.md P51-I item 5).
import { openUserManager } from "./groupUserManager";

import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../default/js/page-data";
import { ajax } from "../../../default/js/vendor/ajax";
import { alert, confirm } from "../../../default/js/vendor/jconfirm";
import {
  addClass,
  animate,
  attr,
  attrOf,
  css,
  data,
  dataId,
  escapeId,
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
  valId,
} from "../../../default/js/vendor/dom";

type Group = components["schemas"]["Group"];
// The real `GET /api/v1/users?groupIds[]=...` response shape, read here
// only by the merge-groups flow's own post-merge member-count refresh
// below -- groupUserManager.ts declares its own identical local alias
// for its own, unrelated real use of the same endpoint (docs/PLAN.md
// P51-I item 5's own extraction, matching this codebase's established
// per-file local-alias convention for OpenAPI-derived shapes rather
// than a cross-file type import for two call sites).
type GroupUserListResponse =
  operations["userList"]["responses"][200]["content"]["application/json"];

const DELAY_FEEDBACK = 3000;

const pwgToken = pwg_getPageData<string>("csrf_token");
const strMemberDefault = pwg_getPageString("member");
const strMembersDefault = pwg_getPageString("members");
const strGroupCreated = pwg_getPageString("Group added");
const strRenamingDone = pwg_getPageString("Group renamed");
const strNameTaken = pwg_getPageString("Name is already taken");
const strNameNotEmpty = pwg_getPageString("Name field must not be empty");
const strGroupDeleted = pwg_getPageString('Group "%s" succesfully deleted');
const strGroupsDeleted = pwg_getPageString("Groups {%s} succesfully deleted");
const strSetDefault = pwg_getPageString("Set as group for new users");
const strUnsetDefault = pwg_getPageString("Unset as group for new users");
const strDelete = pwg_getPageString(
  'Are you sure you want to delete group "%s"?',
);
const strYesDeleteConfirmation = pwg_getPageString("Yes, delete");
const strNoDeleteConfirmation = pwg_getPageString("No, I have changed my mind");
const strMergedInto = pwg_getPageString(
  'Group(s) {%s1} succesfully merged into "%s2"',
);
const strCopy = pwg_getPageString(" (copy)");
const strOtherCopy = pwg_getPageString(" (copy %s)");

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
      void (async () => {
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
          try {
            // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
            const group = (await ajax({
              url: "api/v1/groups",
              type: "POST",
              headers: {
                "X-CSRF-Token": pwgToken,
              },
              json: {
                name: name,
              },
              dataType: "json",
            })) as operations["groupCreate"]["responses"][201]["content"]["application/json"];
            loadState.reverse();
            setVal(
              document.querySelectorAll(".addGroupFormLabelAndInput input"),
              "",
            );
            const groupBox = createGroup(group);
            document.querySelector("#addGroupForm")?.after(groupBox);
            setupGroupBox(groupBox);
            updateBadge();
          } catch {
            loadState.reverse();
            html(
              document.querySelectorAll("#addGroupForm .groupError"),
              strNameNotEmpty,
            );
            fadeIn(document.querySelectorAll("#addGroupForm .groupError"));
            const errorEl = document.querySelectorAll(
              "#addGroupForm .groupError",
            );
            setTimeout(() => {
              fadeOut(errorEl);
            }, DELAY_FEEDBACK);
          }
        } else {
          loadState.reverse();
          html(
            document.querySelectorAll("#addGroupForm .groupError"),
            strNameNotEmpty,
          );
          fadeIn(document.querySelectorAll("#addGroupForm .groupError"));
          const errorEl = document.querySelectorAll(
            "#addGroupForm .groupError",
          );
          setTimeout(() => {
            fadeOut(errorEl);
          }, DELAY_FEEDBACK);
        }
      })();
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
      (group.nbUsers > 1 ? strMembersDefault : strMemberDefault),
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
  html(find(newgroup, ".groupMessage"), strGroupCreated);
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
    // Real, pre-existing bug found while adding dataId()'s own assertion
    // (P51-D): `.GroupContainer` also matches `#group-template` (its own
    // `data-id="template"`, a non-numeric placeholder -- group_list.latte's
    // own `{include groupContent, grpId: "template", ...}`) and
    // `#addGroupForm` (no `data-id` at all -- it only shares the class for
    // layout). Both used to silently reach `setupGroupBox()` with `id`
    // ending up `undefined`, since nothing here ever checked; `typeof ===
    // "number"` is the real, robust exclusion (every genuine group row's
    // `data-id` is a real database row id), not a magic-string id match.
    if (typeof data(groupBox, "id") === "number") setupGroupBox(groupBox);
  });
});
function setupGroupBox(groupBox: Element) {
  const id = dataId(groupBox, "id");

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
    void renameGroup(id, String(val(find(groupBox, ".group_name-editable"))));
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
    let optionIsClicked = false;
    document.querySelectorAll("#GroupOptions div").forEach((option) => {
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- a real mouseup event's own target inside the document is always a Node (or null), never a bare EventTarget with no Node interface.
      if (option.contains(e.target as Node | null)) {
        optionIsClicked = true;
      }
    });
    // eslint-disable-next-line @typescript-eslint/no-unnecessary-condition -- real false positive from closure mutation: optionIsClicked is set inside the forEach callback above, which the rule doesn't track (same class as dom.ts's stopped).
    if (!optionIsClicked) {
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
    void openUserManager(id);
  });

  on(find(groupBox, "#GroupDuplicate"), "click", function () {
    void duplicateAction(id);
  });
}

function toogleSelection(group_id: number, toggleOn: boolean) {
  const groupBox = document.querySelectorAll(
    "#" + escapeId("group-" + String(group_id)),
  );
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
function deleteGroup(id: number) {
  confirm({
    title: strDelete.replace(
      "%s",
      htmlOf(
        document.querySelectorAll(
          "#" + escapeId("group-" + String(id)) + " #group_name",
        ),
      )!,
    ),
    titleClass: "jconfirmDeleteConfirm",
    content: "",
    boxWidth: "30%",
    type: "red",
    buttons: {
      confirm: {
        text: strYesDeleteConfirmation,
        btnClass: "btn-red",
        action: function () {
          const groupName = htmlOf(
            document.querySelectorAll(
              "#" +
                escapeId("group-" + String(id)) +
                " .Group-name-container p",
            ),
          )!;
          alert({
            ...{
              title: strGroupDeleted.replace("%s", groupName),
              // eslint-disable-next-line @typescript-eslint/promise-function-async -- must return ajax()'s own AjaxThenable (jconfirm.ts's `isThenable()` checks for its real `.always()`); `async` would re-wrap it through `Promise.resolve()` and lose that method.
              content: function () {
                return ajax({
                  url: "api/v1/groups/" + String(id),
                  type: "DELETE",
                  headers: {
                    "X-CSRF-Token": pwgToken,
                  },
                  dataType: "json",
                  success: function (
                    _data: operations["groupDelete"]["responses"][200]["content"]["application/json"],
                  ) {
                    document
                      .querySelector("#" + escapeId("group-" + String(id)))
                      ?.remove();
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
            ...jConfirmAlertOptions,
          });
        },
      },
      cancel: {
        text: strNoDeleteConfirmation,
      },
    },
  });
}

async function renameGroup(id: number, newName: string): Promise<void> {
  const loadState = new TemporaryState();
  loadState.changeHTML(
    document.querySelectorAll(
      "#" + escapeId("group-" + String(id)) + " .group-rename .validate",
    ),
    "<i class='animate-spin icon-spin6'></i>",
  );
  loadState.removeClass(
    document.querySelectorAll(
      "#" + escapeId("group-" + String(id)) + " .group-rename .validate",
    ),
    "icon-ok",
  );
  loadState.changeAttribute(
    document.querySelectorAll(
      "#" + escapeId("group-" + String(id)) + " .group-rename span",
    ),
    "style",
    "pointer-events: none",
  );

  if (newName.replace(/\s/g, "").length !== 0) {
    try {
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
      const response = (await ajax({
        url: "api/v1/groups/" + String(id),
        type: "PATCH",
        headers: {
          "X-CSRF-Token": pwgToken,
        },
        json: {
          name: newName,
        },
        dataType: "json",
      })) as operations["groupUpdate"]["responses"][200]["content"]["application/json"];
      loadState.reverse();
      const confirmedName = response.name;
      //Display message
      html(
        find(
          document.querySelectorAll("#" + escapeId("group-" + String(id))),
          ".groupMessage",
        ),
        strRenamingDone,
      );
      const groupMessage = find(
        document.querySelectorAll("#" + escapeId("group-" + String(id))),
        ".groupMessage",
      );
      fadeIn(groupMessage);
      setTimeout(() => {
        fadeOut(groupMessage);
      }, DELAY_FEEDBACK);
      html(
        find(
          document.querySelectorAll("#" + escapeId("group-" + String(id))),
          "#group_name",
        ),
        confirmedName,
      );

      //Hide editable field
      displayRenameForm(false, id);
    } catch {
      loadState.reverse();
      //Display error message
      html(
        find(
          document.querySelectorAll("#" + escapeId("group-" + String(id))),
          ".groupError",
        ),
        strNameTaken,
      );
      const groupError = find(
        document.querySelectorAll("#" + escapeId("group-" + String(id))),
        ".groupError",
      );
      fadeIn(groupError);
      setTimeout(() => {
        fadeOut(groupError);
      }, DELAY_FEEDBACK);
    }
  } else {
    loadState.reverse();
    html(
      find(
        document.querySelectorAll("#" + escapeId("group-" + String(id))),
        ".groupError",
      ),
      strNameNotEmpty,
    );
    const groupError = find(
      document.querySelectorAll("#" + escapeId("group-" + String(id))),
      ".groupError",
    );
    fadeIn(groupError);
    setTimeout(() => {
      fadeOut(groupError);
    }, DELAY_FEEDBACK);
  }
}

// Hide or display rename form
function displayRenameForm(doDisplay: boolean, grpId: number) {
  if (doDisplay) {
    css(
      find(
        document.querySelectorAll("#" + escapeId("group-" + String(grpId))),
        ".group-rename",
      ),
      "display",
      "flex",
    );
    hide(
      find(
        document.querySelectorAll("#" + escapeId("group-" + String(grpId))),
        ".Group-name-container .icon-pencil",
      ),
    );
    css(
      find(
        document.querySelectorAll("#" + escapeId("group-" + String(grpId))),
        ".Group-name-container p",
      ),
      "opacity",
      0,
    );
  } else {
    hide(
      find(
        document.querySelectorAll("#" + escapeId("group-" + String(grpId))),
        ".group-rename",
      ),
    );
    removeAttr(
      find(
        document.querySelectorAll("#" + escapeId("group-" + String(grpId))),
        ".Group-name-container .icon-pencil",
      ),
      "style",
    );
    css(
      find(
        document.querySelectorAll("#" + escapeId("group-" + String(grpId))),
        ".Group-name-container p",
      ),
      "opacity",
      1,
    );
  }
}

const setDefaultGroup = async function (
  id: number,
  is_default: boolean,
): Promise<void> {
  const groupDefault = document.querySelectorAll(
    "#" + escapeId("group-" + String(id)) + " #GroupDefault",
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
      document.querySelectorAll("#" + escapeId("group-" + String(id))),
      ".is-default-token",
    ),
    "icon-spin6",
  );
  addClass(
    find(
      document.querySelectorAll("#" + escapeId("group-" + String(id))),
      ".is-default-token",
    ),
    "animate-spin",
  );
  removeClass(
    find(
      document.querySelectorAll("#" + escapeId("group-" + String(id))),
      ".is-default-token",
    ),
    "icon-star",
  );
  try {
    await ajax({
      url: "api/v1/groups/" + String(id),
      type: "PATCH",
      headers: {
        "X-CSRF-Token": pwgToken,
      },
      json: {
        isDefault: is_default,
      },
      dataType: "json",
    });
    hide(
      document.querySelectorAll(
        "#" + escapeId("group-" + String(id)) + " #GroupOptions",
      ),
    );
    if (is_default) {
      setupDefaultActions(id, true);
    } else {
      setupDefaultActions(id, false);
    }
  } catch (err) {
    console.error(err);
  }
};

function setupDefaultActions(id: number, is_default: boolean) {
  attr(
    document.querySelectorAll(
      "#" + escapeId("group-" + String(id)) + " #GroupDefault",
    ),
    "style",
    "",
  );
  addClass(
    document.querySelectorAll(
      "#" + escapeId("group-" + String(id)) + " #GroupDefault",
    ),
    "icon-star",
  );
  const token = find(
    document.querySelectorAll("#" + escapeId("group-" + String(id))),
    ".is-default-token",
  );
  removeClass(token, "icon-spin6");
  removeClass(token, "animate-spin");
  addClass(token, "icon-star");
  if (is_default) {
    html(
      find(
        document.querySelectorAll("#" + escapeId("group-" + String(id))),
        "#GroupDefault",
      ),
      strUnsetDefault,
    );
    attr(
      find(
        document.querySelectorAll("#" + escapeId("group-" + String(id))),
        ".is-default-token",
      ),
      "title",
      strUnsetDefault,
    );
    // `.unbind("click")` removes every click handler this element has,
    // regardless of which closure added it -- dom.ts's own `off(target,
    // "click")` (no handler argument) matches that, over its own
    // registry of `on()`-added listeners.
    off(
      document.querySelectorAll(
        "#" + escapeId("group-" + String(id)) + " #GroupDefault",
      ),
      "click",
    );
    removeClass(
      find(
        document.querySelectorAll("#" + escapeId("group-" + String(id))),
        ".is-default-token",
      ),
      "deactivate",
    );
    on(
      document.querySelectorAll(
        "#" + escapeId("group-" + String(id)) + " #GroupDefault",
      ),
      "click",
      function () {
        void setDefaultGroup(id, false);
      },
    );
    on(
      find(
        document.querySelectorAll("#" + escapeId("group-" + String(id))),
        ".is-default-token",
      ),
      "click",
      function () {
        void setDefaultGroup(id, false);
      },
    );
  } else {
    html(
      find(
        document.querySelectorAll("#" + escapeId("group-" + String(id))),
        "#GroupDefault",
      ),
      strSetDefault,
    );
    attr(
      find(
        document.querySelectorAll("#" + escapeId("group-" + String(id))),
        ".is-default-token",
      ),
      "title",
      strSetDefault,
    );
    addClass(
      find(
        document.querySelectorAll("#" + escapeId("group-" + String(id))),
        ".is-default-token",
      ),
      "deactivate",
    );
    on(
      document.querySelectorAll(
        "#" + escapeId("group-" + String(id)) + " #GroupDefault",
      ),
      "click",
      function () {
        void setDefaultGroup(id, true);
      },
    );
    off(
      find(
        document.querySelectorAll("#" + escapeId("group-" + String(id))),
        ".is-default-token",
      ),
      "click",
    );
  }
}

async function duplicateAction(id: number): Promise<void> {
  const loadState = new TemporaryState();
  loadState.changeHTML(
    document.querySelectorAll(
      "#" + escapeId("group-" + String(id)) + " #GroupDuplicate",
    ),
    "<i class='icon-spin6 animate-spin'> </i>",
  );
  loadState.removeClass(
    document.querySelectorAll(
      "#" + escapeId("group-" + String(id)) + " #GroupDuplicate",
    ),
    "icon-docs",
  );
  loadState.changeAttribute(
    document.querySelectorAll(
      "#" + escapeId("group-" + String(id)) + " #GroupDuplicate",
    ),
    "style",
    "pointer-events: none; text-align: center;",
  );
  let copyName =
    (htmlOf(
      document.querySelectorAll(
        "#" + escapeId("group-" + String(id)) + " #group_name",
      ),
    ) ?? "") + strCopy;

  const nameExist = function (name: string) {
    let exist = false;
    document.querySelectorAll(".Group-name-container p").forEach((el) => {
      if (htmlOf(el) === name) exist = true;
    });
    return exist;
  };

  let i = 1;
  while (nameExist(copyName)) {
    copyName =
      (htmlOf(
        document.querySelectorAll(
          "#" + escapeId("group-" + String(id)) + " #group_name",
        ),
      ) ?? "") + strOtherCopy.replace("%s", String(i++));
  }

  try {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
    const response = (await ajax({
      url: "api/v1/groups/" + String(id) + "/actions/duplicate",
      type: "POST",
      headers: {
        "X-CSRF-Token": pwgToken,
      },
      json: {
        name: copyName,
      },
      dataType: "json",
    })) as operations["groupDuplicate"]["responses"][201]["content"]["application/json"];
    loadState.reverse();
    hide(
      document.querySelectorAll(
        "#" + escapeId("group-" + String(id)) + " #GroupOptions",
      ),
    );
    const group = response;
    const groupbox = createGroup(group);
    document
      .querySelector("#" + escapeId("group-" + String(id)))
      ?.after(groupbox);
    setupGroupBox(groupbox);
    updateBadge();

    if (response.isDefault) {
      setupDefaultActions(response.id, true);
    }
  } catch (err) {
    loadState.reverse();
    console.error(err);
  }
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

function updateSelectionPanel(changedState = "") {
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
function updateStatePanel(newState = "Selection") {
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
  void (async () => {
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
    const mergeGroup: number[] = [];
    const nameMerge: string[] = [];
    // Always overwritten inside the loop below before use (`destGrp`
    // always matches exactly one selected group's data-id) -- typed as a
    // string, not the original `[]` placeholder, which never matched the
    // value actually assigned or read.
    let nameDest = "";
    // Single-value <select>, never multi. `valId()` returns null only when
    // nothing is selected, which can't happen here -- the merge button is
    // only reachable once 2+ groups are already selected, and each one adds
    // a real <option> to this same <select> (see toogleSelection() above).
    const destGrp = valId(document.querySelectorAll("#MergeOptionsChoices"));
    if (destGrp === null) {
      return;
    }

    document.querySelectorAll(".DeleteGroupList div").forEach((el) => {
      const elId = Number(attrOf(el, "data-id"));
      if (destGrp !== elId) {
        mergeGroup.push(elId);
        nameMerge.push(htmlOf(find(el, "p"))!);
      } else {
        nameDest = htmlOf(find(el, "p"))!;
      }
    });

    try {
      await ajax({
        url: "api/v1/groups/actions/merge",
        type: "POST",
        headers: {
          "X-CSRF-Token": pwgToken,
        },
        json: {
          destinationGroupId: destGrp,
          mergeGroupIds: mergeGroup,
        },
        dataType: "json",
      });
      loadState.reverse();
      updateSelectionPanel("Selection");
      mergeGroup.forEach(function (id: number) {
        const el = document.querySelectorAll(
          "#" + escapeId("group-" + String(id)),
        );
        fadeOut(el, () => {
          el.forEach((one) => {
            one.remove();
          });
        });
      });
      toogleSelection(destGrp, false);
      html(document.querySelectorAll(".DeleteGroupList"), "");
      html(document.querySelectorAll("#MergeOptionsChoices"), "");

      alert({
        ...{
          title: strMergedInto
            .replace("%s1", nameMerge.toString())
            .replace("%s2", nameDest),
          content: "",
        },
        ...jConfirmAlertOptions,
      });

      html(
        document.querySelectorAll(
          "#" + escapeId("group-" + String(destGrp)) + " .group_number_users",
        ),
        "<i class='icon-spin6 animate-spin'> </i>",
      );
      try {
        // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
        const response = (await ajax({
          url: "api/v1/users",
          type: "GET",
          data: {
            groupIds: [destGrp],
          },
          dataType: "json",
        })) as GroupUserListResponse;
        const number = response.users.length;
        html(
          document.querySelectorAll(
            "#" + escapeId("group-" + String(destGrp)) + " .group_number_users",
          ),
          String(number) +
            " " +
            (number > 1 ? strMembersDefault : strMemberDefault),
        );
        updateBadge();
      } catch {
        // No error handling before conversion either.
      }
    } catch (err) {
      loadState.reverse();
      console.error(err);
    }
  })();
});

/*-------
 Delete function on button's pannel
 -------*/

on(document.querySelectorAll(".ConfirmDeleteButton"), "click", function () {
  void (async () => {
    const names: string[] = [];
    const ids: number[] = [];
    document.querySelectorAll(".DeleteGroupList div").forEach((el) => {
      const id = Number(attrOf(el, "data-id"));
      names.push(
        htmlOf(
          document.querySelectorAll(
            "#" + escapeId("group-" + String(id)) + " #group_name",
          ),
        )!,
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
    try {
      await Promise.all(
        ids.map(async function (id: number) {
          return ajax({
            url: "api/v1/groups/" + String(id),
            type: "DELETE",
            headers: {
              "X-CSRF-Token": pwgToken,
            },
            dataType: "json",
          });
        }),
      );
      document.querySelectorAll(".DeleteGroupList div").forEach((el) => {
        const elId = Number(attrOf(el, "data-id"));
        el.remove();
        document
          .querySelector("#" + escapeId("group-" + String(elId)))
          ?.remove();
        document
          .querySelector(
            '#MergeOptionsChoices option[value="' + String(elId) + '"]',
          )
          ?.remove();
      });

      loadState.reverse();
      updateSelectionPanel("NoSelection");
      alert({
        ...{
          title: strGroupsDeleted.replace("%s", names.toString()),
          content: "",
        },
        ...jConfirmAlertOptions,
      });
      updateBadge();
    } catch (err) {
      loadState.reverse();
      console.error(err);
    }
  })();
});

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
