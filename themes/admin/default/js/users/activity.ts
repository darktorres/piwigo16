import type { operations } from "../../../../../openapi/client/schema";
// common.ts's own side effects only (font-checkbox init, search-cancel
// bindings) -- this page has no other first-party consumer of
// common.ts's own real exports. Real, accepted behavior change (docs/
// PLAN.md's own Design §6 precedent): common.ts used to load at
// UserActivityView's own explicit `LoadMode::Footer`; folded into this
// file, it now runs at this file's own `LoadMode::Async` instead (the
// only registrant page where common.ts's timing actually changes).
import "../common";

import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../../default/js/pageData";
import { ajax } from "../../../../default/js/vendor/utils/ajax";
import { selectize } from "../../../../default/js/vendor/widgets/selectize";
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
} from "../../../../default/js/vendor/utils/dom";

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

type ActivityListResponse =
  operations["activityList"]["responses"][200]["content"]["application/json"];
type ActivityRow = ActivityListResponse["activities"][number];

// `objectId`'s own real shape, filtered from a URL query param -- 3
// repeats (sonarjs/use-type-alias).
type ActivityObjectId = string | number | null | undefined;

const nbUsers = pwg_getPageData<number>("nb_users");

const additionalFiltType = pwg_getPageData<string | false>(
  "additional_filt_type",
);
const additionalFiltValue = pwg_getPageData<string | null>(
  "additional_filt_value",
);

const colorIcons = [
  "icon-red",
  "icon-blue",
  "icon-yellow",
  "icon-purple",
  "icon-green",
];
let activityPage = 1;
let pageOffsets: number[] = [0];
let actualPage = 1;
let endPage = false;
let uidFilter: number | undefined;
let actionFilter: string | undefined;
let objectFilter: string | undefined;
let dateMinFilter = pwg_getPageData<string>("activity_dates_min");
let dateMaxFilter = pwg_getPageData<string>("activity_dates_max");

const dateMin = pwg_getPageData<string>("activity_dates_min");
const dateMax = pwg_getPageData<string>("activity_dates_max");

const pageEllipsis = "<span>...</span>";
const pageItem = '<a data-page="%d">%d</a>';
const usersKey = pwg_getPageString("Users");

const actionTypeAdd = pwg_getPageString("add");
const actionTypeDelete = pwg_getPageString("deletion");
const actionTypeMove = pwg_getPageString("move");
const actionTypeEdit = pwg_getPageString("edit");
const actionTypeLogin = pwg_getPageString("login");
const actionTypeLogout = pwg_getPageString("logout");

const actionInfosAlbumAdded = pwg_getPageString("%d album added");
const actionInfosAlbumDeleted = pwg_getPageString("%d album deleted");
const actionInfosAlbumEdited = pwg_getPageString("%d album edited");
const actionInfosAlbumMoved = pwg_getPageString("%d album moved");

const actionInfosAlbumsAdded = pwg_getPageString("%d albums added");
const actionInfosAlbumsDeleted = pwg_getPageString("%d albums deleted");
const actionInfosAlbumsEdited = pwg_getPageString("%d albums edited");
const actionInfosAlbumsMoved = pwg_getPageString("%d albums moved");

const actionInfosUserAdded = pwg_getPageString("%d user added");
const actionInfosUserDeleted = pwg_getPageString("%d user deleted");
const actionInfosUserEdited = pwg_getPageString("%d user edited");
const actionInfosUserLoggedIn = pwg_getPageString("%d user logged in");
const actionInfosUserLoggedOut = pwg_getPageString("%d user logged out");

const actionInfosUsersAdded = pwg_getPageString("%d users added");
const actionInfosUsersDeleted = pwg_getPageString("%d users deleted");
const actionInfosUsersEdited = pwg_getPageString("%d users edited");
const actionInfosUsersLoggedIn = pwg_getPageString("%d users logged in");
const actionInfosUsersLoggedOut = pwg_getPageString("%d users logged out");

const actionInfosPhotoAdded = pwg_getPageString("%d photo added");
const actionInfosPhotoDeleted = pwg_getPageString("%d photo deleted");
const actionInfosPhotoEdited = pwg_getPageString("%d photo edited");
const actionInfosPhotoMoved = pwg_getPageString("%d photo moved");

const actionInfosPhotosAdded = pwg_getPageString("%d photos added");
const actionInfosPhotosDeleted = pwg_getPageString("%d photos deleted");
const actionInfosPhotosEdited = pwg_getPageString("%d photos edited");
const actionInfosPhotosMoved = pwg_getPageString("%d photos moved");

const actionInfosGroupAdded = pwg_getPageString("%d group added");
const actionInfosGroupDeleted = pwg_getPageString("%d group deleted");
const actionInfosGroupEdited = pwg_getPageString("%d group edited");
const actionInfosGroupMoved = pwg_getPageString("%d group moved");

const actionInfosGroupsAdded = pwg_getPageString("%d groups added");
const actionInfosGroupsDeleted = pwg_getPageString("%d groups deleted");
const actionInfosGroupsEdited = pwg_getPageString("%d groups edited");
const actionInfosGroupsMoved = pwg_getPageString("%d groups moved");

const actionInfosTagAdded = pwg_getPageString("%d tag added");
const actionInfosTagDeleted = pwg_getPageString("%d tag deleted");
const actionInfosTagEdited = pwg_getPageString("%d tag edited");
const actionInfosTagMoved = pwg_getPageString("%d tag moved");

const actionInfosTagsAdded = pwg_getPageString("%d tags added");
const actionInfosTagsDeleted = pwg_getPageString("%d tags deleted");
const actionInfosTagsEdited = pwg_getPageString("%d tags edited");
const actionInfosTagsMoved = pwg_getPageString("%d tags moved");

// Declared before the immediately-invoked call below: getUserActivity()
// itself is hoisted (a function declaration), but this const binding
// would otherwise still be in its temporal dead zone at that call site
// since top-level script execution reaches it before this line.
const ACTIVITY_DISPLAY_PAGE_SIZE = 100;

if (additionalFiltType !== false) {
  objectFilter = additionalFiltType;
}

void getUserActivity(
  activityPage,
  uidFilter,
  actionFilter,
  objectFilter,
  [dateMinFilter, dateMaxFilter],
  additionalFiltValue,
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
interface ActivityQueryParams {
  offset: number;
  dateMin?: string | undefined;
  dateMax?: string | undefined;
  userId?: number;
  action?: string;
  object?: string;
  objectId?: string | number;
}

/** Part of `fetchAndMergeActivityLines()`'s own extraction, below. */
function buildActivityQueryParams(
  offset: number,
  uid: number | undefined,
  action: string | undefined,
  object: string | undefined,
  date: (string | undefined)[],
  id: ActivityObjectId,
): ActivityQueryParams {
  const params: ActivityQueryParams = {
    offset: offset,
    dateMin: date[0],
    dateMax: date[1],
  };
  if (uid !== undefined) params.userId = uid;
  if (action !== undefined) params.action = action;
  if (object !== undefined) params.object = object;
  if (id !== undefined && id !== null) params.objectId = id;
  return params;
}

/**
 * Part of `fetchAndMergeActivityLines()`'s own extraction, below --
 * appends one `/api/v1/activity` row onto `lines`, merging it into the
 * last line when it shares the same session/object/action key (the
 * client-side equivalent of GetListHandler's own merge step). Returns
 * the merge key to carry into the next row.
 */
function mergeActivityRow(
  lines: MergedActivityLine[],
  row: ActivityRow,
  currentKey: string,
): string {
  const lineKey = row.sessionIdx + "~" + row.object + "~" + row.action + "~";

  if (lineKey === currentKey) {
    const last = lines[lines.length - 1]!;
    last.counter++;
    last.object_id.push(row.objectId);
    return currentKey;
  }

  const details: MergedActivityDetails = row.details ? { ...row.details } : {};
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
    username: row.performedByUsername ?? "user#" + String(row.performedBy),
    detailsType: detailsType,
    details: details,
    counter: 1,
  });

  return lineKey;
}

/**
 * Part of `fetchAndMergeActivityLines()`'s own extraction, below --
 * resolves display usernames for every merged "user"-object line's own
 * `object_id` list (GetListHandler's own equivalent step).
 */
async function resolveUserActivityUsernames(
  lines: MergedActivityLine[],
): Promise<void> {
  const userLines = lines.filter((l) => l.object === "user");
  if (userLines.length === 0) {
    return;
  }
  const allUserIds = [...new Set(userLines.flatMap((l) => l.object_id))];
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/utils/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
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

async function fetchAndMergeActivityLines(
  startOffset: number,
  uid: number | undefined,
  action: string | undefined,
  object: string | undefined,
  date: (string | undefined)[],
  id: ActivityObjectId,
) {
  let offset = startOffset;
  let hasMore = true;
  const lines: MergedActivityLine[] = [];
  let currentKey = "";

  while (lines.length < ACTIVITY_DISPLAY_PAGE_SIZE && hasMore) {
    const params = buildActivityQueryParams(
      offset,
      uid,
      action,
      object,
      date,
      id,
    );

    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/utils/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
    const response = (await ajax({
      url: "api/v1/activity",
      type: "GET",
      dataType: "json",
      data: params,
    })) as ActivityListResponse;

    ({ hasMore } = response);

    for (const row of response.activities) {
      offset++;

      if (lines.length >= ACTIVITY_DISPLAY_PAGE_SIZE) {
        hasMore = true;
        break;
      }

      currentKey = mergeActivityRow(lines, row, currentKey);
    }
  }

  await resolveUserActivityUsernames(lines);

  return { lines: lines, endPage: !hasMore, nextOffset: offset };
}

async function getUserActivity(
  page: number,
  uid: number | undefined,
  action: string | undefined,
  object: string | undefined,
  date: (string | undefined)[],
  id: ActivityObjectId,
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
      pageOffsets[page - 1]!,
      uid,
      action,
      object,
      date,
      id,
    );

    uidFilter = uid;
    actionFilter = action;
    objectFilter = object;
    dateMinFilter = date[0]!;
    dateMaxFilter = date[1]!;

    hide(document.querySelectorAll(".loading"));

    if (merged.lines.length > 0) {
      merged.lines.forEach((line) => {
        lineConstructor(line);
      });
    } else {
      emptyLine();
    }

    ({ endPage } = merged);
    if (!pageOffsets.includes(merged.nextOffset)) {
      pageOffsets.push(merged.nextOffset);
    }

    removeClass(
      document.querySelectorAll(".user-update-spinner"),
      "icon-spin6",
    );
    show(document.querySelectorAll(".pagination-item-container"));
    updatePaginationMenu();
  } catch (e: unknown) {
    console.error("ajax call failed", e);
  }
}

interface ActionObjectMessages {
  singular: string;
  plural: string;
  sectionIconClass: string;
}

// Message-lookup counterpart of `lineConstructor()`'s own former nested
// `switch(line.action) { switch(line.object) {...} } }` x2 (once per
// singular/plural branch) -- every real i18n string constant above,
// keyed by the same `action`/`object` values those switches matched
// on. `move` genuinely has no "user" case (neither branch's own switch
// ever did), so it's the one action missing that key here too.
const ACTION_OBJECT_MESSAGES: Record<
  string,
  Record<string, ActionObjectMessages>
> = {
  edit: {
    user: {
      singular: actionInfosUserEdited,
      plural: actionInfosUsersEdited,
      sectionIconClass: "icon-user-1",
    },
    album: {
      singular: actionInfosAlbumEdited,
      plural: actionInfosAlbumsEdited,
      sectionIconClass: "icon-folder-open",
    },
    group: {
      singular: actionInfosGroupEdited,
      plural: actionInfosGroupsEdited,
      sectionIconClass: "icon-users-1",
    },
    photo: {
      singular: actionInfosPhotoEdited,
      plural: actionInfosPhotosEdited,
      sectionIconClass: "icon-picture",
    },
    tag: {
      singular: actionInfosTagEdited,
      plural: actionInfosTagsEdited,
      sectionIconClass: "icon-tags",
    },
  },
  add: {
    user: {
      singular: actionInfosUserAdded,
      plural: actionInfosUsersAdded,
      sectionIconClass: "icon-user-1",
    },
    album: {
      singular: actionInfosAlbumAdded,
      plural: actionInfosAlbumsAdded,
      sectionIconClass: "icon-folder-open",
    },
    group: {
      singular: actionInfosGroupAdded,
      plural: actionInfosGroupsAdded,
      sectionIconClass: "icon-users-1",
    },
    photo: {
      singular: actionInfosPhotoAdded,
      plural: actionInfosPhotosAdded,
      sectionIconClass: "icon-picture",
    },
    tag: {
      singular: actionInfosTagAdded,
      plural: actionInfosTagsAdded,
      sectionIconClass: "icon-tags",
    },
  },
  delete: {
    user: {
      singular: actionInfosUserDeleted,
      plural: actionInfosUsersDeleted,
      sectionIconClass: "icon-user-1",
    },
    album: {
      singular: actionInfosAlbumDeleted,
      plural: actionInfosAlbumsDeleted,
      sectionIconClass: "icon-folder-open",
    },
    group: {
      singular: actionInfosGroupDeleted,
      plural: actionInfosGroupsDeleted,
      sectionIconClass: "icon-users-1",
    },
    photo: {
      singular: actionInfosPhotoDeleted,
      plural: actionInfosPhotosDeleted,
      sectionIconClass: "icon-picture",
    },
    tag: {
      singular: actionInfosTagDeleted,
      plural: actionInfosTagsDeleted,
      sectionIconClass: "icon-tags",
    },
  },
  move: {
    album: {
      singular: actionInfosAlbumMoved,
      plural: actionInfosAlbumsMoved,
      sectionIconClass: "icon-folder-open",
    },
    group: {
      singular: actionInfosGroupMoved,
      plural: actionInfosGroupsMoved,
      sectionIconClass: "icon-users-1",
    },
    photo: {
      singular: actionInfosPhotoMoved,
      plural: actionInfosPhotosMoved,
      sectionIconClass: "icon-picture",
    },
    tag: {
      singular: actionInfosTagMoved,
      plural: actionInfosTagsMoved,
      sectionIconClass: "icon-tags",
    },
  },
};

interface ActionTypeStyle {
  typeClass: string;
  iconClass: string;
  nameStr: string;
}

const ACTION_TYPE_STYLES: Record<string, ActionTypeStyle> = {
  edit: {
    typeClass: "icon-blue",
    iconClass: "icon-pencil",
    nameStr: actionTypeEdit,
  },
  add: {
    typeClass: "icon-green",
    iconClass: "icon-plus",
    nameStr: actionTypeAdd,
  },
  delete: {
    typeClass: "icon-red",
    iconClass: "icon-trash-1",
    nameStr: actionTypeDelete,
  },
  move: {
    typeClass: "icon-yellow",
    iconClass: "icon-move",
    nameStr: actionTypeMove,
  },
};

/**
 * Part of `lineConstructor()`'s own extraction, below -- the shared
 * rendering for the 4 object-carrying actions (edit/add/delete/move),
 * looked up in `ACTION_TYPE_STYLES`/`ACTION_OBJECT_MESSAGES` above
 * instead of a real per-action, per-object switch/case pair.
 */
function renderObjectAction(
  newLine: Element,
  line: MergedActivityLine,
  action: string,
): string {
  const style = ACTION_TYPE_STYLES[action]!;
  addClass(find(newLine, ".action-type"), style.typeClass);
  addClass(find(newLine, ".user-pic"), colorIcons[(line.user_id ?? 0) % 5]!);
  addClass(find(newLine, ".action-icon"), style.iconClass);
  html(find(newLine, ".action-name"), style.nameStr);

  const messages = ACTION_OBJECT_MESSAGES[action]?.[line.object];
  if (!messages) {
    return String(line.counter) + " " + line.object + " " + line.action;
  }
  addClass(find(newLine, ".action-section"), messages.sectionIconClass);
  const template = line.counter > 1 ? messages.plural : messages.singular;
  return template.replace("%d", String(line.counter));
}

/** Part of `lineConstructor()`'s own extraction, below. */
function renderLoginAction(newLine: Element, line: MergedActivityLine): string {
  addClass(find(newLine, ".action-type"), "icon-purple");
  addClass(find(newLine, ".user-pic"), colorIcons[(line.user_id ?? 0) % 5]!);
  addClass(find(newLine, ".action-icon"), "icon-key");
  addClass(find(newLine, ".action-section"), "icon-user-1");
  html(find(newLine, ".action-name"), actionTypeLogin);
  const template =
    line.counter > 1 ? actionInfosUsersLoggedIn : actionInfosUserLoggedIn;
  return template.replace("%d", String(line.counter));
}

/** Part of `lineConstructor()`'s own extraction, below. */
function renderLogoutAction(
  newLine: Element,
  line: MergedActivityLine,
): string {
  addClass(find(newLine, ".action-type"), "icon-purple");
  if (line.user_id !== 2) {
    addClass(find(newLine, ".user-pic"), colorIcons[(line.user_id ?? 0) % 5]!);
  } else {
    addClass(
      find(newLine, ".user-pic"),
      colorIcons[(line.object_id[0] ?? 0) % 5]!,
    );
  }
  addClass(find(newLine, ".action-icon"), "icon-logout");
  addClass(find(newLine, ".action-section"), "icon-user-1");
  html(find(newLine, ".action-name"), actionTypeLogout);
  const template =
    line.counter > 1 ? actionInfosUsersLoggedOut : actionInfosUserLoggedOut;
  return template.replace("%d", String(line.counter));
}

/** Part of `lineConstructor()`'s own extraction, below. */
function renderDefaultAction(
  newLine: Element,
  line: MergedActivityLine,
): string {
  addClass(find(newLine, ".action-type"), "icon-purple");
  addClass(find(newLine, ".user-pic"), colorIcons[(line.user_id ?? 0) % 5]!);
  addClass(find(newLine, ".action-section"), "icon-user-1");
  html(find(newLine, ".action-name"), line.action);
  return "x" + String(line.counter);
}

/**
 * Part of `lineConstructor()`'s own extraction, below -- applies the
 * action-type/icon/name/section classes for `line`'s own action and
 * returns the "N thing(s) verbed" message to show in `.action-infos-test`.
 */
function computeLineAction(newLine: Element, line: MergedActivityLine): string {
  switch (line.action) {
    case "edit":
    case "add":
    case "delete":
    case "move":
      return renderObjectAction(newLine, line, line.action);
    case "login":
      return renderLoginAction(newLine, line);
    case "logout":
      return renderLogoutAction(newLine, line);
    default:
      return renderDefaultAction(newLine, line);
  }
}

function lineConstructor(line: MergedActivityLine) {
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- cloning an Element always produces an Element (real DOM guarantee); cloneNode()'s own lib.dom signature just isn't narrowed per-subtype.
  const newLine = document.getElementById("-1")!.cloneNode(true) as Element;

  show(document.querySelectorAll(".tab-title"));
  hide(document.querySelectorAll(".activity-noresult"));
  removeClass(newLine, "hide");

  attr(newLine, "id", String(line.id));

  const finalAlbumInfos = computeLineAction(newLine, line);

  html(find(newLine, ".action-infos-test"), finalAlbumInfos);

  /* Action_section */
  html(find(newLine, ".nb_items"), String(line.counter));

  /* Date_section */
  html(find(newLine, ".date-day"), line.date);
  html(find(newLine, ".date-hour"), line.hour);

  /* User _Section */
  html(find(newLine, ".user-name"), line.username);
  html(find(newLine, ".user-pic"), getInitials(line.username));

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
    const apiKey = isConnectedWith ? "API Key, " : "";
    const details = isConnectedWith
      ? '<i class="icon-key"></i>' + line.details.agent
      : line.details.agent;
    html(find(newLine, ".detail-item-3"), details);
    attr(
      find(newLine, ".detail-item-3"),
      "title",
      apiKey + "User-Agent: " + line.details.agent,
    );
  } else if (
    line.details.users &&
    line.action !== "logout" &&
    line.action !== "login"
  ) {
    const userString = [...new Set(line.details.users)].toString();
    html(find(newLine, ".detail-item-3"), userString);
    attr(
      find(newLine, ".detail-item-3"),
      "title",
      usersKey + ": " + userString,
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

function getInitials(username: string) {
  const words = username.toUpperCase().split(" ");
  let res = words[0]![0]!;

  const [, secondWord] = words;
  if (secondWord !== undefined && secondWord.length > 0) {
    res += secondWord[0]!;
  }
  return res;
}

function moveToPage(page: number) {
  if (page < 0) return;
  actualPage = page;
  updatePaginationMenu(page);
  void getUserActivity(
    page,
    uidFilter,
    actionFilter,
    objectFilter,
    [dateMinFilter, dateMaxFilter],
    additionalFiltValue,
  );
}

on(document.querySelectorAll(".pagination-arrow.rigth"), "click", () => {
  moveToPage(actualPage + 1);
});

on(document.querySelectorAll(".pagination-arrow.left"), "click", () => {
  moveToPage(actualPage - 1);
});

function updatePaginationMenu(_page?: number) {
  updateArrows();
  updatePaginationItems();
  if (endPage && actualPage === 1) {
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
  if (endPage) {
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

function updatePaginationItems() {
  remove(document.querySelectorAll(".pagination-item-container a"));
  remove(document.querySelectorAll(".pagination-item-container span"));

  appendPaginationItem(1);

  if (actualPage > 2) {
    appendPaginationItem();
  }
  if (actualPage !== 1) {
    appendPaginationItem(actualPage);
  }
  if (!endPage) {
    appendPaginationItem();
  }
}

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

function pageReset() {
  activityPage = 1;
  pageOffsets = [0];
  actualPage = 1;
  endPage = false;
}

ready(function () {
  append(
    document.querySelectorAll("h1"),
    `<span class='badge-number'>` + String(nbUsers - 1) + `</span>`,
  );

  // The `.selectize-input`/`.item[data-value]` markup below is
  // `vendor/widgets/selectize.ts`'s own real, rendered DOM (P49-B group 6).
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
        pageReset();
        const item = document.querySelector(
          ".user-selecter .selectize-input .item",
        );
        const value = item !== null ? data(item, "value") : undefined;
        if (value === "none") {
          void getUserActivity(
            1,
            undefined,
            actionFilter,
            objectFilter,
            [dateMinFilter, dateMaxFilter],
            additionalFiltValue,
          );
        } else {
          void getUserActivity(
            1,
            // Excluded the "none" sentinel above -- data() already coerces
            // a real numeric data-value to a real number; Number() is a
            // no-op there and a real parse for any other case.
            Number(value),
            actionFilter,
            objectFilter,
            [dateMinFilter, dateMaxFilter],
            additionalFiltValue,
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
        pageReset();
        const item = document.querySelector(
          ".action-selecter .selectize-input .item",
        );
        const value = item !== null ? data(item, "value") : undefined;
        if (value === "none") {
          if (additionalFiltType !== false) {
            void getUserActivity(
              1,
              uidFilter,
              undefined,
              objectFilter,
              [dateMinFilter, dateMaxFilter],
              additionalFiltValue,
            );
          } else {
            void getUserActivity(
              1,
              uidFilter,
              undefined,
              undefined,
              [dateMinFilter, dateMaxFilter],
              additionalFiltValue,
            );
          }
        } else {
          // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- data() reads a data-* attribute this same app's own setData()/template writes, never adversarial input.
          const [object, action] = (value as string).split("/");
          void getUserActivity(
            1,
            uidFilter,
            action,
            object,
            [dateMinFilter, dateMaxFilter],
            additionalFiltValue,
          );
        }
      }
    },
  );

  on(
    document.querySelectorAll("#date_min_activity"),
    "change",
    function (): void {
      pageReset();
      const minVal = val(document.querySelectorAll("#date_min_activity"));
      if (minVal === "") {
        document
          .getElementById("date_max_activity")!
          .setAttribute("min", dateMin);
      } else {
        document
          .getElementById("date_max_activity")!
          .setAttribute("min", String(minVal));
      }
      void getUserActivity(
        activityPage,
        uidFilter,
        actionFilter,
        objectFilter,
        [minVal, dateMaxFilter],
        additionalFiltValue,
      );
    },
  );

  on(
    document.querySelectorAll("#date_max_activity"),
    "change",
    function (): void {
      pageReset();
      const maxVal = val(document.querySelectorAll("#date_max_activity"));
      if (maxVal === "") {
        document
          .getElementById("date_min_activity")!
          .setAttribute("max", dateMax);
      } else {
        document
          .getElementById("date_min_activity")!
          .setAttribute("max", String(maxVal));
      }
      void getUserActivity(
        activityPage,
        uidFilter,
        actionFilter,
        objectFilter,
        [dateMinFilter, maxVal],
        additionalFiltValue,
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

  if (additionalFiltType !== false) {
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
