// Ambient declarations for genuinely first-party shared global state:
// `interface Window` below, plus a few standalone-library/shared-shape
// types. Nothing here augments a third-party package's own ambient
// types. `Window.SwitchBox`/`SwitchBoxQueue` and
// `Window._pwgRatingAutoQueue`/`RatingAutoQueue`/`PwgRatingResult`/
// `PwgRatingOptions` -- 2 formerly-real copies of this same
// queue-based deferred-init pattern -- both retired (P51-H): the
// former outright (both real pushers, index.ts/picture.ts, always
// imported switchbox.ts before their own first push, so its own
// queue-drain branch could never see anything actually queued), the
// latter onto a real shared module (`ratingAutoQueue.ts`, still real
// coordination since picture.ts/rating.ts share no `import` edge with
// each other).

interface Window {
  // Explicit `window.` exposure of these names is required: Vite/Rollup
  // bundles each entry as its own isolated module graph, and a
  // top-level declaration with no call site *inside its own file* looks
  // like dead/private state to the tree-shaker and minifier alike, even
  // though a sibling entry calls it as a bare global. Declared here (not
  // inferred from the assignment) so every consumer file's own bare
  // reference type-checks too. `pwg_getPageData`/`pwg_getPageString`'s
  // own ambient entries and the 23-member search_filters.ts-derived
  // block below them were both confirmed-dead and deleted here (P51-H):
  // every real consumer imports them directly now (`picture.ts` for the
  // former; `mcs.ts` for the latter, whose own P51-G camelCase renames
  // -- `globalParams`/`fullnameOfCat`/`searchId`/etc. -- left these
  // snake_case ambient names doubly stale).
  popuphelp: (url: string) => void;
  pwg_tryFocus: (id: string) => void;

  // batchManagerGlobal.ts's own 4 functions, called from
  // batch_manager_global.latte's own `href="javascript:
  // selectGenerateDerivAll()"`-style pseudo-protocol links.
  selectGenerateDerivAll: () => void;
  selectGenerateDerivNone: () => void;
  selectDelDerivAll: () => void;
  selectDelDerivNone: () => void;

  // footer.ts's own 2 functions, called from layout.latte's own
  // `onclick="show_user_whats_new()"` / `onClick=
  // "hide_user_whats_new()"` attributes -- same `javascript:`/`onclick=`
  // exposure pattern as batchManagerGlobal.ts's own copy above.
  hide_user_whats_new: () => Promise<void>;
  show_user_whats_new: () => void;

  // updates_ext.ts's own 4 functions, called from
  // updates_ext.latte's own `onClick="ignoreAll()"` /
  // `onClick="resetIgnored()"` / `onClick="updateExtension(...)"` /
  // `onClick="ignoreExtension(...)"` attributes -- same `javascript:`/
  // `onclick=` exposure pattern as footer.ts's own copy above.
  ignoreAll: () => void;
  resetIgnored: () => Promise<void>;
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
  updateSelectionPanel: (changedState?: string) => void;

  // user_list.ts's own public plugin extension point -- JSDoc-documented
  // for third-party plugins to call from their own separately-loaded
  // `<script>` tags.
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
// is one of several -- declared per-page, safe because no two
// declarers ever co-load on the same page). No ambient `declare const`
// needed here at all, unlike every other "consumer needs its declarer
// typed ambiently" case in this file: `var` (unlike `let`/`const`)
// allows unlimited redeclaration in the same scope, so once any one
// real `var pwg_token = ...` exists anywhere in the shared
// type-checking program, every consumer's bare read resolves through
// it. An ambient `declare const pwg_token` here would conflict with
// that real `var` declaration (TS2451), so none is declared.

// Vendored standalone-library globals (not jQuery plugins).
// `plupload`'s own namespace/constants/`Uploader` class are real,
// verified types from `@types/plupload` -- no `declare const` needed
// here; one would silently shadow the real package's own global with
// `any`. `Piecon` has no real upstream types and stays hand-typed, as a
// module declaration since it's imported rather than read off a
// CDN-supplied global. The package is UMD with a `module.exports =
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

// `album_selector.ts`'s own `class AlbumSelector` public surface --
// kept to the methods real consumers actually call; the class's own
// internal implementation stays loosely typed.
interface AlbumSelectorInstance {
  open(): void;
  close(): void;
  removeSelectedAlbum(id: string | number): void;
  // Mixed at runtime: most real callers only ever push stringified ids
  // (`selectAlbum()`'s own `id.toString()`), but the internal
  // ajax-driven pick/create flows push a raw numeric `cat.id` --
  // matching `removeSelectedAlbum`/`selectAlbum`'s own already-
  // established `string | number` id type above.
  getSelectedAlbums(): (string | number)[];
  selectAlbum(id: string | number): void;
  resetAll(): void;
  hardUpdate(cats: (string | number)[]): void;
}

// The constructor options `class AlbumSelector`'s own destructured
// param accepts -- every field optional, matching that constructor's
// own default values.
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

// The shape `album_selector.ts`'s own constructor passes to a
// consumer's `selectAlbum` callback (`this.#selectAlbum = (args) =>
// selectAlbum.call(null, { ...args, newSelectedAlbum, addSelectedAlbum,
// getSelectedAlbum })`). Shared across every real `selectAlbum:`
// consumer (`batchManagerFilter.ts`, `cat_modify.ts`,
// `batchManagerGlobal.ts`, `mcs.ts`, `batchManagerUnit.ts`,
// `picture_modify.ts`, `photos_add_direct.ts`) -- grows if a future
// consumer needs more of `album`'s own real shape than `id`/`name`.
interface AlbumSelectorCallbackArgs {
  album: {
    id: string | number;
    name?: string;
    root?: string;
    // The breadcrumb display name, HTML-stripped, as
    // CategoryListController builds it -- only populated when
    // `adminMode: true`.
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

// The shape `album_selector.ts`'s constructor passes to a consumer's
// `removeSelectedAlbum` callback (`this.#removeSelectedAlbum = (args)
// => removeSelectedAlbum.call(null, { ...args, getSelectedAlbum })`),
// called with `{ id_album: id }` from `remove_selected_album(id)`.
interface AlbumSelectorRemoveCallbackArgs {
  id_album: string | number;
  getSelectedAlbum: () => (string | number)[];
}

// `intro.ts`'s own `storage_details` shared global -- shape traced to
// `IntroView.php`'s `storage_chart_data` property and the actual fields
// `intro.ts`/`intro_tooltips.ts` both read.
type StorageDetails = Record<
  string,
  {
    total: { filesize: number; nb_files: number };
    details?: Record<string, { filesize: number; nb_files: number }>;
  }
>;
