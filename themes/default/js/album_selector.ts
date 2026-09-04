// Real module now (docs/PLAN.md P48 -- was a non-module ambient-global
// declarer pre-P48, see git history for the pre-P48 shape). 8 real
// consumer files (all found via a direct grep, not just this file's own
// prior "12 real registrant pages" comment further down, which named
// only 6): categories/search.ts (bare `strAlbumsFound`/`strResultLimit`
// reads only, no `AlbumSelector` instantiation -- registered on
// albums.php via a NEW `AssetContribution` this same batch adds, a
// real pre-existing gap: categories/search.ts's own bare reads never had a
// real runtime source before, since albums.php never embedded this
// file's script), and pictureModify.ts/photosAddDirect.ts/
// categories/modify.ts/batch_manager/unit.ts/batch_manager/global.ts/
// batch_manager/filter.ts/mcs.ts (all `new AlbumSelector(...)`).
//
// Every consumer imports this file directly, and Rollup emits it once
// as a shared chunk, so there is exactly one `AlbumSelector` class per
// page no matter how many consumers reach it.
//
// That is a change worth recording, because it silently *fixed*
// something. While each consumer was handed a private duplicate, the 2
// pages carrying 2 consumer files (batch_manager_unit.php:
// batch_manager/unit.ts + batch_manager/filter.ts; batch_manager_global.php:
// batch_manager/global.ts + batch_manager/filter.ts) loaded 2 independent
// copies of this class, so `activeAlbumSelector`'s single-active-popup
// coordination (below) did not span both widgets on those pages -- each
// copy tracked its own module state. That was documented as an accepted
// loss with no safe single-copy alternative. Sharing removes the
// problem outright: both widgets now read and write the same
// `activeAlbumSelector`, which is what the coordination was written to
// assume in the first place.
//
// `sprintf` comes from sprintf.ts by plain import; sprintf.ts is itself
// another shared chunk.
import { sprintf } from "./sprintf";
import { pwg_getPageData, pwg_getPageString } from "./pageData";
import { ajax, AjaxError } from "./vendor/utils/ajax";
import type { operations } from "../../../openapi/client/schema";
import {
  addClass,
  after,
  append,
  attr,
  attrOf,
  css,
  cssValue,
  empty,
  escapeId,
  fadeIn,
  fadeOut,
  find,
  hasClass,
  hide,
  html,
  is,
  isVisible,
  off,
  on,
  remove,
  removeClass,
  setVal,
  show,
  trigger,
  val,
} from "./vendor/utils/dom";

// Real shapes for the 2 real GET endpoints this file's own #methodPwg
// switches between (admin mode: /categories; non-admin: /categories/available),
// via the existing OpenAPI schema.
type CategoryAdmin =
  operations["categoryList"]["responses"][200]["content"]["application/json"]["categories"][number];
type CategoryAvailable =
  operations["categoryAvailableList"]["responses"][200]["content"]["application/json"]["categories"][number];
type LimitInfo =
  operations["categoryList"]["responses"][200]["content"]["application/json"]["limit"];
// CategoryAdmin's own OpenAPI schema omits `nbCategories` entirely
// (a real documentation gap, not fixed here -- out of this phase's own
// scope) even though CategoryListController.php's own non-recursive
// branch really does add it at runtime; CategoryAvailable's schema
// documents it as always-present. `fullname` is CategoryAdmin-only in
// the schema, read only when `#inAdminMode` is true. Both added here
// as optional so either real per-mode shape can be handled uniformly.
type AlbumCategory = (CategoryAdmin | CategoryAvailable) & {
  nbCategories?: number;
  fullname?: string;
};
// Every real call site includes the `limit` request param unconditionally,
// so `limit` is always present in the real response either way, even
// though CategoryAvailable's own schema marks it optional (conditional
// on that same param).
type CategoryListOrAvailableResponse =
  | operations["categoryList"]["responses"][200]["content"]["application/json"]
  | operations["categoryAvailableList"]["responses"][200]["content"]["application/json"];

const strPlusAlbumsFound = pwg_getPageString(
  "Only the first %d albums are displayed, out of %d.",
);
const strAlbumSelected = pwg_getPageString("Album already selected");
const strNoSearchInProgress = pwg_getPageString("No search in progress");
// Real cross-file exports -- categories/search.ts's own sole real need from
// this file (docs/PLAN.md P48, see the leading comment further down).
export const strAlbumsFound = pwg_getPageString("<b>%d</b> albums found");
// A real, genuinely coincidental duplicate of albums.ts's own
// identically-named, identically-worded `const strAlbumFound`
// (categories/search.ts's own leading comment has the full history) --
// exported here (not fixed to import from albums.ts instead, that
// file's own P48 conversion is a separate future batch) purely because
// this file becoming a real module would otherwise silently turn
// categories/search.ts's existing bare read into a real TS2304 compile error,
// not because this is the semantically "correct" owner.
export const strAlbumFound = pwg_getPageString("<b>1</b> album found");
export const strResultLimit = pwg_getPageString(
  "<b>%d+</b> albums found, try to refine the search",
);
const strAddSubcatOf = pwg_getPageString("Add a sub-album to “%s”");
const strCreateAndSelect = pwg_getPageString("Create and select");
const strRootAlbumSelect = pwg_getPageString("Root");
const strCompleteNameField = pwg_getPageString("Name field must not be empty");
const strAnErrorHasOccured = pwg_getPageString("An error has occured");
const strAlbumModalTitle = pwg_getPageString("Select an album");
const strAlbumModalPlaceholder = pwg_getPageString("Search");
const strRoot = pwg_getPageString("Root");

let activeAlbumSelector: AlbumSelector | null = null;

/**
 * jQuery captured each static selector once, at class-definition time, and
 * kept that snapshot. `querySelectorAll` is likewise static, so an element
 * added to the page later is invisible to both -- the staleness is
 * preserved, not introduced.
 */
function q(selector: string): HTMLElement[] {
  return Array.from(document.querySelectorAll<HTMLElement>(selector));
}

window.addEventListener("keypress", function (e) {
  // `:visible` is not a CSS selector -- `matches()` throws a SyntaxError on
  // it. jQuery computes it from layout, which `isVisible()` reproduces.
  const haveAlbumSelector = q("#addLinkedAlbum").some(isVisible);
  if (haveAlbumSelector && e.key === "Enter") {
    e.preventDefault();
  }
});

// Real pre-existing bug, found via plugins/installedConfig.ts's own
// P48 module conversion: this file's own `#create_album()` reads
// `pwgToken` bare with no local declaration of its own, relying on
// TypeScript's ambient whole-program resolution to satisfy the
// type-checker (any non-module file's own top-level `pwgToken`
// declaration, anywhere in the program, used to suffice). At runtime
// this had no real source at all on any of this file's own 12 real
// registrant pages -- `plugins/installedConfig.ts`, the only file
// that ever set `window.pwgToken`, is registered on exactly one real
// page (`plugins_installed.php`), which never embeds `AlbumSelector`.
// The `X-CSRF-Token` header `#create_album()` sends has been
// `undefined` at runtime on every real page using this class. Fixed
// with the same local-declaration pattern every other real consumer
// (tags.ts, comments.ts, etc.) already uses -- `pageData.ts` exposes
// the real CSRF token per-page already, no cross-file sharing needed.
const pwgToken = pwg_getPageData<string>("csrf_token");

/**
 * Album selector instance
 * @param {Array} selectedCategoriesIds - Array of IDs for elements already selected.
 * @param {Function} selectAlbum - Function to handle the selection of an album.
 * @param {Function} removeSelectedAlbum - Function to handle the removal of a selected album.
 * @param {Boolean} showRootButton - Flag to indicate whether to show the "root" button.
 * @param {Boolean} adminMode - Flag to indicate if the selector is in admin mode.
 * @param {Number} limitParam - Maximum number of results to retrieve.
 * @param {Number} currentAlbumId - ID of the currently selected album. (Only if you use ShowRootButton to keep one album always selected)
 * @param {String} modalTitle - Custom title for the album selector modal.
 * @param {String} modalSearchPlaceholder - Custom placeholder text for the search input in the modal.
 */
export class AlbumSelector {
  instanceId: string;
  readonly #inAdminMode: boolean;
  readonly #methodPwg: string;
  readonly #limitParam: number;
  #isAlbumCreationChecked: boolean;
  readonly #selectAlbum: (args: {
    album: AlbumSelectorCallbackArgs["album"];
  }) => void;
  readonly #removeSelectedAlbum: (args: { id_album: string | number }) => void;
  #currentSelectedId: string | number;
  #searchCat: Record<string, AlbumCategory>;
  #cats: Record<string, AlbumCategory>;
  #selectedCategories: (string | number)[];
  readonly #showRootBtn: boolean;
  #putToRoot: boolean;
  readonly #currentCat: string | number;
  readonly #title: string;
  readonly #searchPlaceholder: string;
  #loadingAdd: boolean;
  #levelSeparator: string;

  /**
   * Selector for AlbumSelector
   */
  static selectors = {
    addLinkedAlbum: q("#addLinkedAlbum"),
    closeAlbumPopIn: q("#closeAlbumPopIn"),
    searchInput: q("#search-input-ab"),
    searchResult: q("#searchResult"),
    limitReached: q(".limitReached"),
    iconCancelInput: q(".search-cancel-linked-album"),
    relatedCategoriesDom: q(
      ".related-categories-container .breadcrumb-item .remove-item",
    ),
    iconSearchingSpin: q(".searching"),
    albumSelector: q("#linkedAlbumSelector"),
    albumCreate: q("#linkedAlbumCreate"),
    albumCheckBox: q("#album-create-check"),
    linkedAddAlbum: q("#linkedAddAlbum"),
    linkedModalTitle: q("#linkedModalTitle"),
    linkedAlbumSwitch: q("#linkedAlbumSwitch"),
    linkedAlbumSubTitle: q("#linkedAlbumSubtitle"),
    linkedAddNewAlbum: q("#linkedAddNewAlbum"),
    linkedAlbumInput: q("#linkedAlbumInput"),
    putToRoot: q(".put-to-root-container"),
    linkedAlbumCancel: q("#linkedAlbumCancel"),
    linkedAddAlbumErrors: q("#linkedAddAlbumErrors"),
    addAlbumErrors: q(".AddAlbumErrors"),
    putToRootBtn: q("#put-to-root"),
    linkedAlbumPopInContainer: q(".linkedAlbumPopInContainer"),
  };

  constructor({
    selectedCategoriesIds = [],
    // eslint-disable-next-line @typescript-eslint/no-empty-function -- intentional no-op default for an optional callback.
    selectAlbum = (_args: AlbumSelectorCallbackArgs) => {},
    // eslint-disable-next-line @typescript-eslint/no-empty-function -- intentional no-op default for an optional callback.
    removeSelectedAlbum = (_args: AlbumSelectorRemoveCallbackArgs) => {},
    showRootButton = false,
    adminMode = false,
    limitParam = 50,
    currentAlbumId = 0,
    modalTitle = "",
    modalSearchPlaceholder = "",
  }: AlbumSelectorOptions) {
    // eslint-disable-next-line sonarjs/pseudo-random -- not security-sensitive: a per-instance CSS class-scoping suffix, only needs to avoid colliding with another instance on the same page.
    this.instanceId = `AlbumSelector-${Math.random().toString(36).substring(2, 9)}`;
    this.#inAdminMode = adminMode;
    this.#methodPwg = adminMode
      ? "api/v1/categories"
      : "api/v1/categories/available";
    this.#limitParam = limitParam;
    this.#selectedCategories = adminMode
      ? [...selectedCategoriesIds]
      : selectedCategoriesIds.map(String);
    this.#isAlbumCreationChecked = false;
    this.#cats = {};
    this.#searchCat = {};
    this.#selectAlbum = (args) => {
      selectAlbum.call(null, {
        ...args,
        // Response-level, not per-album: a consumer rebuilding the linked
        // breadcrumb needs the same separator the server joins with.
        levelSeparator: this.#levelSeparator,
        newSelectedAlbum: this.#newSelectedAlbum.bind(this),
        addSelectedAlbum: this.#addSelectedAlbum.bind(this),
        getSelectedAlbum: this.getSelectedAlbums.bind(this),
      });
    };
    this.#removeSelectedAlbum = (args) => {
      removeSelectedAlbum.call(null, {
        ...args,
        getSelectedAlbum: this.getSelectedAlbums.bind(this),
      });
    };
    this.#currentSelectedId = "";
    this.#showRootBtn = showRootButton;
    this.#putToRoot = false;
    this.#currentCat = currentAlbumId;
    this.#title = modalTitle === "" ? strAlbumModalTitle : modalTitle;
    this.#searchPlaceholder =
      modalSearchPlaceholder === ""
        ? strAlbumModalPlaceholder
        : modalSearchPlaceholder;
    this.#loadingAdd = false;
    // Replaced by the real configured separator on the first response; the
    // fallback is Piwigo's own default, for the window before one arrives.
    this.#levelSeparator = " / ";

    this.#init();
  }

  #init() {
    if (this.#inAdminMode && this.#showRootBtn) {
      addClass(AlbumSelector.selectors.linkedAlbumPopInContainer, "big");
    }

    if (!this.#showRootBtn) {
      remove(AlbumSelector.selectors.putToRoot);
    }

    if (!this.#inAdminMode) {
      remove(AlbumSelector.selectors.albumCreate);
      remove(AlbumSelector.selectors.linkedAlbumSwitch);
    }
  }

  /*-----------
  Public method
  -----------*/
  open() {
    if (activeAlbumSelector && activeAlbumSelector !== this) {
      activeAlbumSelector.close();
    }
    // eslint-disable-next-line @typescript-eslint/no-this-alias -- not the classic callback-closure idiom: tracks which AlbumSelector *instance* is currently active module-wide, real state (not just a scoping workaround).
    activeAlbumSelector = this;
    this.#openAlbumSelector();
  }

  close() {
    if (activeAlbumSelector === this) {
      activeAlbumSelector = null;
    }
    this.#closeAlbumSelector();
  }

  removeSelectedAlbum(id: string | number) {
    if (this.#selectedCategories.includes(id)) {
      const catToRemoveIndex = this.#selectedCategories.indexOf(id);
      if (catToRemoveIndex > -1) {
        this.#selectedCategories.splice(catToRemoveIndex, 1);
      }
    }

    this.#removeSelectedAlbum({ id_album: id });
  }

  getSelectedAlbums() {
    return [...this.#selectedCategories];
  }

  selectAlbum(id: string | number) {
    this.#selectedCategories.push(id.toString());
  }

  resetAll() {
    this.#selectedCategories = [];
    if (this.#inAdminMode) {
      this.#hardResetAlbumSelector();
    } else {
      this.#resetAlbumSelector();
    }
  }

  hardUpdate(cats: (string | number)[]) {
    this.#selectedCategories = cats;
  }

  /*---------------------
  in selectAlbum() method
  ---------------------*/
  #newSelectedAlbum() {
    this.#selectedCategories =
      this.#showRootBtn && this.#putToRoot ? ["0"] : [this.#currentSelectedId];
  }

  #addSelectedAlbum() {
    this.#selectedCategories.push(this.#currentSelectedId);
  }

  /*----------
  Event method
  ----------*/
  #loadGeneralEvent() {
    const instanceAb = `.${this.instanceId}`;
    // event close album selector
    off(AlbumSelector.selectors.closeAlbumPopIn, `click${instanceAb}`);
    on(AlbumSelector.selectors.closeAlbumPopIn, `click${instanceAb}`, () => {
      this.#closeAlbumSelector();
    });

    // event escape album selector
    off(document, `keyup${instanceAb}`);
    on(document, `keyup${instanceAb}`, (event) => {
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- "keyup" always dispatches a real KeyboardEvent; on()'s own handler param is typed generically via the native EventListener interface.
      const e = event as KeyboardEvent;
      if (
        e.key === "Escape" &&
        AlbumSelector.selectors.addLinkedAlbum.some(isVisible)
      ) {
        this.#closeAlbumSelector();
      }

      if (
        e.key === "Enter" &&
        AlbumSelector.selectors.addLinkedAlbum.some(isVisible)
      ) {
        if (q("#linkedAddNewAlbum").some(isVisible)) {
          trigger(
            AlbumSelector.selectors.linkedAddNewAlbum,
            `click${instanceAb}`,
          );
        }
      }
    });

    // event empty search input
    if (AlbumSelector.selectors.iconCancelInput.length) {
      off(AlbumSelector.selectors.iconCancelInput, `click${instanceAb}`);
      on(AlbumSelector.selectors.iconCancelInput, `click${instanceAb}`, () => {
        this.#resetSearchInput(true);
      });
    }

    // event perform search
    off(AlbumSelector.selectors.searchInput, `keyup${instanceAb}`);
    on(AlbumSelector.selectors.searchInput, `keyup${instanceAb}`, () => {
      const searchValue = val(AlbumSelector.selectors.searchInput) ?? "";
      if (searchValue.length > 0) {
        show(AlbumSelector.selectors.iconCancelInput);
      } else {
        hide(AlbumSelector.selectors.iconCancelInput);
      }
      void this.#performAlbumsSearch(searchValue);
    });

    // event in admin mode
    if (this.#inAdminMode) {
      off(AlbumSelector.selectors.albumCheckBox, `change${instanceAb}`);
      on(AlbumSelector.selectors.albumCheckBox, `change${instanceAb}`, (e) => {
        this.#isAlbumCreationChecked = is(
          // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- a real "change" event's own currentTarget is always the registered Element, never a bare EventTarget with no Element interface.
          e.currentTarget as Element,
          ":checked",
        );
        this.#switchAlbumCreation();
      });
    }

    // event put root btn
    if (this.#showRootBtn) {
      off(AlbumSelector.selectors.putToRootBtn, `click${instanceAb}`);
      on(AlbumSelector.selectors.putToRootBtn, `click${instanceAb}`, (e) => {
        if (!this.#selectedCategories.includes("0")) {
          // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- a real "click" event's own currentTarget is always the registered Element, never a bare EventTarget with no Element interface.
          const curr = e.currentTarget as Element;
          addClass(curr, "notClickable");
          this.#putToRoot = true;
          this.#selectAlbum({ album: { id: 0, root: strRoot } });
          this.#closeAlbumSelector();
        }
      });
    }
  }

  #loadPickAlbumEvent() {
    const instanceAb = `.${this.instanceId}`;
    if (this.#isAlbumCreationChecked) {
      const items = q(".prefill-results-item");
      off(items, `click${instanceAb}`);
      on(items, `click${instanceAb}`, (e) => {
        // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- a real "click" event's own currentTarget is always the registered Element, never a bare EventTarget with no Element interface.
        const curr = e.currentTarget as Element;
        const catId = attrOf(curr, "id")!;
        const cat = this.#cats[catId]!;
        this.#switchAlbumView(cat);
      });
    } else {
      const available = q(".prefill-results-item.available");
      off(available, `click${instanceAb}`);
      on(available, `click${instanceAb}`, (e) => {
        // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- a real "click" event's own currentTarget is always the registered Element, never a bare EventTarget with no Element interface.
        const curr = e.currentTarget as Element;
        const catId = attrOf(curr, "id")!;
        const cat = this.#cats[catId]!;

        this.#currentSelectedId = cat.id;
        this.#selectAlbum({ album: cat });
        this.#closeAlbumSelector();
      });
    }
  }

  #loadSubCatEvent() {
    const instanceAb = `.${this.instanceId}`;
    const togglers = q(".display-subcat");
    off(togglers, `click${instanceAb}`);
    on(togglers, `click${instanceAb}`, (e) => {
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- a real "click" event's own currentTarget is always the registered HTMLElement, never a bare EventTarget with no Element interface.
      const curr = e.currentTarget as HTMLElement;
      const catId = curr.id;
      const cat = this.#cats[catId]!;

      if (hasClass(curr, "open")) {
        removeClass(curr, "open");
        fadeOut(q("#subcat-" + escapeId(cat.id)));
      } else if (q("#subcat-" + escapeId(cat.id)).length) {
        addClass(curr, "open");
        fadeIn(q("#subcat-" + escapeId(cat.id)));
      } else {
        const arrow = q("#" + escapeId(catId) + ".display-subcat");
        removeClass(arrow, "gallery-icon-up-open");
        addClass(arrow, "gallery-icon-spin6 animate-spin");
        after(
          q("#" + escapeId(catId) + ".search-result-item"),
          `<div id="subcat-${catId}" class="search-result-subcat-item"></div>`,
        );
        void this.#prefillSearchSubcats(catId).then(() => {
          const settled = q("#" + escapeId(catId) + ".display-subcat");
          removeClass(settled, "gallery-icon-spin6 animate-spin");
          addClass(settled, "gallery-icon-up-open");
          addClass(curr, "open");
          fadeIn(q("#subcat-" + escapeId(cat.id)));
        });
      }
    });
  }

  #loadFillResultEvent(tempSelect: (string | number)[]) {
    const instanceAb = `.${this.instanceId}`;

    const rows = find(
      AlbumSelector.selectors.searchResult,
      ".search-result-item",
    );
    off(rows, `click${instanceAb}`);
    on(rows, `click${instanceAb}`, (e) => {
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- a real "click" event's own currentTarget is always the registered Element, never a bare EventTarget with no Element interface.
      const curr = e.currentTarget as Element;
      const catId = attrOf(curr, "id")!;
      const cat = this.#searchCat[catId]!;

      const formatedCatId = this.#inAdminMode ? cat.id : String(cat.id);
      if (!tempSelect.includes(formatedCatId)) {
        this.#currentSelectedId = cat.id;
        this.#selectAlbum({ album: cat });
        this.#closeAlbumSelector();
      }
    });
  }

  /*--------------
  General method
  --------------*/
  #setActive() {
    if (activeAlbumSelector && activeAlbumSelector !== this) {
      activeAlbumSelector.close();
    }
    // eslint-disable-next-line @typescript-eslint/no-this-alias -- see open()'s own copy of this comment.
    activeAlbumSelector = this;
  }

  #openAlbumSelector() {
    this.#setActive();
    this.#loadGeneralEvent();

    if (this.#inAdminMode) {
      this.#hardResetAlbumSelector();
    } else {
      this.#resetAlbumSelector();
    }

    if (this.#showRootBtn && !this.#selectedCategories.includes("0")) {
      removeClass(AlbumSelector.selectors.putToRootBtn, "notClickable");
    } else {
      addClass(AlbumSelector.selectors.putToRootBtn, "notClickable");
    }

    html(AlbumSelector.selectors.linkedModalTitle, this.#title);
    attr(
      AlbumSelector.selectors.searchInput,
      "placeholder",
      this.#searchPlaceholder,
    );
    fadeIn(AlbumSelector.selectors.addLinkedAlbum);
  }

  #closeAlbumSelector() {
    this.#cats = {};
    this.#searchCat = {};
    this.#currentSelectedId = "";
    this.#putToRoot = false;
    this.#loadingAdd = false;

    this.#destroyEvent();

    fadeOut(AlbumSelector.selectors.addLinkedAlbum);
  }

  #resetAlbumSelector() {
    void this.#prefillSearch();
    this.#resetSearchInput(false);
    // AlbumSelector.selectors.searchInput.val('');
    // // AlbumSelector.selectors.searchInput.trigger("input");
    html(AlbumSelector.selectors.limitReached, strNoSearchInProgress);
    show(AlbumSelector.selectors.albumSelector);
  }

  #hardResetAlbumSelector() {
    hide(AlbumSelector.selectors.albumCreate);
    AlbumSelector.#hideNewAlbumError();

    this.#resetAlbumSelector();
    setVal(AlbumSelector.selectors.linkedAlbumInput, "");
    if (is(AlbumSelector.selectors.albumCheckBox, ":checked")) {
      trigger(AlbumSelector.selectors.albumCheckBox, "click");
    }
    show(AlbumSelector.selectors.searchResult);
    show(AlbumSelector.selectors.linkedAlbumSwitch);
  }

  #resetSearchInput(prefill: boolean) {
    setVal(AlbumSelector.selectors.searchInput, "");
    show(AlbumSelector.selectors.limitReached);
    html(AlbumSelector.selectors.limitReached, strNoSearchInProgress);
    empty(AlbumSelector.selectors.searchResult);
    if (prefill) {
      void this.#prefillSearch();
    }
  }

  #switchAlbumCreation() {
    this.#resetAlbumSelector();
    const instanceAb = `.${this.instanceId}`;

    if (this.#isAlbumCreationChecked) {
      if (AlbumSelector.selectors.putToRoot.length) {
        hide(AlbumSelector.selectors.putToRoot);
      }
      hide(AlbumSelector.selectors.linkedModalTitle);
      html(AlbumSelector.selectors.linkedModalTitle, strCreateAndSelect);
      show(AlbumSelector.selectors.linkedAddAlbum);
      fadeIn(AlbumSelector.selectors.linkedModalTitle);

      off(AlbumSelector.selectors.linkedAddAlbum, `click${instanceAb}`);
      on(AlbumSelector.selectors.linkedAddAlbum, `click${instanceAb}`, () => {
        this.#switchAlbumView("root");
      });
    } else {
      if (AlbumSelector.selectors.putToRoot.length) {
        fadeIn(AlbumSelector.selectors.putToRoot);
      }
      hide(AlbumSelector.selectors.linkedModalTitle);
      html(AlbumSelector.selectors.linkedModalTitle, this.#title);
      fadeIn(AlbumSelector.selectors.linkedModalTitle);
      hide(AlbumSelector.selectors.linkedAddAlbum);
      off(AlbumSelector.selectors.linkedAddAlbum, "click");
    }
  }

  #switchAlbumView(cat: AlbumCategory | "root") {
    const instanceAb = `.${this.instanceId}`;

    hide(AlbumSelector.selectors.albumSelector);
    hide(AlbumSelector.selectors.searchResult);
    hide(AlbumSelector.selectors.linkedAlbumSwitch);
    fadeIn(AlbumSelector.selectors.albumCreate);

    html(
      AlbumSelector.selectors.linkedAlbumSubTitle,
      sprintf(strAddSubcatOf, cat === "root" ? strRootAlbumSelect : cat.name),
    );
    off(AlbumSelector.selectors.linkedAddNewAlbum, `click${instanceAb}`);
    on(AlbumSelector.selectors.linkedAddNewAlbum, `click${instanceAb}`, () => {
      void this.#addNewAlbum(cat === "root" ? cat : cat.id);
    });

    off(AlbumSelector.selectors.linkedAlbumCancel, `click${instanceAb}`);
    on(AlbumSelector.selectors.linkedAlbumCancel, `click${instanceAb}`, () => {
      this.#closeAlbumSelector();
    });

    off(AlbumSelector.selectors.linkedAlbumInput, `input${instanceAb}`);
    on(AlbumSelector.selectors.linkedAlbumInput, `input${instanceAb}`, () => {
      AlbumSelector.#hideNewAlbumError();
    });
  }

  static #hideNewAlbumError() {
    css(AlbumSelector.selectors.addAlbumErrors, "visibility", "hidden");
  }

  static #showNewAlbumError(text: string) {
    html(AlbumSelector.selectors.linkedAddAlbumErrors, text);
    css(AlbumSelector.selectors.addAlbumErrors, "visibility", "visible");
  }

  #selectNewAlbumAndClose(cat: CategoryAdmin) {
    this.#currentSelectedId = cat.id;
    this.#selectAlbum({ album: cat });
    this.#closeAlbumSelector();
  }

  #destroyEvent() {
    const instanceAb = `.${this.instanceId}`;

    off(document, `keyup${instanceAb}`);
    off(document, `click${instanceAb}`);
    off(document, `change${instanceAb}`);
    off(document, `input${instanceAb}`);
    off(AlbumSelector.selectors.searchInput, `keyup${instanceAb}`);
    off(
      find(AlbumSelector.selectors.searchResult, ".search-result-item"),
      `click${instanceAb}`,
    );
    off(q(".prefill-results-item"), `click${instanceAb}`);
    off(q(".prefill-results-item.available"), `click${instanceAb}`);
  }

  /*--------------
  Dom modification
  --------------*/
  #prefillResults(
    rank: string | number,
    cats: AlbumCategory[],
    limit: LimitInfo,
  ) {
    const isCreationMode = this.#isAlbumCreationChecked;
    const iconAlbum = this.#isAlbumCreationChecked
      ? "icon-add-album"
      : "gallery-icon-plus-circled";
    // `0` is the "no current album" sentinel (the constructor's own
    // default for `currentAlbumId`) -- every real call site relies on
    // that, none ever passes a real id of 0.
    const tempSelectedCat =
      this.#currentCat !== 0 && this.#currentCat !== ""
        ? [...this.#selectedCategories, this.#currentCat.toString()]
        : [...this.#selectedCategories];

    this.#cats = {
      ...this.#cats,
      ...Object.fromEntries(cats.map((c) => [c.id, c])),
    };
    let displayDiv = q("#subcat-" + escapeId(rank));
    if ("root" === rank) {
      empty(AlbumSelector.selectors.searchResult);
      displayDiv = AlbumSelector.selectors.searchResult;
    } else {
      displayDiv = q("#subcat-" + escapeId(rank));
    }

    cats.forEach((cat) => {
      let subcat = "";
      // Genuine pre-existing bug found via strict typing: read
      // `cat.nb_categories` (snake_case) -- no such field exists on
      // either real category shape (confirmed via the OpenAPI schema
      // and CategoryListController.php's own source, which writes
      // `nbCategories`, camelCase). Always undefined, so the "has
      // sub-albums, show expand arrow" indicator has never actually
      // rendered. Fixed to the real field.
      if ((cat.nbCategories ?? 0) > 0) {
        subcat = `<span id="${cat.id}" class="display-subcat gallery-icon-up-open"></span>`;
      }

      const isNotInSelectedCat = !tempSelectedCat.includes(cat.id);
      if (isCreationMode || isNotInSelectedCat) {
        append(
          displayDiv,
          `<div class="search-result-item" id="${cat.id}">
              ${subcat}
              <div class="prefill-results-item available" id="${cat.id}">
                <span class="search-result-path"><span class="search-result-path-name">${cat.name}</span></span>
                <span id=${cat.id}" class="${iconAlbum} item-add"></span>
              </div>
            </div>`,
        );
      } else {
        append(
          displayDiv,
          `<div class="search-result-item already-in" id="${cat.id}" title="${strAlbumSelected}">
              ${subcat}
              <div class="prefill-results-item" id="${cat.id}">
                <span class="search-result-path"><span class="search-result-path-name">${cat.name}</span></span> 
                <span id="${cat.id}" class="gallery-icon-plus-circled item-add notClickable" title="${strAlbumSelected}"></span>
              </div>
            </div>`,
        );
      }

      if (rank !== "root") {
        const [item] = q("#" + escapeId(rank) + ".search-result-item");
        // `.css(name)` on an empty set is undefined, and parseInt of that
        // is NaN -- which then reaches `.css(prop, NaN)` and is skipped.
        // Preserved: an absent parent row leaves the margin untouched
        // rather than throwing.
        const marginLeft =
          parseInt(item === undefined ? "" : cssValue(item, "margin-left")) +
          25;
        css(
          q("#" + escapeId(cat.id) + ".search-result-item"),
          "margin-left",
          marginLeft,
        );
        css(
          q("#" + escapeId(cat.id) + ".search-result-item .search-result-path"),
          "max-width",
          400 - marginLeft - 80,
        );
      }
    });

    this.#loadPickAlbumEvent();
    this.#loadSubCatEvent();
    if (limit.remainingCats > 0) {
      const text = sprintf(
        strPlusAlbumsFound,
        limit.limitedTo,
        limit.totalCats,
      );
      append(displayDiv, `<p class="and-more">${text}</p>`);
    }
  }

  #fillResults(cats: AlbumCategory[]) {
    const iconAlbum = this.#isAlbumCreationChecked
      ? "icon-add-album"
      : "gallery-icon-plus-circled";
    // `0` is the "no current album" sentinel (the constructor's own
    // default for `currentAlbumId`) -- every real call site relies on
    // that, none ever passes a real id of 0.
    const tempSelectedCat =
      this.#currentCat !== 0 && this.#currentCat !== ""
        ? [...this.#selectedCategories, this.#currentCat.toString()]
        : [...this.#selectedCategories];

    this.#searchCat = Object.fromEntries(cats.map((c) => [c.id, c]));
    empty(AlbumSelector.selectors.searchResult);

    cats.forEach((cat) => {
      const catName = this.#inAdminMode ? cat.fullname! : cat.name;

      append(
        AlbumSelector.selectors.searchResult,
        `<div class='search-result-item' id="${cat.id}">
        <span class="search-result-path not-rtl">${AlbumSelector.#getEllipsisName(catName)}</span><span id="${cat.id}" class="${iconAlbum} item-add"></span>
      </div>`,
      );

      if (this.#isAlbumCreationChecked) {
        const instanceAb = `.${this.instanceId}`;
        const row = q(".search-result-item#" + escapeId(cat.id));
        off(row, `click${instanceAb}`);
        on(row, `click${instanceAb}`, () => {
          this.#switchAlbumView(cat);
        });
        return;
      }

      if (tempSelectedCat.includes(cat.id)) {
        const adder = q(
          ".search-result-item #" + escapeId(cat.id) + ".item-add",
        );
        addClass(adder, "notClickable");
        attr(adder, "title", strAlbumSelected);
        const whole = q("#" + escapeId(cat.id) + ".search-result-item");
        addClass(whole, "notClickable");
        attr(whole, "title", strAlbumSelected);
      }
    });

    if (!this.#isAlbumCreationChecked)
      this.#loadFillResultEvent(tempSelectedCat);
  }

  /**
   * Only the admin endpoint carries `levelSeparator`; the available-albums
   * one does not, and leaves the default in place.
   */
  #rememberLevelSeparator(data: CategoryListOrAvailableResponse) {
    if ("levelSeparator" in data && typeof data.levelSeparator === "string") {
      this.#levelSeparator = data.levelSeparator;
    }
  }

  static #getEllipsisName(str: string, lenght = 50) {
    if (str.length <= lenght) return str;
    return "..." + str.slice(-lenght).trim();
  }
  /*-----------
  Ajax method
  -----------*/
  // GET /api/v1/categories (admin mode) filters by parentId; GET
  // /api/v1/categories/available (non-admin mode) filters by catId --
  // the two endpoints were built at different times with different
  // query param names for the same "look at this category" concept.
  #catIdParam(catId: string | number) {
    return this.#inAdminMode ? { parentId: catId } : { catId: catId };
  }

  async #prefillSearch() {
    show(q(".linkedAlbumPopInContainer .searching"));
    const apiParams = {
      ...this.#catIdParam(0),
      recursive: false,
      fullname: true,
      limit: this.#limitParam,
    };

    try {
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/utils/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
      const data = (await ajax({
        url: this.#methodPwg,
        type: "GET",
        dataType: "json",
        data: apiParams,
      })) as CategoryListOrAvailableResponse;

      this.#rememberLevelSeparator(data);
      hide(q(".linkedAlbumPopInContainer .searching"));
      const cats = data.categories;
      const limit = data.limit!;
      this.#prefillResults("root", cats, limit);
    } catch (e) {
      hide(q(".linkedAlbumPopInContainer .searching"));
      console.error("error : ", e instanceof AjaxError ? e.responseText : e);
    }
  }

  async #prefillSearchSubcats(catId: string | number) {
    const apiParams = {
      ...this.#catIdParam(catId),
      recursive: false,
      limit: this.#limitParam,
    };

    try {
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/utils/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
      const data = (await ajax({
        url: this.#methodPwg,
        type: "GET",
        dataType: "json",
        data: apiParams,
      })) as CategoryListOrAvailableResponse;

      this.#rememberLevelSeparator(data);
      const cats = data.categories.filter((c) => c.id !== Number(catId));
      const limit = data.limit!;
      this.#prefillResults(catId, cats, limit);
    } catch (e) {
      console.error(
        "prefill search error :",
        e instanceof AjaxError ? e.responseText : e,
      );
    }
  }

  async #performAlbumsSearch(searchText: string) {
    if (searchText === "") {
      this.#resetSearchInput(true);
      return;
    }
    const apiParams = {
      ...this.#catIdParam(0),
      recursive: true,
      fullname: true,
      search: searchText,
    };

    show(AlbumSelector.selectors.iconSearchingSpin);
    try {
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/utils/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
      const data = (await ajax({
        url: this.#methodPwg,
        type: "GET",
        dataType: "json",
        data: apiParams,
      })) as CategoryListOrAvailableResponse;

      this.#rememberLevelSeparator(data);
      hide(AlbumSelector.selectors.iconSearchingSpin);
      const { categories } = data;
      this.#fillResults(categories);

      if (data.limit && data.limit.remainingCats > 0) {
        html(
          AlbumSelector.selectors.limitReached,
          strResultLimit.replace("%d", String(categories.length)),
        );
      } else {
        if (categories.length === 1) {
          html(AlbumSelector.selectors.limitReached, strAlbumFound);
        } else {
          html(
            AlbumSelector.selectors.limitReached,
            strAlbumsFound.replace("%d", String(categories.length)),
          );
        }
      }
    } catch (e) {
      hide(AlbumSelector.selectors.iconSearchingSpin);
      console.error(e instanceof AjaxError ? e.responseText : e);
    }
  }

  async #addNewAlbum(catId: string | number) {
    if (this.#loadingAdd) return;
    this.#loadingAdd = true;
    const catName = val(AlbumSelector.selectors.linkedAlbumInput);
    const catPosition = val(q("input[name=position]:checked"));
    const apiParams = {
      name: catName,
      parentId: catId === "root" ? 0 : Number(catId),
      position: catPosition,
    };

    if (catName === undefined || catName === "") {
      AlbumSelector.#showNewAlbumError(strCompleteNameField);
      return;
    }

    try {
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/utils/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
      const data = (await ajax({
        url: "api/v1/categories",
        type: "POST",
        json: apiParams,
        headers: {
          "X-CSRF-Token": pwgToken,
        },
        dataType: "json",
      })) as operations["categoryCreate"]["responses"][201]["content"]["application/json"];

      void this.#getAlbumById(data.id);
    } catch {
      AlbumSelector.#showNewAlbumError(strAnErrorHasOccured);
    }
  }

  async #getAlbumById(catId: string | number) {
    try {
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/utils/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
      const data = (await ajax({
        url: "api/v1/categories",
        type: "GET",
        dataType: "json",
        data: {
          parentId: catId,
        },
      })) as operations["categoryList"]["responses"][200]["content"]["application/json"];

      this.#selectNewAlbumAndClose(data.categories[0]!);
    } catch {
      AlbumSelector.#showNewAlbumError(strAnErrorHasOccured);
    }
  }
}
