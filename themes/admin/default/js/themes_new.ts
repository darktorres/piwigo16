import { scaleThemeScreenshot } from "./common";

import { pwg_getPageData } from "../../../default/js/page-data";

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
    scaleThemeScreenshot(themeBox);
  });
});
