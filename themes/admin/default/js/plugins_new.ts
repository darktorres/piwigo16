import "./common";

import { pwg_getPageString } from "../../../default/js/page-data";
import { ajax } from "../../../default/js/vendor/ajax";
export {};

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
// Params match jquery.sort.js's own untyped `sortElements(comparator: (a:
// any, b: any) => number)` ambient signature (no real type source for
// this vendored plugin) -- HTMLElement is the real runtime shape either
// way, and a more specific param type here is still assignable to that
// `any`-typed callback slot.
const sortPlugins = function (a: HTMLElement, b: HTMLElement) {
  if (
    sortOrder == "downloads" ||
    sortOrder == "revision" ||
    sortOrder == "date"
  )
    return parseInt(String($(a).data(sortOrder))) <
      parseInt(String($(b).data(sortOrder)))
      ? 1
      : -1;
  else
    return String($(a).data(sortOrder)).toLowerCase() >
      String($(b).data(sortOrder)).toLowerCase()
      ? 1
      : -1;
};

$(function () {
  // <-- Set the advanced filters -->

  const betaTestPlugins = $("#showBetaTestPlugin")[0]!.hasAttribute("checked");

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
  $(".advanced-filter-btn").click(advanced_filter_button_click);
  $(".advanced-filter span.icon-cancel").click(advanced_filter_hide);

  function advanced_filter_button_click() {
    if (!$(".advanced-filter").hasClass("advanced-filter-open")) {
      advanced_filter_show();
    } else {
      advanced_filter_hide();
    }
  }

  function advanced_filter_show() {
    $(".advanced-filter-btn, .advanced-filter").addClass(
      "advanced-filter-open",
    );
  }

  function advanced_filter_hide() {
    $(".advanced-filter-btn, .advanced-filter").removeClass(
      "advanced-filter-open",
    );
  }

  jQuery('select[name="selectOrder"]').change(function () {
    sortOrder = (this as HTMLSelectElement).value;
    $(".pluginBox").sortElements(sortPlugins);
    void ajax({ url: "admin.php?plugins_new_order=" + sortOrder });
  });

  jQuery("#search").on("input", function () {
    applyFilter("search", (this as HTMLInputElement).value.toUpperCase());
    jQuery("#search").trigger("click");
  });

  $(".search-cancel").on("click", () => {
    applyFilter("search", "");
  });

  $(".buttonInstall").each(function () {
    const plugin_name = $(this).closest(".pluginBox").data("name") as string;
    $(this).pwg_jconfirm_follow_href({
      alert_title: str_install_title.replace("%s", plugin_name),
      alert_confirm: str_confirm_msg,
      alert_cancel: str_cancel_msg,
    });
  });

  jQuery(".certification").tipTip({
    delay: 0,
    fadeIn: 200,
    fadeOut: 200,
  });

  $(".pluginRating").each((i, node) => {
    const ratingContainer = $(node);
    const rating = ratingContainer.data("rating") as number;
    displayStars(ratingContainer.find(".rating-star-container"), rating);
  });

  interface FilterOption {
    value: string;
    text: string;
  }

  // put default values in the select
  const authorNames: FilterOption[] = [{ value: "", text: "-" }];
  const tagsNames: FilterOption[] = [{ value: "", text: "-" }];

  // read all plugin boxes to get author and tags
  $(".pluginBox").each((i, el) => {
    const author = $(el).data("author") as string;
    author.split(", ").forEach((name: string) => {
      if (!authorNames.find((el) => el.value == name)) {
        authorNames.push({ value: name, text: name });
      }
    });

    const tags = $(el).data("tags") as string;
    tags.split(", ").forEach((tag: string) => {
      if (!tagsNames.find((el) => el.value == tag)) {
        tagsNames.push({ value: tag, text: tag });
      }
    });
  });

  // initialize the Selectize control
  let $select = $("#author-filter").selectize({
    // Neither #author-filter nor #tag-filter is a `<select multiple>`
    // (confirmed in plugins_new.latte), so onChange always gives a
    // single string, not string[].
    onChange: function (value: string) {
      applyFilter("author", value);
    },
    plugins: ["remove_button"],
  });

  // fetch the instance
  const selectizeAuthor = $select[0]!.selectize;
  selectizeAuthor.addOption(authorNames);

  // initialize the Selectize control
  $select = $("#tag-filter").selectize({
    onChange: function (value: string) {
      applyFilter("tag", value);
    },
    plugins: ["remove_button"],
  });

  // fetch the instance
  const selectizeTag = $select[0]!.selectize;
  selectizeTag.addOption(tagsNames);

  $(".notation-filter-slider").slider({
    range: "min",
    value: 0,
    min: 0,
    max: 5,
    step: 0.5,
    slide: function (event: JQueryEventObject, ui: JQueryUI.SliderUIParams) {
      updateRatingFilterLabel(ui.value!);
      applyFilter("rating", ui.value!);
    },
  });

  $(".revision-date-filter-slider").slider({
    range: "min",
    value: 0,
    min: 0,
    max: 6,
    slide: function (event: JQueryEventObject, ui: JQueryUI.SliderUIParams) {
      const [month] = value_to_month(ui.value!);
      updateRevisionFilterLabel(ui.value!);
      applyFilter("revision", month);
    },
  });

  // All the slider values and it's corresponding month's number and label
  function value_to_month(val: number): [number, string] {
    switch (val) {
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

  $(".certification-filter-slider").slider({
    range: "min",
    value: minCertification,
    min: minCertification,
    max: 3,
    slide: function (event: JQueryEventObject, ui: JQueryUI.SliderUIParams) {
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

  function displayStars(element: JQuery, rating: number) {
    element.find("span").addClass("icon-star-empty");
    element.find("span i").attr("class", "");

    rating = Math.round(rating * 2);

    if (rating % 2 == 1) {
      $(element)
        .find("span[data-star=" + (rating - 1) / 2 + "] i")
        .addClass("icon-star-half");
      rating -= 1;
    }

    while (rating > 0) {
      rating -= 2;
      $(element)
        .find("span[data-star=" + rating / 2 + "] i")
        .addClass("icon-star");
      $(element)
        .find("span[data-star=" + rating / 2 + "]")
        .removeClass("icon-star-empty");
    }
  }

  // Updates labels when input change

  function updateRatingFilterLabel(value: number) {
    displayStars($(".advanced-filter-rating .rating-star-container"), value);
  }

  function updateCertificationFilterLabel(value: number) {
    const certifNode = $(".advanced-filter-certification .certification");
    certifNode.attr("data-certification", value);
    certifNode.attr("title", strs_certification[String(value)]!);
    certifNode.tipTip({
      delay: 0,
      fadeIn: 200,
      fadeOut: 200,
    });
  }

  function updateRevisionFilterLabel(val: number) {
    const [, label] = value_to_month(val);
    $(".revision-date").html(label);
  }

  // <-- Apply advanced filters -->

  // object that remember filters states
  filters = {
    // Real #search input, always a string.
    search: $("#search").val() as string,
    author: "",
    tag: "",
    rating: $(".notation-filter-slider").slider("value"),
    certification: $(".certification-filter-slider").slider("value"),
    // Real, pre-existing behavior, not a bug this phase fixes: reads
    // `.certification-filter-slider`'s own value here, not
    // `.revision-date-filter-slider`'s -- looks like a likely
    // copy-paste bug (both sliders start at 0 so it's not currently
    // observable), flagged rather than silently changed.
    revision: value_to_month(
      $(".certification-filter-slider").slider("value"),
    )[0],
  };

  selectizeAuthor.setValue("");
  selectizeTag.setValue("");

  function applyFilter(changed: keyof PluginFilters, value: string | number) {
    (filters as Record<keyof PluginFilters, string | number>)[changed] = value;

    sort((pluginBox: JQuery) => {
      const pluginRating =
        (pluginBox.find(".pluginRating").data("rating") as number) || 0;
      const pluginCertification = pluginBox
        .find(".certification")
        .data("certification") as number;
      const pluginAuthors = (pluginBox.data("author") as string).split(", ");
      const pluginName = (pluginBox.data("name") as string).toUpperCase();
      const pluginTags = (pluginBox.data("tags") as string).split(", ");
      const pluginRevisionOld = monthDiff(
        new Date((pluginBox.data("revision") as number) * 1000),
        new Date(),
      ); // number of months between the last revision date and now

      return (
        pluginRating >= filters.rating &&
        pluginCertification >= filters.certification &&
        (filters.search === "" || pluginName.indexOf(filters.search) != -1) &&
        (filters.author === "" || pluginAuthors.includes(filters.author)) &&
        (filters.tag === "" || pluginTags.includes(filters.tag)) &&
        pluginRevisionOld <= filters["revision"]
      );
    });
  }

  // Display or not plugin with a function handler
  function sort(sortFunction: (pluginBox: JQuery) => boolean) {
    $(".pluginBox").each((i, el) => {
      if (sortFunction($(el))) {
        $(el).show();
      } else {
        $(el).hide();
      }
    });
  }

  // Crop the names of plugins if there are too long
  $(".pluginName span").each((i, el) => {
    const name = $(el);
    if (name.html().length > 30) {
      name.html(name.html().slice(0, 30) + "...");
    }
  });

  $("#showBetaTestPlugin").on("change", (e) => {
    $(".beta-test-plugin-switch .slider").addClass("loading");

    const queryParams = new URLSearchParams(window.location.search);

    queryParams.set(
      "beta-test",
      (e.currentTarget as HTMLInputElement).checked.toString(),
    );

    history.replaceState(null, "", "?" + queryParams.toString());

    window.location.reload();
  });
});
