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

// ── Namespaced events ────────────────────────────────────────────────────
//
// jQuery splits an event spec on dots: everything before the first dot is the
// type, the rest are namespaces (src/event.js). `.off("click.apply")` removes
// only that namespace's handlers, and `.off(".apikey")` removes every type
// carrying it. `removeEventListener` has no equivalent -- it needs the exact
// handler reference -- so ported naively these calls become no-ops that leak
// handlers, or over-broad removals that kill unrelated listeners.
//
// It is also how jqtree's `tree.open`/`tree.close`/`tree.move` work: those
// are type `tree` with namespaces `open`/`close`/`move`, not literal types.
// Binding and triggering agree because both go through this parsing.

interface EventSpec {
  type: string;
  namespaces: string[];
}

interface Registration extends EventSpec {
  handler: EventListener;
  wrapped: EventListener;
}

const eventRegistry = new WeakMap<EventTarget, Registration[]>();

/** Namespaces this synthetic event was triggered with, if any. */
const triggeredNamespaces = new WeakMap<Event, string[]>();

export function parseEventSpec(spec: string): EventSpec {
  const [type = "", ...namespaces] = spec.split(".");

  return {
    type,
    namespaces: namespaces.filter((n) => n !== "").sort(),
  };
}

/**
 * jQuery fires a handler when the *triggered* namespaces are all present on
 * the handler -- so `.trigger("tree.open")` reaches a handler bound as
 * `tree.open` but not one bound as plain `tree`, and an untriggered native
 * event (no namespaces) reaches every handler of that type.
 */
function namespacesMatch(handlerNs: string[], firedNs: string[]): boolean {
  return firedNs.every((n) => handlerNs.includes(n));
}

/** `$(el).on("click.ns", handler)`. */
export function on(
  target: EventTarget,
  spec: string,
  handler: EventListener,
  options?: AddEventListenerOptions
): void {
  const { type, namespaces } = parseEventSpec(spec);
  if (type === "") {
    return;
  }

  const wrapped: EventListener = (event) => {
    const fired = triggeredNamespaces.get(event) ?? [];
    if (namespacesMatch(namespaces, fired)) {
      handler.call(target, event);
    }
  };

  const registrations = eventRegistry.get(target) ?? [];
  registrations.push({
    type,
    namespaces,
    handler,
    wrapped,
  });
  eventRegistry.set(target, registrations);

  target.addEventListener(type, wrapped, options);
}

/**
 * `$(el).off("click.ns")` / `.off(".ns")` / `.off("click")`.
 *
 * An empty type matches every type (the `.ns` form); an empty namespace list
 * matches every namespace. Passing `handler` narrows further, for the
 * off-then-on replace idiom used at several call sites.
 */
export function off(
  target: EventTarget,
  spec: string,
  handler?: EventListener
): void {
  const { type, namespaces } = parseEventSpec(spec);
  const registrations = eventRegistry.get(target);
  if (registrations === undefined) {
    return;
  }

  const kept: Registration[] = [];
  for (const registration of registrations) {
    const typeMatches = type === "" || registration.type === type;
    const namespaceMatches = namespacesMatch(registration.namespaces, namespaces);
    const handlerMatches = handler === undefined || registration.handler === handler;

    if (typeMatches && namespaceMatches && handlerMatches) {
      target.removeEventListener(registration.type, registration.wrapped);
    } else {
      kept.push(registration);
    }
  }

  eventRegistry.set(target, kept);
}

/**
 * `$(el).trigger("tree.open", detail)` -- dispatches a bubbling event whose
 * namespaces gate which handlers run, matching jQuery.
 */
export function trigger(
  target: EventTarget,
  spec: string,
  detail?: unknown
): void {
  const { type, namespaces } = parseEventSpec(spec);
  if (type === "") {
    return;
  }

  const event = new CustomEvent(type, {
    bubbles: true,
    cancelable: true,
    detail,
  });
  triggeredNamespaces.set(event, namespaces);
  target.dispatchEvent(event);
}

// ── Animation: the fx queue and tween engine ─────────────────────────────
//
// 151 first-party calls across 7 methods, plus .delay() (15) and .stop()
// (11), and none of them has a native one-liner. Ported from
// node_modules/jquery/src/effects.js and effects/Tween.js.
//
// Animations queue per element rather than running concurrently, which is
// what makes the dominant real idiom work:
//
//   $(x).stop(false, true); $(x).delay(1500).fadeOut(2500);
//
// -- jump any running animation to its end without dropping what is queued
// behind it, then queue a wait followed by a fade.

const FX_SPEEDS: Record<string, number> = {
  slow: 600,
  fast: 200,
  _default: 400,
};

/** jQuery's own tick interval (`jQuery.fx.interval`), not rAF. */
const FX_INTERVAL = 13;

/** Properties jQuery treats as unitless (`jQuery.cssNumber`). */
const CSS_NUMBER = new Set([
  "opacity",
  "zIndex",
  "fontWeight",
  "lineHeight",
  "order",
  "zoom",
]);

/** jQuery's default easing (`effects/Tween.js`). */
export function swing(p: number): number {
  return 0.5 - Math.cos(p * Math.PI) / 2;
}

export function resolveDuration(duration?: number | string): number {
  if (typeof duration === "number") {
    return duration;
  }
  if (typeof duration === "string" && duration in FX_SPEEDS) {
    return FX_SPEEDS[duration] as number;
  }

  return FX_SPEEDS._default as number;
}

type QueueStep = (next: () => void) => void;

interface Stoppable {
  stop(jumpToEnd: boolean): void;
}

interface FxState {
  queue: QueueStep[];
  running: Stoppable | null;
}

const fxStore = new WeakMap<Element, FxState>();

function fxState(el: Element): FxState {
  let state = fxStore.get(el);
  if (state === undefined) {
    state = {
      queue: [],
      running: null,
    };
    fxStore.set(el, state);
  }

  return state;
}

function runNext(el: Element): void {
  const state = fxState(el);
  state.running = null;
  const step = state.queue.shift();
  if (step === undefined) {
    return;
  }
  step(() => {
    runNext(el);
  });
}

function enqueue(el: Element, step: QueueStep): void {
  const state = fxState(el);
  state.queue.push(step);
  if (state.running === null && state.queue.length === 1) {
    runNext(el);
  }
}

const NUMBER_UNIT = /^([+-]?(?:\d*\.)?\d+)([a-z%]*)$/i;

function currentValue(el: HTMLElement, prop: string): number {
  const raw = el.ownerDocument.defaultView
    ?.getComputedStyle(el)
    .getPropertyValue(cssPropertyName(prop));

  return parseFloat(raw ?? "") || 0;
}

function cssPropertyName(prop: string): string {
  return prop.replace(/[A-Z]/g, (c) => "-" + c.toLowerCase());
}

function setStyleValue(
  el: HTMLElement,
  prop: string,
  value: number,
  unit: string
): void {
  el.style.setProperty(cssPropertyName(prop), value + unit);
}

/**
 * jQuery's own unit reconciliation (`effects.js`'s `"*"` tweener): when the
 * target's unit differs from the computed one, it iteratively scales a
 * nonzero starting point until the ratio settles, capped at 20 iterations.
 * Ported rather than replaced by direct arithmetic -- the reference box for a
 * percentage differs per property, and this makes no assumption about it.
 */
function startValueInUnit(
  el: HTMLElement,
  prop: string,
  target: number,
  unit: string
): number {
  const computedRaw = el.ownerDocument.defaultView
    ?.getComputedStyle(el)
    .getPropertyValue(cssPropertyName(prop));
  const parsed = NUMBER_UNIT.exec((computedRaw ?? "").trim());
  const computedUnit = parsed?.[2] ?? "";

  if (parsed === null || computedUnit === unit) {
    return parseFloat(parsed?.[1] ?? "") || 0;
  }

  let start = parseFloat(parsed[1] ?? "") || target || 1;
  let scale = 1;
  let iterations = 20;
  const original = el.style.getPropertyValue(cssPropertyName(prop));

  let previous: number;
  do {
    scale = scale === 0 ? 0.5 : scale;
    start = start / scale;
    setStyleValue(el, prop, start, unit);
    previous = scale;
    scale = target === 0 ? 1 : currentValue(el, prop) / target;
    iterations -= 1;
  } while (previous !== scale && scale !== 1 && iterations > 0);

  el.style.setProperty(cssPropertyName(prop), original);

  return start;
}

class Tween implements Stoppable {
  private readonly startedAt = Date.now();

  private timer: ReturnType<typeof setInterval> | null = null;

  public constructor(
    private readonly el: HTMLElement,
    private readonly props: {
      prop: string;
      from: number;
      to: number;
      unit: string;
    }[],
    private readonly duration: number,
    private readonly complete: (() => void) | undefined,
    private readonly done: () => void
  ) {}

  public start(): void {
    if (this.duration <= 0) {
      this.finish(true);

      return;
    }
    this.timer = setInterval(() => {
      this.tick();
    }, FX_INTERVAL);
  }

  private tick(): void {
    const elapsed = Date.now() - this.startedAt;
    const fraction = Math.min(1, elapsed / this.duration);
    const eased = swing(fraction);

    for (const { prop, from, to, unit } of this.props) {
      setStyleValue(this.el, prop, from + (to - from) * eased, unit);
    }

    if (fraction >= 1) {
      this.finish(true);
    }
  }

  public stop(jumpToEnd: boolean): void {
    if (this.timer !== null) {
      clearInterval(this.timer);
      this.timer = null;
    }
    this.finish(jumpToEnd);
  }

  private finish(applyEnd: boolean): void {
    if (this.timer !== null) {
      clearInterval(this.timer);
      this.timer = null;
    }
    if (applyEnd) {
      for (const { prop, to, unit } of this.props) {
        setStyleValue(this.el, prop, to, unit);
      }
      this.complete?.call(this.el);
    }
    this.done();
  }
}

/** `$(el).animate(props, duration, complete)`. */
export function animate(
  target: Element | ArrayLike<Element>,
  props: Record<string, number | string>,
  duration?: number | string,
  complete?: () => void
): void {
  const ms = resolveDuration(duration);

  for (const el of toElements(target)) {
    if (!(el instanceof HTMLElement)) {
      continue;
    }

    enqueue(el, (next) => {
      const tweens = Object.entries(props).map(([prop, value]) => {
        const parsed = NUMBER_UNIT.exec(String(value).trim());
        const to = parseFloat(parsed?.[1] ?? String(value)) || 0;
        const unit =
          (parsed?.[2] ?? "") || (CSS_NUMBER.has(prop) ? "" : "px");

        return {
          prop,
          from: startValueInUnit(el, prop, to, unit),
          to,
          unit,
        };
      });

      const tween = new Tween(el, tweens, ms, complete, next);
      fxState(el).running = tween;
      tween.start();
    });
  }
}

/** `$(el).delay(ms)` -- a queued wait, not a bare setTimeout. */
export function delay(target: Element | ArrayLike<Element>, ms: number): void {
  for (const el of toElements(target)) {
    enqueue(el, (next) => {
      const handle = setTimeout(next, ms);
      fxState(el).running = {
        stop: () => {
          clearTimeout(handle);
          next();
        },
      };
    });
  }
}

/**
 * `$(el).stop(clearQueue, jumpToEnd)`. The call sites use `.stop()` and
 * `.stop(false, true)`: the latter jumps the running animation to its end
 * and runs its completion callback, while leaving the queue behind it intact.
 */
export function stop(
  target: Element | ArrayLike<Element>,
  clearQueue = false,
  jumpToEnd = false
): void {
  for (const el of toElements(target)) {
    const state = fxState(el);
    if (clearQueue) {
      state.queue = [];
    }
    state.running?.stop(jumpToEnd);
  }
}
