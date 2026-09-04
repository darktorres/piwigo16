import type { operations } from "../../../../openapi/client/schema";
import { sprintf } from "../../../default/js/sprintf";

import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../default/js/pageData";
import { ajax, AjaxError } from "../../../default/js/vendor/utils/ajax";
import { pwgDatepicker } from "../../../default/js/vendor/widgets/datepicker";
import {
  addClass,
  append,
  attr,
  attrOf,
  css,
  data,
  dataId,
  delegate,
  empty,
  hide,
  html,
  htmlOf,
  find,
  on,
  ready,
  remove,
  removeAttr,
  removeClass,
  removeData,
  setData,
  show,
  textOf,
  toggle,
  trigger,
} from "../../../default/js/vendor/utils/dom";
import { tipTip } from "../../../default/js/vendor/widgets/tiptip";

type HistorySearchResponse =
  operations["historySearch"]["responses"][200]["content"]["application/json"];
type HistoryLine = HistorySearchResponse["lines"][number];
type HistorySummary = HistorySearchResponse["summary"];

interface HistoryFilterParams {
  start: string;
  end: string;
  types: Record<number, string>;
  user_id: number;
  image_id: string | number | null;
  filename: string;
  ip: string;
  pageNumber: number;
}

const dateObj = new Date();
let month: number | string = dateObj.getUTCMonth() + 1; //months from 1-12
let day: number | string = dateObj.getUTCDate();
const year = dateObj.getUTCFullYear();

const filterUserName = pwg_getPageData<string>("user_name");

if (month < 10) month = "0" + String(month);
if (day < 10) day = "0" + String(day);

const today = String(year) + "-" + String(month) + "-" + String(day);
const currentParam: HistoryFilterParams = {
  start: "",
  end: today,
  types: {
    0: "none",
    1: "picture",
    2: "high",
    3: "other",
  },
  user_id: pwg_getPageData<number>("user_id"),
  image_id: pwg_getPageData<string>("image_id"),
  filename: "",
  ip: pwg_getPageData<string>("ip"),
  pageNumber: 0, // fetch lines from line 0 to line 100
};

const strDwld = pwg_getPageString("Downloaded");
const strMostVisited = pwg_getPageString("Most visited");
const strBestRated = pwg_getPageString("Best rated");
const strList = pwg_getPageString("Random photo");
const strFavorites = pwg_getPageString("Your favorites");
const strRecentCats = pwg_getPageString("Recent albums");
const strRecentPics = pwg_getPageString("Recent photos");
const strMemories = pwg_getPageString("Memories");
const strNoLongerExistPhoto = pwg_getPageString("This photo no longer exists");
const strTags = pwg_getPageString("Tags");
const unitMB = pwg_getPageString("%s MB");
const strGuest = pwg_getPageString("guest");
const strContactForm = pwg_getPageString("Contact Form");
const strEditImg = pwg_getPageString("Edit photo");

const strSearchDetails: Record<string, string> = {
  allwords: pwg_getPageString("Search for words"),
  datePosted: pwg_getPageString("Post date"),
  tags: strTags,
  cat: pwg_getPageString("Album"),
  author: pwg_getPageString("Author"),
  addedBy: pwg_getPageString("Added by"),
  filetypes: pwg_getPageString("File type"),
};
const strAndMore = pwg_getPageString("and %d more");

const guestId = pwg_getPageData<number>("guest_id");

/** `$("<div>").html(markup).text().trim()` -- strips tags down to plain text. */
function stripHtml(markup: string): string {
  const tempDiv = document.createElement("div");
  tempDiv.innerHTML = markup;
  return tempDiv.textContent.trim();
}

ready(() => {
  activateLineOptions();
  checkFilters();

  if (currentParam.ip !== "") {
    addIpFilter(currentParam.ip);
  }
  if (currentParam.image_id !== "") {
    addImageFilter(currentParam.image_id);
  }
  // `currentParam.user_id` is now plain `number` (P51-D) -- it used to
  // be `string | number`, and every write site but this file's own
  // ready() check set it to the *string* "-1" (or a string user id),
  // while PHP's own `int $userId`/`exposedPageData()` send a real JSON
  // number. A plain `!== "-1"` here compared a number to a string on
  // first load, which is always true regardless of the real sentinel
  // value -- the "Additional filters" user chip rendered unconditionally
  // on every page load. Narrowing every write site to a real number
  // (rather than continuing to paper over it with `Number(...)` here)
  // closes the bug class for good, not just this one comparison.
  if (currentParam.user_id !== -1) {
    addUserFilter(filterUserName);
  }

  on(document.querySelectorAll(".elem-type-select"), "change", function () {
    const selectedType = attrOf(
      document.querySelectorAll(".elem-type-select option:checked"),
      "value",
    );

    if (selectedType === "visited") {
      currentParam.types = {
        0: "none",
        1: "picture",
      };
    } else if (selectedType === "downloaded") {
      currentParam.types = {
        0: "high",
        1: "other",
      };
    } else {
      currentParam.types = {
        0: "none",
        1: "picture",
        2: "high",
        3: "other",
      };
    }

    void fillHistoryResult(currentParam);
  });

  // `vendor/widgets/datepicker.ts`'s own native port dispatches a real native
  // "change" event (bubbling) on the visible field on init/selection --
  // matching the original's own real `this.$input.trigger("change")`
  // -- so a native listener on the `.date-start`/`.date-end` ancestor
  // sees it, including the one fired on page load.
  on(document.querySelectorAll(".date-start"), "change", function () {
    const value = attrOf(
      document.querySelectorAll('.date-start input[name="start"]'),
      "value",
    );
    if (currentParam.start !== value) {
      currentParam.start = value ?? "";
      currentParam.pageNumber = 0;
      void fillHistoryResult(currentParam);
    }
  });

  on(document.querySelectorAll(".date-end"), "change", function () {
    const newValue = attrOf(
      document.querySelectorAll('.date-end input[name="end"]'),
      "value",
    );
    if (currentParam.end !== newValue) {
      currentParam.end = newValue ?? "";
      currentParam.pageNumber = 0;
      // The datepicker first fills the end-date with '1899-12-31',
      // which triggers an unnecessary ajax request
      // when you come to the history search page from a photo.
      if (newValue !== "1899-12-31") {
        void fillHistoryResult(currentParam);
      }
    }
  });

  on(document.querySelectorAll("#start_unset"), "click", function () {
    // Genuine pre-existing bug found only by strict typechecking:
    // `!currentParam.start == ""` compares a boolean to a string,
    // always false -- this guard never actually ran, so the "unset
    // start date" button silently did nothing. Real intent (matching
    // `.date-start`'s own change handler above): only act when a
    // start filter is actually set.
    if (currentParam.start !== "") {
      currentParam.pageNumber = 0;
      currentParam.start = "";
      void fillHistoryResult(currentParam);
    }
  });

  on(document.querySelectorAll("#end_unset"), "click", function () {
    // Same class of bug as #start_unset above, plus a copy-paste typo:
    // compared `currentParam.start` (always false either way) instead
    // of `currentParam.end`, the field this handler actually resets
    // -- `today` is `.end`'s own "unset" sentinel, matching how
    // `#start_unset` above uses `""` for `.start`.
    if (currentParam.end !== today) {
      currentParam.end = today;
      currentParam.pageNumber = 0;
      void fillHistoryResult(currentParam);
    }
  });

  on(document.querySelectorAll(".pagination-arrow.rigth"), "click", () => {
    currentParam.pageNumber += 1;
    void fillHistoryResult(currentParam);
  });

  on(document.querySelectorAll(".pagination-arrow.left"), "click", () => {
    currentParam.pageNumber -= 1;
    void fillHistoryResult(currentParam);
  });

  on(document.querySelectorAll(".refresh-results"), "click", function () {
    void fillHistoryResult(currentParam);
  });
});

// onLoad needed to wait localization loads
ready(function () {
  pwgDatepicker(document.querySelectorAll("[data-datepicker]"), {
    jqueryCode: pwg_getPageData<string | undefined>("jquery_code"),
  });
});

function activateLineOptions() {
  hide(find(document.querySelectorAll(".search-line"), ".img-option"));

  /* Display the option on the click on "..." */
  on(
    find(document.querySelectorAll(".search-line"), ".toggle-img-option"),
    "click",
    function (this: Element) {
      // jQuery's own `.toggle()` display-memory semantics apply here too --
      // use dom.ts's `toggle()` rather than a hand-rolled inline-style
      // check (see comments.ts's own finding for why the naive version is
      // wrong the moment the element starts hidden via a CSS class rule).
      toggle(find(this, ".img-option"));
    },
  );

  /* Hide img options and rename field on click on the screen */

  on(document, "mouseup", function (e: Event) {
    e.stopPropagation();
    let optionIsClicked = false;
    document.querySelectorAll(".img-option span").forEach((span) => {
      if (
        span !== e.target &&
        // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- a real mouseup event's own target inside the document is always a Node (or null), never a bare EventTarget with no Node interface.
        span.contains(e.target as Node | null)
      ) {
        optionIsClicked = true;
      }
    });
    // eslint-disable-next-line @typescript-eslint/no-unnecessary-condition -- real false positive from closure mutation: optionIsClicked is set inside the forEach callback above, which the rule doesn't track (same class as dom.ts's stopped).
    if (!optionIsClicked) {
      hide(find(document.querySelectorAll(".search-line"), ".img-option"));
    }
  });
}

function fillSummaryResult(summary: HistorySummary) {
  empty(document.querySelectorAll(".user-list"));

  html(
    find(document.querySelectorAll(".summary-lines"), ".summary-data"),
    summary.nbLinesText,
  );
  html(
    find(document.querySelectorAll(".summary-weight"), ".summary-data"),
    unitMB.replace("%s", String(summary.filesizeMb)),
  );
  html(
    find(document.querySelectorAll(".summary-users"), ".summary-data"),
    summary.usersText,
  );
  html(
    find(document.querySelectorAll(".summary-guests"), ".summary-data"),
    summary.guestsText,
  );

  if (summary.nbGuests > 0) {
    const summaryGuestsData = find(
      document.querySelectorAll(".summary-guests"),
      ".summary-data",
    );
    addClass(summaryGuestsData, "icon-plus-circled");
    on(summaryGuestsData, "click", function () {
      // Same string-vs-number sentinel mismatch as the ready() handler
      // above -- normalize via Number() rather than a strict string
      // compare.
      if (currentParam.user_id === -1) {
        currentParam.user_id = guestId;
        addGuestFilter(strGuest);
        void fillHistoryResult(currentParam);
      }
    });
    // `.hover(fn)` with a single argument binds the same handler to both
    // mouseenter and mouseleave.
    on(summaryGuestsData, "mouseenter mouseleave", function (this: Element) {
      css(this, "cursor", "pointer");
    });

    show(document.querySelectorAll(".summary-guests"));
  } else {
    hide(document.querySelectorAll(".summary-guests"));
  }

  const userDotTitle = summary.members
    .map((member) => member.username)
    .join(", ");
  attr(document.querySelectorAll(".user-dot"), "title", userDotTitle);
  addClass(document.querySelectorAll(".user-dot"), "tiptip");

  let tmp = 0;
  hide(document.querySelectorAll(".user-dot"));
  // summary.members is already ordered most-active-first
  summary.members.forEach((member) => {
    if (tmp < 5) {
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- cloning an Element always produces an Element (real DOM guarantee); cloneNode()'s own lib.dom signature just isn't narrowed per-subtype.
      const newUserItem = document
        .getElementById("-2")!
        .cloneNode(true) as Element;

      removeClass(newUserItem, "hide");
      html(find(newUserItem, ".user-item-name"), member.username ?? "");
      setData(newUserItem, "user-id", member.userId);

      on(newUserItem, "click", function (this: Element) {
        if (currentParam.user_id !== member.userId) {
          currentParam.user_id = dataId(this, "user-id");
          addUserFilter(member.username);
          void fillHistoryResult(currentParam);
        }
      });
      document.querySelector(".user-list")?.appendChild(newUserItem);
      tmp++;
    } else {
      show(document.querySelectorAll(".user-dot"));
    }
  });
}

function showResults(doShow: boolean) {
  if (doShow) {
    show(document.querySelectorAll(".search-summary"));
    show(document.querySelectorAll(".container"));
  } else {
    hide(document.querySelectorAll(".search-summary"));
    hide(document.querySelectorAll(".container"));
  }
}

async function fillHistoryResult(
  ajaxParam: HistoryFilterParams,
): Promise<void> {
  showResults(false);
  removeClass(document.querySelectorAll(".loading"), "hide");
  hide(document.querySelectorAll(".noResults"));
  empty(document.querySelectorAll(".tab"));

  try {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/utils/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
    const rawData = (await ajax({
      url: "api/v1/history/search",
      data: ajaxParam,
    })) as HistorySearchResponse;

    const { lines, maxPage, summary } = rawData;

    //clear lines before refill

    if (lines.length > 0) {
      let id = 0;
      lines.forEach((line) => {
        lineConstructor(line, id);
        id++;
      });

      fillSummaryResult(summary);
      showResults(true);
      hide(document.querySelectorAll(".noResults"));
    } else {
      showResults(false);
      show(document.querySelectorAll(".noResults"));
    }

    activateLineOptions();
    addClass(document.querySelectorAll(".loading"), "hide");
    updatePagination(maxPage);
    tipTip(document.querySelectorAll(".tiptip"), {
      delay: 0,
      fadeIn: 200,
      fadeOut: 200,
      edgeOffset: 3,
    });
  } catch (e) {
    console.error(e instanceof AjaxError ? e.responseText : e);
  }
}

function lineConstructor(line: HistoryLine, id: number) {
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- cloning an Element always produces an Element (real DOM guarantee); cloneNode()'s own lib.dom signature just isn't narrowed per-subtype.
  const newLine = document.getElementById("-1")!.cloneNode(true) as Element;

  const sections = [
    "categories",
    "tags",
    "best_rated",
    "memories-1-year-ago",
    "list",
    "search",
    "most_visited",
    "recent_pics",
    "recent_cats",
    "favorites",
  ];

  const icons = [
    "line-icon icon-folder-open icon-yellow",
    "line-icon icon-tags icon-blue",
    "line-icon icon-star icon-green",
    "line-icon icon-clock icon-yellow",
    "line-icon icon-dice-solid icon-purple",
    "line-icon icon-search icon-purple",
    "line-icon icon-fire icon-red",
    "line-icon icon-clock icon-yellow",
    "line-icon icon-clock icon-yellow",
    "line-icon icon-heart icon-red",
  ];

  removeClass(newLine, "hide");

  attr(newLine, "id", String(id));

  html(find(newLine, ".date-day"), line.dateFormatted ?? "");
  html(find(newLine, ".date-hour"), line.time);

  html(
    find(newLine, ".user-name"),
    (line.username ?? "") + '<i class="add-filter icon-plus-circled"></i>',
  );

  attr(find(newLine, ".user-name"), "id", String(line.userId));
  if (currentParam.user_id === -1) {
    on(find(newLine, ".user-name"), "click", function (this: Element) {
      currentParam.user_id = Number(attrOf(this, "id"));
      currentParam.pageNumber = 0;
      addUserFilter(htmlOf(this) ?? "");
      void fillHistoryResult(currentParam);
    });
  }

  html(
    find(newLine, ".user-ip"),
    line.ip + '<i class="add-filter icon-plus-circled"></i>',
  );
  setData(find(newLine, ".user-ip")[0]!, "ip", line.ip);
  setupGeoIpHover(find(newLine, ".user-ip")[0]!);
  if (currentParam.ip === "") {
    on(find(newLine, ".user-ip"), "click", function (this: Element) {
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- data() reads a data-* attribute this same app's own setData()/template writes, never adversarial input.
      currentParam.ip = (data(this, "ip") as string | undefined) ?? "";
      currentParam.pageNumber = 0;
      addIpFilter(htmlOf(this) ?? "");
      void fillHistoryResult(currentParam);
    });
  }

  setData(find(newLine, ".add-img-as-filter")[0]!, "img-id", line.imageId);
  if (currentParam.image_id === "") {
    on(find(newLine, ".add-img-as-filter"), "click", function (this: Element) {
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- data() reads a data-* attribute this same app's own setData()/template writes, never adversarial input.
      const imgId = data(this, "img-id") as string | number | null;
      currentParam.image_id = imgId;
      currentParam.pageNumber = 0;
      addImageFilter(imgId);
      void fillHistoryResult(currentParam);
    });
  }

  if (line.imageEditUrl !== null && line.imageEditUrl !== "") {
    attr(find(newLine, ".edit-img"), "href", line.imageEditUrl);
  } else {
    const editImg = find(newLine, ".edit-img");
    attr(editImg, "href", "#");
    addClass(editImg, "notClickable tiptip");
    attr(editImg, "title", strNoLongerExistPhoto);
    on(editImg, "click", (e: Event) => {
      e.preventDefault();
    });
  }

  // Genuine pre-existing bug found via strict typing: this read
  // `line.SECTION` (uppercase) -- a property that has never existed on
  // any real history line (confirmed via grep, no PHP source ever
  // writes it; the real field is `section`, read correctly two other
  // places in this same function, below). Every line therefore always
  // fell through to `default`, showing a generic "unrecognized" entry
  // with no type-specific label/detail text, even though its section
  // icon (via the separate `sections.indexOf(line.section)` lookup
  // further down) rendered correctly. Fixed to the real field.
  switch (line.section) {
    case "tags": {
      if (line.tagNames.length > 1 && line.tagNames.length <= 2) {
        html(
          find(newLine, ".type-name"),
          line.tagNames[0]! + ", " + line.tagNames[1]! + ", ...",
        );
        html(
          find(newLine, ".type-id"),
          "#" +
            String(line.tagIds[0]) +
            ", " +
            String(line.tagIds[1]) +
            ", ...",
        );
      } else if (line.tagNames.length > 2) {
        html(
          find(newLine, ".type-name"),
          line.tagNames[0]! +
            ", " +
            line.tagNames[1]! +
            ", " +
            line.tagNames[2]! +
            ", ...",
        );
        html(
          find(newLine, ".type-id"),
          "#" +
            String(line.tagIds[0]) +
            ", " +
            String(line.tagIds[1]) +
            ", " +
            String(line.tagIds[2]) +
            ", ...",
        );
      } else {
        html(find(newLine, ".type-name"), line.tagNames[0] ?? "");
        html(find(newLine, ".type-id"), "#" + String(line.tagIds[0]));
      }

      const detailStr = line.tagNames.join(", ");
      const detailItem1 = find(newLine, ".detail-item-1");
      html(detailItem1, detailStr);
      attr(detailItem1, "title", detailStr);
      removeClass(detailItem1, "hide");
      addClass(detailItem1, "icon-tags");
      break;
    }

    case "most_visited":
      html(find(newLine, ".type-name"), strMostVisited);
      {
        const detailItem1 = find(newLine, ".detail-item-1");
        html(detailItem1, strMostVisited);
        addClass(detailItem1, "icon-fire");
      }
      hide(find(newLine, ".type-id"));
      break;
    case "best_rated":
      html(find(newLine, ".type-name"), strBestRated);
      {
        const detailItem1 = find(newLine, ".detail-item-1");
        html(detailItem1, strBestRated);
        addClass(detailItem1, "icon-star");
      }
      hide(find(newLine, ".type-id"));
      break;
    case "list":
      html(find(newLine, ".type-name"), strList);
      {
        const detailItem1 = find(newLine, ".detail-item-1");
        html(detailItem1, strList);
        addClass(detailItem1, "icon-dice-solid");
      }
      hide(find(newLine, ".type-id"));
      break;
    case "search": {
      const { searchDetails } = line;
      // Genuinely heterogeneous per-filter-type search-criteria data
      // (same nature as searchFilters.ts's own global_params/
      // fullname_of_cat, deferred to P48) -- each key's real value
      // shape (string[], a nested object, or an opaque `filetypes`
      // blob) varies by which filter was active on the saved search
      // this line's `searchId` refers to.
      const searchIcons: Record<string, any> = {
        allwords: "gallery-icon-search",
        tags: "gallery-icon-tag",
        datePosted: "gallery-icon-calendar-plus",
        cat: "gallery-icon-album",
        author: "gallery-icon-user-edit",
        addedBy: "gallery-icon-user",
        filetypes: "gallery-icon-file-image",
      };
      html(find(newLine, ".type-name"), line.section);
      html(find(newLine, ".type-id"), "#" + String(line.searchId));
      if (line.searchId === null) {
        hide(find(newLine, ".type-id"));
      }

      if (searchDetails === null) {
        hide(find(newLine, ".detail-item-1"));
        break;
      }
      const activeSearchDetails: Record<string, any> = {};
      Object.entries(searchDetails).forEach(([key, value]) => {
        if (value !== null) {
          activeSearchDetails[key] = value;
        }
      });
      let countItem = 1;
      const activeMore: any[] = [];
      const activeItems = Object.keys(activeSearchDetails);
      if (activeItems.length > 0) {
        const hasAllwords = Boolean(activeSearchDetails["allwords"]);
        if (hasAllwords) {
          const item = find(newLine, ".detail-item-" + String(countItem));
          html(item, activeSearchDetails["allwords"].join(" "));
          addClass(item, String(searchIcons["allwords"]) + " tiptip");
          attr(
            item,
            "title",
            "<b>" +
              strSearchDetails["allwords"]! +
              " :</b> " +
              String(activeSearchDetails["allwords"].join(" ")),
          );
          countItem++;
          activeMore.push("allwords");
        }
        const hasCat = Boolean(activeSearchDetails["cat"]);
        if (hasCat) {
          const arrayCat = Object.values(activeSearchDetails["cat"]);
          const cat = arrayCat.join(" + ");
          const text = stripHtml(cat);
          const item = find(newLine, ".detail-item-" + String(countItem));
          html(item, cat);
          addClass(item, String(searchIcons["cat"]) + " tiptip");
          attr(
            item,
            "title",
            "<b>" + strSearchDetails["cat"]! + " :</b> " + text,
          );
          removeClass(item, "hide");
          countItem++;
          activeMore.push("cat");
        }
        if (countItem <= 2 && Boolean(activeSearchDetails["tags"])) {
          const arrayTags = Object.values(activeSearchDetails["tags"]);
          const item = find(newLine, ".detail-item-" + String(countItem));
          html(item, arrayTags.join(" + "));
          addClass(item, String(searchIcons["tags"]) + " tiptip");
          attr(
            item,
            "title",
            "<b>" +
              strSearchDetails["tags"]! +
              " :</b> " +
              arrayTags.join(" + "),
          );
          removeClass(item, "hide");
          countItem++;
          activeMore.push("tags");
        }
        if (countItem <= 2) {
          const badgeToAdd =
            activeItems.length === 1 ? 1 : countItem === 1 ? 2 : 1;
          let badgeAdded = 0;
          activeItems.some((key) => {
            if (key !== "allwords" && key !== "cat" && key !== "tags") {
              // Genuinely heterogeneous per-key value shape, same as
              // activeSearchDetails/searchIcons above.
              let arrayKey: any;
              if (Array.isArray(activeSearchDetails[key])) {
                arrayKey = activeSearchDetails[key];
              } else if (typeof activeSearchDetails[key] === "object") {
                arrayKey = Object.values(activeSearchDetails[key]);
              } else {
                arrayKey = [activeSearchDetails[key]];
              }
              const item = find(newLine, ".detail-item-" + String(countItem));
              html(item, arrayKey.join(" + "));
              addClass(item, String(searchIcons[key]) + " tiptip");
              attr(
                item,
                "title",
                "<b>" +
                  strSearchDetails[key]! +
                  " :</b> " +
                  String(arrayKey.join(" + ")),
              );
              removeClass(item, "hide");
              countItem++;
              badgeAdded++;
              activeMore.push(key);
              if (badgeAdded === badgeToAdd) {
                return true;
              }
            }
            return false;
          });
        }
      } else {
        hide(find(newLine, ".detail-item-1"));
      }
      if (activeItems.length >= 3) {
        let countMore = 0;
        const searchDetailsStr = Object.entries(activeSearchDetails)
          .filter(([key]) => !activeMore.includes(key))
          .map(([key, value]: [string, any]) => {
            let valueStr;
            if (Array.isArray(value)) {
              valueStr = value.join(" + ");
            } else if (typeof value === "object") {
              valueStr = Object.entries(value)
                .map(([, v]: [string, any]) => v)
                .join(" + ");
            } else {
              valueStr = value;
            }

            if (key === "cat") {
              valueStr = stripHtml(valueStr);
            }
            countMore++;
            return `<b>${strSearchDetails[key]!}</b> : ${valueStr}`;
          })
          .join(" <br />");
        const item3 = find(newLine, ".detail-item-3");
        html(item3, sprintf(strAndMore, countMore));
        addClass(item3, "icon-info-circled-1 tiptip");
        attr(item3, "title", searchDetailsStr);
        removeClass(item3, "hide");
      }
      break;
    }
    case "favorites":
      html(find(newLine, ".type-name"), strFavorites);
      {
        const detailItem1 = find(newLine, ".detail-item-1");
        html(detailItem1, strFavorites);
        addClass(detailItem1, "icon-heart");
      }
      hide(find(newLine, ".type-id"));
      break;
    case "recent_cats":
      html(find(newLine, ".type-name"), strRecentCats);
      {
        const detailItem1 = find(newLine, ".detail-item-1");
        html(detailItem1, strRecentCats);
        addClass(detailItem1, "icon-clock");
      }
      hide(find(newLine, ".type-id"));
      break;
    case "recent_pics":
      html(find(newLine, ".type-name"), strRecentPics);
      {
        const detailItem1 = find(newLine, ".detail-item-1");
        html(detailItem1, strRecentPics);
        addClass(detailItem1, "icon-clock");
      }
      hide(find(newLine, ".type-id"));
      break;
    case "categories": {
      html(find(newLine, ".type-name"), line.categoryName ?? "");
      const detailItem1 = find(newLine, ".detail-item-1");
      html(detailItem1, line.categoryName ?? "");
      addClass(detailItem1, "icon-folder-open tiptip");
      if (line.categoryPath === null) {
        removeAttr(detailItem1, "title");
      } else {
        attr(detailItem1, "title", line.categoryPath);
      }
      if (line.imageThumbnailUrl === null) {
        hide(find(newLine, ".type-id"));
      }
      break;
    }
    case "memories-1-year-ago":
      html(find(newLine, ".type-name"), strMemories);
      {
        const detailItem1 = find(newLine, ".detail-item-1");
        html(detailItem1, strMemories);
        addClass(detailItem1, "icon-clock");
      }
      break;
    case "contact":
      addClass(
        find(newLine, ".type-icon i"),
        "line-icon icon-mail-1 icon-yellow",
      );
      html(find(newLine, ".type-name"), strContactForm);
      html(find(newLine, ".detail-item-1"), strContactForm);
      hide(find(newLine, ".type-id"));
      break;
    default:
      addClass(
        find(newLine, ".type-icon i"),
        "line-icon icon-help-puzzle icon-grey",
      );
      html(find(newLine, ".type-name"), line.section ?? "");
      hide(find(newLine, ".type-id"));
      break;
  }

  if (line.imageThumbnailUrl !== null) {
    const img = document.createElement("img");
    attr(img, "src", line.imageThumbnailUrl);
    attr(img, "alt", line.imageLabel ?? "");
    attr(img, "title", line.imageLabel ?? "");

    html(find(newLine, ".type-name"), line.imageLabel ?? "");
    const typeIcon = find(newLine, ".type-icon");
    empty(typeIcon);
    typeIcon[0]?.appendChild(img);
    html(find(newLine, ".type-id"), "#" + String(line.imageId));
    if (line.imageEditUrl === null) {
      removeAttr(typeIcon, "href");
    } else {
      attr(typeIcon, "href", line.imageEditUrl);
    }
    removeClass(typeIcon, "no-img");
    attr(find(newLine, ".type-icon img"), "title", strEditImg);
    addClass(find(newLine, ".type-icon img"), "tiptip");
    show(find(newLine, ".type-id"));
  } else {
    removeClass(
      find(newLine, ".type-icon .icon-file-image"),
      "icon-file-image",
    );
    hide(find(newLine, ".toggle-img-option"));

    if (sections.includes(line.section ?? "")) {
      const lineIconClass = icons[sections.indexOf(line.section ?? "")]!;
      addClass(find(newLine, ".type-icon i"), lineIconClass);
    } else {
      console.warn("Unhandled section : " + String(line.section));
    }
  }

  removeClass(find(newLine, ".detail-item-1"), "hide");
  if (line.imageType === "high") {
    const detailItem1 = find(newLine, ".detail-item-1");
    html(detailItem1, strDwld);
    addClass(detailItem1, "icon-blue");
    removeClass(detailItem1, "detail-item-1");
    removeClass(detailItem1, "hide");
    addClass(find(newLine, ".date-dwld-icon"), "icon-blue icon-floppy");
  } else {
    remove(find(newLine, ".date-dwld-icon"));
  }
  displayLine(newLine);
}

function displayLine(line: Element) {
  document.querySelector(".tab")?.appendChild(line);
}

function addUserFilter(username: string | null) {
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- cloning an Element always produces an Element (real DOM guarantee); cloneNode()'s own lib.dom signature just isn't narrowed per-subtype.
  const newFilter = document
    .getElementById("default-filter")!
    .cloneNode(true) as Element;
  removeClass(newFilter, "hide");

  html(find(newFilter, ".filter-title"), username ?? "");
  addClass(find(newFilter, ".filter-icon"), "icon-user");

  on(find(newFilter, ".remove-filter"), "click", function (this: Element) {
    this.parentElement?.remove();

    currentParam.user_id = -1;
    currentParam.pageNumber = 0;
    void fillHistoryResult(currentParam);
    checkFilters();
    show(document.querySelectorAll(".summary-guests"));
  });

  hide(document.querySelectorAll(".summary-guests"));
  document.querySelector(".filter-container")?.appendChild(newFilter);
  checkFilters();
}

function addGuestFilter(username: string) {
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- cloning an Element always produces an Element (real DOM guarantee); cloneNode()'s own lib.dom signature just isn't narrowed per-subtype.
  const newFilter = document
    .getElementById("default-filter")!
    .cloneNode(true) as Element;
  removeClass(newFilter, "hide");

  html(find(newFilter, ".filter-title"), username);
  addClass(find(newFilter, ".filter-icon"), "icon-user-secret");

  on(find(newFilter, ".remove-filter"), "click", function (this: Element) {
    this.parentElement?.remove();

    currentParam.user_id = -1;
    currentParam.pageNumber = 0;
    void fillHistoryResult(currentParam);
    checkFilters();
  });

  document.querySelector(".filter-container")?.appendChild(newFilter);
  checkFilters();
}

function addIpFilter(ip: string) {
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- cloning an Element always produces an Element (real DOM guarantee); cloneNode()'s own lib.dom signature just isn't narrowed per-subtype.
  const newFilter = document
    .getElementById("default-filter")!
    .cloneNode(true) as Element;
  removeClass(newFilter, "hide");

  html(find(newFilter, ".filter-title"), ip);
  html(find(newFilter, ".filter-icon"), "IP ");
  addClass(find(newFilter, ".filter-icon"), "bold");

  on(find(newFilter, ".remove-filter"), "click", function (this: Element) {
    this.parentElement?.remove();

    currentParam.ip = "";
    currentParam.pageNumber = 0;
    void fillHistoryResult(currentParam);
    checkFilters();
  });

  document.querySelector(".filter-container")?.appendChild(newFilter);
  checkFilters();
}

function addImageFilter(img_id: string | number | null) {
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- cloning an Element always produces an Element (real DOM guarantee); cloneNode()'s own lib.dom signature just isn't narrowed per-subtype.
  const newFilter = document
    .getElementById("default-filter")!
    .cloneNode(true) as Element;
  removeClass(newFilter, "hide");

  html(find(newFilter, ".filter-title"), "Image #" + String(img_id));
  addClass(find(newFilter, ".filter-icon"), "icon-picture");

  on(find(newFilter, ".remove-filter"), "click", function (this: Element) {
    this.parentElement?.remove();

    currentParam.image_id = "";
    currentParam.pageNumber = 0;
    void fillHistoryResult(currentParam);
    checkFilters();
  });

  document.querySelector(".filter-container")?.appendChild(newFilter);
  checkFilters();
}

function updateArrows(actualPage: number, maxPage: number) {
  if (actualPage === 0) {
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

  if (actualPage === maxPage - 1) {
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

function updatePagination(maxPage: number) {
  updateArrows(currentParam.pageNumber, maxPage);

  empty(document.querySelectorAll(".pagination-item-container"));
  append(
    document.querySelectorAll(".pagination-item-container"),
    "<a class='actual'>" +
      String(currentParam.pageNumber + 1) +
      "/" +
      String(maxPage) +
      "</a>",
  );
}

function checkFilters() {
  if (
    document.querySelectorAll(".filter-container")[0]!.childElementCount - 1 >
    0
  ) {
    //Check if there are filters
    show(find(document.querySelectorAll(".filter-tags"), "label"));
  } else {
    hide(find(document.querySelectorAll(".filter-tags"), "label"));
  }
}

// GET /api/v1/geoip's own response shape -- the real replacement for
// jquery.geoip.js's client-side call to the long-dead freegeoip.net
// JSONP endpoint (docs/PLAN.md P49-B group 1's own finding). Same type
// ratings/user.ts's own copy of this call reads.
type GeoIpLookupResponse =
  operations["geoIpLookup"]["responses"][200]["content"]["application/json"];

/**
 * Real pre-existing bug found while wiring GET /api/v1/geoip up to a
 * real backend (docs/PLAN.md P49-B group 1): this used to be bound via
 * `document.querySelectorAll(".IP").forEach(...)` inside `ready()`,
 * querying a class that has never existed in this file's own rendered
 * markup -- `lineConstructor()` (below) has always used `.user-ip`. Every
 * row's `.IP` match count was 0 at `ready()` time regardless, since rows
 * are appended asynchronously afterwards, so the hover handler never
 * fired even once, independent of the freegeoip.net dead-endpoint issue
 * this same conversion also fixes. Fixed by binding per-row, from
 * lineConstructor() itself, on the one real `.user-ip` element that row
 * actually has -- not a page-wide re-scan on every search result (that
 * would re-register a listener on every already-bound older row too).
 */
function setupGeoIpHover(ipEl: Element): void {
  on(
    ipEl,
    "mouseenter",
    function (): void {
      setData(ipEl, "isOver", true);
      on(
        ipEl,
        "mouseleave",
        function (): void {
          removeData(ipEl, "isOver");
        },
        { once: true },
      );

      void (async () => {
        try {
          // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/utils/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
          const geoData = (await ajax({
            url: "api/v1/geoip",
            type: "GET",
            dataType: "json",
            data: { ip: textOf(ipEl) },
          })) as GeoIpLookupResponse;

          if (!geoData.available || geoData.fullName === undefined) return;

          let content = geoData.fullName;
          if (geoData.latitude != null && geoData.longitude != null) {
            content +=
              '\x3Cbr>\x3Ca class="ipGeoOpen" data-lat="' +
              String(geoData.latitude) +
              '" data-lon="' +
              String(geoData.longitude) +
              '"';
            content += ' href="#">show on a Google Map</a>';
          }

          tipTip(ipEl, {
            content: content,
            keepAlive: true,
            defaultPosition: "right",
            maxWidth: 320,
          });
          // tipTip's own hover binding (dom.ts's hover()) listens for real,
          // native "mouseenter" -- unlike jQuery's own synthetic version
          // (internally a "mouseover" listener translated via
          // relatedTarget), a real "mouseenter" reaches it directly.
          if (data(ipEl, "isOver") === true) trigger(ipEl, "mouseenter");
        } catch (e) {
          console.error(e instanceof AjaxError ? e.responseText : e);
        }
      })();
    },
    { once: true },
  );
}

ready(function () {
  delegate(
    document,
    "click",
    ".ipGeoOpen",
    function (this: Element, event: Event): void {
      const lat = data(this, "lat");
      const lon = data(this, "lon");
      const parent = this.parentElement;
      this.remove();

      let appendHtml =
        '\x3Cbr>\x3Cimg width=300 height=220 src="http://maps.googleapis.com/maps/api/staticmap';
      appendHtml +=
        "?sensor=false&size=300x220&zoom=6&markers=size:tiny%7C" +
        String(lat) +
        "," +
        String(lon) +
        '">';

      if (parent !== null) {
        append(parent, appendHtml);
      }

      // `return false` from a jQuery handler.
      event.preventDefault();
      event.stopPropagation();
    },
  );
});
