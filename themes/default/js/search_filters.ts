import { pwg_getPageData, pwg_getPageString } from "./page-data";

const global_params_json = pwg_getPageData<string | false>(
  "global_params_json",
);
// Deferred to P48 (real modules/type-design pass), matching this same
// key's own established ambient-type comment below: the parsed shape
// is a complex nested search-filter query object, not something this
// phase re-derives real types for.
let global_params: any;
if (typeof global_params_json !== "undefined") {
  // String(...) makes explicit the same coercion JSON.parse() already
  // did implicitly pre-P47 whenever this value was `false` (JSON.parse
  // itself calls ToString on a non-string argument) -- same behavior,
  // just satisfies the stricter real parameter type now that this
  // value's real `string | false` shape is known.
  global_params = JSON.parse(String(global_params_json));
}

const fullname_of_cat_json = pwg_getPageData<string | false | null>(
  "fullname_of_cat_json",
);
let fullname_of_cat: any;
if (typeof fullname_of_cat_json !== "undefined") {
  // Same String(...) coercion note as global_params_json above.
  fullname_of_cat = JSON.parse(String(fullname_of_cat_json));
}

const search_id_from_page = pwg_getPageData<string | undefined>("search_id");
let search_id: string | undefined;
if (typeof search_id_from_page !== "undefined") {
  search_id = search_id_from_page;
}

// No real consumer reads this (confirmed via grep against mcs.js, the
// one file that reads every one of this file's other globals) -- kept,
// not deleted, since `pwg_getPageData` is a pure getter with no
// side effect either way and this phase's own "same code" scope
// doesn't extend to removing genuinely-dead-but-harmless reads.
pwg_getPageData<string>("user_rank");

const str_word_widget_label = pwg_getPageString("Search for words");
const str_tags_widget_label = pwg_getPageString("Tag");
const str_album_widget_label = pwg_getPageString("Album");
const str_author_widget_label = pwg_getPageString("Author");
const str_added_by_widget_label = pwg_getPageString("Added by");
const str_filetypes_widget_label = pwg_getPageString("File type");

const str_rating_widget_label = pwg_getPageString("Rating");
const str_no_rating = pwg_getPageString("no rate");
const str_between_rating = pwg_getPageString("between %d and %d");
const str_filesize_widget_label = pwg_getPageString("Filesize");
const str_width_widget_label = pwg_getPageString("Width");
const str_height_widget_label = pwg_getPageString("Height");
const str_ratio_widget_label = pwg_getPageString("Ratio");
const str_ratios_label: Record<string, string> = {};
str_ratios_label["Portrait"] = pwg_getPageString("Portrait");
str_ratios_label["square"] = pwg_getPageString("square");
str_ratios_label["Landscape"] = pwg_getPageString("Landscape");
str_ratios_label["Panorama"] = pwg_getPageString("Panorama");
const str_expert_widget_label = pwg_getPageString("Expert mode");

const str_empty_search_top_alt = pwg_getPageString(
  "Fill in the filters to start a search",
);
const str_empty_search_bot_alt = pwg_getPageString(
  'Pre-established filters are proposed, but you can add or remove them using the "Choose filters" button.',
);
const str_search_in_ab = pwg_getPageString("Search in albums");

const prefix_icon = "gallery-icon-";

interface PwgSliderConfig {
  values: number[];
  selected: { min: number; max: number };
  text: string;
}

// <!-- sliders config -->
const sliders: {
  filesizes?: PwgSliderConfig;
  heights?: PwgSliderConfig;
  widths?: PwgSliderConfig;
} = {};

// Real shape of the filesize/height/width page-data keys (SearchFiltersView.php's
// own `array<string, mixed>|null` docblock, narrowed to the real fields
// this file itself reads).
interface PageDataSliderSource {
  list: string;
  selected: { min: number | string; max: number | string };
}

const filesize = pwg_getPageData<PageDataSliderSource | null>("filesize");
if (filesize) {
  sliders.filesizes = {
    values: filesize.list.split(",").map(Number),
    selected: {
      min: Number(filesize.selected.min),
      max: Number(filesize.selected.max),
    },
    text: pwg_getPageString("between %s and %s MB"),
  };
}

const height = pwg_getPageData<PageDataSliderSource | null>("height");
if (height) {
  sliders.heights = {
    values: height.list.split(",").map(Number),
    selected: {
      min: Number(height.selected.min),
      max: Number(height.selected.max),
    },
    text: pwg_getPageString("between %d and %d pixels"),
  };
}

const width = pwg_getPageData<PageDataSliderSource | null>("width");
if (width) {
  sliders.widths = {
    values: width.list.split(",").map(Number),
    selected: {
      min: Number(width.selected.min),
      max: Number(width.selected.max),
    },
    text: pwg_getPageString("between %d and %d pixels"),
  };
}

const show_filter_ratings_value = pwg_getPageData<boolean | undefined>(
  "show_filter_ratings",
);
const show_filter_ratings =
  typeof show_filter_ratings_value === "undefined"
    ? false
    : show_filter_ratings_value;

// Real exports now (docs/PLAN.md P48) -- mcs.ts is the one real
// consumer of most of these, history.ts of `global_params`/
// `fullname_of_cat` too (both previously bare-global reads, no more
// `window.` latching). `user_rank`/`filesize`/`height`/`width` stay
// module-private -- zero real bare-identifier readers anywhere despite
// being declared here (confirmed via grep, not assumed).
export {
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
};
