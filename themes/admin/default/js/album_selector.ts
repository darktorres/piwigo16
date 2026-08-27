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
// Every real consumer imports via the `?dup` suffix (Design §4) --
// this file has many real registrant pages. On the 2 pages where 2
// real consumer files coexist (batch_manager_unit.php:
// batchManagerUnit.ts + batchManagerFilter.ts; batch_manager_global.php:
// batchManagerGlobal.ts + batchManagerFilter.ts), each file's own
// independent `?dup` import means 2 separate `AlbumSelector` class
// copies load on that one page -- a real, accepted behavior change
// (docs/PLAN.md's own Design §6 precedent for documented, unavoidable
// changes): `activeAlbumSelector`'s single-active-popup coordination
// (below) no longer spans both widgets on those 2 pages specifically,
// since each copy tracks its own independent module state. No safe
// single-copy alternative exists here -- unlike batchManagerGlobal.ts/
// batch_manager_global.ts's own circular pair, batchManagerFilter.ts is
// itself shared across 2 different pages, so it can't statically import
// "the one true copy" from either page's own hub file.
//
// This file's own `sprintf` need is satisfied by a *nested* `?dup`
// import (common.ts, itself imported via `?dup` since it too has many
// real registrant pages) -- the first real use of this pattern in the
// codebase, since this file is itself already `?dup`-imported by its 8
// own consumers above. Confirmed sound directly from vite.config.ts's
// own plugin: each `resolveId` call for THIS import receives the
// *current* importer (the caller's own already-unique dup'd virtual
// id, which differs per outer consumer), so the resulting nested id
// stays uniquely keyed per outer page just the same -- no shared-chunk
// collision risk, just (accepted, Design §4) code duplicated once per
// outer consumer that reaches this file.
import { sprintf } from "./common";
import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../default/js/page-data";

// Real shapes for the 2 real GET endpoints this file's own #methodPwg
// switches between (admin mode: /categories; non-admin: /categories/available),
// via the existing OpenAPI schema. Kept as top-level `type X = import(...)`
// aliases -- real `import(...)` type-only references, distinct from this
// file's own real `export`s below.
type CategoryAdmin =
  import("../../../../openapi/client/schema").operations["categoryList"]["responses"][200]["content"]["application/json"]["categories"][number];
type CategoryAvailable =
  import("../../../../openapi/client/schema").operations["categoryAvailableList"]["responses"][200]["content"]["application/json"]["categories"][number];
type LimitInfo =
  import("../../../../openapi/client/schema").operations["categoryList"]["responses"][200]["content"]["application/json"]["limit"];
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
  | import("../../../../openapi/client/schema").operations["categoryList"]["responses"][200]["content"]["application/json"]
  | import("../../../../openapi/client/schema").operations["categoryAvailableList"]["responses"][200]["content"]["application/json"];

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

$(window).on("keypress", function (e) {
  const haveAlbumSelector = $("#addLinkedAlbum").is(":visible");
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

  /**
   * Selector for AlbumSelector
   */
  static selectors = {
    addLinkedAlbum: $("#addLinkedAlbum"),
    closeAlbumPopIn: $("#closeAlbumPopIn"),
    searchInput: $("#search-input-ab"),
    searchResult: $("#searchResult"),
    limitReached: $(".limitReached"),
    iconCancelInput: $(".search-cancel-linked-album"),
    relatedCategoriesDom: $(
      ".related-categories-container .breadcrumb-item .remove-item",
    ),
    iconSearchingSpin: $(".searching"),
    albumSelector: $("#linkedAlbumSelector"),
    albumCreate: $("#linkedAlbumCreate"),
    albumCheckBox: $("#album-create-check"),
    linkedAddAlbum: $("#linkedAddAlbum"),
    linkedModalTitle: $("#linkedModalTitle"),
    linkedAlbumSwitch: $("#linkedAlbumSwitch"),
    linkedAlbumSubTitle: $("#linkedAlbumSubtitle"),
    linkedAddNewAlbum: $("#linkedAddNewAlbum"),
    linkedAlbumInput: $("#linkedAlbumInput"),
    putToRoot: $(".put-to-root-container"),
    linkedAlbumCancel: $("#linkedAlbumCancel"),
    linkedAddAlbumErrors: $("#linkedAddAlbumErrors"),
    addAlbumErrors: $(".AddAlbumErrors"),
    putToRootBtn: $("#put-to-root"),
    linkedAlbumPopInContainer: $(".linkedAlbumPopInContainer"),
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
    this.#selectAlbum = (args) =>
      selectAlbum.call(null, {
        ...args,
        newSelectedAlbum: this.#newSelectedAlbum.bind(this),
        addSelectedAlbum: this.#addSelectedAlbum.bind(this),
        getSelectedAlbum: this.get_selected_albums.bind(this),
      });
    this.#removeSelectedAlbum = (args) =>
      removeSelectedAlbum.call(null, {
        ...args,
        getSelectedAlbum: this.get_selected_albums.bind(this),
      });
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

    this.#init();
  }

  #init() {
    // console.log('init id:', activeAlbumSelector.instanceId);
    if (this.#in_admin_mode && this.#show_root_btn) {
      AlbumSelector.selectors.linkedAlbumPopInContainer.addClass("big");
    }

    if (!this.#show_root_btn) {
      AlbumSelector.selectors.putToRoot.remove();
    }

    if (!this.#in_admin_mode) {
      AlbumSelector.selectors.albumCreate.remove();
      AlbumSelector.selectors.linkedAlbumSwitch.remove();
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
    AlbumSelector.selectors.closeAlbumPopIn
      .off(`click${instanceAb}`)
      .on(`click${instanceAb}`, () => {
        this.#close_album_selector();
      });

    // event escape album selector
    $(document)
      .off(`keyup${instanceAb}`)
      .on(`keyup${instanceAb}`, (e) => {
        if (
          e.key === "Escape" &&
          AlbumSelector.selectors.addLinkedAlbum.is(":visible")
        ) {
          this.#close_album_selector();
        }

        if (
          e.key === "Enter" &&
          AlbumSelector.selectors.addLinkedAlbum.is(":visible")
        ) {
          if ($("#linkedAddNewAlbum").is(":visible")) {
            AlbumSelector.selectors.linkedAddNewAlbum.trigger(
              `click${instanceAb}`,
            );
          }
        }
      });

    // event empty search input
    if (AlbumSelector.selectors.iconCancelInput.length) {
      AlbumSelector.selectors.iconCancelInput
        .off(`click${instanceAb}`)
        .on(`click${instanceAb}`, () => {
          this.#reset_search_input(true);
        });
    }

    // event perform search
    AlbumSelector.selectors.searchInput
      .off(`keyup${instanceAb}`)
      .on(`keyup${instanceAb}`, (_e) => {
        const searchValue = String(
          AlbumSelector.selectors.searchInput.val() ?? "",
        );
        if (searchValue.length > 0) {
          AlbumSelector.selectors.iconCancelInput.show();
        } else {
          AlbumSelector.selectors.iconCancelInput.hide();
        }
        this.#perform_albums_search(searchValue);
      });

    // event in admin mode
    if (this.#in_admin_mode) {
      AlbumSelector.selectors.albumCheckBox
        .off(`change${instanceAb}`)
        .on(`change${instanceAb}`, (e) => {
          this.#isAlbumCreationChecked = $(e.currentTarget).is(":checked");
          this.#switch_album_creation();
        });
    }

    // event put root btn
    if (this.#show_root_btn) {
      AlbumSelector.selectors.putToRootBtn
        .off(`click${instanceAb}`)
        .on(`click${instanceAb}`, (e) => {
          if (!this.#selected_categories.includes("0")) {
            const curr = $(e.currentTarget);
            curr.addClass("notClickable");
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
      $(".prefill-results-item")
        .off(`click${instanceAb}`)
        .on(`click${instanceAb}`, (e) => {
          const curr = $(e.currentTarget);
          const cat_id = curr.attr("id")!;
          const cat = this.#cats[cat_id]!;
          this.#switch_album_view(cat);
        });
    } else {
      $(".prefill-results-item.available")
        .off(`click${instanceAb}`)
        .on(`click${instanceAb}`, (e) => {
          const curr = $(e.currentTarget);
          const cat_id = curr.attr("id")!;
          const cat = this.#cats[cat_id]!;

          this.#currentSelectedId = cat.id;
          this.#selectAlbum({ album: cat });
          this.#close_album_selector();
        });
    }
  }

  #loadSubCatEvent() {
    const instanceAb = `.${this.instanceId}`;
    $(".display-subcat")
      .off(`click${instanceAb}`)
      .on(`click${instanceAb}`, (e) => {
        const curr = e.currentTarget;
        const cat_id = $(curr).prop("id") as string;
        const cat = this.#cats[cat_id]!;

        if ($(curr).hasClass("open")) {
          $(curr).removeClass("open");
          $("#subcat-" + cat.id).fadeOut();
        } else if ($("#subcat-" + cat.id).length) {
          $(curr).addClass("open");
          $("#subcat-" + cat.id).fadeIn();
        } else {
          $("#" + cat_id + ".display-subcat")
            .removeClass("gallery-icon-up-open")
            .addClass("gallery-icon-spin6 animate-spin");
          $("#" + cat_id + ".search-result-item").after(
            `<div id="subcat-${cat_id}" class="search-result-subcat-item"></div>`,
          );
          void this.#prefill_search_subcats(cat_id).then(() => {
            $("#" + cat_id + ".display-subcat")
              .removeClass("gallery-icon-spin6 animate-spin")
              .addClass("gallery-icon-up-open");
            $(curr).addClass("open");
            $("#subcat-" + cat.id).fadeIn();
          });
        }
      });
  }

  #loadFillResultEvent(tempSelect: (string | number)[]) {
    const instanceAb = `.${this.instanceId}`;

    AlbumSelector.selectors.searchResult
      .find(".search-result-item")
      .off(`click${instanceAb}`)
      .on(`click${instanceAb}`, (e) => {
        const curr = $(e.currentTarget);
        const cat_id = curr.attr("id")!;
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
      AlbumSelector.selectors.putToRootBtn.removeClass("notClickable");
    } else {
      AlbumSelector.selectors.putToRootBtn.addClass("notClickable");
    }

    AlbumSelector.selectors.linkedModalTitle.html(this.#title);
    AlbumSelector.selectors.searchInput.attr(
      "placeholder",
      this.#searchPlaceholder,
    );
    AlbumSelector.selectors.addLinkedAlbum.fadeIn();
  }

  #close_album_selector() {
    this.#cats = {};
    this.#searchCat = {};
    this.#currentSelectedId = "";
    this.#put_to_root = false;
    this.#loading_add = false;

    this.#destroyEvent();

    AlbumSelector.selectors.addLinkedAlbum.fadeOut();
  }

  #reset_album_selector() {
    this.#prefill_search();
    this.#reset_search_input(false);
    // AlbumSelector.selectors.searchInput.val('');
    // // AlbumSelector.selectors.searchInput.trigger("input");
    AlbumSelector.selectors.limitReached.html(str_no_search_in_progress);
    AlbumSelector.selectors.albumSelector.show();
  }

  #hard_reset_album_selector() {
    AlbumSelector.selectors.albumCreate.hide();
    this.#hide_new_album_error();

    this.#reset_album_selector();
    AlbumSelector.selectors.linkedAlbumInput.val("");
    if (AlbumSelector.selectors.albumCheckBox.is(":checked")) {
      AlbumSelector.selectors.albumCheckBox.trigger("click");
    }
    AlbumSelector.selectors.searchResult.show();
    AlbumSelector.selectors.linkedAlbumSwitch.show();
  }

  #reset_search_input(prefill: boolean) {
    AlbumSelector.selectors.searchInput.val("");
    AlbumSelector.selectors.limitReached.show().html(str_no_search_in_progress);
    AlbumSelector.selectors.searchResult.empty();
    if (prefill) {
      this.#prefill_search();
    }
  }

  #switch_album_creation() {
    this.#reset_album_selector();
    const instanceAb = `.${this.instanceId}`;

    if (this.#isAlbumCreationChecked) {
      if (AlbumSelector.selectors.putToRoot.length) {
        AlbumSelector.selectors.putToRoot.hide();
      }
      AlbumSelector.selectors.linkedModalTitle.hide();
      AlbumSelector.selectors.linkedModalTitle.html(str_create_and_select);
      AlbumSelector.selectors.linkedAddAlbum.show();
      AlbumSelector.selectors.linkedModalTitle.fadeIn();

      AlbumSelector.selectors.linkedAddAlbum
        .off(`click${instanceAb}`)
        .on(`click${instanceAb}`, () => {
          this.#switch_album_view("root");
        });
    } else {
      if (AlbumSelector.selectors.putToRoot.length) {
        AlbumSelector.selectors.putToRoot.fadeIn();
      }
      AlbumSelector.selectors.linkedModalTitle.hide();
      AlbumSelector.selectors.linkedModalTitle.html(this.#title);
      AlbumSelector.selectors.linkedModalTitle.fadeIn();
      AlbumSelector.selectors.linkedAddAlbum.hide();
      AlbumSelector.selectors.linkedAddAlbum.off("click");
    }
  }

  #switch_album_view(cat: AlbumCategory | "root") {
    const instanceAb = `.${this.instanceId}`;

    AlbumSelector.selectors.albumSelector.hide();
    AlbumSelector.selectors.searchResult.hide();
    AlbumSelector.selectors.linkedAlbumSwitch.hide();
    AlbumSelector.selectors.albumCreate.fadeIn();

    AlbumSelector.selectors.linkedAlbumSubTitle.html(
      sprintf(
        str_add_subcat_of,
        cat === "root" ? str_root_album_select : cat.name,
      ),
    );
    AlbumSelector.selectors.linkedAddNewAlbum
      .off(`click${instanceAb}`)
      .on(`click${instanceAb}`, () => {
        this.#add_new_album(cat === "root" ? cat : cat.id);
      });

    AlbumSelector.selectors.linkedAlbumCancel
      .off(`click${instanceAb}`)
      .on(`click${instanceAb}`, () => {
        this.#close_album_selector();
      });

    AlbumSelector.selectors.linkedAlbumInput
      .off(`input${instanceAb}`)
      .on(`input${instanceAb}`, () => {
        this.#hide_new_album_error();
      });
  }

  #hide_new_album_error() {
    AlbumSelector.selectors.addAlbumErrors.css("visibility", "hidden");
  }

  #show_new_album_error(text: string) {
    AlbumSelector.selectors.linkedAddAlbumErrors.html(text);
    AlbumSelector.selectors.addAlbumErrors.css("visibility", "visible");
  }

  #select_new_album_and_close(cat: CategoryAdmin) {
    this.#currentSelectedId = cat.id;
    this.#selectAlbum({ album: cat });
    this.#close_album_selector();
  }

  #destroyEvent() {
    const instanceAb = `.${this.instanceId}`;

    $(document).off(`keyup${instanceAb}`);
    $(document).off(`click${instanceAb}`);
    $(document).off(`change${instanceAb}`);
    $(document).off(`input${instanceAb}`);
    AlbumSelector.selectors.searchInput.off(`keyup${instanceAb}`);
    AlbumSelector.selectors.searchResult
      .find(".search-result-item")
      .off(`click${instanceAb}`);
    $(".prefill-results-item").off(`click${instanceAb}`);
    $(".prefill-results-item.available").off(`click${instanceAb}`);
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
    let display_div = $("#subcat-" + rank);
    if ("root" == rank) {
      AlbumSelector.selectors.searchResult.empty();
      display_div = AlbumSelector.selectors.searchResult;
    } else {
      display_div = $("#subcat-" + rank);
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
        display_div.append(
          `<div class="search-result-item" id="${cat.id}">
              ${subcat}
              <div class="prefill-results-item available" id="${cat.id}">
                <span class="search-result-path"><span class="search-result-path-name">${cat.name}</span></span>
                <span id=${cat.id}" class="${iconAlbum} item-add"></span>
              </div>
            </div>`,
        );
      } else {
        display_div.append(
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
        const item = $("#" + rank + ".search-result-item");
        const margin_left = parseInt(item.css("margin-left")) + 25;
        $("#" + cat.id + ".search-result-item").css("margin-left", margin_left);
        $("#" + cat.id + ".search-result-item .search-result-path").css(
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
      display_div.append(`<p class="and-more">${text}</p>`);
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
    AlbumSelector.selectors.searchResult.empty();

    cats.forEach((cat) => {
      const cat_name = this.#in_admin_mode ? cat.fullname! : cat.name;

      AlbumSelector.selectors.searchResult.append(
        `<div class='search-result-item' id="${cat.id}">
        <span class="search-result-path not-rtl">${this.#getEllipsisName(cat_name)}</span><span id="${cat.id}" class="${iconAlbum} item-add"></span>
      </div>`,
      );

      if (this.#isAlbumCreationChecked) {
        const instanceAb = `.${this.instanceId}`;
        $(".search-result-item#" + cat.id)
          .off(`click${instanceAb}`)
          .on(`click${instanceAb}`, () => {
            this.#switch_album_view(cat);
          });
        return;
      }

      if (tempSelectedCat.includes(cat.id)) {
        $(".search-result-item #" + cat.id + ".item-add")
          .addClass("notClickable")
          .attr("title", str_album_selected);
        $("#" + cat.id + ".search-result-item")
          .addClass("notClickable")
          .attr("title", str_album_selected);
      }
    });

    if (!this.#isAlbumCreationChecked)
      this.#loadFillResultEvent(tempSelectedCat);
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
    $(".linkedAlbumPopInContainer .searching").show();
    const api_params = {
      ...this.#catIdParam(0),
      recursive: false,
      fullname: true,
      limit: this.#limitParam,
    };

    $.ajax({
      url: this.#methodPwg,
      type: "GET",
      dataType: "json",
      data: api_params,
      success: (data: CategoryListOrAvailableResponse) => {
        // for debug
        // console.log(data);
        $(".linkedAlbumPopInContainer .searching").hide();
        const cats = data.categories;
        const limit = data.limit!;
        this.#prefill_results("root", cats, limit);
      },
      error: function (e: JQuery.jqXHR) {
        $(".linkedAlbumPopInContainer .searching").hide();
        console.log("error : ", e.responseText);
      },
    });
  }

  // eslint-disable-next-line @typescript-eslint/require-await -- the call site (`#loadSubCatEvent`) relies on this always returning a real Promise (`.then(...)`), even though the body never needs to `await` anything itself: `$.ajax`'s own `error` callback already handles failures internally, nothing here re-throws.
  async #prefill_search_subcats(cat_id: string | number) {
    const api_params = {
      ...this.#catIdParam(cat_id),
      recursive: false,
      limit: this.#limitParam,
    };

    $.ajax({
      url: this.#methodPwg,
      type: "GET",
      dataType: "json",
      data: api_params,
      success: (data: CategoryListOrAvailableResponse) => {
        const cats = data.categories.filter((c) => c.id != cat_id);
        const limit = data.limit!;
        this.#prefill_results(cat_id, cats, limit);
      },
      error: (e: JQuery.jqXHR) => {
        console.log("prefill search error :", e);
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

    AlbumSelector.selectors.iconSearchingSpin.show();
    $.ajax({
      url: this.#methodPwg,
      type: "GET",
      dataType: "json",
      data: api_params,
      success: (data: CategoryListOrAvailableResponse) => {
        AlbumSelector.selectors.iconSearchingSpin.hide();
        const categories = data.categories;
        this.#fill_results(categories);

        if (data.limit && data.limit.remainingCats > 0) {
          AlbumSelector.selectors.limitReached.html(
            str_result_limit.replace("%d", String(categories.length)),
          );
        } else {
          if (categories.length == 1) {
            AlbumSelector.selectors.limitReached.html(str_album_found);
          } else {
            AlbumSelector.selectors.limitReached.html(
              str_albums_found.replace("%d", String(categories.length)),
            );
          }
        }
      },
      error: (e: JQuery.jqXHR) => {
        AlbumSelector.selectors.iconSearchingSpin.hide();
        console.log(e.responseText);
      },
    });
  }

  #add_new_album(cat_id: string | number) {
    if (this.#loading_add) return;
    this.#loading_add = true;
    const cat_name = AlbumSelector.selectors.linkedAlbumInput.val();
    const cat_position = $("input[name=position]:checked").val();
    const api_params = {
      name: cat_name,
      parentId: cat_id === "root" ? 0 : +cat_id,
      position: cat_position,
    };

    if (!cat_name || "" === cat_name) {
      this.#show_new_album_error(str_complete_name_field);
      return;
    }

    $.ajax({
      url: "api/v1/categories",
      type: "POST",
      contentType: "application/json",
      headers: {
        "X-CSRF-Token": pwg_token,
      },
      data: JSON.stringify(api_params),
      dataType: "json",
      success: (
        data: import("../../../../openapi/client/schema").operations["categoryCreate"]["responses"][201]["content"]["application/json"],
      ) => {
        this.#get_album_by_id(data.id);
      },
      error: () => {
        this.#show_new_album_error(str_an_error_has_occured);
      },
    });
  }

  #get_album_by_id(cat_id: string | number) {
    $.ajax({
      url: "api/v1/categories",
      type: "GET",
      dataType: "json",
      data: {
        parentId: cat_id,
      },
      success: (
        data: import("../../../../openapi/client/schema").operations["categoryList"]["responses"][200]["content"]["application/json"],
      ) => {
        this.#select_new_album_and_close(data.categories[0]!);
      },
      error: () => {
        this.#show_new_album_error(str_an_error_has_occured);
      },
    });
  }
}
