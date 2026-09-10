// Real consumer of pageData.ts's own top-level `pwg_getPageData`
// (docs/PLAN.md P48, pageData.ts's own batch -- was a bare
// ambient-global read before that). This import doesn't change this
// file's own "stays its own standalone entry, unfolded" status below:
// nothing else imports footer.ts itself, so it's safe for it to become
// a real module (gaining this import) without needing to be folded into
// anyone else's bundle.
import { pwg_getPageData } from "../../../default/js/pageData";
import { ajax, AjaxError } from "../../../default/js/vendor/utils/ajax";
import { on } from "../../../default/js/vendor/utils/dom";
import { tipTip } from "../../../default/js/vendor/widgets/tiptip";

/**
 * `#whats_new` renders as a real `<dialog>` (converted from a hand-rolled
 * fixed-position overlay `<div>`, docs/PLAN.md P52-J) -- narrowed once here
 * so open/close can use the native `showModal()`/`close()` API.
 */
const whatsNewDialogEl = (() => {
  const el = document.getElementById("whats_new");
  return el instanceof HTMLDialogElement ? el : null;
})();

// This file's own registration stays its own standalone Vite entry,
// unfolded (docs/PLAN.md P48, this file's own catalog line's
// investigation, confirmed not just assumed): every OTHER shared-
// library file in this campaign is imported by its real registrant
// pages' own bundles because each is registered by an individual
// View's own `pageAssets()`, giving a concrete per-page file to attach
// the import to. `footer.ts` is different -- it's injected exactly
// once, centrally, by `Template::finalizeHtml()` (via
// `ThemeBaseAssets::lateAdminScripts()`), deliberately decoupled from
// any specific View to preserve the exact same-priority script
// insertion ORDER every real `layout.latte` originally had (see
// `ThemeBaseAssets`'s own class docblock) -- `finalizeHtml()` has no
// notion of "the current page's own primary script id" to fold into.
// Manually threading a footer import through every admin View's own
// `pageAssets()` instead would mean re-deriving that centralized
// ordering guarantee per-page, a real regression risk for a file with
// zero real exports to gain from module conversion in the first place.
tipTip(document.querySelectorAll(".tiptip"), {
  delay: 0,
  fadeIn: 200,
  fadeOut: 200,
});

on(
  document.querySelectorAll("a.externalLink"),
  "click",
  function (this: Element, event: Event): void {
    window.open(this.getAttribute("href") ?? undefined);
    // jQuery treats a handler returning false as preventDefault() PLUS
    // stopPropagation(), so both are needed here -- returning false from a
    // native listener only does the former.
    event.preventDefault();
    event.stopPropagation();
  },
);

// Persists the user's dismissal server-side -- run from the dialog's own
// native "close" event (registered below) so every close path (the close
// icon, the "Ok, got it!" button, Escape, backdrop click) saves it exactly
// once, instead of duplicating the call per trigger.
async function persistWhatsNewDismissed(): Promise<void> {
  try {
    await ajax({
      url:
        "api/v1/session/preferences/show_whats_new_" +
        pwg_getPageData<string>("whats_new_major_version"),
      type: "PUT",
      dataType: "JSON",
      json: {
        value: JSON.stringify(false),
        isJson: true,
      },
    });
  } catch (e) {
    console.error(e instanceof AjaxError ? e.responseText : e);
  }
}

function showUserWhatsNew() {
  whatsNewDialogEl?.showModal();
}

if (pwg_getPageData<boolean>("show_whats_new")) {
  showUserWhatsNew();
}

if (whatsNewDialogEl !== null) {
  on(whatsNewDialogEl, "close", (): void => {
    void persistWhatsNewDismissed();
  });
  // Native <dialog> already closes on Escape; this matches its own
  // ::backdrop with the usual click-outside-to-close idiom.
  on(whatsNewDialogEl, "click", function (e: Event) {
    if (e.target === whatsNewDialogEl) {
      whatsNewDialogEl.close();
    }
  });
}

on(
  document.querySelectorAll("#whats_new_notification"),
  "click",
  showUserWhatsNew,
);
on(
  document.querySelectorAll("#whats_new_notification"),
  "keydown",
  function (event: Event) {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- "keydown" always dispatches a real KeyboardEvent; on()'s own handler param is typed generically via the native EventListener interface.
    const { key } = event as KeyboardEvent;
    if (key === "Enter" || key === " ") {
      event.preventDefault();
      showUserWhatsNew();
    }
  },
);
on(
  document.querySelectorAll(".close_whats_new, .js-hide-whats-new"),
  "click",
  (): void => {
    whatsNewDialogEl?.close();
  },
);
on(document.querySelectorAll(".close_whats_new"), "keydown", function (
  event: Event,
) {
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- "keydown" always dispatches a real KeyboardEvent; on()'s own handler param is typed generically via the native EventListener interface.
  const { key } = event as KeyboardEvent;
  if (key === "Enter" || key === " ") {
    event.preventDefault();
    whatsNewDialogEl?.close();
  }
});
