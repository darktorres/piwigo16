// Real module now (docs/PLAN.md P48 -- was a non-module ambient-global
// declarer pre-P48, see git history for the pre-P48 shape). 8 real
// consumer files (all found via a direct grep, not just this file's own
// prior "12 real registrant pages" comment further down, which named
// only 6): cat_search.ts (bare `str_albums_found`/`str_result_limit`
// reads only, no `AlbumSelector` instantiation -- registered on
// albums.php via a NEW `AssetContribution` this same batch adds, a
// real pre-existing gap: cat_search.ts's own bare reads never had a
// real runtime source before, since albums.php never embedded this
// file's script), and picture_modify.ts/photos_add_direct.ts/
// cat_modify.ts/batchManagerUnit.ts/batchManagerGlobal.ts/
// batchManagerFilter.ts/mcs.ts (all `new AlbumSelector(...)`).
//
// Every consumer imports this file directly, and Rollup emits it once
// as a shared chunk, so there is exactly one `AlbumSelector` class per
// page no matter how many consumers reach it.
//
// That is a change worth recording, because it silently *fixed*
// something. While each consumer was handed a private duplicate, the 2
// pages carrying 2 consumer files (batch_manager_unit.php:
// batchManagerUnit.ts + batchManagerFilter.ts; batch_manager_global.php:
// batchManagerGlobal.ts + batchManagerFilter.ts) loaded 2 independent
// copies of this class, so `activeAlbumSelector`'s single-active-popup
// coordination (below) did not span both widgets on those pages -- each
// copy tracked its own module state. That was documented as an accepted
// loss with no safe single-copy alternative. Sharing removes the
// problem outright: both widgets now read and write the same
// `activeAlbumSelector`, which is what the coordination was written to
// assume in the first place.
//
// `sprintf` comes from common.ts by plain import; common.ts is itself
// another shared chunk.
import { sprintf } from "./common";
import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../default/js/page-data";
import { ajax } from "../../../default/js/vendor/ajax";
import type { operations } from "../../../../openapi/client/schema";
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
} from "../../../default/js/vendor/dom";

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
// the schema, read only when `#in_admin_mode` is true. Both added here
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

const str_plus_albums_found = pwg_getPageString(
  "Only the first %d albums are displayed, out of %d.",
);
const str_album_selected = pwg_getPageString("Album already selected");
const str_no_search_in_progress = pwg_getPageString("No search in progress");
// Real cross-file exports -- cat_search.ts's own sole real need from
// this file (docs/PLAN.md P48, see the leading comment further down).
export const str_albums_found = pwg_getPageString("<b>%d</b> albums found");
// A real, genuinely coincidental duplicate of albums.ts's own
// identically-named, identically-worded `const str_album_found`
// (cat_search.ts's own leading comment has the full history) --
// exported here (not fixed to import from albums.ts instead, that
// file's own P48 conversion is a separate future batch) purely because
// this file becoming a real module would otherwise silently turn
// cat_search.ts's existing bare read into a real TS2304 compile error,
// not because this is the semantically "correct" owner.
export const str_album_found = pwg_getPageString("<b>1</b> album found");
export const str_result_limit = pwg_getPageString(
  "<b>%d+</b> albums found, try to refine the search",
);
const str_add_subcat_of = pwg_getPageString("Add a sub-album to “%s”");
const str_create_and_select = pwg_getPageString("Create and select");
const str_root_album_select = pwg_getPageString("Root");
const str_complete_name_field = pwg_getPageString(
  "Name field must not be empty",
);
const str_an_error_has_occured = pwg_getPageString("An error has occured");
const str_album_modal_title = pwg_getPageString("Select an album");
const str_album_modal_placeholder = pwg_getPageString("Search");
const str_root = pwg_getPageString("Root");

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

// Real pre-existing bug, found via plugins_installed_config.ts's own
// P48 module conversion: this file's own `#create_album()` reads
// `pwg_token` bare with no local declaration of its own, relying on
// TypeScript's ambient whole-program resolution to satisfy the
// type-checker (any non-module file's own top-level `pwg_token`
// declaration, anywhere in the program, used to suffice). At runtime
// this had no real source at all on any of this file's own 12 real
// registrant pages -- `plugins_installed_config.ts`, the only file
// that ever set `window.pwg_token`, is registered on exactly one real
// page (`plugins_installed.php`), which never embeds `AlbumSelector`.
// The `X-CSRF-Token` header `#create_album()` sends has been
// `undefined` at runtime on every real page using this class. Fixed
// with the same local-declaration pattern every other real consumer
// (tags.ts, comments.ts, etc.) already uses -- `page-data.ts` exposes
// the real CSRF token per-page already, no cross-file sharing needed.
const pwg_token = pwg_getPageData<string>("csrf_token");

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
  #in_admin_mode: boolean;
  #methodPwg: string;
  #limitParam: number;
  #isAlbumCreationChecked: boolean;
  #selectAlbum: (args: { album: AlbumSelectorCallbackArgs["album"] }) => void;
  #removeSelectedAlbum: (args: { id_album: string | number }) => void;
  #currentSelectedId: string | number;
  #searchCat: Record<string, AlbumCategory>;
  #cats: Record<string, AlbumCategory>;
  #selected_categories: (string | number)[];
  #show_root_btn: boolean;
  #put_to_root: boolean;
  #current_cat: string | number;
  #title: string;
  #searchPlaceholder: string;
  #loading_add: boolean;
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
    selectAlbum = (_args: AlbumSelectorCallbackArgs) => {},
    removeSelectedAlbum = (_args: AlbumSelectorRemoveCallbackArgs) => {},
    showRootButton = false,
    adminMode = false,
    limitParam = 50,
    currentAlbumId = 0,
    modalTitle = "",
    modalSearchPlaceholder = "",
  }: AlbumSelectorOptions) {
    this.instanceId = `AlbumSelector-${Math.random().toString(36).substring(2, 9)}`;
    this.#in_admin_mode = adminMode;
    this.#methodPwg = adminMode
      ? "api/v1/categories"
      : "api/v1/categories/available";
    this.#limitParam = limitParam;
    this.#selected_categories = adminMode
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
        getSelectedAlbum: this.get_selected_albums.bind(this),
      });
    };
    this.#removeSelectedAlbum = (args) => {
      removeSelectedAlbum.call(null, {
        ...args,
        getSelectedAlbum: this.get_selected_albums.bind(this),
      });
    };
    this.#currentSelectedId = "";
    this.#show_root_btn = showRootButton;
    this.#put_to_root = false;
    this.#current_cat = currentAlbumId;
    this.#title = modalTitle === "" ? str_album_modal_title : modalTitle;
    this.#searchPlaceholder =
      modalSearchPlaceholder === ""
        ? str_album_modal_placeholder
        : modalSearchPlaceholder;
    this.#loading_add = false;
    // Replaced by the real configured separator on the first response; the
    // fallback is Piwigo's own default, for the window before one arrives.
    this.#levelSeparator = " / ";

    this.#init();
  }

  #init() {
    // console.log('init id:', activeAlbumSelector.instanceId);
    if (this.#in_admin_mode && this.#show_root_btn) {
      addClass(AlbumSelector.selectors.linkedAlbumPopInContainer, "big");
    }

    if (!this.#show_root_btn) {
      remove(AlbumSelector.selectors.putToRoot);
    }

    if (!this.#in_admin_mode) {
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
    this.#open_album_selector();
  }

  close() {
    if (activeAlbumSelector === this) {
      activeAlbumSelector = null;
    }
    this.#close_album_selector();
  }

  remove_selected_album(id: string | number) {
    if (this.#selected_categories.includes(id)) {
      const cat_to_remove_index = this.#selected_categories.indexOf(id);
      if (cat_to_remove_index > -1) {
        this.#selected_categories.splice(cat_to_remove_index, 1);
      }
    }

    this.#removeSelectedAlbum({ id_album: id });
  }

  get_selected_albums() {
    return [...this.#selected_categories];
  }

  select_album(id: string | number) {
    this.#selected_categories.push(id.toString());
  }

  resetAll() {
    this.#selected_categories = [];
    if (this.#in_admin_mode) {
      this.#hard_reset_album_selector();
    } else {
      this.#reset_album_selector();
    }
  }

  hardUpdate(cats: (string | number)[]) {
    this.#selected_categories = cats;
  }

  /*---------------------
  in selectAlbum() method
  ---------------------*/
  #newSelectedAlbum() {
    this.#selected_categories =
      this.#show_root_btn && this.#put_to_root
        ? ["0"]
        : [this.#currentSelectedId];
  }

  #addSelectedAlbum() {
    this.#selected_categories.push(this.#currentSelectedId);
  }

  /*----------
  Event method
  ----------*/
  #loadGeneralEvent() {
    const instanceAb = `.${this.instanceId}`;
    // event close album selector
    off(AlbumSelector.selectors.closeAlbumPopIn, `click${instanceAb}`);
    on(AlbumSelector.selectors.closeAlbumPopIn, `click${instanceAb}`, () => {
      this.#close_album_selector();
    });

    // event escape album selector
    off(document, `keyup${instanceAb}`);
    on(document, `keyup${instanceAb}`, (event) => {
      const e = event as KeyboardEvent;
      if (
        e.key === "Escape" &&
        AlbumSelector.selectors.addLinkedAlbum.some(isVisible)
      ) {
        this.#close_album_selector();
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
        this.#reset_search_input(true);
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
      this.#perform_albums_search(searchValue);
    });

    // event in admin mode
    if (this.#in_admin_mode) {
      off(AlbumSelector.selectors.albumCheckBox, `change${instanceAb}`);
      on(AlbumSelector.selectors.albumCheckBox, `change${instanceAb}`, (e) => {
        this.#isAlbumCreationChecked = is(
          e.currentTarget as Element,
          ":checked",
        );
        this.#switch_album_creation();
      });
    }

    // event put root btn
    if (this.#show_root_btn) {
      off(AlbumSelector.selectors.putToRootBtn, `click${instanceAb}`);
      on(AlbumSelector.selectors.putToRootBtn, `click${instanceAb}`, (e) => {
        if (!this.#selected_categories.includes("0")) {
          const curr = e.currentTarget as Element;
          addClass(curr, "notClickable");
          this.#put_to_root = true;
          this.#selectAlbum({ album: { id: 0, root: str_root } });
          this.#close_album_selector();
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
        const curr = e.currentTarget as Element;
        const cat_id = attrOf(curr, "id")!;
        const cat = this.#cats[cat_id]!;
        this.#switch_album_view(cat);
      });
    } else {
      const available = q(".prefill-results-item.available");
      off(available, `click${instanceAb}`);
      on(available, `click${instanceAb}`, (e) => {
        const curr = e.currentTarget as Element;
        const cat_id = attrOf(curr, "id")!;
        const cat = this.#cats[cat_id]!;

        this.#currentSelectedId = cat.id;
        this.#selectAlbum({ album: cat });
        this.#close_album_selector();
      });
    }
  }

  #loadSubCatEvent() {
    const instanceAb = `.${this.instanceId}`;
    const togglers = q(".display-subcat");
    off(togglers, `click${instanceAb}`);
    on(togglers, `click${instanceAb}`, (e) => {
      const curr = e.currentTarget as HTMLElement;
      const cat_id = curr.id;
      const cat = this.#cats[cat_id]!;

      if (hasClass(curr, "open")) {
        removeClass(curr, "open");
        fadeOut(q("#subcat-" + escapeId(cat.id)));
      } else if (q("#subcat-" + escapeId(cat.id)).length) {
        addClass(curr, "open");
        fadeIn(q("#subcat-" + escapeId(cat.id)));
      } else {
        const arrow = q("#" + escapeId(cat_id) + ".display-subcat");
        removeClass(arrow, "gallery-icon-up-open");
        addClass(arrow, "gallery-icon-spin6 animate-spin");
        after(
          q("#" + escapeId(cat_id) + ".search-result-item"),
          `<div id="subcat-${cat_id}" class="search-result-subcat-item"></div>`,
        );
        void this.#prefill_search_subcats(cat_id).then(() => {
          const settled = q("#" + escapeId(cat_id) + ".display-subcat");
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
      const curr = e.currentTarget as Element;
      const cat_id = attrOf(curr, "id")!;
      const cat = this.#searchCat[cat_id]!;

      const formated_cat_id = this.#in_admin_mode ? cat.id : String(cat.id);
      if (!tempSelect.includes(formated_cat_id)) {
        this.#currentSelectedId = cat.id;
        this.#selectAlbum({ album: cat });
        this.#close_album_selector();
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

  #open_album_selector() {
    this.#setActive();
    this.#loadGeneralEvent();

    if (this.#in_admin_mode) {
      this.#hard_reset_album_selector();
    } else {
      this.#reset_album_selector();
    }

    if (this.#show_root_btn && !this.#selected_categories.includes("0")) {
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

  #close_album_selector() {
    this.#cats = {};
    this.#searchCat = {};
    this.#currentSelectedId = "";
    this.#put_to_root = false;
    this.#loading_add = false;

    this.#destroyEvent();

    fadeOut(AlbumSelector.selectors.addLinkedAlbum);
  }

  #reset_album_selector() {
    this.#prefill_search();
    this.#reset_search_input(false);
    // AlbumSelector.selectors.searchInput.val('');
    // // AlbumSelector.selectors.searchInput.trigger("input");
    html(AlbumSelector.selectors.limitReached, str_no_search_in_progress);
    show(AlbumSelector.selectors.albumSelector);
  }

  #hard_reset_album_selector() {
    hide(AlbumSelector.selectors.albumCreate);
    this.#hide_new_album_error();

    this.#reset_album_selector();
    setVal(AlbumSelector.selectors.linkedAlbumInput, "");
    if (is(AlbumSelector.selectors.albumCheckBox, ":checked")) {
      trigger(AlbumSelector.selectors.albumCheckBox, "click");
    }
    show(AlbumSelector.selectors.searchResult);
    show(AlbumSelector.selectors.linkedAlbumSwitch);
  }

  #reset_search_input(prefill: boolean) {
    setVal(AlbumSelector.selectors.searchInput, "");
    show(AlbumSelector.selectors.limitReached);
    html(AlbumSelector.selectors.limitReached, str_no_search_in_progress);
    empty(AlbumSelector.selectors.searchResult);
    if (prefill) {
      this.#prefill_search();
    }
  }

  #switch_album_creation() {
    this.#reset_album_selector();
    const instanceAb = `.${this.instanceId}`;

    if (this.#isAlbumCreationChecked) {
      if (AlbumSelector.selectors.putToRoot.length) {
        hide(AlbumSelector.selectors.putToRoot);
      }
      hide(AlbumSelector.selectors.linkedModalTitle);
      html(AlbumSelector.selectors.linkedModalTitle, str_create_and_select);
      show(AlbumSelector.selectors.linkedAddAlbum);
      fadeIn(AlbumSelector.selectors.linkedModalTitle);

      off(AlbumSelector.selectors.linkedAddAlbum, `click${instanceAb}`);
      on(AlbumSelector.selectors.linkedAddAlbum, `click${instanceAb}`, () => {
        this.#switch_album_view("root");
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

  #switch_album_view(cat: AlbumCategory | "root") {
    const instanceAb = `.${this.instanceId}`;

    hide(AlbumSelector.selectors.albumSelector);
    hide(AlbumSelector.selectors.searchResult);
    hide(AlbumSelector.selectors.linkedAlbumSwitch);
    fadeIn(AlbumSelector.selectors.albumCreate);

    html(
      AlbumSelector.selectors.linkedAlbumSubTitle,
      sprintf(
        str_add_subcat_of,
        cat === "root" ? str_root_album_select : cat.name,
      ),
    );
    off(AlbumSelector.selectors.linkedAddNewAlbum, `click${instanceAb}`);
    on(AlbumSelector.selectors.linkedAddNewAlbum, `click${instanceAb}`, () => {
      this.#add_new_album(cat === "root" ? cat : cat.id);
    });

    off(AlbumSelector.selectors.linkedAlbumCancel, `click${instanceAb}`);
    on(AlbumSelector.selectors.linkedAlbumCancel, `click${instanceAb}`, () => {
      this.#close_album_selector();
    });

    off(AlbumSelector.selectors.linkedAlbumInput, `input${instanceAb}`);
    on(AlbumSelector.selectors.linkedAlbumInput, `input${instanceAb}`, () => {
      this.#hide_new_album_error();
    });
  }

  #hide_new_album_error() {
    css(AlbumSelector.selectors.addAlbumErrors, "visibility", "hidden");
  }

  #show_new_album_error(text: string) {
    html(AlbumSelector.selectors.linkedAddAlbumErrors, text);
    css(AlbumSelector.selectors.addAlbumErrors, "visibility", "visible");
  }

  #select_new_album_and_close(cat: CategoryAdmin) {
    this.#currentSelectedId = cat.id;
    this.#selectAlbum({ album: cat });
    this.#close_album_selector();
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
  #prefill_results(
    rank: string | number,
    cats: AlbumCategory[],
    limit: LimitInfo,
  ) {
    const isCreationMode = this.#isAlbumCreationChecked;
    const iconAlbum = this.#isAlbumCreationChecked
      ? "icon-add-album"
      : "gallery-icon-plus-circled";
    const tempSelectedCat = this.#current_cat
      ? [...this.#selected_categories, this.#current_cat.toString()]
      : [...this.#selected_categories];

    this.#cats = {
      ...this.#cats,
      ...Object.fromEntries(cats.map((c) => [c.id, c])),
    };
    let display_div = q("#subcat-" + escapeId(rank));
    if ("root" == rank) {
      empty(AlbumSelector.selectors.searchResult);
      display_div = AlbumSelector.selectors.searchResult;
    } else {
      display_div = q("#subcat-" + escapeId(rank));
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
          display_div,
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
          display_div,
          `<div class="search-result-item already-in" id="${cat.id}" title="${str_album_selected}">
              ${subcat}
              <div class="prefill-results-item" id="${cat.id}">
                <span class="search-result-path"><span class="search-result-path-name">${cat.name}</span></span> 
                <span id="${cat.id}" class="gallery-icon-plus-circled item-add notClickable" title="${str_album_selected}"></span>
              </div>
            </div>`,
        );
      }

      if (rank !== "root") {
        const item = q("#" + escapeId(rank) + ".search-result-item")[0];
        // `.css(name)` on an empty set is undefined, and parseInt of that
        // is NaN -- which then reaches `.css(prop, NaN)` and is skipped.
        // Preserved: an absent parent row leaves the margin untouched
        // rather than throwing.
        const margin_left =
          parseInt(item === undefined ? "" : cssValue(item, "margin-left")) +
          25;
        css(
          q("#" + escapeId(cat.id) + ".search-result-item"),
          "margin-left",
          margin_left,
        );
        css(
          q("#" + escapeId(cat.id) + ".search-result-item .search-result-path"),
          "max-width",
          400 - margin_left - 80,
        );
      }
    });

    this.#loadPickAlbumEvent();
    this.#loadSubCatEvent();
    // for debug
    // console.log(limit);
    if (limit.remainingCats > 0) {
      const text = sprintf(
        str_plus_albums_found,
        limit.limitedTo,
        limit.totalCats,
      );
      append(display_div, `<p class="and-more">${text}</p>`);
    }
  }

  #fill_results(cats: AlbumCategory[]) {
    const iconAlbum = this.#isAlbumCreationChecked
      ? "icon-add-album"
      : "gallery-icon-plus-circled";
    const tempSelectedCat = this.#current_cat
      ? [...this.#selected_categories, this.#current_cat.toString()]
      : [...this.#selected_categories];

    this.#searchCat = Object.fromEntries(cats.map((c) => [c.id, c]));
    empty(AlbumSelector.selectors.searchResult);

    cats.forEach((cat) => {
      const cat_name = this.#in_admin_mode ? cat.fullname! : cat.name;

      append(
        AlbumSelector.selectors.searchResult,
        `<div class='search-result-item' id="${cat.id}">
        <span class="search-result-path not-rtl">${this.#getEllipsisName(cat_name)}</span><span id="${cat.id}" class="${iconAlbum} item-add"></span>
      </div>`,
      );

      if (this.#isAlbumCreationChecked) {
        const instanceAb = `.${this.instanceId}`;
        const row = q(".search-result-item#" + escapeId(cat.id));
        off(row, `click${instanceAb}`);
        on(row, `click${instanceAb}`, () => {
          this.#switch_album_view(cat);
        });
        return;
      }

      if (tempSelectedCat.includes(cat.id)) {
        const adder = q(
          ".search-result-item #" + escapeId(cat.id) + ".item-add",
        );
        addClass(adder, "notClickable");
        attr(adder, "title", str_album_selected);
        const whole = q("#" + escapeId(cat.id) + ".search-result-item");
        addClass(whole, "notClickable");
        attr(whole, "title", str_album_selected);
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

  #getEllipsisName(str: string, lenght = 50) {
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
  #catIdParam(cat_id: string | number) {
    return this.#in_admin_mode ? { parentId: cat_id } : { catId: cat_id };
  }

  #prefill_search() {
    show(q(".linkedAlbumPopInContainer .searching"));
    const api_params = {
      ...this.#catIdParam(0),
      recursive: false,
      fullname: true,
      limit: this.#limitParam,
    };

    void ajax({
      url: this.#methodPwg,
      type: "GET",
      dataType: "json",
      data: api_params,
      success: (payload) => {
        // for debug
        // console.log(data);
        const data = payload as CategoryListOrAvailableResponse;
        this.#rememberLevelSeparator(data);
        hide(q(".linkedAlbumPopInContainer .searching"));
        const cats = data.categories;
        const limit = data.limit!;
        this.#prefill_results("root", cats, limit);
      },
      error: function (e) {
        hide(q(".linkedAlbumPopInContainer .searching"));
        console.error("error : ", e.responseText);
      },
    });
  }

  // eslint-disable-next-line @typescript-eslint/require-await -- the call site (`#loadSubCatEvent`) relies on this always returning a real Promise (`.then(...)`), even though the body never needs to `await` anything itself: `ajax()`'s own `error` callback already handles failures internally, nothing here re-throws.
  async #prefill_search_subcats(cat_id: string | number) {
    const api_params = {
      ...this.#catIdParam(cat_id),
      recursive: false,
      limit: this.#limitParam,
    };

    void ajax({
      url: this.#methodPwg,
      type: "GET",
      dataType: "json",
      data: api_params,
      success: (payload) => {
        const data = payload as CategoryListOrAvailableResponse;
        this.#rememberLevelSeparator(data);
        const cats = data.categories.filter((c) => c.id != cat_id);
        const limit = data.limit!;
        this.#prefill_results(cat_id, cats, limit);
      },
      error: (e) => {
        console.error("prefill search error :", e);
      },
    });
  }

  #perform_albums_search(searchText: string) {
    if (searchText == "") {
      this.#reset_search_input(true);
      return;
    }
    const api_params = {
      ...this.#catIdParam(0),
      recursive: true,
      fullname: true,
      search: searchText,
    };

    show(AlbumSelector.selectors.iconSearchingSpin);
    void ajax({
      url: this.#methodPwg,
      type: "GET",
      dataType: "json",
      data: api_params,
      success: (payload) => {
        const data = payload as CategoryListOrAvailableResponse;
        this.#rememberLevelSeparator(data);
        hide(AlbumSelector.selectors.iconSearchingSpin);
        const categories = data.categories;
        this.#fill_results(categories);

        if (data.limit && data.limit.remainingCats > 0) {
          html(
            AlbumSelector.selectors.limitReached,
            str_result_limit.replace("%d", String(categories.length)),
          );
        } else {
          if (categories.length == 1) {
            html(AlbumSelector.selectors.limitReached, str_album_found);
          } else {
            html(
              AlbumSelector.selectors.limitReached,
              str_albums_found.replace("%d", String(categories.length)),
            );
          }
        }
      },
      error: (e) => {
        hide(AlbumSelector.selectors.iconSearchingSpin);
        console.error(e.responseText);
      },
    });
  }

  #add_new_album(cat_id: string | number) {
    if (this.#loading_add) return;
    this.#loading_add = true;
    const cat_name = val(AlbumSelector.selectors.linkedAlbumInput);
    const cat_position = val(q("input[name=position]:checked"));
    const api_params = {
      name: cat_name,
      parentId: cat_id === "root" ? 0 : Number(cat_id),
      position: cat_position,
    };

    if (!cat_name || "" === cat_name) {
      this.#show_new_album_error(str_complete_name_field);
      return;
    }

    void ajax({
      url: "api/v1/categories",
      type: "POST",
      contentType: "application/json",
      headers: {
        "X-CSRF-Token": pwg_token,
      },
      data: JSON.stringify(api_params),
      dataType: "json",
      success: (payload) => {
        const data =
          payload as operations["categoryCreate"]["responses"][201]["content"]["application/json"];
        this.#get_album_by_id(data.id);
      },
      error: () => {
        this.#show_new_album_error(str_an_error_has_occured);
      },
    });
  }

  #get_album_by_id(cat_id: string | number) {
    void ajax({
      url: "api/v1/categories",
      type: "GET",
      dataType: "json",
      data: {
        parentId: cat_id,
      },
      success: (payload) => {
        const data =
          payload as operations["categoryList"]["responses"][200]["content"]["application/json"];
        this.#select_new_album_and_close(data.categories[0]!);
      },
      error: () => {
        this.#show_new_album_error(str_an_error_has_occured);
      },
    });
  }
}
