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
// this page" consequence of batchManagerFilter.ts's own separate direct
// import).
import { AlbumSelector } from "./album_selector";
import { pwgAddAlbum } from "./addAlbum";
import { ajax } from "../../../default/js/vendor/ajax";
import { AjaxQueue } from "../../../default/js/vendor/ajaxQueue";
import { colorbox } from "../../../default/js/vendor/colorbox";
import {
  append,
  css,
  data,
  escapeId,
  find,
  hide,
  html,
  is,
  isVisible,
  on,
  ready,
  setChecked,
  setVal,
  show,
  trigger,
  val,
} from "../../../default/js/vendor/dom";
import { tipTip } from "../../../default/js/vendor/tiptip";

/* ********** Thumbs */

/* Shift-click: select all photos between the click and the shift+click */
ready(function () {
  let last_clicked = 0;
  let last_clickedstatus = true;

  function enableShiftClick(container: Element): void {
    const inputs: HTMLInputElement[] = [];
    let count = 0;
    find(container, "input[type=checkbox]").forEach((el) => {
      const checkbox = el as HTMLInputElement;
      const pos = count;
      inputs[count++] = checkbox;
      // "shclick" is a first-party custom event, not a library's --
      // both this bind and the trigger below convert together, and so
      // does batch_manager_global.ts's own remote trigger of it (this
      // file's whole reason for converting last). The real event
      // (carrying `shiftKey`) travels as the CustomEvent's `detail`,
      // since dom.ts's `trigger()` has no jQuery-style extra-parameter
      // slot.
      on(checkbox, "shclick", function (shclickEvent: Event) {
        const event = (shclickEvent as CustomEvent<MouseEvent | KeyboardEvent>)
          .detail;
        if (event.shiftKey) {
          let first = last_clicked;
          let last = pos;
          if (first > last) {
            first = pos;
            last = last_clicked;
          }

          for (let i = first; i <= last; i++) {
            const input = inputs[i]!;
            input.checked = last_clickedstatus;
            trigger([input], "change");
            const li = input.closest("li");
            if (last_clickedstatus) {
              li?.classList.add("thumbSelected");
            } else {
              li?.classList.remove("thumbSelected");
            }
          }
        } else {
          last_clicked = pos;
          last_clickedstatus = checkbox.checked;
        }
      });
      on(checkbox, "click", function (event: Event) {
        trigger([checkbox], "shclick", event);
      });
    });
  }
  const thumbnailsContainer = document.querySelector("ul.thumbnails");
  if (thumbnailsContainer !== null) {
    enableShiftClick(thumbnailsContainer);
  }

  const ab_action = new AlbumSelector({
    adminMode: true,
    selectAlbum: select_album_action,
    removeSelectedAlbum: remove_album_action,
  });

  on(document.querySelectorAll("#associate_as"), "click", function () {
    ab_action.open();
  });

  on(
    document.querySelectorAll(".selected-associate-action"),
    "click",
    (e: Event) => {
      const target = e.target as Element;
      if (target.classList.contains("remove-associate")) {
        ab_action.remove_selected_album(target.id);
      }
    },
  );
});

/* ********** Album Selector */
function select_album_action({
  album,
  addSelectedAlbum,
}: AlbumSelectorCallbackArgs) {
  html(document.querySelectorAll("#associate_as p"), str_add_alb_associate);
  append(
    document.querySelectorAll(".selected-associate-action"),
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
  // `id_album` is the raw album id `select_album_action()` wrote above
  // as this chip's own bare `id` attribute -- digit-leading, so it
  // needs escapeId() under native querySelector (same bug class as
  // mcs.ts's remove_related_category()).
  find(
    document.querySelectorAll(".selected-associate-item"),
    "#" + escapeId(id_album),
  )[0]?.parentElement?.remove();
  const selected = getSelectedAlbum();
  if (!selected.length) {
    html(
      document.querySelectorAll("#associate_as p"),
      str_select_alb_associate,
    );
  }
}

colorbox(document.querySelectorAll("a.preview-box"), { photo: true });

tipTip(document.querySelectorAll(".thumbnails img"), {
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
ready(function () {
  // Still jQuery: jQuery-UI's datepicker + timepicker-addon, ported in
  // P49-B group 5 (pwgDatepicker).
  jQuery("[data-datepicker]").pwgDatepicker({
    showTimepicker: true,
    cancelButton: lang.Cancel,
  });
});

// Real regression found live (docs/PLAN.md P48, this pair's own merge
// into one bundle): `pwgAddAlbum()` needs `[data-add-album]`'s own
// target `<select>` to already have its selectize widget initialized
// (`addAlbum.ts`'s own `throw new Error('pwgAddAlbum: target must use
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
ready(function () {
  const trigger = document.querySelector("[data-add-album]");
  if (trigger !== null) {
    pwgAddAlbum(trigger);
  }
});

on(
  document.querySelectorAll("input[name=remove_author]"),
  "click",
  function (this: Element) {
    if (is(this, ":checked")) {
      hide(document.querySelectorAll("input[name=author]"));
    } else {
      show(document.querySelectorAll("input[name=author]"));
    }
  },
);

on(
  document.querySelectorAll("input[name=remove_title]"),
  "click",
  function (this: Element) {
    if (is(this, ":checked")) {
      hide(document.querySelectorAll("input[name=title]"));
    } else {
      show(document.querySelectorAll("input[name=title]"));
    }
  },
);

on(
  document.querySelectorAll("input[name=remove_date_creation]"),
  "click",
  function (this: Element) {
    if (is(this, ":checked")) {
      hide(document.querySelectorAll("#set_date_creation"));
    } else {
      show(document.querySelectorAll("#set_date_creation"));
    }
  },
);

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
  show(document.querySelectorAll("#uploadingActions"));
  css(
    document.querySelectorAll("#uploadingActions .progress-bar"),
    "width",
    "0%",
  );
}

function progress_end() {
  hide(document.querySelectorAll("#uploadingActions"));
}

export function progress(success?: boolean) {
  const percent = parseInt(
    String((derivatives.done / derivatives.total) * 100),
  );
  css(
    document.querySelectorAll("#uploadingActions .progressbar"),
    "width",
    percent.toString() + "%",
  );
  if (success !== undefined) {
    const type = success ? "regenerateSuccess" : "regenerateError";
    const typeInputs = document.querySelectorAll('[name="' + type + '"]');
    let s = Number(val(typeInputs));
    // eslint-disable-next-line no-useless-assignment -- `s` genuinely exists only to be pre-incremented once here; inlining it would just make this harder to read for no behavior change.
    setVal(typeInputs, String(++s));
  }

  if (derivatives.finished()) {
    progress_end();
    document.querySelector<HTMLElement>("#applyAction")?.click();
  }
}

export function getDerivativeUrls(queue: AjaxQueue) {
  const ids = derivatives.elements!.splice(0, 500).map(Number);
  const params: { maxUrls: number; ids: number[]; types: string[] } = {
    maxUrls: 100000,
    ids: ids,
    types: [],
  };
  document
    .querySelectorAll("#action_generate_derivatives input")
    .forEach((t) => {
      if (is(t, ":checked")) params.types.push((t as HTMLInputElement).value);
    });
  hide(document.querySelectorAll("#applyActionBlock"));
  hide(document.querySelectorAll(".permitActionListButton"));
  hide(document.querySelectorAll("#confirmDel"));
  show(document.querySelectorAll("#regenerationMsg"));
  html(document.querySelectorAll("#regenerationText"), lang.generateMsg);
  progress_start();
  void ajax({
    type: "POST",
    url: "api/v1/images/actions/missing-derivatives",
    contentType: "application/json",
    headers: {
      "X-CSRF-Token": val(document.querySelectorAll("input[name=pwg_token]"))!,
    },
    data: JSON.stringify(params),
    dataType: "json",
    success: function (
      responseData: import("../../../../openapi/client/schema").operations["imageMissingDerivatives"]["responses"][200]["content"]["application/json"],
    ) {
      derivatives.total += responseData.urls.length;
      html(
        document.querySelectorAll("#regenerationStatus .badge-number"),
        derivatives.done.toString() + "/" + derivatives.total.toString(),
      );
      progress();
      for (let i = 0; i < responseData.urls.length; i++) {
        queue.add({
          type: "GET",
          url: responseData.urls[i] + "&ajaxload=true",
          dataType: "json",
          success: function (_data: unknown) {
            derivatives.done++;
            html(
              document.querySelectorAll("#regenerationStatus .badge-number"),
              derivatives.done.toString() + "/" + derivatives.total.toString(),
            );
            progress(true);
          },
          error: function (_data: unknown) {
            derivatives.done++;
            html(
              document.querySelectorAll("#regenerationStatus .badge-number"),
              derivatives.done.toString() + "/" + derivatives.total.toString(),
            );
            progress(false);
          },
        });
      }
      if (derivatives.elements!.length)
        setTimeout(
          () => {
            getDerivativeUrls(queue);
          },
          25 * (derivatives.total - derivatives.done),
        );
    },
  });
}

function selectGenerateDerivAll() {
  const els = document.querySelectorAll(
    "#action_generate_derivatives input[type=checkbox]",
  );
  setChecked(els, true);
  trigger(els, "change");
}
function selectGenerateDerivNone() {
  const els = document.querySelectorAll(
    "#action_generate_derivatives input[type=checkbox]",
  );
  setChecked(els, false);
  trigger(els, "change");
}

function selectDelDerivAll() {
  const els = document.querySelectorAll(
    '#action_delete_derivatives input[name="del_derivatives_type[]"]',
  );
  setChecked(els, true);
  trigger(els, "change");
}
function selectDelDerivNone() {
  const els = document.querySelectorAll(
    '#action_delete_derivatives input[name="del_derivatives_type[]"]',
  );
  setChecked(els, false);
  trigger(els, "change");
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
on(window, "keypress", function (e: Event) {
  const selected = val(
    document.querySelectorAll("select[name='selectAction']"),
  );
  const haveTextarea =
    document.querySelectorAll(`#action_${String(selected)} textarea`).length >
    0;
  const addLinkedAlbum = document.querySelector("#addLinkedAlbum");
  const haveAlbumSelector =
    addLinkedAlbum !== null && isVisible(addLinkedAlbum);

  const keyEvent = e as KeyboardEvent;
  if (
    keyEvent.key === "Enter" &&
    (selected as unknown) != -1 &&
    !haveTextarea &&
    !haveAlbumSelector
  ) {
    keyEvent.preventDefault();
    trigger(document.querySelectorAll("#applyAction"), "click");
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
// `#applyAction` is a real `<button type="submit">`, and this handler
// leans on jQuery's own return-value sugar (`return false` ==
// preventDefault()+stopPropagation(), `return true`/falling off the end
// == let the native submit through) to decide whether the click
// actually submits the form. Translated explicitly below rather than
// kept as jQuery -- "click" isn't a library-only event, so this isn't
// one of the genuine widget exceptions.
on(document.querySelectorAll("#applyAction"), "click", function (e: Event) {
  if (typeof elements != "undefined") {
    return;
  }

  let progressBar_max: number;

  if (val(document.querySelectorAll('[name="selectAction"]')) == "metadata") {
    e.preventDefault();
    e.stopPropagation();
    hide(document.querySelectorAll(".bulkAction"));
    html(
      document.querySelectorAll("#regenerationText"),
      lang.syncProgressMessage,
    );
    elements = [];

    if (is(document.querySelector("input[name=setSelected]")!, ":checked")) {
      elements = all_elements;
    } else {
      document
        .querySelectorAll('input[name="selection[]"]:checked')
        .forEach((el) => {
          // Checkbox `.val()` is always its plain `value` attribute string
          // (never string[]/undefined) -- never a multi-select.
          elements!.push((el as HTMLInputElement).value);
        });
    }

    const queuedManager = new AjaxQueue({ maxRequests: 1 });

    // Local alias for the definitely-assigned value, kept for
    // readability. The non-null assertion this used to need is gone:
    // it was only necessary while `all_elements` arrived typed as `any`
    // through a direct import, which defeated control-flow narrowing of
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

    hide(document.querySelectorAll("#applyActionBlock"));
    hide(document.querySelectorAll(".permitActionListButton"));
    hide(document.querySelectorAll("#confirmDel"));
    show(document.querySelectorAll("#regenerationMsg"));
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
            "X-CSRF-Token": val(
              document.querySelectorAll("input[name=pwg_token]"),
            )!,
          },
          data: JSON.stringify({
            imageIds: ids,
          }),
          dataType: "json",
          success: function (
            responseData: import("../../../../openapi/client/schema").operations["imageSyncMetadata"]["responses"][200]["content"]["application/json"],
          ) {
            todo += thisBatchSize;
            if (responseData.nbSynchronized != thisBatchSize) {
              /*TODO: user feedback only data.nbSynchronized images out of thisBatchSize were sync*/
            }
            html(
              document.querySelectorAll("#regenerationStatus .badge-number"),
              todo.toString() + "/" + progressBar_max.toString(),
            );
            progress_bar(todo, progressBar_max, false);
          },
          error: function (_data: unknown) {
            todo += thisBatchSize;
            /*TODO: user feedback*/
            html(
              document.querySelectorAll("#regenerationStatus .badge-number"),
              todo.toString() + "/" + progressBar_max.toString(),
            );
            progress_bar(todo, progressBar_max, false);
          },
        });
      })(image_ids);
      image_ids = [];
    }
  }

  if (val(document.querySelectorAll('[name="selectAction"]')) == "delete") {
    if (
      !is(
        document.querySelector("#confirmDel input[name=confirm_deletion]")!,
        ":checked",
      )
    ) {
      css(
        document.querySelectorAll("#confirmDel span.errors"),
        "visibility",
        "visible",
      );
      e.preventDefault();
      e.stopPropagation();
      return;
    }
    e.stopPropagation();
  } else {
    return;
  }

  hide(document.querySelectorAll(".bulkAction"));
  const maxRequests = 1;

  const queuedManager = new AjaxQueue({ maxRequests: maxRequests });

  elements = [];

  if (is(document.querySelector("input[name=setSelected]")!, ":checked")) {
    elements = all_elements;
  } else {
    document
      .querySelectorAll('input[name="selection[]"]:checked')
      .forEach((el) => {
        // Checkbox `.val()` is always its plain `value` attribute string
        // (never string[]/undefined) -- never a multi-select.
        elements!.push((el as HTMLInputElement).value);
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

  hide(document.querySelectorAll("#applyActionBlock"));
  hide(document.querySelectorAll(".permitActionListButton"));
  hide(document.querySelectorAll("#confirmDel"));
  html(
    document.querySelectorAll("#regenerationText"),
    lang.deleteProgressMessage,
  );
  show(document.querySelectorAll("#regenerationMsg"));
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
        headers: {
          "X-CSRF-Token": val(
            document.querySelectorAll("input[name=pwg_token]"),
          )!,
        },
        data: JSON.stringify({
          imageIds: ids.map(Number),
        }),
        dataType: "json",
        success: function (
          responseData: import("../../../../openapi/client/schema").operations["imageDelete"]["responses"][200]["content"]["application/json"],
        ) {
          todo += thisBatchSize;
          if (responseData.deletedCount != thisBatchSize) {
            /*TODO: user feedback only data.deletedCount images out of thisBatchSize were deleted*/
          }
          /*TODO: user feedback if isError*/
          html(
            document.querySelectorAll("#regenerationStatus .badge-number"),
            todo.toString() + "/" + progressBar_max.toString(),
          );
          progress_bar(todo, progressBar_max, false);
        },
        error: function (_data: unknown) {
          todo += thisBatchSize;
          /*TODO: user feedback*/
          html(
            document.querySelectorAll("#regenerationStatus .badge-number"),
            todo.toString() + "/" + progressBar_max.toString(),
          );
          progress_bar(todo, progressBar_max, false);
        },
      });
    })(image_ids);

    image_ids = [];
  }

  /* tell PHP how many photos were deleted */
  append(
    document.querySelectorAll("form"),
    '<input type="hidden" name="nb_photos_deleted" value="' +
      deleteElements.length +
      '">',
  );

  e.preventDefault();
  e.stopPropagation();
});

function progress_bar_start() {
  show(document.querySelectorAll("#uploadingActions"));
  css(
    document.querySelectorAll("#uploadingActions .progress-bar"),
    "width",
    "0%",
  );
}

// Genuinely dead code, confirmed via a repo-wide grep (zero references
// anywhere, not even from a template) -- kept, not deleted, prefixed
// per this codebase's own `^_`-means-intentionally-unused convention:
// `progress_end()` (a few lines up) does the exact same thing and is
// the one actually called.
function _progress_bar_end() {
  hide(document.querySelectorAll("#uploadingActions"));
}

function progress_bar(val: number, max: number, _success: boolean) {
  const percent = parseInt(String((val / max) * 100));
  css(
    document.querySelectorAll("#uploadingActions .progressbar"),
    "width",
    percent.toString() + "%",
  );
  if (val == max) document.querySelector<HTMLElement>("#applyAction")?.click();
}

on(
  document.querySelectorAll("#confirmDel input[name=confirm_deletion]"),
  "change",
  function () {
    css(
      document.querySelectorAll("#confirmDel span.errors"),
      "visibility",
      "hidden",
    );
  },
);

on(
  document.querySelectorAll("#sync_md5sum"),
  "click",
  function (this: Element, e: Event) {
    hide([this]);
    show(document.querySelectorAll("#add_md5sum"));

    const addBlockSize = Math.min(
      Number(
        (
          Number(data(document.querySelector("#md5sum_to_add")!, "origin")) / 2
        ).toFixed(),
      ),
      1000,
    );
    add_md5sum_block(addBlockSize);

    e.preventDefault();
    e.stopPropagation();
  },
);

function add_md5sum_block(blockSize?: number) {
  void ajax({
    url: "api/v1/images/actions/set-md5sum",
    type: "POST",
    contentType: "application/json",
    headers: {
      "X-CSRF-Token": val(document.querySelectorAll("input[name=pwg_token]"))!,
    },
    dataType: "json",
    data: JSON.stringify({
      blockSize: blockSize,
    }),
    success: function (
      responseData: import("../../../../openapi/client/schema").operations["imageSetMd5sum"]["responses"][200]["content"]["application/json"],
    ) {
      html(
        document.querySelectorAll("#md5sum_to_add"),
        String(responseData.remainingCount),
      );

      const origin = document.querySelector("#md5sum_to_add")!;
      const percent_remaining = Number(
        (
          (responseData.remainingCount * 100) /
          Number(data(origin, "origin"))
        ).toFixed(),
      );
      const percent_done = 100 - percent_remaining;
      html(document.querySelectorAll("#md5sum_added"), String(percent_done));
      if (responseData.remainingCount > 0) {
        add_md5sum_block();
      } else {
        // time to refresh the whole page
        let redirect_to = "admin.php?page=batch_manager";
        redirect_to += "&action=sync_md5sum";
        redirect_to += "&nb_md5sum_added=" + String(data(origin, "origin"));

        window.location.href = redirect_to;
      }
    },
    error: function (XMLHttpRequest) {
      hide(document.querySelectorAll("#add_md5sum"));
      show(document.querySelectorAll("#add_md5sum_error"));
      html(
        document.querySelectorAll("#add_md5sum_error"),
        "error " + XMLHttpRequest.status + " : " + XMLHttpRequest.statusText,
      );
    },
  });
}

on(
  document.querySelectorAll("#delete_orphans"),
  "click",
  function (this: Element, e: Event) {
    hide([this]);
    show(document.querySelectorAll("#orphans_deletion"));

    const deleteBlockSize = Math.min(
      Number(
        (
          Number(
            data(document.querySelector("#orphans_to_delete")!, "origin"),
          ) / 2
        ).toFixed(),
      ),
      1000,
    );

    delete_orphans_block(deleteBlockSize);

    e.preventDefault();
    e.stopPropagation();
  },
);

function delete_orphans_block(blockSize?: number) {
  void ajax({
    url: "api/v1/images/actions/delete-orphans",
    type: "POST",
    contentType: "application/json",
    headers: {
      "X-CSRF-Token": val(document.querySelectorAll("input[name=pwg_token]"))!,
    },
    data: JSON.stringify({
      blockSize: blockSize,
    }),
    dataType: "json",
    success: function (
      responseData: import("../../../../openapi/client/schema").operations["imageDeleteOrphans"]["responses"][200]["content"]["application/json"],
    ) {
      html(
        document.querySelectorAll("#orphans_to_delete"),
        String(responseData.nbOrphans),
      );

      const origin = document.querySelector("#orphans_to_delete")!;
      const percent_remaining = Number(
        (
          (responseData.nbOrphans * 100) /
          Number(data(origin, "origin"))
        ).toFixed(),
      );
      const percent_done = 100 - percent_remaining;
      html(document.querySelectorAll("#orphans_deleted"), String(percent_done));

      if (responseData.nbOrphans > 0) {
        delete_orphans_block();
      } else {
        // time to refresh the whole page
        let redirect_to = "admin.php?page=batch_manager";
        redirect_to += "&action=delete_orphans";
        redirect_to += "&nb_orphans_deleted=" + String(data(origin, "origin"));

        window.location.href = redirect_to;
      }
    },
    error: function (XMLHttpRequest) {
      hide(document.querySelectorAll("#orphans_deletion"));
      show(document.querySelectorAll("#orphans_deletion_error"));
      html(
        document.querySelectorAll("#orphans_deletion_error"),
        "error " + XMLHttpRequest.status + " : " + XMLHttpRequest.statusText,
      );
    },
  });
}
