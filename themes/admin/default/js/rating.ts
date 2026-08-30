import type { operations } from "../../../../openapi/client/schema";
// Folds scripts.ts's own code in via a direct import (docs/ PLAN.md
// P48) instead of the separate `core.scripts` script tag RatingView
// used to register directly -- this page doesn't read any of
// scripts.ts's own real exports, just needs its side effects
// (`popuphelp`/`pwg_tryFocus`'s own `window.X` assignments, the
// `[data-confirm]` click guard).
import "../../../default/js/scripts";
import "./common";
import { CategoriesCache } from "./LocalStorageCache";

import { pwg_getPageData } from "../../../default/js/page-data";
import { ajax } from "../../../default/js/vendor/ajax";
import {
  delegate,
  fadeTo,
  hide,
  on,
  ready,
  remove,
  show,
  stop,
  val,
} from "../../../default/js/vendor/dom";
export {};

const categoriesCache = new CategoriesCache({
  serverKey: pwg_getPageData<string>("cache_key_categories"),
  serverId: pwg_getPageData<string>("cache_key_hash"),
  rootUrl: pwg_getPageData<string>("root_url"),
});

// Still jQuery: selectize() takes a JQuery object. The widget's own later
// reads (`.selectize` below) attach directly to the raw <select> element
// (selectize.js's own `input.selectize = self`) and need no wrapper.
categoriesCache.selectize(jQuery("[data-selectize=categories]"));

on(
  document.querySelectorAll("#removeAlbumFilter"),
  "click",
  function (event: Event): void {
    document
      .querySelector<HTMLSelectElement>("select[name=cat]")!
      .selectize.setValue(null);
    event.preventDefault();
    event.stopPropagation();
  },
);

function checkCatFilter() {
  if (val(document.querySelectorAll("select[name=cat]")) === "") {
    hide(document.querySelectorAll("#removeAlbumFilter"));
  } else {
    show(document.querySelectorAll("#removeAlbumFilter"));
  }
}

checkCatFilter();
// Still jQuery, deliberately: selectize.js's own onChange() propagates every
// value change (typing/picking an option, or the setValue(null) above) by
// calling `this.$input.trigger('change')` -- a jQuery-internal dispatch,
// not a real DOM event. `<select>` has no native `.change()` method for
// jQuery.trigger()'s own native-method fast path to call either, so a
// native addEventListener("change", ...) here would never fire. Converts
// with selectize itself in P49-B group 6.
jQuery("select[name=cat]").change(function () {
  checkCatFilter();
});

ready(function () {
  // jQuery's `$("h1").append(...)` appended to every matching heading, not
  // just the first.
  document.querySelectorAll("h1").forEach((heading) => {
    heading.insertAdjacentHTML(
      "beforeend",
      "<span class='badge-number'>" +
        String(pwg_getPageData<number>("nb_elements")) +
        "</span>",
    );
  });
});

const pwg_token = pwg_getPageData<string>("csrf_token");

delegate(
  document,
  "click",
  "a.icon-trash[data-image-id]",
  function (this: HTMLElement, event: Event): void {
    del(
      this,
      Number(this.dataset.imageId),
      Number(this.dataset.userId),
      this.dataset.anonymousId || null,
    );
    event.preventDefault();
    event.stopPropagation();
  },
);

function del(node: HTMLElement, id: number, uid: number, aid: string | null) {
  // `closest("tr")` can return null for markup with no ancestor <tr> -- an
  // empty array here reproduces jQuery's own "operate on nothing" semantics
  // for fadeTo/stop/remove below, rather than skipping the ajax call.
  const tr = node.closest("tr");
  const trSet = tr === null ? [] : [tr];
  fadeTo(trSet, 1000, 0.4);
  const data = {
    imageId: id,
    anonymousId: aid || null,
  };

  void ajax({
    url:
      pwg_getPageData<string>("root_url") +
      "api/v1/users/" +
      uid +
      "/actions/delete-ratings",
    method: "POST",
    contentType: "application/json",
    data: JSON.stringify(data),
    headers: { "X-CSRF-Token": pwg_token },
    error: function (jqXHR) {
      stop(trSet);
      fadeTo(trSet, 0, 1);
      alert(jqXHR.status + " " + jqXHR.statusText);
    },
    success: function (
      result: operations["userDeleteRatings"]["responses"][200]["content"]["application/json"],
    ) {
      if (result.deletedCount) remove(trSet);
      else alert(result.deletedCount);
    },
  });
}
