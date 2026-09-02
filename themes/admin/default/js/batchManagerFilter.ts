// Real consumer of album_selector.ts's own top-level `class
// AlbumSelector` (docs/PLAN.md P48 -- was a bare ambient-global read,
// see that file's own leading comment for the full real-consumer
// list). This file is its own Vite entry, registered on 2 real pages
// (batch_manager_unit.php, batch_manager_global.php), each of which
// also reaches album_selector.ts through its other real consumer
// (batchManagerUnit.ts / batchManagerGlobal.ts).
//
// That used to mean two independent `AlbumSelector` class copies
// coexisted on either page, since every consumer was handed a private
// duplicate. It no longer does: album_selector.ts is emitted once as a
// shared chunk, so both consumers now hold the *same* class and the
// single-active-popup coordination its module state was written for
// actually works.
import { AlbumSelector } from "./album_selector";
import { pwgDoubleSlider } from "./doubleSlider";

import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../default/js/page-data";
import {
  addClass,
  attrOf,
  fadeIn,
  fadeOut,
  hide,
  html,
  isVisible,
  off,
  on,
  ready,
  removeClass,
  setVal,
  show,
  slideToggle,
  slideUp,
  val,
} from "../../../default/js/vendor/dom";

// `sliders` here is a genuinely independent, unrelated top-level
// `const` from search_filters.ts's own real exported `sliders` (a
// different theme, a different page, never co-loaded) -- this file's
// own `export {}` module isolation keeps the two from ever colliding
// regardless.
interface DimensionsData {
  widths: string;
  heights: string;
  ratios: string;
  selected: {
    min_width: string | number;
    max_width: string | number;
    min_height: string | number;
    max_height: string | number;
    min_ratio: string | number;
    max_ratio: string | number;
  };
}
interface FilesizeData {
  list: string;
  selected: { min: string | number; max: string | number };
}

const dimensions = pwg_getPageData<DimensionsData>("dimensions");
const filesizeData = pwg_getPageData<FilesizeData>("filesize");

const sliders = {
  widths: {
    values: dimensions.widths.split(",").map(Number),
    selected: {
      min: Number(dimensions.selected.min_width),
      max: Number(dimensions.selected.max_width),
    },
    text: pwg_getPageString("between %d and %d pixels"),
  },

  heights: {
    values: dimensions.heights.split(",").map(Number),
    selected: {
      min: Number(dimensions.selected.min_height),
      max: Number(dimensions.selected.max_height),
    },
    text: pwg_getPageString("between %d and %d pixels"),
  },

  ratios: {
    values: dimensions.ratios.split(",").map(Number),
    selected: {
      min: Number(dimensions.selected.min_ratio),
      max: Number(dimensions.selected.max_ratio),
    },
    text: pwg_getPageString("between %.2f and %.2f"),
  },

  filesizes: {
    values: filesizeData.list.split(",").map(Number),
    selected: {
      min: Number(filesizeData.selected.min),
      max: Number(filesizeData.selected.max),
    },
    text: pwg_getPageString("between %s and %s MB"),
  },
};

const filterCategorySelected = pwg_getPageData<number | null>(
  "filter_category_selected",
);
const selected_filter_cat_ids =
  filterCategorySelected !== null && filterCategorySelected !== 0
    ? [String(filterCategorySelected)]
    : [];

const str_select_album = pwg_getPageString("Select at least one album");
const str_select_tag = pwg_getPageString("Select at least one tag");
let errorFilters = "";

/* ********** Filters*/
function filter_enable(filter: string) {
  /* show the filter*/
  show(document.querySelectorAll("#" + filter));

  /* check the checkbox to declare we use this filter */
  document
    .querySelectorAll('input[type=checkbox][name="' + filter + '_use"]')
    .forEach((el) => {
      (el as HTMLInputElement).checked = true;
    });

  /* forbid to select this filter in the addFilter list */
  addClass(
    document.querySelectorAll('#addFilter a[data-value="' + filter + '"]'),
    "disabled",
  );

  /* hide the no filter message */
  hide(document.querySelectorAll(".noFilter"));
  removeClass(document.querySelectorAll(".addFilter-button"), "highlight");
}

function filter_disable(filter: string) {
  /* hide the filter line */
  hide(document.querySelectorAll("#" + filter));

  /* uncheck the checkbox to declare we do not use this filter */
  document
    .querySelectorAll('input[name="' + filter + '_use"]')
    .forEach((el) => {
      (el as HTMLInputElement).checked = false;
    });

  /* give the possibility to show it again */
  removeClass(
    document.querySelectorAll('#addFilter a[data-value="' + filter + '"]'),
    "disabled",
  );

  /* show the no filter message if no filter selected */
  const anyVisible = Array.from(
    document.querySelectorAll("#filterList li"),
  ).some((el) => isVisible(el));
  if (!anyVisible) {
    show(document.querySelectorAll(".noFilter"));
    addClass(document.querySelectorAll(".addFilter-button"), "highlight");
  }
}
// Album Selector
function select_album_filter({
  album,
  newSelectedAlbum,
  getSelectedAlbum,
}: AlbumSelectorCallbackArgs) {
  html(document.querySelectorAll("#selectedAlbumNameFilter"), album.name!);
  newSelectedAlbum();
  hide_filters_error(str_select_album);
  setVal(
    document.querySelectorAll("#filterCategoryValue"),
    String(Number(getSelectedAlbum()[0]!)),
  );
  hide(document.querySelectorAll("#selectAlbumFilter"));
  fadeIn(document.querySelectorAll("#selectedAlbumFilterArea"));
}

// Tags and Albums validation
function show_filters_error(message: string) {
  errorFilters = message;
  html(document.querySelectorAll("#errorFilter"), `<p>${message}</p>`);
  fadeIn(document.querySelectorAll("#errorFilter"));
}

function hide_filters_error(message: string) {
  if (message === errorFilters) {
    hide(document.querySelectorAll("#errorFilter"));
  }
}

ready(function () {
  // Moved out of batch_manager_filter.inc.latte's own
  // `onclick="$('.addFilter-dropdown').slideToggle()"` -- see albums.ts for
  // why these came out of the templates. The document-level handler
  // further down already slides this dropdown up when a click lands
  // outside it, and skips clicks on .addFilter-button precisely so this
  // toggle keeps working.
  on(document.querySelectorAll(".addFilter-button"), "click", function () {
    slideToggle(document.querySelectorAll(".addFilter-dropdown"));
  });

  const ab_filter = new AlbumSelector({
    selectedCategoriesIds: selected_filter_cat_ids,
    selectAlbum: select_album_filter,
    adminMode: true,
  });

  on(
    document.querySelectorAll("#selectAlbumFilter, #selectedAlbumEditFilter"),
    "click",
    function () {
      ab_filter.open();
    },
  );

  addClass(document.querySelectorAll(".removeFilter"), "icon-cancel-circled");

  on(
    document.querySelectorAll(".removeFilter"),
    "click",
    function (event: Event): boolean {
      event.preventDefault();
      // jQuery's `.parent("li")` -- the immediate parent, filtered by tag;
      // every real .removeFilter is inside exactly one <li> with a real id.
      const li = (event.currentTarget as Element).parentElement!;
      const filter = li.id;
      filter_disable(filter);

      return false;
    },
  );

  on(
    document.querySelectorAll("#addFilter a"),
    "click",
    function (event: Event): void {
      const filter = attrOf(event.currentTarget as Element, "data-value")!;
      filter_enable(filter);
    },
  );

  on(
    document.querySelectorAll("#removeFilters"),
    "click",
    function (event: Event): boolean {
      event.preventDefault();
      document.querySelectorAll("#filterList li").forEach((el) => {
        filter_disable(el.id);
      });
      return false;
    },
  );

  pwgDoubleSlider(
    document.querySelector("[data-slider=widths]")!,
    sliders.widths,
  );
  pwgDoubleSlider(
    document.querySelector("[data-slider=heights]")!,
    sliders.heights,
  );
  pwgDoubleSlider(
    document.querySelector("[data-slider=ratios]")!,
    sliders.ratios,
  );
  pwgDoubleSlider(
    document.querySelector("[data-slider=filesizes]")!,
    sliders.filesizes,
  );

  on(document, "mouseup", function (e: Event): void {
    e.stopPropagation();
    if (!(e.target as Element).classList.contains("addFilter-button")) {
      slideUp(document.querySelectorAll(".addFilter-dropdown"));
    }
  });

  // Filter JS Validation -- reads the real underlying <select>'s value,
  // which selectize (`vendor/selectize.ts`, P49-B group 6) keeps synced
  // and now dispatches a real native "change" event for.
  on(
    document.querySelectorAll('.filterBlock select[data-selectize="tags"]'),
    "change",
    function (event: Event): void {
      const tagValue = val([event.currentTarget as Element]);
      if (tagValue !== undefined && tagValue !== "") {
        hide_filters_error(str_select_tag);
      }
    },
  );

  on(
    document.querySelectorAll("#applyFilter"),
    "click",
    function (e: Event): void {
      const filterTags = document.getElementById("filter_tags");
      if (filterTags !== null && isVisible(filterTags)) {
        const tags = document.querySelectorAll(
          '.filterBlock select[data-selectize="tags"]',
        );
        const tagsValue = val(tags);
        if (tagsValue === undefined || tagsValue === "") {
          e.preventDefault();
          show_filters_error(str_select_tag);
          const removeFilterTags = document.querySelectorAll(
            "#filter_tags .removeFilter",
          );
          off(removeFilterTags, "click.apply");
          on(removeFilterTags, "click.apply", function () {
            hide_filters_error(str_select_tag);
          });
        }
      }

      const filterCategory = document.getElementById("filter_category");
      if (filterCategory !== null && isVisible(filterCategory)) {
        const albums = ab_filter.get_selected_albums();
        if (albums.length === 0) {
          e.preventDefault();
          show_filters_error(str_select_album);
          const removeFilterCategory = document.querySelectorAll(
            "#filter_category .removeFilter",
          );
          off(removeFilterCategory, "click.apply");
          on(removeFilterCategory, "click.apply", function () {
            hide_filters_error(str_select_album);
          });
        }
      }
    },
  );

  on(document.querySelectorAll(".help-popin-search"), "click", function () {
    fadeIn(document.querySelectorAll("#modalQuickSearch"));
  });

  on(document.querySelectorAll("#closeModalQuickSearch"), "click", function () {
    fadeOut(document.querySelectorAll("#modalQuickSearch"));
  });
});
