// Real consumer of page-data.ts's own top-level `pwg_getPageData`
// (docs/PLAN.md P48, page-data.ts's own batch -- was a bare
// ambient-global read before that). This import doesn't change this
// file's own "stays its own standalone entry, unfolded" status below:
// nothing else imports footer.ts itself, so it's safe for it to become
// a real module (gaining this import) without needing to be folded into
// anyone else's bundle.
import { pwg_getPageData } from "../../../default/js/page-data";
import { ajax, AjaxError } from "../../../default/js/vendor/ajax";
import { hide, on, show } from "../../../default/js/vendor/dom";
import { tipTip } from "../../../default/js/vendor/tiptip";

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
// zero real exports to gain from module conversion in the first place
// (both its exposures below are confirmed category-2 `onclick`
// targets, not real cross-file symbols) -- not attempted here.
//
// hide_user_whats_new/show_user_whats_new are called from
// layout.latte's own `onclick="show_user_whats_new()"` / `onClick=
// "hide_user_whats_new()"` attributes -- the `javascript:`/`onclick=`
// pattern (docs/PLAN.md P46-C's own finding) needs the same `window.X
// = X` exposure as a cross-file bare read, once this file's own
// top-level declarations become IIFE-private at build time.
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

async function hide_user_whats_new(): Promise<void> {
  hide(document.querySelectorAll("#whats_new"));

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

function show_user_whats_new() {
  show(document.querySelectorAll("#whats_new"));
}

if (pwg_getPageData<boolean>("show_whats_new")) {
  show_user_whats_new();
}

window.hide_user_whats_new = hide_user_whats_new;
window.show_user_whats_new = show_user_whats_new;
