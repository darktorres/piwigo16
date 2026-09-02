import type { operations } from "../../../openapi/client/schema";
// Real consumer of album_selector.ts's own top-level `class
// AlbumSelector` (docs/PLAN.md P48 -- was a `window.AlbumSelector`
// read, see that file's own leading comment for the full real-consumer
// list).
import { AlbumSelector } from "../../admin/default/js/album_selector";
import { sprintf } from "../../admin/default/js/common";
// This file has exactly one real registrant page (SearchFiltersView),
// but doubleSlider.ts itself has 2 real file-level consumers (this
// file and batchManagerFilter.ts, each its own separate Vite entry),
// so Rollup emits it as a shared chunk.
import { pwgDoubleSlider } from "../../admin/default/js/doubleSlider";
// Real consumer of search_filters.ts's own exports (docs/PLAN.md P48,
// search_filters.ts's own batch -- was bare-global reads before that).
// This file is search_filters.ts's only real consumer
// anywhere (confirmed directly -- history.ts's own leading comment
// mentions `global_params`/`fullname_of_cat` only in prose, not a real
// bare-identifier read), so a plain import is safe (Design §4) --
// search_filters.ts's own former standalone entry/registration is gone
// too (SearchFiltersView's own leading comment).
//
// `global_params` is read-only here, and deliberately so. It used to
// carry a second, parallel copy of the filter state: every widget
// handler wrote the chosen values into `global_params.fields.*` right
// beside the `PS_params.*` write next to it. Nothing ever read that
// copy back -- every `global_params` read in this file runs in the
// setup pass below, before any handler can fire; `performSearch()`
// builds its request body out of `PS_params` alone; and a search
// reloads the page, so the next read starts from a fresh
// server-rendered value either way. Those writes are gone (P49-A,
// typing pass), which also removed several that had drifted to shapes
// the server never produces -- `added_by` as `{mode, words}` where
// `SearchRules` says `list<int|string>`, `expert` as a bare string
// where it is `{string: ...}`, `allwords.words` as the raw input text
// where it is a word list. Add a read before adding a write back.
import {
  global_params,
  fullname_of_cat,
  search_id,
  str_word_widget_label,
  str_tags_widget_label,
  str_album_widget_label,
  str_author_widget_label,
  str_added_by_widget_label,
  str_filetypes_widget_label,
  str_rating_widget_label,
  str_no_rating,
  str_between_rating,
  str_filesize_widget_label,
  str_width_widget_label,
  str_height_widget_label,
  str_ratio_widget_label,
  str_ratios_label,
  str_expert_widget_label,
  str_empty_search_top_alt,
  str_empty_search_bot_alt,
  str_search_in_ab,
  prefix_icon,
  sliders,
  show_filter_ratings,
} from "./search_filters";
import { ajax } from "./vendor/ajax";
import {
  getSelectizeInstance,
  selectize as createSelectize,
} from "./vendor/selectize";
import {
  addClass,
  append,
  attr,
  attrOf,
  children,
  css,
  data,
  empty,
  escapeId,
  fadeIn,
  fadeOut,
  find,
  hasClass,
  hide,
  html,
  innerWidth,
  is,
  isVisible,
  offset,
  on,
  prepend,
  ready,
  removeAttr,
  removeClass,
  setChecked,
  setDisabled,
  setVal,
  show,
  text,
  textOf,
  toggle,
  trigger,
  val,
  windowWidth,
} from "./vendor/dom";

export {};

let PS_params: Record<string, any> = {};

function siblingsOf(el: Element): Element[] {
  const parent = el.parentElement;
  if (parent === null) return [];
  return Array.from(parent.children).filter((child) => child !== el);
}

/**
 * The 14 filter widgets below (word, tag, date_posted, date_created,
 * album, authors, added_by, filetypes, ratios, ratings, filesize,
 * height, width, expert) all share this early-return check inside their
 * click handler: ignore a click landing inside any open `.filter-form`,
 * or on the trigger's own excluded chrome (a "remove"/"remove-item"
 * icon). `closest(".filter-form")` matching the target itself covers
 * what the original wrote as a separate `hasClass("filter-form")` check.
 */
/**
 * jQuery/Sizzle's `:input` pseudo-selector (input/select/textarea/button)
 * has no native equivalent -- `querySelectorAll` throws a SyntaxError on
 * it. `qualifier` (e.g. ":checked", ":not(:checked)") is appended to each
 * tag so a compound like `:input:checked` expands correctly.
 */
function inputSelector(qualifier = ""): string {
  return ["input", "select", "textarea", "button"]
    .map((tag) => tag + qualifier)
    .join(", ");
}

function shouldIgnoreFilterClick(
  target: EventTarget | null,
  ...extraClasses: string[]
): boolean {
  const el = target as Element | null;
  if (el?.closest(".filter-form") != null) {
    return true;
  }
  return extraClasses.some((cls) => el?.classList.contains(cls) === true);
}

ready(function () {
  let ab: AlbumSelectorInstance;
  // Genuinely heterogeneous -- pushed values are `PS_params[key]`
  // (`PS_params: Record<string, any>`, this campaign's own
  // "Record<string, any> only where genuinely heterogeneous"
  // allowance), whose real shape varies per filter (string, array, or
  // number depending on which filter pushed it).
  const empty_filters_list: any[] = [];
  // Confirmed via grep: never pushed to anywhere in this file, so
  // `filters_to_remove.length > 0` (below) is always false and
  // `performSearch(PS_params, true)` there never actually runs -- a
  // real, pre-existing dead branch, not something this typing pass
  // fixes (deciding what should populate it is a design question, not
  // a type gap). Typed to its most plausible intended shape (filter
  // name strings, matching `updateFilters()`'s own `filterName` param)
  // rather than left as `any[]`.
  const filters_to_remove: string[] = [];

  addClass(
    document.querySelectorAll(".linkedAlbumPopInContainer .ClosePopIn"),
    prefix_icon + "cancel",
  );
  addClass(
    document.querySelectorAll(".linkedAlbumPopInContainer .searching"),
    prefix_icon + "spin6",
  );
  hide(document.querySelectorAll(".linkedAlbumPopInContainer .searching"));
  css(document.querySelectorAll(".AddIconContainer"), "display", "none");
  on(
    document.querySelectorAll(".filter-validate"),
    "click",
    function (this: Element) {
      css(find(this, ".loading"), "display", "block");
      hide(find(this, ".validate-text"));
    },
  );

  // If we open another filter, hide all other dropdowns expect the one just opened
  on(
    document.querySelectorAll("div.filter"),
    "click",
    function (this: Element) {
      removeClass(siblingsOf(this), "show-filter-dropdown");
      css(children(siblingsOf(this), "div.filter-form"), "display", "none");
    },
  );

  // If we open the choose filters modal hide all filter forms if any open
  on(document.querySelectorAll("div.filter-manager"), "click", function () {
    css(
      children(document.querySelectorAll("div.filter"), "div.filter-form"),
      "display",
      "none",
    );
  });

  // Declare params sent to pwg.images.filteredSearch.update
  // PS for performSearch()
  PS_params = {};
  PS_params.search_id = search_id;

  // Setup word filter
  const allwords_rule = global_params.fields.allwords;
  if (allwords_rule) {
    css(document.querySelectorAll(".filter-word"), "display", "flex");
    setChecked(
      document.querySelectorAll(".filter-manager-controller.word"),
      true,
    );

    let word_search_str = "";
    const word_search_words = allwords_rule.words;
    word_search_words.forEach((word) => {
      word_search_str += word + " ";
    });
    setVal(
      document.querySelectorAll("#word-search"),
      word_search_str.slice(0, -1),
    );

    if (word_search_words.length > 0) {
      addClass(document.querySelectorAll(".filter-word"), "filter-filled");
      html(
        document.querySelectorAll(".filter-word .search-words"),
        word_search_str.slice(0, -1),
      );
    } else {
      html(
        document.querySelectorAll(".filter-word .search-words"),
        str_word_widget_label,
      );
    }

    const word_search_fields = allwords_rule.fields;
    word_search_fields.forEach((field_name) => {
      setChecked(document.querySelectorAll("#" + field_name), true);
    });

    const word_search_mode = allwords_rule.mode;
    setChecked(
      document.querySelectorAll(
        `.word-search-options input[value="${word_search_mode}"]`,
      ),
      true,
    );

    on(
      document.querySelectorAll(".filter-word .filter-actions .clear"),
      "click",
      function () {
        setVal(document.querySelectorAll(".filter-word #word-search"), "");
        setChecked(
          document.querySelectorAll(".filter-word .search-params input"),
          true,
        );
        setChecked(
          document.querySelectorAll(
            ".filter-word .word-search-options input[value='AND']",
          ),
          true,
        );
      },
    );

    PS_params.allwords = word_search_str.slice(0, -1);
    PS_params.allwords_fields = word_search_fields;
    PS_params.allwords_mode = word_search_mode;

    empty_filters_list.push(PS_params.allwords);
  }

  //Hide filter spinner
  hide(document.querySelectorAll(".filter-spinner"));

  // Setup tag filter
  const tags_rule = global_params.fields.tags;
  document.querySelectorAll<HTMLSelectElement>("#tag-search").forEach((el) => {
    createSelectize(el, {
      plugins: ["remove_button"],
      maxOptions: el.querySelectorAll("option").length,
      items: tags_rule?.words,
    });
  });

  if (tags_rule) {
    css(document.querySelectorAll(".filter-tag"), "display", "flex");
    setChecked(
      document.querySelectorAll(".filter-manager-controller.tags"),
      true,
    );
    setChecked(
      document.querySelectorAll(
        `.filter-tag-form .search-params input[value="${tags_rule.mode}"]`,
      ),
      true,
    );

    let tag_search_str = "";
    const tagSearchEl =
      document.querySelector<HTMLSelectElement>("#tag-search")!;
    const tagSearchSelectize = getSelectizeInstance(tagSearchEl)!;
    (tagSearchSelectize.getValue() as (string | number)[]).forEach((id) => {
      tag_search_str +=
        (tagSearchSelectize.getItem(id)?.textContent ?? "")
          .replace(/\(\d+ \w+\)×/, "")
          .trim() + ", ";
    });
    if (tags_rule.words.length > 0) {
      addClass(document.querySelectorAll(".filter-tag"), "filter-filled");
      text(
        document.querySelectorAll(".filter.filter-tag .search-words"),
        tag_search_str.slice(0, -2),
      );
    } else {
      text(
        document.querySelectorAll(".filter.filter-tag .search-words"),
        str_tags_widget_label,
      );
    }

    on(
      document.querySelectorAll(".filter-tag .filter-actions .clear"),
      "click",
      function () {
        getSelectizeInstance(tagSearchEl)?.clear();
        setChecked(
          document.querySelectorAll(
            ".filter-tag .search-params input[value='AND']",
          ),
          true,
        );
      },
    );

    PS_params.tags = tags_rule.words.length > 0 ? tags_rule.words : "";
    PS_params.tags_mode = tags_rule.mode;

    empty_filters_list.push(PS_params.tags);
  }

  // Setup Date post filter
  const date_posted_rule = global_params.fields.date_posted;
  if (date_posted_rule) {
    css(document.querySelectorAll(".filter-date_posted"), "display", "flex");
    setChecked(
      document.querySelectorAll(".filter-manager-controller.date_posted"),
      true,
    );

    if (date_posted_rule.preset != "") {
      // If filter is used and not empty check preset date option
      setChecked(
        document.querySelectorAll("#date_posted-" + date_posted_rule.preset),
        true,
      );
      // `label#" + date_posted_rule.preset` is an ID selector fragment,
      // and preset can be a bare digit-leading value too (e.g. "30d",
      // not just the "custom" sub-case below) -- same escapeId() fix as
      // that branch, same reason.
      let date_posted_str = textOf(
        document.querySelectorAll(
          `.date_posted-option label#${escapeId(date_posted_rule.preset)} .date-period`,
        ),
      );

      // if option is custom check custom dates
      if ("custom" == date_posted_rule.preset) {
        date_posted_str = "";
        const customArray = date_posted_rule.custom;

        // Was `$(customArray).each(function (index) { ... this.substring(1,
        // $(this).length) ... })`. Same iteration, same values: jQuery
        // wraps a real array into a set of its entries (so
        // `$(customArray).length` was the entry count), while `$(this)`
        // wrapped one boxed String into a set of its *characters* (so
        // `$(this).length` was that entry's own character count, making
        // the old call `substring(1, entry.length)` -- the entry minus
        // its one-character type prefix).
        customArray.forEach((customEntry, index) => {
          const customValue = customEntry.substring(1);

          const customInputs = document.querySelectorAll(
            "#date_posted_" + customValue,
          );
          setChecked(customInputs, true);
          addClass(customInputs, "selected");
          // `.siblings("label")` -- the sibling <label>, not a
          // descendant, so this isn't `find()`.
          customInputs.forEach((inputEl) => {
            const label = siblingsOf(inputEl).find(
              (sibling) => sibling.tagName === "LABEL",
            );
            if (label !== undefined) {
              show(find(label, ".checked-icon"));
            }
          });

          // `label#" + customValue` is an ID selector fragment, and
          // `customValue` is the bare year/month/day number the
          // template writes as that label's own `id` (unlike
          // `#date_posted_<value>` above, which is always prefixed).
          // Digit-leading, so it needs escapeId() -- unescaped this
          // throws a SyntaxError under native querySelectorAll the
          // first time a custom date filter is active, a real bug
          // Sizzle tolerated silently.
          date_posted_str += textOf(
            document.querySelectorAll(
              `.date_posted-option label#${escapeId(customValue)} .date-period`,
            ),
          );

          if (customArray.length > 1 && index != customArray.length - 1) {
            date_posted_str += ", ";
          }
        });

        document.querySelectorAll(".date_posted-option.year").forEach((el) => {
          updateDateFilters(`.custom_posted_date #${el.id}`);
        });
      }

      // change badge label if filter not empty
      addClass(
        document.querySelectorAll(".filter-date_posted"),
        "filter-filled",
      );
      text(
        document.querySelectorAll(".filter.filter-date_posted .search-words"),
        date_posted_str,
      );
    }

    on(
      document.querySelectorAll(".filter-date_posted .filter-actions .clear"),
      "click",
      function () {
        updateFilters("date_posted", "add");
        setChecked(
          document.querySelectorAll(".date_posted-option input"),
          false,
        );
        trigger(
          document.querySelectorAll(".date_posted-option input"),
          "change",
        );
      },
    );

    // Disable possiblity for user to select custom option, its gets selected programtically later on
    attr(
      document.querySelectorAll("#date_posted_custom"),
      "disabled",
      "disabled",
    );

    // Handle toggle between preset and custom options
    on(
      document.querySelectorAll(".custom_posted_date_toggle"),
      "click",
      function () {
        toggle(document.querySelectorAll(".custom_posted_date"));
        toggle(document.querySelectorAll(".preset_posted_date"));
      },
    );

    // Handle accoridan features in custom options
    on(
      document.querySelectorAll(".custom_posted_date .accordion-toggle"),
      "click",
      function (this: Element) {
        const clickedOption = this.parentElement!;
        clickedOption.classList.toggle("show-child");
        if ("year" == data(this, "type")) {
          toggle(
            find(clickedOption.parentElement!, ".date_posted-option.month"),
          );
        } else if ("month" == data(this, "type")) {
          toggle(find(clickedOption.parentElement!, ".date_posted-option.day"));
        }
      },
    );

    // For debug
    // $('.date_posted-option-container').find(':input').show();

    // On custom date input select
    on(
      document.querySelectorAll(
        ".custom_posted_date .date_posted-option input",
      ),
      "change",
      function (this: Element) {
        const currentYear = data(this, "year");

        const selector = `.custom_posted_date #container_${String(currentYear)}`;
        updateDateFilters(selector);

        // Used to select custom in preset list if dates are selected
        if (
          document.querySelectorAll(".custom_posted_date input:checked")
            .length > 0
        ) {
          setChecked(document.querySelectorAll("#date_posted-custom"), true);
          attr(
            document.querySelectorAll(".preset_posted_date input"),
            "disabled",
            "disabled",
          );
        } else {
          setChecked(document.querySelectorAll("#date_posted-custom"), false);
          removeAttr(
            document.querySelectorAll(".preset_posted_date input"),
            "disabled",
          );
        }
      },
    );

    // Used to select custom in preset list if dates are selected
    if (
      document.querySelectorAll(".custom_posted_date input:checked").length > 0
    ) {
      setChecked(document.querySelectorAll("#date_posted-custom"), true);
      attr(
        document.querySelectorAll(".preset_posted_date input"),
        "disabled",
        "disabled",
      );
    } else {
      setChecked(document.querySelectorAll("#date_posted-custom"), false);
      removeAttr(
        document.querySelectorAll(".preset_posted_date input"),
        "disabled",
      );
    }

    PS_params.date_posted_preset = date_posted_rule.preset;
    // Was `custom != ""`, which compared the array against a string:
    // `[].toString()` is `""`, so it read as an emptiness test by way of
    // loose equality. `length > 0` states that directly. The one input
    // the two disagree on is `[""]`, which no producer can build (every
    // entry carries a one-character type prefix).
    PS_params.date_posted_custom =
      date_posted_rule.custom.length > 0 ? date_posted_rule.custom : "";

    empty_filters_list.push(PS_params.date_posted_preset);
    empty_filters_list.push(PS_params.date_posted_custom);
  }

  // Setup Date creation filter

  const date_created_rule = global_params.fields.date_created;
  if (date_created_rule) {
    css(document.querySelectorAll(".filter-date_created"), "display", "flex");
    setChecked(
      document.querySelectorAll(".filter-manager-controller.date_created"),
      true,
    );

    if (date_created_rule.preset != "") {
      // If filter is used and not empty check preset date option
      setChecked(
        document.querySelectorAll("#date_created-" + date_created_rule.preset),
        true,
      );
      // Same digit-leading-preset bug as the date_posted block above
      // (e.g. preset "30d"), same escapeId() fix.
      let date_created_str = textOf(
        document.querySelectorAll(
          `.date_created-option label#${escapeId(date_created_rule.preset)} .date-period`,
        ),
      );

      // if option is custom check custom dates
      if ("custom" == date_created_rule.preset) {
        date_created_str = "";
        const customArray = date_created_rule.custom;

        // Same jQuery-set rewrite as the date_posted block above.
        customArray.forEach((customEntry, index) => {
          const customValue = customEntry.substring(1);

          const customInputs = document.querySelectorAll(
            "#date_created_" + customValue,
          );
          setChecked(customInputs, true);
          addClass(customInputs, "selected");
          // `.siblings("label")` -- the sibling <label>, not a
          // descendant, so this isn't `find()`.
          customInputs.forEach((inputEl) => {
            const label = siblingsOf(inputEl).find(
              (sibling) => sibling.tagName === "LABEL",
            );
            if (label !== undefined) {
              show(find(label, ".checked-icon"));
            }
          });

          // Same digit-leading-ID bug as the date_posted block above,
          // same fix.
          date_created_str += textOf(
            document.querySelectorAll(
              `.date_created-option label#${escapeId(customValue)} .date-period`,
            ),
          );

          if (customArray.length > 1 && index != customArray.length - 1) {
            date_created_str += ", ";
          }
        });

        document.querySelectorAll(".date_created-option.year").forEach((el) => {
          updateDateFilters(`.custom_created_date #${el.id}`);
        });
      }

      // change badge label if filter not empty
      addClass(
        document.querySelectorAll(".filter-date_created"),
        "filter-filled",
      );
      text(
        document.querySelectorAll(".filter.filter-date_created .search-words"),
        date_created_str,
      );
    }

    on(
      document.querySelectorAll(".filter-date_created .filter-actions .clear"),
      "click",
      function () {
        updateFilters("date_created", "add");
        setChecked(
          document.querySelectorAll(".date_created-option input"),
          false,
        );
        trigger(
          document.querySelectorAll(".date_created-option input"),
          "change",
        );

        // $('.date_created-option input').removeAttr('disabled');
        // $('.date_created-option input').removeClass('grey-icon');
      },
    );

    // Disable possiblity for user to select custom option, its gets selected programtically later on
    attr(
      document.querySelectorAll("#date_created_custom"),
      "disabled",
      "disabled",
    );

    // Handle toggle between preset and custom options
    on(
      document.querySelectorAll(".custom_created_date_toggle"),
      "click",
      function () {
        toggle(document.querySelectorAll(".custom_created_date"));
        toggle(document.querySelectorAll(".preset_created_date"));
      },
    );

    // Handle accoridan features in custom options
    on(
      document.querySelectorAll(".custom_created_date .accordion-toggle"),
      "click",
      function (this: Element) {
        const clickedOption = this.parentElement!;
        clickedOption.classList.toggle("show-child");
        if ("year" == data(this, "type")) {
          toggle(
            find(clickedOption.parentElement!, ".date_created-option.month"),
          );
        } else if ("month" == data(this, "type")) {
          toggle(
            find(clickedOption.parentElement!, ".date_created-option.day"),
          );
        }
      },
    );

    // On custom date input select
    on(
      document.querySelectorAll(
        ".custom_created_date .date_created-option input",
      ),
      "change",
      function (this: Element) {
        const currentYear = data(this, "year");
        const selector = `.custom_created_date #container_${String(currentYear)}`;
        updateDateFilters(selector);

        // Used to select custom in preset list if dates are selected
        if (
          document.querySelectorAll(".custom_created_date input:checked")
            .length > 0
        ) {
          setChecked(document.querySelectorAll("#date_created-custom"), true);
          attr(
            document.querySelectorAll(".preset_created_date input"),
            "disabled",
            "disabled",
          );
        } else {
          setChecked(document.querySelectorAll("#date_created-custom"), false);
          removeAttr(
            document.querySelectorAll(".preset_created_date input"),
            "disabled",
          );
        }
      },
    );

    // Used to select custom in preset list if dates are selected
    if (
      document.querySelectorAll(".custom_created_date input:checked").length > 0
    ) {
      setChecked(document.querySelectorAll("#date_created-custom"), true);
      attr(
        document.querySelectorAll(".preset_created_date input"),
        "disabled",
        "disabled",
      );
    } else {
      setChecked(document.querySelectorAll("#date_created-custom"), false);
      removeAttr(
        document.querySelectorAll(".preset_created_date input"),
        "disabled",
      );
    }

    PS_params.date_created_preset = date_created_rule.preset;
    // Same `custom != ""` rewrite as the date_posted block above.
    PS_params.date_created_custom =
      date_created_rule.custom.length > 0 ? date_created_rule.custom : "";

    empty_filters_list.push(PS_params.date_created_preset);
    empty_filters_list.push(PS_params.date_created_custom);
  }

  // Setup album filter
  const cat_rule = global_params.fields.cat;
  if (cat_rule) {
    css(document.querySelectorAll(".filter-album"), "display", "flex");
    setChecked(
      document.querySelectorAll(".filter-manager-controller.album"),
      true,
    );

    let album_widget_value = "";
    cat_rule.words.forEach((cat_id) => {
      display_related_category(cat_id, fullname_of_cat[cat_id]);
      album_widget_value += fullname_of_cat[cat_id] + ", ";
    });

    // Load Album Selector
    ab = new AlbumSelector({
      selectedCategoriesIds: cat_rule.words,
      selectAlbum: add_related_category,
      removeSelectedAlbum: remove_related_category,
      modalTitle: str_search_in_ab,
    });

    on(document.querySelectorAll(".add-album-button"), "click", function () {
      ab.open();
    });

    on(
      document.querySelectorAll(".selected-categories-container"),
      "click",
      function (e: Event) {
        const target = e.target as Element;
        if (target.classList.contains("remove-item")) {
          ab.remove_selected_album(target.id);
        }
      },
    );

    if (cat_rule.words.length > 0) {
      addClass(document.querySelectorAll(".filter-album"), "filter-filled");
      html(
        document.querySelectorAll(".filter-album .search-words"),
        album_widget_value.slice(0, -2),
      );
    } else {
      html(
        document.querySelectorAll(".filter-album .search-words"),
        str_album_widget_label,
      );
    }

    if (cat_rule.sub_inc) {
      setChecked(document.querySelectorAll("#search-sub-cats"), true);
    }

    on(
      document.querySelectorAll(".filter-album .filter-actions .clear"),
      "click",
      function () {
        ab.resetAll();
        empty(document.querySelectorAll(".selected-categories-container"));
        setChecked(document.querySelectorAll("#search-sub-cats"), false);
      },
    );

    PS_params.categories = cat_rule.words.length > 0 ? cat_rule.words : "";
    PS_params.categories_withsubs = cat_rule.sub_inc;

    empty_filters_list.push(PS_params.categories);
  }

  // Setup author filter
  const author_rule = global_params.fields.author;
  document.querySelectorAll<HTMLSelectElement>("#authors").forEach((el) => {
    createSelectize(el, {
      plugins: ["remove_button"],
      maxOptions: el.querySelectorAll("option").length,
      items: author_rule?.words,
    });
    if (author_rule) {
      css(document.querySelectorAll(".filter-authors"), "display", "flex");
      setChecked(
        document.querySelectorAll(".filter-manager-controller.author"),
        true,
      );

      let author_search_str = "";
      const authorsSelectize = getSelectizeInstance(el)!;
      (authorsSelectize.getValue() as (string | number)[]).forEach((id) => {
        author_search_str +=
          (authorsSelectize.getItem(id)?.textContent ?? "")
            .replace(/\(\d+ \w+\)×/, "")
            .trim() + ", ";
      });

      if (author_rule.words.length > 0) {
        addClass(document.querySelectorAll(".filter-authors"), "filter-filled");
        text(
          document.querySelectorAll(".filter.filter-authors .search-words"),
          author_search_str.slice(0, -2),
        );
      } else {
        text(
          document.querySelectorAll(".filter.filter-authors .search-words"),
          str_author_widget_label,
        );
      }

      on(
        document.querySelectorAll(".filter-authors .filter-actions .clear"),
        "click",
        function () {
          getSelectizeInstance(el)?.clear();
        },
      );

      PS_params.authors = author_rule.words.length > 0 ? author_rule.words : "";

      empty_filters_list.push(PS_params.authors);
    }
  });

  // Setup added_by filter
  const added_by_ids = global_params.fields.added_by;
  if (added_by_ids) {
    css(document.querySelectorAll(".filter-added_by"), "display", "flex");
    setChecked(
      document.querySelectorAll(".filter-manager-controller.added_by"),
      true,
    );

    if (added_by_ids.length > 0) {
      addClass(document.querySelectorAll(".filter-added_by"), "filter-filled");

      const added_by_names: string[] = [];

      document.querySelectorAll(".added_by-option").forEach((el) => {
        const input = find(el, "input");
        const added_by_id = parseInt(String(attrOf(input, "name")));

        if (added_by_ids.includes(added_by_id)) {
          setChecked(input, true);
          added_by_names.push(textOf(find(el, ".added_by-name")));
        }
      });

      text(
        document.querySelectorAll(".filter.filter-added_by .search-words"),
        added_by_names.join(", "),
      );
    } else {
      text(
        document.querySelectorAll(".filter.filter-added_by .search-words"),
        str_added_by_widget_label,
      );
    }

    on(
      document.querySelectorAll(".filter-added_by .filter-actions .clear"),
      "click",
      function () {
        setChecked(
          document.querySelectorAll(".filter-added_by .added_by-option input"),
          false,
        );
      },
    );

    PS_params.added_by = added_by_ids.length > 0 ? added_by_ids : "";

    empty_filters_list.push(PS_params.added_by);
  }

  // Setup filetypes filter
  const filetypes_filter = global_params.fields.filetypes;
  if (filetypes_filter) {
    css(document.querySelectorAll(".filter-filetypes"), "display", "flex");
    setChecked(
      document.querySelectorAll(".filter-manager-controller.filetypes"),
      true,
    );

    let filetypes_search_str = "";
    filetypes_filter.forEach((ft) => {
      filetypes_search_str += ft + ", ";
    });

    if (filetypes_filter.length > 0) {
      addClass(document.querySelectorAll(".filter-filetypes"), "filter-filled");
      text(
        document.querySelectorAll(".filter.filter-filetypes .search-words"),
        filetypes_search_str.toUpperCase().slice(0, -2),
      );

      // `?? ""` for a name-less input: no filter list ever holds the
      // empty string, so it misses exactly as the old `undefined` did.
      document.querySelectorAll(".filetypes-option input").forEach((el) => {
        if (filetypes_filter.includes(attrOf(el, "name") ?? "")) {
          setChecked(el, true);
        }
      });
    } else {
      text(
        document.querySelectorAll(".filter.filter-filetypes .search-words"),
        str_filetypes_widget_label,
      );
    }

    on(
      document.querySelectorAll(".filter-filetypes .filter-actions .clear"),
      "click",
      function () {
        setChecked(
          document.querySelectorAll(
            ".filter-filetypes .filetypes-option input",
          ),
          false,
        );
      },
    );

    PS_params.filetypes = filetypes_filter.length > 0 ? filetypes_filter : "";

    empty_filters_list.push(PS_params.filetypes);
  }

  // Setup Ratio filter
  const ratios_filter = global_params.fields.ratios;
  if (ratios_filter) {
    css(document.querySelectorAll(".filter-ratios"), "display", "flex");
    setChecked(
      document.querySelectorAll(".filter-manager-controller.ratios"),
      true,
    );

    let ratios_search_str = "";
    ratios_filter.forEach((ft) => {
      ratios_search_str += str_ratios_label[ft] + ", ";
    });

    if (ratios_filter.length > 0) {
      addClass(document.querySelectorAll(".filter-ratios"), "filter-filled");
      text(
        document.querySelectorAll(".filter.filter-ratios .search-words"),
        ratios_search_str.slice(0, -2),
      );

      document.querySelectorAll(".ratios-option input").forEach((el) => {
        if (ratios_filter.includes(attrOf(el, "name") ?? "")) {
          setChecked(el, true);
        }
      });
    } else {
      text(
        document.querySelectorAll(".filter.filter-ratios .search-words"),
        str_ratio_widget_label,
      );
    }

    on(
      document.querySelectorAll(".filter-ratios .filter-actions .clear"),
      "click",
      function () {
        setChecked(
          document.querySelectorAll(".filter-ratios .ratios-option input"),
          false,
        );
      },
    );

    PS_params.ratios = ratios_filter.length > 0 ? ratios_filter : "";

    empty_filters_list.push(PS_params.ratios);
  }

  // Setup rating filter
  const ratings_filter = global_params.fields.ratings;
  if (ratings_filter && show_filter_ratings) {
    css(document.querySelectorAll(".filter-ratings"), "display", "flex");
    setChecked(
      document.querySelectorAll(".filter-manager-controller.ratings"),
      true,
    );

    let ratings_search_str = "";
    // Entries are strings, not numbers (`SearchRules::toArray()` emits
    // `list<string>`, and the API rejects a non-string entry with a
    // 422) -- the `0 ==` test and the `- 1` below were both running on
    // JS's own coercion, which `Number()` now states outright. The
    // label itself keeps using the raw entry, exactly as before.
    ratings_filter.forEach((rating, i) => {
      if (Number(rating) === 0) {
        ratings_search_str += str_no_rating;
        if (ratings_filter.length > 1) {
          ratings_search_str += ", ";
        }
      } else {
        const str_between = str_between_rating.split("%d");
        ratings_search_str +=
          str_between[0]! +
          (Number(rating) - 1) +
          str_between[1]! +
          rating +
          str_between[2]!;
        if (ratings_filter.length - 1 != i) {
          ratings_search_str += ", ";
        }
      }
    });

    if (ratings_filter.length > 0) {
      addClass(document.querySelectorAll(".filter-ratings"), "filter-filled");
      text(
        document.querySelectorAll(".filter.filter-ratings .search-words"),
        ratings_search_str,
      );

      document.querySelectorAll(".ratings-option input").forEach((el) => {
        if (ratings_filter.includes(attrOf(el, "name") ?? "")) {
          setChecked(el, true);
        }
      });
    } else {
      text(
        document.querySelectorAll(".filter.filter-ratings .search-words"),
        str_rating_widget_label,
      );
    }

    on(
      document.querySelectorAll(".filter-ratings .filter-actions .clear"),
      "click",
      function () {
        setChecked(
          document.querySelectorAll(".filter-ratings .ratings-option input"),
          false,
        );
      },
    );

    PS_params.ratings = ratings_filter.length > 0 ? ratings_filter : "";

    empty_filters_list.push(PS_params.ratings);
  }

  // Real `pwgDoubleSlider({ stop })` callback (`themes/admin/default/js/
  // doubleSlider.ts`) -- the direct native replacement for the
  // `jQuery(...).on("slidestop", ...)` listener this used to be
  // (jQuery UI slider's own custom event, invisible to a native
  // addEventListener, P49-B group 4).
  function onFilesizeSlideStop(): void {
    const slider = document.querySelector("[data-slider=filesizes]")!;
    const min = val(find(slider, "[data-input=min]"));
    const max = val(find(slider, "[data-input=max]"));

    const minInputs = document.querySelectorAll(
      "input[name=filter_filesize_min_text]",
    );
    setVal(minInputs, min!);
    trigger(minInputs, "change");
    const maxInputs = document.querySelectorAll(
      "input[name=filter_filesize_max_text]",
    );
    setVal(maxInputs, max!);
    trigger(maxInputs, "change");
  }

  // Setup filesize filter
  if (
    global_params.fields.filesize_min != null &&
    global_params.fields.filesize_max != null
  ) {
    css(document.querySelectorAll(".filter-filesize"), "display", "flex");
    setChecked(
      document.querySelectorAll(".filter-manager-controller.filesize"),
      true,
    );
    html(
      document.querySelectorAll(".filter.filter-filesize .slider-info"),
      sprintf(
        sliders.filesizes!.text,
        sliders.filesizes!.selected.min,
        sliders.filesizes!.selected.max,
      ),
    );

    pwgDoubleSlider(document.querySelector("[data-slider=filesizes]")!, {
      ...sliders.filesizes!,
      stop: onFilesizeSlideStop,
    });

    if (
      global_params.fields.filesize_min != null &&
      // These bounds are `int|string` server-side (`SearchRules::
      // $filesizeMin` & co). `Number()` is what `> 0` was already doing
      // to a string operand -- a relational comparison against a number
      // coerces via ToNumber -- just written out.
      Number(global_params.fields.filesize_max) > 0
    ) {
      addClass(document.querySelectorAll(".filter-filesize"), "filter-filled");
      html(
        document.querySelectorAll(".filter.filter-filesize .search-words"),
        sprintf(
          sliders.filesizes!.text,
          sliders.filesizes!.selected.min,
          sliders.filesizes!.selected.max,
        ),
      );
    } else {
      text(
        document.querySelectorAll(".filter.filter-filesize .search-words"),
        str_filesize_widget_label,
      );
    }

    on(
      document.querySelectorAll(".filter-filesize .filter-actions .clear"),
      "click",
      function () {
        updateFilters("filesize", "add");
        trigger(document.querySelectorAll(".filter-filesize"), "click");
        // `stop` re-passed here too: the original's own `slidestop`
        // listener was bound once, directly to the container element,
        // so it kept firing across every later `pwgDoubleSlider()`
        // re-init below -- this "clear" handler's own re-init has to
        // pass `stop` again to match, or it would silently go dark
        // after the first clear.
        pwgDoubleSlider(document.querySelector("[data-slider=filesizes]")!, {
          ...sliders.filesizes!,
          stop: onFilesizeSlideStop,
        });
        if (
          hasClass(
            document.querySelectorAll(".filter-filesize"),
            "filter-filled",
          )
        ) {
          removeClass(
            document.querySelectorAll(".filter-filesize"),
            "filter-filled",
          );
          text(
            document.querySelectorAll(".filter.filter-filesize .search-words"),
            str_filesize_widget_label,
          );
        }
      },
    );

    PS_params.filesize_min = global_params.fields.filesize_min ?? "";
    PS_params.filesize_max = global_params.fields.filesize_max ?? "";

    empty_filters_list.push(PS_params.filesize_min);
    empty_filters_list.push(PS_params.filesize_max);
  }

  // Setup Height filter
  if (
    global_params.fields.height_min != null &&
    global_params.fields.height_max != null
  ) {
    css(document.querySelectorAll(".filter-height"), "display", "flex");
    setChecked(
      document.querySelectorAll(".filter-manager-controller.height"),
      true,
    );
    html(
      document.querySelectorAll(".filter.filter-height .slider-info"),
      sprintf(
        sliders.heights!.text,
        sliders.heights!.selected.min,
        sliders.heights!.selected.max,
      ),
    );

    pwgDoubleSlider(
      document.querySelector("[data-slider=heights]")!,
      sliders.heights!,
    );

    if (
      Number(global_params.fields.height_min) > 0 &&
      Number(global_params.fields.height_max) > 0
    ) {
      addClass(document.querySelectorAll(".filter-height"), "filter-filled");
      html(
        document.querySelectorAll(".filter.filter-height .search-words"),
        sprintf(
          sliders.heights!.text,
          sliders.heights!.selected.min,
          sliders.heights!.selected.max,
        ),
      );
    } else {
      text(
        document.querySelectorAll(".filter.filter-height .search-words"),
        str_height_widget_label,
      );
    }

    on(
      document.querySelectorAll(".filter-height .filter-actions .clear"),
      "click",
      function () {
        updateFilters("height", "add");
        trigger(document.querySelectorAll(".filter-height"), "click");
        pwgDoubleSlider(
          document.querySelector("[data-slider=heights]")!,
          sliders.heights!,
        );
        if (
          hasClass(document.querySelectorAll(".filter-height"), "filter-filled")
        ) {
          removeClass(
            document.querySelectorAll(".filter-height"),
            "filter-filled",
          );
          text(
            document.querySelectorAll(".filter.filter-height .search-words"),
            str_height_widget_label,
          );
        }
      },
    );

    PS_params.height_min = global_params.fields.height_min ?? "";
    PS_params.height_max = global_params.fields.height_max ?? "";

    empty_filters_list.push(PS_params.height_min);
    empty_filters_list.push(PS_params.height_max);
  }

  // Setup Width filter
  if (
    global_params.fields.width_min != null &&
    global_params.fields.width_max != null
  ) {
    css(document.querySelectorAll(".filter-width"), "display", "flex");
    setChecked(
      document.querySelectorAll(".filter-manager-controller.width"),
      true,
    );
    html(
      document.querySelectorAll(".filter.filter-width .slider-info"),
      sprintf(
        sliders.widths!.text,
        sliders.widths!.selected.min,
        sliders.widths!.selected.max,
      ),
    );

    pwgDoubleSlider(
      document.querySelector("[data-slider=widths]")!,
      sliders.widths!,
    );

    if (
      Number(global_params.fields.width_min) > 0 &&
      Number(global_params.fields.width_max) > 0
    ) {
      addClass(document.querySelectorAll(".filter-width"), "filter-filled");
      html(
        document.querySelectorAll(".filter.filter-width .search-words"),
        sprintf(
          sliders.widths!.text,
          sliders.widths!.selected.min,
          sliders.widths!.selected.max,
        ),
      );
    } else {
      text(
        document.querySelectorAll(".filter.filter-width .search-words"),
        str_width_widget_label,
      );
    }

    on(
      document.querySelectorAll(".filter-width .filter-actions .clear"),
      "click",
      function () {
        updateFilters("width", "add");
        trigger(document.querySelectorAll(".filter-width"), "click");
        pwgDoubleSlider(
          document.querySelector("[data-slider=widths]")!,
          sliders.widths!,
        );
        if (
          hasClass(document.querySelectorAll(".filter-width"), "filter-filled")
        ) {
          removeClass(
            document.querySelectorAll(".filter-width"),
            "filter-filled",
          );
          text(
            document.querySelectorAll(".filter.filter-width .search-words"),
            str_width_widget_label,
          );
        }
      },
    );

    PS_params.width_min = global_params.fields.width_min ?? "";
    PS_params.width_max = global_params.fields.width_max ?? "";

    empty_filters_list.push(PS_params.width_min);
    empty_filters_list.push(PS_params.width_max);
  }

  // Setup Expert filter
  const expert_rule = global_params.fields.expert;
  if (expert_rule) {
    css(document.querySelectorAll(".filter-expert"), "display", "flex");
    setChecked(
      document.querySelectorAll(".filter-manager-controller.expert"),
      true,
    );

    const expert_search_str = expert_rule.string;
    setVal(document.querySelectorAll("#expert-search"), expert_search_str);

    if (expert_search_str.length > 0) {
      addClass(document.querySelectorAll(".filter-expert"), "filter-filled");
      text(
        document.querySelectorAll(".filter.filter-expert .search-words"),
        expert_search_str,
      );
    } else {
      text(
        document.querySelectorAll(".filter.filter-expert .search-words"),
        str_expert_widget_label,
      );
    }

    on(
      document.querySelectorAll(".filter-expert .filter-actions .clear"),
      "click",
      function () {
        setVal(document.querySelectorAll(".filter-expert #expert-search"), "");
      },
    );

    PS_params.expert = expert_search_str.length > 0 ? expert_search_str : "";

    empty_filters_list.push(PS_params.expert);
  }

  if (filters_to_remove.length > 0) {
    performSearch(PS_params, true);
  }

  // Adapt no result message
  if (document.querySelectorAll(".filter-filled").length === 0) {
    html(
      document.querySelectorAll(".mcs-no-result .text .top"),
      str_empty_search_top_alt,
    );
    html(
      document.querySelectorAll(".mcs-no-result .text .bot"),
      str_empty_search_bot_alt,
    );
  }

  if (
    !empty_filters_list.every(
      (param) => param === "" || param === null || typeof param === "undefined",
    )
  ) {
    addClass(document.querySelectorAll(".clear-all"), "clickable");
    on(document.querySelectorAll(".clear-all.clickable"), "click", function () {
      const exclude_params = [
        "search_id",
        "allwords_mode",
        "allwords_fields",
        "tags_mode",
        "categories_withsubs",
      ];
      for (const key in PS_params) {
        if (!exclude_params.includes(key)) {
          if ("date_posted_custom" == key || "date_created_custom" == key) {
            PS_params[key] = [];
          } else {
            PS_params[key] = "";
          }
        }
      }
      performSearch(PS_params, true);
    });
  }

  /**
   * Filter Manager
   */
  on(document.querySelectorAll(".filter-manager"), "click", function () {
    show(document.querySelectorAll(".filter-manager-popin"));
  });

  on(document, "keyup", function (e: Event) {
    if ((e as KeyboardEvent).key === "Escape") {
      trigger(
        document.querySelectorAll(
          ".filter-manager-popin .filter-manager-close",
        ),
        "click",
      );
      trigger(document.querySelectorAll("#closeModalQuickSearch"), "click");
    }
    if ((e as KeyboardEvent).key === "Enter") {
      document
        .querySelectorAll(".filter-form .filter-validate")
        .forEach((el) => {
          if (isVisible(el)) {
            trigger([el], "click");
          }
        });
    }
  });

  on(
    document.querySelectorAll(".filter-manager-popin"),
    "click",
    function (this: Element, e: Event) {
      // Was `$(this).is(e.target) && $(this).has(e.target).length === 0`
      // -- since `.has()` never matches the element itself (descendants
      // only), that second half is always true once the first half
      // holds, so the pair reduces to "the click landed on the backdrop
      // itself, not a descendant of it".
      if (e.target === this) {
        trigger(
          document.querySelectorAll(
            ".filter-manager-popin .filter-manager-close",
          ),
          "click",
        );
      }
    },
  );

  on(
    document.querySelectorAll(
      ".filter-manager-popin .filter-cancel, .filter-manager-popin .filter-manager-close",
    ),
    "click",
    function () {
      hide(document.querySelectorAll(".filter-manager-popin"));
      document
        .querySelectorAll(".filter-manager-controller-container input")
        .forEach((el) => {
          const wid = data(el, "wid") as string;
          if (is(el, ":checked")) {
            if (!isVisible(document.querySelector(".filter.filter-" + wid)!)) {
              setChecked(el, false);
            }
          } else {
            if (isVisible(document.querySelector(".filter.filter-" + wid)!)) {
              setChecked(el, true);
            }
          }
        });
    },
  );

  on(
    document.querySelectorAll(".filter-manager-popin .filter-validate"),
    "click",
    function () {
      document
        .querySelectorAll(".filter-manager-controller-container input")
        .forEach((el) => {
          const wid = data(el, "wid") as string;
          if (is(el, ":checked")) {
            if (!isVisible(document.querySelector(".filter.filter-" + wid)!)) {
              updateFilters(wid, "add");
            }
          } else {
            if (isVisible(document.querySelector(".filter.filter-" + wid)!)) {
              updateFilters(wid, "del");
            }
          }
        });
      // Set second param to true to trigger reload
      performSearch(PS_params, true);
    },
  );

  /**
   * Tags & Albums found
   */
  on(document.querySelectorAll(".mcs-tags-found"), "click", function () {
    show(document.querySelectorAll(".tags-found-popin"));
  });
  on(document.querySelectorAll(".mcs-albums-found"), "click", function () {
    show(document.querySelectorAll(".albums-found-popin"));
  });

  on(document, "keyup", function (e: Event) {
    if ((e as KeyboardEvent).key === "Escape") {
      trigger(
        document.querySelectorAll(".tags-found-popin .tags-found-close"),
        "click",
      );
      trigger(
        document.querySelectorAll(".albums-found-popin .albums-found-close"),
        "click",
      );
    }
  });

  on(
    document.querySelectorAll(".tags-found-popin"),
    "click",
    function (this: Element, e: Event) {
      if (e.target === this) {
        trigger(
          document.querySelectorAll(".tags-found-popin .tags-found-close"),
          "click",
        );
      }
    },
  );
  on(document.querySelectorAll(".tags-found-close"), "click", function () {
    hide(document.querySelectorAll(".tags-found-popin"));
  });

  on(
    document.querySelectorAll(".albums-found-popin"),
    "click",
    function (this: Element, e: Event) {
      if (e.target === this) {
        trigger(
          document.querySelectorAll(".albums-found-popin .albums-found-close"),
          "click",
        );
      }
    },
  );
  on(document.querySelectorAll(".albums-found-close"), "click", function () {
    hide(document.querySelectorAll(".albums-found-popin"));
  });

  /**
   * Filter Word
   */
  on(document.querySelectorAll(".filter-word"), "click", function (e: Event) {
    if (shouldIgnoreFilterClick(e.target)) {
      return;
    }
    toggle(
      document.querySelectorAll(".filter-word-form"),
      0,
      function (this: Element) {
        if (isVisible(this)) {
          addClass(
            document.querySelectorAll(".filter-word"),
            "show-filter-dropdown",
          );
          document.querySelector<HTMLElement>("#word-search")?.focus();
        } else {
          removeClass(
            document.querySelectorAll(".filter-word"),
            "show-filter-dropdown",
          );

          PS_params.allwords = val(document.querySelectorAll("#word-search"));
          PS_params.allwords_mode = attrOf(
            document.querySelectorAll(".word-search-options input:checked"),
            "value",
          );

          const new_fields: (string | undefined)[] = [];
          document
            .querySelectorAll(".filter-word-form .search-params input:checked")
            .forEach((el) => {
              new_fields.push(attrOf(el, "name") ?? undefined);
            });
          PS_params.allwords_fields = new_fields.length > 0 ? new_fields : "";
        }
      },
    );
  });
  on(
    document.querySelectorAll(".filter-word .filter-validate"),
    "click",
    function () {
      trigger(document.querySelectorAll(".filter-word"), "click");
      performSearch(PS_params, true);
    },
  );
  on(
    document.querySelectorAll(".filter-word .filter-actions .delete"),
    "click",
    function () {
      updateFilters("word", "del");
      performSearch(PS_params, true);
      if (
        !hasClass(document.querySelectorAll(".filter-word"), "filter-filled")
      ) {
        hide(document.querySelectorAll(".filter-word"));
        setChecked(
          document.querySelectorAll(".filter-manager-controller.word"),
          false,
        );
      }
    },
  );

  /**
   * Filter Tag
   */
  on(document.querySelectorAll(".filter-tag"), "click", function (e: Event) {
    if (shouldIgnoreFilterClick(e.target, "remove")) {
      return;
    }
    toggle(
      document.querySelectorAll(".filter-tag-form"),
      0,
      function (this: Element) {
        if (isVisible(this)) {
          addClass(
            document.querySelectorAll(".filter-tag"),
            "show-filter-dropdown",
          );
        } else {
          removeClass(
            document.querySelectorAll(".filter-tag"),
            "show-filter-dropdown",
          );
          {
            const tagValue = getSelectizeInstance(
              document.querySelector<HTMLSelectElement>("#tag-search")!,
            )!.getValue() as (string | number)[];
            PS_params.tags = tagValue.length > 0 ? tagValue : "";
          }
          PS_params.tags_mode = val(
            document.querySelectorAll(
              ".filter-tag-form .search-params input:checked",
            ),
          );
        }
      },
    );
  });
  on(
    document.querySelectorAll(".filter-tag .filter-validate"),
    "click",
    function () {
      trigger(document.querySelectorAll(".filter-tag"), "click");
      performSearch(PS_params, true);
    },
  );
  on(
    document.querySelectorAll(".filter-tag .filter-actions .delete"),
    "click",
    function () {
      updateFilters("tag", "del");
      performSearch(PS_params, true);
      if (
        !hasClass(document.querySelectorAll(".filter-tag"), "filter-filled")
      ) {
        hide(document.querySelectorAll(".filter-tag"));
        setChecked(
          document.querySelectorAll(".filter-manager-controller.tags"),
          false,
        );
      }
    },
  );

  /**
   * Filter Date posted
   */
  on(
    document.querySelectorAll(".filter-date_posted"),
    "click",
    function (e: Event) {
      if (shouldIgnoreFilterClick(e.target)) {
        return;
      }
      toggle(
        document.querySelectorAll(".filter-date_posted-form"),
        0,
        function (this: Element) {
          if (isVisible(this)) {
            addClass(
              document.querySelectorAll(".filter-date_posted"),
              "show-filter-dropdown",
            );
          } else {
            removeClass(
              document.querySelectorAll(".filter-date_posted"),
              "show-filter-dropdown",
            );

            const presetValue = val(
              document.querySelectorAll(
                ".preset_posted_date .date_posted-option input:checked",
              ),
            );

            PS_params.date_posted_preset = presetValue ?? "";

            if ("custom" == presetValue) {
              const customDates: (string | number | string[] | undefined)[] =
                [];

              document
                .querySelectorAll(
                  ".custom_posted_date .date_posted-option input:checked",
                )
                .forEach((el) => {
                  customDates.push(val([el]));
                });

              PS_params.date_posted_custom =
                customDates.length > 0 ? customDates : "";
            }
          }
        },
      );
    },
  );

  on(
    document.querySelectorAll(".filter-date_posted .filter-validate"),
    "click",
    function () {
      trigger(document.querySelectorAll(".filter-date_posted"), "click");
      performSearch(PS_params, true);
    },
  );

  on(
    document.querySelectorAll(".filter-date_posted .filter-actions .delete"),
    "click",
    function () {
      updateFilters("date_posted", "del");
      performSearch(PS_params, true);
      if (
        !hasClass(
          document.querySelectorAll(".filter-date_posted"),
          "filter-filled",
        )
      ) {
        hide(document.querySelectorAll(".filter-date_posted"));
        setChecked(
          document.querySelectorAll(".filter-manager-controller.date"),
          false,
        );
      }
    },
  );

  /**
   * Filter Date created
   */
  on(
    document.querySelectorAll(".filter-date_created"),
    "click",
    function (e: Event) {
      if (shouldIgnoreFilterClick(e.target)) {
        return;
      }
      toggle(
        document.querySelectorAll(".filter-date_created-form"),
        0,
        function (this: Element) {
          if (isVisible(this)) {
            addClass(
              document.querySelectorAll(".filter-date_created"),
              "show-filter-dropdown",
            );
          } else {
            removeClass(
              document.querySelectorAll(".filter-date_created"),
              "show-filter-dropdown",
            );

            const presetValue = val(
              document.querySelectorAll(
                ".preset_created_date .date_created-option input:checked",
              ),
            );

            PS_params.date_created_preset = presetValue ?? "";

            if ("custom" == presetValue) {
              const customDates: (string | number | string[] | undefined)[] =
                [];

              document
                .querySelectorAll(
                  ".custom_created_date .date_created-option input:checked",
                )
                .forEach((el) => {
                  customDates.push(val([el]));
                });

              PS_params.date_created_custom =
                customDates.length > 0 ? customDates : "";
            }
          }
        },
      );
    },
  );

  on(
    document.querySelectorAll(".filter-date_created .filter-validate"),
    "click",
    function () {
      trigger(document.querySelectorAll(".filter-date_created"), "click");
      performSearch(PS_params, true);
    },
  );

  on(
    document.querySelectorAll(".filter-date_created .filter-actions .delete"),
    "click",
    function () {
      updateFilters("date_created", "del");
      performSearch(PS_params, true);
      if (
        !hasClass(
          document.querySelectorAll(".filter-date_created"),
          "filter-filled",
        )
      ) {
        hide(document.querySelectorAll(".filter-date_created"));
        setChecked(
          document.querySelectorAll(".filter-manager-controller.date"),
          false,
        );
      }
    },
  );

  /**
   * Filter Album
   */
  on(document.querySelectorAll(".filter-album"), "click", function (e: Event) {
    if (shouldIgnoreFilterClick(e.target, "remove-item")) {
      return;
    }
    toggle(
      document.querySelectorAll(".filter-album-form"),
      0,
      function (this: Element) {
        if (isVisible(this)) {
          addClass(
            document.querySelectorAll(".filter-album"),
            "show-filter-dropdown",
          );
        } else {
          removeClass(
            document.querySelectorAll(".filter-album"),
            "show-filter-dropdown",
          );
          PS_params.categories =
            ab.get_selected_albums().length > 0 ? ab.get_selected_albums() : "";
          PS_params.categories_withsubs =
            document.querySelectorAll("input[name='search-sub-cats']:checked")
              .length != 0;
        }
      },
    );
  });
  on(
    document.querySelectorAll(".filter-album .filter-validate"),
    "click",
    function () {
      trigger(document.querySelectorAll(".filter-album"), "click");
      performSearch(PS_params, true);
    },
  );
  on(
    document.querySelectorAll(".filter-album .filter-actions .delete"),
    "click",
    function () {
      updateFilters("album", "del");
      performSearch(PS_params, true);
      if (
        !hasClass(document.querySelectorAll(".filter-album"), "filter-filled")
      ) {
        hide(document.querySelectorAll(".filter-album"));
        setChecked(
          document.querySelectorAll(".filter-manager-controller.album"),
          false,
        );
      }
    },
  );

  /**
   * Author Widget
   */
  on(
    document.querySelectorAll(".filter-authors"),
    "click",
    function (e: Event) {
      if (shouldIgnoreFilterClick(e.target, "remove")) {
        return;
      }
      toggle(
        document.querySelectorAll(".filter-author-form"),
        0,
        function (this: Element) {
          if (isVisible(this)) {
            addClass(
              document.querySelectorAll(".filter-authors"),
              "show-filter-dropdown",
            );
          } else {
            removeClass(
              document.querySelectorAll(".filter-authors"),
              "show-filter-dropdown",
            );

            {
              const authorValue = getSelectizeInstance(
                document.querySelector<HTMLSelectElement>("#authors")!,
              )!.getValue() as (string | number)[];
              PS_params.authors = authorValue.length > 0 ? authorValue : "";
            }
          }
        },
      );
    },
  );
  on(
    document.querySelectorAll(".filter-authors .filter-validate"),
    "click",
    function () {
      trigger(document.querySelectorAll(".filter-authors"), "click");
      performSearch(PS_params, true);
    },
  );
  on(
    document.querySelectorAll(".filter-authors .filter-actions .delete"),
    "click",
    function () {
      updateFilters("authors", "del");
      performSearch(PS_params, true);
      if (
        !hasClass(document.querySelectorAll(".filter-authors"), "filter-filled")
      ) {
        hide(document.querySelectorAll(".filter-authors"));
        setChecked(
          document.querySelectorAll(".filter-manager-controller.author"),
          false,
        );
      }
    },
  );

  /**
   * Added by Widget
   */
  on(
    document.querySelectorAll(".filter-added_by"),
    "click",
    function (e: Event) {
      if (shouldIgnoreFilterClick(e.target, "remove")) {
        return;
      }
      toggle(
        document.querySelectorAll(".filter-added_by-form"),
        0,
        function (this: Element) {
          if (isVisible(this)) {
            addClass(
              document.querySelectorAll(".filter-added_by"),
              "show-filter-dropdown",
            );
          } else {
            removeClass(
              document.querySelectorAll(".filter-added_by"),
              "show-filter-dropdown",
            );

            const added_by_array: (string | undefined)[] = [];
            document
              .querySelectorAll(".added_by-option input:checked")
              .forEach((el) => {
                added_by_array.push(attrOf(el, "name") ?? undefined);
              });

            PS_params.added_by =
              added_by_array.length > 0 ? added_by_array : "";
          }
        },
      );
    },
  );
  on(
    document.querySelectorAll(".filter-added_by .filter-validate"),
    "click",
    function () {
      trigger(document.querySelectorAll(".filter-added_by"), "click");
      performSearch(PS_params, true);
    },
  );
  on(
    document.querySelectorAll(".filter-added_by .filter-actions .delete"),
    "click",
    function () {
      updateFilters("added_by", "del");
      performSearch(PS_params, true);
      if (
        !hasClass(
          document.querySelectorAll(".filter-added_by"),
          "filter-filled",
        )
      ) {
        hide(document.querySelectorAll(".filter-added_by"));
        setChecked(
          document.querySelectorAll(".filter-manager-controller.added_by"),
          false,
        );
      }
    },
  );

  /**
   * File type Widget
   */
  on(
    document.querySelectorAll(".filter-filetypes"),
    "click",
    function (e: Event) {
      if (shouldIgnoreFilterClick(e.target, "remove")) {
        return;
      }
      toggle(
        document.querySelectorAll(".filter-filetypes-form"),
        0,
        function (this: Element) {
          if (isVisible(this)) {
            addClass(
              document.querySelectorAll(".filter-filetypes"),
              "show-filter-dropdown",
            );
          } else {
            removeClass(
              document.querySelectorAll(".filter-filetypes"),
              "show-filter-dropdown",
            );

            const filetypes_array: (string | undefined)[] = [];
            document
              .querySelectorAll(".filetypes-option input:checked")
              .forEach((el) => {
                filetypes_array.push(attrOf(el, "name") ?? undefined);
              });

            PS_params.filetypes =
              filetypes_array.length > 0 ? filetypes_array : "";
          }
        },
      );
    },
  );

  on(
    document.querySelectorAll(".filter-filetypes .filter-validate"),
    "click",
    function () {
      trigger(document.querySelectorAll(".filter-filetypes"), "click");
      performSearch(PS_params, true);
    },
  );
  on(
    document.querySelectorAll(".filter-filetypes .filter-actions .delete"),
    "click",
    function () {
      updateFilters("filetypes", "del");
      performSearch(PS_params, true);
      if (
        !hasClass(
          document.querySelectorAll(".filter-filetypes"),
          "filter-filled",
        )
      ) {
        hide(document.querySelectorAll(".filter-filetypes"));
        setChecked(
          document.querySelectorAll(".filter-manager-controller.filetypes"),
          false,
        );
      }
    },
  );

  /**
   * Ratios widget
   */
  on(document.querySelectorAll(".filter-ratios"), "click", function (e: Event) {
    if (shouldIgnoreFilterClick(e.target, "remove")) {
      return;
    }
    toggle(
      document.querySelectorAll(".filter-ratios-form"),
      0,
      function (this: Element) {
        if (isVisible(this)) {
          addClass(
            document.querySelectorAll(".filter-ratios"),
            "show-filter-dropdown",
          );
        } else {
          removeClass(
            document.querySelectorAll(".filter-ratios"),
            "show-filter-dropdown",
          );

          const ratios_array: (string | undefined)[] = [];
          document
            .querySelectorAll(".ratios-option input:checked")
            .forEach((el) => {
              ratios_array.push(attrOf(el, "name") ?? undefined);
            });

          PS_params.ratios = ratios_array.length > 0 ? ratios_array : "";
        }
      },
    );
  });

  on(
    document.querySelectorAll(".filter-ratios .filter-validate"),
    "click",
    function () {
      trigger(document.querySelectorAll(".filter-ratios"), "click");
      performSearch(PS_params, true);
    },
  );
  on(
    document.querySelectorAll(".filter-ratios .filter-actions .delete"),
    "click",
    function () {
      updateFilters("ratios", "del");
      performSearch(PS_params, true);
      if (
        !hasClass(document.querySelectorAll(".filter-ratios"), "filter-filled")
      ) {
        hide(document.querySelectorAll(".filter-ratios"));
        setChecked(
          document.querySelectorAll(".filter-manager-controller.ratios"),
          false,
        );
      }
    },
  );

  /**
   * Rating widget
   */
  on(
    document.querySelectorAll(".filter-ratings"),
    "click",
    function (e: Event) {
      if (shouldIgnoreFilterClick(e.target, "remove")) {
        return;
      }
      toggle(
        document.querySelectorAll(".filter-ratings-form"),
        0,
        function (this: Element) {
          if (isVisible(this)) {
            addClass(
              document.querySelectorAll(".filter-ratings"),
              "show-filter-dropdown",
            );
          } else {
            removeClass(
              document.querySelectorAll(".filter-ratings"),
              "show-filter-dropdown",
            );
            const ratings_array: (string | undefined)[] = [];

            document
              .querySelectorAll(".ratings-option input:checked")
              .forEach((el) => {
                ratings_array.push(attrOf(el, "name") ?? undefined);
              });

            PS_params.ratings = ratings_array.length > 0 ? ratings_array : "";
          }
        },
      );
    },
  );

  on(
    document.querySelectorAll(".filter-ratings .filter-validate"),
    "click",
    function () {
      trigger(document.querySelectorAll(".filter-ratings"), "click");
      performSearch(PS_params, true);
    },
  );
  on(
    document.querySelectorAll(".filter-ratings .filter-actions .delete"),
    "click",
    function () {
      updateFilters("ratings", "del");
      performSearch(PS_params, true);
      if (
        !hasClass(document.querySelectorAll(".filter-ratings"), "filter-filled")
      ) {
        hide(document.querySelectorAll(".filter-ratings"));
        setChecked(
          document.querySelectorAll(".filter-manager-controller.ratings"),
          false,
        );
      }
    },
  );

  /**
   * Filesize widget
   */
  on(
    document.querySelectorAll(".filter-filesize"),
    "click",
    function (e: Event) {
      if (shouldIgnoreFilterClick(e.target, "remove")) {
        return;
      }
      toggle(
        document.querySelectorAll(".filter-filesize-form"),
        0,
        function (this: Element) {
          if (isVisible(this)) {
            addClass(
              document.querySelectorAll(".filter-filesize"),
              "show-filter-dropdown",
            );
          } else {
            removeClass(
              document.querySelectorAll(".filter-filesize"),
              "show-filter-dropdown",
            );
          }
        },
      );
    },
  );
  on(
    document.querySelectorAll(".filter-filesize .filter-validate"),
    "click",
    function () {
      const filesize_min = Math.floor(
        Number(
          val(document.querySelectorAll("input[name=filter_filesize_min]")),
        ) * 1024,
      );
      const filesize_max = Math.ceil(
        Number(
          val(document.querySelectorAll("input[name=filter_filesize_max]")),
        ) * 1024,
      );

      PS_params.filesize_min = filesize_min;
      PS_params.filesize_max = filesize_max;

      trigger(document.querySelectorAll(".filter-filesize"), "click");
      performSearch(PS_params, true);
    },
  );

  on(
    document.querySelectorAll(".filter-filesize .filter-actions .delete"),
    "click",
    function () {
      updateFilters("filesize", "del");
      performSearch(PS_params, true);
      if (
        !hasClass(
          document.querySelectorAll(".filter-filesize"),
          "filter-filled",
        )
      ) {
        hide(document.querySelectorAll(".filter-filesize"));
        setChecked(
          document.querySelectorAll(".filter-manager-controller.filesize"),
          false,
        );
      }
    },
  );

  /**
   * Height widget
   */
  on(document.querySelectorAll(".filter-height"), "click", function (e: Event) {
    if (shouldIgnoreFilterClick(e.target, "remove")) {
      return;
    }
    toggle(
      document.querySelectorAll(".filter-height-form"),
      0,
      function (this: Element) {
        if (isVisible(this)) {
          addClass(
            document.querySelectorAll(".filter-height"),
            "show-filter-dropdown",
          );
        } else {
          removeClass(
            document.querySelectorAll(".filter-height"),
            "show-filter-dropdown",
          );
        }
      },
    );
  });
  on(
    document.querySelectorAll(".filter-height .filter-validate"),
    "click",
    function () {
      const height_min = val(
        document.querySelectorAll("input[name=filter_height_min]"),
      );
      const height_max = val(
        document.querySelectorAll("input[name=filter_height_max]"),
      );

      PS_params.height_min = height_min;
      PS_params.height_max = height_max;

      trigger(document.querySelectorAll(".filter-height"), "click");
      performSearch(PS_params, true);
    },
  );

  on(
    document.querySelectorAll(".filter-height .filter-actions .delete"),
    "click",
    function () {
      updateFilters("height", "del");
      performSearch(PS_params, true);
      if (
        !hasClass(document.querySelectorAll(".filter-height"), "filter-filled")
      ) {
        hide(document.querySelectorAll(".filter-height"));
        setChecked(
          document.querySelectorAll(".filter-manager-controller.height"),
          false,
        );
      }
    },
  );

  /**
   * Width widget
   */
  on(document.querySelectorAll(".filter-width"), "click", function (e: Event) {
    if (shouldIgnoreFilterClick(e.target, "remove")) {
      return;
    }
    toggle(
      document.querySelectorAll(".filter-width-form"),
      0,
      function (this: Element) {
        if (isVisible(this)) {
          addClass(
            document.querySelectorAll(".filter-width"),
            "show-filter-dropdown",
          );
        } else {
          removeClass(
            document.querySelectorAll(".filter-width"),
            "show-filter-dropdown",
          );
        }
      },
    );
  });
  on(
    document.querySelectorAll(".filter-width .filter-validate"),
    "click",
    function () {
      const width_min = val(
        document.querySelectorAll("input[name=filter_width_min]"),
      );
      const width_max = val(
        document.querySelectorAll("input[name=filter_width_max]"),
      );

      PS_params.width_min = width_min;
      PS_params.width_max = width_max;

      trigger(document.querySelectorAll(".filter-width"), "click");
      performSearch(PS_params, true);
    },
  );

  on(
    document.querySelectorAll(".filter-width .filter-actions .delete"),
    "click",
    function () {
      updateFilters("width", "del");
      performSearch(PS_params, true);
      if (
        !hasClass(document.querySelectorAll(".filter-width"), "filter-filled")
      ) {
        hide(document.querySelectorAll(".filter-width"));
        setChecked(
          document.querySelectorAll(".filter-manager-controller.width"),
          false,
        );
      }
    },
  );

  /**
   * Expert widget
   */
  on(document.querySelectorAll(".filter-expert"), "click", function (e: Event) {
    if (shouldIgnoreFilterClick(e.target, "remove")) {
      return;
    }
    toggle(
      document.querySelectorAll(".filter-expert-form"),
      0,
      function (this: Element) {
        if (isVisible(this)) {
          addClass(
            document.querySelectorAll(".filter-expert"),
            "show-filter-dropdown",
          );
        } else {
          removeClass(
            document.querySelectorAll(".filter-expert"),
            "show-filter-dropdown",
          );

          PS_params.expert = val(document.querySelectorAll("#expert-search"));
        }
      },
    );
  });

  on(
    document.querySelectorAll(".filter-expert .filter-validate"),
    "click",
    function () {
      trigger(document.querySelectorAll(".filter-expert"), "click");
      performSearch(PS_params, true);
    },
  );
  on(
    document.querySelectorAll(".filter-expert .filter-actions .delete"),
    "click",
    function () {
      updateFilters("expert", "del");
      performSearch(PS_params, true);
      if (
        !hasClass(document.querySelectorAll(".filter-expert"), "filter-filled")
      ) {
        hide(document.querySelectorAll(".filter-expert"));
        setChecked(
          document.querySelectorAll(".filter-manager-controller.expert"),
          false,
        );
      }
    },
  );
});

function performSearch(params: Record<string, any>, reload: boolean = false) {
  // PS_params uses snake_case field names (also used elsewhere in this
  // file to drive the active-filter-chip UI) -- translated to
  // POST /api/v1/images/searches's camelCase body shape here, the one
  // place that actually sends it. `expert` has no equivalent on this
  // endpoint, so it's not sent.
  const body: Record<string, any> = {
    allwords: params.allwords,
    allwordsFields: params.allwords_fields,
    allwordsMode: params.allwords_mode,
    tags: params.tags,
    tagsMode: params.tags_mode,
    datePostedPreset: params.date_posted_preset,
    datePostedCustom: params.date_posted_custom,
    dateCreatedPreset: params.date_created_preset,
    dateCreatedCustom: params.date_created_custom,
    categories: params.categories,
    categoriesWithsubs: params.categories_withsubs,
    authors: params.authors,
    addedBy: params.added_by,
    filetypes: params.filetypes,
    ratios: params.ratios,
    ratings: params.ratings,
  };
  if (params.search_id) {
    body.searchId = params.search_id;
  }
  (
    [
      ["filesize_min", "filesizeMin"],
      ["filesize_max", "filesizeMax"],
      ["width_min", "widthMin"],
      ["width_max", "widthMax"],
      ["height_min", "heightMin"],
      ["height_max", "heightMax"],
    ] as [string, string][]
  ).forEach(([from, to]) => {
    if (params[from] !== "" && params[from] != null) {
      body[to] = Number(params[from]);
    }
  });

  void ajax({
    url: "api/v1/images/searches",
    type: "POST",
    contentType: "application/json",
    dataType: "json",
    data: JSON.stringify(body),
    success: function (
      data: operations["imageFilteredSearchCreate"]["responses"][201]["content"]["application/json"],
    ) {
      if (reload && typeof data.searchUrl !== "undefined") {
        reloadPage(data.searchUrl);
      }
    },
    error: function (e) {
      console.error(e);
      append(
        document.querySelectorAll(".filter-form "),
        '<p class="error">Error</p>',
      );
      css(
        find(document.querySelectorAll(".filter-validate"), ".validate-text"),
        "display",
        "block",
      );
      hide(find(document.querySelectorAll(".filter-validate"), ".loading"));
      const removeFilterEls = document.querySelectorAll(".remove-filter");
      removeClass(removeFilterEls, prefix_icon + "spin6 animate-spin");
      addClass(removeFilterEls, prefix_icon + "cancel");
    },
  });
}

function add_related_category({
  album,
  addSelectedAlbum,
}: AlbumSelectorCallbackArgs) {
  display_related_category(album.id, album.name);
  append(
    document.querySelectorAll(".invisible-related-categories-select"),
    `<option selected value="${album.id}"></option>`,
  );
  addSelectedAlbum();
}

function remove_related_category({
  id_album,
}: AlbumSelectorRemoveCallbackArgs) {
  // `id_album` is a raw category id (e.g. "42"), written below as this
  // element's own bare `id` attribute -- digit-leading, so it needs
  // escapeId() under native querySelector (Sizzle tolerated it
  // unescaped).
  document.querySelector("#" + escapeId(id_album))?.parentElement?.remove();
}

function display_related_category(
  cat_id: string | number,
  cat_link_path: string | undefined,
) {
  append(
    document.querySelectorAll(".selected-categories-container"),
    `<div class="breadcrumb-item">
      <span class="link-path">${cat_link_path}</span><span id="${cat_id}" class="mcs-icon ${prefix_icon}cancel remove-item"></span>
    </div>`,
  );
}

function updateFilters(filterName: string, mode: "add" | "del") {
  switch (filterName) {
    case "word":
      if (mode == "add") {
        PS_params.allwords = "";
        PS_params.allwords_mode = "AND";
        PS_params.allwords_fields = [];
      } else if (mode == "del") {
        delete PS_params.allwords;
        delete PS_params.allwords_mode;
        delete PS_params.allwords_fields;
      }
      break;

    case "tag":
      if (mode == "add") {
        PS_params.tags = "";
        PS_params.tags_mode = "AND";
      } else if (mode == "del") {
        delete PS_params.tags;
        delete PS_params.tags_mode;
      }
      break;

    case "album":
      if (mode == "add") {
        PS_params.categories = "";
        PS_params.categories_withsubs = false;
      } else if (mode == "del") {
        delete PS_params.categories;
        delete PS_params.categories_withsubs;
      }
      break;

    case "date_posted":
      if (mode == "add") {
        PS_params.date_posted_preset = "";
        PS_params.date_posted_custom = [];
      } else if (mode == "del") {
        delete PS_params.date_posted_preset;
        delete PS_params.date_posted_custom;
      }
      break;

    case "date_created":
      if (mode == "add") {
        PS_params.date_created_preset = "";
        PS_params.date_created_custom = [];
      } else if (mode == "del") {
        delete PS_params.date_created_preset;
        delete PS_params.date_created_custom;
      }
      break;

    case "filesize":
      if (mode == "add") {
        PS_params.filesize_min = "";
        PS_params.filesize_max = "";
      } else if (mode == "del") {
        delete PS_params.filesize_min;
        delete PS_params.filesize_max;
      }
      break;

    case "height":
      if (mode == "add") {
        PS_params.height_min = "";
        PS_params.height_max = "";
      } else if (mode == "del") {
        delete PS_params.height_min;
        delete PS_params.height_max;
      }
      break;

    case "width":
      if (mode == "add") {
        PS_params.width_min = "";
        PS_params.width_max = "";
      } else if (mode == "del") {
        delete PS_params.width_min;
        delete PS_params.width_max;
      }
      break;

    default:
      if (mode == "add") {
        PS_params[filterName] = "";
      } else if (mode == "del") {
        // eslint-disable-next-line @typescript-eslint/no-dynamic-delete -- a search-parameter bag keyed by the filter name chosen at runtime, serialised straight to the API; a Map would have to be converted back on every request.
        delete PS_params[filterName];
      }
      break;
  }
}

function reloadPage(url: string) {
  window.location.href = url;
}

function updateDateFilters(selector: string) {
  const ctx = document.querySelector(selector);
  if (ctx === null) {
    return;
  }
  const inputYear = find(ctx, ".year_input input");
  const iconYear = find(ctx, ".year_input .mcs-icon");
  const allMonth = children(find(ctx, ".months_container"));
  let yearIsCheck = false;

  // check year
  // year state
  // check => check mark
  // uncheck with children checked => outline check mark
  // uncheck without children checked => hide
  if (is(inputYear, ":checked")) {
    yearIsCheck = true;
    setDisabled(find(ctx, inputSelector(":not(:checked)")), true);
    removeClass(iconYear, "gallery-icon-check-outline grey-icon");
    addClass(iconYear, "gallery-icon-checkmark");
    show(iconYear);
  } else if (find(ctx, inputSelector(":checked")).length > 0) {
    setDisabled(find(ctx, inputSelector()), false);
    removeClass(iconYear, "gallery-icon-checkmark");
    addClass(iconYear, "gallery-icon-check-outline grey-icon");
    show(iconYear);
  } else {
    setDisabled(find(ctx, inputSelector()), false);
    removeClass(iconYear, "grey-icon");
    hide(iconYear);
  }

  // check month and his days
  // month state
  // check => check mark
  // uncheck with children check => outline check mark
  // uncheck with no children and year is checked => grey check mark
  // uncheck with no children => hide
  allMonth.forEach((monthEl) => {
    const monthInput = find(monthEl, ".month_input input");
    const iconMonth = find(monthEl, ".month_input .mcs-icon");
    const allDays = children(find(monthEl, ".days_container"));
    let monthIsChecked = false;

    if (is(monthInput, ":checked")) {
      monthIsChecked = true;
      setDisabled(find(allDays, inputSelector(":not(:checked)")), true);
      removeClass(iconMonth, "gallery-icon-check-outline grey-icon");
      addClass(iconMonth, "gallery-icon-checkmark");
      show(iconMonth);
    } else if (find(allDays, inputSelector(":checked")).length > 0) {
      removeClass(iconMonth, "gallery-icon-checkmark");
      addClass(iconMonth, "gallery-icon-check-outline grey-icon");
      show(iconMonth);
    } else if (yearIsCheck) {
      removeClass(iconMonth, "gallery-icon-check-outline");
      addClass(iconMonth, "gallery-icon-checkmark grey-icon");
      show(iconMonth);
    } else {
      removeClass(iconMonth, "grey-icon");
      hide(iconMonth);
    }

    // day state
    // check => check mark
    // uncheck with year or month checked => grey check mark
    // uncheck without year or month checked => hide
    allDays.forEach((dayEl) => {
      const inputDay = find(dayEl, "input");
      const iconDay = find(dayEl, ".mcs-icon");

      if (is(inputDay, ":checked")) {
        removeClass(iconDay, "grey-icon");
        show(iconDay);
      } else if (monthIsChecked || yearIsCheck) {
        addClass(iconDay, "grey-icon");
        show(iconDay);
      } else {
        removeClass(iconDay, "grey-icon");
        hide(iconDay);
      }
    });
  });
}

/**
 * Replace the filter_form elements if they exceed the window
 */
function resize_filter_form() {
  document.querySelectorAll(".form_mobile_arrow").forEach((el) => {
    el.remove();
  });
  document.querySelectorAll<HTMLElement>(".filter").forEach((filterEl) => {
    const window_width = windowWidth();
    const left_distance = offset(filterEl).left;
    const filterForm = find(filterEl, ".filter-form");
    const filterFormFirst = filterForm[0] as HTMLElement;
    const filter_form_width = innerWidth(filterFormFirst);
    const too_left = left_distance + innerWidth(filterEl) - filter_form_width;
    const is_desktop = window.matchMedia("(min-width: 600px)").matches;
    css(filterForm, "left", "0px");
    const margin_left = is_desktop ? 15 : 0;

    if (left_distance + filter_form_width > window_width) {
      const check_left = too_left < 0 ? Math.abs(too_left - margin_left) : 0;
      const mobile_marg = is_desktop ? 0 : 2;
      const replace_form_width =
        -filter_form_width + innerWidth(filterEl) + check_left - mobile_marg;
      css(filterForm, "left", replace_form_width + "px");
    }
    if (!is_desktop) {
      const left_arrow = offset(filterEl).left + innerWidth(filterEl) / 2;
      prepend(
        filterForm,
        '<svg width="10" height="10" viewBox="0 0 14 14" class="form_mobile_arrow" style="left:' +
          left_arrow +
          'px"><polygon class="arrow-border" points="7,0 14,14 0,14"/><polygon class="arrow-fill" points="7,1 13.5,14 0.5,14"/></svg>',
      );
    }
  });
}
on(window, "load", function () {
  resize_filter_form();
});
on(window, "resize", function () {
  resize_filter_form();
});

on(document.querySelectorAll(".help-popin-search"), "click", function () {
  fadeIn(document.querySelectorAll("#modalQuickSearch"));
});

on(document.querySelectorAll("#closeModalQuickSearch"), "click", function () {
  fadeOut(document.querySelectorAll("#modalQuickSearch"));
});
