import { pwg_getPageData } from "../../../default/js/page-data";
import { css, innerHeight, innerWidth } from "../../../default/js/vendor/dom";

document
  .querySelectorAll<HTMLImageElement>(".themeShotImg")
  .forEach(function (img) {
    img.addEventListener("error", function () {
      img.src = pwg_getPageData<string>("default_screenshot");
    });
  });

// `load`, not `ready`: the whole point is to run once the screenshots have
// their intrinsic dimensions, which is after DOMContentLoaded. A deferred
// module still registers this in time -- `load` is the one event that fires
// after every deferred script has run.
window.addEventListener("load", function () {
  document.querySelectorAll(".themeBox").forEach(function (themeBox) {
    // jQuery reads dimensions off the first element of a set and writes to
    // every one, and both halves are kept: the measurement comes from
    // `first`, the sizing goes to all of them.
    const screenImages =
      themeBox.querySelectorAll<HTMLElement>(".preview-box img");
    const first = screenImages[0];
    const previewBox = themeBox.querySelector<HTMLElement>(".preview-box");
    if (first === undefined || previewBox === null) {
      return;
    }

    const imageW = innerWidth(first);
    const imageH = innerHeight(first);
    const size = innerWidth(previewBox);

    if (imageW > imageH) {
      css(screenImages, "height", String(size) + "px");
      css(screenImages, "width", String((imageW * size) / imageH) + "px");
    } else {
      css(screenImages, "width", String(size) + "px");
      // "heigth" (sic) -- a genuine pre-existing typo in the original .js,
      // preserved rather than fixed: jQuery.css() silently no-ops on an
      // unrecognized property, so this has always been a dead statement.
      // Correcting it would resize the screenshots, which is a behaviour
      // change and not this phase's business.
      css(screenImages, "heigth", String((imageH * size) / imageW) + "px");
    }
  });
});
