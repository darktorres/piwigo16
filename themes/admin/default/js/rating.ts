import type { operations } from "../../../../openapi/client/schema";
// Folds scripts.ts's own code in via a real `?dup` import (docs/
// PLAN.md P48) instead of the separate `core.scripts` script tag
// RatingView used to register directly -- this page doesn't read any
// of scripts.ts's own real exports, just needs its side effects
// (`popuphelp`/`pwg_tryFocus`'s own `window.X` assignments, the
// `[data-confirm]` click guard). `?dup` since scripts.ts has many real
// registrant pages (Design §4).
import "../../../default/js/scripts?dup";
import "./common?dup";
import { CategoriesCache } from "./LocalStorageCache?dup";

import { pwg_getPageData } from "../../../default/js/page-data?dup";
export {};

const categoriesCache = new CategoriesCache({
  serverKey: pwg_getPageData<string>("cache_key_categories"),
  serverId: pwg_getPageData<string>("cache_key_hash"),
  rootUrl: pwg_getPageData<string>("root_url"),
});

categoriesCache.selectize(jQuery("[data-selectize=categories]"));

jQuery("#removeAlbumFilter").click(function () {
  jQuery("select[name=cat]")[0]!.selectize.setValue(null);
  return false;
});

function checkCatFilter() {
  if (jQuery("select[name=cat]").val() === "") {
    jQuery("#removeAlbumFilter").hide();
  } else {
    jQuery("#removeAlbumFilter").show();
  }
}

checkCatFilter();
jQuery("select[name=cat]").change(function () {
  checkCatFilter();
});

$(document).ready(function () {
  $("h1").append(
    "<span class='badge-number'>" +
      pwg_getPageData<number>("nb_elements") +
      "</span>",
  );
});

const pwg_token = pwg_getPageData<string>("csrf_token");

$(document).on(
  "click",
  "a.icon-trash[data-image-id]",
  function (this: HTMLElement) {
    return del(
      this,
      Number(this.dataset.imageId),
      Number(this.dataset.userId),
      this.dataset.anonymousId || null,
    );
  },
);

function del(node: HTMLElement, id: number, uid: number, aid: string | null) {
  const tr = jQuery(node).parents("tr").first().fadeTo(1000, 0.4),
    data = {
      imageId: id,
      anonymousId: aid || null,
    };

  $.ajax({
    url:
      pwg_getPageData<string>("root_url") +
      "api/v1/users/" +
      uid +
      "/actions/delete-ratings",
    method: "POST",
    contentType: "application/json",
    data: JSON.stringify(data),
    headers: { "X-CSRF-Token": pwg_token },
    error: function (jqXHR: JQuery.jqXHR) {
      tr.stop();
      tr.fadeTo(0, 1);
      alert(jqXHR.status + " " + jqXHR.statusText);
    },
    success: function (
      result: operations["userDeleteRatings"]["responses"][200]["content"]["application/json"],
    ) {
      if (result.deletedCount) tr.remove();
      else alert(result.deletedCount);
    },
  });
  return false;
}
