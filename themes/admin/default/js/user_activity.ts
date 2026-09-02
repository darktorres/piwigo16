import type { operations } from "../../../../openapi/client/schema";
// common.ts's own side effects only (font-checkbox init, search-cancel
// bindings) -- this page has no other first-party consumer of
// common.ts's own real exports. Real, accepted behavior change (docs/
// PLAN.md's own Design §6 precedent): common.ts used to load at
// UserActivityView's own explicit `LoadMode::Footer`; folded into this
// file, it now runs at this file's own `LoadMode::Async` instead (the
// only registrant page where common.ts's timing actually changes).
import "./common";

import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../default/js/page-data";
import { ajax } from "../../../default/js/vendor/ajax";
import { selectize } from "../../../default/js/vendor/selectize";
import {
  addClass,
  append,
  attr,
  cssValue,
  data,
  find,
  hasClass,
  hide,
  html,
  on,
  parseHtml,
  ready,
  remove,
  removeClass,
  show,
  slideToggle,
  val,
} from "../../../default/js/vendor/dom";
export {};

type UserListResponse =
  operations["userList"]["responses"][200]["content"]["application/json"];

// `row.details` is genuinely a heterogeneous `object | null` blob at
// the API layer (additionalProperties: true) -- these are the specific
// fields this file itself either reads (`script`/`method`, presence-
// checked via detailsType) or writes (`agent`, plus `users`/
// `users_string` computed below for merged "user"-object lines).
interface MergedActivityDetails {
  agent?: string | null;
  connected_with?: unknown;
  script?: string;
  method?: string;
  users?: string[];
  users_string?: string;
  [key: string]: unknown;
}

// The real shape `fetchAndMergeActivityLines`'s own client-side merge
// produces from consecutive-same-session/object/action `ActivityRow`s
// (GetListHandler's own equivalent server-side step, done here instead
// per activityList's own docblock).
interface MergedActivityLine {
  id: number;
  object: string;
  object_id: number[];
  action: string;
  ip_address: string | null;
  date: string;
  hour: string;
  user_id: number | null;
  username: string;
  detailsType: "method" | "script" | null;
  details: MergedActivityDetails;
  counter: number;
}

const nb_users = pwg_getPageData<number>("nb_users");

const additional_filt_type = pwg_getPageData<string | false>(
  "additional_filt_type",
);
const additional_filt_value = pwg_getPageData<string | null>(
  "additional_filt_value",
);

const color_icons = [
  "icon-red",
  "icon-blue",
  "icon-yellow",
  "icon-purple",
  "icon-green",
];
let activity_page = 1;
let page_offsets: number[] = [0];
let actual_page = 1;
let end_page = false;
let uid_filter: string | number | undefined;
let action_filter: string | undefined;
let object_filter: string | undefined;
let date_min_filter = pwg_getPageData<string>("activity_dates_min");
let date_max_filter = pwg_getPageData<string>("activity_dates_max");

const date_min = pwg_getPageData<string>("activity_dates_min");
const date_max = pwg_getPageData<string>("activity_dates_max");

const page_ellipsis = "<span>...</span>";
const page_item = '<a data-page="%d">%d</a>';
const users_key = pwg_getPageString("Users");

const actionType_add = pwg_getPageString("add");
const actionType_delete = pwg_getPageString("deletion");
const actionType_move = pwg_getPageString("move");
const actionType_edit = pwg_getPageString("edit");
const actionType_login = pwg_getPageString("login");
const actionType_logout = pwg_getPageString("logout");

const actionInfos_album_added = pwg_getPageString("%d album added");
const actionInfos_album_deleted = pwg_getPageString("%d album deleted");
const actionInfos_album_edited = pwg_getPageString("%d album edited");
const actionInfos_album_moved = pwg_getPageString("%d album moved");

const actionInfos_albums_added = pwg_getPageString("%d albums added");
const actionInfos_albums_deleted = pwg_getPageString("%d albums deleted");
const actionInfos_albums_edited = pwg_getPageString("%d albums edited");
const actionInfos_albums_moved = pwg_getPageString("%d albums moved");

const actionInfos_user_added = pwg_getPageString("%d user added");
const actionInfos_user_deleted = pwg_getPageString("%d user deleted");
const actionInfos_user_edited = pwg_getPageString("%d user edited");
const actionInfos_user_logged_in = pwg_getPageString("%d user logged in");
const actionInfos_user_logged_out = pwg_getPageString("%d user logged out");

const actionInfos_users_added = pwg_getPageString("%d users added");
const actionInfos_users_deleted = pwg_getPageString("%d users deleted");
const actionInfos_users_edited = pwg_getPageString("%d users edited");
const actionInfos_users_logged_in = pwg_getPageString("%d users logged in");
const actionInfos_users_logged_out = pwg_getPageString("%d users logged out");

const actionInfos_photo_added = pwg_getPageString("%d photo added");
const actionInfos_photo_deleted = pwg_getPageString("%d photo deleted");
const actionInfos_photo_edited = pwg_getPageString("%d photo edited");
const actionInfos_photo_moved = pwg_getPageString("%d photo moved");

const actionInfos_photos_added = pwg_getPageString("%d photos added");
const actionInfos_photos_deleted = pwg_getPageString("%d photos deleted");
const actionInfos_photos_edited = pwg_getPageString("%d photos edited");
const actionInfos_photos_moved = pwg_getPageString("%d photos moved");

const actionInfos_group_added = pwg_getPageString("%d group added");
const actionInfos_group_deleted = pwg_getPageString("%d group deleted");
const actionInfos_group_edited = pwg_getPageString("%d group edited");
const actionInfos_group_moved = pwg_getPageString("%d group moved");

const actionInfos_groups_added = pwg_getPageString("%d groups added");
const actionInfos_groups_deleted = pwg_getPageString("%d groups deleted");
const actionInfos_groups_edited = pwg_getPageString("%d groups edited");
const actionInfos_groups_moved = pwg_getPageString("%d groups moved");

const actionInfos_tag_added = pwg_getPageString("%d tag added");
const actionInfos_tag_deleted = pwg_getPageString("%d tag deleted");
const actionInfos_tag_edited = pwg_getPageString("%d tag edited");
const actionInfos_tag_moved = pwg_getPageString("%d tag moved");

const actionInfos_tags_added = pwg_getPageString("%d tags added");
const actionInfos_tags_deleted = pwg_getPageString("%d tags deleted");
const actionInfos_tags_edited = pwg_getPageString("%d tags edited");
const actionInfos_tags_moved = pwg_getPageString("%d tags moved");

//{*<-- Getting and Displaying Activities -->*}

// Declared before the immediately-invoked call below: get_user_activity()
// itself is hoisted (a function declaration), but this const binding
// would otherwise still be in its temporal dead zone at that call site
// since top-level script execution reaches it before this line.
const ACTIVITY_DISPLAY_PAGE_SIZE = 100;

if (additional_filt_type !== false) {
  object_filter = additional_filt_type;
}

void get_user_activity(
  activity_page,
  uid_filter,
  action_filter,
  object_filter,
  [date_min_filter, date_max_filter],
  additional_filt_value,
);

/*
 * GET /api/v1/activity returns flat, unmerged rows by design (see
 * ActivityListController's own docblock). This performs
 * consecutive-same-session/object/action line-merging-with-counter
 * client-side, from those flat rows. Fetches successive pages of raw
 * rows (using the endpoint's own offset/hasMore) and merges them
 * adjacently until either ACTIVITY_DISPLAY_PAGE_SIZE merged lines are
 * accumulated or the server reports no more rows -- a merge can span a
 * fetch boundary, so `currentKey` intentionally isn't reset between
 * iterations of the while loop below.
 */
async function fetchAndMergeActivityLines(
  startOffset: number,
  uid: string | number | undefined,
  action: string | undefined,
  object: string | undefined,
  date: (string | undefined)[],
  id: string | number | null | undefined,
) {
  let offset = startOffset;
  let hasMore = true;
  const lines: MergedActivityLine[] = [];
  let currentKey = "";

  while (lines.length < ACTIVITY_DISPLAY_PAGE_SIZE && hasMore) {
    const params: {
      offset: number;
      dateMin?: string | undefined;
      dateMax?: string | undefined;
      userId?: string | number;
      action?: string;
      object?: string;
      objectId?: string | number;
    } = {
      offset: offset,
      dateMin: date[0],
      dateMax: date[1],
    };
    if (uid !== undefined) params.userId = uid;
    if (action !== undefined) params.action = action;
    if (object !== undefined) params.object = object;
    if (id !== undefined && id !== null) params.objectId = id;

    const response = (await ajax({
      url: "api/v1/activity",
      type: "GET",
      dataType: "json",
      data: params,
    })) as operations["activityList"]["responses"][200]["content"]["application/json"];

    hasMore = response.hasMore;

    for (const row of response.activities) {
      offset++;

      if (lines.length >= ACTIVITY_DISPLAY_PAGE_SIZE) {
        hasMore = true;
        break;
      }

      const lineKey =
        row.sessionIdx + "~" + row.object + "~" + row.action + "~";

      if (lineKey === currentKey) {
        const last = lines[lines.length - 1]!;
        last.counter++;
        last.object_id.push(row.objectId);
      } else {
        const details: MergedActivityDetails = row.details
          ? { ...row.details }
          : {};
        let detailsType: "method" | "script" | null = null;
        if ("method" in details) detailsType = "method";
        if ("script" in details) detailsType = "script";
        details.agent = row.userAgent;

        lines.push({
          id: lines.length,
          object: row.object,
          object_id: [row.objectId],
          action: row.action,
          ip_address: row.ipAddress,
          date: row.dateFormatted,
          hour: row.occuredOn.split(" ")[1]!,
          user_id: row.performedBy,
          username:
            row.performedByUsername ?? "user#" + String(row.performedBy),
          detailsType: detailsType,
          details: details,
          counter: 1,
        });

        currentKey = lineKey;
      }
    }
  }

  // Resolve display usernames for every merged "user"-object line's own
  // object_id list -- GetListHandler's own equivalent step.
  const userLines = lines.filter((l) => l.object === "user");
  if (userLines.length > 0) {
    const allUserIds = [...new Set(userLines.flatMap((l) => l.object_id))];
    const userInfo = (await ajax({
      url: "api/v1/users",
      type: "GET",
      dataType: "json",
      data: { userIds: allUserIds, perPage: 0 },
    })) as UserListResponse;
    const usernameOfId: Record<string, string> = {};
    userInfo.users.forEach((u) => {
      usernameOfId[u.id] = u.username;
    });

    userLines.forEach((l) => {
      const usernames = l.object_id.map(
        (uid2) => usernameOfId[uid2] ?? "user#" + String(uid2),
      );
      l.details.users = usernames;
      l.details.users_string = [...new Set(usernames)].join(", ");
    });
  }

  return { lines: lines, endPage: !hasMore, nextOffset: offset };
}

async function get_user_activity(
  page: number,
  uid: string | number | undefined,
  action: string | undefined,
  object: string | undefined,
  date: (string | undefined)[],
  id: string | number | null | undefined,
) {
  // Genuine pre-existing bug found only by strict typechecking:
  // jQuery's `.contents()` takes no selector argument at all, so the
  // original `.contents(':not(#-1):not(.loading)')` silently ignored
  // it and removed *every* direct child of `.tab` -- including the
  // `#-1` line template `emptyLine()`/`lineConstructor()` clone from
  // and the `.loading` spinner toggled just below -- on the very
  // first call. `.children()` is the real jQuery method that accepts
  // a selector, matching the code's own clear intent.
  //
  // `#-1` (a literal id starting with a hyphen then a digit) is also
  // not a valid native CSS identifier -- getElementById() side-steps
  // that entirely, same reasoning as escapeId() elsewhere in P49-A.
  const tab = document.querySelector(".tab");
  if (tab !== null) {
    Array.from(tab.children).forEach((child) => {
      if (child.id !== "-1" && !child.classList.contains("loading")) {
        child.remove();
      }
    });
  }
  show(document.querySelectorAll(".loading"));
  addClass(document.querySelectorAll(".pagination-arrow.rigth"), "unavailable");
  addClass(document.querySelectorAll(".pagination-arrow.left"), "unavailable");
  hide(document.querySelectorAll(".pagination-item-container"));
  addClass(document.querySelectorAll(".user-update-spinner"), "icon-spin6");

  try {
    const merged = await fetchAndMergeActivityLines(
      page_offsets[page - 1]!,
      uid,
      action,
      object,
      date,
      id,
    );

    uid_filter = uid;
    action_filter = action;
    object_filter = object;
    date_min_filter = date[0]!;
    date_max_filter = date[1]!;

    hide(document.querySelectorAll(".loading"));

    if (merged.lines.length > 0) {
      merged.lines.forEach((line) => {
        lineConstructor(line);
      });
    } else {
      emptyLine();
    }

    end_page = merged.endPage;
    if (!page_offsets.includes(merged.nextOffset)) {
      page_offsets.push(merged.nextOffset);
    }

    removeClass(
      document.querySelectorAll(".user-update-spinner"),
      "icon-spin6",
    );
    show(document.querySelectorAll(".pagination-item-container"));
    update_pagination_menu();
  } catch (e: unknown) {
    console.error("ajax call failed", e);
  }
}

function lineConstructor(line: MergedActivityLine) {
  const newLine = document.getElementById("-1")!.cloneNode(true) as Element;

  show(document.querySelectorAll(".tab-title"));
  hide(document.querySelectorAll(".activity-noresult"));
  removeClass(newLine, "hide");

  /* console log to help debug
    {* console.log(line); *}*/
  attr(newLine, "id", String(line.id));

  let final_albumInfos: string;

  //{* Determines wich string need to be placed in the line constructed *}

  if (line.counter > 1) {
    // pluriel
    switch (line.action) {
      case "edit":
        addClass(find(newLine, ".action-type"), "icon-blue");
        addClass(
          find(newLine, ".user-pic"),
          color_icons[(line.user_id ?? 0) % 5]!,
        );
        addClass(find(newLine, ".action-icon"), "icon-pencil");

        html(find(newLine, ".action-name"), actionType_edit);
        switch (line.object) {
          case "user":
            final_albumInfos = actionInfos_users_edited.replace(
              "%d",
              String(line.counter),
            );
            addClass(find(newLine, ".action-section"), "icon-user-1");

            break;
          case "album":
            final_albumInfos = actionInfos_albums_edited.replace(
              "%d",
              String(line.counter),
            );
            addClass(find(newLine, ".action-section"), "icon-folder-open");

            break;
          case "group":
            final_albumInfos = actionInfos_groups_edited.replace(
              "%d",
              String(line.counter),
            );
            addClass(find(newLine, ".action-section"), "icon-users-1");

            break;
          case "photo":
            final_albumInfos = actionInfos_photos_edited.replace(
              "%d",
              String(line.counter),
            );
            addClass(find(newLine, ".action-section"), "icon-picture");

            break;
          case "tag":
            final_albumInfos = actionInfos_tags_edited.replace(
              "%d",
              String(line.counter),
            );
            addClass(find(newLine, ".action-section"), "icon-tags");

            break;
          default:
            final_albumInfos =
              String(line.counter) + " " + line.object + " " + line.action;
            break;
        }

        break;

      case "add":
        addClass(find(newLine, ".action-type"), "icon-green");
        addClass(
          find(newLine, ".user-pic"),
          color_icons[(line.user_id ?? 0) % 5]!,
        );
        addClass(find(newLine, ".action-icon"), "icon-plus");

        html(find(newLine, ".action-name"), actionType_add);
        switch (line.object) {
          case "user":
            final_albumInfos = actionInfos_users_added.replace(
              "%d",
              String(line.counter),
            );
            addClass(find(newLine, ".action-section"), "icon-user-1");

            break;
          case "album":
            final_albumInfos = actionInfos_albums_added.replace(
              "%d",
              String(line.counter),
            );
            addClass(find(newLine, ".action-section"), "icon-folder-open");

            break;
          case "group":
            final_albumInfos = actionInfos_groups_added.replace(
              "%d",
              String(line.counter),
            );
            addClass(find(newLine, ".action-section"), "icon-users-1");

            break;
          case "photo":
            final_albumInfos = actionInfos_photos_added.replace(
              "%d",
              String(line.counter),
            );
            addClass(find(newLine, ".action-section"), "icon-picture");

            break;
          case "tag":
            final_albumInfos = actionInfos_tags_added.replace(
              "%d",
              String(line.counter),
            );
            addClass(find(newLine, ".action-section"), "icon-tags");

            break;
          default:
            final_albumInfos =
              String(line.counter) + " " + line.object + " " + line.action;
            break;
        }

        break;

      case "delete":
        addClass(find(newLine, ".action-type"), "icon-red");
        addClass(
          find(newLine, ".user-pic"),
          color_icons[(line.user_id ?? 0) % 5]!,
        );
        addClass(find(newLine, ".action-icon"), "icon-trash-1");

        html(find(newLine, ".action-name"), actionType_delete);
        switch (line.object) {
          case "user":
            final_albumInfos = actionInfos_users_deleted.replace(
              "%d",
              String(line.counter),
            );
            addClass(find(newLine, ".action-section"), "icon-user-1");

            break;
          case "album":
            final_albumInfos = actionInfos_albums_deleted.replace(
              "%d",
              String(line.counter),
            );
            addClass(find(newLine, ".action-section"), "icon-folder-open");

            break;
          case "group":
            final_albumInfos = actionInfos_groups_deleted.replace(
              "%d",
              String(line.counter),
            );
            addClass(find(newLine, ".action-section"), "icon-users-1");

            break;
          case "photo":
            final_albumInfos = actionInfos_photos_deleted.replace(
              "%d",
              String(line.counter),
            );
            addClass(find(newLine, ".action-section"), "icon-picture");

            break;
          case "tag":
            final_albumInfos = actionInfos_tags_deleted.replace(
              "%d",
              String(line.counter),
            );
            addClass(find(newLine, ".action-section"), "icon-tags");

            break;
          default:
            final_albumInfos =
              String(line.counter) + " " + line.object + " " + line.action;
            break;
        }

        break;

      case "move":
        addClass(find(newLine, ".action-type"), "icon-yellow");
        addClass(
          find(newLine, ".user-pic"),
          color_icons[(line.user_id ?? 0) % 5]!,
        );
        addClass(find(newLine, ".action-icon"), "icon-move");

        html(find(newLine, ".action-name"), actionType_move);
        switch (line.object) {
          case "album":
            final_albumInfos = actionInfos_albums_moved.replace(
              "%d",
              String(line.counter),
            );
            addClass(find(newLine, ".action-section"), "icon-folder-open");

            break;
          case "group":
            final_albumInfos = actionInfos_groups_moved.replace(
              "%d",
              String(line.counter),
            );
            addClass(find(newLine, ".action-section"), "icon-users-1");

            break;
          case "photo":
            final_albumInfos = actionInfos_photos_moved.replace(
              "%d",
              String(line.counter),
            );
            addClass(find(newLine, ".action-section"), "icon-picture");

            break;
          case "tag":
            final_albumInfos = actionInfos_tags_moved.replace(
              "%d",
              String(line.counter),
            );
            addClass(find(newLine, ".action-section"), "icon-tags");

            break;
          default:
            final_albumInfos =
              String(line.counter) + " " + line.object + " " + line.action;
            break;
        }

        break;

      case "login":
        addClass(find(newLine, ".action-type"), "icon-purple");
        addClass(
          find(newLine, ".user-pic"),
          color_icons[(line.user_id ?? 0) % 5]!,
        );
        addClass(find(newLine, ".action-icon"), "icon-key");
        addClass(find(newLine, ".action-section"), "icon-user-1");

        html(find(newLine, ".action-name"), actionType_login);

        final_albumInfos = actionInfos_users_logged_in.replace(
          "%d",
          String(line.counter),
        );

        break;

      case "logout":
        addClass(find(newLine, ".action-type"), "icon-purple");
        if (line.user_id !== 2) {
          addClass(
            find(newLine, ".user-pic"),
            color_icons[(line.user_id ?? 0) % 5]!,
          );
        } else {
          addClass(
            find(newLine, ".user-pic"),
            color_icons[(line.object_id[0] ?? 0) % 5]!,
          );
        }
        addClass(find(newLine, ".action-icon"), "icon-logout");
        addClass(find(newLine, ".action-section"), "icon-user-1");

        html(find(newLine, ".action-name"), actionType_logout);

        final_albumInfos = actionInfos_users_logged_out.replace(
          "%d",
          String(line.counter),
        );

        break;

      default:
        addClass(find(newLine, ".action-type"), "icon-purple");
        addClass(
          find(newLine, ".user-pic"),
          color_icons[(line.user_id ?? 0) % 5]!,
        );
        addClass(find(newLine, ".action-section"), "icon-user-1");
        html(find(newLine, ".action-name"), line.action);
        final_albumInfos = "x" + String(line.counter);
        break;
    }
  } else {
    // singulier
    switch (line.action) {
      case "edit":
        addClass(find(newLine, ".action-type"), "icon-blue");
        addClass(
          find(newLine, ".user-pic"),
          color_icons[(line.user_id ?? 0) % 5]!,
        );
        addClass(find(newLine, ".action-icon"), "icon-pencil");

        html(find(newLine, ".action-name"), actionType_edit);
        switch (line.object) {
          case "user":
            final_albumInfos = actionInfos_user_edited.replace(
              "%d",
              String(line.counter),
            );
            addClass(find(newLine, ".action-section"), "icon-user-1");

            break;
          case "album":
            final_albumInfos = actionInfos_album_edited.replace(
              "%d",
              String(line.counter),
            );
            addClass(find(newLine, ".action-section"), "icon-folder-open");

            break;
          case "group":
            final_albumInfos = actionInfos_group_edited.replace(
              "%d",
              String(line.counter),
            );
            addClass(find(newLine, ".action-section"), "icon-users-1");

            break;
          case "photo":
            final_albumInfos = actionInfos_photo_edited.replace(
              "%d",
              String(line.counter),
            );
            addClass(find(newLine, ".action-section"), "icon-picture");

            break;
          case "tag":
            final_albumInfos = actionInfos_tag_edited.replace(
              "%d",
              String(line.counter),
            );
            addClass(find(newLine, ".action-section"), "icon-tags");

            break;
          default:
            final_albumInfos =
              String(line.counter) + " " + line.object + " " + line.action;
            break;
        }

        break;
      case "add":
        addClass(find(newLine, ".action-type"), "icon-green");
        addClass(
          find(newLine, ".user-pic"),
          color_icons[(line.user_id ?? 0) % 5]!,
        );
        addClass(find(newLine, ".action-icon"), "icon-plus");

        html(find(newLine, ".action-name"), actionType_add);
        switch (line.object) {
          case "user":
            final_albumInfos = actionInfos_user_added.replace(
              "%d",
              String(line.counter),
            );
            addClass(find(newLine, ".action-section"), "icon-user-1");

            break;
          case "album":
            final_albumInfos = actionInfos_album_added.replace(
              "%d",
              String(line.counter),
            );
            addClass(find(newLine, ".action-section"), "icon-folder-open");

            break;
          case "group":
            final_albumInfos = actionInfos_group_added.replace(
              "%d",
              String(line.counter),
            );
            addClass(find(newLine, ".action-section"), "icon-users-1");

            break;
          case "photo":
            final_albumInfos = actionInfos_photo_added.replace(
              "%d",
              String(line.counter),
            );
            addClass(find(newLine, ".action-section"), "icon-picture");

            break;
          case "tag":
            final_albumInfos = actionInfos_tag_added.replace(
              "%d",
              String(line.counter),
            );
            addClass(find(newLine, ".action-section"), "icon-tags");

            break;
          default:
            final_albumInfos =
              String(line.counter) + " " + line.object + " " + line.action;

            break;
        }

        break;
      case "delete":
        addClass(find(newLine, ".action-type"), "icon-red");
        addClass(
          find(newLine, ".user-pic"),
          color_icons[(line.user_id ?? 0) % 5]!,
        );
        addClass(find(newLine, ".action-icon"), "icon-trash-1");

        html(find(newLine, ".action-name"), actionType_delete);
        switch (line.object) {
          case "user":
            final_albumInfos = actionInfos_user_deleted.replace(
              "%d",
              String(line.counter),
            );
            addClass(find(newLine, ".action-section"), "icon-user-1");

            break;
          case "album":
            final_albumInfos = actionInfos_album_deleted.replace(
              "%d",
              String(line.counter),
            );
            addClass(find(newLine, ".action-section"), "icon-folder-open");

            break;
          case "group":
            final_albumInfos = actionInfos_group_deleted.replace(
              "%d",
              String(line.counter),
            );
            addClass(find(newLine, ".action-section"), "icon-users-1");

            break;
          case "photo":
            final_albumInfos = actionInfos_photo_deleted.replace(
              "%d",
              String(line.counter),
            );
            addClass(find(newLine, ".action-section"), "icon-picture");

            break;
          case "tag":
            final_albumInfos = actionInfos_tag_deleted.replace(
              "%d",
              String(line.counter),
            );
            addClass(find(newLine, ".action-section"), "icon-tags");

            break;
          default:
            final_albumInfos =
              String(line.counter) + " " + line.object + " " + line.action;
            break;
        }

        break;
      case "move":
        addClass(find(newLine, ".action-type"), "icon-yellow");
        addClass(
          find(newLine, ".user-pic"),
          color_icons[(line.user_id ?? 0) % 5]!,
        );
        addClass(find(newLine, ".action-icon"), "icon-move");

        html(find(newLine, ".action-name"), actionType_move);
        switch (line.object) {
          case "album":
            final_albumInfos = actionInfos_album_moved.replace(
              "%d",
              String(line.counter),
            );
            addClass(find(newLine, ".action-section"), "icon-folder-open");

            break;
          case "group":
            final_albumInfos = actionInfos_group_moved.replace(
              "%d",
              String(line.counter),
            );
            addClass(find(newLine, ".action-section"), "icon-users-1");

            break;
          case "photo":
            final_albumInfos = actionInfos_photo_moved.replace(
              "%d",
              String(line.counter),
            );
            addClass(find(newLine, ".action-section"), "icon-picture");

            break;
          case "tag":
            final_albumInfos = actionInfos_tag_moved.replace(
              "%d",
              String(line.counter),
            );
            addClass(find(newLine, ".action-section"), "icon-tags");

            break;
          default:
            final_albumInfos =
              String(line.counter) + " " + line.object + " " + line.action;
            break;
        }

        break;
      case "login":
        addClass(find(newLine, ".action-type"), "icon-purple");
        addClass(
          find(newLine, ".user-pic"),
          color_icons[(line.user_id ?? 0) % 5]!,
        );
        addClass(find(newLine, ".action-icon"), "icon-key");
        addClass(find(newLine, ".action-section"), "icon-user-1");

        html(find(newLine, ".action-name"), actionType_login);

        final_albumInfos = actionInfos_user_logged_in.replace(
          "%d",
          String(line.counter),
        );

        break;
      case "logout":
        addClass(find(newLine, ".action-type"), "icon-purple");
        if (line.user_id !== 2) {
          addClass(
            find(newLine, ".user-pic"),
            color_icons[(line.user_id ?? 0) % 5]!,
          );
        } else {
          addClass(
            find(newLine, ".user-pic"),
            color_icons[(line.object_id[0] ?? 0) % 5]!,
          );
        }
        addClass(find(newLine, ".action-icon"), "icon-logout");
        addClass(find(newLine, ".action-section"), "icon-user-1");

        html(find(newLine, ".action-name"), actionType_logout);

        final_albumInfos = actionInfos_user_logged_out.replace(
          "%d",
          String(line.counter),
        );

        break;

      default:
        addClass(find(newLine, ".action-type"), "icon-purple");
        addClass(
          find(newLine, ".user-pic"),
          color_icons[(line.user_id ?? 0) % 5]!,
        );
        addClass(find(newLine, ".action-section"), "icon-user-1");
        html(find(newLine, ".action-name"), line.action);
        final_albumInfos = "x" + String(line.counter);
        break;
    }
  }

  html(find(newLine, ".action-infos-test"), final_albumInfos);

  /* Action_section */
  html(find(newLine, ".nb_items"), String(line.counter));

  /* Date_section */
  html(find(newLine, ".date-day"), line.date);
  html(find(newLine, ".date-hour"), line.hour);

  /* User _Section */
  html(find(newLine, ".user-name"), line.username);
  html(find(newLine, ".user-pic"), get_initials(line.username));

  /* Detail_section */
  html(find(newLine, ".detail-item-1"), line.ip_address ?? "");
  attr(
    find(newLine, ".detail-item-1"),
    "title",
    "IP: " + (line.ip_address ?? ""),
  );

  if (line.detailsType === "script") {
    html(find(newLine, ".detail-item-2"), line.details.script ?? "");
    attr(find(newLine, ".detail-item-2"), "title", "Script");
  } else if (line.detailsType === "method") {
    html(find(newLine, ".detail-item-2"), line.details.method ?? "");
    attr(find(newLine, ".detail-item-2"), "title", "API Method");
  }

  if (
    line.details.agent !== undefined &&
    line.details.agent !== null &&
    line.details.agent !== ""
  ) {
    const isConnectedWith = Boolean(line.details.connected_with);
    const api_key = isConnectedWith ? "API Key, " : "";
    const details = isConnectedWith
      ? '<i class="icon-key"></i>' + line.details.agent
      : line.details.agent;
    html(find(newLine, ".detail-item-3"), details);
    attr(
      find(newLine, ".detail-item-3"),
      "title",
      api_key + "User-Agent: " + line.details.agent,
    );
  } else if (
    line.details.users &&
    line.action !== "logout" &&
    line.action !== "login"
  ) {
    const user_string = [...new Set(line.details.users)].toString();
    html(find(newLine, ".detail-item-3"), user_string);
    attr(
      find(newLine, ".detail-item-3"),
      "title",
      users_key + ": " + user_string,
    );
  } else {
    remove(find(newLine, ".detail-item-3"));
  }

  addClass(newLine, "uid-" + String(line.user_id));

  displayLine(newLine);
}

function displayLine(line: Element) {
  document.querySelector(".tab")?.appendChild(line);
}

function emptyLine() {
  hide(document.querySelectorAll(".tab-title"));
  show(document.querySelectorAll(".activity-noresult"));
}

function get_initials(username: string) {
  const words = username.toUpperCase().split(" ");
  let res = words[0]![0]!;

  if (words.length > 1 && words[1]![0] !== undefined) {
    res += words[1]![0];
  }
  return res;
}

//{* Pagination *}

function move_to_page(page: number) {
  if (page < 0) return;
  actual_page = page;
  update_pagination_menu(page);
  void get_user_activity(
    page,
    uid_filter,
    action_filter,
    object_filter,
    [date_min_filter, date_max_filter],
    additional_filt_value,
  );
}

on(document.querySelectorAll(".pagination-arrow.rigth"), "click", () => {
  move_to_page(actual_page + 1);
});

on(document.querySelectorAll(".pagination-arrow.left"), "click", () => {
  move_to_page(actual_page - 1);
});

function update_pagination_menu(_page?: number) {
  updateArrows();
  update_pagination_items();
  if (end_page && actual_page === 1) {
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
  if (end_page) {
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

function update_pagination_items() {
  remove(document.querySelectorAll(".pagination-item-container a"));
  remove(document.querySelectorAll(".pagination-item-container span"));

  append_pagination_item(1);

  if (actual_page > 2) {
    append_pagination_item();
  }
  if (actual_page !== 1) {
    append_pagination_item(actual_page);
  }
  if (!end_page) {
    append_pagination_item();
  }
}

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
      move_to_page(data(new_tag, "page") as number);
    });
  } else {
    container.appendChild(parseHtml(page_ellipsis)[0]!);
  }
}

function page_reset() {
  activity_page = 1;
  page_offsets = [0];
  actual_page = 1;
  end_page = false;
}

ready(function () {
  append(
    document.querySelectorAll("h1"),
    `<span class='badge-number'>` + String(nb_users - 1) + `</span>`,
  );

  // The `.selectize-input`/`.item[data-value]` markup below is
  // `vendor/selectize.ts`'s own real, rendered DOM (P49-B group 6).
  on(
    document.querySelectorAll("select.user-selecter"),
    "change",
    function (): void {
      if (
        hasClass(
          document.querySelectorAll(".user-selecter .selectize-input"),
          "full",
        )
      ) {
        page_reset();
        const item = document.querySelector(
          ".user-selecter .selectize-input .item",
        );
        const value = item !== null ? data(item, "value") : undefined;
        if (value === "none") {
          //{* call ajax sur activity list sans uid *}
          void get_user_activity(
            1,
            undefined,
            action_filter,
            object_filter,
            [date_min_filter, date_max_filter],
            additional_filt_value,
          );
        } else {
          //{* call ajax sur activity list avec uid en param *}
          void get_user_activity(
            1,
            value as string | number | undefined,
            action_filter,
            object_filter,
            [date_min_filter, date_max_filter],
            additional_filt_value,
          );
        }
      }
    },
  );

  on(
    document.querySelectorAll("select.action-selecter"),
    "change",
    function (): void {
      if (
        hasClass(
          document.querySelectorAll(".action-selecter .selectize-input"),
          "full",
        )
      ) {
        page_reset();
        const item = document.querySelector(
          ".action-selecter .selectize-input .item",
        );
        const value = item !== null ? data(item, "value") : undefined;
        if (value === "none") {
          //{* call ajax sur activity list sans action et object *}
          if (additional_filt_type !== false) {
            void get_user_activity(
              1,
              uid_filter,
              undefined,
              object_filter,
              [date_min_filter, date_max_filter],
              additional_filt_value,
            );
          } else {
            void get_user_activity(
              1,
              uid_filter,
              undefined,
              undefined,
              [date_min_filter, date_max_filter],
              additional_filt_value,
            );
          }
        } else {
          //{* call ajax sur activity list avec action et object en param *}
          const object = (value as string).split("/")[0];
          const action = (value as string).split("/")[1];
          void get_user_activity(
            1,
            uid_filter,
            action,
            object,
            [date_min_filter, date_max_filter],
            additional_filt_value,
          );
        }
      }
    },
  );

  on(
    document.querySelectorAll("#date_min_activity"),
    "change",
    function (): void {
      page_reset();
      const minVal = val(document.querySelectorAll("#date_min_activity"));
      if (minVal === "") {
        document
          .getElementById("date_max_activity")!
          .setAttribute("min", date_min);
      } else {
        document
          .getElementById("date_max_activity")!
          .setAttribute("min", String(minVal));
      }
      void get_user_activity(
        activity_page,
        uid_filter,
        action_filter,
        object_filter,
        [minVal, date_max_filter],
        additional_filt_value,
      );
    },
  );

  on(
    document.querySelectorAll("#date_max_activity"),
    "change",
    function (): void {
      page_reset();
      const maxVal = val(document.querySelectorAll("#date_max_activity"));
      if (maxVal === "") {
        document
          .getElementById("date_min_activity")!
          .setAttribute("max", date_max);
      } else {
        document
          .getElementById("date_min_activity")!
          .setAttribute("max", String(maxVal));
      }
      void get_user_activity(
        activity_page,
        uid_filter,
        action_filter,
        object_filter,
        [date_min_filter, maxVal],
        additional_filt_value,
      );
    },
  );

  document
    .querySelectorAll<HTMLSelectElement>(".user-selecter")
    .forEach((el) => {
      selectize(el, {}).clear();
    });
  document
    .querySelectorAll<HTMLSelectElement>(".action-selecter")
    .forEach((el) => {
      selectize(el, {}).clear();
    });

  if (additional_filt_type !== false) {
    addClass(
      document.querySelectorAll("#activityMoreFilters"),
      "extend-padding",
    );
  } else {
    hide(document.querySelectorAll("#activityMoreFiltersContent"));
  }
  //var used to prevent the user to interfere with the collapsible when it's toggling, to avoid some problems
  let toggleTriggered = false;
  on(
    document.querySelectorAll("#activityMoreFilters"),
    "click",
    function (): void {
      const content = document.querySelector("#activityMoreFiltersContent");
      if (content === null) return;

      if (cssValue(content, "display") === "none" && !toggleTriggered) {
        toggleTriggered = true;
        addClass(
          document.querySelectorAll("#activityMoreFilters"),
          "extend-padding",
        );
        slideToggle(content, function () {
          toggleTriggered = false;
        });
      } else if (cssValue(content, "display") === "flex" && !toggleTriggered) {
        toggleTriggered = true;
        slideToggle(content, function () {
          removeClass(
            document.querySelectorAll("#activityMoreFilters"),
            "extend-padding",
          );
          toggleTriggered = false;
        });
      }
    },
  );
});
