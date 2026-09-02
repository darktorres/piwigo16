// Genuinely bidirectional with batchManagerGlobal.ts (docs/PLAN.md P48
// -- was window-global latching pre-P48, see git history). This file
// declares `lang`/`all_elements`/`str_add_alb_associate`/
// `str_select_alb_associate`, imported by batchManagerGlobal.ts; that
// file declares `derivatives`/`progress_start`/`progress`/
// `getDerivativeUrls`, imported here (only used inside the deferred
// `#applyAction` click handler below -- safe regardless of evaluation
// order. batchManagerGlobal.ts's `lang.Cancel` read used to be the one
// exception, a genuine top-level synchronous read across this cycle; it
// now happens inside a `.ready()` callback, so no binding is read
// across the cycle during evaluation any more. That file's own leading
// comment records the one ordering dependency that does remain -- the
// order the two modules register their ready callbacks in). Both files
// fold into one real page bundle
// (themes/admin/default/js/pages/batch_manager_global.ts) -- a real,
// necessary requirement, not a style choice: this file's own top-level
// code has real, unconditional side effects (event-handler
// registration), so it can never safely be loaded twice on the same
// page the way a pure-declaration file like addAlbum.ts can.
import {
  derivatives,
  progress_start,
  progress,
  getDerivativeUrls,
} from "./batchManagerGlobal";
import { sprintf } from "./common";
import { CategoriesCache, TagsCache } from "./LocalStorageCache";
import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../default/js/page-data";
import { AjaxQueue } from "../../../default/js/vendor/ajaxQueue";
import {
  addClass,
  css,
  hide,
  is,
  on,
  ready,
  setVal,
  show,
  text,
  toggle,
  trigger,
  val,
} from "../../../default/js/vendor/dom";

export const lang = {
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

  const associated_categories = pwg_getPageData<
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
            Boolean(associated_categories[cat.id]),
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

const nb_thumbs_set = pwg_getPageData<number>("nb_thumbs_set");
const applyOnDetails_pattern = pwg_getPageString("on the %d selected photos");
export const all_elements =
  // eslint-disable-next-line @typescript-eslint/no-unnecessary-condition, @typescript-eslint/strict-boolean-expressions -- real runtime guard: pwg_getPageData<T>() always returns T per its own signature even when the key is genuinely absent from the page-data payload (an unsafe cast, not a real guarantee).
  pwg_getPageData<(string | number)[]>("all_elements") || [];

const selectedMessage_pattern = pwg_getPageString("%d of %d photos selected");
const selectedMessage_none = pwg_getPageString(
  "No photo selected, %d photos in current set",
);
const selectedMessage_all = pwg_getPageString("All %d photos are selected");
export const str_add_alb_associate = pwg_getPageString("Add Album");
export const str_select_alb_associate = pwg_getPageString("Select an album");

ready(function () {
  function checkPermitAction(): void {
    let nbSelected: number;
    if (is(document.querySelectorAll("input[name=setSelected]"), ":checked")) {
      nbSelected = nb_thumbs_set;
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
      sprintf(applyOnDetails_pattern, nbSelected),
    );

    // display the number of currently selected photos in the "Selection" fieldset
    if (nbSelected === 0) {
      text(
        document.querySelectorAll("#selectedMessage"),
        sprintf(selectedMessage_none, nb_thumbs_set),
      );
    } else if (nbSelected === nb_thumbs_set) {
      text(
        document.querySelectorAll("#selectedMessage"),
        sprintf(selectedMessage_all, nb_thumbs_set),
      );
    } else {
      text(
        document.querySelectorAll("#selectedMessage"),
        sprintf(selectedMessage_pattern, nbSelected, nb_thumbs_set),
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
        // Reaches batchManagerGlobal.ts's own enableShiftClick(), whose
        // "shclick" bind/trigger pair converted together with this call
        // site once that file's own P49-A module-cycle deferral lifted
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
        this.checked ? all_elements.join(",") : "",
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

  // batchManagerGlobal.ts's own `#applyAction` handler and click
  // triggers converted together with this one (its own P49-A
  // module-cycle deferral, now lifted) -- both now bind/dispatch through
  // real native events, so this can bind natively too. Was jQuery-bound
  // only because the old (pre-conversion) trigger side needed it; "click"
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

    // getDerivativeUrls() (batchManagerGlobal.ts) queues every request
    // this same instance -- including its own recursive self-calls, one
    // batch of urls at a time until derivatives.elements is drained --
    // so the queue is created once here and threaded through, not
    // recreated per batch.
    const queue = new AjaxQueue({ maxRequests: 1 });

    derivatives.elements = [];
    if (
      is(document.querySelectorAll('input[name="setSelected"]'), ":checked")
    ) {
      derivatives.elements = all_elements;
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

    progress_start();
    progress();
    getDerivativeUrls(queue);
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
