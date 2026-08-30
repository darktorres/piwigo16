import type { operations } from "../../../../openapi/client/schema";
// Folds scripts.ts's own code in via a direct import (docs/ PLAN.md
// P48) instead of the separate `core.scripts` script tag RatingUserView
// used to register directly -- this page doesn't read any of
// scripts.ts's own real exports, just needs its side effects
// (`popuphelp`/`pwg_tryFocus`'s own `window.X` assignments, the
// `[data-confirm]` click guard).
import "../../../default/js/scripts";
import { jConfirm_confirm_options } from "./common";

import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../default/js/page-data";
import { ajax } from "../../../default/js/vendor/ajax";
import {
  attrOf,
  data,
  delegate,
  fadeTo,
  find,
  htmlOf,
  on,
  ready,
  removeData,
  setData,
  stop,
} from "../../../default/js/vendor/dom";
export {};

// GeoIp -- themes/admin/default/js/jquery.geoip.js, loaded via the
// same page's own combineScript() call (excluded from P46's own
// 61-file count, not converted yet -- ambient `declare const GeoIp`
// in build/jquery-plugins.d.ts stands in for it in the meantime).

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

// rating_user.latte's own `data-usr='{"uid":...,"aid":"..."}'` literal.
interface RatingUserCellData {
  uid: number;
  aid: string;
}

// Still jQuery: dataTables is a library, ported in P49-B group 7 (its live
// subset).
jQuery("#rateTable").dataTable({
  dom: '<"dtBar"filp>rt<"dtBar"ilp>',
  pageLength: 100,
  lengthMenu: [
    [25, 50, 100, 500, -1],
    [25, 50, 100, 500, "All"],
  ],
  sorting: [], //[[1,'desc']],
  autoWidth: false,
  sortClasses: false,
  columnDefs: [
    {
      aTargets: ["dtc_user"],
      sType: "string",
      sClass: null,
    },
    {
      aTargets: ["dtc_date"],
      asSorting: ["desc", "asc"],
      sType: "string",
      sClass: null,
    },
    {
      aTargets: ["dtc_stat"],
      asSorting: ["desc", "asc"],
      bSearchable: false,
      sType: "numeric",
      sClass: null,
    },
    {
      aTargets: ["dtc_rate"],
      asSorting: ["desc", "asc"],
      bSearchable: false,
      sType: "html",
      sClass: null,
    },
    {
      aTargets: ["dtc_del"],
      bSortable: false,
      bSearchable: false,
      sType: "string",
      sClass: null,
    },
  ],
});

// DataTables has no real type source (docs/PLAN.md's own confirmed-
// unresolvable vendor list) -- narrowed to the one real method this
// file actually calls, rather than left as the vendor's own bare `any`.
interface DataTableApi {
  row(selector: unknown): { remove(): { draw(): void } };
}
const oTable = jQuery("#rateTable").DataTable() as DataTableApi;

function uidFromCell(cell: HTMLElement): RatingUserCellData {
  let tr: HTMLElement = cell;
  while (tr.nodeName !== "TR") tr = tr.parentNode as HTMLElement;

  return data(tr, "usr") as RatingUserCellData;
}

// -----DELETE-----
ready(function () {
  // Delegated onto the stable #rateTable container, not bound directly to
  // .del elements: dataTables redraws (paging/sorting) replace the row
  // elements those direct bindings would be attached to.
  delegate(
    document.querySelectorAll("#rateTable"),
    "click",
    ".del",
    function (this: Element, event: Event): void {
      event.preventDefault();
      const title_msg = pwg_getPageString(
        'Are you sure you want to delete the ratings of the user "%s"?',
      );
      const confirm_msg = pwg_getPageString("Yes, I am sure");
      const cancel_msg = pwg_getPageString("No, I have changed my mind");
      const trAncestor = this.closest("tr");
      const usr_name =
        trAncestor === null ? "" : (htmlOf(find(trAncestor, ".usr")) ?? "");
      // Still jQuery: jquery-confirm is a library, ported in P49-B group 5.
      $.confirm({
        title: title_msg.replace("%s", usr_name),
        buttons: {
          confirm: {
            text: confirm_msg,
            btnClass: "btn-red",
            action: function () {
              const cell = (event.target as Element).parentNode as HTMLElement;
              let trElement: HTMLElement = cell;
              while (trElement.nodeName !== "TR")
                trElement = trElement.parentNode as HTMLElement;
              fadeTo(trElement, 1000, 0.4);
              const tr = trElement;
              const data = uidFromCell(cell);
              void ajax({
                url:
                  pwg_getPageData<string>("root_url") +
                  "api/v1/users/" +
                  data.uid +
                  "/actions/delete-ratings",
                method: "POST",
                contentType: "application/json",
                data: JSON.stringify({ anonymousId: data.aid || null }),
                headers: { "X-CSRF-Token": pwg_token },
                error: function (jqXHR) {
                  stop(tr);
                  fadeTo(tr, 0, 1);
                  alert(jqXHR.status + " " + jqXHR.statusText);
                },
                success: function (
                  result: operations["userDeleteRatings"]["responses"][200]["content"]["application/json"],
                ) {
                  if (result.deletedCount) oTable.row(tr).remove().draw();
                  else alert(result.deletedCount);
                },
              });
            },
          },
          cancel: {
            text: cancel_msg,
          },
        },
        ...jConfirm_confirm_options,
      });
    },
  );
});

interface GeoIpResult {
  fullName?: string;
  latitude?: number;
  longitude?: number;
  region_name?: string;
}

ready(function () {
  // Still jQuery: tooltip is a jQuery-UI widget (the same $.Widget factory
  // datepicker/sortable/slider use), ported alongside them in P49-B group 4.
  jQuery("#rateTable").tooltip({
    items: ".usr,[title]",
    content: function (this: HTMLElement, callback: (content: string) => void) {
      // jQuery-UI's own _updateContent() calls this with `this` already
      // bound to the raw target element (`contentOption.call(target[0],
      // ...)`), so no jQuery wrapping is needed here even though the
      // widget itself stays jQuery.
      // eslint-disable-next-line @typescript-eslint/no-this-alias -- needs to stay reachable inside the nested mouseleave/GeoIp callbacks below, which each have their own `this`.
      const el = this;
      const t = attrOf(el, "title");
      if (t) return t;
      const udata = uidFromCell(el);
      if (!udata.aid) return;
      setData(el, "isOver", true);
      on(
        el,
        "mouseleave",
        function (): void {
          removeData(el, "isOver");
        },
        { once: true },
      );

      GeoIp.get(udata.aid + ".1", function (geoData: GeoIpResult) {
        if (!geoData.fullName) return;
        let content = geoData.fullName;
        if (geoData.latitude && geoData.region_name) {
          content +=
            "<" +
            "br>" +
            "<" +
            'img width=300 height=220 src="http://maps.googleapis.com/maps/api/staticmap?sensor=false&size=300x220&zoom=6' +
            "&markers=size:tiny%7C" +
            geoData.latitude +
            "," +
            geoData.longitude +
            '">';
        }
        if (data(el, "isOver")) callback(content);
      });
    },
  });
});
