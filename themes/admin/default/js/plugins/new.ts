// common.ts's own side effects (font-checkbox init, search-cancel
// bindings) -- plugins_new.latte's own `#search` input carries the
// generic `.search-input` class too, and its own `.search-cancel`
// click handler below only clears the filter state, not the input's
// own visible value or the cancel button's show/hide toggling -- both
// need the shared wiring. This page used to get it incidentally, as a
// side effect of importing pwg_jconfirm_follow_href from what was then
// the same file (common.ts); the P51-I split made that dependency
// explicit instead of leaving it accidental.
import "../common";
import { pwg_jconfirm_follow_href } from "../jconfirmPresets";

import { pwg_getPageString } from "../../../../default/js/pageData";
import { ajax, AjaxError } from "../../../../default/js/vendor/utils/ajax";
import {
  addClass,
  data,
  find,
  hasClass,
  hide,
  html,
  htmlOf,
  on,
  ready,
  removeClass,
  show,
  trigger,
  val,
} from "../../../../default/js/vendor/utils/dom";
import { selectize as createSelectize } from "../../../../default/js/vendor/widgets/selectize";
import {
  slider,
  type SliderUIParams,
} from "../../../../default/js/vendor/widgets/slider";
import { sortElements } from "../../../../default/js/vendor/utils/sortElements";
import { tipTip } from "../../../../default/js/vendor/widgets/tiptip";

const strConfirmMsg = pwg_getPageString("Yes, I am sure");
const strCancelMsg = pwg_getPageString("No, I have changed my mind");
const strInstallTitle = pwg_getPageString(
  'Are you sure you want to install the plugin "%s"?',
);
const strsCertification: Record<string, string> = {
  "-1": pwg_getPageString("This plugin is incompatible with your version"),
  "0": pwg_getPageString(
    "This plugin have no update since 3 years ! It may be outdated",
  ),
  "1": pwg_getPageString("This plugin has no recent update"),
  "2": pwg_getPageString("This plugin was updated less than 6 months ago"),
  "3": pwg_getPageString("This plugin have been updated recently"),
};
const strXMonth = pwg_getPageString("%d month");
const strXMonths = pwg_getPageString("%d months");
const strXYear = pwg_getPageString("%d year");
const strXYears = pwg_getPageString("%d years");
const strFromBegining = pwg_getPageString("since the beginning");

// <-- Define sort orders -->
let sortOrder = "date";
// `data()` reads real `data-*` attributes (name/revision/downloads, all
// rendered by the template).
const sortPlugins = function (a: Element, b: Element) {
  if (
    sortOrder === "downloads" ||
    sortOrder === "revision" ||
    sortOrder === "date"
  )
    return parseInt(String(data(a, sortOrder))) <
      parseInt(String(data(b, sortOrder)))
      ? 1
      : -1;
  else
    return String(data(a, sortOrder)).toLowerCase() >
      String(data(b, sortOrder)).toLowerCase()
      ? 1
      : -1;
};

ready(function () {
  // <-- Set the advanced filters -->

  // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- the page's own "#showBetaTestPlugin" checkbox is always real.
  const betaTestPlugins = document
    .getElementById("showBetaTestPlugin")!
    .hasAttribute("checked");

  interface PluginFilters {
    search: string;
    author: string;
    tag: string;
    rating: number;
    certification: number;
    revision: number;
  }

  // object that remember filters states (initialized later, below)
  let filters: PluginFilters = {
    search: "",
    author: "",
    tag: "",
    rating: 0,
    certification: 0,
    revision: 0,
  };

  // toggle advanced filter's panel
  on(
    document.querySelectorAll(".advanced-filter-btn"),
    "click",
    advancedFilterButtonClick,
  );
  on(
    document.querySelectorAll(".advanced-filter span.icon-cancel"),
    "click",
    advancedFilterHide,
  );

  function advancedFilterButtonClick() {
    if (
      !hasClass(
        document.querySelectorAll(".advanced-filter"),
        "advanced-filter-open",
      )
    ) {
      advancedFilterShow();
    } else {
      advancedFilterHide();
    }
  }

  function advancedFilterShow() {
    addClass(
      document.querySelectorAll(".advanced-filter-btn, .advanced-filter"),
      "advanced-filter-open",
    );
  }

  function advancedFilterHide() {
    removeClass(
      document.querySelectorAll(".advanced-filter-btn, .advanced-filter"),
      "advanced-filter-open",
    );
  }

  on(
    document.querySelectorAll('select[name="selectOrder"]'),
    "change",
    function (this: HTMLSelectElement): void {
      sortOrder = this.value;
      sortElements(document.querySelectorAll(".pluginBox"), sortPlugins);
      void (async () => {
        try {
          await ajax({ url: "admin.php?plugins_new_order=" + sortOrder });
        } catch (e) {
          console.error(e instanceof AjaxError ? e.responseText : e);
        }
      })();
    },
  );

  on(
    document.querySelectorAll("#search"),
    "input",
    function (this: HTMLInputElement): void {
      applyFilter("search", this.value.toUpperCase());
      trigger(document.querySelectorAll("#search"), "click");
    },
  );

  on(document.querySelectorAll(".search-cancel"), "click", () => {
    applyFilter("search", "");
  });

  document.querySelectorAll(".buttonInstall").forEach((el) => {
    const box = el.closest(".pluginBox");
    const pluginName = box !== null ? data<string>(box, "name") : "";
    pwg_jconfirm_follow_href(el, {
      alert_title: strInstallTitle.replace("%s", pluginName),
      alert_confirm: strConfirmMsg,
      alert_cancel: strCancelMsg,
    });
  });

  tipTip(document.querySelectorAll(".certification"), {
    delay: 0,
    fadeIn: 200,
    fadeOut: 200,
  });

  document.querySelectorAll(".pluginRating").forEach((node) => {
    const rating = data<number>(node, "rating");
    const starContainer = node.querySelector(".rating-star-container");
    if (starContainer !== null) {
      displayStars(starContainer, rating);
    }
  });

  interface FilterOption extends Record<string, unknown> {
    value: string;
    text: string;
  }

  // put default values in the select
  const authorNames: FilterOption[] = [{ value: "", text: "-" }];
  const tagsNames: FilterOption[] = [{ value: "", text: "-" }];

  // read all plugin boxes to get author and tags
  document.querySelectorAll(".pluginBox").forEach((el) => {
    const author = data<string>(el, "author");
    author.split(", ").forEach((name: string) => {
      if (!authorNames.find((opt) => opt.value === name)) {
        authorNames.push({ value: name, text: name });
      }
    });

    const tags = data<string>(el, "tags");
    tags.split(", ").forEach((tag: string) => {
      if (!tagsNames.find((opt) => opt.value === tag)) {
        tagsNames.push({ value: tag, text: tag });
      }
    });
  });

  // initialize the Selectize control
  const selectizeAuthor = createSelectize<string, FilterOption>(
    // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- the page's own "#author-filter" select is always real.
    document.querySelector<HTMLSelectElement>("#author-filter")!,
    {
      // Neither #author-filter nor #tag-filter is a `<select multiple>`
      // (confirmed in plugins_new.latte), so onChange always gives a
      // single string, not string[].
      onChange: function (value) {
        // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- verified above: a non-multiple selectize instance's onChange() never gives an array.
        applyFilter("author", value as string);
      },
      plugins: ["remove_button"],
    },
  );
  selectizeAuthor.addOption(authorNames);

  // initialize the Selectize control
  const selectizeTag = createSelectize<string, FilterOption>(
    // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- the page's own "#tag-filter" select is always real.
    document.querySelector<HTMLSelectElement>("#tag-filter")!,
    {
      onChange: function (value) {
        // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- verified above: a non-multiple selectize instance's onChange() never gives an array.
        applyFilter("tag", value as string);
      },
      plugins: ["remove_button"],
    },
  );
  selectizeTag.addOption(tagsNames);

  slider(document.querySelectorAll(".notation-filter-slider"), {
    range: "min",
    value: 0,
    min: 0,
    max: 5,
    step: 0.5,
    slide: function (_event: Event, ui: SliderUIParams) {
      // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- a real slide event always carries the slider's own current value.
      const value = ui.value!;
      updateRatingFilterLabel(value);
      applyFilter("rating", value);
    },
  });

  slider(document.querySelectorAll(".revision-date-filter-slider"), {
    range: "min",
    value: 0,
    min: 0,
    max: 6,
    slide: function (_event: Event, ui: SliderUIParams) {
      // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- a real slide event always carries the slider's own current value.
      const value = ui.value!;
      const [month] = valueToMonth(value);
      updateRevisionFilterLabel(value);
      applyFilter("revision", month);
    },
  });

  // All the slider values and it's corresponding month's number and label
  function valueToMonth(sliderValue: number): [number, string] {
    switch (sliderValue) {
      case 6:
        return [1, strXMonth.replace("%d", String(1))];
      case 5:
        return [3, strXMonths.replace("%d", String(3))];
      case 4:
        return [6, strXMonths.replace("%d", String(6))];
      case 3:
        return [12, strXYear.replace("%d", String(1))];
      case 2:
        return [24, strXYears.replace("%d", String(2))];
      case 1:
        return [60, strXYears.replace("%d", String(5))];
      default:
        return [Number.MAX_SAFE_INTEGER, strFromBegining];
    }
  }

  // The certification filter dosen't include incompatible if the beta-test option is not checked
  const minCertification = betaTestPlugins ? -1 : 0;

  slider(document.querySelectorAll(".certification-filter-slider"), {
    range: "min",
    value: minCertification,
    min: minCertification,
    max: 3,
    slide: function (_event: Event, ui: SliderUIParams) {
      // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- a real slide event always carries the slider's own current value.
      const value = ui.value!;
      updateCertificationFilterLabel(value);
      applyFilter("certification", value);
    },
  });

  // Diffrence between two dates, in months
  function monthDiff(d1: Date, d2: Date) {
    let months;
    months = (d2.getFullYear() - d1.getFullYear()) * 12;
    months -= d1.getMonth();
    months += d2.getMonth();
    return months <= 0 ? 0 : months;
  }

  updateRatingFilterLabel(0);
  updateCertificationFilterLabel(minCertification);
  updateRevisionFilterLabel(0);

  function displayStars(container: Element, initialRating: number) {
    addClass(find(container, "span"), "icon-star-empty");
    find(container, "span i").forEach((el) => {
      el.className = "";
    });

    let rating = Math.round(initialRating * 2);

    // Attribute selector values are quoted here -- unlike Sizzle (jQuery's
    // selector engine), which tolerates an unquoted value starting with a
    // digit, native querySelectorAll() enforces real CSS grammar (an
    // identifier can't start with an unescaped digit) and throws a real
    // SyntaxError. Confirmed live: a real plugin with rating 4.5 in this
    // environment (PEM reachable here, same as updates/ext.ts's page)
    // reaches the `(rating - 1) / 2 === 4` branch and crashes unquoted.
    if (rating % 2 === 1) {
      addClass(
        find(container, 'span[data-star="' + String((rating - 1) / 2) + '"] i'),
        "icon-star-half",
      );
      rating -= 1;
    }

    while (rating > 0) {
      rating -= 2;
      addClass(
        find(container, 'span[data-star="' + String(rating / 2) + '"] i'),
        "icon-star",
      );
      removeClass(
        find(container, 'span[data-star="' + String(rating / 2) + '"]'),
        "icon-star-empty",
      );
    }
  }

  // Updates labels when input change

  function updateRatingFilterLabel(value: number) {
    const container = document.querySelector(
      ".advanced-filter-rating .rating-star-container",
    );
    if (container !== null) displayStars(container, value);
  }

  function updateCertificationFilterLabel(value: number) {
    const certifNode = document.querySelector(
      ".advanced-filter-certification .certification",
    );
    if (certifNode === null) return;
    certifNode.setAttribute("data-certification", String(value));
    // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- value is always one of strsCertification's own declared -1..3 keys (the certification slider's own min/max bound it).
    certifNode.setAttribute("title", strsCertification[String(value)]!);
    tipTip(certifNode, {
      delay: 0,
      fadeIn: 200,
      fadeOut: 200,
    });
  }

  function updateRevisionFilterLabel(sliderValue: number) {
    const [, label] = valueToMonth(sliderValue);
    html(document.querySelectorAll(".revision-date"), label);
  }

  // <-- Apply advanced filters -->

  // object that remember filters states
  filters = {
    search: val(document.querySelectorAll("#search")) ?? "",
    author: "",
    tag: "",
    // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- both sliders are already initialized above with a real numeric "value" option; this getter form always echoes it back.
    rating: slider(
      document.querySelectorAll(".notation-filter-slider"),
      "value",
    )!,
    // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- both sliders are already initialized above with a real numeric "value" option; this getter form always echoes it back.
    certification: slider(
      document.querySelectorAll(".certification-filter-slider"),
      "value",
    )!,
    // Real, pre-existing behavior, not a bug this phase fixes: reads
    // `.certification-filter-slider`'s own value here, not
    // `.revision-date-filter-slider`'s -- looks like a likely
    // copy-paste bug (both sliders start at 0 so it's not currently
    // observable), flagged rather than silently changed.
    revision: valueToMonth(
      // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- both sliders are already initialized above with a real numeric "value" option; this getter form always echoes it back.
      slider(
        document.querySelectorAll(".certification-filter-slider"),
        "value",
      )!,
    )[0],
  };

  selectizeAuthor.setValue("");
  selectizeTag.setValue("");

  function applyFilter(changed: keyof PluginFilters, value: string | number) {
    (filters as Record<keyof PluginFilters, string | number>)[changed] = value;

    sort((pluginBox: Element) => {
      const ratingEl = pluginBox.querySelector(".pluginRating");
      const pluginRating =
        (ratingEl !== null ? data<number>(ratingEl, "rating") : 0) || 0;
      const pluginCertification = data<number>(
        // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- every rendered .pluginBox always has its own real ".certification" child (plugins_new.latte).
        pluginBox.querySelector(".certification")!,
        "certification",
      );
      const pluginAuthors = data<string>(pluginBox, "author").split(", ");
      const pluginName = data<string>(pluginBox, "name").toUpperCase();
      const pluginTags = data<string>(pluginBox, "tags").split(", ");
      const pluginRevisionOld = monthDiff(
        new Date(data<number>(pluginBox, "revision") * 1000),
        new Date(),
      ); // number of months between the last revision date and now

      return (
        pluginRating >= filters.rating &&
        pluginCertification >= filters.certification &&
        (filters.search === "" || pluginName.includes(filters.search)) &&
        (filters.author === "" || pluginAuthors.includes(filters.author)) &&
        (filters.tag === "" || pluginTags.includes(filters.tag)) &&
        pluginRevisionOld <= filters.revision
      );
    });
  }

  // Display or not plugin with a function handler
  function sort(sortFunction: (pluginBox: Element) => boolean) {
    document.querySelectorAll(".pluginBox").forEach((el) => {
      if (sortFunction(el)) {
        show(el);
      } else {
        hide(el);
      }
    });
  }

  // Crop the names of plugins if there are too long
  document.querySelectorAll(".pluginName span").forEach((el) => {
    const name = htmlOf(el) ?? "";
    if (name.length > 30) {
      html(el, name.slice(0, 30) + "...");
    }
  });

  on(document.querySelectorAll("#showBetaTestPlugin"), "change", (e) => {
    addClass(
      document.querySelectorAll(".beta-test-plugin-switch .slider"),
      "loading",
    );

    const queryParams = new URLSearchParams(window.location.search);

    queryParams.set(
      "beta-test",
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- #showBetaTestPlugin is a real <input type=checkbox> (plugins_new.latte), so its own "change" event's currentTarget is always an HTMLInputElement.
      (e.currentTarget as HTMLInputElement).checked.toString(),
    );

    history.replaceState(null, "", "?" + queryParams.toString());

    window.location.reload();
  });
});
