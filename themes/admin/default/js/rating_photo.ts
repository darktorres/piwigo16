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
import { ajax, AjaxError } from "../../../default/js/vendor/ajax";
import { getSelectizeInstance } from "../../../default/js/vendor/selectize";
import {
  dataId,
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

const categoriesCache = new CategoriesCache({
  serverKey: pwg_getPageData<string>("cache_key_categories"),
  serverId: pwg_getPageData<string>("cache_key_hash"),
  rootUrl: pwg_getPageData<string>("root_url"),
});

categoriesCache.selectize(
  document.querySelectorAll("[data-selectize=categories]"),
);

on(
  document.querySelectorAll("#removeAlbumFilter"),
  "click",
  function (event: Event): void {
    // Was `.selectize.setValue(null)` -- selectize.js's own `setValue()`
    // always `clear()`s before applying the given value regardless, so a
    // `null`/empty value is functionally just `clear()`.
    getSelectizeInstance(
      document.querySelector<HTMLSelectElement>("select[name=cat]")!,
    )?.clear();
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
// `vendor/selectize.ts`'s own `triggerChange()` dispatches a real native
// `change` event on the underlying `<select>` on every value change
// (typing/picking an option, or the `clear()` above), so a plain native
// listener now sees it -- unlike jQuery's own internal `.trigger('change')`
// dispatch the original library used, which only ever reached other
// jQuery-bound handlers.
on(document.querySelectorAll("select[name=cat]"), "change", function () {
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

const pwgToken = pwg_getPageData<string>("csrf_token");

delegate(
  document,
  "click",
  "a.icon-trash[data-image-id]",
  function (this: HTMLElement, event: Event): void {
    void del(
      this,
      dataId(this, "imageId"),
      dataId(this, "userId"),
      this.dataset["anonymousId"] ?? null,
    );
    event.preventDefault();
    event.stopPropagation();
  },
);

async function del(
  node: HTMLElement,
  id: number,
  uid: number,
  aid: string | null,
): Promise<void> {
  // `closest("tr")` can return null for markup with no ancestor <tr> -- an
  // empty array here reproduces jQuery's own "operate on nothing" semantics
  // for fadeTo/stop/remove below, rather than skipping the ajax call.
  const tr = node.closest("tr");
  const trSet = tr === null ? [] : [tr];
  fadeTo(trSet, 1000, 0.4);
  const data = {
    imageId: id,
    anonymousId: aid !== null && aid !== "" ? aid : null,
  };

  try {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
    const result = (await ajax({
      url:
        pwg_getPageData<string>("root_url") +
        "api/v1/users/" +
        String(uid) +
        "/actions/delete-ratings",
      method: "POST",
      json: data,
      headers: { "X-CSRF-Token": pwgToken },
    })) as operations["userDeleteRatings"]["responses"][200]["content"]["application/json"];

    if (result.deletedCount) remove(trSet);
    else alert(result.deletedCount);
  } catch (e) {
    stop(trSet);
    fadeTo(trSet, 0, 1);
    alert(
      e instanceof AjaxError
        ? String(e.status) + " " + e.statusText
        : String(e),
    );
  }
}
