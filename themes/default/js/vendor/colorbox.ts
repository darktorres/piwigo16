// Native port of Colorbox (P49-B), real source read from the vendored
// `jquery.colorbox` package (`node_modules/jquery.colorbox/
// jquery.colorbox.js`, `github:jackmoore/colorbox#1.5.14`). 8 real call
// sites across 7 admin `.ts` files (`batch_manager/global.ts`,
// `picture_modify.ts`, `batch_manager/unit.ts`, `themes_installed.ts`,
// `configuration/main.ts`, `admin_help.ts`, `photos_add_applications.ts`,
// plus `addAlbum.ts`'s own `pwgAddAlbum()`), every one of them driving
// the SAME shared singleton box/overlay the original itself builds only
// once (`appendHTML()`'s own `if (!$box)` guard) -- this port keeps that
// architecture: one module-level instance, `colorbox()` just registers
// more trigger elements against it.
//
// Narrowed hard to what these 8 real call sites actually reach:
// - Modes: `photo` (forced via `{photo:true}` or auto-detected via the
//   real `photoRegex`), `inline` (`addAlbum.ts`'s own add-album popup,
//   the one real user of it), and the ajax/HTML fallback (`admin_help.ts`'s
//   `.help-popin`, whose href is a page URL, not an image) -- `iframe`/
//   `html`/`data` (ajax POST body) are real, unreachable modes and
//   aren't ported.
// - Grouping (`rel`): only `photos_add_applications.ts` ever passes a
//   `rel` (a constant `"group1"` shared by all 9 elements), so next/
//   prev/counter/loop/preloading are real, load-bearing behavior --
//   `slideshow` is a real, always-off default (never set `true` by any
//   call site) and isn't ported at all, marker element included.
// - `scalePhotos`/`retinaImage`/`retinaUrl`/`maxWidth`/`maxHeight`/
//   `innerWidth`/`innerHeight`/`top`/`bottom`/`left`/`right`/`fixed`/
//   `className` are never set by any real call site -- the original's
//   own photo-scaling and custom-positioning branches they gate are
//   dead code here and aren't ported; positioning is always the
//   original's own "center in the viewport" default.
// - `width`/`height` are real (`addAlbum.ts`: `650`/`"auto"`;
//   `admin_help.ts`: `"500px"`) -- `"auto"` is the original's own
//   `parseInt("auto",10) === NaN` accident that falls through to
//   measuring the loaded content directly; ported as an explicit
//   "unset" case instead of replicating the NaN arithmetic, same
//   observable result.
// - `onComplete` is the one real callback (`addAlbum.ts`'s own
//   re-init-once/reset-form/refocus dance) -- `onOpen`/`onLoad`/
//   `onCleanup`/`onClosed` are never used and aren't exposed.
// - `trapFocus`/`returnFocus`/`overlayClose`/`escKey`/`arrowKey`/
//   `closeButton`/`reposition`/`loop`/`preloading` are all real,
//   always-on defaults (never overridden) and are ported as
//   unconditional behavior rather than options.
// - Text (`current`/`previous`/`next`/`close`/`xhrError`/`imgError`) is
//   never overridden by any real call site -- kept as the original's
//   own hardcoded English literals rather than exposed as options.
//
// The "elastic" grow/reposition transition (real, default, never
// overridden to "fade"/"none") needs a continuous per-frame callback to
// resize the 3x3 grid's border strips alongside the animating box --
// `dom.ts`'s own `animate()` has no such per-tick hook, so this port
// hand-rolls a small `requestAnimationFrame` tween (`animateBox()`,
// reusing `swing()` for the same easing curve `dom.ts` already uses)
// rather than extending the shared helper for a need only this module
// has. The close fade (a plain opacity tween) has no such requirement
// and goes through `dom.ts`'s own `fadeTo()`/`stop()` directly.
//
// CSS is unchanged (kept on the CDN, `ColorboxView`'s own `pageAssets()`)
// -- every id/class this module creates (`#colorbox`, `#cboxOverlay`,
// `#cboxContent`, `.cboxPhoto`, `cboxElement`, ...) matches the
// original's own naming exactly so the existing stylesheet applies
// without modification.
import { ajax } from "./ajax";
import {
  fadeTo,
  height,
  outerHeight,
  outerWidth,
  stop,
  swing,
  width,
  windowHeight,
  windowWidth,
} from "./dom";

export interface ColorboxOptions {
  photo?: boolean;
  inline?: boolean;
  href?: string;
  rel?: string;
  width?: number | string;
  height?: number | string;
  onComplete?: () => void;
}

const ELEMENT_CLASS = "cboxElement";
const PHOTO_REGEX = /\.(gif|png|jp(e|g|eg)|bmp|ico|webp|jxr|svg)((#|\?).*)?$/i;
const OPACITY = 0.9;
const INITIAL_WIDTH = 600;
const INITIAL_HEIGHT = 450;
const SPEED = 300;
const FADE_OUT = 300;
const TEXT = {
  current: "image {current} of {total}",
  previous: "previous",
  next: "next",
  xhrError: "This content failed to load.",
  imgError: "This image failed to load.",
};

function toArray(target: Element | ArrayLike<Element>): Element[] {
  return target instanceof Element ? [target] : Array.from(target);
}

// ── Registration ────────────────────────────────────────────────────────

const optionsByElement = new WeakMap<Element, ColorboxOptions>();
const registeredElements: Element[] = [];

function getHref(el: Element, opts: ColorboxOptions): string {
  return opts.href ?? el.getAttribute("href") ?? "";
}

function getRel(el: Element, opts: ColorboxOptions): string {
  return opts.rel ?? el.getAttribute("rel") ?? "";
}

function getTitleText(el: Element): string {
  return el.getAttribute("title") ?? "";
}

function isImagePhoto(opts: ColorboxOptions, href: string): boolean {
  return opts.photo === true || PHOTO_REGEX.test(href);
}

function clickHandler(el: Element, event: MouseEvent): void {
  if (event.button > 0 || event.shiftKey || event.altKey || event.metaKey || event.ctrlKey) {
    return;
  }
  event.preventDefault();
  launch(el);
}

export function colorbox(
  elements: Element | ArrayLike<Element>,
  options: ColorboxOptions = {}
): void {
  ensureBox();

  for (const el of toArray(elements)) {
    if (!optionsByElement.has(el)) {
      registeredElements.push(el);
      el.addEventListener("click", (event) => {
        // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- "click" always dispatches a real MouseEvent; addEventListener()'s own handler param is typed generically via the native EventListener interface.
        clickHandler(el, event as MouseEvent);
      });
    }
    optionsByElement.set(el, options);
    el.classList.add(ELEMENT_CLASS);
  }
}

export function closeColorbox(): void {
  close();
}

// ── Shared box/overlay singleton ─────────────────────────────────────────

let boxBuilt = false;
let overlayEl: HTMLDivElement;
let boxEl: HTMLDivElement;
let wrapEl: HTMLDivElement;
let contentEl: HTMLDivElement;
let topBorderEl: HTMLDivElement;
let leftBorderEl: HTMLDivElement;
let rightBorderEl: HTMLDivElement;
let bottomBorderEl: HTMLDivElement;
let loadingBayEl: HTMLDivElement;
let loadingOverlayEl: HTMLDivElement;
let titleEl: HTMLDivElement;
let currentEl: HTMLDivElement;
let prevBtn: HTMLButtonElement;
let nextBtn: HTMLButtonElement;
let closeBtn: HTMLButtonElement;
let loadedEl: HTMLDivElement | undefined;

function tag(id: string): HTMLDivElement {
  const el = document.createElement("div");
  el.id = "cbox" + id;
  return el;
}

// ── Launch state ─────────────────────────────────────────────────────────

interface ActiveState {
  el: Element;
  opts: ColorboxOptions;
  related: Element[];
  index: number;
  w: number;
  h: number;
}

let current: ActiveState | undefined;
let isOpen = false;
let isBusy = false;
let isClosing = false;
let requestSeq = 0;
let interfaceWidth = 0;
let interfaceHeight = 0;
let loadedWidth = 0;
let loadedHeight = 0;
let lastFocusedEl: HTMLElement | null = null;
let loadingTimer: ReturnType<typeof setTimeout> | undefined;
let purgeCallbacks: (() => void)[] = [];

function buildBox(): void {
  if (boxBuilt) {
    return;
  }
  boxBuilt = true;

  boxEl = document.createElement("div");
  boxEl.id = "colorbox";
  boxEl.setAttribute("role", "dialog");
  boxEl.tabIndex = -1;
  boxEl.style.display = "none";

  overlayEl = tag("Overlay");
  overlayEl.style.display = "none";

  loadingOverlayEl = tag("LoadingOverlay");
  const loadingGraphicEl = tag("LoadingGraphic");

  wrapEl = tag("Wrapper");

  contentEl = tag("Content");
  contentEl.style.position = "relative";

  titleEl = tag("Title");
  currentEl = tag("Current");

  prevBtn = document.createElement("button");
  prevBtn.type = "button";
  prevBtn.id = "cboxPrevious";
  nextBtn = document.createElement("button");
  nextBtn.type = "button";
  nextBtn.id = "cboxNext";
  closeBtn = document.createElement("button");
  closeBtn.type = "button";
  closeBtn.id = "cboxClose";
  closeBtn.innerHTML = "close";

  contentEl.append(
    titleEl,
    currentEl,
    prevBtn,
    nextBtn,
    loadingOverlayEl,
    loadingGraphicEl,
    closeBtn
  );

  const topLeftEl = tag("TopLeft");
  topBorderEl = tag("TopCenter");
  const topRightEl = tag("TopRight");
  const topRow = document.createElement("div");
  topRow.append(topLeftEl, topBorderEl, topRightEl);

  leftBorderEl = tag("MiddleLeft");
  rightBorderEl = tag("MiddleRight");
  const middleRow = document.createElement("div");
  middleRow.style.clear = "left";
  middleRow.append(leftBorderEl, contentEl, rightBorderEl);

  const bottomLeftEl = tag("BottomLeft");
  bottomBorderEl = tag("BottomCenter");
  const bottomRightEl = tag("BottomRight");
  const bottomRow = document.createElement("div");
  bottomRow.style.clear = "left";
  bottomRow.append(bottomLeftEl, bottomBorderEl, bottomRightEl);

  wrapEl.append(topRow, middleRow, bottomRow);

  for (const el of [
    topLeftEl,
    topBorderEl,
    topRightEl,
    leftBorderEl,
    rightBorderEl,
    bottomLeftEl,
    bottomBorderEl,
    bottomRightEl,
  ]) {
    el.style.float = "left";
  }

  loadingBayEl = document.createElement("div");
  loadingBayEl.style.cssText =
    "position:absolute; width:9999px; visibility:hidden; display:none; max-width:none;";

  boxEl.append(wrapEl, loadingBayEl);

  prevBtn.addEventListener("click", () => {
    goPrev();
  });
  nextBtn.addEventListener("click", () => {
    goNext();
  });
  closeBtn.addEventListener("click", () => {
    close();
  });
  overlayEl.addEventListener("click", () => {
    close();
  });

  document.addEventListener("keydown", (event) => {
    if (!isOpen) {
      return;
    }
    if (event.key === "Escape") {
      event.preventDefault();
      close();
    } else if (
      current !== undefined &&
      current.related.length > 1 &&
      !event.altKey
    ) {
      if (event.key === "ArrowLeft") {
        event.preventDefault();
        goPrev();
      } else if (event.key === "ArrowRight") {
        event.preventDefault();
        goNext();
      }
    }
  });
}

function ensureBox(): void {
  buildBox();
  if (boxEl.parentElement === null) {
    document.body.append(overlayEl, boxEl);
  }
}

function computeRelated(
  el: Element,
  opts: ColorboxOptions
): { list: Element[]; index: number } {
  const rel = getRel(el, opts);
  let list: Element[];
  if (rel) {
    list = registeredElements.filter(
      (candidate) => getRel(candidate, optionsByElement.get(candidate) ?? {}) === rel
    );
    if (!list.includes(el)) {
      list = [...list, el];
    }
  } else {
    list = [el];
  }
  return { list, index: list.indexOf(el) };
}

function relatedAt(state: ActiveState, delta: number): Element {
  const max = state.related.length;
  const idx = ((state.index + delta) % max + max) % max;
  return state.related[idx]!;
}

function runPurge(): void {
  const callbacks = purgeCallbacks;
  purgeCallbacks = [];
  for (const cb of callbacks) {
    cb();
  }
}

function trapFocusHandler(event: FocusEvent): void {
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- a real focus event's own target inside the document is always a Node, never a bare EventTarget with no Node interface.
  const target = event.target as Node;
  if (!boxEl.contains(target) && target !== overlayEl) {
    event.stopPropagation();
    boxEl.focus();
  }
}

function onWindowResize(): void {
  if (current !== undefined) {
    positionBox(SPEED);
  }
}

/** Convert '%' and 'px' values to integers, `$.colorbox`'s own `setSize()`. */
function setSize(size: number | string, dimension: "x" | "y"): number {
  const str = String(size);
  const base = str.includes("%")
    ? (dimension === "x" ? windowWidth() : windowHeight()) / 100
    : 1;
  return Math.round(base * parseInt(str, 10));
}

/**
 * `"auto"` (the only non-numeric value any real call site passes, via
 * `height`) means "unset" here rather than replicating the original's
 * own `parseInt("auto",10) === NaN` fallthrough -- same observable
 * result (measure the loaded content), less surprising arithmetic.
 */
function parsedSize(
  size: number | string | undefined,
  dimension: "x" | "y"
): number | undefined {
  if (size === undefined || size === "auto") {
    return undefined;
  }
  const n = setSize(size, dimension);
  return Number.isNaN(n) ? undefined : n;
}

function makeErrorEl(message: string): HTMLDivElement {
  const el = document.createElement("div");
  el.id = "cboxError";
  el.innerHTML = message;
  return el;
}

// ── Elastic grow/reposition tween ─────────────────────────────────────────

interface BoxRect {
  top: number;
  left: number;
  width: number;
  height: number;
}

let boxRect: BoxRect | undefined;
let boxAnimFrame: number | undefined;

function applyBoxCss(box: BoxRect): void {
  boxEl.style.top = String(box.top) + "px";
  boxEl.style.left = String(box.left) + "px";
  boxEl.style.width = String(box.width) + "px";
  boxEl.style.height = String(box.height) + "px";
}

function modalDimensions(): void {
  const boxWidth = parseFloat(boxEl.style.width) || 0;
  const boxHeight = parseFloat(boxEl.style.height) || 0;
  const w = String(boxWidth - interfaceWidth) + "px";
  const h = String(boxHeight - interfaceHeight) + "px";
  topBorderEl.style.width = w;
  bottomBorderEl.style.width = w;
  contentEl.style.width = w;
  contentEl.style.height = h;
  leftBorderEl.style.height = h;
  rightBorderEl.style.height = h;
}

function animateBox(
  from: BoxRect,
  to: BoxRect,
  duration: number,
  onComplete: () => void
): void {
  if (boxAnimFrame !== undefined) {
    cancelAnimationFrame(boxAnimFrame);
    boxAnimFrame = undefined;
  }
  if (duration <= 0) {
    applyBoxCss(to);
    modalDimensions();
    onComplete();
    return;
  }
  const start = performance.now();
  const tick = (now: number): void => {
    const fraction = Math.min(1, (now - start) / duration);
    const eased = swing(fraction);
    applyBoxCss({
      top: from.top + (to.top - from.top) * eased,
      left: from.left + (to.left - from.left) * eased,
      width: from.width + (to.width - from.width) * eased,
      height: from.height + (to.height - from.height) * eased,
    });
    modalDimensions();
    if (fraction < 1) {
      boxAnimFrame = requestAnimationFrame(tick);
    } else {
      boxAnimFrame = undefined;
      onComplete();
    }
  };
  boxAnimFrame = requestAnimationFrame(tick);
}

function computeTargetBox(w: number, h: number): BoxRect {
  const totalWidth = w + loadedWidth + interfaceWidth;
  const totalHeight = h + loadedHeight + interfaceHeight;
  const left =
    window.scrollX + Math.round(Math.max(windowWidth() - totalWidth, 0) / 2);
  const top =
    window.scrollY + Math.round(Math.max(windowHeight() - totalHeight, 0) / 2);
  return { top, left, width: totalWidth, height: totalHeight };
}

function positionBox(speed: number, onDone?: () => void): void {
  window.removeEventListener("resize", onWindowResize);

  const target = computeTargetBox(current!.w, current!.h);
  const unchanged =
    boxRect?.width === target.width &&
    boxRect.height === target.height &&
    boxRect.top === target.top &&
    boxRect.left === target.left;
  const from = boxRect ?? { ...target, width: 0, height: 0 };

  boxEl.style.visibility = "visible";
  wrapEl.style.width = "9999px";
  wrapEl.style.height = "9999px";

  animateBox(from, target, unchanged ? 0 : speed, () => {
    boxRect = target;
    isBusy = false;

    wrapEl.style.width = String(target.width) + "px";
    wrapEl.style.height = String(target.height) + "px";

    setTimeout(() => {
      window.addEventListener("resize", onWindowResize);
    }, 1);

    onDone?.();
  });
}

// ── Open / navigate / load / close ────────────────────────────────────────

function launch(el: Element): void {
  if (isClosing) {
    return;
  }
  const opts = optionsByElement.get(el) ?? {};
  const related = computeRelated(el, opts);
  current = { el, opts, related: related.list, index: related.index, w: 0, h: 0 };

  if (!isOpen) {
    isOpen = true;
    isBusy = true;
    lastFocusedEl =
      document.activeElement instanceof HTMLElement
        ? document.activeElement
        : null;

    boxEl.style.visibility = "hidden";
    boxEl.style.display = "block";
    boxEl.style.opacity = "";

    const placeholder = document.createElement("div");
    placeholder.id = "cboxLoadedContent";
    placeholder.style.cssText =
      "width:0; height:0; overflow:hidden; visibility:hidden";
    contentEl.style.width = "";
    contentEl.style.height = "";
    loadedEl?.remove();
    loadedEl = placeholder;
    contentEl.prepend(placeholder);

    interfaceHeight =
      height(topBorderEl) +
      height(bottomBorderEl) +
      (outerHeight(contentEl, true) - height(contentEl));
    interfaceWidth =
      width(leftBorderEl) +
      width(rightBorderEl) +
      (outerWidth(contentEl, true) - width(contentEl));
    loadedHeight = outerHeight(placeholder, true);
    loadedWidth = outerWidth(placeholder, true);

    const initialWidth = setSize(INITIAL_WIDTH, "x");
    const initialHeight = setSize(INITIAL_HEIGHT, "y");
    current.w = initialWidth - loadedWidth - interfaceWidth;
    current.h = initialHeight - loadedHeight - interfaceHeight;

    placeholder.style.width = "";
    placeholder.style.height = String(current.h) + "px";

    positionBox(0);

    titleEl.style.display = "none";
    currentEl.style.display = "none";
    nextBtn.style.display = "none";
    prevBtn.style.display = "none";

    boxEl.focus();
    document.addEventListener("focus", trapFocusHandler, true);
  }

  overlayEl.style.opacity = String(OPACITY);
  overlayEl.style.cursor = "pointer";
  overlayEl.style.visibility = "visible";
  overlayEl.style.display = "block";

  load();
}

function load(): void {
  requestSeq += 1;
  const myRequest = requestSeq;
  isBusy = true;

  runPurge();

  const state = current!;
  const {opts} = state;
  const href = getHref(state.el, opts);

  const rawW = parsedSize(opts.width, "x");
  const rawH = parsedSize(opts.height, "y");
  state.w = rawW !== undefined ? rawW - loadedWidth - interfaceWidth : 0;
  state.h = rawH !== undefined ? rawH - loadedHeight - interfaceHeight : 0;

  loadingTimer = setTimeout(() => {
    loadingOverlayEl.style.display = "block";
  }, 100);

  if (opts.inline === true) {
    const target = href ? document.querySelector(href) : null;
    if (target !== null) {
      const placeholder = document.createElement("div");
      placeholder.style.display = "none";
      target.before(placeholder);
      purgeCallbacks.push(() => {
        placeholder.replaceWith(target);
      });
      prep(target);
    }
  } else if (isImagePhoto(opts, href)) {
    const img = new Image();
    img.className = "cboxPhoto";
    img.addEventListener("error", () => {
      prep(makeErrorEl(TEXT.imgError));
    });
    img.addEventListener("load", () => {
      if (myRequest !== requestSeq) {
        return;
      }
      setTimeout(() => {
        img.style.width = String(img.naturalWidth) + "px";
        img.style.height = String(img.naturalHeight) + "px";
        if (state.related.length > 1) {
          img.style.cursor = "pointer";
          img.addEventListener("click", () => {
            goNext();
          });
        }
        prep(img);
      }, 1);
    });
    img.src = href;
  } else if (href) {
    void (async () => {
      try {
        // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- ajax()'s own real return type is always Promise<unknown> regardless of its T (see vendor/ajax.ts's own AjaxThenable/decorate comment); the cast is the whole of what T means for an awaited call.
        const html = (await ajax({
          url: href,
          dataType: "html",
        })) as string;

        if (myRequest !== requestSeq) {
          return;
        }
        const container = document.createElement("div");
        container.innerHTML = html;
        prep(container);
      } catch {
        if (myRequest !== requestSeq) {
          return;
        }
        prep(makeErrorEl(TEXT.xhrError));
      }
    })();
  }
}

function prep(node: Node): void {
  if (!isOpen) {
    return;
  }
  const state = current!;

  loadedEl?.remove();
  const loaded = document.createElement("div");
  loaded.id = "cboxLoadedContent";
  loaded.appendChild(node);
  loadedEl = loaded;

  loaded.style.display = "none";
  loadingBayEl.style.display = "block";
  loadingBayEl.appendChild(loaded);

  const w = state.w || width(loaded);
  loaded.style.width = String(w) + "px";
  loaded.style.overflow = "auto";

  const h = state.h || height(loaded);
  loaded.style.height = String(h) + "px";

  contentEl.prepend(loaded);
  loadingBayEl.style.display = "none";

  if (node instanceof HTMLImageElement) {
    node.style.float = "none";
  }

  positionBox(SPEED, () => {
    if (!isOpen) {
      return;
    }

    titleEl.innerHTML = getTitleText(state.el);
    titleEl.style.display = "";
    loaded.style.display = "";

    const total = state.related.length;
    if (total > 1) {
      currentEl.innerHTML = TEXT.current
        .replace("{current}", String(state.index + 1))
        .replace("{total}", String(total));
      currentEl.style.display = "";

      nextBtn.style.display = "";
      nextBtn.innerHTML = TEXT.next;
      prevBtn.style.display = "";
      prevBtn.innerHTML = TEXT.previous;

      for (const rel of [relatedAt(state, -1), relatedAt(state, 1)]) {
        const relOpts = optionsByElement.get(rel) ?? {};
        const relHref = getHref(rel, relOpts);
        if (relHref && isImagePhoto(relOpts, relHref)) {
          new Image().src = relHref;
        }
      }
    } else {
      currentEl.style.display = "none";
      nextBtn.style.display = "none";
      prevBtn.style.display = "none";
    }

    clearTimeout(loadingTimer);
    loadingOverlayEl.style.display = "none";
    isBusy = false;
    state.opts.onComplete?.();
  });
}

function goNext(): void {
  if (isBusy || current === undefined || current.related.length < 2) {
    return;
  }
  launch(relatedAt(current, 1));
}

function goPrev(): void {
  if (isBusy || current === undefined || current.related.length < 2) {
    return;
  }
  launch(relatedAt(current, -1));
}

function close(): void {
  if (!isOpen || isClosing) {
    return;
  }
  isClosing = true;
  isOpen = false;

  if (boxAnimFrame !== undefined) {
    cancelAnimationFrame(boxAnimFrame);
    boxAnimFrame = undefined;
  }
  window.removeEventListener("resize", onWindowResize);
  document.removeEventListener("focus", trapFocusHandler, true);

  stop(overlayEl);
  fadeTo(overlayEl, FADE_OUT, 0);

  stop(boxEl);
  fadeTo(boxEl, FADE_OUT, 0, () => {
    boxEl.style.display = "none";
    overlayEl.style.display = "none";
    runPurge();
    loadedEl?.remove();
    loadedEl = undefined;

    setTimeout(() => {
      isClosing = false;
      lastFocusedEl?.focus();
    }, 1);
  });
}
