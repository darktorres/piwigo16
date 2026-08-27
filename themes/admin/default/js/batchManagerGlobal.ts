// Genuinely bidirectional with batch_manager_global.ts (docs/PLAN.md
// P48 -- was window-global latching, see git history for the pre-P48
// shape). The cycle itself is fine: nothing imported from it is read
// while this module evaluates. `lang.Cancel` used to be, which made
// correctness depend on this file's circular import forcing
// batch_manager_global.ts to evaluate first -- a guarantee that held
// only because the bundle entry happened to import this file first.
// That read now happens inside a `.ready()` callback instead, so import
// order no longer matters and the previous "do not reorder the bundle
// entry's own import statements" warning no longer applies.
import {
  lang,
  all_elements,
  str_add_alb_associate,
  str_select_alb_associate,
} from "./batch_manager_global";
// Real consumer of album_selector.ts's own top-level `class
// AlbumSelector` (docs/PLAN.md P48 -- was a `window.AlbumSelector`
// read, see that file's own leading comment for the full real-consumer
// list, including the real, accepted "2 independent class copies on
// this page" consequence of batchManagerFilter.ts's own separate
// `?dup` import). `?dup` since album_selector.ts has several real
// registrant pages (Design §4).
import { AlbumSelector } from "./album_selector";

/* ********** Thumbs */

/* Shift-click: select all photos between the click and the shift+click */
jQuery(document).ready(function () {
  let last_clicked = 0,
    last_clickedstatus = true;
  jQuery.fn.enableShiftClick = function (this: JQuery) {
    const inputs: HTMLElement[] = [];
    let count = 0;
    this.find("input[type=checkbox]").each(function () {
      const pos = count;
      inputs[count++] = this;
      $(this).bind(
        "shclick",
        function (dummy: unknown, event: JQuery.TriggeredEvent) {
          if (event.shiftKey) {
            let first = last_clicked;
            let last = pos;
            if (first > last) {
              first = pos;
              last = last_clicked;
            }

            for (let i = first; i <= last; i++) {
              const input = $(inputs[i]!);
              $(input).prop("checked", last_clickedstatus).trigger("change");
              if (last_clickedstatus) {
                $(input).closest("li").addClass("thumbSelected");
              } else {
                $(input).closest("li").removeClass("thumbSelected");
              }
            }
          } else {
            last_clicked = pos;
            last_clickedstatus = (this as HTMLInputElement).checked;
          }
          return true;
        },
      );
      $(this).click(function (event) {
        $(this).triggerHandler("shclick", event);
      });
    });
    return this;
  };
  jQuery("ul.thumbnails").enableShiftClick();

  const ab_action = new AlbumSelector({
    adminMode: true,
    selectAlbum: select_album_action,
    removeSelectedAlbum: remove_album_action,
  });

  $("#associate_as").on("click", function () {
    ab_action.open();
  });

  $(".selected-associate-action").on("click", (e) => {
    if (e.target.classList.contains("remove-associate")) {
      ab_action.remove_selected_album($(e.target).attr("id")!);
    }
  });
});

/* ********** Album Selector */
function select_album_action({
  album,
  addSelectedAlbum,
}: AlbumSelectorCallbackArgs) {
  $("#associate_as p").html(str_add_alb_associate);
  $(".selected-associate-action").append(
    `<div class="selected-associate-item">
      <span>${album.name}</span><span id="${album.id}" class="remove-associate icon-cancel-circled"></span>
      <input type="hidden" id="associate_input_${album.id}" name="associate[]" value="${album.id}">
    </div>`,
  );
  addSelectedAlbum();
}

function remove_album_action({
  id_album,
  getSelectedAlbum,
}: AlbumSelectorRemoveCallbackArgs) {
  $(".selected-associate-item").find(`#${id_album}`).parent().remove();
  const selected = getSelectedAlbum();
  if (!selected.length) {
    $("#associate_as p").html(str_select_alb_associate);
  }
}

jQuery("a.preview-box").colorbox({ photo: true });

jQuery(".thumbnails img").tipTip({
  delay: 0,
  fadeIn: 200,
  fadeOut: 200,
});

/* ********** Actions*/

// `lang` is read inside the ready callback, not at module top level.
// Reading it during this module's own evaluation only worked because the
// circular import of batch_manager_global.ts forced that module to
// finish evaluating first -- an ordering guarantee that holds for a
// single self-contained bundle but not once Rollup is free to hoist a
// shared module into a chunk. `lang` is a `const`, so an evaluation
// order that reaches this line first is a TDZ ReferenceError at page
// load, not a silent undefined. Deferring the read removes the
// dependency on import order entirely; this mirrors the `.ready()`
// wrapper directly below, added for the same class of ordering problem.
jQuery(document).ready(function () {
  jQuery("[data-datepicker]").pwgDatepicker({
    showTimepicker: true,
    cancelButton: lang.Cancel,
  });
});

// Real regression found live (docs/PLAN.md P48, this pair's own merge
// into one bundle): `pwgAddAlbum()` needs `[data-add-album]`'s own
// target `<select>` to already have its selectize widget initialized
// (`addAlbum.ts`'s own `jQuery.error('pwgAddAlbum: target must use
// selectize')` guard), which happens inside batch_manager_global.ts's
// own `jQuery(document).ready(...)` callback. jQuery 3.x's `.ready()`
// resolves via a real Deferred, not truly synchronously even when the
// document is already ready (Footer scripts run after the DOM is
// already parsed) -- confirmed live via Playwright: calling this bare,
// synchronously, ran it *before* that deferred callback's own body,
// throwing every time. Before this batch, batchManagerGlobal.ts
// (Async) and batch_manager_global.ts (Footer) were 2 separate script
// tags with enough real wall-clock time between them for this to never
// surface; merging them into one bundle removed that gap. Wrapping
// this in its own `.ready()` call re-establishes correct relative
// order: batch_manager_global.ts's own `.ready()` callback (the
// selectize setup) is registered first, since its module fully
// evaluates before this file's own subsequent code runs -- jQuery's
// ready queue runs callbacks in registration order.
//
// NOTE this is still a real import-order dependency, and a different
// one from the `lang.Cancel` TDZ fixed above: it needs the two modules
// to *register* their ready callbacks in this order, not merely to have
// finished evaluating. Reordering them does not throw a ReferenceError,
// it makes pwgAddAlbum() run before the selectize setup and hit
// addAlbum.ts's own `pwgAddAlbum: target must use selectize` guard.
// Confirmed live once already. Anything that lets the bundler decide
// module evaluation order (shared chunks) has to re-verify this page.
jQuery(document).ready(function () {
  jQuery("[data-add-album]").pwgAddAlbum();
});

$("input[name=remove_author]").click(function () {
  if ($(this).is(":checked")) {
    $("input[name=author]").hide();
  } else {
    $("input[name=author]").show();
  }
});

$("input[name=remove_title]").click(function () {
  if ($(this).is(":checked")) {
    $("input[name=title]").hide();
  } else {
    $("input[name=title]").show();
  }
});

$("input[name=remove_date_creation]").click(function () {
  if ($(this).is(":checked")) {
    $("#set_date_creation").hide();
  } else {
    $("#set_date_creation").show();
  }
});

interface Derivatives {
  elements: (string | number)[] | null;
  done: number;
  total: number;
  finished(): boolean;
}

export const derivatives: Derivatives = {
  elements: null,
  done: 0,
  total: 0,

  finished: function () {
    return (
      derivatives.done == derivatives.total &&
      !!derivatives.elements &&
      derivatives.elements.length == 0
    );
  },
};

export function progress_start() {
  jQuery("#uploadingActions").show();
  jQuery("#uploadingActions .progress-bar").width("0%");
}

function progress_end() {
  jQuery("#uploadingActions").hide();
}

export function progress(success?: boolean) {
  const percent = parseInt(
    String((derivatives.done / derivatives.total) * 100),
  );
  jQuery("#uploadingActions .progressbar").width(percent.toString() + "%");
  if (success !== undefined) {
    const type = success ? "regenerateSuccess" : "regenerateError";
    let s = Number(jQuery('[name="' + type + '"]').val());
    // eslint-disable-next-line no-useless-assignment -- `s` genuinely exists only to be pre-incremented once here; inlining it would just make this harder to read for no behavior change.
    jQuery('[name="' + type + '"]').val(++s);
  }

  if (derivatives.finished()) {
    progress_end();
    jQuery("#applyAction").click();
  }
}

export function getDerivativeUrls() {
  const ids = derivatives.elements!.splice(0, 500).map(Number);
  const params: { maxUrls: number; ids: number[]; types: string[] } = {
    maxUrls: 100000,
    ids: ids,
    types: [],
  };
  jQuery("#action_generate_derivatives input").each(function (i, t) {
    if ($(t).is(":checked")) params.types.push((t as HTMLInputElement).value);
  });
  jQuery("#applyActionBlock").hide();
  jQuery(".permitActionListButton").hide();
  jQuery("#confirmDel").hide();
  jQuery("#regenerationMsg").show();
  jQuery("#regenerationText").html(lang.generateMsg);
  progress_start();
  jQuery.ajax({
    type: "POST",
    url: "api/v1/images/actions/missing-derivatives",
    contentType: "application/json",
    headers: {
      "X-CSRF-Token": jQuery("input[name=pwg_token]").val() as string,
    },
    data: JSON.stringify(params),
    dataType: "json",
    success: function (
      data: import("../../../../openapi/client/schema").operations["imageMissingDerivatives"]["responses"][200]["content"]["application/json"],
    ) {
      derivatives.total += data.urls.length;
      jQuery("#regenerationStatus .badge-number").html(
        derivatives.done.toString() + "/" + derivatives.total.toString(),
      );
      progress();
      for (let i = 0; i < data.urls.length; i++) {
        jQuery.manageAjax.add("queued", {
          type: "GET",
          url: data.urls[i] + "&ajaxload=true",
          dataType: "json",
          success: function (_data: unknown) {
            derivatives.done++;
            jQuery("#regenerationStatus .badge-number").html(
              derivatives.done.toString() + "/" + derivatives.total.toString(),
            );
            progress(true);
          },
          error: function (_data: unknown) {
            derivatives.done++;
            jQuery("#regenerationStatus .badge-number").html(
              derivatives.done.toString() + "/" + derivatives.total.toString(),
            );
            progress(false);
          },
        });
      }
      if (derivatives.elements!.length)
        setTimeout(
          getDerivativeUrls,
          25 * (derivatives.total - derivatives.done),
        );
    },
  });
}

function selectGenerateDerivAll() {
  $("#action_generate_derivatives input[type=checkbox]")
    .prop("checked", true)
    .trigger("change");
}
function selectGenerateDerivNone() {
  $("#action_generate_derivatives input[type=checkbox]")
    .prop("checked", false)
    .trigger("change");
}

function selectDelDerivAll() {
  $('#action_delete_derivatives input[name="del_derivatives_type[]"]')
    .prop("checked", true)
    .trigger("change");
}
function selectDelDerivNone() {
  $('#action_delete_derivatives input[name="del_derivatives_type[]"]')
    .prop("checked", false)
    .trigger("change");
}

// Explicit `window.` exposure -- required for a different reason than
// this file's other `window.X = X` lines: not read by another *script*
// at all, but called from `batch_manager_global.latte`'s own
// `href="javascript:selectGenerateDerivAll()"`-style pseudo-protocol
// links, which look these up as real `window` properties when clicked
// -- wrapping this whole file in its own IIFE (vite.config.ts's
// banner/footer) would otherwise make them invisible to that lookup.
window.selectGenerateDerivAll = selectGenerateDerivAll;
window.selectGenerateDerivNone = selectGenerateDerivNone;
window.selectDelDerivAll = selectDelDerivAll;
window.selectDelDerivNone = selectDelDerivNone;

// Trigger action click on pressing enter and if the value of applyAction is not equal to -1
$(window).on("keypress", function (e) {
  const selected = $("select[name='selectAction']").val();
  const haveTextarea = $(`#action_${String(selected)} textarea`).length;
  const haveAlbumSelector = $("#addLinkedAlbum").is(":visible");

  if (
    e.key === "Enter" &&
    selected != -1 &&
    !haveTextarea &&
    !haveAlbumSelector
  ) {
    e.preventDefault();
    $("#applyAction").trigger("click");
  }
});

// Real pre-existing behavior, not a bug to fix: the original .js never
// declares `elements` with `var` anywhere (confirmed), making it an
// accidental *implicit global* -- which, unlike a normal function-local
// var, PERSISTS across repeated clicks. The handler below's own
// `typeof(elements) != "undefined"` guard relies on exactly that
// persistence as a real (if accidental) "only run once" check: once
// the first click sets `elements`, every later click short-circuits.
// Declaring it here (file-top-level, inside this file's own IIFE
// wrapper) reproduces that same cross-click persistence via closure,
// without actually leaking it onto `window` -- nothing else in the
// codebase relies on a shared global named `elements` (confirmed).
let elements: (string | number)[] | undefined;

/* sync metadatas or delete photos by blocks, with progress bar */
jQuery("#applyAction").click(function (e) {
  if (typeof elements != "undefined") {
    return true;
  }

  let progressBar_max: number;

  if (jQuery('[name="selectAction"]').val() == "metadata") {
    e.preventDefault();
    e.stopPropagation();
    jQuery(".bulkAction").hide();
    jQuery("#regenerationText").html(lang.syncProgressMessage);
    elements = [];

    if (jQuery("input[name=setSelected]").is(":checked")) {
      elements = all_elements;
    } else {
      jQuery('input[name="selection[]"]')
        .filter(":checked")
        .each(function () {
          // Checkbox `.val()` is always its plain `value` attribute string
          // (never string[]/undefined) -- never a multi-select.
          elements!.push(jQuery(this).val() as string);
        });
    }

    const queuedManager = jQuery.manageAjax.create("queued", {
      queue: true,
      cacheResponse: false,
      maxRequests: 1,
    });

    // Local alias for the definitely-assigned value, kept for
    // readability. The non-null assertion this used to need is gone:
    // it was only necessary while `all_elements` arrived typed as `any`
    // through a `?dup` import, which defeated control-flow narrowing of
    // the outer `let elements`. With the real `(string | number)[]`
    // type restored, both assignment branches narrow it properly.
    const syncElements = elements;

    progressBar_max = syncElements.length;
    let todo = 0;
    const syncBlockSize = Math.min(
      Number((syncElements.length / 2).toFixed()),
      1000,
    );
    let image_ids = [];

    jQuery("#applyActionBlock").hide();
    jQuery(".permitActionListButton").hide();
    jQuery("#confirmDel").hide();
    jQuery("#regenerationMsg").show();
    progress_bar_start();
    for (let i = 0; i < syncElements.length; i++) {
      image_ids.push(syncElements[i]);
      if (
        i % syncBlockSize != syncBlockSize - 1 &&
        i != syncElements.length - 1
      ) {
        continue;
      }

      (function (ids) {
        const thisBatchSize = ids.length;
        queuedManager.add({
          url: "api/v1/images/actions/sync-metadata",
          type: "POST",
          contentType: "application/json",
          headers: {
            "X-CSRF-Token": jQuery("input[name=pwg_token]").val(),
          },
          data: JSON.stringify({
            imageIds: ids,
          }),
          dataType: "json",
          success: function (
            data: import("../../../../openapi/client/schema").operations["imageSyncMetadata"]["responses"][200]["content"]["application/json"],
          ) {
            todo += thisBatchSize;
            if (data.nbSynchronized != thisBatchSize) {
              /*TODO: user feedback only data.nbSynchronized images out of thisBatchSize were sync*/
            }
            jQuery("#regenerationStatus .badge-number").html(
              todo.toString() + "/" + progressBar_max.toString(),
            );
            progress_bar(todo, progressBar_max, false);
          },
          error: function (_data: unknown) {
            todo += thisBatchSize;
            /*TODO: user feedback*/
            jQuery("#regenerationStatus .badge-number").html(
              todo.toString() + "/" + progressBar_max.toString(),
            );
            progress_bar(todo, progressBar_max, false);
          },
        });
      })(image_ids);
      image_ids = [];
    }
  }

  if (jQuery('[name="selectAction"]').val() == "delete") {
    if (!jQuery("#confirmDel input[name=confirm_deletion]").is(":checked")) {
      jQuery("#confirmDel span.errors").css("visibility", "visible");
      return false;
    }
    e.stopPropagation();
  } else {
    return true;
  }

  jQuery(".bulkAction").hide();
  const maxRequests = 1;

  const queuedManager = jQuery.manageAjax.create("queued", {
    queue: true,
    cacheResponse: false,
    maxRequests: maxRequests,
  });

  elements = [];

  if (jQuery("input[name=setSelected]").is(":checked")) {
    elements = all_elements;
  } else {
    jQuery('input[name="selection[]"]')
      .filter(":checked")
      .each(function () {
        // Checkbox `.val()` is always its plain `value` attribute string
        // (never string[]/undefined) -- never a multi-select.
        elements!.push(jQuery(this).val() as string);
      });
  }

  // Local alias, same as syncElements above -- and likewise no longer
  // needs a non-null assertion now that `all_elements` has a real type.
  const deleteElements = elements;

  progressBar_max = deleteElements.length;
  let todo = 0;
  const deleteBlockSize = Math.min(
    Number((deleteElements.length / 2).toFixed()),
    1000,
  );
  let image_ids = [];

  jQuery("#applyActionBlock").hide();
  jQuery(".permitActionListButton").hide();
  jQuery("#confirmDel").hide();
  jQuery("#regenerationText").html(lang.deleteProgressMessage);
  jQuery("#regenerationMsg").show();
  progress_bar_start();
  for (let i = 0; i < deleteElements.length; i++) {
    image_ids.push(deleteElements[i]);
    if (
      i % deleteBlockSize != deleteBlockSize - 1 &&
      i != deleteElements.length - 1
    ) {
      continue;
    }

    (function (ids) {
      const thisBatchSize = ids.length;
      queuedManager.add({
        type: "POST",
        url: "api/v1/images/actions/delete",
        contentType: "application/json",
        headers: { "X-CSRF-Token": jQuery("input[name=pwg_token]").val() },
        data: JSON.stringify({
          imageIds: ids.map(Number),
        }),
        dataType: "json",
        success: function (
          data: import("../../../../openapi/client/schema").operations["imageDelete"]["responses"][200]["content"]["application/json"],
        ) {
          todo += thisBatchSize;
          if (data.deletedCount != thisBatchSize) {
            /*TODO: user feedback only data.deletedCount images out of thisBatchSize were deleted*/
          }
          /*TODO: user feedback if isError*/
          jQuery("#regenerationStatus .badge-number").html(
            todo.toString() + "/" + progressBar_max.toString(),
          );
          progress_bar(todo, progressBar_max, false);
        },
        error: function (_data: unknown) {
          todo += thisBatchSize;
          /*TODO: user feedback*/
          jQuery("#regenerationStatus .badge-number").html(
            todo.toString() + "/" + progressBar_max.toString(),
          );
          progress_bar(todo, progressBar_max, false);
        },
      });
    })(image_ids);

    image_ids = [];
  }

  /* tell PHP how many photos were deleted */
  jQuery("form").append(
    '<input type="hidden" name="nb_photos_deleted" value="' +
      deleteElements.length +
      '">',
  );

  return false;
});

function progress_bar_start() {
  jQuery("#uploadingActions").show();
  jQuery("#uploadingActions .progress-bar").width("0%");
}

// Genuinely dead code, confirmed via a repo-wide grep (zero references
// anywhere, not even from a template) -- kept, not deleted, prefixed
// per this codebase's own `^_`-means-intentionally-unused convention:
// `progress_end()` (a few lines up) does the exact same thing and is
// the one actually called.
function _progress_bar_end() {
  jQuery("#uploadingActions").hide();
}

function progress_bar(val: number, max: number, _success: boolean) {
  const percent = parseInt(String((val / max) * 100));
  jQuery("#uploadingActions .progressbar").width(percent.toString() + "%");
  if (val == max) jQuery("#applyAction").click();
}

jQuery("#confirmDel input[name=confirm_deletion]").change(function () {
  jQuery("#confirmDel span.errors").css("visibility", "hidden");
});

jQuery("#sync_md5sum").click(function (_e) {
  jQuery(this).hide();
  jQuery("#add_md5sum").show();

  const addBlockSize = Math.min(
    Number((jQuery("#md5sum_to_add").data("origin") / 2).toFixed()),
    1000,
  );
  add_md5sum_block(addBlockSize);

  return false;
});

function add_md5sum_block(blockSize?: number) {
  jQuery.ajax({
    url: "api/v1/images/actions/set-md5sum",
    type: "POST",
    contentType: "application/json",
    headers: {
      "X-CSRF-Token": jQuery("input[name=pwg_token]").val() as string,
    },
    dataType: "json",
    data: JSON.stringify({
      blockSize: blockSize,
    }),
    success: function (
      data: import("../../../../openapi/client/schema").operations["imageSetMd5sum"]["responses"][200]["content"]["application/json"],
    ) {
      jQuery("#md5sum_to_add").html(String(data.remainingCount));

      const percent_remaining = Number(
        (
          (data.remainingCount * 100) /
          jQuery("#md5sum_to_add").data("origin")
        ).toFixed(),
      );
      const percent_done = 100 - percent_remaining;
      jQuery("#md5sum_added").html(String(percent_done));
      if (data.remainingCount > 0) {
        add_md5sum_block();
      } else {
        // time to refresh the whole page
        let redirect_to = "admin.php?page=batch_manager";
        redirect_to += "&action=sync_md5sum";
        redirect_to +=
          "&nb_md5sum_added=" + jQuery("#md5sum_to_add").data("origin");

        window.location.href = redirect_to;
      }
    },
    error: function (XMLHttpRequest: JQuery.jqXHR) {
      jQuery("#add_md5sum").hide();
      jQuery("#add_md5sum_error")
        .show()
        .html(
          "error " + XMLHttpRequest.status + " : " + XMLHttpRequest.statusText,
        );
    },
  });
}

jQuery("#delete_orphans").click(function (_e) {
  jQuery(this).hide();
  jQuery("#orphans_deletion").show();

  const deleteBlockSize = Math.min(
    Number((jQuery("#orphans_to_delete").data("origin") / 2).toFixed()),
    1000,
  );

  delete_orphans_block(deleteBlockSize);

  return false;
});

function delete_orphans_block(blockSize?: number) {
  jQuery.ajax({
    url: "api/v1/images/actions/delete-orphans",
    type: "POST",
    contentType: "application/json",
    headers: {
      "X-CSRF-Token": jQuery("input[name=pwg_token]").val() as string,
    },
    data: JSON.stringify({
      blockSize: blockSize,
    }),
    dataType: "json",
    success: function (
      data: import("../../../../openapi/client/schema").operations["imageDeleteOrphans"]["responses"][200]["content"]["application/json"],
    ) {
      jQuery("#orphans_to_delete").html(String(data.nbOrphans));

      const percent_remaining = Number(
        (
          (data.nbOrphans * 100) /
          jQuery("#orphans_to_delete").data("origin")
        ).toFixed(),
      );
      const percent_done = 100 - percent_remaining;
      jQuery("#orphans_deleted").html(String(percent_done));

      if (data.nbOrphans > 0) {
        delete_orphans_block();
      } else {
        // time to refresh the whole page
        let redirect_to = "admin.php?page=batch_manager";
        redirect_to += "&action=delete_orphans";
        redirect_to +=
          "&nb_orphans_deleted=" + jQuery("#orphans_to_delete").data("origin");

        window.location.href = redirect_to;
      }
    },
    error: function (XMLHttpRequest: JQuery.jqXHR) {
      jQuery("#orphans_deletion").hide();
      jQuery("#orphans_deletion_error")
        .show()
        .html(
          "error " + XMLHttpRequest.status + " : " + XMLHttpRequest.statusText,
        );
    },
  });
}
