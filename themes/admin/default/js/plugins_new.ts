import { pwg_jconfirm_follow_href } from "./common";

import { pwg_getPageString } from "../../../default/js/page-data";
import { ajax, AjaxError } from "../../../default/js/vendor/ajax";
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
} from "../../../default/js/vendor/dom";
import { selectize as createSelectize } from "../../../default/js/vendor/selectize";
import { slider, type SliderUIParams } from "../../../default/js/vendor/slider";
import { sortElements } from "../../../default/js/vendor/sortElements";
import { tipTip } from "../../../default/js/vendor/tiptip";

const str_confirm_msg = pwg_getPageString("Yes, I am sure");
const str_cancel_msg = pwg_getPageString("No, I have changed my mind");
const str_install_title = pwg_getPageString(
  'Are you sure you want to install the plugin "%s"?',
);
const strs_certification: Record<string, string> = {
  "-1": pwg_getPageString("This plugin is incompatible with your version"),
  "0": pwg_getPageString(
    "This plugin have no update since 3 years ! It may be outdated",
  ),
  "1": pwg_getPageString("This plugin has no recent update"),
  "2": pwg_getPageString("This plugin was updated less than 6 months ago"),
  "3": pwg_getPageString("This plugin have been updated recently"),
};
const str_x_month = pwg_getPageString("%d month");
const str_x_months = pwg_getPageString("%d months");
const str_x_year = pwg_getPageString("%d year");
const str_x_years = pwg_getPageString("%d years");
const str_from_begining = pwg_getPageString("since the beginning");

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
    advanced_filter_button_click,
  );
  on(
    document.querySelectorAll(".advanced-filter span.icon-cancel"),
    "click",
    advanced_filter_hide,
  );

  function advanced_filter_button_click() {
    if (
      !hasClass(
        document.querySelectorAll(".advanced-filter"),
        "advanced-filter-open",
      )
    ) {
      advanced_filter_show();
    } else {
      advanced_filter_hide();
    }
  }

  function advanced_filter_show() {
    addClass(
      document.querySelectorAll(".advanced-filter-btn, .advanced-filter"),
      "advanced-filter-open",
    );
  }

  function advanced_filter_hide() {
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
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- data() reads a data-* attribute this same app's own setData()/template writes, never adversarial input.
    const plugin_name = box !== null ? (data(box, "name") as string) : "";
    pwg_jconfirm_follow_href(el, {
      alert_title: str_install_title.replace("%s", plugin_name),
      alert_confirm: str_confirm_msg,
      alert_cancel: str_cancel_msg,
    });
  });

  tipTip(document.querySelectorAll(".certification"), {
    delay: 0,
    fadeIn: 200,
    fadeOut: 200,
  });

  document.querySelectorAll(".pluginRating").forEach((node) => {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- data() reads a data-* attribute this same app's own setData()/template writes, never adversarial input.
    const rating = data(node, "rating") as number;
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
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- data() reads a data-* attribute this same app's own setData()/template writes, never adversarial input.
    const author = data(el, "author") as string;
    author.split(", ").forEach((name: string) => {
      if (!authorNames.find((opt) => opt.value === name)) {
        authorNames.push({ value: name, text: name });
      }
    });

    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- data() reads a data-* attribute this same app's own setData()/template writes, never adversarial input.
    const tags = data(el, "tags") as string;
    tags.split(", ").forEach((tag: string) => {
      if (!tagsNames.find((opt) => opt.value === tag)) {
        tagsNames.push({ value: tag, text: tag });
      }
    });
  });

  // initialize the Selectize control
  const selectizeAuthor = createSelectize<string, FilterOption>(
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
      updateRatingFilterLabel(ui.value!);
      applyFilter("rating", ui.value!);
    },
  });

  slider(document.querySelectorAll(".revision-date-filter-slider"), {
    range: "min",
    value: 0,
    min: 0,
    max: 6,
    slide: function (_event: Event, ui: SliderUIParams) {
      const [month] = value_to_month(ui.value!);
      updateRevisionFilterLabel(ui.value!);
      applyFilter("revision", month);
    },
  });

  // All the slider values and it's corresponding month's number and label
  function value_to_month(sliderValue: number): [number, string] {
    switch (sliderValue) {
      case 6:
        return [1, str_x_month.replace("%d", String(1))];
      case 5:
        return [3, str_x_months.replace("%d", String(3))];
      case 4:
        return [6, str_x_months.replace("%d", String(6))];
      case 3:
        return [12, str_x_year.replace("%d", String(1))];
      case 2:
        return [24, str_x_years.replace("%d", String(2))];
      case 1:
        return [60, str_x_years.replace("%d", String(5))];
      default:
        return [Number.MAX_SAFE_INTEGER, str_from_begining];
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
      updateCertificationFilterLabel(ui.value!);
      applyFilter("certification", ui.value!);
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
    // environment (PEM reachable here, same as updates_ext.ts's page)
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
    certifNode.setAttribute("title", strs_certification[String(value)]!);
    tipTip(certifNode, {
      delay: 0,
      fadeIn: 200,
      fadeOut: 200,
    });
  }

  function updateRevisionFilterLabel(sliderValue: number) {
    const [, label] = value_to_month(sliderValue);
    html(document.querySelectorAll(".revision-date"), label);
  }

  // <-- Apply advanced filters -->

  // object that remember filters states
  filters = {
    search: val(document.querySelectorAll("#search")) ?? "",
    author: "",
    tag: "",
    rating: slider(
      document.querySelectorAll(".notation-filter-slider"),
      "value",
    )!,
    certification: slider(
      document.querySelectorAll(".certification-filter-slider"),
      "value",
    )!,
    // Real, pre-existing behavior, not a bug this phase fixes: reads
    // `.certification-filter-slider`'s own value here, not
    // `.revision-date-filter-slider`'s -- looks like a likely
    // copy-paste bug (both sliders start at 0 so it's not currently
    // observable), flagged rather than silently changed.
    revision: value_to_month(
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
        // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- data() reads a data-* attribute this same app's own setData()/template writes, never adversarial input.
        (ratingEl !== null ? (data(ratingEl, "rating") as number) : 0) || 0;
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- data() reads a data-* attribute this same app's own setData()/template writes, never adversarial input.
      const pluginCertification = data(
        pluginBox.querySelector(".certification")!,
        "certification",
      ) as number;
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- data() reads a data-* attribute this same app's own setData()/template writes, never adversarial input.
      const pluginAuthors = (data(pluginBox, "author") as string).split(", ");
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- data() reads a data-* attribute this same app's own setData()/template writes, never adversarial input.
      const pluginName = (data(pluginBox, "name") as string).toUpperCase();
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- data() reads a data-* attribute this same app's own setData()/template writes, never adversarial input.
      const pluginTags = (data(pluginBox, "tags") as string).split(", ");
      const pluginRevisionOld = monthDiff(
        // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- data() reads a data-* attribute this same app's own setData()/template writes, never adversarial input.
        new Date((data(pluginBox, "revision") as number) * 1000),
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
