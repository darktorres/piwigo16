const global_params_json = pwg_getPageData("global_params_json");
let global_params: any;
if (typeof global_params_json !== "undefined") {
  global_params = JSON.parse(global_params_json);
}

const fullname_of_cat_json = pwg_getPageData("fullname_of_cat_json");
let fullname_of_cat: any;
if (typeof fullname_of_cat_json !== "undefined") {
  fullname_of_cat = JSON.parse(fullname_of_cat_json);
}

const search_id_from_page = pwg_getPageData("search_id");
let search_id: any;
if (typeof search_id_from_page !== "undefined") {
  search_id = search_id_from_page;
}

// No real consumer reads this (confirmed via grep against mcs.js, the
// one file that reads every one of this file's other globals) -- kept,
// not deleted, since `pwg_getPageData` is a pure getter with no
// side effect either way and this phase's own "same code" scope
// doesn't extend to removing genuinely-dead-but-harmless reads.
pwg_getPageData("user_rank");

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

const filesize = pwg_getPageData("filesize");
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

const height = pwg_getPageData("height");
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

const width = pwg_getPageData("width");
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

const show_filter_ratings_value = pwg_getPageData("show_filter_ratings");
const show_filter_ratings =
  typeof show_filter_ratings_value === "undefined"
    ? false
    : show_filter_ratings_value;

// Explicit `window.` exposure -- required, not decorative (see
// page-data.ts's own copy of this comment for the full explanation).
// mcs.js/mcs.ts is the one real consumer, reading every one of these as
// a bare global (confirmed via grep, not the plan's own original
// guess -- `user_rank`/`filesize`/`height`/`width` turned out to have
// zero real bare-identifier readers there despite being declared here,
// so they're deliberately NOT exposed).
window.global_params = global_params;
window.fullname_of_cat = fullname_of_cat;
window.search_id = search_id;
window.str_word_widget_label = str_word_widget_label;
window.str_tags_widget_label = str_tags_widget_label;
window.str_album_widget_label = str_album_widget_label;
window.str_author_widget_label = str_author_widget_label;
window.str_added_by_widget_label = str_added_by_widget_label;
window.str_filetypes_widget_label = str_filetypes_widget_label;
window.str_rating_widget_label = str_rating_widget_label;
window.str_no_rating = str_no_rating;
window.str_between_rating = str_between_rating;
window.str_filesize_widget_label = str_filesize_widget_label;
window.str_width_widget_label = str_width_widget_label;
window.str_height_widget_label = str_height_widget_label;
window.str_ratio_widget_label = str_ratio_widget_label;
window.str_ratios_label = str_ratios_label;
window.str_expert_widget_label = str_expert_widget_label;
window.str_empty_search_top_alt = str_empty_search_top_alt;
window.str_empty_search_bot_alt = str_empty_search_bot_alt;
window.str_search_in_ab = str_search_in_ab;
window.prefix_icon = prefix_icon;
window.sliders = sliders;
window.show_filter_ratings = show_filter_ratings;
