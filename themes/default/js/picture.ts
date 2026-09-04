// Real consumer of scripts.ts's own top-level `phpWGOpenWindow`
// (docs/PLAN.md P48 -- was a bare ambient-global read, see that file's
// own leading comment for the full real-consumer list). This file
// itself becomes a real module as a result (previously non-module).
// `pushRatingAutoQueue()` below is a separate, still-real queue-based
// deferred-init pattern (P47's RatingAutoQueue design, moved off the
// ambient `window._pwgRatingAutoQueue` global onto a real shared
// module at P51-H) -- `window.SwitchBox`'s own former copy of that
// pattern retired outright instead (P51-H): both of this file's and
// index.ts's own pushes already ran after `import "./switchbox"`
// resolved, so its queue-drain branch could never see anything
// actually queued, unlike this one (picture.ts and rating.ts share no
// `import` edge with each other at all).
import { phpWGOpenWindow } from "./scripts";
import { registerSwitchBox } from "./switchbox";
import { pushRatingAutoQueue } from "./ratingAutoQueue";

import { pwg_getPageData, pwg_getPageString } from "./page-data";
import { ajax, AjaxError } from "./vendor/ajax";
import { css, ready } from "./vendor/dom";

function changeImgSrc(url: string, typeSave: string, typeMap: string): void {
  const theImg = document.querySelector<HTMLImageElement>("#theMainImage");
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
    // eslint-disable-next-line @typescript-eslint/no-unnecessary-type-assertion, @typescript-eslint/no-unsafe-type-assertion -- tsc genuinely needs both casts here (confirmed directly against tsc: removing either produces a real TS2531/TS2339); a real click inside the document always targets an HTMLElement.
    const link = (e.target as HTMLElement).closest(
      "[data-derivative-url]",
    ) as HTMLElement | null;
    if (!link) {
      return;
    }
    e.preventDefault();
    changeImgSrc(
      link.dataset["derivativeUrl"]!,
      link.dataset["derivativeTypeSave"]!,
      link.dataset["derivativeTypeMap"]!,
    );
  });
}
registerSwitchBox("#derivativeSwitchLink", "#derivativeSwitchBox");

const originalLink = document.getElementById("originalLink");
if (originalLink) {
  originalLink.addEventListener("click", function (e) {
    e.preventDefault();
    phpWGOpenWindow(
      originalLink.dataset["originalUrl"]!,
      "xxx",
      "scrollbars=yes,toolbar=no,status=no,resizable=yes",
    );
  });
}

ready(function () {
  if (document.getElementById("downloadSwitchBox")) {
    document.getElementById("downloadSwitchLink")?.removeAttribute("href");
    registerSwitchBox("#downloadSwitchLink", "#downloadSwitchBox");
  }
});

async function addToCadie(
  aElement: HTMLAnchorElement & { disabled?: boolean },
  id: unknown,
): Promise<void> {
  if (aElement.disabled === true) return;
  aElement.disabled = true;

  try {
    await ajax({
      url: pwg_getPageData<string>("root_url") + "api/v1/session/caddie",
      method: "POST",
      json: { imageIds: [id] },
      headers: { "X-CSRF-Token": pwg_getPageData<string>("csrf_token") },
    });

    aElement.disabled = false;
  } catch (e) {
    alert(
      e instanceof AjaxError
        ? String(e.status) + " " + e.statusText
        : String(e),
    );
    document.location.href = aElement.href;
  }
}

// eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- `disabled` isn't a real <a> property; this file's own addToCadie() sets it as a non-standard attribute-like flag, matching the original's real (if non-standard) behavior.
const caddieLink = document.getElementById("caddieLink") as
  (HTMLAnchorElement & { disabled?: boolean }) | null;
if (caddieLink) {
  caddieLink.addEventListener("click", function (e) {
    e.preventDefault();
    void addToCadie(caddieLink, pwg_getPageData<string | number>("image_id"));
  });
}

pushRatingAutoQueue({
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
