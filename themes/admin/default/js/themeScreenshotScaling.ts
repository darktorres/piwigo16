import {
  css,
  innerHeight,
  innerWidth,
} from "../../../default/js/vendor/utils/dom";

/**
 * jQuery reads dimensions off the first element of a set and writes to
 * every one, and both halves are kept: the measurement comes from
 * `first`, the sizing goes to all of them. Shared by themes_installed.ts
 * and themes_new.ts, whose own copies of this were previously
 * near-identical (P51-H).
 */
export function scaleThemeScreenshot(themeBox: Element): void {
  const screenImages =
    themeBox.querySelectorAll<HTMLElement>(".preview-box img");
  const [first] = screenImages;
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
}
