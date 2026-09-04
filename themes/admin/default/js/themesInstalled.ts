import { pwg_jconfirm_follow_href } from "./jconfirmPresets";
import { scaleThemeScreenshot } from "./themeScreenshotScaling";

import { pwg_getPageString } from "../../../default/js/pageData";
import { colorbox } from "../../../default/js/vendor/widgets/colorbox";
import {
  attrOf,
  fadeOut,
  fadeToggle,
  hasClass,
  on,
  ready,
} from "../../../default/js/vendor/utils/dom";

const confirmMsg = pwg_getPageString("Yes, I am sure");
const cancelMsg = pwg_getPageString("No, I have changed my mind");
document.querySelectorAll(".delete-theme-button").forEach(function (button) {
  const themeName = attrOf(
    button.closest(".themeBox")?.querySelectorAll(".themeName") ?? [],
    "title",
  );
  const title = pwg_getPageString(
    'Are you sure you want to delete the theme "%s"?',
  );
  pwg_jconfirm_follow_href(button, {
    alert_title: title.replace("%s", themeName!),
    alert_confirm: confirmMsg,
    alert_cancel: cancelMsg,
  });
});

ready(function () {
  colorbox(document.querySelectorAll("a.preview-box"));

  on(document, "mouseup", function (e: Event): void {
    e.stopPropagation();
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- a real mouseup event's own target inside the document is always an Element (or null), never a bare EventTarget with no Element interface.
    if (!hasClass(e.target as Element, "showInfo")) {
      fadeOut(document.querySelectorAll(".showInfo-dropdown"));
    }
  });
});

window.addEventListener("load", function () {
  document.querySelectorAll(".themeBox").forEach(function (box) {
    on(box.querySelectorAll(".showInfo"), "click", function (): void {
      // The original's own `if ($(this) !== dropdown)` guard here compared
      // two freshly-constructed jQuery wrapper objects by reference, which
      // is never true -- `$(x) !== $(x)` always, even wrapping the exact
      // same element -- so every `.showInfo-dropdown` (this box's own
      // included) was already unconditionally faded out here, before the
      // fadeToggle() below. Preserved as the unconditional fade it actually
      // is, not "fixed" into the filtered version the broken comparison
      // only looked like it intended: a real behaviour change, out of
      // this phase's scope.
      fadeOut(document.querySelectorAll(".showInfo-dropdown"));
      fadeToggle(box.querySelectorAll(".showInfo-dropdown"));
    });

    scaleThemeScreenshot(box);
  });
});
