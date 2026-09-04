// Extracted from group_list.ts's own "Manage User Part" (docs/PLAN.md
// P51-I item 5) -- a genuinely separable concern: this popup manages
// one group's member list, reached from group_list.ts through exactly
// one call boundary (openUserManager(), wired to the "manage members"
// trigger), plus DOM-only coupling for group_list.ts's own rendered
// ".GroupContainer .group_number_users" badge (updateMembernumber()
// below writes to it by selector, the same loose coupling common.ts's
// fontCheckbox() already uses across many unrelated pages). No shared
// mutable state with group_list.ts's own group CRUD/selection code.
import { TemporaryState } from "./TemporaryState";
import { UsersCache } from "./LocalStorageCache";
// Type-only -- erased at compile time, so it never reaches Rollup's
// module graph at all.
import type { UserEntity } from "./LocalStorageCache";

import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../default/js/page-data";
import { ajax } from "../../../default/js/vendor/ajax";
import {
  selectize as createSelectize,
  type SelectizeInstance,
} from "../../../default/js/vendor/selectize";
import {
  addClass,
  attr,
  attrOf,
  css,
  cssValue,
  dataId,
  escapeId,
  fadeIn,
  fadeOut,
  find,
  hide,
  html,
  htmlOf,
  on,
  outerHeight,
  parseHtml,
  ready,
  removeClass,
  val,
} from "../../../default/js/vendor/dom";
import type { operations } from "../../../../openapi/client/schema";

// Same real `GET /api/v1/users?groupIds[]=...` response shape
// group_list.ts's own merge-into-group flow also reads -- duplicated
// here rather than imported, matching this codebase's established
// per-file local-alias convention for OpenAPI-derived shapes (e.g.
// user_list.ts's own `UserRow` alongside group_list.ts's `Group`).
type GroupUserListResponse =
  operations["userList"]["responses"][200]["content"]["application/json"];
// This popup's own `usersInGroup` list holds either a real fetched user
// row, or a locally-synthesized partial one appended right after a
// successful add-user call (before any refetch) -- only `id`/`username`
// are ever read from either shape throughout this file.
interface GroupMemberDisplay {
  id: number;
  username: string;
}
interface UserSelectOption extends Record<string, unknown> {
  value: string | number;
  text: string;
}

const pwgToken = pwg_getPageData<string>("csrf_token");
const strMemberDefault = pwg_getPageString("member");
const strMembersDefault = pwg_getPageString("members");
const strUserAssociated = pwg_getPageString("User associated");
const strUserDissociate = pwg_getPageString("Dissociate user from this group");
const strUserDissociated = pwg_getPageString(
  'User "%s" dissociated from this group',
);
const strUserList = pwg_getPageString("Manage the members");

const serverKey = pwg_getPageData<string>("cache_key_users");
const serverId = pwg_getPageData<string>("cache_key_hash");
const rootUrl = pwg_getPageData<string>("root_url");

/*-------
 Manage User Part
 -------*/

// Initialize the research user bar
let selectize: SelectizeInstance<string | number, UserSelectOption>;

// Initialize the cache -- placeholder cast, real init happens via
// `new UsersCache(...)` inside updateUserSearch()/at module load
// (below) before any handler that reads it can actually run.
// eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- deliberate placeholder, replaced by a real `new UsersCache(...)` before any handler that reads it can actually run.
let usersCache = {} as UsersCache;

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
      selectize.removeOption(dataId(el, "id"));
    });
  };
});

// Display the user manager for a specific group
export async function openUserManager(grpId: number): Promise<void> {
  const loadState = new TemporaryState();
  loadState.removeClass(
    document.querySelectorAll(
      "#" + escapeId("group-" + String(grpId)) + " #UserListTrigger",
    ),
    "icon-user-1",
  );
  loadState.changeAttribute(
    document.querySelectorAll(
      "#" + escapeId("group-" + String(grpId)) + " #UserListTrigger",
    ),
    "style",
    "pointer-events: none",
  );
  loadState.changeHTML(
    document.querySelectorAll(
      "#" + escapeId("group-" + String(grpId)) + " #UserListTrigger",
    ),
    "<i class='icon-spin6 animate-spin'> </i>",
  );
  try {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
    const response = (await ajax({
      url: "api/v1/users",
      type: "GET",
      data: {
        groupIds: [grpId],
      },
      dataType: "json",
    })) as GroupUserListResponse;
    loadState.reverse();
    //Set the popin name
    html(
      document.querySelectorAll(".group-name-block p"),
      (htmlOf(
        document.querySelectorAll(
          "#" + escapeId("group-" + String(grpId)) + " #group_name",
        ),
      ) ?? "") +
        " / " +
        strUserList,
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
        getUserDisplay(usersInGroup[i]!.username, usersInGroup[i]!.id, grpId),
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
    updateMembernumber(usersInGroup.length, grpId);
    //Attribute the group id to the div
    attr(
      document.querySelectorAll("#UserList"),
      "data-group_id",
      String(grpId),
    );

    attr(
      document.querySelectorAll(".LinkUserManager a"),
      "href",
      "admin.php?page=user_list&group=" + String(grpId),
    );
  } catch (err) {
    loadState.reverse();
    console.error(err);
  }
}

//Add a user block
function getUserDisplay(
  username: string,
  user_id: number,
  grpId: number,
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
      strUserDissociate +
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
    void (async () => {
      addClass(find(userBlock, ".icon-cancel"), "icon-spin6");
      addClass(find(userBlock, ".icon-cancel"), "animate-spin");
      css(find(userBlock, ".icon-cancel"), "pointer-events", "none");
      removeClass(find(userBlock, ".icon-cancel"), "icon-cancel");
      try {
        await ajax({
          url: "api/v1/groups/" + String(grpId) + "/actions/remove-user",
          type: "POST",
          headers: {
            "X-CSRF-Token": pwgToken,
          },
          json: {
            userIds: [user_id],
          },
          dataType: "json",
        });
        const str = strUserDissociated.replace("%s", username);
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

        usersInGroup = usersInGroup.filter((u) => u.id !== user_id);

        //Update member number
        updateMembernumber(
          parseInt(
            htmlOf(document.querySelectorAll(".UserNumberBadge")) ?? "0",
          ) - 1,
          grpId,
        );
      } catch {
        // No error handling before conversion either.
      }
    })();
  });
  return userBlock;
}

//Update member number function
function updateMembernumber(number: number, grpId: number) {
  html(
    document.querySelectorAll(
      '.GroupContainer[data-id="' + String(grpId) + '"] .group_number_users',
    ),
    String(number) + " " + (number > 1 ? strMembersDefault : strMemberDefault),
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
  void (async () => {
    const grpId = Number(
      attrOf(document.querySelectorAll("#UserList"), "data-group_id"),
    );
    // This instance is created without `multiple: true` (see
    // createSelectize() call above), so its own `getValue(): T | T[]`
    // signature -- shared with the multi-select case -- only ever returns
    // a single T here, never an array.
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- verified above: a non-multiple selectize instance's getValue() never returns an array.
    const id = selectize.getValue() as string | number;

    // `id` stays `string | number` here (not narrowed to `dataId`'s/
    // `valId`'s plain `number`): a non-multiple selectize instance's own
    // `getValue()` returns the literal string `""` as its "nothing
    // selected" sentinel regardless of what `T` claims (`vendor/selectize.ts`'s
    // own `items[0] ?? ("" as unknown as T)`) -- `String(id) !== ""` is the
    // real, load-bearing check for that, and must run before `id` can be
    // trusted as a real numeric option value.
    if (String(id) !== "") {
      const numericId = Number(id);
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
      try {
        await ajax({
          url: "api/v1/groups/" + String(grpId) + "/actions/add-user",
          type: "POST",
          headers: {
            "X-CSRF-Token": pwgToken,
          },
          json: {
            userIds: [numericId],
          },
          dataType: "json",
        });
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
          if (u.id === numericId) {
            ({ username } = u);
          }
        });
        const userBlock = getUserDisplay(username, numericId, grpId);
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
        html(find(associateUserInfo, "p"), strUserAssociated);
        fadeIn(associateUserInfo);

        updateUserSearch();

        usersInGroup.push({ username: username, id: numericId });

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
          grpId,
        );
      } catch (err) {
        loadState.reverse();
        console.error(err);
      }
    }
  })();
});

on(document.querySelectorAll(".input-user-name"), "input", function () {
  const searchString = String(
    val(document.querySelectorAll(".input-user-name")),
  ).toLowerCase();
  const grpId = dataId(document.querySelector(".UserListPopIn")!, "group_id");
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
        usersInGroupList.prepend(getUserDisplay(u.username, u.id, grpId));
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
        getUserDisplay(usersInGroup[i]!.username, usersInGroup[i]!.id, grpId),
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
