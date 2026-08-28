/// <reference types="jqtree" />
// jqtree ships its own real, bundled `.d.ts` (`node_modules/jqtree/src/
// tree.jquery.d.ts`, referenced via its own `package.json`'s `types`
// field) but isn't a `@types/*` package, so it isn't picked up by
// `tsconfig.json`'s `types` array -- this reference directive is what
// actually pulls in its `interface JQuery { tree: IJQTreePlugin; }`
// declaration (P47). That declaration is a *property*, not
// method-shorthand, so the hand-rolled `tree(...)` overloads this file
// used to carry were deleted below -- keeping both would be the same
// "subsequent property declarations must have the same type" conflict
// documented at the `JQueryUI.Datepicker` merge further down.

// Shared ambient types for vendored jQuery-plugin methods that
// @types/jquery doesn't cover (docs/PLAN.md P46). `JQueryStatic`/`JQuery`
// are already global interfaces (see node_modules/@types/jquery), so
// merging into them here needs no `declare global` wrapper -- this file
// has no top-level import/export of its own.
//
// Also the home for genuinely first-party (non-jQuery-plugin) shared
// ambient globals, when a real one turns up during conversion (P46-B
// found window.SwitchBox needs exactly this).
//
// Starts empty -- grows one method/global at a time as each conversion
// batch's real call sites need it, rather than guessing the full list
// up front.

// See `Window.SwitchBox`/`Window._pwgRatingAutoQueue` below for the real
// "queue array, then live handler" shape-shifting story these 2 cover.
interface SwitchBoxQueue {
  push(link: string, box: string): void;
  // Only present during the real "queue array" phase -- the live
  // `{push: fn}` handler switchbox.ts's own IIFE replaces it with has
  // neither.
  length?: number;
  [index: number]: string | undefined;
}
// Duplicates rating.ts's own identically-named, identically-shaped
// local interfaces -- until that file's own P48 module conversion
// (docs/PLAN.md), both were real ambient globals (that file was a
// genuinely non-module IIFE), referenced bare here, not redeclared.
// Now that it's a real module, its own copies are module-private, so
// `RatingAutoQueue` below (which can't `import` from a module file
// without becoming one itself, breaking its own other ambient
// declarations) needs its own local copy -- a pure type duplication,
// safe since both stay structurally identical by construction (rating.ts
// is the only real writer of either shape).
interface PwgRatingResult {
  score: number;
  count: number;
  average?: number;
}
interface PwgRatingOptions {
  rootUrl: string;
  image_id: string | number;
  onSuccess?: (result: PwgRatingResult) => void;
  updateRateElement?: HTMLElement;
  updateRateText?: string;
  ratingSummaryElement?: HTMLElement;
  ratingSummaryText?: string;
}
interface RatingAutoQueue {
  push(opts: PwgRatingOptions): void;
  // Only present during the real "queue array" phase -- see
  // `SwitchBoxQueue`'s own copy of this comment above.
  length?: number;
  [index: number]: PwgRatingOptions | undefined;
}

interface Window {
  // `index.ts`/`picture.ts` push onto this before `switchbox.ts` (loaded
  // later, footer-positioned) replaces it with a live `{push: fn}`
  // handler -- same shape-shifting "queue array, then live handler"
  // pattern as `_pwgRatingAutoQueue` below. Both real shapes agree on
  // "has a `push(link, box)` method" (an array's own real `.push`
  // signature is looser, but every real call site already only ever
  // passes 2 strings either way), and switchbox.ts's own queue-drain
  // read needs the array-like `.length`/`[i]` members too -- one
  // `SwitchBoxQueue` type covers both real phases (P47), not a modeled
  // union: a precise union of the 2 distinct shapes buys no real safety
  // here (every real caller/callee already agrees on the args).
  SwitchBox?: SwitchBoxQueue;

  // `picture.ts` pushes a rating-options object onto this (queue array)
  // if `rating.ts` hasn't loaded yet; `rating.ts`'s own IIFE drains the
  // queue then replaces it with a live `{push: fn}` handler for any
  // later pusher. Same shape-shifting pattern as `SwitchBox` above.
  // Started as a bare (non-`window.`) global, matching the original
  // pre-P46 .js -- 2 real bugs found in sequence via VR against a real
  // browser before landing here: a bare undeclared read threw
  // ReferenceError whenever picture.ts ran first, and `var`-declaring
  // it "fixed" that but broke it a second way once every P46 entry got
  // wrapped in its own IIFE (see vite.config.ts's banner/footer
  // comment) -- a `var` inside that wrapper is scoped to the wrapper,
  // no longer a real global at all. `window.` property access (here,
  // like `SwitchBox` above) is the one form that's safe both ways.
  _pwgRatingAutoQueue?: RatingAutoQueue;

  // Explicit `window.` exposure of these names is required for the
  // same reason documented at each assignment site (page-data.ts,
  // scripts.ts): Vite/Rollup bundles each P46 entry as its own isolated
  // module graph, and a top-level declaration with no call site *inside
  // its own file* looks like dead/private state to the tree-shaker and
  // minifier alike, even though sibling entries call it as a bare
  // global. Declared here (not inferred from the assignment) so every
  // consumer file's own bare reference -- e.g. `pwg_getPageData(...)`
  // in picture.ts, which never imports anything -- type-checks too.
  // `phpWGOpenWindow`/`pwgAddEventListener` no longer need an entry here
  // (docs/PLAN.md P48, scripts.ts's own module conversion) -- real
  // exports now, imported directly by their own real consumers.
  // `popuphelp`/`pwg_tryFocus` stay -- both real, permanent `window.X`
  // exposures even after that conversion (see scripts.ts's own leading
  // comment for why neither converts).
  // eslint-disable-next-line @typescript-eslint/no-unnecessary-type-parameters -- mirrors page-data.ts's own signature; see the note there.
  pwg_getPageData: <T = unknown>(key: string) => T;
  pwg_getPageString: (key: string) => string;
  popuphelp: (url: string) => void;
  pwg_tryFocus: (id: string) => void;

  // search_filters.ts's own page-data-derived globals, all real bare
  // reads confirmed in mcs.ts (P47). `global_params`/`fullname_of_cat`
  // stay `any` -- the parsed JSON's own shape is a complex nested
  // search-filter query object, not first-party logic this phase
  // re-derives real types for (that's P48's job). Every other one here
  // is either a real page-data-typed primitive or a translated string
  // (`pwg_getPageString()`'s own real `string` return), both squarely
  // this phase's own job.
  global_params: any;
  fullname_of_cat: any;
  search_id: string | undefined;
  str_word_widget_label: string;
  str_tags_widget_label: string;
  str_album_widget_label: string;
  str_author_widget_label: string;
  str_added_by_widget_label: string;
  str_filetypes_widget_label: string;
  str_rating_widget_label: string;
  str_no_rating: string;
  str_between_rating: string;
  str_filesize_widget_label: string;
  str_width_widget_label: string;
  str_height_widget_label: string;
  str_ratio_widget_label: string;
  str_ratios_label: Record<string, string>;
  str_expert_widget_label: string;
  str_empty_search_top_alt: string;
  str_empty_search_bot_alt: string;
  str_search_in_ab: string;
  prefix_icon: string;
  sliders: {
    filesizes?: PwgSliderConfig;
    heights?: PwgSliderConfig;
    widths?: PwgSliderConfig;
  };
  show_filter_ratings: boolean;

  // batchManagerGlobal.ts's own 4 functions, called from
  // batch_manager_global.latte's own `href="javascript:
  // selectGenerateDerivAll()"`-style pseudo-protocol links (docs/
  // PLAN.md P46-C's own finding, the one that first surfaced this
  // exposure pattern -- no ambient entry was needed for it until this
  // file itself became a real module (P48), since a global-script
  // file's own top-level function declarations merge into
  // `typeof globalThis` automatically).
  selectGenerateDerivAll: () => void;
  selectGenerateDerivNone: () => void;
  selectDelDerivAll: () => void;
  selectDelDerivNone: () => void;

  // footer.ts's own 2 functions, called from layout.latte's own
  // `onclick="show_user_whats_new()"` / `onClick=
  // "hide_user_whats_new()"` attributes -- same `javascript:`/`onclick=`
  // exposure pattern as batchManagerGlobal.ts's own copy above.
  hide_user_whats_new: () => void;
  show_user_whats_new: () => void;

  // updates_ext.ts's own 4 functions, called from
  // updates_ext.latte's own `onClick="ignoreAll()"` /
  // `onClick="resetIgnored()"` / `onClick="updateExtension(...)"` /
  // `onClick="ignoreExtension(...)"` attributes -- same `javascript:`/
  // `onclick=` exposure pattern as footer.ts's own copy above.
  ignoreAll: () => void;
  resetIgnored: () => void;
  updateExtension: (type: string, id: string, revision: string) => void;
  ignoreExtension: (type: string, id: string) => void;

  // group_list.ts's own 2 functions -- `hideAddGroupForm` is called
  // from group_list.latte's own `onclick="hideAddGroupForm()"`
  // attribute; `updateSelectionPanel` is called from a
  // dynamically-set `onclick`/`OnClick` HTML attribute string this
  // same file builds at runtime (`buttonAvailable()`'s own
  // `button.attr("OnClick", onClick)`) -- the same exposure
  // requirement either way.
  hideAddGroupForm: () => void;
  // Real param type (P47) -- was `any` here from before group_list.ts's
  // own typing pass ever landed; that pass retyped the real function's
  // own signature but never circled back to update this copy.
  updateSelectionPanel: (changedState?: string) => void;

  // user_list.ts's own real public plugin extension point (P47) --
  // JSDoc-documented for third-party plugins to call from their own
  // separately-loaded `<script>` tags.
  plugin_add_tab_in_user_modal: (
    tab_name: string,
    content_id: string,
    users_table?: string | null,
    set_data_function?: ((...args: unknown[]) => unknown) | null,
    get_data_function?: ((...args: unknown[]) => unknown) | null,
  ) => void;
}

// `pwg_token` (the CSRF token) is independently declared per-page by
// whichever file happens to be that page's own top-level script
// (`albums.js`'s own `var pwg_token = pwg_getPageData('csrf_token');`
// is one of several -- same "declared per-page, safe because never
// co-loaded with a conflicting value" pattern as P46-0's own
// `add_related_category` finding). No ambient `declare const` needed
// here at all, unlike every other "consumer converts before its
// declarer" case in this file: `var` (unlike `let`/`const`) allows
// unlimited redeclaration in the same scope, so once ANY one real
// `var pwg_token = ...` exists anywhere in the shared type-checking
// program (`plugins_installed_config.ts` supplied the first, followed
// by every other per-page declarer as it converts), every consumer's
// bare read resolves through it -- confirmed via a real TS2451 error
// the one time an ambient `declare const pwg_token` briefly coexisted
// with `plugins_installed_config.ts`'s own real `var` declaration.

// Vendored standalone-library globals (not jQuery plugins).
// `plupload`'s own namespace/constants/`Uploader` class are now real,
// verified types from `@types/plupload` (P47) -- no `declare const`
// needed here any more; keeping one would silently shadow the real
// package's own global with `any` (confirmed via a local `tsc` repro
// during planning). `Piecon` has no real upstream types (one of P46-0's
// own genuinely-unresolved vendored libraries) and stays hand-typed --
// as a module declaration now that it is imported rather than read off
// a CDN-supplied global. The package is UMD with a `module.exports =
// Piecon` branch, so Vite's CommonJS interop hands back the namespace
// object as the default export. `setOptions` is part of the real API
// too, though this codebase only calls the other two.
declare module "piecon" {
  const Piecon: {
    setProgress(percent: number): void;
    setOptions(options: Record<string, unknown>): void;
    reset(): void;
  };
  export default Piecon;
}

// jquery.geoip.js -- genuinely first-party (docs/PLAN.md's own scope
// note), but excluded from this phase's real 61-file count and not yet
// converted itself. `rating_user.ts`/`history.ts` both call `.get()`
// with their own tooltip-content callback (each independently declaring
// an identical local `GeoIpResult` interface for the real lookup-result
// shape) -- typed here only enough to stop the outer call itself from
// resolving to `any` (P47); the callback's own `data` param stays
// loosely `any` in this ambient signature since the real result shape
// genuinely lives with jquery.geoip.js itself, not here.
declare const GeoIp: {
  get(query: string, callback: (data: any) => void): void;
};

// Chart.js (vendored -- P46-0's own CDN table). Real, verified types
// from `@types/chart.js` (P47) -- no `declare const` needed any more
// (removed; leaving one would silently shadow the real package's own
// global with `any`, confirmed via a local `tsc` repro during
// planning). `stats.ts`'s own graph rendering is the one real
// first-party call site.
//
// moment.js (vendored -- P46-0's own CDN table) ships its own bundled
// `.d.ts` (`node_modules/moment/moment.d.ts`, already an installed npm
// dependency) but declares itself via `export = moment`, not
// `export as namespace` -- it has no global binding of its own, so
// `typeof import(...)` bridges the real npm types to the CDN-loaded
// global the same way `tus` does above. `stats.ts`'s own graph
// rendering is the one real first-party call site.

interface JQueryStatic {
  // jquery.ajaxmanager (vendored, never published to npm -- P46-0's own
  // CDN table) -- `thumbnails.loader.ts`'s own queued-thumbnail loader is
  // the one real first-party call site.
  manageAjax: {
    create(
      name: string,
      options: Record<string, unknown>,
    ): {
      add(options: Record<string, unknown>): void;
    };
    // The other real half of this plugin's API: adds a request to an
    // *already-created*, named queue (by the queue name string, not
    // the object `.create()` returns) -- `batchManagerGlobal.ts`'s own
    // `getDerivativeUrls()` is the one real first-party call site.
    add(queueName: string, options: Record<string, unknown>): void;
  };

  // jquery-confirm (vendored -- P46-0's own CDN table). `common.ts`'s own
  // `pwg_jconfirm_follow_href` is the one real first-party call site so
  // far; every admin page that pops a confirm/alert dialog calls this
  // same `$.confirm(...)` API directly too, so this grows as those
  // convert.
  confirm(options: Record<string, unknown>): void;
  alert(options: Record<string, unknown>): void;

  // Real jQuery-core static helper, just missing from @types/jquery's
  // own typings for this jQuery version. `LocalStorageCache.ts`'s own
  // `AbstractSelectizer._selectize` is the one real first-party call
  // site.
  isNumeric(value: any): boolean;

  // jquery.jgrowl (vendored -- P46-0's own CDN table). `updates_ext.ts`'s
  // own update/ignore-extension toast notifications are the one real
  // first-party call site.
  jGrowl(text: string, options?: Record<string, unknown>): void;

  // jquery-ui-timepicker-addon (vendored -- P46-0's own CDN table, no
  // real upstream types). `datepicker.ts`'s own `pwgDatepicker` plugin
  // is the one real first-party call site. `JQueryStatic.datepicker`
  // itself is no longer declared here -- see the `JQueryUI.Datepicker`
  // merge below, required because `@types/jqueryui` (P47) now declares
  // `JQueryStatic.datepicker: JQueryUI.Datepicker` itself, and
  // re-declaring the same property with a different from-scratch type
  // here would be a real "subsequent property declarations must have
  // the same type" compile error.
  timepicker: { log: any };
}

// `@types/jqueryui` (P47) declares `JQueryStatic.datepicker:
// JQueryUI.Datepicker`, but its public API has no home for the
// internal engine methods `datepicker.ts`'s own `pwgDatepicker` plugin
// patches (`_generateMonthYearHeader`/`_selectMonthYear`/`parseDateTime`/
// `parseDate`) -- merged directly onto the real `JQueryUI.Datepicker`
// interface instead of re-declaring `JQueryStatic.datepicker` from
// scratch (which would conflict, per the note above). Confirmed a real,
// mergeable `interface`, not a `type` alias, via a local `tsc` repro
// during planning.
declare namespace JQueryUI {
  interface Datepicker {
    _generateMonthYearHeader(...args: any[]): any;
    _selectMonthYear(...args: any[]): any;
    parseDateTime(
      dateFormat: string,
      timeFormat: string,
      value: string,
      ...args: any[]
    ): any;
    parseDate(format: string, value: string, ...args: any[]): any;
  }
}

// The real shape `datepicker.ts`'s own `pwgDatepicker` plugin accepts
// (P47) -- confirmed against every real call site
// (batchManagerGlobal.ts/batchManagerUnit.ts/picture_modify.ts/
// history.ts's own zero-arg call).
interface PwgDatepickerSettings {
  showTimepicker?: boolean;
  cancelButton?: string | false;
}

// jqtree's own bundled `IJQTreePlugin` (referenced above) never typed
// its real `"getState"` command -- an upstream typings gap, not
// something we can edit in `node_modules` directly. `albums.ts`'s own
// album tree reads `.tree("getState").open_nodes` -- confirmed against
// jqtree's real runtime source (`save_state_handler.js`'s own
// `getState()`) that this returns `{ open_nodes, selected_node }`,
// both arrays of the tree's own `INode.id` type (`number | string`).
// `IJQTreePlugin` is a real, mergeable top-level interface, so this
// adds the one missing overload without touching the vendored file.
interface IJQTreePlugin {
  (behavior: "getState"): {
    open_nodes: (number | string)[];
    selected_node: (number | string)[];
  };
}

// jqtree's own bundled `INode` (referenced above) never typed 2 more
// real, well-known Node-prototype methods (`getLevel()`/
// `getPreviousSibling()`) -- `albums.ts`'s own `getId()`/`getRank()`/
// `getPathNode()` all call these (P47). Same "real mergeable interface,
// one missing overload" fix as `IJQTreePlugin`'s own `getState()` above.
interface INode {
  getLevel(): number;
  getPreviousSibling(): INode | null;
}

// `albums.ts`'s own real per-row shape (P47), traced to
// AlbumsPageRenderer.php's own `assocToOrderedTree()` -- the raw JSON
// tree fed into `.tree({data: ...})` and returned by `pwg_getPageData
// ("album_data")`, *before* jqtree wraps each row into a live `INode`.
// `load_on_demand`/`haveChildren` are client-side-only fields albums.ts
// itself adds when reshaping a row for lazy loading -- never present in
// the raw PHP payload.
interface AlbumTreeNode {
  id: string;
  rank: string | number | null;
  name: string;
  status: string;
  visible: string;
  uppercats: string;
  nb_images: number;
  last_updates: string;
  has_not_access: boolean;
  nb_sub_photos: number;
  nb_subcats?: number;
  children?: AlbumTreeNode[];
  load_on_demand?: boolean;
  haveChildren?: AlbumTreeNode[];
}

// The *live* jqtree node shape once `.tree()` has wrapped a raw
// `AlbumTreeNode` -- jqtree copies every own-property of the raw data
// object onto the resulting node alongside its own real `INode` fields
// (`.parent`/`.getLevel()`/`.iterate()`/etc.), so both sets are real,
// simultaneously-present properties on every node `getNodeById()`/
// `onCreateLi` ever hands back. `INode`'s own `[key: string]: any`
// index signature (jqtree's own bundled `.d.ts`, not editable here)
// otherwise makes every one of these fields resolve to `any`.
interface AlbumJqTreeNode extends INode {
  rank: string | number | null;
  status: string;
  visible: string;
  uppercats: string;
  nb_images: number;
  last_updates: string;
  has_not_access: boolean;
  nb_sub_photos: number;
  nb_subcats?: number;
  load_on_demand: boolean | null;
  haveChildren?: AlbumTreeNode[];
  parent: AlbumJqTreeNode | null;
  children: AlbumJqTreeNode[];
  getPreviousSibling(): AlbumJqTreeNode | null;
}

interface JQuery {
  // Real jQuery-core instance method (deprecated since 1.8, but still
  // present in the vendored 1.11.3 runtime -- @types/jquery never typed
  // it even in legacy.d.ts). `plugins_installated.ts`'s own
  // `jQuery("div.active").size()` is the one real first-party call site.
  size(): number;

  // selectize.js's own `.selectize()` init method is now real, verified
  // types from `@types/selectize` (P47) -- deleted here (was
  // method-shorthand on both sides, harmless to leave, but redundant).
  // `@types/selectize` also declares `interface HTMLElement { selectize:
  // Selectize.IApi<any, any>; }` globally itself, so the live-instance
  // property (`$el[0].selectize`) needs no ambient entry here either.

  // jquery.cluetip (vendored, github-sourced -- P46-0's own CDN table).
  // `intro.ts`'s own tooltip setup is the one real first-party call
  // site so far.
  cluetip(options?: Record<string, unknown>): JQuery;

  // common.ts's own 2 first-party `jQuery.fn` extensions (not vendored
  // plugins).
  fontCheckbox(): JQuery;
  pwg_jconfirm_follow_href(options?: {
    alert_title?: string;
    alert_confirm?: string;
    alert_cancel?: string;
    alert_content?: string;
  }): void;

  // admin.ts's own first-party `jQuery.fn.lightAccordion` extension --
  // declared and consumed within the same file, no other real call
  // site found. Options interface lives here (not in admin.ts itself)
  // since admin.ts is a module (`export {}`) and this ambient
  // declaration needs the same shape (P47).
  lightAccordion(options?: LightAccordionOptions): JQuery;

  // jQuery UI's own `slider` widget (vendored -- the one full-bundle
  // `jquery.ui` id) is now real, verified types from `@types/jqueryui`
  // (P47) -- deleted here (method-shorthand on both sides, harmless to
  // leave, but redundant). `doubleSlider.ts`'s own `pwgDoubleSlider`
  // extension (declared separately below) is built on it.

  // jquery.tipTip (vendored -- P46-0's own CDN table, no real upstream
  // types). `batchManagerGlobal.ts`'s own tooltip setup is the one real
  // first-party call site. `.colorbox()` is now real, verified types
  // from `@types/jquery.colorbox` (P47) -- deleted here: it was
  // declared as a *property* by the real package (`colorbox: Colorbox`)
  // vs. method-shorthand here, a real "duplicate identifier" conflict
  // if both were left in place (confirmed via a local `tsc` repro
  // during planning), not just redundant like `.slider()` above.
  tipTip(options?: Record<string, unknown>): JQuery;

  // jquery.Jcrop (vendored -- P46-0's own CDN table). `picture_coi.ts`'s
  // own crop-of-interest setup is the one real first-party call site.
  // The optional callback runs with `this` bound to the real Jcrop API
  // object -- `.animateTo(...)` is the one real method called (P47),
  // not the jQuery collection.
  Jcrop(
    options: Record<string, any>,
    callback?: (this: { animateTo(coords: number[]): void }) => void,
  ): JQuery;

  // `datepicker.ts`'s own first-party `jQuery.fn.pwgDatepicker`
  // extension and `addAlbum.ts`'s own `jQuery.fn.pwgAddAlbum` -- both
  // files converted (docs/PLAN.md P46-C), `batchManagerGlobal.ts` was
  // the first *consumer*-only file that needed the ambient type
  // without declaring it itself (same reasoning as `pwg_token`, P46-C's
  // own `album_selector.ts`).
  pwgDatepicker(options?: PwgDatepickerSettings): JQuery;
  pwgAddAlbum(options?: Record<string, unknown>): JQuery;

  // jQuery UI core datepicker + jquery-ui-timepicker-addon's own
  // combined `.fn` widget method (vendored -- same pair as
  // `JQueryStatic.datepicker`/`.timepicker` above). `datepicker.ts`'s
  // own `pwgDatepicker` plugin is the one real first-party call site;
  // every one of its many distinct command-string overloads collapses
  // to one loose signature, matching `.sortable()`/`.slider()`'s own
  // treatment above.
  datetimepicker(...args: any[]): any;
  datepicker(...args: any[]): any;

  // datatables.net (vendored -- P46-0's own CDN table). `rating_user.ts`'s
  // own user-rating list is the one real first-party call site so far.
  // `.dataTable()` is the setup call; `.DataTable()` returns the real
  // API object (`.row(...).remove().draw()`), loosely typed like every
  // other vendored jQuery UI/plugin API surface in this file.
  dataTable(options?: Record<string, unknown>): JQuery;
  DataTable(options?: Record<string, unknown>): any;

  // jQuery UI's own `tooltip` widget (vendored -- the one full-bundle
  // `jquery.ui` id) is now real, verified types from `@types/jqueryui`
  // (P47) -- deleted here (method-shorthand on both sides, harmless to
  // leave, but redundant). `rating_user.ts`'s own GeoIP-lookup tooltip
  // is the one real first-party call site.

  // jquery.sort.js (the well-known `jQuery.fn.sortElements` snippet --
  // one of the genuinely-unresolved libraries P46-0's own CDN
  // migration kept vendored, no real package/repo found anywhere).
  // `plugins_new.ts`'s own plugin-list sort is the one real
  // first-party call site.
  sortElements(comparator: (a: any, b: any) => number): JQuery;

  // jqtree's own `.tree()` is now real, verified types from its own
  // bundled `.d.ts` (`/// <reference types="jqtree" />` at the top of
  // this file) -- deleted here: jqtree declares `tree` as a *property*
  // (`tree: IJQTreePlugin`), so leaving this file's own method-shorthand
  // `tree(...)` overloads in place would be a real "duplicate
  // identifier" conflict, not just redundant. `albums.ts`'s own album
  // tree is the one real first-party call site.

  // `batchManagerGlobal.ts`'s own first-party `jQuery.fn` extension --
  // declared and consumed within the same file, no other real call
  // site found.
  enableShiftClick(): JQuery;

  // `doubleSlider.ts`'s own first-party `jQuery.fn.pwgDoubleSlider`
  // extension -- `batchManagerFilter.ts` was the first *consumer*-only
  // file that needed the ambient type without declaring it itself
  // (same reasoning as `pwgDatepicker`/`pwgAddAlbum` above).
  // `PwgDoubleSliderOptions` (below) replaces the former
  // `Record<string, unknown>` (P47) -- declared here, not inside
  // doubleSlider.ts's own IIFE, since this ambient signature needs the
  // same shape.
  pwgDoubleSlider(options?: PwgDoubleSliderOptions): JQuery;

  // plupload's own jQuery-UI queue widget (vendored -- P46-0's own CDN
  // table). `photos_add_direct.ts`'s own upload-queue setup is the one
  // real first-party call site.
  pluploadQueue(options?: Record<string, unknown>): JQuery;

  // jquery.autogrow-textarea.js (vendored, one of the 3 genuinely-
  // unresolved libraries P46-0's own CDN migration kept vendored --
  // see docs/PLAN.md's own "2 libraries remain genuinely unresolvable"
  // note). `autosize.ts`'s own textarea auto-grow setup is the one real
  // first-party call site.
  autogrow(): JQuery;

  // jQuery UI's `sortable` widget (vendored -- the one full-bundle
  // `jquery.ui` id) is now real, verified types from `@types/jqueryui`
  // (P47) -- deleted here (method-shorthand on both sides, harmless to
  // leave, but redundant). `menubar.ts`'s own drag-to-reorder menu
  // setup is the one real first-party call site.
}

// admin.ts's own `jQuery.fn.lightAccordion` options shape (P47) --
// declared here rather than in admin.ts itself, since admin.ts is a
// module (`export {}`) and the `interface JQuery` augmentation above
// needs the same type.
interface LightAccordionOptions {
  header?: string;
  content?: string;
  active?: number;
}

// `album_selector.ts`'s own real `class AlbumSelector` public surface
// (P47) -- mirrors `TemporaryStateCtor`'s own precedent above. Kept to
// the methods real consumers actually call; the real class's own
// internal implementation stays loosely typed until its own P47-B turn.
interface AlbumSelectorInstance {
  open(): void;
  close(): void;
  remove_selected_album(id: string | number): void;
  // Genuinely mixed at runtime (P47): most real callers only ever push
  // stringified ids (`select_album()`'s own `id.toString()`), but the
  // internal ajax-driven pick/create flows push a raw numeric
  // `cat.id` -- matching `remove_selected_album`/`select_album`'s own
  // already-established `string | number` id type above.
  get_selected_albums(): (string | number)[];
  select_album(id: string | number): void;
  resetAll(): void;
  hardUpdate(cats: (string | number)[]): void;
}

// The real constructor options `class AlbumSelector`'s own destructured
// param accepts (P47) -- every field optional, matching that
// constructor's own default values; confirmed against every real
// `new AlbumSelector({...})` call site.
interface AlbumSelectorOptions {
  selectedCategoriesIds?: (string | number)[];
  selectAlbum?: (args: AlbumSelectorCallbackArgs) => void;
  removeSelectedAlbum?: (args: AlbumSelectorRemoveCallbackArgs) => void;
  showRootButton?: boolean;
  adminMode?: boolean;
  limitParam?: number;
  currentAlbumId?: string | number;
  modalTitle?: string;
  modalSearchPlaceholder?: string;
}

// The real shape `album_selector.ts`'s own constructor passes to a
// consumer's `selectAlbum` callback (P47) -- confirmed by reading the
// constructor's own wrapping (`this.#selectAlbum = (args) =>
// selectAlbum.call(null, { ...args, newSelectedAlbum, addSelectedAlbum,
// getSelectedAlbum })`), not guessed. Shared across every real
// `selectAlbum:` consumer (`batchManagerFilter.ts`, `cat_modify.ts`,
// `batchManagerGlobal.ts`, `mcs.ts`, `batchManagerUnit.ts`,
// `picture_modify.ts`, `photos_add_direct.ts`) -- grows if a future
// consumer needs more of `album`'s own real shape than `id`/`name`.
interface AlbumSelectorCallbackArgs {
  album: {
    id: string | number;
    name?: string;
    root?: string;
    // The breadcrumb display name, HTML-stripped, as
    // CategoryListController builds it. Was
    // `full_name_with_admin_links` until it turned out that field does
    // not exist: the controller's own docblock records dropping the
    // XML-era `additional_output=full_name_with_admin_links` opt-in, so
    // every consumer reading it got `undefined` at runtime while this
    // declaration kept it compiling. All four consumers are
    // `adminMode: true`, which is the mode that carries `fullname`.
    fullname?: string;
    // The same breadcrumb, per segment, for the three consumers that render
    // it as links the way their own server-rendered rows do.
    breadcrumb?: { id: string; name: string }[];
  };
  // Response-level, not per-album: the separator the server joins breadcrumb
  // segments with.
  levelSeparator: string;
  newSelectedAlbum: () => void;
  addSelectedAlbum: (...args: any[]) => void;
  getSelectedAlbum: () => (string | number)[];
}

// The real shape `album_selector.ts`'s constructor passes to a
// consumer's `removeSelectedAlbum` callback (P47) -- confirmed via the
// same constructor wrapping as `AlbumSelectorCallbackArgs` above
// (`this.#removeSelectedAlbum = (args) => removeSelectedAlbum.call(null,
// { ...args, getSelectedAlbum })`), called with `{ id_album: id }` from
// `remove_selected_album(id)`.
interface AlbumSelectorRemoveCallbackArgs {
  id_album: string | number;
  getSelectedAlbum: () => (string | number)[];
}

// `intro.ts`'s own `storage_details` shared global (P47) -- real shape
// traced to `IntroView.php`'s `storage_chart_data` property and the
// actual fields `intro.ts`/`intro_tooltips.ts` both read.
interface StorageDetails {
  [type: string]: {
    total: { filesize: number; nb_files: number };
    details?: Record<string, { filesize: number; nb_files: number }>;
  };
}

// `doubleSlider.ts`'s own real options shape (P47) -- matches its own
// leading docblock comment (`values {mixed[]}`, `selected {object} min
// and max`, `text {string}`) and every real caller's own literal shape
// (`batchManagerFilter.ts`'s `sliders.widths`/etc.).
interface PwgDoubleSliderOptions {
  values: number[];
  selected: { min: number; max: number };
  text: string;
}
