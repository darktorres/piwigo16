// Real single bundle file for batch_manager_global.php (docs/PLAN.md
// P51-I item 2). Was two separate files (batch_manager_global.ts,
// batchManagerGlobal.ts) with a genuine circular import between them --
// see git history for the pre-merge shape. That cycle carried two real
// ordering hazards, both gone now that this is one file evaluated
// top-to-bottom by the language itself, no import-order reasoning
// needed any more:
// - a `lang.Cancel` TDZ read (batchManagerGlobal.ts's own top-level
//   code used to depend on the circular import forcing this file to
//   evaluate first) -- moot now that `lang` is just declared earlier in
//   this same file, well before any of its reads.
// - a `.ready()`-callback *registration order* requirement for
//   `pwgAddAlbum()`/`pwgDatepicker()` (both need the tags/categories
//   selectize setup below to have registered its own `.ready()`
//   callback first) -- automatically satisfied now, since that setup is
//   textually first in this file. See that callback's own comment,
//   further down, for the still-real reason it stays wrapped in
//   `.ready()` rather than becoming plain top-level code.
import {
  addClass,
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
  text,
  toggle,
  trigger,
  val,
} from "../../../default/js/vendor/dom";
// common.ts's own side effects (font-checkbox init, search-cancel
// bindings) -- batch_manager_filter.inc.latte's own `.font-checkbox`
// filter checkboxes (included by batch_manager_global.latte) need
// fontCheckbox() to run. This page used to get that incidentally, as a
// side effect of importing sprintf from what was then the same file
// (common.ts); the P51-I split made that dependency explicit instead
// of leaving it accidental.
import "./common";
import { sprintf } from "../../../default/js/sprintf";
import { CategoriesCache, TagsCache } from "./LocalStorageCache";
// Real consumer of album_selector.ts's own top-level `class
// AlbumSelector` (docs/PLAN.md P48 -- was a `window.AlbumSelector`
// read, see that file's own leading comment for the full real-consumer
// list, including the real, accepted "2 independent class copies on
// this page" consequence of batchManagerFilter.ts's own separate direct
// import).
import { AlbumSelector } from "../../../default/js/album_selector";
import { pwgAddAlbum } from "./addAlbum";
import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../default/js/page-data";
import { ajax, AjaxError } from "../../../default/js/vendor/ajax";
import { AjaxQueue } from "../../../default/js/vendor/ajaxQueue";
import { colorbox } from "../../../default/js/vendor/colorbox";
import { pwgDatepicker } from "../../../default/js/vendor/datepicker";
import { tipTip } from "../../../default/js/vendor/tiptip";
import type { operations } from "../../../../openapi/client/schema";

const lang = {
  Cancel: pwg_getPageString("Cancel"),
  deleteProgressMessage: pwg_getPageString("Deletion in progress"),
  syncProgressMessage: pwg_getPageString("Synchronization in progress"),
  AreYouSure: pwg_getPageString("Are you sure?"),
  generateMsg: pwg_getPageString("Generate multiple size images"),
};

// Was `jQuery(document).ready(...)`, not dom.ts's own `ready()`: this
// whole block used to be selectize CDN-script interop, and swapping to
// `ready()`'s always-deferred-via-`setTimeout()` timing broke that
// script's own initialization (confirmed live via a reproducible
// page-load "Script error." from inside the CDN bundle). Now that
// selectize itself is a real native module (P49-B group 6, `vendor/
// selectize.ts`) with no async script load to race, that constraint is
// gone -- converted to the same `ready()` every other P49 file uses.
ready(function () {
  // <!-- TAGS -->
  const tagsCache = new TagsCache({
    serverKey: pwg_getPageData<string>("cache_key_tags"),
    serverId: pwg_getPageData<string>("cache_key_hash"),
    rootUrl: pwg_getPageData<string>("root_url"),
  });

  tagsCache.selectize(document.querySelectorAll("[data-selectize=tags]"), {
    lang: {
      Add: pwg_getPageString("Create"),
    },
  });

  // <!-- CATEGORIES -->
  const categoriesCache = new CategoriesCache({
    serverKey: pwg_getPageData<string>("cache_key_categories"),
    serverId: pwg_getPageData<string>("cache_key_hash"),
    rootUrl: pwg_getPageData<string>("root_url"),
  });

  const associatedCategories = pwg_getPageData<
    Record<string | number, unknown>
  >("associated_categories");

  interface SelectizeCategoryOption extends Record<string, unknown> {
    id: string | number;
  }

  categoriesCache.selectize(
    document.querySelectorAll("[data-selectize=categories]"),
    {
      filter: function (
        this: { name: string },
        categories: SelectizeCategoryOption[],
        options: { default?: string | number | undefined },
      ) {
        if (this.name === "dissociate") {
          const filtered = categories.filter((cat) =>
            Boolean(associatedCategories[cat.id]),
          );

          if (filtered.length > 0) {
            options.default = filtered[0]!.id;
          }

          return filtered;
        } else {
          return categories;
        }
      },
    },
  );
});

const nbThumbsSet = pwg_getPageData<number>("nb_thumbs_set");
const applyOnDetailsPattern = pwg_getPageString("on the %d selected photos");
const allElements =
  // eslint-disable-next-line @typescript-eslint/no-unnecessary-condition, @typescript-eslint/strict-boolean-expressions -- real runtime guard: pwg_getPageData<T>() always returns T per its own signature even when the key is genuinely absent from the page-data payload (an unsafe cast, not a real guarantee).
  pwg_getPageData<(string | number)[]>("all_elements") || [];

const selectedMessagePattern = pwg_getPageString("%d of %d photos selected");
const selectedMessageNone = pwg_getPageString(
  "No photo selected, %d photos in current set",
);
const selectedMessageAll = pwg_getPageString("All %d photos are selected");
const strAddAlbAssociate = pwg_getPageString("Add Album");
const strSelectAlbAssociate = pwg_getPageString("Select an album");

interface Derivatives {
  elements: (string | number)[] | null;
  done: number;
  total: number;
  finished(): boolean;
}

const derivatives: Derivatives = {
  elements: null,
  done: 0,
  total: 0,

  finished: function () {
    return (
      derivatives.done === derivatives.total &&
      derivatives.elements !== null &&
      derivatives.elements.length === 0
    );
  },
};

function progressStart() {
  show(document.querySelectorAll("#uploadingActions"));
  css(
    document.querySelectorAll("#uploadingActions .progress-bar"),
    "width",
    "0%",
  );
}

function progressEnd() {
  hide(document.querySelectorAll("#uploadingActions"));
}

function progress(success?: boolean) {
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
    progressEnd();
    document.querySelector<HTMLElement>("#applyAction")?.click();
  }
}

async function getDerivativeUrls(queue: AjaxQueue): Promise<void> {
  const ids = derivatives.elements!.splice(0, 500).map(Number);
  const params: { maxUrls: number; ids: number[]; types: string[] } = {
    maxUrls: 100000,
    ids: ids,
    types: [],
  };
  document
    .querySelectorAll<HTMLInputElement>("#action_generate_derivatives input")
    .forEach((t) => {
      if (is(t, ":checked")) params.types.push(t.value);
    });
  hide(document.querySelectorAll("#applyActionBlock"));
  hide(document.querySelectorAll(".permitActionListButton"));
  hide(document.querySelectorAll("#confirmDel"));
  show(document.querySelectorAll("#regenerationMsg"));
  html(document.querySelectorAll("#regenerationText"), lang.generateMsg);
  progressStart();

  let responseData: operations["imageMissingDerivatives"]["responses"][200]["content"]["application/json"];
  try {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
    responseData = (await ajax({
      type: "POST",
      url: "api/v1/images/actions/missing-derivatives",
      json: params,
      headers: {
        "X-CSRF-Token": val(
          document.querySelectorAll("input[name=pwg_token]"),
        )!,
      },
      dataType: "json",
    })) as operations["imageMissingDerivatives"]["responses"][200]["content"]["application/json"];
  } catch (e) {
    console.error(e instanceof AjaxError ? e.responseText : e);
    return;
  }

  derivatives.total += responseData.urls.length;
  html(
    document.querySelectorAll("#regenerationStatus .badge-number"),
    derivatives.done.toString() + "/" + derivatives.total.toString(),
  );
  progress();
  for (const url of responseData.urls) {
    queue.add({
      type: "GET",
      url: url + "&ajaxload=true",
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
        void getDerivativeUrls(queue);
      },
      25 * (derivatives.total - derivatives.done),
    );
}

ready(function () {
  function checkPermitAction(): void {
    let nbSelected: number;
    if (is(document.querySelectorAll("input[name=setSelected]"), ":checked")) {
      nbSelected = nbThumbsSet;
    } else {
      nbSelected = document.querySelectorAll(
        ".thumbnails input[type=checkbox]:checked",
      ).length;
    }

    if (nbSelected === 0) {
      hide(document.querySelectorAll("#permitAction"));
      show(document.querySelectorAll("#forbidAction"));
    } else {
      show(document.querySelectorAll("#permitAction"));
      hide(document.querySelectorAll("#forbidAction"));
    }

    text(
      document.querySelectorAll("#applyOnDetails"),
      sprintf(applyOnDetailsPattern, nbSelected),
    );

    // display the number of currently selected photos in the "Selection" fieldset
    if (nbSelected === 0) {
      text(
        document.querySelectorAll("#selectedMessage"),
        sprintf(selectedMessageNone, nbThumbsSet),
      );
    } else if (nbSelected === nbThumbsSet) {
      text(
        document.querySelectorAll("#selectedMessage"),
        sprintf(selectedMessageAll, nbThumbsSet),
      );
    } else {
      text(
        document.querySelectorAll("#selectedMessage"),
        sprintf(selectedMessagePattern, nbSelected, nbThumbsSet),
      );
    }
  }

  hide(document.querySelectorAll("[id^=action_]"));

  on(
    document.querySelectorAll("select[name=selectAction]"),
    "change",
    function (this: HTMLSelectElement) {
      hide(document.querySelectorAll("[id^=action_]"));

      const action = this.value;
      // if (action == 'move') {
      //   action = 'associate';
      // }

      show(document.querySelectorAll("#action_" + action));

      if (this.value !== "-1") {
        show(document.querySelectorAll("#applyActionBlock"));
      } else {
        hide(document.querySelectorAll("#applyActionBlock"));
      }
      if (this.value === "delete" || this.value === "delete_derivatives") {
        css(document.querySelectorAll("#confirmDel"), "visibility", "visible");
      } else {
        css(document.querySelectorAll("#confirmDel"), "visibility", "hidden");
      }
    },
  );

  on(
    document.querySelectorAll(".wrap1 label"),
    "click",
    function (this: Element, event: Event) {
      document
        .querySelectorAll<HTMLInputElement>("input[name=setSelected]")
        .forEach((el) => {
          el.checked = false;
        });
      trigger(document.querySelectorAll("input[name=setSelected]"), "change");

      const li = this.closest("li");
      const checkbox = Array.from(this.children).find(
        (child): child is HTMLInputElement =>
          child.matches("input[type=checkbox]"),
      );

      if (checkbox !== undefined) {
        // Reaches enableShiftClick() (below, in this same file), whose
        // "shclick" bind/trigger pair converted together with this call
        // site once that block's own P49-A module-cycle deferral lifted
        // (see this file's leading comment). The real event travels as
        // the CustomEvent's `detail`, since dom.ts's `trigger()` has no
        // jQuery-style extra-parameter slot.
        trigger(checkbox, "shclick", event);

        if (checkbox.checked) {
          li?.classList.add("thumbSelected");
        } else {
          li?.classList.remove("thumbSelected");
        }
      }

      checkPermitAction();
    },
  );

  on(document.querySelectorAll("#selectAll"), "click", function (event: Event) {
    document
      .querySelectorAll<HTMLInputElement>("input[name=setSelected]")
      .forEach((el) => {
        el.checked = false;
      });
    trigger(document.querySelectorAll("input[name=setSelected]"), "change");
    selectPageThumbnails();
    checkPermitAction();
    event.preventDefault();
    event.stopPropagation();
  });

  function selectPageThumbnails(): void {
    document.querySelectorAll(".thumbnails label").forEach((label) => {
      const checkbox = Array.from(label.children).find(
        (child): child is HTMLInputElement =>
          child.matches("input[type=checkbox]"),
      );

      if (checkbox !== undefined) {
        checkbox.checked = true;
        trigger(checkbox, "change");
      }
      label.closest("li")?.classList.add("thumbSelected");
    });
  }

  on(
    document.querySelectorAll("#selectNone"),
    "click",
    function (event: Event) {
      document
        .querySelectorAll<HTMLInputElement>("input[name=setSelected]")
        .forEach((el) => {
          el.checked = false;
        });
      trigger(document.querySelectorAll("input[name=setSelected]"), "change");

      document.querySelectorAll(".thumbnails label").forEach((label) => {
        const checkbox = Array.from(label.children).find(
          (child): child is HTMLInputElement =>
            child.matches("input[type=checkbox]"),
        );

        if (checkbox !== undefined) {
          if (checkbox.checked) {
            checkbox.checked = false;
            trigger(checkbox, "change");
          }
          label.closest("li")?.classList.remove("thumbSelected");
        }
      });
      checkPermitAction();
      event.preventDefault();
      event.stopPropagation();
    },
  );

  on(
    document.querySelectorAll("#selectInvert"),
    "click",
    function (event: Event) {
      document
        .querySelectorAll<HTMLInputElement>("input[name=setSelected]")
        .forEach((el) => {
          el.checked = false;
        });
      trigger(document.querySelectorAll("input[name=setSelected]"), "change");

      document.querySelectorAll(".thumbnails label").forEach((label) => {
        const checkbox = Array.from(label.children).find(
          (child): child is HTMLInputElement =>
            child.matches("input[type=checkbox]"),
        );

        if (checkbox !== undefined) {
          checkbox.checked = !checkbox.checked;
          trigger(checkbox, "change");

          if (checkbox.checked) {
            label.closest("li")?.classList.add("thumbSelected");
          } else {
            label.closest("li")?.classList.remove("thumbSelected");
          }
        }
      });
      checkPermitAction();
      event.preventDefault();
      event.stopPropagation();
    },
  );

  on(document.querySelectorAll("#selectSet"), "click", function (event: Event) {
    selectPageThumbnails();
    document
      .querySelectorAll<HTMLInputElement>("input[name=setSelected]")
      .forEach((el) => {
        el.checked = true;
      });
    trigger(document.querySelectorAll("input[name=setSelected]"), "change");
    checkPermitAction();
    event.preventDefault();
    event.stopPropagation();
  });

  on(
    document.querySelectorAll("input[name=setSelected]"),
    "change",
    function (this: HTMLInputElement) {
      setVal(
        document.querySelectorAll("input[name=whole_set]"),
        this.checked ? allElements.join(",") : "",
      );
    },
  );

  // if the whole set is selected on page load (after a first action has been applied),
  // trigger a change to make sure input[name=whole_set] is updated
  if (is(document.querySelectorAll('input[name="setSelected"]'), ":checked")) {
    trigger(document.querySelectorAll("input[name=setSelected]"), "change");
  }

  on(
    document.querySelectorAll("input[name=confirm_deletion]"),
    "change",
    function () {
      css(
        document.querySelectorAll("#confirmDel span.errors"),
        "visibility",
        "hidden",
      );
    },
  );

  // This file's other `#applyAction` click handler (below, guarding
  // metadata/delete) converted together with this one (its own P49-A
  // module-cycle deferral, now lifted) -- both now bind through real
  // native events, so this can bind natively too. Was jQuery-bound only
  // because the old (pre-conversion) trigger side needed it; "click"
  // itself is not a library-only event.
  on(document.querySelectorAll("#applyAction"), "click", function (e: Event) {
    const action = val(document.querySelectorAll('[name="selectAction"]'));
    if (action === "delete_derivatives") {
      if (
        !is(
          document.querySelectorAll("#confirmDel input[name=confirm_deletion]"),
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
      } else {
        return;
      }
    }

    if (action !== "generate_derivatives" || derivatives.finished()) {
      return;
    }

    hide(document.querySelectorAll(".bulkAction"));

    // getDerivativeUrls() (above, in this same file) queues every
    // request this same instance -- including its own recursive
    // self-calls, one batch of urls at a time until derivatives.elements
    // is drained -- so the queue is created once here and threaded
    // through, not recreated per batch.
    const queue = new AjaxQueue({ maxRequests: 1 });

    derivatives.elements = [];
    if (
      is(document.querySelectorAll('input[name="setSelected"]'), ":checked")
    ) {
      derivatives.elements = allElements;
    } else {
      document
        .querySelectorAll<HTMLInputElement>(
          ".thumbnails input[type=checkbox]:checked",
        )
        .forEach((el) => {
          derivatives.elements!.push(el.value);
        });
    }

    hide(document.querySelectorAll("#applyActionBlock"));
    hide(document.querySelectorAll('select[name="selectAction"]'));
    addClass(
      document.querySelectorAll(".permitActionListButton div"),
      "hidden",
    );
    show(document.querySelectorAll("#regenerationMsg"));

    progressStart();
    progress();
    void getDerivativeUrls(queue);
    e.preventDefault();
    e.stopPropagation();
  });

  checkPermitAction();

  on(
    document.querySelectorAll("select[name=filter_prefilter]"),
    "change",
    function (this: HTMLSelectElement) {
      toggle(
        document.querySelectorAll("#empty_caddie"),
        this.value === "caddie",
      );
      toggle(
        document.querySelectorAll("#duplicates_options"),
        this.value === "duplicates",
      );
      toggle(
        document.querySelectorAll("#delete_orphans"),
        this.value === "no_album",
      );
      toggle(
        document.querySelectorAll("#sync_md5sum"),
        this.value === "no_sync_md5sum",
      );
    },
  );
});

/* ********** Thumbs */

/* Shift-click: select all photos between the click and the shift+click */
ready(function () {
  let lastClicked = 0;
  let lastClickedStatus = true;

  function enableShiftClick(container: Element): void {
    const inputs: HTMLInputElement[] = [];
    let count = 0;
    find(container, "input[type=checkbox]").forEach((el) => {
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- verified by the selector: only <input type=checkbox> elements can match "input[type=checkbox]".
      const checkbox = el as HTMLInputElement;
      const pos = count;
      inputs[count++] = checkbox;
      // "shclick" is a first-party custom event, not a library's --
      // both this bind and the trigger below convert together, and so
      // does this file's own remote trigger of it above (the ".wrap1
      // label" click handler). The real event (carrying `shiftKey`)
      // travels as the CustomEvent's `detail`, since dom.ts's
      // `trigger()` has no jQuery-style extra-parameter slot.
      on(checkbox, "shclick", function (shclickEvent: Event) {
        // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- "shclick" is this same file's own real trigger() dispatch a few lines below, always a real CustomEvent with this detail shape.
        const event = (shclickEvent as CustomEvent<MouseEvent | KeyboardEvent>)
          .detail;
        if (event.shiftKey) {
          let first = lastClicked;
          let last = pos;
          if (first > last) {
            first = pos;
            last = lastClicked;
          }

          for (let i = first; i <= last; i++) {
            const input = inputs[i]!;
            input.checked = lastClickedStatus;
            trigger([input], "change");
            const li = input.closest("li");
            if (lastClickedStatus) {
              li?.classList.add("thumbSelected");
            } else {
              li?.classList.remove("thumbSelected");
            }
          }
        } else {
          lastClicked = pos;
          lastClickedStatus = checkbox.checked;
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

  const abAction = new AlbumSelector({
    adminMode: true,
    selectAlbum: selectAlbumAction,
    removeSelectedAlbum: removeAlbumAction,
  });

  on(document.querySelectorAll("#associate_as"), "click", function () {
    abAction.open();
  });

  on(
    document.querySelectorAll(".selected-associate-action"),
    "click",
    (e: Event) => {
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- a real click inside the document always targets an Element (or null), never a bare EventTarget with no Element interface.
      const target = e.target as Element;
      if (target.classList.contains("remove-associate")) {
        abAction.removeSelectedAlbum(target.id);
      }
    },
  );
});

/* ********** Album Selector */
function selectAlbumAction({
  album,
  addSelectedAlbum,
}: AlbumSelectorCallbackArgs) {
  html(document.querySelectorAll("#associate_as p"), strAddAlbAssociate);
  append(
    document.querySelectorAll(".selected-associate-action"),
    `<div class="selected-associate-item">
      <span>${album.name ?? ""}</span><span id="${album.id}" class="remove-associate icon-cancel-circled"></span>
      <input type="hidden" id="associate_input_${album.id}" name="associate[]" value="${album.id}">
    </div>`,
  );
  addSelectedAlbum();
}

function removeAlbumAction({
  id_album,
  getSelectedAlbum,
}: AlbumSelectorRemoveCallbackArgs) {
  // `id_album` is the raw album id `selectAlbumAction()` wrote above
  // as this chip's own bare `id` attribute -- digit-leading, so it
  // needs escapeId() under native querySelector (same bug class as
  // mcs.ts's remove_related_category()).
  find(
    document.querySelectorAll(".selected-associate-item"),
    "#" + escapeId(id_album),
  )[0]?.parentElement?.remove();
  const selected = getSelectedAlbum();
  if (!selected.length) {
    html(document.querySelectorAll("#associate_as p"), strSelectAlbAssociate);
  }
}

colorbox(document.querySelectorAll("a.preview-box"), { photo: true });

tipTip(document.querySelectorAll(".thumbnails img"), {
  delay: 0,
  fadeIn: 200,
  fadeOut: 200,
});

/* ********** Actions*/

// Deferred via `.ready()` rather than read as plain top-level code
// purely to preserve this file's pre-merge relative timing (this used
// to be a separate module, and every `.ready()` call in a Footer-mode
// bundle like this one resolves via its own `setTimeout()`, so it fired
// after the *other* file's own top-level code had already run --
// see this file's leading comment). `lang` itself has no TDZ concern
// any more; it's declared well above, in this same file.
ready(function () {
  pwgDatepicker(document.querySelectorAll("[data-datepicker]"), {
    showTimepicker: true,
    cancelButton: lang.Cancel,
    jqueryCode: pwg_getPageData<string | undefined>("jquery_code"),
  });
});

// Real regression found live (docs/PLAN.md P48, this pair's own first
// merge into one bundle): `pwgAddAlbum()` needs `[data-add-album]`'s own
// target `<select>` to already have its selectize widget initialized
// (`addAlbum.ts`'s own `throw new Error('pwgAddAlbum: target must use
// selectize')` guard), which happens inside the tags/categories
// `.ready()` callback near the top of this file. jQuery 3.x's
// `.ready()` resolves via a real Deferred, not truly synchronously even
// when the document is already ready (Footer scripts run after the DOM
// is already parsed) -- confirmed live via Playwright: calling this
// bare, synchronously, ran it *before* that deferred callback's own
// body, throwing every time. Wrapping this in its own `.ready()` call
// re-establishes correct relative order: the selectize setup's own
// `.ready()` callback is registered first, since it's textually first
// in this file -- dom.ts's `ready()` resolves via `setTimeout()`, and
// same-delay timeouts fire in registration order.
//
// NOTE this is still a real ordering dependency, and a different one
// from the `lang.Cancel` TDZ this merge already removed: it needs the
// two `.ready()` calls to *register* in this order, not merely for both
// modules to have finished evaluating. Reordering the two blocks in
// this file does not throw a ReferenceError, it makes pwgAddAlbum() run
// before the selectize setup and hit addAlbum.ts's own `pwgAddAlbum:
// target must use selectize` guard. Confirmed live once already.
ready(function () {
  const triggerEl = document.querySelector("[data-add-album]");
  if (triggerEl !== null) {
    pwgAddAlbum(triggerEl);
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

  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- "keypress" always dispatches a real KeyboardEvent; on()'s own handler param is typed generically via the native EventListener interface.
  const keyEvent = e as KeyboardEvent;
  if (
    keyEvent.key === "Enter" &&
    Number(selected) !== -1 &&
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
  if (typeof elements !== "undefined") {
    return;
  }

  let progressBarMax: number;

  if (val(document.querySelectorAll('[name="selectAction"]')) === "metadata") {
    e.preventDefault();
    e.stopPropagation();
    hide(document.querySelectorAll(".bulkAction"));
    html(
      document.querySelectorAll("#regenerationText"),
      lang.syncProgressMessage,
    );
    elements = [];

    if (is(document.querySelector("input[name=setSelected]")!, ":checked")) {
      elements = allElements;
    } else {
      document
        .querySelectorAll<HTMLInputElement>('input[name="selection[]"]:checked')
        .forEach((el) => {
          elements!.push(el.value);
        });
    }

    const queuedManager = new AjaxQueue({ maxRequests: 1 });

    // Local alias for the definitely-assigned value, kept for
    // readability. The non-null assertion this used to need is gone:
    // it was only necessary while `allElements` arrived typed as `any`
    // through a direct import, which defeated control-flow narrowing of
    // the outer `let elements`. With the real `(string | number)[]`
    // type restored, both assignment branches narrow it properly.
    const syncElements = elements;

    progressBarMax = syncElements.length;
    let todo = 0;
    const syncBlockSize = Math.min(
      Number((syncElements.length / 2).toFixed()),
      1000,
    );
    let imageIds = [];

    hide(document.querySelectorAll("#applyActionBlock"));
    hide(document.querySelectorAll(".permitActionListButton"));
    hide(document.querySelectorAll("#confirmDel"));
    show(document.querySelectorAll("#regenerationMsg"));
    progressBarStart();
    for (let i = 0; i < syncElements.length; i++) {
      imageIds.push(syncElements[i]);
      if (
        i % syncBlockSize !== syncBlockSize - 1 &&
        i !== syncElements.length - 1
      ) {
        continue;
      }

      // `todo`/`progressBarMax` are a running counter and a fixed total
      // shared across every batch's own async callback, not a
      // per-iteration snapshot -- each callback must see the live value
      // as later batches complete, which is exactly what a real closure
      // over the outer scope gives here. Only `ids` needed the IIFE's
      // own per-iteration capture, already applied above.
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
          // eslint-disable-next-line @typescript-eslint/no-loop-func -- see comment above the IIFE.
          success: function (
            responseData: operations["imageSyncMetadata"]["responses"][200]["content"]["application/json"],
          ) {
            todo += thisBatchSize;
            if (responseData.nbSynchronized !== thisBatchSize) {
              /*TODO: user feedback only data.nbSynchronized images out of thisBatchSize were sync*/
            }
            html(
              document.querySelectorAll("#regenerationStatus .badge-number"),
              todo.toString() + "/" + progressBarMax.toString(),
            );
            progressBar(todo, progressBarMax, false);
          },
          // eslint-disable-next-line @typescript-eslint/no-loop-func -- see comment above the IIFE.
          error: function (_data: unknown) {
            todo += thisBatchSize;
            /*TODO: user feedback*/
            html(
              document.querySelectorAll("#regenerationStatus .badge-number"),
              todo.toString() + "/" + progressBarMax.toString(),
            );
            progressBar(todo, progressBarMax, false);
          },
        });
      })(imageIds);
      imageIds = [];
    }
  }

  if (val(document.querySelectorAll('[name="selectAction"]')) === "delete") {
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
    elements = allElements;
  } else {
    document
      .querySelectorAll<HTMLInputElement>('input[name="selection[]"]:checked')
      .forEach((el) => {
        elements!.push(el.value);
      });
  }

  // Local alias, same as syncElements above -- and likewise no longer
  // needs a non-null assertion now that `allElements` has a real type.
  const deleteElements = elements;

  progressBarMax = deleteElements.length;
  let todo = 0;
  const deleteBlockSize = Math.min(
    Number((deleteElements.length / 2).toFixed()),
    1000,
  );
  let imageIds = [];

  hide(document.querySelectorAll("#applyActionBlock"));
  hide(document.querySelectorAll(".permitActionListButton"));
  hide(document.querySelectorAll("#confirmDel"));
  html(
    document.querySelectorAll("#regenerationText"),
    lang.deleteProgressMessage,
  );
  show(document.querySelectorAll("#regenerationMsg"));
  progressBarStart();
  for (let i = 0; i < deleteElements.length; i++) {
    imageIds.push(deleteElements[i]);
    if (
      i % deleteBlockSize !== deleteBlockSize - 1 &&
      i !== deleteElements.length - 1
    ) {
      continue;
    }

    // `todo`/`progressBarMax` are a running counter and a fixed total
    // shared across every batch's own async callback, not a
    // per-iteration snapshot -- each callback must see the live value
    // as later batches complete, which is exactly what a real closure
    // over the outer scope gives here. Only `ids` needed the IIFE's
    // own per-iteration capture, already applied above.
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
        // eslint-disable-next-line @typescript-eslint/no-loop-func -- see comment above the IIFE.
        success: function (
          responseData: operations["imageDelete"]["responses"][200]["content"]["application/json"],
        ) {
          todo += thisBatchSize;
          if (responseData.deletedCount !== thisBatchSize) {
            /*TODO: user feedback only data.deletedCount images out of thisBatchSize were deleted*/
          }
          /*TODO: user feedback if isError*/
          html(
            document.querySelectorAll("#regenerationStatus .badge-number"),
            todo.toString() + "/" + progressBarMax.toString(),
          );
          progressBar(todo, progressBarMax, false);
        },
        // eslint-disable-next-line @typescript-eslint/no-loop-func -- see comment above the IIFE.
        error: function (_data: unknown) {
          todo += thisBatchSize;
          /*TODO: user feedback*/
          html(
            document.querySelectorAll("#regenerationStatus .badge-number"),
            todo.toString() + "/" + progressBarMax.toString(),
          );
          progressBar(todo, progressBarMax, false);
        },
      });
    })(imageIds);

    imageIds = [];
  }

  /* tell PHP how many photos were deleted */
  append(
    document.querySelectorAll("form"),
    '<input type="hidden" name="nb_photos_deleted" value="' +
      String(deleteElements.length) +
      '">',
  );

  e.preventDefault();
  e.stopPropagation();
});

function progressBarStart() {
  show(document.querySelectorAll("#uploadingActions"));
  css(
    document.querySelectorAll("#uploadingActions .progress-bar"),
    "width",
    "0%",
  );
}

function progressBar(current: number, max: number, _success: boolean) {
  const percent = parseInt(String((current / max) * 100));
  css(
    document.querySelectorAll("#uploadingActions .progressbar"),
    "width",
    percent.toString() + "%",
  );
  if (current === max)
    document.querySelector<HTMLElement>("#applyAction")?.click();
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
    void addMd5sumBlock(addBlockSize);

    e.preventDefault();
    e.stopPropagation();
  },
);

async function addMd5sumBlock(blockSize?: number): Promise<void> {
  try {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
    const responseData = (await ajax({
      url: "api/v1/images/actions/set-md5sum",
      type: "POST",
      json: {
        blockSize: blockSize,
      },
      headers: {
        "X-CSRF-Token": val(
          document.querySelectorAll("input[name=pwg_token]"),
        )!,
      },
      dataType: "json",
    })) as operations["imageSetMd5sum"]["responses"][200]["content"]["application/json"];

    html(
      document.querySelectorAll("#md5sum_to_add"),
      String(responseData.remainingCount),
    );

    const origin = document.querySelector("#md5sum_to_add")!;
    const percentRemaining = Number(
      (
        (responseData.remainingCount * 100) /
        Number(data(origin, "origin"))
      ).toFixed(),
    );
    const percentDone = 100 - percentRemaining;
    html(document.querySelectorAll("#md5sum_added"), String(percentDone));
    if (responseData.remainingCount > 0) {
      void addMd5sumBlock();
    } else {
      // time to refresh the whole page
      let redirectTo = "admin.php?page=batch_manager";
      redirectTo += "&action=sync_md5sum";
      redirectTo += "&nb_md5sum_added=" + String(data(origin, "origin"));

      window.location.href = redirectTo;
    }
  } catch (e) {
    hide(document.querySelectorAll("#add_md5sum"));
    show(document.querySelectorAll("#add_md5sum_error"));
    html(
      document.querySelectorAll("#add_md5sum_error"),
      "error " +
        (e instanceof AjaxError
          ? String(e.status) + " : " + e.statusText
          : String(e)),
    );
  }
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

    void deleteOrphansBlock(deleteBlockSize);

    e.preventDefault();
    e.stopPropagation();
  },
);

async function deleteOrphansBlock(blockSize?: number): Promise<void> {
  try {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
    const responseData = (await ajax({
      url: "api/v1/images/actions/delete-orphans",
      type: "POST",
      json: {
        blockSize: blockSize,
      },
      headers: {
        "X-CSRF-Token": val(
          document.querySelectorAll("input[name=pwg_token]"),
        )!,
      },
      dataType: "json",
    })) as operations["imageDeleteOrphans"]["responses"][200]["content"]["application/json"];

    html(
      document.querySelectorAll("#orphans_to_delete"),
      String(responseData.nbOrphans),
    );

    const origin = document.querySelector("#orphans_to_delete")!;
    const percentRemaining = Number(
      (
        (responseData.nbOrphans * 100) /
        Number(data(origin, "origin"))
      ).toFixed(),
    );
    const percentDone = 100 - percentRemaining;
    html(document.querySelectorAll("#orphans_deleted"), String(percentDone));

    if (responseData.nbOrphans > 0) {
      void deleteOrphansBlock();
    } else {
      // time to refresh the whole page
      let redirectTo = "admin.php?page=batch_manager";
      redirectTo += "&action=delete_orphans";
      redirectTo += "&nb_orphans_deleted=" + String(data(origin, "origin"));

      window.location.href = redirectTo;
    }
  } catch (e) {
    hide(document.querySelectorAll("#orphans_deletion"));
    show(document.querySelectorAll("#orphans_deletion_error"));
    html(
      document.querySelectorAll("#orphans_deletion_error"),
      "error " +
        (e instanceof AjaxError
          ? String(e.status) + " : " + e.statusText
          : String(e)),
    );
  }
}
