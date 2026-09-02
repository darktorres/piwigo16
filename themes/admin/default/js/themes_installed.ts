import { pwg_jconfirm_follow_href } from "./common";

import { pwg_getPageString } from "../../../default/js/page-data";
import { colorbox } from "../../../default/js/vendor/colorbox";
import {
  attrOf,
  css,
  fadeOut,
  fadeToggle,
  hasClass,
  innerHeight,
  innerWidth,
  on,
  ready,
} from "../../../default/js/vendor/dom";
export {};

const confirm_msg = pwg_getPageString("Yes, I am sure");
const cancel_msg = pwg_getPageString("No, I have changed my mind");
document.querySelectorAll(".delete-theme-button").forEach(function (button) {
  const theme_name = attrOf(
    button.closest(".themeBox")?.querySelectorAll(".themeName") ?? [],
    "title",
  );
  const title = pwg_getPageString(
    'Are you sure you want to delete the theme "%s"?',
  );
  pwg_jconfirm_follow_href(button, {
    alert_title: title.replace("%s", theme_name!),
    alert_confirm: confirm_msg,
    alert_cancel: cancel_msg,
  });
});

ready(function () {
  colorbox(document.querySelectorAll("a.preview-box"));

  on(document, "mouseup", function (e: Event): void {
    e.stopPropagation();
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

    const screenImage = box.querySelectorAll<HTMLElement>(".preview-box img");
    const first = screenImage[0];
    const previewBox = box.querySelector<HTMLElement>(".preview-box");
    if (first === undefined || previewBox === null) {
      return;
    }

    const imageW = innerWidth(first);
    const imageH = innerHeight(first);
    const size = innerWidth(previewBox);

    if (imageW > imageH) {
      css(screenImage, "height", String(size) + "px");
      css(screenImage, "width", String((imageW * size) / imageH) + "px");
    } else {
      css(screenImage, "width", String(size) + "px");
      // "heigth" (sic) -- same genuine pre-existing typo already
      // preserved in themes_new.ts's own copy of this scaling logic;
      // jQuery.css() silently no-ops on an unrecognized property.
      css(screenImage, "heigth", String((imageH * size) / imageW) + "px");
    }
  });
});
