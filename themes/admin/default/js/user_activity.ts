import type { operations } from "../../../../openapi/client/schema";
// common.ts's own side effects only (font-checkbox init, search-cancel
// bindings) -- this page has no other first-party consumer of
// common.ts's own real exports. Real, accepted behavior change (docs/
// PLAN.md's own Design §6 precedent): common.ts used to load at
// UserActivityView's own explicit `LoadMode::Footer`; folded into this
// file, it now runs at this file's own `LoadMode::Async` instead (the
// only registrant page where common.ts's timing actually changes).
import "./common";
import { UsersCache } from "./LocalStorageCache";

import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../default/js/page-data";
import { ajax } from "../../../default/js/vendor/ajax";
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

const usersCache = new UsersCache({
  serverKey: pwg_getPageData<string>("cache_key_users"),
  serverId: pwg_getPageData<string>("cache_key_hash"),
  rootUrl: pwg_getPageData<string>("root_url"),
});
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
let current_page_offset = 0;
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
const create_selecter = true;
const users_key = pwg_getPageString("Users");

const line_key = pwg_getPageString("%s line");
const lines_key = pwg_getPageString("%s lines");

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

if (additional_filt_type) {
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
      dateMin?: string;
      dateMax?: string;
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

    const data = (await ajax({
      url: "api/v1/activity",
      type: "GET",
      dataType: "json",
      data: params,
    })) as operations["activityList"]["responses"][200]["content"]["application/json"];

    hasMore = data.hasMore;

    for (const row of data.activities) {
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
          username: row.performedByUsername || "user#" + row.performedBy,
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
        (uid2) => usernameOfId[uid2] || "user#" + uid2,
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
  $(".tab").children(":not(#-1):not(.loading)").remove();
  $(".loading").show();
  $(".pagination-arrow.rigth").addClass("unavailable");
  $(".pagination-arrow.left").addClass("unavailable");
  $(".pagination-item-container").hide();
  $(".user-update-spinner").addClass("icon-spin6");

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

    $(".loading").hide();

    if (merged.lines.length > 0) {
      merged.lines.forEach((line) => {
        lineConstructor(line);
      });
    } else {
      emptyLine();
    }

    current_page_offset = page_offsets[page - 1]!;
    end_page = merged.endPage;
    if (!page_offsets.includes(merged.nextOffset)) {
      page_offsets.push(merged.nextOffset);
    }

    $(".user-update-spinner").removeClass("icon-spin6");
    $(".pagination-item-container").show();
    update_pagination_menu();
  } catch (e: unknown) {
    console.log("ajax call failed");
    console.log(e);
  }
}

function lineConstructor(line: MergedActivityLine) {
  const newLine = $("#-1").clone();

  $(".tab-title").show();
  $(".activity-noresult").hide();
  newLine.removeClass("hide");

  /* console log to help debug 
    {* console.log(line); *}*/
  newLine.attr("id", line.id);

  let final_albumInfos: string;

  //{* Determines wich string need to be placed in the line constructed *}

  if (line.counter > 1) {
    // pluriel
    switch (line.action) {
      case "edit":
        newLine.find(".action-type").addClass("icon-blue");
        newLine
          .find(".user-pic")
          .addClass(color_icons[(line.user_id ?? 0) % 5]!);
        newLine.find(".action-icon").addClass("icon-pencil");

        newLine.find(".action-name").html(actionType_edit);
        switch (line.object) {
          case "user":
            final_albumInfos = actionInfos_users_edited.replace(
              "%d",
              String(line.counter),
            );
            newLine.find(".action-section").addClass("icon-user-1");

            break;
          case "album":
            final_albumInfos = actionInfos_albums_edited.replace(
              "%d",
              String(line.counter),
            );
            newLine.find(".action-section").addClass("icon-folder-open");

            break;
          case "group":
            final_albumInfos = actionInfos_groups_edited.replace(
              "%d",
              String(line.counter),
            );
            newLine.find(".action-section").addClass("icon-users-1");

            break;
          case "photo":
            final_albumInfos = actionInfos_photos_edited.replace(
              "%d",
              String(line.counter),
            );
            newLine.find(".action-section").addClass("icon-picture");

            break;
          case "tag":
            final_albumInfos = actionInfos_tags_edited.replace(
              "%d",
              String(line.counter),
            );
            newLine.find(".action-section").addClass("icon-tags");

            break;
          default:
            final_albumInfos =
              line.counter + " " + line.object + " " + line.action;
            break;
        }

        break;

      case "add":
        newLine.find(".action-type").addClass("icon-green");
        newLine
          .find(".user-pic")
          .addClass(color_icons[(line.user_id ?? 0) % 5]!);
        newLine.find(".action-icon").addClass("icon-plus");

        newLine.find(".action-name").html(actionType_add);
        switch (line.object) {
          case "user":
            final_albumInfos = actionInfos_users_added.replace(
              "%d",
              String(line.counter),
            );
            newLine.find(".action-section").addClass("icon-user-1");

            break;
          case "album":
            final_albumInfos = actionInfos_albums_added.replace(
              "%d",
              String(line.counter),
            );
            newLine.find(".action-section").addClass("icon-folder-open");

            break;
          case "group":
            final_albumInfos = actionInfos_groups_added.replace(
              "%d",
              String(line.counter),
            );
            newLine.find(".action-section").addClass("icon-users-1");

            break;
          case "photo":
            final_albumInfos = actionInfos_photos_added.replace(
              "%d",
              String(line.counter),
            );
            newLine.find(".action-section").addClass("icon-picture");

            break;
          case "tag":
            final_albumInfos = actionInfos_tags_added.replace(
              "%d",
              String(line.counter),
            );
            newLine.find(".action-section").addClass("icon-tags");

            break;
          default:
            final_albumInfos =
              line.counter + " " + line.object + " " + line.action;
            break;
        }

        break;

      case "delete":
        newLine.find(".action-type").addClass("icon-red");
        newLine
          .find(".user-pic")
          .addClass(color_icons[(line.user_id ?? 0) % 5]!);
        newLine.find(".action-icon").addClass("icon-trash-1");

        newLine.find(".action-name").html(actionType_delete);
        switch (line.object) {
          case "user":
            final_albumInfos = actionInfos_users_deleted.replace(
              "%d",
              String(line.counter),
            );
            newLine.find(".action-section").addClass("icon-user-1");

            break;
          case "album":
            final_albumInfos = actionInfos_albums_deleted.replace(
              "%d",
              String(line.counter),
            );
            newLine.find(".action-section").addClass("icon-folder-open");

            break;
          case "group":
            final_albumInfos = actionInfos_groups_deleted.replace(
              "%d",
              String(line.counter),
            );
            newLine.find(".action-section").addClass("icon-users-1");

            break;
          case "photo":
            final_albumInfos = actionInfos_photos_deleted.replace(
              "%d",
              String(line.counter),
            );
            newLine.find(".action-section").addClass("icon-picture");

            break;
          case "tag":
            final_albumInfos = actionInfos_tags_deleted.replace(
              "%d",
              String(line.counter),
            );
            newLine.find(".action-section").addClass("icon-tags");

            break;
          default:
            final_albumInfos =
              line.counter + " " + line.object + " " + line.action;
            break;
        }

        break;

      case "move":
        newLine.find(".action-type").addClass("icon-yellow");
        newLine
          .find(".user-pic")
          .addClass(color_icons[(line.user_id ?? 0) % 5]!);
        newLine.find(".action-icon").addClass("icon-move");

        newLine.find(".action-name").html(actionType_move);
        switch (line.object) {
          case "album":
            final_albumInfos = actionInfos_albums_moved.replace(
              "%d",
              String(line.counter),
            );
            newLine.find(".action-section").addClass("icon-folder-open");

            break;
          case "group":
            final_albumInfos = actionInfos_groups_moved.replace(
              "%d",
              String(line.counter),
            );
            newLine.find(".action-section").addClass("icon-users-1");

            break;
          case "photo":
            final_albumInfos = actionInfos_photos_moved.replace(
              "%d",
              String(line.counter),
            );
            newLine.find(".action-section").addClass("icon-picture");

            break;
          case "tag":
            final_albumInfos = actionInfos_tags_moved.replace(
              "%d",
              String(line.counter),
            );
            newLine.find(".action-section").addClass("icon-tags");

            break;
          default:
            final_albumInfos =
              line.counter + " " + line.object + " " + line.action;
            break;
        }

        break;

      case "login":
        newLine.find(".action-type").addClass("icon-purple");
        newLine
          .find(".user-pic")
          .addClass(color_icons[(line.user_id ?? 0) % 5]!);
        newLine.find(".action-icon").addClass("icon-key");
        newLine.find(".action-section").addClass("icon-user-1");

        newLine.find(".action-name").html(actionType_login);

        final_albumInfos = actionInfos_users_logged_in.replace(
          "%d",
          String(line.counter),
        );

        break;

      case "logout":
        newLine.find(".action-type").addClass("icon-purple");
        if (line.user_id != 2) {
          newLine
            .find(".user-pic")
            .addClass(color_icons[(line.user_id ?? 0) % 5]!);
        } else {
          newLine
            .find(".user-pic")
            .addClass(color_icons[(line.object_id[0] ?? 0) % 5]!);
        }
        newLine.find(".action-icon").addClass("icon-logout");
        newLine.find(".action-section").addClass("icon-user-1");

        newLine.find(".action-name").html(actionType_logout);

        final_albumInfos = actionInfos_users_logged_out.replace(
          "%d",
          String(line.counter),
        );

        break;

      default:
        newLine.find(".action-type").addClass("icon-purple");
        newLine
          .find(".user-pic")
          .addClass(color_icons[(line.user_id ?? 0) % 5]!);
        newLine.find(".action-section").addClass("icon-user-1");
        newLine.find(".action-name").html(line.action);
        final_albumInfos = "x" + line.counter;
        break;
    }
  } else {
    // singulier
    switch (line.action) {
      case "edit":
        newLine.find(".action-type").addClass("icon-blue");
        newLine
          .find(".user-pic")
          .addClass(color_icons[(line.user_id ?? 0) % 5]!);
        newLine.find(".action-icon").addClass("icon-pencil");

        newLine.find(".action-name").html(actionType_edit);
        switch (line.object) {
          case "user":
            final_albumInfos = actionInfos_user_edited.replace(
              "%d",
              String(line.counter),
            );
            newLine.find(".action-section").addClass("icon-user-1");

            break;
          case "album":
            final_albumInfos = actionInfos_album_edited.replace(
              "%d",
              String(line.counter),
            );
            newLine.find(".action-section").addClass("icon-folder-open");

            break;
          case "group":
            final_albumInfos = actionInfos_group_edited.replace(
              "%d",
              String(line.counter),
            );
            newLine.find(".action-section").addClass("icon-users-1");

            break;
          case "photo":
            final_albumInfos = actionInfos_photo_edited.replace(
              "%d",
              String(line.counter),
            );
            newLine.find(".action-section").addClass("icon-picture");

            break;
          case "tag":
            final_albumInfos = actionInfos_tag_edited.replace(
              "%d",
              String(line.counter),
            );
            newLine.find(".action-section").addClass("icon-tags");

            break;
          default:
            final_albumInfos =
              line.counter + " " + line.object + " " + line.action;
            break;
        }

        break;
      case "add":
        newLine.find(".action-type").addClass("icon-green");
        newLine
          .find(".user-pic")
          .addClass(color_icons[(line.user_id ?? 0) % 5]!);
        newLine.find(".action-icon").addClass("icon-plus");

        newLine.find(".action-name").html(actionType_add);
        switch (line.object) {
          case "user":
            final_albumInfos = actionInfos_user_added.replace(
              "%d",
              String(line.counter),
            );
            newLine.find(".action-section").addClass("icon-user-1");

            break;
          case "album":
            final_albumInfos = actionInfos_album_added.replace(
              "%d",
              String(line.counter),
            );
            newLine.find(".action-section").addClass("icon-folder-open");

            break;
          case "group":
            final_albumInfos = actionInfos_group_added.replace(
              "%d",
              String(line.counter),
            );
            newLine.find(".action-section").addClass("icon-users-1");

            break;
          case "photo":
            final_albumInfos = actionInfos_photo_added.replace(
              "%d",
              String(line.counter),
            );
            newLine.find(".action-section").addClass("icon-picture");

            break;
          case "tag":
            final_albumInfos = actionInfos_tag_added.replace(
              "%d",
              String(line.counter),
            );
            newLine.find(".action-section").addClass("icon-tags");

            break;
          default:
            final_albumInfos =
              line.counter + " " + line.object + " " + line.action;

            break;
        }

        break;
      case "delete":
        newLine.find(".action-type").addClass("icon-red");
        newLine
          .find(".user-pic")
          .addClass(color_icons[(line.user_id ?? 0) % 5]!);
        newLine.find(".action-icon").addClass("icon-trash-1");

        newLine.find(".action-name").html(actionType_delete);
        switch (line.object) {
          case "user":
            final_albumInfos = actionInfos_user_deleted.replace(
              "%d",
              String(line.counter),
            );
            newLine.find(".action-section").addClass("icon-user-1");

            break;
          case "album":
            final_albumInfos = actionInfos_album_deleted.replace(
              "%d",
              String(line.counter),
            );
            newLine.find(".action-section").addClass("icon-folder-open");

            break;
          case "group":
            final_albumInfos = actionInfos_group_deleted.replace(
              "%d",
              String(line.counter),
            );
            newLine.find(".action-section").addClass("icon-users-1");

            break;
          case "photo":
            final_albumInfos = actionInfos_photo_deleted.replace(
              "%d",
              String(line.counter),
            );
            newLine.find(".action-section").addClass("icon-picture");

            break;
          case "tag":
            final_albumInfos = actionInfos_tag_deleted.replace(
              "%d",
              String(line.counter),
            );
            newLine.find(".action-section").addClass("icon-tags");

            break;
          default:
            final_albumInfos =
              line.counter + " " + line.object + " " + line.action;
            break;
        }

        break;
      case "move":
        newLine.find(".action-type").addClass("icon-yellow");
        newLine
          .find(".user-pic")
          .addClass(color_icons[(line.user_id ?? 0) % 5]!);
        newLine.find(".action-icon").addClass("icon-move");

        newLine.find(".action-name").html(actionType_move);
        switch (line.object) {
          case "album":
            final_albumInfos = actionInfos_album_moved.replace(
              "%d",
              String(line.counter),
            );
            newLine.find(".action-section").addClass("icon-folder-open");

            break;
          case "group":
            final_albumInfos = actionInfos_group_moved.replace(
              "%d",
              String(line.counter),
            );
            newLine.find(".action-section").addClass("icon-users-1");

            break;
          case "photo":
            final_albumInfos = actionInfos_photo_moved.replace(
              "%d",
              String(line.counter),
            );
            newLine.find(".action-section").addClass("icon-picture");

            break;
          case "tag":
            final_albumInfos = actionInfos_tag_moved.replace(
              "%d",
              String(line.counter),
            );
            newLine.find(".action-section").addClass("icon-tags");

            break;
          default:
            final_albumInfos =
              line.counter + " " + line.object + " " + line.action;
            break;
        }

        break;
      case "login":
        newLine.find(".action-type").addClass("icon-purple");
        newLine
          .find(".user-pic")
          .addClass(color_icons[(line.user_id ?? 0) % 5]!);
        newLine.find(".action-icon").addClass("icon-key");
        newLine.find(".action-section").addClass("icon-user-1");

        newLine.find(".action-name").html(actionType_login);

        final_albumInfos = actionInfos_user_logged_in.replace(
          "%d",
          String(line.counter),
        );

        break;
      case "logout":
        newLine.find(".action-type").addClass("icon-purple");
        if (line.user_id != 2) {
          newLine
            .find(".user-pic")
            .addClass(color_icons[(line.user_id ?? 0) % 5]!);
        } else {
          newLine
            .find(".user-pic")
            .addClass(color_icons[(line.object_id[0] ?? 0) % 5]!);
        }
        newLine.find(".action-icon").addClass("icon-logout");
        newLine.find(".action-section").addClass("icon-user-1");

        newLine.find(".action-name").html(actionType_logout);

        final_albumInfos = actionInfos_user_logged_out.replace(
          "%d",
          String(line.counter),
        );

        break;

      default:
        newLine.find(".action-type").addClass("icon-purple");
        newLine
          .find(".user-pic")
          .addClass(color_icons[(line.user_id ?? 0) % 5]!);
        newLine.find(".action-section").addClass("icon-user-1");
        newLine.find(".action-name").html(line.action);
        final_albumInfos = "x" + line.counter;
        break;
    }
  }

  newLine.find(".action-infos-test").html(final_albumInfos);

  /* Action_section */
  newLine.find(".nb_items").html(String(line.counter));

  /* Date_section */
  newLine.find(".date-day").html(line.date);
  newLine.find(".date-hour").html(line.hour);

  /* User _Section */
  newLine.find(".user-name").html(line.username);
  newLine.find(".user-pic").html(get_initials(line.username));

  /* Detail_section */
  newLine.find(".detail-item-1").html(line.ip_address ?? "");
  newLine.find(".detail-item-1").attr("title", "IP: " + line.ip_address);

  if (line.detailsType == "script") {
    newLine.find(".detail-item-2").html(line.details.script ?? "");
    newLine.find(".detail-item-2").attr("title", "Script");
  } else if (line.detailsType == "method") {
    newLine.find(".detail-item-2").html(line.details.method ?? "");
    newLine.find(".detail-item-2").attr("title", "API Method");
  }

  if (line.details.agent) {
    const api_key = line.details.connected_with ? "API Key, " : "";
    const details = line.details.connected_with
      ? '<i class="icon-key"></i>' + line.details.agent
      : line.details.agent;
    newLine.find(".detail-item-3").html(details);
    newLine
      .find(".detail-item-3")
      .attr("title", api_key + "User-Agent: " + line.details.agent);
  } else if (
    line.details.users &&
    line.action != "logout" &&
    line.action != "login"
  ) {
    const user_string = [...new Set(line.details.users)].toString();
    newLine.find(".detail-item-3").html(user_string);
    newLine
      .find(".detail-item-3")
      .attr("title", users_key + ": " + user_string);
  } else {
    newLine.find(".detail-item-3").remove();
  }

  newLine.addClass("uid-" + line.user_id);

  displayLine(newLine);
}

function displayLine(line: JQuery) {
  $(".tab").append(line);
}

function emptyLine() {
  $(".tab-title").hide();
  $(".activity-noresult").show();
}

function get_initials(username: string) {
  const words = username.toUpperCase().split(" ");
  let res = words[0]![0]!;

  if (words.length > 1 && words[1]![0] !== undefined) {
    res += words[1]![0];
  }
  return res;
}

function setCreationDate(startDate: string, endDate: string) {
  $(".start-date").html(startDate);

  $(".end-date").html(endDate);
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

$(".pagination-arrow.rigth").on("click", () => {
  move_to_page(actual_page + 1);
});

$(".pagination-arrow.left").on("click", () => {
  move_to_page(actual_page - 1);
});

function update_pagination_menu(_page?: number) {
  updateArrows();
  update_pagination_items();
  if (end_page && actual_page == 1) {
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
  if (end_page) {
    $(".pagination-arrow.rigth").addClass("unavailable");
  } else {
    $(".pagination-arrow.rigth").removeClass("unavailable");
  }
}

function update_pagination_items() {
  $(".pagination-item-container a").remove();
  $(".pagination-item-container span").remove();

  append_pagination_item(1);

  if (actual_page > 2) {
    append_pagination_item();
  }
  if (actual_page != 1) {
    append_pagination_item(actual_page);
  }
  if (!end_page) {
    append_pagination_item();
  }
}

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

function page_reset() {
  activity_page = 1;
  current_page_offset = 0;
  page_offsets = [0];
  actual_page = 1;
  end_page = false;
}

$(document).ready(function () {
  $("h1").append(`<span class='badge-number'>` + (nb_users - 1) + `</span>`);

  $("select.user-selecter").on("change", function (_user) {
    if ($(".user-selecter .selectize-input").hasClass("full")) {
      page_reset();
      if ($(".user-selecter .selectize-input .item").data("value") == "none") {
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
          $(".user-selecter .selectize-input .item").data("value") as
            string | number | undefined,
          action_filter,
          object_filter,
          [date_min_filter, date_max_filter],
          additional_filt_value,
        );
      }
    }
  });

  $("select.action-selecter").on("change", function (_user) {
    if ($(".action-selecter .selectize-input").hasClass("full")) {
      page_reset();
      if (
        $(".action-selecter .selectize-input .item").data("value") == "none"
      ) {
        //{* call ajax sur activity list sans action et object *}
        if (additional_filt_type) {
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
        const object = (
          $(".action-selecter .selectize-input .item").data("value") as string
        ).split("/")[0];
        const action = (
          $(".action-selecter .selectize-input .item").data("value") as string
        ).split("/")[1];
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
  });

  $("#date_min_activity").on("change", function (_user) {
    page_reset();
    if ($("#date_min_activity").val() == "") {
      document
        .getElementById("date_max_activity")!
        .setAttribute("min", date_min);
    } else {
      document
        .getElementById("date_max_activity")!
        .setAttribute("min", String($("#date_min_activity").val()));
    }
    void get_user_activity(
      activity_page,
      uid_filter,
      action_filter,
      object_filter,
      [$("#date_min_activity").val() as string, date_max_filter],
      additional_filt_value,
    );
  });

  $("#date_max_activity").on("change", function (_user) {
    page_reset();
    if ($("#date_max_activity").val() == "") {
      document
        .getElementById("date_min_activity")!
        .setAttribute("max", date_max);
    } else {
      document
        .getElementById("date_min_activity")!
        .setAttribute("max", String($("#date_max_activity").val()));
    }
    void get_user_activity(
      activity_page,
      uid_filter,
      action_filter,
      object_filter,
      [date_min_filter, $("#date_max_activity").val() as string],
      additional_filt_value,
    );
  });

  jQuery(".user-selecter").selectize();
  jQuery(".user-selecter")[0]!.selectize.setValue(null);

  jQuery(".action-selecter").selectize();
  jQuery(".action-selecter")[0]!.selectize.setValue(null);

  if (additional_filt_type) {
    $("#activityMoreFilters").addClass("extend-padding");
  } else {
    $("#activityMoreFiltersContent").hide();
  }
  //var used to prevent the user to interfere with the collapsible when it's toggling, to avoid some problems
  let toggleTriggered = false;
  $("#activityMoreFilters").on("click", function () {
    if (
      $("#activityMoreFiltersContent").css("display") == "none" &&
      toggleTriggered == false
    ) {
      toggleTriggered = true;
      $("#activityMoreFilters").addClass("extend-padding");
      $("#activityMoreFiltersContent").slideToggle(function () {
        toggleTriggered = false;
      });
    } else if (
      $("#activityMoreFiltersContent").css("display") == "flex" &&
      toggleTriggered == false
    ) {
      toggleTriggered = true;
      $("#activityMoreFiltersContent").slideToggle(function () {
        $("#activityMoreFilters").removeClass("extend-padding");
        toggleTriggered = false;
      });
    }
  });
});
