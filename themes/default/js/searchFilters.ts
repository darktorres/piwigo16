import type { PwgDoubleSliderOptions } from "./doubleSlider";
import { pwg_getPageData, pwg_getPageString } from "./pageData";

interface SearchAllwordsRule {
  words: string[];
  mode: "AND" | "OR";
  fields: string[];
}

/**
 * `AuthorRule` deliberately drops the `mode` key server-side (its own
 * docblock: "always 'OR' from every real producer -- dropped here,
 * confirmed dead"). mcs.ts still writes it, so it is typed here as the
 * client-only field it is.
 */
interface SearchAuthorRule {
  words: string[];
  mode?: "OR";
}

interface SearchExpertRule {
  string: string;
}

interface SearchTagsRule {
  words: (number | string)[];
  mode: "AND" | "OR";
}

/**
 * `words` stays `int|string` per element for the same reason
 * `CategoryRule::$words` does: the API only regex-validates each id is
 * all-digits, it never casts, so JSON can hand this either.
 */
interface SearchCategoryRule {
  words: (number | string)[];
  sub_inc: boolean;
}

interface SearchDateRule {
  preset: string;
  custom: string[];
}

interface SearchFields {
  allwords?: SearchAllwordsRule;
  author?: SearchAuthorRule;
  expert?: SearchExpertRule;
  filetypes?: string[];
  added_by?: (number | string)[];
  cat?: SearchCategoryRule;
  tags?: SearchTagsRule;
  date_posted?: SearchDateRule;
  date_created?: SearchDateRule;
  ratios?: string[];
  ratings?: string[];
  filesize_min?: number | string;
  filesize_max?: number | string;
  width_min?: number | string;
  width_max?: number | string;
  height_min?: number | string;
  height_max?: number | string;
}

/**
 * The saved search's own `rules` array, JSON-encoded by
 * `SearchFilterRenderer::render()` (`gp: json_encode($mySearch)`) right
 * after it overwrites `$mySearch['fields']` with
 * `SearchRules::toArray()`. Every member of the interfaces above is
 * derived from that method and its seven rule projections in
 * `src/Piwigo/Search/Projection/`, whose docblocks carry the element
 * types (`list<string>`, `list<int|string>`, `'AND'|'OR'`).
 *
 * Every field of `SearchFields` is optional because
 * `SearchRules::toArray()` only emits a key when that filter is part of
 * the search -- a null property there plays the original array's own
 * `!isset()` role, and the distinction is load-bearing (`filetypes: []`
 * means "filter active, nothing matched"; absent means "filter never
 * chosen"). mcs.ts reads it exactly that way, one
 * `if (globalParams.fields.x)` per filter.
 */
interface GlobalSearchParams {
  /**
   * Always present: `SearchFilterRenderer::render()` assigns
   * `$mySearch['fields']` unconditionally before encoding.
   */
  fields: SearchFields;
}

const globalParamsJson = pwg_getPageData<string | false>("global_params_json");
let globalParams: GlobalSearchParams;
if (typeof globalParamsJson !== "undefined") {
  // String(...) makes explicit the same coercion JSON.parse() already
  // did implicitly pre-P47 whenever this value was `false` (JSON.parse
  // itself calls ToString on a non-string argument) -- same behavior,
  // just satisfies the stricter real parameter type now that this
  // value's real `string | false` shape is known.
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- GlobalSearchParams is the same genuinely heterogeneous, deliberately-not-deep-validated search-filter query object this file's own eslint.config.ts any-relaxation entry already documents.
  globalParams = JSON.parse(String(globalParamsJson)) as GlobalSearchParams;
}

const fullnameOfCatJson = pwg_getPageData<string | false | null>(
  "fullname_of_cat_json",
);
// `SearchFilterRenderer::render()` builds this as
// `$fullnameOf[$row->id->value] = strip_tags($catDisplayName)` -- an
// album-id-keyed map of plain-text full names, encoded only when the
// `cat` filter is part of the search.
let fullnameOfCat: Record<string, string>;
if (typeof fullnameOfCatJson !== "undefined") {
  // Same String(...) coercion note as globalParamsJson above.
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- same genuinely heterogeneous search-filter query object as globalParamsJson above.
  fullnameOfCat = JSON.parse(String(fullnameOfCatJson)) as Record<
    string,
    string
  >;
}

const searchIdFromPage = pwg_getPageData<string | undefined>("search_id");
let searchId: string | undefined;
if (typeof searchIdFromPage !== "undefined") {
  searchId = searchIdFromPage;
}

// No real consumer reads this (confirmed via grep against mcs.js, the
// one file that reads every one of this file's other globals) -- kept,
// not deleted, since `pwg_getPageData` is a pure getter with no
// side effect either way and this phase's own "same code" scope
// doesn't extend to removing genuinely-dead-but-harmless reads.
pwg_getPageData<string>("user_rank");

const strWordWidgetLabel = pwg_getPageString("Search for words");
const strTagsWidgetLabel = pwg_getPageString("Tag");
const strAlbumWidgetLabel = pwg_getPageString("Album");
const strAuthorWidgetLabel = pwg_getPageString("Author");
const strAddedByWidgetLabel = pwg_getPageString("Added by");
const strFiletypesWidgetLabel = pwg_getPageString("File type");

const strRatingWidgetLabel = pwg_getPageString("Rating");
const strNoRating = pwg_getPageString("no rate");
const strBetweenRating = pwg_getPageString("between %d and %d");
const strFilesizeWidgetLabel = pwg_getPageString("Filesize");
const strWidthWidgetLabel = pwg_getPageString("Width");
const strHeightWidgetLabel = pwg_getPageString("Height");
const strRatioWidgetLabel = pwg_getPageString("Ratio");
const strRatiosLabel: Record<string, string> = {};
strRatiosLabel["Portrait"] = pwg_getPageString("Portrait");
strRatiosLabel["square"] = pwg_getPageString("square");
strRatiosLabel["Landscape"] = pwg_getPageString("Landscape");
strRatiosLabel["Panorama"] = pwg_getPageString("Panorama");
const strExpertWidgetLabel = pwg_getPageString("Expert mode");

const strEmptySearchTopAlt = pwg_getPageString(
  "Fill in the filters to start a search",
);
const strEmptySearchBotAlt = pwg_getPageString(
  'Pre-established filters are proposed, but you can add or remove them using the "Choose filters" button.',
);
const strSearchInAb = pwg_getPageString("Search in albums");

const prefixIcon = "gallery-icon-";

// <!-- sliders config -->
const sliders: {
  filesizes?: PwgDoubleSliderOptions;
  heights?: PwgDoubleSliderOptions;
  widths?: PwgDoubleSliderOptions;
} = {};

// Real shape of the filesize/height/width page-data keys -- now
// RangeFilterOptions::toPageData()'s own declared shape (P58-A), so this is
// the whole payload rather than a narrowing of an `array<string, mixed>`.
//
// The slider's range comes from `list` and its position from `selected`;
// there is no `bounds`, and the claim in an earlier version of this comment
// that the range came from the markup's data-min/data-max was simply wrong.
// Nothing read those attributes, and they are gone.
//
// The endpoints are strings, and null when the option set is empty. Both are
// passed through Number() below, which is why the previous `number | string`
// never mattered: Number('0') === Number(0), and Number(null) === 0.
interface PageDataSliderSource {
  list: string;
  selected: { min: string | null; max: string | null };
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

const showFilterRatingsValue = pwg_getPageData<boolean | undefined>(
  "show_filter_ratings",
);
const showFilterRatings =
  typeof showFilterRatingsValue === "undefined"
    ? false
    : showFilterRatingsValue;

// Real exports now (docs/PLAN.md P48) -- mcs.ts is the one real
// consumer of most of these, history.ts of `globalParams`/
// `fullnameOfCat` too (both previously bare-global reads, no more
// `window.` latching). `user_rank`/`filesize`/`height`/`width` stay
// module-private -- zero real bare-identifier readers anywhere despite
// being declared here (confirmed via grep, not assumed).
export {
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
};
