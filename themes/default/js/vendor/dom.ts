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
