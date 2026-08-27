// Real consumer of scripts.ts's own top-level `phpWGOpenWindow`
// (docs/PLAN.md P48 -- was a bare ambient-global read, see that file's
// own leading comment for the full real-consumer list). This file
// itself becomes a real module as a result (previously non-module) --
// `window.SwitchBox`/`window._pwgRatingAutoQueue` below are a separate,
// already-established queue-based deferred-init pattern (P47's
// SwitchBoxQueue/RatingAutoQueue redesign), not a plain function
// exposure, and stay exactly as they are.
import { phpWGOpenWindow } from "./scripts";
// switchbox.ts's own side effect only (docs/PLAN.md P48) -- drains
// `window.SwitchBox`; this file is one of its 2 real pushers (the
// other is IndexView's own index.ts).
import "./switchbox";

import { pwg_getPageData, pwg_getPageString } from "./page-data";
import { ajax } from "./vendor/ajax";
import { css, ready } from "./vendor/dom";
export {};

function changeImgSrc(url: string, typeSave: string, typeMap: string): void {
  const theImg = document.getElementById(
    "theMainImage",
  ) as HTMLImageElement | null;
  if (theImg) {
    theImg.removeAttribute("width");
    theImg.removeAttribute("height");
    theImg.src = url;
    theImg.useMap = "#map" + typeMap;
  }
  css(
    document.querySelectorAll("#derivativeSwitchBox .switchCheck"),
    "visibility",
    "hidden",
  );
  const checked = document.getElementById("derivativeChecked" + typeMap);
  if (checked) {
    css(checked, "visibility", "visible");
  }
  document.cookie =
    "picture_deriv=" +
    typeSave +
    ";path=" +
    pwg_getPageData<string>("cookie_path");
}

const derivativeSwitchBox = document.getElementById("derivativeSwitchBox");
if (derivativeSwitchBox) {
  derivativeSwitchBox.addEventListener("click", function (e) {
    // eslint-disable-next-line @typescript-eslint/no-unnecessary-type-assertion -- tsc genuinely needs both casts here (confirmed directly against tsc: removing either produces a real TS2531/TS2339).
    const link = (e.target as HTMLElement).closest(
      "[data-derivative-url]",
    ) as HTMLElement | null;
    if (!link) {
      return;
    }
    e.preventDefault();
    changeImgSrc(
      link.dataset.derivativeUrl!,
      link.dataset.derivativeTypeSave!,
      link.dataset.derivativeTypeMap!,
    );
  });
}
(window.SwitchBox = window.SwitchBox || ([] as string[])).push(
  "#derivativeSwitchLink",
  "#derivativeSwitchBox",
);

const originalLink = document.getElementById("originalLink");
if (originalLink) {
  originalLink.addEventListener("click", function (e) {
    e.preventDefault();
    phpWGOpenWindow(
      originalLink.dataset.originalUrl!,
      "xxx",
      "scrollbars=yes,toolbar=no,status=no,resizable=yes",
    );
  });
}

ready(function () {
  if (document.getElementById("downloadSwitchBox")) {
    document.getElementById("downloadSwitchLink")?.removeAttribute("href");
    (window.SwitchBox = window.SwitchBox || ([] as string[])).push(
      "#downloadSwitchLink",
      "#downloadSwitchBox",
    );
  }
});

function addToCadie(
  aElement: HTMLAnchorElement & { disabled?: boolean },
  id: unknown,
): void {
  if (aElement.disabled) return;
  aElement.disabled = true;
  void ajax({
    url: pwg_getPageData<string>("root_url") + "api/v1/session/caddie",
    method: "POST",
    contentType: "application/json",
    data: JSON.stringify({ imageIds: [id] }),
    headers: { "X-CSRF-Token": pwg_getPageData<string>("csrf_token") },
    error: function (jqXHR) {
      alert(jqXHR.status + " " + jqXHR.statusText);
      document.location.href = aElement.href;
    },
    success: function (_result) {
      aElement.disabled = false;
    },
  });
}

const caddieLink = document.getElementById("caddieLink") as
  (HTMLAnchorElement & { disabled?: boolean }) | null;
if (caddieLink) {
  caddieLink.addEventListener("click", function (e) {
    e.preventDefault();
    addToCadie(caddieLink, pwg_getPageData<string | number>("image_id"));
  });
}

// `window.` prefix, not a bare reference: 2 real bugs found in sequence
// via VR against a real browser. First, a bare (undeclared) read of
// `_pwgRatingAutoQueue` threw ReferenceError whenever picture.ts was the
// first script on the page to touch it. Adding `var` "fixed" that but
// broke it a second way once every P46 entry got wrapped in its own
// `(function(){...})()` (see vite.config.ts's own banner/footer
// comment): a `var` *inside* that wrapper is scoped to the wrapper, no
// longer a real global at all, invisible to rating.ts's own separate
// wrapper. `window.` property access is safe from both problems at
// once -- reading a missing property never throws (unlike a bare
// undeclared identifier), and `window` itself is the one true global
// every wrapped entry can still reach directly.
window._pwgRatingAutoQueue = window._pwgRatingAutoQueue || [];
window._pwgRatingAutoQueue.push({
  rootUrl: pwg_getPageData<string>("root_url"),
  image_id: pwg_getPageData<string | number>("image_id"),
  onSuccess: function (rating: { score: number; count: number }) {
    let e: HTMLElement | null = document.getElementById("updateRate");
    if (e) e.innerHTML = pwg_getPageString("Update your rating");
    e = document.getElementById("ratingScore");
    if (e) e.innerHTML = String(rating.score);
    e = document.getElementById("ratingCount");
    if (e) {
      if (rating.count === 1) {
        e.innerHTML = ("(" + pwg_getPageString("%d rate") + ")").replace(
          "%d",
          String(rating.count),
        );
      } else {
        e.innerHTML = ("(" + pwg_getPageString("%d rates") + ")").replace(
          "%d",
          String(rating.count),
        );
      }
    }
  },
});
