import type { operations } from "../../../../openapi/client/schema";
import { sprintf } from "./common";

import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../default/js/page-data";
import { ajax } from "../../../default/js/vendor/ajax";
import { pwgDatepicker } from "../../../default/js/vendor/datepicker";
import {
  addClass,
  append,
  attr,
  attrOf,
  css,
  data,
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
} from "../../../default/js/vendor/dom";
import { tipTip } from "../../../default/js/vendor/tiptip";
export {};

type HistorySearchResponse =
  operations["historySearch"]["responses"][200]["content"]["application/json"];
type HistoryLine = HistorySearchResponse["lines"][number];
type HistorySummary = HistorySearchResponse["summary"];
type HistorySearchDetails = NonNullable<HistoryLine["searchDetails"]>;

interface HistoryFilterParams {
  start: string;
  end: string;
  types: Record<number, string>;
  user_id: string | number;
  image_id: string | number | null;
  filename: string;
  ip: string;
  pageNumber: number;
}

const dateObj = new Date();
let month: number | string = dateObj.getUTCMonth() + 1; //months from 1-12
let day: number | string = dateObj.getUTCDate();
const year = dateObj.getUTCFullYear();

const filter_user_name = pwg_getPageData<string>("user_name");

if (month < 10) month = "0" + month;
if (day < 10) day = "0" + day;

const today = year + "-" + month + "-" + day;
const current_param: HistoryFilterParams = {
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

const str_dwld = pwg_getPageString("Downloaded");
const str_most_visited = pwg_getPageString("Most visited");
const str_best_rated = pwg_getPageString("Best rated");
const str_list = pwg_getPageString("Random photo");
const str_favorites = pwg_getPageString("Your favorites");
const str_recent_cats = pwg_getPageString("Recent albums");
const str_recent_pics = pwg_getPageString("Recent photos");
const str_memories = pwg_getPageString("Memories");
const str_no_longer_exist_photo = pwg_getPageString(
  "This photo no longer exists",
);
const str_tags = pwg_getPageString("Tags");
const unit_MB = pwg_getPageString("%s MB");
const str_guest = pwg_getPageString("guest");
const str_contact_form = pwg_getPageString("Contact Form");
const str_edit_img = pwg_getPageString("Edit photo");

const str_search_details: Record<string, string> = {
  allwords: pwg_getPageString("Search for words"),
  datePosted: pwg_getPageString("Post date"),
  tags: str_tags,
  cat: pwg_getPageString("Album"),
  author: pwg_getPageString("Author"),
  addedBy: pwg_getPageString("Added by"),
  filetypes: pwg_getPageString("File type"),
};
const str_and_more = pwg_getPageString("and %d more");

const guest_id = pwg_getPageData<number>("guest_id");

/** `$("<div>").html(markup).text().trim()` -- strips tags down to plain text. */
function stripHtml(markup: string): string {
  const tempDiv = document.createElement("div");
  tempDiv.innerHTML = markup;
  return (tempDiv.textContent ?? "").trim();
}

ready(() => {
  activateLineOptions();
  checkFilters();

  if (current_param.ip != "") {
    addIpFilter(current_param.ip);
  }
  if (current_param.image_id != "") {
    addImageFilter(current_param.image_id);
  }
  if (current_param.user_id != "-1") {
    addUserFilter(filter_user_name);
  }

  on(document.querySelectorAll(".elem-type-select"), "change", function () {
    const selectedType = attrOf(
      document.querySelectorAll(".elem-type-select option:checked"),
      "value",
    );
    console.log(selectedType);

    if (selectedType == "visited") {
      current_param.types = {
        0: "none",
        1: "picture",
      };
    } else if (selectedType == "downloaded") {
      current_param.types = {
        0: "high",
        1: "other",
      };
    } else {
      current_param.types = {
        0: "none",
        1: "picture",
        2: "high",
        3: "other",
      };
    }

    fillHistoryResult(current_param);
  });

  // `vendor/datepicker.ts`'s own native port dispatches a real native
  // "change" event (bubbling) on the visible field on init/selection --
  // matching the original's own real `this.$input.trigger("change")`
  // -- so a native listener on the `.date-start`/`.date-end` ancestor
  // sees it, including the one fired on page load.
  on(document.querySelectorAll(".date-start"), "change", function () {
    const value = attrOf(
      document.querySelectorAll('.date-start input[name="start"]'),
      "value",
    );
    if (current_param.start != value) {
      current_param.start = value ?? "";
      current_param.pageNumber = 0;
      fillHistoryResult(current_param);
    }
  });

  on(document.querySelectorAll(".date-end"), "change", function () {
    const newValue = attrOf(
      document.querySelectorAll('.date-end input[name="end"]'),
      "value",
    );
    if (current_param.end != newValue) {
      current_param.end = newValue ?? "";
      current_param.pageNumber = 0;
      // The datepicker first fills the end-date with '1899-12-31',
      // which triggers an unnecessary ajax request
      // when you come to the history search page from a photo.
      if (newValue !== "1899-12-31") {
        fillHistoryResult(current_param);
      }
    }
  });

  on(document.querySelectorAll("#start_unset"), "click", function () {
    console.log("here" + current_param.start);
    // Genuine pre-existing bug found only by strict typechecking:
    // `!current_param.start == ""` compares a boolean to a string,
    // always false -- this guard never actually ran, so the "unset
    // start date" button silently did nothing. Real intent (matching
    // `.date-start`'s own change handler above): only act when a
    // start filter is actually set.
    if (current_param.start != "") {
      current_param.pageNumber = 0;
      current_param.start = "";
      fillHistoryResult(current_param);
    }
  });

  on(document.querySelectorAll("#end_unset"), "click", function () {
    // Same class of bug as #start_unset above, plus a copy-paste typo:
    // compared `current_param.start` (always false either way) instead
    // of `current_param.end`, the field this handler actually resets
    // -- `today` is `.end`'s own "unset" sentinel, matching how
    // `#start_unset` above uses `""` for `.start`.
    if (current_param.end != today) {
      current_param.end = today;
      current_param.pageNumber = 0;
      fillHistoryResult(current_param);
    }
  });

  on(document.querySelectorAll(".pagination-arrow.rigth"), "click", () => {
    current_param.pageNumber += 1;
    fillHistoryResult(current_param);
  });

  on(document.querySelectorAll(".pagination-arrow.left"), "click", () => {
    current_param.pageNumber -= 1;
    fillHistoryResult(current_param);
  });

  on(document.querySelectorAll(".refresh-results"), "click", function () {
    fillHistoryResult(current_param);
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
    function (event: Event) {
      const el = event.currentTarget as Element;
      // jQuery's own `.toggle()` display-memory semantics apply here too --
      // use dom.ts's `toggle()` rather than a hand-rolled inline-style
      // check (see comments.ts's own finding for why the naive version is
      // wrong the moment the element starts hidden via a CSS class rule).
      toggle(find(el, ".img-option"));
    },
  );

  /* Hide img options and rename field on click on the screen */

  on(document, "mouseup", function (e: Event) {
    e.stopPropagation();
    let option_is_clicked = false;
    document.querySelectorAll(".img-option span").forEach((span) => {
      if (span !== e.target && span.contains(e.target as Node | null)) {
        option_is_clicked = true;
      }
    });
    if (!option_is_clicked) {
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
    unit_MB.replace("%s", String(summary.filesizeMb)),
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
      if (current_param.user_id == "-1") {
        current_param.user_id = guest_id;
        addGuestFilter(str_guest);
        fillHistoryResult(current_param);
      }
    });
    // `.hover(fn)` with a single argument binds the same handler to both
    // mouseenter and mouseleave.
    on(summaryGuestsData, "mouseenter mouseleave", function (event: Event) {
      css(event.currentTarget as Element, "cursor", "pointer");
    });

    show(document.querySelectorAll(".summary-guests"));
  } else {
    hide(document.querySelectorAll(".summary-guests"));
  }

  const user_dot_title = summary.members
    .map((member) => member.username)
    .join(", ");
  attr(document.querySelectorAll(".user-dot"), "title", user_dot_title);
  addClass(document.querySelectorAll(".user-dot"), "tiptip");

  let tmp = 0;
  hide(document.querySelectorAll(".user-dot"));
  // summary.members is already ordered most-active-first
  summary.members.forEach((member) => {
    if (tmp < 5) {
      const new_user_item = document
        .getElementById("-2")!
        .cloneNode(true) as Element;

      removeClass(new_user_item, "hide");
      html(find(new_user_item, ".user-item-name"), member.username ?? "");
      setData(new_user_item, "user-id", member.userId);

      on(new_user_item, "click", function (event: Event) {
        const el = event.currentTarget as Element;
        if (current_param.user_id != member.userId) {
          current_param.user_id = data(el, "user-id") as string | number;
          addUserFilter(member.username);
          fillHistoryResult(current_param);
        }
      });
      document.querySelector(".user-list")?.appendChild(new_user_item);
      tmp++;
    } else {
      show(document.querySelectorAll(".user-dot"));
    }
  });
}

function showResults(doShow: boolean) {
  console.log("EMPTY");
  if (doShow) {
    show(document.querySelectorAll(".search-summary"));
    show(document.querySelectorAll(".container"));
  } else {
    hide(document.querySelectorAll(".search-summary"));
    hide(document.querySelectorAll(".container"));
  }
}

function fillHistoryResult(ajaxParam: HistoryFilterParams) {
  let maxPage = 0;
  void ajax({
    url: "api/v1/history/search",
    data: ajaxParam,
    beforeSend: function () {
      showResults(false);
      removeClass(document.querySelectorAll(".loading"), "hide");
      hide(document.querySelectorAll(".noResults"));
      empty(document.querySelectorAll(".tab"));
    },
    success: function (raw_data: HistorySearchResponse) {
      const data = raw_data.lines;
      maxPage = raw_data.maxPage;
      const summary = raw_data.summary;

      //clear lines before refill

      if (data.length > 0) {
        let id = 0;
        data.forEach((line) => {
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
    },
    error: function (e) {
      console.log(e);
    },
  }).done(() => {
    activateLineOptions();
    addClass(document.querySelectorAll(".loading"), "hide");
    updatePagination(maxPage);
    tipTip(document.querySelectorAll(".tiptip"), {
      delay: 0,
      fadeIn: 200,
      fadeOut: 200,
      edgeOffset: 3,
    });
  });
}

function lineConstructor(line: HistoryLine, id: number) {
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

  /* console log to help debug */
  // console.log(line);
  attr(newLine, "id", String(id));
  // console.log(id);

  html(find(newLine, ".date-day"), line.dateFormatted ?? "");
  html(find(newLine, ".date-hour"), line.time);

  html(
    find(newLine, ".user-name"),
    line.username + '<i class="add-filter icon-plus-circled"></i>',
  );

  attr(find(newLine, ".user-name"), "id", String(line.userId));
  if (current_param.user_id == "-1") {
    on(find(newLine, ".user-name"), "click", function (event: Event) {
      const el = event.currentTarget as Element;
      current_param.user_id = String(attrOf(el, "id"));
      current_param.pageNumber = 0;
      addUserFilter(htmlOf(el) ?? "");
      fillHistoryResult(current_param);
    });
  }

  html(
    find(newLine, ".user-ip"),
    line.ip + '<i class="add-filter icon-plus-circled"></i>',
  );
  setData(find(newLine, ".user-ip")[0]!, "ip", line.ip);
  setupGeoIpHover(find(newLine, ".user-ip")[0]!);
  if (current_param.ip == "") {
    on(find(newLine, ".user-ip"), "click", function (event: Event) {
      const el = event.currentTarget as Element;
      current_param.ip = (data(el, "ip") as string | undefined) ?? "";
      current_param.pageNumber = 0;
      addIpFilter(htmlOf(el) ?? "");
      fillHistoryResult(current_param);
    });
  }

  setData(find(newLine, ".add-img-as-filter")[0]!, "img-id", line.imageId);
  if (current_param.image_id == "") {
    on(find(newLine, ".add-img-as-filter"), "click", function (event: Event) {
      const el = event.currentTarget as Element;
      const imgId = data(el, "img-id") as string | number | null;
      current_param.image_id = imgId;
      current_param.pageNumber = 0;
      addImageFilter(imgId);
      fillHistoryResult(current_param);
    });
  }

  if (line.imageEditUrl) {
    attr(find(newLine, ".edit-img"), "href", line.imageEditUrl);
  } else {
    const editImg = find(newLine, ".edit-img");
    attr(editImg, "href", "#");
    addClass(editImg, "notClickable tiptip");
    attr(editImg, "title", str_no_longer_exist_photo);
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
          line.tagNames[0] + ", " + line.tagNames[1] + ", ...",
        );
        html(
          find(newLine, ".type-id"),
          "#" + line.tagIds[0] + ", " + line.tagIds[1] + ", ...",
        );
      } else if (line.tagNames.length > 2) {
        html(
          find(newLine, ".type-name"),
          line.tagNames[0] +
            ", " +
            line.tagNames[1] +
            ", " +
            line.tagNames[2] +
            ", ...",
        );
        html(
          find(newLine, ".type-id"),
          "#" +
            line.tagIds[0] +
            ", " +
            line.tagIds[1] +
            ", " +
            line.tagIds[2] +
            ", ...",
        );
      } else {
        html(find(newLine, ".type-name"), line.tagNames[0] ?? "");
        html(find(newLine, ".type-id"), "#" + line.tagIds[0]);
      }

      let detail_str = "";
      line.tagNames.forEach((tag) => {
        detail_str += tag + ", ";
      });
      detail_str = detail_str.slice(0, -2);
      const detailItem1 = find(newLine, ".detail-item-1");
      html(detailItem1, detail_str);
      attr(detailItem1, "title", detail_str);
      removeClass(detailItem1, "hide");
      addClass(detailItem1, "icon-tags");
      break;
    }

    case "most_visited":
      html(find(newLine, ".type-name"), str_most_visited);
      {
        const detailItem1 = find(newLine, ".detail-item-1");
        html(detailItem1, str_most_visited);
        addClass(detailItem1, "icon-fire");
      }
      hide(find(newLine, ".type-id"));
      break;
    case "best_rated":
      html(find(newLine, ".type-name"), str_best_rated);
      {
        const detailItem1 = find(newLine, ".detail-item-1");
        html(detailItem1, str_best_rated);
        addClass(detailItem1, "icon-star");
      }
      hide(find(newLine, ".type-id"));
      break;
    case "list":
      html(find(newLine, ".type-name"), str_list);
      {
        const detailItem1 = find(newLine, ".detail-item-1");
        html(detailItem1, str_list);
        addClass(detailItem1, "icon-dice-solid");
      }
      hide(find(newLine, ".type-id"));
      break;
    case "search": {
      // for debug
      // console.log('search n° : ', line.searchId, ' ', line.searchDetails);
      const search_details = line.searchDetails;
      // Genuinely heterogeneous per-filter-type search-criteria data
      // (same nature as search_filters.ts's own global_params/
      // fullname_of_cat, deferred to P48) -- each key's real value
      // shape (string[], a nested object, or an opaque `filetypes`
      // blob) varies by which filter was active on the saved search
      // this line's `searchId` refers to.
      const search_icons: Record<string, any> = {
        allwords: "gallery-icon-search",
        tags: "gallery-icon-tag",
        datePosted: "gallery-icon-calendar-plus",
        cat: "gallery-icon-album",
        author: "gallery-icon-user-edit",
        addedBy: "gallery-icon-user",
        filetypes: "gallery-icon-file-image",
      };
      html(find(newLine, ".type-name"), line.section ?? "");
      html(find(newLine, ".type-id"), "#" + line.searchId);
      if (!line.searchId) {
        hide(find(newLine, ".type-id"));
      }

      if (!search_details) {
        hide(find(newLine, ".detail-item-1"));
        break;
      }
      const active_search_details: Record<string, any> = {};
      Object.keys(search_details).forEach((key) => {
        const value = search_details[key as keyof HistorySearchDetails];
        if (value !== null) {
          active_search_details[key] = value;
        }
      });
      let count_item = 1;
      const active_more: any[] = [];
      const active_items = Object.keys(active_search_details);
      if (active_items.length > 0) {
        if (active_search_details.allwords) {
          const item = find(newLine, ".detail-item-" + count_item);
          html(item, active_search_details.allwords.join(" "));
          addClass(item, search_icons.allwords + " tiptip");
          attr(
            item,
            "title",
            "<b>" +
              str_search_details["allwords"] +
              " :</b> " +
              active_search_details.allwords.join(" "),
          );
          count_item++;
          active_more.push("allwords");
        }
        if (active_search_details.cat) {
          const array_cat = Object.values(active_search_details.cat);
          const cat = array_cat.join(" + ");
          const text = stripHtml(cat);
          const item = find(newLine, ".detail-item-" + count_item);
          html(item, cat);
          addClass(item, search_icons.cat + " tiptip");
          attr(
            item,
            "title",
            "<b>" + str_search_details["cat"] + " :</b> " + text,
          );
          removeClass(item, "hide");
          count_item++;
          active_more.push("cat");
        }
        if (count_item <= 2 && active_search_details.tags) {
          const array_tags = Object.values(active_search_details.tags);
          const item = find(newLine, ".detail-item-" + count_item);
          html(item, array_tags.join(" + "));
          addClass(item, search_icons.tags + " tiptip");
          attr(
            item,
            "title",
            "<b>" +
              str_search_details["tags"] +
              " :</b> " +
              array_tags.join(" + "),
          );
          removeClass(item, "hide");
          count_item++;
          active_more.push("tags");
        }
        if (count_item <= 2) {
          const badge_to_add =
            active_items.length == 1 ? 1 : count_item == 1 ? 2 : 1;
          let badge_added = 0;
          active_items.some((key) => {
            if (key !== "allwords" && key !== "cat" && key !== "tags") {
              // Genuinely heterogeneous per-key value shape, same as
              // active_search_details/search_icons above.
              let array_key: any;
              if (Array.isArray(active_search_details[key])) {
                array_key = active_search_details[key];
              } else if (typeof active_search_details[key] === "object") {
                array_key = Object.values(active_search_details[key]);
              } else {
                array_key = [active_search_details[key]];
              }
              const item = find(newLine, ".detail-item-" + count_item);
              html(item, array_key.join(" + "));
              addClass(item, search_icons[key] + " tiptip");
              attr(
                item,
                "title",
                "<b>" +
                  str_search_details[key] +
                  " :</b> " +
                  array_key.join(" + "),
              );
              removeClass(item, "hide");
              count_item++;
              badge_added++;
              active_more.push(key);
              if (badge_added === badge_to_add) {
                return true;
              }
            }
            return false;
          });
        }
      } else {
        hide(find(newLine, ".detail-item-1"));
      }
      if (active_items.length >= 3) {
        let count_more = 0;
        const search_details_str = Object.entries(active_search_details)
          .filter(([key]) => !active_more.includes(key))
          .map(([key, value]: [string, any]) => {
            let value_str;
            if (Array.isArray(value)) {
              value_str = value.join(" + ");
            } else if (typeof value === "object") {
              value_str = Object.entries(value)
                .map(([, v]: [string, any]) => v)
                .join(" + ");
            } else {
              value_str = value;
            }

            if (key == "cat") {
              value_str = stripHtml(value_str);
            }
            count_more++;
            return `<b>${str_search_details[key]}</b> : ${value_str}`;
          })
          .join(" <br />");
        const item3 = find(newLine, ".detail-item-3");
        html(item3, sprintf(str_and_more, count_more));
        addClass(item3, "icon-info-circled-1 tiptip");
        attr(item3, "title", search_details_str);
        removeClass(item3, "hide");
      }
      break;
    }
    case "favorites":
      html(find(newLine, ".type-name"), str_favorites);
      {
        const detailItem1 = find(newLine, ".detail-item-1");
        html(detailItem1, str_favorites);
        addClass(detailItem1, "icon-heart");
      }
      hide(find(newLine, ".type-id"));
      break;
    case "recent_cats":
      html(find(newLine, ".type-name"), str_recent_cats);
      {
        const detailItem1 = find(newLine, ".detail-item-1");
        html(detailItem1, str_recent_cats);
        addClass(detailItem1, "icon-clock");
      }
      hide(find(newLine, ".type-id"));
      break;
    case "recent_pics":
      html(find(newLine, ".type-name"), str_recent_pics);
      {
        const detailItem1 = find(newLine, ".detail-item-1");
        html(detailItem1, str_recent_pics);
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
      if (!line.imageThumbnailUrl) {
        hide(find(newLine, ".type-id"));
      }
      break;
    }
    case "memories-1-year-ago":
      html(find(newLine, ".type-name"), str_memories);
      {
        const detailItem1 = find(newLine, ".detail-item-1");
        html(detailItem1, str_memories);
        addClass(detailItem1, "icon-clock");
      }
      break;
    case "contact":
      addClass(
        find(newLine, ".type-icon i"),
        "line-icon icon-mail-1 icon-yellow",
      );
      html(find(newLine, ".type-name"), str_contact_form);
      html(find(newLine, ".detail-item-1"), str_contact_form);
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

  if (line.imageThumbnailUrl) {
    const img = document.createElement("img");
    attr(img, "src", line.imageThumbnailUrl);
    attr(img, "alt", line.imageLabel || "");
    attr(img, "title", line.imageLabel || "");

    html(find(newLine, ".type-name"), line.imageLabel ?? "");
    const typeIcon = find(newLine, ".type-icon");
    empty(typeIcon);
    typeIcon[0]?.appendChild(img);
    html(find(newLine, ".type-id"), "#" + line.imageId);
    if (line.imageEditUrl === null) {
      removeAttr(typeIcon, "href");
    } else {
      attr(typeIcon, "href", line.imageEditUrl);
    }
    removeClass(typeIcon, "no-img");
    attr(find(newLine, ".type-icon img"), "title", str_edit_img);
    addClass(find(newLine, ".type-icon img"), "tiptip");
    show(find(newLine, ".type-id"));
  } else {
    removeClass(
      find(newLine, ".type-icon .icon-file-image"),
      "icon-file-image",
    );
    hide(find(newLine, ".toggle-img-option"));

    if (sections.indexOf(line.section ?? "") != -1) {
      const lineIconClass = icons[sections.indexOf(line.section ?? "")]!;
      addClass(find(newLine, ".type-icon i"), lineIconClass);
    } else {
      console.log("Unhandled section : " + line.section);
    }
  }

  removeClass(find(newLine, ".detail-item-1"), "hide");
  if (line.imageType == "high") {
    const detailItem1 = find(newLine, ".detail-item-1");
    html(detailItem1, str_dwld);
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
  const newFilter = document
    .getElementById("default-filter")!
    .cloneNode(true) as Element;
  removeClass(newFilter, "hide");

  html(find(newFilter, ".filter-title"), username ?? "");
  addClass(find(newFilter, ".filter-icon"), "icon-user");

  on(find(newFilter, ".remove-filter"), "click", function (event: Event) {
    (event.currentTarget as Element).parentElement?.remove();

    current_param.user_id = "-1";
    current_param.pageNumber = 0;
    fillHistoryResult(current_param);
    checkFilters();
    show(document.querySelectorAll(".summary-guests"));
  });

  hide(document.querySelectorAll(".summary-guests"));
  document.querySelector(".filter-container")?.appendChild(newFilter);
  checkFilters();
}

function addGuestFilter(username: string) {
  const newFilter = document
    .getElementById("default-filter")!
    .cloneNode(true) as Element;
  removeClass(newFilter, "hide");

  html(find(newFilter, ".filter-title"), username);
  addClass(find(newFilter, ".filter-icon"), "icon-user-secret");

  on(find(newFilter, ".remove-filter"), "click", function (event: Event) {
    (event.currentTarget as Element).parentElement?.remove();

    current_param.user_id = "-1";
    current_param.pageNumber = 0;
    fillHistoryResult(current_param);
    checkFilters();
  });

  document.querySelector(".filter-container")?.appendChild(newFilter);
  checkFilters();
}

function addIpFilter(ip: string) {
  const newFilter = document
    .getElementById("default-filter")!
    .cloneNode(true) as Element;
  removeClass(newFilter, "hide");

  html(find(newFilter, ".filter-title"), ip);
  html(find(newFilter, ".filter-icon"), "IP ");
  addClass(find(newFilter, ".filter-icon"), "bold");

  on(find(newFilter, ".remove-filter"), "click", function (event: Event) {
    (event.currentTarget as Element).parentElement?.remove();

    current_param.ip = "";
    current_param.pageNumber = 0;
    fillHistoryResult(current_param);
    checkFilters();
  });

  document.querySelector(".filter-container")?.appendChild(newFilter);
  checkFilters();
}

function addImageFilter(img_id: string | number | null) {
  const newFilter = document
    .getElementById("default-filter")!
    .cloneNode(true) as Element;
  removeClass(newFilter, "hide");

  html(find(newFilter, ".filter-title"), "Image #" + img_id);
  addClass(find(newFilter, ".filter-icon"), "icon-picture");

  on(find(newFilter, ".remove-filter"), "click", function (event: Event) {
    (event.currentTarget as Element).parentElement?.remove();

    current_param.image_id = "";
    current_param.pageNumber = 0;
    fillHistoryResult(current_param);
    checkFilters();
  });

  document.querySelector(".filter-container")?.appendChild(newFilter);
  checkFilters();
}

function updateArrows(actualPage: number, maxPage: number) {
  if (actualPage == 0) {
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

  if (actualPage == maxPage - 1) {
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
  updateArrows(current_param.pageNumber, maxPage);

  empty(document.querySelectorAll(".pagination-item-container"));
  append(
    document.querySelectorAll(".pagination-item-container"),
    "<a class='actual'>" +
      (current_param.pageNumber + 1) +
      "/" +
      maxPage +
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
// rating_user.ts's own copy of this call reads.
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

      void ajax({
        url: "api/v1/geoip",
        type: "GET",
        dataType: "json",
        data: { ip: textOf(ipEl) },
        success: function (geoData: GeoIpLookupResponse) {
          if (!geoData.available || geoData.fullName === undefined) return;

          let content = geoData.fullName;
          if (geoData.latitude != null && geoData.longitude != null) {
            content +=
              '\x3Cbr>\x3Ca class="ipGeoOpen" data-lat="' +
              geoData.latitude +
              '" data-lon="' +
              geoData.longitude +
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
          if (data(ipEl, "isOver")) trigger(ipEl, "mouseenter");
        },
      });
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
        lat +
        "," +
        lon +
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
