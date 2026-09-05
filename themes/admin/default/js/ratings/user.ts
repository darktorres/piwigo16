import type { operations } from "../../../../../openapi/client/schema";
// Folds scripts.ts's own code in via a direct import (docs/ PLAN.md
// P48) instead of the separate `core.scripts` script tag RatingUserView
// used to register directly -- this page doesn't read any of
// scripts.ts's own real exports, just needs its side effects
// (`popuphelp`/`pwg_tryFocus`'s own `window.X` assignments, the
// `[data-confirm]` click guard).
import "../../../../default/js/scripts";
import { jConfirmConfirmOptions } from "../jconfirmPresets";

import {
  pwg_getPageData,
  pwg_getPageString,
} from "../../../../default/js/pageData";
import { ajax, AjaxError } from "../../../../default/js/vendor/utils/ajax";
import { confirm } from "../../../../default/js/vendor/widgets/jconfirm";
import { dataTable } from "../../../../default/js/vendor/widgets/dataTable";
import { tooltip } from "../../../../default/js/vendor/widgets/tooltip";
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
} from "../../../../default/js/vendor/utils/dom";

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

// rating_user.latte's own `data-usr='{"uid":...,"aid":"..."}'` literal.
interface RatingUserCellData {
  uid: number;
  aid: string;
}

// Native port now (P49-C, `vendor/widgets/dataTable.ts`) -- the legacy
// aTargets/asSorting/bSearchable/bSortable/sType hungarian-notation
// option shape below is this file's own real, unmodified original
// options object, just re-typed against the vendor module's own
// (deliberately narrower) `DataTableColumnDef` shape.
// eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- the page's own "#rateTable" element is always real.
const rateTableEl = document.querySelector<HTMLTableElement>("#rateTable")!;
const oTable = dataTable(rateTableEl, {
  pageLength: 100,
  lengthMenu: [25, 50, 100, 500, -1],
  columnDefs: [
    { targetClass: "dtc_user" },
    { targetClass: "dtc_date", sortDirections: ["desc", "asc"] },
    {
      targetClass: "dtc_stat",
      sortDirections: ["desc", "asc"],
      searchable: false,
      type: "numeric",
    },
    {
      targetClass: "dtc_rate",
      sortDirections: ["desc", "asc"],
      searchable: false,
      type: "numeric",
    },
    { targetClass: "dtc_del", sortable: false, searchable: false },
  ],
});

function uidFromCell(cell: HTMLElement): RatingUserCellData {
  // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- every real cell passed here is always inside a real table row.
  const tr = cell.closest("tr")!;
  return data<RatingUserCellData>(tr, "usr");
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
      const titleMsg = pwg_getPageString(
        'Are you sure you want to delete the ratings of the user "%s"?',
      );
      const confirmMsg = pwg_getPageString("Yes, I am sure");
      const cancelMsg = pwg_getPageString("No, I have changed my mind");
      const trAncestor = this.closest("tr");
      const usrName =
        trAncestor === null ? "" : (htmlOf(find(trAncestor, ".usr")) ?? "");
      confirm({
        title: titleMsg.replace("%s", usrName),
        buttons: {
          confirm: {
            text: confirmMsg,
            btnClass: "btn-red",
            action: function () {
              // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion, @typescript-eslint/no-non-null-assertion -- a real click inside the document always targets an Element (or null), never a bare EventTarget with no Element interface; the ".del" link's own real parent cell always exists.
              const cell = (event.target as Element).parentElement!;
              // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- every real cell here is always inside a real table row.
              const trElement = cell.closest("tr")!;
              fadeTo(trElement, 1000, 0.4);
              const tr = trElement;
              const ids = uidFromCell(cell);
              void (async () => {
                try {
                  const result = await ajax<
                    operations["userDeleteRatings"]["responses"][200]["content"]["application/json"]
                  >({
                    url:
                      pwg_getPageData<string>("root_url") +
                      "api/v1/users/" +
                      String(ids.uid) +
                      "/actions/delete-ratings",
                    method: "POST",
                    json: { anonymousId: ids.aid || null },
                    headers: { "X-CSRF-Token": pwgToken },
                  });

                  if (result.deletedCount) oTable.row(tr).remove().draw();
                  else alert(result.deletedCount);
                } catch (e) {
                  stop(tr);
                  fadeTo(tr, 0, 1);
                  alert(
                    e instanceof AjaxError
                      ? String(e.status) + " " + e.statusText
                      : String(e),
                  );
                }
              })();
            },
          },
          cancel: {
            text: cancelMsg,
          },
        },
        ...jConfirmConfirmOptions,
      });
    },
  );
});

// GET /api/v1/geoip's own response shape -- the real replacement for
// jquery.geoip.js's client-side call to the long-dead freegeoip.net
// JSONP endpoint (docs/PLAN.md P49-B group 1's own finding). Same type
// history.ts's own copy of this call reads.
type GeoIpLookupResponse =
  operations["geoIpLookup"]["responses"][200]["content"]["application/json"];

ready(function () {
  // Native port now (P49-C, `vendor/widgets/tooltip.ts`) -- `items: ".usr,[title]"`
  // still relies on the vendor module's own real delegated binding (a
  // matching descendant at hover time, not at bind time) to keep working
  // after #rateTable's own dataTable() redraws its rows on
  // paging/sorting/filtering.
  tooltip(rateTableEl, {
    items: ".usr,[title]",
    content: function (this: HTMLElement, callback: (content: string) => void) {
      // jQuery-UI's own real `_updateContent()` (the source `vendor/
      // tooltip.ts` was ported from) calls this with `this` already bound
      // to the raw target element, which the native port's own `show()`
      // call site (`options.content.call(target, show)`) preserves as-is.
      // eslint-disable-next-line @typescript-eslint/no-this-alias -- needs to stay reachable inside the nested mouseleave/GeoIp callbacks below, which each have their own `this`.
      const el = this;
      const t = attrOf(el, "title");
      if (t !== undefined && t !== null && t !== "") return t;
      const udata = uidFromCell(el);
      if (!udata.aid) return undefined;
      setData(el, "isOver", true);
      on(
        el,
        "mouseleave",
        function (): void {
          removeData(el, "isOver");
        },
        { once: true },
      );

      void (async () => {
        try {
          const geoData = await ajax<GeoIpLookupResponse>({
            url: "api/v1/geoip",
            // `aid` is an anonymous rater's IP with its last octet
            // deliberately stripped for privacy (RateService::$anonymousId/
            // PictureRateRenderer::$anonymous_id), so this reconstructs a
            // plausible full IP -- good enough for a city-level lookup,
            // since city blocks are coarser than a single host anyway. Must
            // stay exactly this shape; it's the one piece of real logic in
            // this call site.
            type: "GET",
            dataType: "json",
            data: { ip: udata.aid + ".1" },
          });

          if (!geoData.available || geoData.fullName === undefined) return;
          let content = geoData.fullName;
          if (geoData.latitude != null && geoData.longitude != null) {
            content +=
              "<" +
              "br>" +
              "<" +
              'img width=300 height=220 src="http://maps.googleapis.com/maps/api/staticmap?sensor=false&size=300x220&zoom=6' +
              "&markers=size:tiny%7C" +
              String(geoData.latitude) +
              "," +
              String(geoData.longitude) +
              '">';
          }
          if (data(el, "isOver") === true) callback(content);
        } catch (e) {
          console.error(e instanceof AjaxError ? e.responseText : e);
        }
      })();
      return undefined;
    },
  });
});
