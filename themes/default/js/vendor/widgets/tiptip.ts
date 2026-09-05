import {
  addClass,
  attrOf,
  css,
  fadeIn as domFadeIn,
  fadeOut as domFadeOut,
  hide,
  hover,
  offset,
  outerHeight,
  outerWidth,
  removeAttr,
  stop,
  windowHeight,
  windowWidth,
} from "../utils/dom";

/**
 * Port of jquery.tipTip.js v1.3 (real source read from
 * github:drewwilson/TipTip, pinned at #277e33629e). Every real call site
 * across this app uses the default `activation: "hover"` and never passes
 * `attribute`, `enter`, or `exit` -- so those, and the `focus`/`click`
 * activation modes, are not ported; only the hover-triggered, title-or-
 * `content`-sourced tooltip every real call site actually drives.
 *
 * `#tiptip_holder`/`#tiptip_content`/`#tiptip_arrow` are a page-wide
 * singleton the original creates once and reuses -- `maxWidth` is only
 * ever applied at that first creation, so on any real page the first
 * `tipTip()` call to run (not the last one written) wins for the whole
 * page's lifetime. Faithfully preserved: this is exactly how the jQuery
 * version already behaved, not something this port introduces.
 */
// 3 repeats (sonarjs/use-type-alias): TipTipOptions's own field, plus
// the position-computation helpers below.
type TipTipPosition = "top" | "bottom" | "left" | "right";

export interface TipTipOptions {
  keepAlive?: boolean;
  maxWidth?: string | number;
  edgeOffset?: number;
  defaultPosition?: TipTipPosition;
  delay?: number;
  fadeIn?: number | string;
  fadeOut?: number | string;
  content?: string;
}

function toArray(target: Element | ArrayLike<Element>): Element[] {
  return target instanceof Element ? [target] : Array.from(target);
}

interface TipTipPositionState {
  tClass: string;
  margLeft: number;
  margTop: number;
  arrowTop: number;
  arrowLeft: number;
}

/** Part of `computeTipTipPosition()`'s own extraction, below. */
function resolveInitialTipClass(
  defaultPosition: TipTipPosition,
): string {
  if (defaultPosition === "bottom") {
    return "_bottom";
  }
  if (defaultPosition === "top") {
    return "_top";
  }
  if (defaultPosition === "left") {
    return "_left";
  }
  return "_right";
}

/**
 * Part of `computeTipTipPosition()`'s own extraction, below -- flips
 * the tip to the opposite horizontal side when it would otherwise run
 * off the left/right edge of the window.
 */
function applyHorizontalOverflow(
  state: TipTipPositionState,
  left: number,
  top: number,
  tipW: number,
  tipH: number,
  orgWidth: number,
  wCompare: number,
  hCompare: number,
  edgeOffset: number,
): TipTipPositionState {
  const rightCompare = wCompare + left < Math.trunc(window.scrollX);
  const leftCompare = tipW + left > Math.trunc(windowWidth());

  if (
    (rightCompare && wCompare < 0) ||
    (state.tClass === "_right" && !leftCompare) ||
    (state.tClass === "_left" && left < tipW + edgeOffset + 5)
  ) {
    return {
      tClass: "_right",
      arrowTop: Math.round(tipH - 13) / 2,
      arrowLeft: -12,
      margLeft: Math.round(left + orgWidth + edgeOffset),
      margTop: Math.round(top + hCompare),
    };
  }
  if (
    (leftCompare && wCompare < 0) ||
    (state.tClass === "_left" && !rightCompare)
  ) {
    return {
      tClass: "_left",
      arrowTop: Math.round(tipH - 13) / 2,
      arrowLeft: Math.round(tipW),
      margLeft: Math.round(left - (tipW + edgeOffset + 5)),
      margTop: Math.round(top + hCompare),
    };
  }
  return state;
}

/**
 * Part of `computeTipTipPosition()`'s own extraction, below -- flips
 * (or corner-combines) the tip to the top/bottom when it would
 * otherwise run off the top/bottom edge of the window.
 */
function applyVerticalOverflow(
  state: TipTipPositionState,
  top: number,
  orgHeight: number,
  tipH: number,
  edgeOffset: number,
): TipTipPositionState {
  const topCompare =
    top + orgHeight + edgeOffset + tipH + 8 >
    Math.trunc(windowHeight() + window.scrollY);
  const bottomCompare = top + orgHeight - (edgeOffset + tipH + 8) < 0;

  if (topCompare || (state.tClass === "_top" && !bottomCompare)) {
    return {
      ...state,
      tClass:
        state.tClass === "_top" || state.tClass === "_bottom"
          ? "_top"
          : `${state.tClass}_top`,
      arrowTop: tipH,
      margTop: Math.round(top - (tipH + 5 + edgeOffset)),
    };
  }
  if (bottomCompare || state.tClass === "_bottom") {
    return {
      ...state,
      tClass:
        state.tClass === "_top" || state.tClass === "_bottom"
          ? "_bottom"
          : `${state.tClass}_bottom`,
      arrowTop: -12,
      margTop: Math.round(top + orgHeight + edgeOffset),
    };
  }
  return state;
}

/** Part of `computeTipTipPosition()`'s own extraction, below -- the final corner-class margin nudge. */
function applyCornerAdjustment(
  state: TipTipPositionState,
): TipTipPositionState {
  let { margTop, margLeft } = state;
  if (state.tClass === "_right_top" || state.tClass === "_left_top") {
    margTop += 5;
  } else if (state.tClass === "_right_bottom" || state.tClass === "_left_bottom") {
    margTop -= 5;
  }
  if (state.tClass === "_left_top" || state.tClass === "_left_bottom") {
    margLeft += 5;
  }
  return { ...state, margTop, margLeft };
}

/**
 * Part of `tipTip()`'s own `activate()` extraction, below -- the pure
 * "which side, and how far offset" positioning math, independent of
 * the actual DOM writes `activate()` itself still does.
 */
function computeTipTipPosition(
  el: HTMLElement,
  tiptipHolder: HTMLElement,
  defaultPosition: TipTipPosition,
  edgeOffset: number,
): TipTipPositionState {
  const top = Math.trunc(offset(el).top);
  const left = Math.trunc(offset(el).left);
  const orgWidth = Math.trunc(outerWidth(el));
  const orgHeight = Math.trunc(outerHeight(el));
  const tipW = outerWidth(tiptipHolder);
  const tipH = outerHeight(tiptipHolder);
  const wCompare = Math.round((orgWidth - tipW) / 2);
  const hCompare = Math.round((orgHeight - tipH) / 2);

  let state: TipTipPositionState = {
    tClass: resolveInitialTipClass(defaultPosition),
    margLeft: Math.round(left + wCompare),
    margTop: Math.round(top + orgHeight + edgeOffset),
    arrowTop: 0,
    arrowLeft: Math.round(tipW - 12) / 2,
  };

  state = applyHorizontalOverflow(
    state,
    left,
    top,
    tipW,
    tipH,
    orgWidth,
    wCompare,
    hCompare,
    edgeOffset,
  );
  state = applyVerticalOverflow(state, top, orgHeight, tipH, edgeOffset);
  state = applyCornerAdjustment(state);

  return state;
}

let holder: HTMLElement | null = null;
let contentEl: HTMLElement | null = null;
let arrowEl: HTMLElement | null = null;

function ensureHolder(maxWidth: string | number): {
  holder: HTMLElement;
  content: HTMLElement;
  arrow: HTMLElement;
} {
  if (holder === null) {
    holder = document.createElement("div");
    holder.id = "tiptip_holder";
    // `css()` appends "px" to a bare number the way jQuery's own `.css()`
    // does -- the original library's literal string-concatenated
    // `style="max-width:..."` left a bare number (history.ts's
    // `maxWidth: 320`) as invalid, browser-ignored CSS. Real-world impact
    // is nil either way (this only ever fires on the very first `tipTip()`
    // call any given page makes, which is never that one), but there is no
    // reason to keep the broken path when the shared helper already does
    // the right thing.
    css(holder, "max-width", maxWidth);

    contentEl = document.createElement("div");
    contentEl.id = "tiptip_content";

    const arrowInner = document.createElement("div");
    arrowInner.id = "tiptip_arrow_inner";
    arrowEl = document.createElement("div");
    arrowEl.id = "tiptip_arrow";
    arrowEl.appendChild(arrowInner);

    holder.appendChild(arrowEl);
    holder.appendChild(contentEl);
    document.body.appendChild(holder);
  }

  // eslint-disable-next-line @typescript-eslint/no-non-null-assertion -- holder/contentEl/arrowEl are always set together on the first real call, and never reset to null afterward.
  return { holder, content: contentEl!, arrow: arrowEl! };
}

export function tipTip(
  elements: Element | ArrayLike<Element>,
  options: TipTipOptions = {}
): void {
  const opts = {
    keepAlive: false,
    maxWidth: "200px",
    edgeOffset: 3,
    defaultPosition: "bottom" as const,
    delay: 400,
    fadeIn: 200,
    fadeOut: 200,
    ...options,
  };

  const { holder: tiptipHolder, content: tiptipContent, arrow: tiptipArrow } =
    ensureHolder(opts.maxWidth);

  for (const el of toArray(elements)) {
    if (!(el instanceof HTMLElement)) {
      continue;
    }

    // A missing `title` (as opposed to an empty one) is not a real case
    // any real call site hits -- every `.tiptip`-classed element this app
    // renders carries one server-side. The original library's own
    // behavior there is a latent bug, not an intentional fallback: jQuery's
    // `.html(undefined)` is a no-op (`value === undefined` reads as the
    // getter overload), so it would silently bind hover listeners that
    // show whatever the shared `#tiptip_content` last held for a
    // different element. Treating "no attribute" the same as "empty" --
    // skip binding entirely -- avoids replicating that rather than
    // faithfully reproducing it.
    const orgTitle =
      opts.content !== undefined && opts.content !== ""
        ? opts.content
        : (attrOf(el, "title") ?? "");
    if (orgTitle === "") {
      continue;
    }

    if (opts.content === undefined || opts.content === "") {
      removeAttr(el, "title");
    }

    let timeout: ReturnType<typeof setTimeout> | false = false;

    const activate = (): void => {
      tiptipContent.innerHTML = orgTitle;
      hide(tiptipHolder);
      removeAttr(tiptipHolder, "class");
      css(tiptipHolder, "margin", "0");
      removeAttr(tiptipArrow, "style");

      const { tClass, margLeft, margTop, arrowTop, arrowLeft } =
        computeTipTipPosition(
          el,
          tiptipHolder,
          opts.defaultPosition,
          opts.edgeOffset,
        );

      css(tiptipArrow, {
        "margin-left": `${arrowLeft}px`,
        "margin-top": `${arrowTop}px`,
      });
      css(tiptipHolder, {
        "margin-left": `${margLeft}px`,
        "margin-top": `${margTop}px`,
      });
      addClass(tiptipHolder, `tip${tClass}`);

      if (timeout !== false) {
        clearTimeout(timeout);
      }
      timeout = setTimeout(() => {
        stop(tiptipHolder, true, true);
        domFadeIn(tiptipHolder, opts.fadeIn);
      }, opts.delay);
    };

    const deactivate = (): void => {
      if (timeout !== false) {
        clearTimeout(timeout);
      }
      domFadeOut(tiptipHolder, opts.fadeOut);
    };

    // keepAlive: leaving the trigger does not hide the tooltip -- only
    // leaving the tooltip itself does, via the holder's own hover below.
    hover(el, activate, opts.keepAlive ? (): void => undefined : deactivate);

    if (opts.keepAlive) {
      hover(tiptipHolder, (): void => undefined, deactivate);
    }
  }
}
