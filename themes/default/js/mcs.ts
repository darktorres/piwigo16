import type { operations } from "../../../openapi/client/schema";
// Real consumer of album_selector.ts's own top-level `class
// AlbumSelector` (docs/PLAN.md P48 -- was a `window.AlbumSelector`
// read, see that file's own leading comment for the full real-consumer
// list).
import { AlbumSelector } from "./album_selector";
import { sprintf } from "./sprintf";
// This file has exactly one real registrant page (SearchFiltersView),
// but doubleSlider.ts itself has 2 real file-level consumers (this
// file and batch_manager/filter.ts, each its own separate Vite entry),
// so Rollup emits it as a shared chunk.
import { pwgDoubleSlider } from "./doubleSlider";
// Real consumer of searchFilters.ts's own exports (docs/PLAN.md P48,
// searchFilters.ts's own batch -- was bare-global reads before that).
// This file is searchFilters.ts's only real consumer
// anywhere (confirmed directly -- history.ts's own leading comment
// mentions `globalParams`/`fullnameOfCat` only in prose, not a real
// bare-identifier read), so a plain import is safe (Design §4) --
// searchFilters.ts's own former standalone entry/registration is gone
// too (SearchFiltersView's own leading comment).
//
// `globalParams` is read-only here, and deliberately so. It used to
// carry a second, parallel copy of the filter state: every widget
// handler wrote the chosen values into `globalParams.fields.*` right
// beside the `psParams.*` write next to it. Nothing ever read that
// copy back -- every `globalParams` read in this file runs in the
// setup pass below, before any handler can fire; `performSearch()`
// builds its request body out of `psParams` alone; and a search
// reloads the page, so the next read starts from a fresh
// server-rendered value either way. Those writes are gone (P49-A,
// typing pass), which also removed several that had drifted to shapes
// the server never produces -- `added_by` as `{mode, words}` where
// `SearchRules` says `list<int|string>`, `expert` as a bare string
// where it is `{string: ...}`, `allwords.words` as the raw input text
// where it is a word list. Add a read before adding a write back.
import {
  globalParams,
  fullnameOfCat,
  searchId,
  strWordWidgetLabel,
  strTagsWidgetLabel,
  strAlbumWidgetLabel,
  strAuthorWidgetLabel,
  strAddedByWidgetLabel,
  strFiletypesWidgetLabel,
  strRatingWidgetLabel,
  strNoRating,
  strBetweenRating,
  strFilesizeWidgetLabel,
  strWidthWidgetLabel,
  strHeightWidgetLabel,
  strRatioWidgetLabel,
  strRatiosLabel,
  strExpertWidgetLabel,
  strEmptySearchTopAlt,
  strEmptySearchBotAlt,
  strSearchInAb,
  prefixIcon,
  sliders,
  showFilterRatings,
} from "./searchFilters";
import { ajax, AjaxError } from "./vendor/utils/ajax";
import {
  getSelectizeInstance,
  selectize as createSelectize,
} from "./vendor/widgets/selectize";
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
} from "./vendor/utils/dom";

let psParams: Record<string, any> = {};

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
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- a real event's own target inside the document is always an Element (or null), never a bare EventTarget with no Element interface.
  const el = target as Element | null;
  if (el?.closest(".filter-form") != null) {
    return true;
  }
  return extraClasses.some((cls) => el?.classList.contains(cls) === true);
}

let ab: AlbumSelectorInstance;

function setupWordFilter(emptyFiltersList: any[]): void {
  // Setup word filter
  const allwordsRule = globalParams.fields.allwords;
  if (allwordsRule) {
    css(document.querySelectorAll(".filter-word"), "display", "flex");
    setChecked(
      document.querySelectorAll(".filter-manager-controller.word"),
      true,
    );

    const wordSearchWords = allwordsRule.words;
    const wordSearchStr = wordSearchWords.join(" ");
    setVal(document.querySelectorAll("#word-search"), wordSearchStr);

    if (wordSearchWords.length > 0) {
      addClass(document.querySelectorAll(".filter-word"), "filter-filled");
      html(
        document.querySelectorAll(".filter-word .search-words"),
        wordSearchStr,
      );
    } else {
      html(
        document.querySelectorAll(".filter-word .search-words"),
        strWordWidgetLabel,
      );
    }

    const wordSearchFields = allwordsRule.fields;
    wordSearchFields.forEach((field_name) => {
      setChecked(document.querySelectorAll("#" + field_name), true);
    });

    const wordSearchMode = allwordsRule.mode;
    setChecked(
      document.querySelectorAll(
        `.word-search-options input[value="${wordSearchMode}"]`,
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

    psParams["allwords"] = wordSearchStr;
    psParams["allwords_fields"] = wordSearchFields;
    psParams["allwords_mode"] = wordSearchMode;

    emptyFiltersList.push(psParams["allwords"]);
  }
}

function setupTagFilter(emptyFiltersList: any[]): void {
  // Setup tag filter
  const tagsRule = globalParams.fields.tags;
  document.querySelectorAll<HTMLSelectElement>("#tag-search").forEach((el) => {
    createSelectize(el, {
      plugins: ["remove_button"],
      maxOptions: el.querySelectorAll("option").length,
      items: tagsRule?.words,
    });
  });

  if (tagsRule) {
    css(document.querySelectorAll(".filter-tag"), "display", "flex");
    setChecked(
      document.querySelectorAll(".filter-manager-controller.tags"),
      true,
    );
    setChecked(
      document.querySelectorAll(
        `.filter-tag-form .search-params input[value="${tagsRule.mode}"]`,
      ),
      true,
    );

    const tagSearchEl =
      document.querySelector<HTMLSelectElement>("#tag-search")!;
    const tagSearchSelectize = getSelectizeInstance(tagSearchEl)!;
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- #tag-search is a real <select multiple> (search_filters.inc.latte), so getValue() always returns an array here.
    const tagSearchStr = (tagSearchSelectize.getValue() as (string | number)[])
      .map((id) =>
        (tagSearchSelectize.getItem(id)?.textContent ?? "")
          .replace(/\(\d+ \w+\)×/, "")
          .trim(),
      )
      .join(", ");
    if (tagsRule.words.length > 0) {
      addClass(document.querySelectorAll(".filter-tag"), "filter-filled");
      text(
        document.querySelectorAll(".filter.filter-tag .search-words"),
        tagSearchStr,
      );
    } else {
      text(
        document.querySelectorAll(".filter.filter-tag .search-words"),
        strTagsWidgetLabel,
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

    psParams["tags"] = tagsRule.words.length > 0 ? tagsRule.words : "";
    psParams["tags_mode"] = tagsRule.mode;

    emptyFiltersList.push(psParams["tags"]);
  }
}

function setupDatePostedFilter(emptyFiltersList: any[]): void {
  // Setup Date post filter
  const datePostedRule = globalParams.fields.date_posted;
  if (datePostedRule) {
    css(document.querySelectorAll(".filter-date_posted"), "display", "flex");
    setChecked(
      document.querySelectorAll(".filter-manager-controller.date_posted"),
      true,
    );

    if (datePostedRule.preset !== "") {
      // If filter is used and not empty check preset date option
      setChecked(
        document.querySelectorAll("#date_posted-" + datePostedRule.preset),
        true,
      );
      // `label#" + datePostedRule.preset` is an ID selector fragment,
      // and preset can be a bare digit-leading value too (e.g. "30d",
      // not just the "custom" sub-case below) -- same escapeId() fix as
      // that branch, same reason.
      let datePostedStr = textOf(
        document.querySelectorAll(
          `.date_posted-option label#${escapeId(datePostedRule.preset)} .date-period`,
        ),
      );

      // if option is custom check custom dates
      if ("custom" === datePostedRule.preset) {
        datePostedStr = "";
        const customArray = datePostedRule.custom;

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
          datePostedStr += textOf(
            document.querySelectorAll(
              `.date_posted-option label#${escapeId(customValue)} .date-period`,
            ),
          );

          if (customArray.length > 1 && index !== customArray.length - 1) {
            datePostedStr += ", ";
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
        datePostedStr,
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
        if ("year" === data(this, "type")) {
          toggle(
            find(clickedOption.parentElement!, ".date_posted-option.month"),
          );
        } else if ("month" === data(this, "type")) {
          toggle(find(clickedOption.parentElement!, ".date_posted-option.day"));
        }
      },
    );

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

    psParams["date_posted_preset"] = datePostedRule.preset;
    // Was `custom != ""`, which compared the array against a string:
    // `[].toString()` is `""`, so it read as an emptiness test by way of
    // loose equality. `length > 0` states that directly. The one input
    // the two disagree on is `[""]`, which no producer can build (every
    // entry carries a one-character type prefix).
    psParams["date_posted_custom"] =
      datePostedRule.custom.length > 0 ? datePostedRule.custom : "";

    emptyFiltersList.push(psParams["date_posted_preset"]);
    emptyFiltersList.push(psParams["date_posted_custom"]);
  }
}

function setupDateCreatedFilter(emptyFiltersList: any[]): void {
  // Setup Date creation filter

  const dateCreatedRule = globalParams.fields.date_created;
  if (dateCreatedRule) {
    css(document.querySelectorAll(".filter-date_created"), "display", "flex");
    setChecked(
      document.querySelectorAll(".filter-manager-controller.date_created"),
      true,
    );

    if (dateCreatedRule.preset !== "") {
      // If filter is used and not empty check preset date option
      setChecked(
        document.querySelectorAll("#date_created-" + dateCreatedRule.preset),
        true,
      );
      // Same digit-leading-preset bug as the date_posted block above
      // (e.g. preset "30d"), same escapeId() fix.
      let dateCreatedStr = textOf(
        document.querySelectorAll(
          `.date_created-option label#${escapeId(dateCreatedRule.preset)} .date-period`,
        ),
      );

      // if option is custom check custom dates
      if ("custom" === dateCreatedRule.preset) {
        dateCreatedStr = "";
        const customArray = dateCreatedRule.custom;

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
          dateCreatedStr += textOf(
            document.querySelectorAll(
              `.date_created-option label#${escapeId(customValue)} .date-period`,
            ),
          );

          if (customArray.length > 1 && index !== customArray.length - 1) {
            dateCreatedStr += ", ";
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
        dateCreatedStr,
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
        if ("year" === data(this, "type")) {
          toggle(
            find(clickedOption.parentElement!, ".date_created-option.month"),
          );
        } else if ("month" === data(this, "type")) {
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

    psParams["date_created_preset"] = dateCreatedRule.preset;
    // Same `custom != ""` rewrite as the date_posted block above.
    psParams["date_created_custom"] =
      dateCreatedRule.custom.length > 0 ? dateCreatedRule.custom : "";

    emptyFiltersList.push(psParams["date_created_preset"]);
    emptyFiltersList.push(psParams["date_created_custom"]);
  }
}

function setupAlbumFilter(emptyFiltersList: any[]): void {
  // Setup album filter
  const catRule = globalParams.fields.cat;
  if (catRule) {
    css(document.querySelectorAll(".filter-album"), "display", "flex");
    setChecked(
      document.querySelectorAll(".filter-manager-controller.album"),
      true,
    );

    catRule.words.forEach((cat_id) => {
      displayRelatedCategory(cat_id, fullnameOfCat[cat_id]);
    });
    const albumWidgetValue = catRule.words
      .map((cat_id) => fullnameOfCat[cat_id] ?? "")
      .join(", ");

    // Load Album Selector
    ab = new AlbumSelector({
      selectedCategoriesIds: catRule.words,
      selectAlbum: addRelatedCategory,
      removeSelectedAlbum: removeRelatedCategory,
      modalTitle: strSearchInAb,
    });

    on(document.querySelectorAll(".add-album-button"), "click", function () {
      ab.open();
    });

    on(
      document.querySelectorAll(".selected-categories-container"),
      "click",
      function (e: Event) {
        // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- a real click inside the document always targets an Element (or null), never a bare EventTarget with no Element interface.
        const target = e.target as Element;
        if (target.classList.contains("remove-item")) {
          ab.removeSelectedAlbum(target.id);
        }
      },
    );

    if (catRule.words.length > 0) {
      addClass(document.querySelectorAll(".filter-album"), "filter-filled");
      html(
        document.querySelectorAll(".filter-album .search-words"),
        albumWidgetValue,
      );
    } else {
      html(
        document.querySelectorAll(".filter-album .search-words"),
        strAlbumWidgetLabel,
      );
    }

    if (catRule.sub_inc) {
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

    psParams["categories"] = catRule.words.length > 0 ? catRule.words : "";
    psParams["categories_withsubs"] = catRule.sub_inc;

    emptyFiltersList.push(psParams["categories"]);
  }
}

function setupAuthorFilter(emptyFiltersList: any[]): void {
  // Setup author filter
  const authorRule = globalParams.fields.author;
  document.querySelectorAll<HTMLSelectElement>("#authors").forEach((el) => {
    createSelectize(el, {
      plugins: ["remove_button"],
      maxOptions: el.querySelectorAll("option").length,
      items: authorRule?.words,
    });
    if (authorRule) {
      css(document.querySelectorAll(".filter-authors"), "display", "flex");
      setChecked(
        document.querySelectorAll(".filter-manager-controller.author"),
        true,
      );

      const authorsSelectize = getSelectizeInstance(el)!;
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- #authors is a real <select multiple> (search_filters.inc.latte), so getValue() always returns an array here.
      const authorIds = authorsSelectize.getValue() as (string | number)[];
      const authorSearchStr = authorIds
        .map((id) =>
          (authorsSelectize.getItem(id)?.textContent ?? "")
            .replace(/\(\d+ \w+\)×/, "")
            .trim(),
        )
        .join(", ");

      if (authorRule.words.length > 0) {
        addClass(document.querySelectorAll(".filter-authors"), "filter-filled");
        text(
          document.querySelectorAll(".filter.filter-authors .search-words"),
          authorSearchStr,
        );
      } else {
        text(
          document.querySelectorAll(".filter.filter-authors .search-words"),
          strAuthorWidgetLabel,
        );
      }

      on(
        document.querySelectorAll(".filter-authors .filter-actions .clear"),
        "click",
        function () {
          getSelectizeInstance(el)?.clear();
        },
      );

      psParams["authors"] = authorRule.words.length > 0 ? authorRule.words : "";

      emptyFiltersList.push(psParams["authors"]);
    }
  });
}

function setupAddedByFilter(emptyFiltersList: any[]): void {
  // Setup added_by filter
  const addedByIds = globalParams.fields.added_by;
  if (addedByIds) {
    css(document.querySelectorAll(".filter-added_by"), "display", "flex");
    setChecked(
      document.querySelectorAll(".filter-manager-controller.added_by"),
      true,
    );

    if (addedByIds.length > 0) {
      addClass(document.querySelectorAll(".filter-added_by"), "filter-filled");

      const addedByNames: string[] = [];

      document.querySelectorAll(".added_by-option").forEach((el) => {
        const input = find(el, "input");
        const addedById = parseInt(String(attrOf(input, "name")));

        if (addedByIds.includes(addedById)) {
          setChecked(input, true);
          addedByNames.push(textOf(find(el, ".added_by-name")));
        }
      });

      text(
        document.querySelectorAll(".filter.filter-added_by .search-words"),
        addedByNames.join(", "),
      );
    } else {
      text(
        document.querySelectorAll(".filter.filter-added_by .search-words"),
        strAddedByWidgetLabel,
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

    psParams["added_by"] = addedByIds.length > 0 ? addedByIds : "";

    emptyFiltersList.push(psParams["added_by"]);
  }
}

function setupFiletypesFilter(emptyFiltersList: any[]): void {
  // Setup filetypes filter
  const filetypesFilter = globalParams.fields.filetypes;
  if (filetypesFilter) {
    css(document.querySelectorAll(".filter-filetypes"), "display", "flex");
    setChecked(
      document.querySelectorAll(".filter-manager-controller.filetypes"),
      true,
    );

    const filetypesSearchStr = filetypesFilter.join(", ");

    if (filetypesFilter.length > 0) {
      addClass(document.querySelectorAll(".filter-filetypes"), "filter-filled");
      text(
        document.querySelectorAll(".filter.filter-filetypes .search-words"),
        filetypesSearchStr.toUpperCase(),
      );

      // `?? ""` for a name-less input: no filter list ever holds the
      // empty string, so it misses exactly as the old `undefined` did.
      document.querySelectorAll(".filetypes-option input").forEach((el) => {
        if (filetypesFilter.includes(attrOf(el, "name") ?? "")) {
          setChecked(el, true);
        }
      });
    } else {
      text(
        document.querySelectorAll(".filter.filter-filetypes .search-words"),
        strFiletypesWidgetLabel,
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

    psParams["filetypes"] = filetypesFilter.length > 0 ? filetypesFilter : "";

    emptyFiltersList.push(psParams["filetypes"]);
  }
}

function setupRatiosFilter(emptyFiltersList: any[]): void {
  // Setup Ratio filter
  const ratiosFilter = globalParams.fields.ratios;
  if (ratiosFilter) {
    css(document.querySelectorAll(".filter-ratios"), "display", "flex");
    setChecked(
      document.querySelectorAll(".filter-manager-controller.ratios"),
      true,
    );

    const ratiosSearchStr = ratiosFilter
      .map((ft) => strRatiosLabel[ft] ?? "")
      .join(", ");

    if (ratiosFilter.length > 0) {
      addClass(document.querySelectorAll(".filter-ratios"), "filter-filled");
      text(
        document.querySelectorAll(".filter.filter-ratios .search-words"),
        ratiosSearchStr,
      );

      document.querySelectorAll(".ratios-option input").forEach((el) => {
        if (ratiosFilter.includes(attrOf(el, "name") ?? "")) {
          setChecked(el, true);
        }
      });
    } else {
      text(
        document.querySelectorAll(".filter.filter-ratios .search-words"),
        strRatioWidgetLabel,
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

    psParams["ratios"] = ratiosFilter.length > 0 ? ratiosFilter : "";

    emptyFiltersList.push(psParams["ratios"]);
  }
}

function setupRatingsFilter(emptyFiltersList: any[]): void {
  // Setup rating filter
  const ratingsFilter = globalParams.fields.ratings;
  if (ratingsFilter && showFilterRatings) {
    css(document.querySelectorAll(".filter-ratings"), "display", "flex");
    setChecked(
      document.querySelectorAll(".filter-manager-controller.ratings"),
      true,
    );

    let ratingsSearchStr = "";
    // Entries are strings, not numbers (`SearchRules::toArray()` emits
    // `list<string>`, and the API rejects a non-string entry with a
    // 422) -- the `0 ==` test and the `- 1` below were both running on
    // JS's own coercion, which `Number()` now states outright. The
    // label itself keeps using the raw entry, exactly as before.
    ratingsFilter.forEach((rating, i) => {
      if (Number(rating) === 0) {
        ratingsSearchStr += strNoRating;
        if (ratingsFilter.length > 1) {
          ratingsSearchStr += ", ";
        }
      } else {
        const strBetween = strBetweenRating.split("%d");
        ratingsSearchStr +=
          strBetween[0]! +
          String(Number(rating) - 1) +
          strBetween[1]! +
          rating +
          strBetween[2]!;
        if (ratingsFilter.length - 1 !== i) {
          ratingsSearchStr += ", ";
        }
      }
    });

    if (ratingsFilter.length > 0) {
      addClass(document.querySelectorAll(".filter-ratings"), "filter-filled");
      text(
        document.querySelectorAll(".filter.filter-ratings .search-words"),
        ratingsSearchStr,
      );

      document.querySelectorAll(".ratings-option input").forEach((el) => {
        if (ratingsFilter.includes(attrOf(el, "name") ?? "")) {
          setChecked(el, true);
        }
      });
    } else {
      text(
        document.querySelectorAll(".filter.filter-ratings .search-words"),
        strRatingWidgetLabel,
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

    psParams["ratings"] = ratingsFilter.length > 0 ? ratingsFilter : "";

    emptyFiltersList.push(psParams["ratings"]);
  }
}

function setupFilesizeFilter(emptyFiltersList: any[]): void {
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
    globalParams.fields.filesize_min != null &&
    globalParams.fields.filesize_max != null
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
      // These bounds are `int|string` server-side (`SearchRules::
      // $filesizeMin` & co). `Number()` is what `> 0` was already doing
      // to a string operand -- a relational comparison against a number
      // coerces via ToNumber -- just written out.
      Number(globalParams.fields.filesize_max) > 0
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
        strFilesizeWidgetLabel,
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
            strFilesizeWidgetLabel,
          );
        }
      },
    );

    psParams["filesize_min"] = globalParams.fields.filesize_min ?? "";
    psParams["filesize_max"] = globalParams.fields.filesize_max ?? "";

    emptyFiltersList.push(psParams["filesize_min"]);
    emptyFiltersList.push(psParams["filesize_max"]);
  }
}

function setupHeightFilter(emptyFiltersList: any[]): void {
  // Setup Height filter
  if (
    globalParams.fields.height_min != null &&
    globalParams.fields.height_max != null
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
      Number(globalParams.fields.height_min) > 0 &&
      Number(globalParams.fields.height_max) > 0
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
        strHeightWidgetLabel,
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
            strHeightWidgetLabel,
          );
        }
      },
    );

    psParams["height_min"] = globalParams.fields.height_min ?? "";
    psParams["height_max"] = globalParams.fields.height_max ?? "";

    emptyFiltersList.push(psParams["height_min"]);
    emptyFiltersList.push(psParams["height_max"]);
  }
}

function setupWidthFilter(emptyFiltersList: any[]): void {
  // Setup Width filter
  if (
    globalParams.fields.width_min != null &&
    globalParams.fields.width_max != null
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
      Number(globalParams.fields.width_min) > 0 &&
      Number(globalParams.fields.width_max) > 0
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
        strWidthWidgetLabel,
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
            strWidthWidgetLabel,
          );
        }
      },
    );

    psParams["width_min"] = globalParams.fields.width_min ?? "";
    psParams["width_max"] = globalParams.fields.width_max ?? "";

    emptyFiltersList.push(psParams["width_min"]);
    emptyFiltersList.push(psParams["width_max"]);
  }
}

function setupExpertFilter(emptyFiltersList: any[]): void {
  // Setup Expert filter
  const expertRule = globalParams.fields.expert;
  if (expertRule) {
    css(document.querySelectorAll(".filter-expert"), "display", "flex");
    setChecked(
      document.querySelectorAll(".filter-manager-controller.expert"),
      true,
    );

    const expertSearchStr = expertRule.string;
    setVal(document.querySelectorAll("#expert-search"), expertSearchStr);

    if (expertSearchStr.length > 0) {
      addClass(document.querySelectorAll(".filter-expert"), "filter-filled");
      text(
        document.querySelectorAll(".filter.filter-expert .search-words"),
        expertSearchStr,
      );
    } else {
      text(
        document.querySelectorAll(".filter.filter-expert .search-words"),
        strExpertWidgetLabel,
      );
    }

    on(
      document.querySelectorAll(".filter-expert .filter-actions .clear"),
      "click",
      function () {
        setVal(document.querySelectorAll(".filter-expert #expert-search"), "");
      },
    );

    psParams["expert"] = expertSearchStr.length > 0 ? expertSearchStr : "";

    emptyFiltersList.push(psParams["expert"]);
  }
}

function finalizeFilterSetup(
  emptyFiltersList: any[],
  filtersToRemove: string[],
): void {
  if (filtersToRemove.length > 0) {
    void performSearch(psParams, true);
  }

  // Adapt no result message
  if (document.querySelectorAll(".filter-filled").length === 0) {
    html(
      document.querySelectorAll(".mcs-no-result .text .top"),
      strEmptySearchTopAlt,
    );
    html(
      document.querySelectorAll(".mcs-no-result .text .bot"),
      strEmptySearchBotAlt,
    );
  }

  if (
    !emptyFiltersList.every(
      (param) => param === "" || param === null || typeof param === "undefined",
    )
  ) {
    addClass(document.querySelectorAll(".clear-all"), "clickable");
    on(document.querySelectorAll(".clear-all.clickable"), "click", function () {
      const excludeParams = [
        "searchId",
        "allwords_mode",
        "allwords_fields",
        "tags_mode",
        "categories_withsubs",
      ];
      for (const key in psParams) {
        if (!excludeParams.includes(key)) {
          if ("date_posted_custom" === key || "date_created_custom" === key) {
            psParams[key] = [];
          } else {
            psParams[key] = "";
          }
        }
      }
      void performSearch(psParams, true);
    });
  }
}

function wireFilterManagerPopin(): void {
  /**
   * Filter Manager
   */
  on(document.querySelectorAll(".filter-manager"), "click", function () {
    show(document.querySelectorAll(".filter-manager-popin"));
  });

  on(document, "keyup", function (e: Event) {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- "keyup" always dispatches a real KeyboardEvent; on()'s own handler param is typed generically via the native EventListener interface.
    const { key } = e as KeyboardEvent;
    if (key === "Escape") {
      trigger(
        document.querySelectorAll(
          ".filter-manager-popin .filter-manager-close",
        ),
        "click",
      );
      trigger(document.querySelectorAll("#closeModalQuickSearch"), "click");
    }
    if (key === "Enter") {
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
          // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- data() reads a data-* attribute this same app's own setData()/template writes, never adversarial input.
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
          // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- data() reads a data-* attribute this same app's own setData()/template writes, never adversarial input.
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
      void performSearch(psParams, true);
    },
  );
}

function wireTagsAlbumsFoundPopins(): void {
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
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- "keyup" always dispatches a real KeyboardEvent; on()'s own handler param is typed generically via the native EventListener interface.
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
}

function wireWordFilterInteractions(): void {
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

          psParams["allwords"] = val(document.querySelectorAll("#word-search"));
          psParams["allwords_mode"] = attrOf(
            document.querySelectorAll(".word-search-options input:checked"),
            "value",
          );

          const newFields: (string | undefined)[] = [];
          document
            .querySelectorAll(".filter-word-form .search-params input:checked")
            .forEach((el) => {
              newFields.push(attrOf(el, "name") ?? undefined);
            });
          psParams["allwords_fields"] = newFields.length > 0 ? newFields : "";
        }
      },
    );
  });
  on(
    document.querySelectorAll(".filter-word .filter-validate"),
    "click",
    function () {
      trigger(document.querySelectorAll(".filter-word"), "click");
      void performSearch(psParams, true);
    },
  );
  on(
    document.querySelectorAll(".filter-word .filter-actions .delete"),
    "click",
    function () {
      updateFilters("word", "del");
      void performSearch(psParams, true);
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
}

function wireTagFilterInteractions(): void {
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
            // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- #tag-search is a real <select multiple> (search_filters.inc.latte), so getValue() always returns an array here.
            const tagValue = getSelectizeInstance(
              document.querySelector<HTMLSelectElement>("#tag-search")!,
            )!.getValue() as (string | number)[];
            psParams["tags"] = tagValue.length > 0 ? tagValue : "";
          }
          psParams["tags_mode"] = val(
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
      void performSearch(psParams, true);
    },
  );
  on(
    document.querySelectorAll(".filter-tag .filter-actions .delete"),
    "click",
    function () {
      updateFilters("tag", "del");
      void performSearch(psParams, true);
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
}

function wireDatePostedFilterInteractions(): void {
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

            psParams["date_posted_preset"] = presetValue ?? "";

            if ("custom" === presetValue) {
              const customDates: (string | number | string[] | undefined)[] =
                [];

              document
                .querySelectorAll(
                  ".custom_posted_date .date_posted-option input:checked",
                )
                .forEach((el) => {
                  customDates.push(val([el]));
                });

              psParams["date_posted_custom"] =
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
      void performSearch(psParams, true);
    },
  );

  on(
    document.querySelectorAll(".filter-date_posted .filter-actions .delete"),
    "click",
    function () {
      updateFilters("date_posted", "del");
      void performSearch(psParams, true);
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
}

function wireDateCreatedFilterInteractions(): void {
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

            psParams["date_created_preset"] = presetValue ?? "";

            if ("custom" === presetValue) {
              const customDates: (string | number | string[] | undefined)[] =
                [];

              document
                .querySelectorAll(
                  ".custom_created_date .date_created-option input:checked",
                )
                .forEach((el) => {
                  customDates.push(val([el]));
                });

              psParams["date_created_custom"] =
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
      void performSearch(psParams, true);
    },
  );

  on(
    document.querySelectorAll(".filter-date_created .filter-actions .delete"),
    "click",
    function () {
      updateFilters("date_created", "del");
      void performSearch(psParams, true);
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
}

function wireAlbumFilterInteractions(): void {
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
          psParams["categories"] =
            ab.getSelectedAlbums().length > 0 ? ab.getSelectedAlbums() : "";
          psParams["categories_withsubs"] =
            document.querySelectorAll("input[name='search-sub-cats']:checked")
              .length !== 0;
        }
      },
    );
  });
  on(
    document.querySelectorAll(".filter-album .filter-validate"),
    "click",
    function () {
      trigger(document.querySelectorAll(".filter-album"), "click");
      void performSearch(psParams, true);
    },
  );
  on(
    document.querySelectorAll(".filter-album .filter-actions .delete"),
    "click",
    function () {
      updateFilters("album", "del");
      void performSearch(psParams, true);
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
}

function wireAuthorFilterInteractions(): void {
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
              // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- #authors is a real <select multiple> (search_filters.inc.latte), so getValue() always returns an array here.
              const authorValue = getSelectizeInstance(
                document.querySelector<HTMLSelectElement>("#authors")!,
              )!.getValue() as (string | number)[];
              psParams["authors"] = authorValue.length > 0 ? authorValue : "";
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
      void performSearch(psParams, true);
    },
  );
  on(
    document.querySelectorAll(".filter-authors .filter-actions .delete"),
    "click",
    function () {
      updateFilters("authors", "del");
      void performSearch(psParams, true);
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
}

function wireAddedByFilterInteractions(): void {
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

            const addedByArray: (string | undefined)[] = [];
            document
              .querySelectorAll(".added_by-option input:checked")
              .forEach((el) => {
                addedByArray.push(attrOf(el, "name") ?? undefined);
              });

            psParams["added_by"] = addedByArray.length > 0 ? addedByArray : "";
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
      void performSearch(psParams, true);
    },
  );
  on(
    document.querySelectorAll(".filter-added_by .filter-actions .delete"),
    "click",
    function () {
      updateFilters("added_by", "del");
      void performSearch(psParams, true);
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
}

function wireFiletypesFilterInteractions(): void {
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

            const filetypesArray: (string | undefined)[] = [];
            document
              .querySelectorAll(".filetypes-option input:checked")
              .forEach((el) => {
                filetypesArray.push(attrOf(el, "name") ?? undefined);
              });

            psParams["filetypes"] =
              filetypesArray.length > 0 ? filetypesArray : "";
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
      void performSearch(psParams, true);
    },
  );
  on(
    document.querySelectorAll(".filter-filetypes .filter-actions .delete"),
    "click",
    function () {
      updateFilters("filetypes", "del");
      void performSearch(psParams, true);
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
}

function wireRatiosFilterInteractions(): void {
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

          const ratiosArray: (string | undefined)[] = [];
          document
            .querySelectorAll(".ratios-option input:checked")
            .forEach((el) => {
              ratiosArray.push(attrOf(el, "name") ?? undefined);
            });

          psParams["ratios"] = ratiosArray.length > 0 ? ratiosArray : "";
        }
      },
    );
  });

  on(
    document.querySelectorAll(".filter-ratios .filter-validate"),
    "click",
    function () {
      trigger(document.querySelectorAll(".filter-ratios"), "click");
      void performSearch(psParams, true);
    },
  );
  on(
    document.querySelectorAll(".filter-ratios .filter-actions .delete"),
    "click",
    function () {
      updateFilters("ratios", "del");
      void performSearch(psParams, true);
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
}

function wireRatingsFilterInteractions(): void {
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
            const ratingsArray: (string | undefined)[] = [];

            document
              .querySelectorAll(".ratings-option input:checked")
              .forEach((el) => {
                ratingsArray.push(attrOf(el, "name") ?? undefined);
              });

            psParams["ratings"] = ratingsArray.length > 0 ? ratingsArray : "";
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
      void performSearch(psParams, true);
    },
  );
  on(
    document.querySelectorAll(".filter-ratings .filter-actions .delete"),
    "click",
    function () {
      updateFilters("ratings", "del");
      void performSearch(psParams, true);
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
}

function wireFilesizeFilterInteractions(): void {
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
      const filesizeMin = Math.floor(
        Number(
          val(document.querySelectorAll("input[name=filter_filesize_min]")),
        ) * 1024,
      );
      const filesizeMax = Math.ceil(
        Number(
          val(document.querySelectorAll("input[name=filter_filesize_max]")),
        ) * 1024,
      );

      psParams["filesize_min"] = filesizeMin;
      psParams["filesize_max"] = filesizeMax;

      trigger(document.querySelectorAll(".filter-filesize"), "click");
      void performSearch(psParams, true);
    },
  );

  on(
    document.querySelectorAll(".filter-filesize .filter-actions .delete"),
    "click",
    function () {
      updateFilters("filesize", "del");
      void performSearch(psParams, true);
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
}

function wireHeightFilterInteractions(): void {
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
      const heightMin = val(
        document.querySelectorAll("input[name=filter_height_min]"),
      );
      const heightMax = val(
        document.querySelectorAll("input[name=filter_height_max]"),
      );

      psParams["height_min"] = heightMin;
      psParams["height_max"] = heightMax;

      trigger(document.querySelectorAll(".filter-height"), "click");
      void performSearch(psParams, true);
    },
  );

  on(
    document.querySelectorAll(".filter-height .filter-actions .delete"),
    "click",
    function () {
      updateFilters("height", "del");
      void performSearch(psParams, true);
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
}

function wireWidthFilterInteractions(): void {
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
      const widthMin = val(
        document.querySelectorAll("input[name=filter_width_min]"),
      );
      const widthMax = val(
        document.querySelectorAll("input[name=filter_width_max]"),
      );

      psParams["width_min"] = widthMin;
      psParams["width_max"] = widthMax;

      trigger(document.querySelectorAll(".filter-width"), "click");
      void performSearch(psParams, true);
    },
  );

  on(
    document.querySelectorAll(".filter-width .filter-actions .delete"),
    "click",
    function () {
      updateFilters("width", "del");
      void performSearch(psParams, true);
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
}

function wireExpertFilterInteractions(): void {
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

          psParams["expert"] = val(document.querySelectorAll("#expert-search"));
        }
      },
    );
  });

  on(
    document.querySelectorAll(".filter-expert .filter-validate"),
    "click",
    function () {
      trigger(document.querySelectorAll(".filter-expert"), "click");
      void performSearch(psParams, true);
    },
  );
  on(
    document.querySelectorAll(".filter-expert .filter-actions .delete"),
    "click",
    function () {
      updateFilters("expert", "del");
      void performSearch(psParams, true);
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
}

ready(function () {
  // Genuinely heterogeneous -- pushed values are `psParams[key]`
  // (`psParams: Record<string, any>`, this campaign's own
  // "Record<string, any> only where genuinely heterogeneous"
  // allowance), whose real shape varies per filter (string, array, or
  // number depending on which filter pushed it).
  const emptyFiltersList: any[] = [];
  // Confirmed via grep: never pushed to anywhere in this file, so
  // `filtersToRemove.length > 0` (below) is always false and
  // `performSearch(psParams, true)` there never actually runs -- a
  // real, pre-existing dead branch, not something this typing pass
  // fixes (deciding what should populate it is a design question, not
  // a type gap). Typed to its most plausible intended shape (filter
  // name strings, matching `updateFilters()`'s own `filterName` param)
  // rather than left as `any[]`.
  const filtersToRemove: string[] = [];

  addClass(
    document.querySelectorAll(".linkedAlbumPopInContainer .ClosePopIn"),
    prefixIcon + "cancel",
  );
  addClass(
    document.querySelectorAll(".linkedAlbumPopInContainer .searching"),
    prefixIcon + "spin6",
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
  psParams = {};
  psParams["searchId"] = searchId;

  setupWordFilter(emptyFiltersList);

  //Hide filter spinner
  hide(document.querySelectorAll(".filter-spinner"));

  setupTagFilter(emptyFiltersList);
  setupDatePostedFilter(emptyFiltersList);
  setupDateCreatedFilter(emptyFiltersList);
  setupAlbumFilter(emptyFiltersList);
  setupAuthorFilter(emptyFiltersList);
  setupAddedByFilter(emptyFiltersList);
  setupFiletypesFilter(emptyFiltersList);
  setupRatiosFilter(emptyFiltersList);
  setupRatingsFilter(emptyFiltersList);
  setupFilesizeFilter(emptyFiltersList);
  setupHeightFilter(emptyFiltersList);
  setupWidthFilter(emptyFiltersList);
  setupExpertFilter(emptyFiltersList);

  finalizeFilterSetup(emptyFiltersList, filtersToRemove);
  wireFilterManagerPopin();
  wireTagsAlbumsFoundPopins();

  wireWordFilterInteractions();
  wireTagFilterInteractions();
  wireDatePostedFilterInteractions();
  wireDateCreatedFilterInteractions();
  wireAlbumFilterInteractions();
  wireAuthorFilterInteractions();
  wireAddedByFilterInteractions();
  wireFiletypesFilterInteractions();
  wireRatiosFilterInteractions();
  wireRatingsFilterInteractions();
  wireFilesizeFilterInteractions();
  wireHeightFilterInteractions();
  wireWidthFilterInteractions();
  wireExpertFilterInteractions();
});

async function performSearch(
  params: Record<string, any>,
  reload = false,
): Promise<void> {
  // psParams uses snake_case field names (also used elsewhere in this
  // file to drive the active-filter-chip UI) -- translated to
  // POST /api/v1/images/searches's camelCase body shape here, the one
  // place that actually sends it. `expert` has no equivalent on this
  // endpoint, so it's not sent.
  const body: Record<string, any> = {
    allwords: params["allwords"],
    allwordsFields: params["allwords_fields"],
    allwordsMode: params["allwords_mode"],
    tags: params["tags"],
    tagsMode: params["tags_mode"],
    datePostedPreset: params["date_posted_preset"],
    datePostedCustom: params["date_posted_custom"],
    dateCreatedPreset: params["date_created_preset"],
    dateCreatedCustom: params["date_created_custom"],
    categories: params["categories"],
    categoriesWithsubs: params["categories_withsubs"],
    authors: params["authors"],
    addedBy: params["added_by"],
    filetypes: params["filetypes"],
    ratios: params["ratios"],
    ratings: params["ratings"],
  };
  const hasSearchId = Boolean(params["searchId"]);
  if (hasSearchId) {
    body["searchId"] = params["searchId"];
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

  try {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/utils/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
    const response = (await ajax({
      url: "api/v1/images/searches",
      type: "POST",
      json: body,
      dataType: "json",
    })) as operations["imageFilteredSearchCreate"]["responses"][201]["content"]["application/json"];

    if (reload && typeof response.searchUrl !== "undefined") {
      reloadPage(response.searchUrl);
    }
  } catch (e) {
    console.error(e instanceof AjaxError ? e.responseText : e);
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
    removeClass(removeFilterEls, prefixIcon + "spin6 animate-spin");
    addClass(removeFilterEls, prefixIcon + "cancel");
  }
}

function addRelatedCategory({
  album,
  addSelectedAlbum,
}: AlbumSelectorCallbackArgs) {
  displayRelatedCategory(album.id, album.name);
  append(
    document.querySelectorAll(".invisible-related-categories-select"),
    `<option selected value="${album.id}"></option>`,
  );
  addSelectedAlbum();
}

function removeRelatedCategory({ id_album }: AlbumSelectorRemoveCallbackArgs) {
  // `id_album` is a raw category id (e.g. "42"), written below as this
  // element's own bare `id` attribute -- digit-leading, so it needs
  // escapeId() under native querySelector (Sizzle tolerated it
  // unescaped).
  document.querySelector("#" + escapeId(id_album))?.parentElement?.remove();
}

function displayRelatedCategory(
  cat_id: string | number,
  cat_link_path: string | undefined,
) {
  append(
    document.querySelectorAll(".selected-categories-container"),
    `<div class="breadcrumb-item">
      <span class="link-path">${cat_link_path ?? ""}</span><span id="${cat_id}" class="mcs-icon ${prefixIcon}cancel remove-item"></span>
    </div>`,
  );
}

type FilterResetField = [key: string, addValue: unknown];

// Each real filter resets 1-3 `psParams` fields to their own "empty"
// value on "add" (a filter freshly checked in the filter-manager
// popin), or drops them entirely on "del" (the filter's own X button)
// -- same field lists as `updateFilters()`'s own former switch, kept
// as data instead of 9 near-identical if/else-per-case branches.
const FILTER_RESET_FIELDS: Record<string, FilterResetField[]> = {
  word: [
    ["allwords", ""],
    ["allwords_mode", "AND"],
    ["allwords_fields", []],
  ],
  tag: [
    ["tags", ""],
    ["tags_mode", "AND"],
  ],
  album: [
    ["categories", ""],
    ["categories_withsubs", false],
  ],
  date_posted: [
    ["date_posted_preset", ""],
    ["date_posted_custom", []],
  ],
  date_created: [
    ["date_created_preset", ""],
    ["date_created_custom", []],
  ],
  filesize: [
    ["filesize_min", ""],
    ["filesize_max", ""],
  ],
  height: [
    ["height_min", ""],
    ["height_max", ""],
  ],
  width: [
    ["width_min", ""],
    ["width_max", ""],
  ],
};

function updateFilters(filterName: string, mode: "add" | "del"): void {
  const fields = FILTER_RESET_FIELDS[filterName] ?? [[filterName, ""]];
  for (const [key, addValue] of fields) {
    if (mode === "add") {
      psParams[key] = addValue;
    } else {
      // eslint-disable-next-line @typescript-eslint/no-dynamic-delete -- a search-parameter bag keyed by the filter name chosen at runtime, serialised straight to the API; a Map would have to be converted back on every request.
      delete psParams[key];
    }
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
function resizeFilterForm() {
  document.querySelectorAll(".form_mobile_arrow").forEach((el) => {
    el.remove();
  });
  document.querySelectorAll<HTMLElement>(".filter").forEach((filterEl) => {
    const currentWindowWidth = windowWidth();
    const leftDistance = offset(filterEl).left;
    const filterForm = find(filterEl, ".filter-form");
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- verified by the selector: ".filter-form" is always a real HTMLElement (a <div> in this app's own markup, never SVG), and every real ".filter" container has exactly one.
    const filterFormFirst = filterForm[0] as HTMLElement;
    const filterFormWidth = innerWidth(filterFormFirst);
    const tooLeft = leftDistance + innerWidth(filterEl) - filterFormWidth;
    const isDesktop = window.matchMedia("(min-width: 600px)").matches;
    css(filterForm, "left", "0px");
    const marginLeft = isDesktop ? 15 : 0;

    if (leftDistance + filterFormWidth > currentWindowWidth) {
      const checkLeft = tooLeft < 0 ? Math.abs(tooLeft - marginLeft) : 0;
      const mobileMarg = isDesktop ? 0 : 2;
      const replaceFormWidth =
        -filterFormWidth + innerWidth(filterEl) + checkLeft - mobileMarg;
      css(filterForm, "left", String(replaceFormWidth) + "px");
    }
    if (!isDesktop) {
      const leftArrow = offset(filterEl).left + innerWidth(filterEl) / 2;
      prepend(
        filterForm,
        '<svg width="10" height="10" viewBox="0 0 14 14" class="form_mobile_arrow" style="left:' +
          String(leftArrow) +
          'px"><polygon class="arrow-border" points="7,0 14,14 0,14"/><polygon class="arrow-fill" points="7,1 13.5,14 0.5,14"/></svg>',
      );
    }
  });
}
on(window, "load", function () {
  resizeFilterForm();
});
on(window, "resize", function () {
  resizeFilterForm();
});

on(document.querySelectorAll(".help-popin-search"), "click", function () {
  fadeIn(document.querySelectorAll("#modalQuickSearch"));
});

on(document.querySelectorAll("#closeModalQuickSearch"), "click", function () {
  fadeOut(document.querySelectorAll("#modalQuickSearch"));
});
