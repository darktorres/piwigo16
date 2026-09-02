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
} from "./dom";

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
export interface TipTipOptions {
  keepAlive?: boolean;
  maxWidth?: string | number;
  edgeOffset?: number;
  defaultPosition?: "top" | "bottom" | "left" | "right";
  delay?: number;
  fadeIn?: number | string;
  fadeOut?: number | string;
  content?: string;
}

function toArray(target: Element | ArrayLike<Element>): Element[] {
  return target instanceof Element ? [target] : Array.from(target);
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
    const orgTitle = opts.content ? opts.content : (attrOf(el, "title") ?? "");
    if (orgTitle === "") {
      continue;
    }

    if (!opts.content) {
      removeAttr(el, "title");
    }

    let timeout: ReturnType<typeof setTimeout> | false = false;

    const activate = (): void => {
      tiptipContent.innerHTML = orgTitle;
      hide(tiptipHolder);
      removeAttr(tiptipHolder, "class");
      css(tiptipHolder, "margin", "0");
      removeAttr(tiptipArrow, "style");

      const top = Math.trunc(offset(el).top);
      const left = Math.trunc(offset(el).left);
      const orgWidth = Math.trunc(outerWidth(el));
      const orgHeight = Math.trunc(outerHeight(el));
      const tipW = outerWidth(tiptipHolder);
      const tipH = outerHeight(tiptipHolder);
      const wCompare = Math.round((orgWidth - tipW) / 2);
      const hCompare = Math.round((orgHeight - tipH) / 2);
      let margLeft = Math.round(left + wCompare);
      let margTop = Math.round(top + orgHeight + opts.edgeOffset);
      let tClass: string;
      let arrowTop = 0;
      let arrowLeft = Math.round(tipW - 12) / 2;

      if (opts.defaultPosition === "bottom") {
        tClass = "_bottom";
      } else if (opts.defaultPosition === "top") {
        tClass = "_top";
      } else if (opts.defaultPosition === "left") {
        tClass = "_left";
      } else {
        tClass = "_right";
      }

      const rightCompare = wCompare + left < Math.trunc(window.scrollX);
      const leftCompare = tipW + left > Math.trunc(windowWidth());

      if (
        (rightCompare && wCompare < 0) ||
        (tClass === "_right" && !leftCompare) ||
        (tClass === "_left" && left < tipW + opts.edgeOffset + 5)
      ) {
        tClass = "_right";
        arrowTop = Math.round(tipH - 13) / 2;
        arrowLeft = -12;
        margLeft = Math.round(left + orgWidth + opts.edgeOffset);
        margTop = Math.round(top + hCompare);
      } else if ((leftCompare && wCompare < 0) || (tClass === "_left" && !rightCompare)) {
        tClass = "_left";
        arrowTop = Math.round(tipH - 13) / 2;
        arrowLeft = Math.round(tipW);
        margLeft = Math.round(left - (tipW + opts.edgeOffset + 5));
        margTop = Math.round(top + hCompare);
      }

      const topCompare =
        top + orgHeight + opts.edgeOffset + tipH + 8 >
        Math.trunc(windowHeight() + window.scrollY);
      const bottomCompare = top + orgHeight - (opts.edgeOffset + tipH + 8) < 0;

      if (topCompare || (tClass === "_top" && !bottomCompare)) {
        tClass = tClass === "_top" || tClass === "_bottom" ? "_top" : `${tClass}_top`;
        arrowTop = tipH;
        margTop = Math.round(top - (tipH + 5 + opts.edgeOffset));
      } else if (bottomCompare || tClass === "_bottom") {
        tClass = tClass === "_top" || tClass === "_bottom" ? "_bottom" : `${tClass}_bottom`;
        arrowTop = -12;
        margTop = Math.round(top + orgHeight + opts.edgeOffset);
      }

      if (tClass === "_right_top" || tClass === "_left_top") {
        margTop += 5;
      } else if (tClass === "_right_bottom" || tClass === "_left_bottom") {
        margTop -= 5;
      }
      if (tClass === "_left_top" || tClass === "_left_bottom") {
        margLeft += 5;
      }

      css(tiptipArrow, { "margin-left": `${arrowLeft}px`, "margin-top": `${arrowTop}px` });
      css(tiptipHolder, { "margin-left": `${margLeft}px`, "margin-top": `${margTop}px` });
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
