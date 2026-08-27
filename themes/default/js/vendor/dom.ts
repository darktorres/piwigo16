// Shared DOM helper for the jQuery removal (docs/PLAN.md P49).
//
// jQuery is not a thin wrapper over the DOM: a handful of its methods have
// semantics with no native equivalent, and 645 first-party call sites plus
// every ported library depend on them. Translating those literally is
// silently or loudly wrong, so they are implemented once here and everything
// else is built on top.
//
// Every behaviour below is taken from the pinned jQuery 1.11.3 source in
// node_modules/jquery/src -- the exact version this app loads -- not from
// jQuery's documentation or from memory.

const dataStore = new WeakMap<Element, Map<string, unknown>>();

/**
 * `data-*` attribute name for a key, following jQuery's own camelCase
 * mapping (`src/data.js`: `key.replace(rmultiDash, "-$1").toLowerCase()`),
 * so `fooBar` reads `data-foo-bar`.
 */
function attributeNameFor(key: string): string {
  return "data-" + key.replace(/([A-Z])/g, "-$1").toLowerCase();
}

/**
 * jQuery's own coercion ladder for a value read out of a `data-*` attribute
 * (`src/data.js`'s `dataAttr()`), reproduced in order: the three literals,
 * then a number *only if it round-trips exactly* (so `"007"` and `"1e1000"`
 * stay strings), then JSON for a value wrapped in braces or brackets, then
 * the raw string. A malformed JSON value falls back to the string rather
 * than throwing, matching the `try`/`catch` there.
 */
export function coerceDataAttribute(raw: string): unknown {
  if (raw === "true") return true;
  if (raw === "false") return false;
  if (raw === "null") return null;
  if (+raw + "" === raw) return +raw;

  if (/^(?:\{[\w\W]*\}|\[[\w\W]*\])$/.test(raw)) {
    try {
      return JSON.parse(raw) as unknown;
    } catch {
      return raw;
    }
  }

  return raw;
}

/**
 * `$(el).data(key)`.
 *
 * Deliberately NOT `el.dataset[key]`. jQuery reads the attribute at most
 * once and then caches the coerced value in an internal store ("Make sure we
 * set the data so it isn't changed later", `src/data.js`), so a later
 * attribute change is invisible; and the value it hands back is coerced, not
 * a string. Call sites read numbers and booleans straight out of this.
 */
export function data(el: Element, key: string): unknown {
  const store = dataStore.get(el);
  if (store !== undefined && store.has(key)) {
    return store.get(key);
  }

  const raw = el.getAttribute(attributeNameFor(key));
  if (raw === null) {
    return undefined;
  }

  const value = coerceDataAttribute(raw);
  setData(el, key, value);

  return value;
}

/**
 * `$(el).data(key, value)` -- writes to the store only. jQuery never writes
 * the `data-*` attribute back, so anything reading the DOM (CSS attribute
 * selectors, PHP-rendered markup, another script reading `getAttribute`)
 * keeps seeing the original value.
 */
export function setData(el: Element, key: string, value: unknown): void {
  let store = dataStore.get(el);
  if (store === undefined) {
    store = new Map<string, unknown>();
    dataStore.set(el, store);
  }
  store.set(key, value);
}

/** `$(el).removeData(key)` -- drops the cached value, leaving the attribute. */
export function removeData(el: Element, key: string): void {
  dataStore.get(el)?.delete(key);
}

// ── Visibility: show / hide ──────────────────────────────────────────────
//
// From node_modules/jquery/src/css.js's `showHide()` and
// src/css/defaultDisplay.js. `.hide()` and `.show()` are not
// `display:none`/`display:block`: jQuery remembers the display the element
// had and restores exactly that, so hiding and re-showing an inline element,
// a table row or a flex container gives back what it was, not `block`.
//
// The remembered value lives in jQuery's *internal* `_data` store, which is
// a different store from the public `.data()` above -- so a call site doing
// `.data("olddisplay")` never sees or clobbers it. Kept separate here for the
// same reason.
const internalStore = new WeakMap<Element, Map<string, unknown>>();

function internalGet(el: Element, key: string): unknown {
  return internalStore.get(el)?.get(key);
}

function internalSet(el: Element, key: string, value: unknown): void {
  let store = internalStore.get(el);
  if (store === undefined) {
    store = new Map<string, unknown>();
    internalStore.set(el, store);
  }
  store.set(key, value);
}

const defaultDisplayCache = new Map<string, string>();

function computedDisplay(el: Element): string {
  return el.ownerDocument.defaultView?.getComputedStyle(el).display ?? "";
}

/**
 * `jQuery.css(elem, "display") === "none" || !contains(document, elem)`
 * (src/css/var/isHidden.js). Note this is the *display*-based test used by
 * show/hide, not the offset-based `:visible` selector -- they disagree for a
 * `visibility: hidden` or zero-size element, and jQuery uses each in a
 * different place.
 */
export function isHiddenForDisplay(el: Element): boolean {
  return (
    computedDisplay(el) === "none" || !el.ownerDocument.contains(el)
  );
}

function actualDisplay(nodeName: string, doc: Document): string {
  const probe = doc.createElement(nodeName);
  doc.body.appendChild(probe);
  const display = doc.defaultView?.getComputedStyle(probe).display ?? "";
  probe.remove();

  return display;
}

/**
 * The browser's default display for a tag, cached per nodeName.
 *
 * The iframe fallback is jQuery's and is kept: when the page's own CSS sets
 * `display:none` on a bare element of that tag, probing in this document
 * yields "none", which would make `.show()` a no-op. A blank iframe has no
 * such stylesheet, so it yields the real UA default.
 */
export function defaultDisplay(nodeName: string): string {
  const cached = defaultDisplayCache.get(nodeName);
  if (cached !== undefined) {
    return cached;
  }

  let display = actualDisplay(nodeName, document);

  if (display === "none" || display === "") {
    const iframe = document.createElement("iframe");
    iframe.setAttribute("frameborder", "0");
    iframe.width = "0";
    iframe.height = "0";
    document.documentElement.appendChild(iframe);

    const frameDoc = iframe.contentWindow?.document;
    if (frameDoc !== undefined) {
      frameDoc.write("");
      frameDoc.close();
      display = actualDisplay(nodeName, frameDoc);
    }

    iframe.remove();
  }

  defaultDisplayCache.set(nodeName, display);

  return display;
}

function toElements(target: Element | ArrayLike<Element>): Element[] {
  return target instanceof Element ? [target] : Array.from(target);
}

/**
 * `showHide()` from src/css.js, two-pass structure and all: jQuery reads
 * every element's state first and only then writes, so a set of elements is
 * not measured against a layout its own earlier writes already changed.
 */
function showHide(target: Element | ArrayLike<Element>, show: boolean): void {
  const elements = toElements(target).filter(
    (el): el is HTMLElement => el instanceof HTMLElement
  );
  const values: (string | undefined)[] = [];

  elements.forEach((el, index) => {
    let remembered = internalGet(el, "olddisplay");
    const display = el.style.display;

    if (show) {
      // Clear an inline `none` first, to learn whether a stylesheet is what
      // is hiding this element.
      if (remembered === undefined && display === "none") {
        el.style.display = "";
      }
      if (el.style.display === "" && isHiddenForDisplay(el)) {
        remembered = defaultDisplay(el.nodeName);
        internalSet(el, "olddisplay", remembered);
      }
    } else {
      const hidden = isHiddenForDisplay(el);
      if ((display !== "" && display !== "none") || !hidden) {
        internalSet(el, "olddisplay", hidden ? display : computedDisplay(el));
      }
    }

    values[index] = typeof remembered === "string" ? remembered : undefined;
  });

  elements.forEach((el, index) => {
    if (!show || el.style.display === "none" || el.style.display === "") {
      el.style.display = show ? (values[index] ?? "") : "none";
    }
  });
}

/** `$(el).show()`. */
export function show(target: Element | ArrayLike<Element>): void {
  showHide(target, true);
}

/** `$(el).hide()`. */
export function hide(target: Element | ArrayLike<Element>): void {
  showHide(target, false);
}

/** `$(el).toggle()` / `.toggle(force)`. */
export function toggle(
  target: Element | ArrayLike<Element>,
  force?: boolean
): void {
  if (force !== undefined) {
    showHide(target, force);

    return;
  }

  for (const el of toElements(target)) {
    showHide(el, isHiddenForDisplay(el));
  }
}
