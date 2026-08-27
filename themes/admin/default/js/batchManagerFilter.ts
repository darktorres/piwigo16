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
// doubleSlider.ts's own side effect only (`$.fn.pwgDoubleSlider`, this
// file's real `.pwgDoubleSlider(...)` call sites below).
import "./doubleSlider";

import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../default/js/page-data";
export {};

// `sliders` here is a genuinely independent, unrelated top-level `var`
// from search_filters.ts's own `window.sliders` (a different theme, a
// different page, never co-loaded) -- this file's own `export {}`
// module isolation keeps the two from ever colliding regardless.
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
const selected_filter_cat_ids = filterCategorySelected
  ? [String(filterCategorySelected)]
  : [];

const str_select_album = pwg_getPageString("Select at least one album");
const str_select_tag = pwg_getPageString("Select at least one tag");
let errorFilters = "";

/* ********** Filters*/
function filter_enable(filter: string) {
  /* show the filter*/
  $("#" + filter).show();

  /* check the checkbox to declare we use this filter */
  $("input[type=checkbox][name=" + filter + "_use]").prop("checked", true);

  /* forbid to select this filter in the addFilter list */
  $("#addFilter")
    .find("a[data-value=" + filter + "]")
    .addClass("disabled");

  /* hide the no filter message */
  $(".noFilter").hide();
  $(".addFilter-button").removeClass("highlight");
}

function filter_disable(filter: string) {
  /* hide the filter line */
  $("#" + filter).hide();

  /* uncheck the checkbox to declare we do not use this filter */
  $("input[name=" + filter + "_use]").prop("checked", false);

  /* give the possibility to show it again */
  $("#addFilter")
    .find("a[data-value=" + filter + "]")
    .removeClass("disabled");

  /* show the no filter message if no filter selected */
  if ($("#filterList li:visible").length == 0) {
    $(".noFilter").show();
    $(".addFilter-button").addClass("highlight");
  }
}
// Album Selector
function select_album_filter({
  album,
  newSelectedAlbum,
  getSelectedAlbum,
}: AlbumSelectorCallbackArgs) {
  $("#selectedAlbumNameFilter").html(album.name!);
  newSelectedAlbum();
  hide_filters_error(str_select_album);
  $("#filterCategoryValue").val(+getSelectedAlbum()[0]!);
  $("#selectAlbumFilter").hide();
  $("#selectedAlbumFilterArea").fadeIn();
}

// Tags and Albums validation
function show_filters_error(message: string) {
  errorFilters = message;
  $("#errorFilter").html(`<p>${message}</p>`).fadeIn();
}

function hide_filters_error(message: string) {
  if (message === errorFilters) {
    $("#errorFilter").hide();
  }
}

$(document).ready(function () {
  const ab_filter = new AlbumSelector({
    selectedCategoriesIds: selected_filter_cat_ids,
    selectAlbum: select_album_filter,
    adminMode: true,
  });

  $("#selectAlbumFilter, #selectedAlbumEditFilter").on("click", function () {
    ab_filter.open();
  });

  $(".removeFilter").addClass("icon-cancel-circled");

  $(".removeFilter").click(function () {
    const filter = $(this).parent("li").attr("id")!;
    filter_disable(filter);

    return false;
  });

  $("#addFilter a").on("click", function () {
    const filter = $(this).attr("data-value")!;
    filter_enable(filter);
  });

  $("#removeFilters").click(function () {
    $("#filterList li").each(function () {
      const filter = $(this).attr("id")!;
      filter_disable(filter);
    });
    return false;
  });

  $("[data-slider=widths]").pwgDoubleSlider(sliders.widths);
  $("[data-slider=heights]").pwgDoubleSlider(sliders.heights);
  $("[data-slider=ratios]").pwgDoubleSlider(sliders.ratios);
  $("[data-slider=filesizes]").pwgDoubleSlider(sliders.filesizes);

  $(document).mouseup(function (e) {
    e.stopPropagation();
    if (!$(event!.target as unknown as Element).hasClass("addFilter-button")) {
      $(".addFilter-dropdown").slideUp();
    }
  });

  // Filter JS Validation
  $('.filterBlock select[data-selectize="tags"]').on("change", function () {
    if ($(this).val()) {
      hide_filters_error(str_select_tag);
    }
  });

  $("#applyFilter").on("click", function (e) {
    if ($("#filter_tags").is(":visible")) {
      const tags = $('.filterBlock select[data-selectize="tags"]');
      if (!tags.val()) {
        e.preventDefault();
        show_filters_error(str_select_tag);
        $("#filter_tags .removeFilter")
          .off("click.apply")
          .on("click.apply", function () {
            hide_filters_error(str_select_tag);
          });
      }
    }

    if ($("#filter_category").is(":visible")) {
      const albums = ab_filter.get_selected_albums();
      if (albums.length === 0) {
        e.preventDefault();
        show_filters_error(str_select_album);
        $("#filter_category .removeFilter")
          .off("click.apply")
          .on("click.apply", function () {
            hide_filters_error(str_select_album);
          });
      }
    }
  });

  $(".help-popin-search").on("click", function () {
    $("#modalQuickSearch").fadeIn();
  });

  $("#closeModalQuickSearch").on("click", function () {
    $("#modalQuickSearch").fadeOut();
  });
});
