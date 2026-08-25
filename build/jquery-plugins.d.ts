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

interface Window {
  // `index.ts`/`picture.ts` push onto this before `switchbox.ts` (loaded
  // later, footer-positioned) replaces it with a live `{push: fn}`
  // handler -- same shape-shifting "queue array, then live handler"
  // pattern as `_pwgRatingAutoQueue` below. `any` rather than a modeled
  // union: both the array-push and live-handler-push call sites already
  // agree on "2 string/Element args," so a precise union buys no real
  // safety here.
  SwitchBox?: any;

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
  _pwgRatingAutoQueue?: any;

  // Explicit `window.` exposure of these 6 names is required for the
  // same reason documented at each assignment site (page-data.ts,
  // scripts.ts): Vite/Rollup bundles each P46 entry as its own isolated
  // module graph, and a top-level declaration with no call site *inside
  // its own file* looks like dead/private state to the tree-shaker and
  // minifier alike, even though sibling entries call it as a bare
  // global. Declared here (not inferred from the assignment) so every
  // consumer file's own bare reference -- e.g. `pwg_getPageData(...)`
  // in picture.ts, which never imports anything -- type-checks too.
  pwg_getPageData: <T = unknown>(key: string) => T;
  pwg_getPageString: (key: string) => string;
  phpWGOpenWindow: (theURL: string, winName: string, features: string) => void;
  popuphelp: (url: string) => void;
  pwgAddEventListener: (
    elem: EventTarget,
    evt: string,
    fn: EventListenerOrEventListenerObject,
  ) => void;
  pwg_tryFocus: (id: string) => void;

  // search_filters.ts's own page-data-derived globals, all real bare
  // reads confirmed in (still-.js, not yet type-checked) mcs.js --
  // `any` throughout: these are page-data-JSON-derived or translated
  // strings, not first-party logic this phase re-derives real types
  // for (that's P48's job, not P46's).
  global_params: any;
  fullname_of_cat: any;
  search_id: any;
  str_word_widget_label: any;
  str_tags_widget_label: any;
  str_album_widget_label: any;
  str_author_widget_label: any;
  str_added_by_widget_label: any;
  str_filetypes_widget_label: any;
  str_rating_widget_label: any;
  str_no_rating: any;
  str_between_rating: any;
  str_filesize_widget_label: any;
  str_width_widget_label: any;
  str_height_widget_label: any;
  str_ratio_widget_label: any;
  str_ratios_label: any;
  str_expert_widget_label: any;
  str_empty_search_top_alt: any;
  str_empty_search_bot_alt: any;
  str_search_in_ab: any;
  prefix_icon: any;
  sliders: any;
  show_filter_ratings: any;

  // common.ts's own established shared-global set (P46-A/eslint.config.ts's
  // pre-existing globals block for this exact file, now enforced by real
  // exposure instead of just an ESLint no-undef exemption).
  array_delete: (arr: any[], item: any) => void;
  str_repeat: (i: string, m: number) => string;
  getRandomInt: (min: number, max: number) => number;
  sprintf: (...args: any[]) => string;
  jConfirm_alert_options: Record<string, unknown>;
  jConfirm_confirm_options: Record<string, unknown>;
  jConfirm_warning_options: Record<string, unknown>;
  jConfirm_confirm_with_content_options: Record<string, unknown>;
  // A real ES6 `class`, not a plain function/object like the rest of this
  // interface -- `typeof TemporaryStateCtor` (a constructor type) is what
  // lets a consumer file write `new TemporaryState()` against the bare
  // global the same way it already could pre-P46.
  TemporaryState: TemporaryStateCtor;

  // LocalStorageCache.ts's own established shared-global set (same
  // eslint.config.ts pre-existing globals block, same "now enforced by
  // real exposure" note as common.ts's own copy of this comment).
  // Loosely typed on purpose: these 4 are old-style prototype-chain
  // "classes" (`Foo.prototype = new Bar()`), not real ES6 classes --
  // modeling the actual inheritance chain precisely isn't this phase's
  // job (P46 stays "same code, same behavior," not a real typing
  // refactor); `any` options in, an object with a real `selectize()`
  // method out is all a consumer ever needs from the ambient type.
  CategoriesCache: new (options: any) => {
    selectize($target: JQuery, options?: any): void;
  };
  TagsCache: new (options: any) => {
    selectize($target: JQuery, options?: any): void;
  };
  GroupsCache: new (options: any) => {
    selectize($target: JQuery, options?: any): void;
  };
  UsersCache: new (options: any) => {
    selectize($target: JQuery, options?: any): void;
  };
  // The base class the 4 above inherit from -- exported (`exports.
  // LocalStorageCache = ...`) same as the others in the real pre-P46
  // .js, even though no other real file reads it bare today; typed for
  // the same "same code" completeness reason, not because it's used.
  LocalStorageCache: any;

  // album_selector.ts's own established shared-global set (confirmed via
  // the P46-C full 61-file sweep, not this plan's earlier 5-minute
  // spot-check -- see docs/PLAN.md's own sweep writeup for the full
  // per-name evidence).
  str_albums_found: string;
  str_result_limit: string;
  AlbumSelector: new (options: Record<string, any>) => any;

  // intro.ts's own established shared-global set (matches this plan's
  // own earlier 5-minute spot-check finding, confirmed by full reading
  // during the P46-C sweep).
  str_gb: string;
  str_mb: string;
  storage_details: Record<string, any>;
  translate_files: string;
  translate_type: Record<string, string>;

  // batch_manager_global.ts / batchManagerGlobal.ts's own genuinely
  // bidirectional shared-global set (docs/PLAN.md P46-C's own full
  // sweep -- see both files' own leading comments for the real
  // ordering-safety analysis, not just this ambient typing).
  lang: {
    Cancel: string;
    deleteProgressMessage: string;
    syncProgressMessage: string;
    AreYouSure: string;
    generateMsg: string;
  };
  all_elements: any[];
  str_add_alb_associate: string;
  str_select_alb_associate: string;
  derivatives: {
    elements: any[] | null;
    done: number;
    total: number;
    finished(): boolean;
  };
  progress_start: () => void;
  progress: (success?: boolean) => void;
  getDerivativeUrls: () => void;

  // plugins_installed_config.ts's own shared-global set
  // (docs/PLAN.md P46-C's full sweep) -- plugins_installated.ts reads
  // every one of these bare. `window.` exposure here is required at
  // runtime (not just documentation): see plugins_installed_config.ts's
  // own leading comment for the real IIFE-wrapping bug this caught.
  incompatible_msg: string;
  activate_msg: string;
  deactivate_all_msg: string;
  pwg_token: string;
  nb_plugin: { all: number; active: number; inactive: number; other: number };
  confirm_msg: string;
  cancel_msg: string;
  delete_plugin_msg: string;
  deleted_plugin_msg: string;
  restore_plugin_msg: string;
  uninstall_plugin_msg: string;
  plugin_added_str: string;
  plugin_deactivated_str: string;
  plugin_restored_str: string;
  plugin_action_error: string;
  not_webmaster: string;
  nothing_found: string;
  x_plugins_found: string;
  plugin_found: string;
  isWebmaster: number;
  str_restore_def: string;
  show_details: boolean;
  plugin_filter: string | null;

  // footer.ts's own 2 functions, called from layout.latte's own
  // `onclick="show_user_whats_new()"` / `onClick=
  // "hide_user_whats_new()"` attributes -- the `javascript:`/`onclick=`
  // exposure pattern (docs/PLAN.md P46-C's own finding, first surfaced
  // by batchManagerGlobal.ts's own selectGenerateDerivAll/etc.).
  hide_user_whats_new: () => void;
  show_user_whats_new: () => void;

  // updates_ext.ts's own 4 functions, called from
  // updates_ext.latte's own `onClick="ignoreAll()"` /
  // `onClick="resetIgnored()"` / `onClick="updateExtension(...)"` /
  // `onClick="ignoreExtension(...)"` attributes -- same `javascript:`/
  // `onclick=` exposure pattern as footer.ts's own copy above.
  ignoreAll: () => void;
  resetIgnored: () => void;
  updateExtension: (type: any, id: any, revision: any) => void;
  ignoreExtension: (type: any, id: any) => void;

  // albums.ts's own shared-global set (docs/PLAN.md P46-C's full
  // sweep) -- cat_search.ts reads both of these bare.
  data: any;
  str_album_found: string;

  // group_list.ts's own 2 functions -- `hideAddGroupForm` is called
  // from group_list.latte's own `onclick="hideAddGroupForm()"`
  // attribute; `updateSelectionPanel` is called from a
  // dynamically-set `onclick`/`OnClick` HTML attribute string this
  // same file builds at runtime (`buttonAvailable()`'s own
  // `button.attr("OnClick", onClick)`) -- the same exposure
  // requirement either way.
  hideAddGroupForm: () => void;
  updateSelectionPanel: (changedState?: any) => void;

  // toaster.ts's own function -- profile.ts calls this bare (P46-G).
  pwgToaster: (info: any) => void;

  // Chart.js (vendored -- P46-0's own CDN table, real types from
  // `@types/chart.js`, P47). `@types/chart.js`'s own `.d.ts` has a real
  // top-level `import` of its own (for `moment`'s types), which makes
  // it an ambient *module* -- its `export as namespace Chart` UMD
  // global is therefore only referenceable bare from a *non*-module
  // file (TS2686 otherwise). Every P46 entry except the 4 established
  // shared-global files is a module (`export {}`), so `stats.ts` (the
  // one real first-party call site) reads this `window.` property
  // instead of the bare identifier -- same exposure pattern as every
  // other cross-file/cross-module-boundary global in this file.
  Chart: typeof Chart;
}

// batch_manager_global.ts reads these 4 as *bare* identifiers (deferred,
// inside its own `#applyAction` click handler only -- confirmed safe
// regardless of load order). Only the 3 functions need a `declare
// function` binding here, same reasoning as page-data.ts's own copy of
// this comment: TS allows a `declare function` to coexist with a real
// `function` declaration of the same name elsewhere (function
// declaration merging), so both this ambient signature and
// batchManagerGlobal.ts's own real one are valid together. `derivatives`
// itself does NOT get one: it's declared with `const` there, and `let`/
// `const` (unlike `function`) flatly disallow being redeclared by
// anything, ambient or not, in the same global scope -- confirmed via a
// real TS2451 "Cannot redeclare block-scoped variable" error. No
// ambient binding is needed for it anyway: every `themes/**/*.ts` file
// is one shared global type-checking scope regardless of module
// bundling, so batchManagerGlobal.ts's own real `const derivatives`
// declaration already makes the bare name resolve correctly for
// batch_manager_global.ts's own type-checking, with zero ambient help.
declare function progress_start(): void;
declare function progress(success?: boolean): void;
declare function getDerivativeUrls(): void;

// Declared outside `Window` (TS doesn't allow forward-referencing a
// same-file class-as-type from inside an interface merge) -- mirrors
// common.ts's own `TemporaryState` class shape exactly. Kept minimal
// (method signatures only) since the real implementation lives in
// common.ts; this is purely the ambient type consumer files need.
interface TemporaryStateCtor {
  new (): {
    attrChanges: {
      object: JQuery;
      attribute: string;
      value: string | undefined;
    }[];
    classChanges: { object: JQuery; state: boolean; class: string }[];
    htmlChanges: { object: JQuery; html: string }[];
    changeAttribute(obj: JQuery, attr: string, tempVal: string): void;
    changeClass(obj: JQuery, st: boolean, tempclass: string): void;
    addClass(obj: JQuery, tempclass: string): void;
    removeClass(obj: JQuery, tempclass: string): void;
    changeHTML(obj: JQuery, temphtml: string): void;
    reverse(): void;
  };
}

// These 4 (declared as real functions in page-data.ts/scripts.ts,
// exposed onto `window` there) are also called as *bare* identifiers by
// consumer files (picture.ts calls `phpWGOpenWindow`, rating.ts calls
// `pwgAddEventListener`, several call `pwg_getPageData`/
// `pwg_getPageString`) -- those files never write `window.` themselves,
// relying on the same "global script, no module scope" assumption every
// pre-P46 .js file already relied on. Declaring them as ambient
// `declare function` bindings (not just `Window` properties above) is
// what makes the bare reference type-check in every *consuming* file.
// Add the other 2 (`popuphelp`/`pwg_tryFocus`) here too the first time a
// converted .ts file actually calls one of them bare -- no real
// consumer yet, so no ambient binding needed for them yet either.
declare function pwg_getPageData<T = unknown>(key: string): T;
declare function pwg_getPageString(key: string): string;
declare function phpWGOpenWindow(
  theURL: string,
  winName: string,
  features: string,
): void;
declare function pwgAddEventListener(
  elem: EventTarget,
  evt: string,
  fn: EventListenerOrEventListenerObject,
): void;
declare function sprintf(...args: any[]): string;

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
// own genuinely-unresolved vendored libraries) and stays hand-typed.
// `tus` is real, verified via `tus-js-client`'s own bundled `.d.ts`
// (already an installed npm dependency) -- `typeof import(...)` gives
// the real named-export shape (`Upload`, `isSupported`, etc.) the CDN
// global mirrors.
declare const Piecon: { setProgress(percent: number): void; reset(): void };
declare const tus: typeof import("tus-js-client");

// jquery.geoip.js -- genuinely first-party (docs/PLAN.md's own scope
// note), but excluded from this phase's real 61-file count and not yet
// converted itself. `rating_user.ts`'s own tooltip content callback is
// the one real first-party call site that needs the ambient type in
// the meantime.
declare const GeoIp: any;

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
declare const moment: typeof import("moment");

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
  // site found.
  lightAccordion(options?: Record<string, any>): JQuery;

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
  // object (`.animateTo(...)` etc.), not the jQuery collection.
  Jcrop(options: Record<string, any>, callback?: (this: any) => void): JQuery;

  // `datepicker.ts`'s own first-party `jQuery.fn.pwgDatepicker`
  // extension and `addAlbum.ts`'s own `jQuery.fn.pwgAddAlbum` -- both
  // files converted (docs/PLAN.md P46-C), `batchManagerGlobal.ts` was
  // the first *consumer*-only file that needed the ambient type
  // without declaring it itself (same reasoning as `pwg_token`, P46-C's
  // own `album_selector.ts`).
  pwgDatepicker(options?: Record<string, unknown>): JQuery;
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
  pwgDoubleSlider(options?: Record<string, unknown>): JQuery;

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
