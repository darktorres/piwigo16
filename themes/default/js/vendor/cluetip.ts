// Native port of clueTip (P49-B, `jquery-cluetip`), real source read
// from the vendored `jquery-cluetip@1.2.6` package (`node_modules/
// jquery-cluetip/jquery.cluetip.js`). Two real, live call sites --
// `install.ts`'s newsletter-subscribe span (`positionBy: "bottomTop"`)
// and `languages/new.ts`'s per-language external-link cells (the real
// default, `positionBy: "auto"`) -- both call `.cluetip({width: 300,
// splitTitle: "|"[, positionBy]})` on a `title="A|B"`-attributed
// element and nothing else: no `rel`-attribute/ajax/local content
// source, no click/focus activation (always hover), no arrows/sticky/
// mouseOutClose/tracking/hoverClass/truncate, and `multiple` is always
// its own real `false` default -- one shared tooltip element reused
// across every trigger, not one per call site. A third call site,
// `intro.ts`'s own `.cluetip()` registration, is genuinely dead: no
// `.cluetip`-classed markup exists anywhere on the admin intro page,
// statically or dynamically injected (its own newsletter-promo box
// uses `.tiptip`, already ported in P49-B group 2) -- removed outright
// (`intro.ts`'s call and `IntroView.php`'s CDN script registration)
// rather than ported as an always-no-op call.
//
// Narrowed hard to what these 2 real call sites actually reach:
// - Content is always the `title` attribute, split on `|` into a title
//   (before the first delimiter) and one or more body parts (after
//   it, each its own `.split-body` div) -- the `rel`-attribute/ajax/
//   local-element content sources are real, unreachable branches here
//   and aren't ported. The delimiter itself is hardcoded (`SPLIT_TITLE`
//   below): every real call site passes the same `"|"`, so exposing it
//   as a per-call option would have no real caller to serve.
// - Activation is always hover (`mouseenter`/`mouseleave`) -- `click`/
//   `focus` activation aren't ported.
// - `positionBy` is only ever the real default (`"auto"`, an unset
//   option) or `"bottomTop"` -- `"topBottom"`/`"fixed"`/`"mouse"` are
//   real, unreachable values and aren't ported (the "position by
//   mouse" fallback *inside* the `"auto"` branch, for a trigger too
//   wide for the viewport, is real reachable code within that branch
//   and stays).
// - `sticky`/`mouseOutClose`/`arrows`/`hoverClass`/`waitImage`/
//   `tracking`/`truncate`/`escapeTitle` are all real, always-off
//   defaults, never overridden by any real call site -- dropped.
//   `dropShadow`'s real effect (every real target browser supports
//   `box-shadow`, so the original's own old-browser div-based fallback
//   is dead here) collapses to one inline `box-shadow` style, set once.
// - `delayedClose`'s real default (50ms) is real, load-bearing
//   behavior -- a quick re-hover before the timer fires cancels the
//   pending hide instead of flickering -- and is ported.
// - `showTitle`'s real default (`true`, never overridden) means the
//   title bar is always shown, falling back to `&nbsp;` when the split
//   produces an empty title -- ported for parity even though every
//   real title here is non-empty.
//
// The shared tooltip is kept permanently `display: block` and toggled
// via `visibility` instead of the original's own `.hide()`/`.show()`
// (`display: none`/`''`) -- a deliberate simplification, not a literal
// translation: it needs to be measurable (`offsetHeight`) at any time
// to compute its own vertical position, and `visibility: hidden` stays
// measurable while `display: none` would not, without needing the
// original's own internal "temporarily unhide to measure" trick.
//
// No CSS asset is registered for this anywhere today (`jquery.cluetip.
// css` was never loaded by any View) -- theme.css's own `.cluetip-
// outer`/`.cluetip-title`/`.cluetip-inner` rules, plus this module's
// own inline `position`/`display`/`box-shadow`, are the entire real
// styling surface; this port changes nothing there. Real source's own
// `cluetipPadding` (computed CSS padding of the outer wrapper, added
// into `tipWidth`) is hardcoded to 0 here: no stylesheet sets padding
// on `#cluetip`/`.cluetip` itself, only on the inner `.cluetip-title`/
// `.cluetip-inner` divs.
import {
  attr,
  attrOf,
  cssValue,
  hover,
  offset,
  outerHeight,
  innerWidth,
  windowWidth,
  windowHeight,
} from "./dom";

export interface ClueTipOptions {
  width: number;
  positionBy?: "bottomTop";
}

const SPLIT_TITLE = "|";
const TOP_OFFSET = 15;
const LEFT_OFFSET = 15;
const Z_INDEX = 97;
const DROP_SHADOW_STEPS = 6;
const DELAYED_CLOSE_MS = 50;
const STANDARD_CLASSES = "cluetip ui-widget ui-widget-content ui-cluetip";

function toArray(target: Element | ArrayLike<Element>): Element[] {
  return target instanceof Element ? [target] : Array.from(target);
}

interface ClueTipContent {
  title: string;
  bodyParts: string[];
  originalTitleAttr: string;
}

const contentByElement = new WeakMap<Element, ClueTipContent>();

let tipEl: HTMLDivElement | undefined;
let titleEl: HTMLHeadingElement;
let innerEl: HTMLDivElement;
let closeTimer: ReturnType<typeof setTimeout> | undefined;

function ensureTip(): HTMLDivElement {
  if (tipEl) {
    return tipEl;
  }

  tipEl = document.createElement("div");
  tipEl.id = "cluetip";
  tipEl.style.position = "absolute";
  tipEl.style.visibility = "hidden";
  tipEl.style.boxShadow = "1px 1px 6px rgba(0, 0, 0, 0.5)";

  const outerEl = document.createElement("div");
  outerEl.className = "cluetip-outer";
  outerEl.style.position = "relative";
  outerEl.style.zIndex = String(Z_INDEX);

  titleEl = document.createElement("h3");
  titleEl.className = "cluetip-title";

  innerEl = document.createElement("div");
  innerEl.className = "cluetip-inner";

  outerEl.append(titleEl, innerEl);

  const extraEl = document.createElement("div");
  extraEl.className = "cluetip-extra";

  tipEl.append(outerEl, extraEl);
  document.body.append(tipEl);
  return tipEl;
}

function scheduleClose(): void {
  if (closeTimer !== undefined) {
    clearTimeout(closeTimer);
  }
  closeTimer = setTimeout(() => {
    if (tipEl) {
      tipEl.style.visibility = "hidden";
    }
    closeTimer = undefined;
  }, DELAYED_CLOSE_MS);
}

function activate(
  link: HTMLElement,
  content: ClueTipContent,
  width: number,
  positionBy: "auto" | "bottomTop",
  event: MouseEvent
): void {
  const tip = ensureTip();

  if (closeTimer !== undefined) {
    clearTimeout(closeTimer);
    closeTimer = undefined;
  }

  tip.style.width = `${width}px`;

  const linkTop = offset(link).top;
  const linkLeft = offset(link).left;
  const linkWidth = innerWidth(link);
  const mouseX = event.pageX;
  const mouseY = event.pageY;

  const winWidth = windowWidth();
  const wHeight = windowHeight();
  const sTop = window.scrollY;
  const baseline = sTop + wHeight;

  const tipWidth = width + DROP_SHADOW_STEPS;

  let posX =
    (linkWidth > linkLeft && linkLeft > tipWidth) ||
    linkLeft + linkWidth + tipWidth + LEFT_OFFSET > winWidth
      ? linkLeft - tipWidth - LEFT_OFFSET
      : linkWidth + linkLeft + LEFT_OFFSET;

  if (linkWidth + tipWidth > winWidth) {
    if (mouseX + 20 + tipWidth > winWidth) {
      const marginLeft = parseFloat(cssValue(tip, "margin-left")) || 0;
      const innerMarginRight = parseFloat(cssValue(innerEl, "margin-right")) || 0;
      posX =
        mouseX - tipWidth - LEFT_OFFSET >= 0
          ? mouseX - tipWidth - LEFT_OFFSET - marginLeft + innerMarginRight
          : mouseX - tipWidth / 2;
    } else {
      posX = mouseX + LEFT_OFFSET;
    }
  }

  const pY = posX < 0 ? mouseY + TOP_OFFSET : mouseY;
  if (posX < 0 || positionBy === "bottomTop") {
    posX = mouseX + tipWidth / 2 > winWidth ? winWidth / 2 - tipWidth / 2 : Math.max(mouseX - tipWidth / 2, 0);
  }

  tip.style.left = `${posX}px`;
  tip.style.zIndex = String(Z_INDEX);

  const titleHtml = content.title || "&nbsp;";
  titleEl.style.display = "";
  titleEl.innerHTML = titleHtml;

  innerEl.innerHTML = "";
  content.bodyParts.forEach((part) => {
    const div = document.createElement("div");
    div.className = "split-body";
    div.innerHTML = part;
    innerEl.append(div);
  });

  const tipHeight = outerHeight(tip);
  const insufficientX = posX < mouseX && Math.max(posX, 0) + tipWidth > mouseX;

  let direction: "top" | "bottom" | "left" | "right" | "" = "";
  let tipY: number;
  if (positionBy === "bottomTop" || insufficientX) {
    direction =
      linkTop + tipHeight + TOP_OFFSET > baseline && mouseY - sTop > tipHeight + TOP_OFFSET ? "top" : "bottom";
    tipY = direction === "top" ? mouseY - tipHeight - TOP_OFFSET : mouseY + TOP_OFFSET;
  } else if (linkTop + tipHeight + TOP_OFFSET > baseline) {
    tipY = tipHeight >= wHeight ? sTop : baseline - tipHeight - TOP_OFFSET;
  } else if (cssValue(link, "display") === "block") {
    tipY = pY - TOP_OFFSET;
  } else {
    tipY = linkTop - DROP_SHADOW_STEPS;
  }
  if (direction === "") {
    direction = posX < linkLeft ? "left" : "right";
  }

  tip.style.top = `${tipY}px`;
  tip.className = `${STANDARD_CLASSES} clue-${direction}-default cluetip-default`;
  tip.style.visibility = "visible";
}

export function cluetip(elements: Element | ArrayLike<Element>, options: ClueTipOptions): void {
  const {width} = options;
  const positionBy = options.positionBy ?? "auto";

  // Real source creates its own shared `#cluetip` div the first time
  // `.cluetip()` is ever called on a page, not lazily on first hover --
  // eagerly matched here rather than deferred into `activate()`.
  ensureTip();

  for (const el of toArray(elements)) {
    if (!(el instanceof HTMLElement)) {
      continue;
    }

    const rawTitle = attrOf(el, "title") ?? "";
    const parts = rawTitle.split(SPLIT_TITLE);
    const title = parts.shift() ?? "";
    const content: ClueTipContent = { title, bodyParts: parts, originalTitleAttr: rawTitle };
    contentByElement.set(el, content);

    hover(
      el,
      (event) => {
        attr(el, "title", "");
        // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- hover()'s own "mouseenter" binding always dispatches a real MouseEvent; its handler param is typed generically via the native EventListener interface.
        activate(el, content, width, positionBy, event as MouseEvent);
      },
      () => {
        attr(el, "title", content.originalTitleAttr);
        scheduleClose();
      }
    );
  }
}
