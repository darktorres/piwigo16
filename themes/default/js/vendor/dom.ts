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
  if (String(Number(raw)) === raw) return Number(raw);

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
/**
 * The `:visible` pseudo-selector -- 33 first-party uses, and
 * `querySelector` throws a real `SyntaxError` on it, because it is not a
 * selector at all. jQuery computes it from layout
 * (`css/hiddenVisibleSelectors.js`): an element is hidden when it has no
 * box, which is true for `display: none` *and* for a zero-size element, and
 * false for `visibility: hidden`, which still occupies space.
 *
 * Deliberately not the same test as `isHiddenForDisplay()` above -- they
 * disagree on exactly those two cases, and jQuery uses each in a different
 * place. The `reliableHiddenOffsets()` half of the original is an old-IE
 * workaround and is dropped.
 */
export function isVisible(el: Element): boolean {
  if (!(el instanceof HTMLElement)) {
    return true;
  }

  return !(el.offsetWidth <= 0 && el.offsetHeight <= 0);
}

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
      frameDoc.open();
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
export function show(
  target: Element | ArrayLike<Element>,
  speed?: number | string | (() => void),
  complete?: () => void
): void {
  if (speed === undefined) {
    showHide(target, true);

    return;
  }

  runEffect(target, SHOW_PROPS, "show", speed, complete);
}

/** `$(el).hide()` / `.hide(duration)`. */
export function hide(
  target: Element | ArrayLike<Element>,
  speed?: number | string | (() => void),
  complete?: () => void
): void {
  if (speed === undefined) {
    showHide(target, false);

    return;
  }

  runEffect(target, SHOW_PROPS, "hide", speed, complete);
}

/**
 * `$(el).toggle()` / `.toggle(force)` / `.toggle(duration)`.
 *
 * jQuery overloads all three on one argument, and distinguishes them exactly
 * as this does (effects.js: `speed == null || typeof speed === "boolean"`
 * takes the instant path, anything else animates). A boolean is a forced
 * state, a number or string is a duration.
 */
export function toggle(
  target: Element | ArrayLike<Element>,
  speedOrForce?: boolean | number | string | (() => void),
  complete?: () => void
): void {
  if (typeof speedOrForce === "boolean") {
    showHide(target, speedOrForce);

    return;
  }

  if (speedOrForce !== undefined) {
    runEffect(target, SHOW_PROPS, "toggle", speedOrForce, complete);

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

/**
 * jQuery accepts several whitespace-separated specs in one call
 * (`types.match(rnotwhite) || [""]` in `jQuery.event.add`/`remove`), which
 * `switchbox`'s `on("mouseleave click", ...)` relies on. An empty or
 * all-whitespace spec still yields one empty entry, so `.off("")` keeps
 * meaning "every type".
 */
function splitSpecs(spec: string): string[] {
  return spec.match(/\S+/g) ?? [""];
}

/**
 * jQuery binds to every element of a set, so these accept a set as readily
 * as a single target.
 *
 * The two cases are told apart by asking whether the value can take a
 * listener at all, rather than by `instanceof EventTarget`: that test is
 * per-realm and answers false for anything from another document (an
 * iframe, or the test environment's DOM), which would silently route a
 * single element down the set branch and bind nothing. A `length` check
 * would not work either -- `window.length` is its frame count.
 */
function toTargets(target: EventTarget | ArrayLike<Element>): EventTarget[] {
  return typeof (target as EventTarget).addEventListener === "function"
    ? [target as EventTarget]
    : Array.from(target as ArrayLike<Element>);
}

/** `$(el).on("click.ns", handler)`, or several types, or a whole set. */
export function on(
  target: EventTarget | ArrayLike<Element>,
  spec: string,
  handler: EventListener,
  options?: AddEventListenerOptions
): void {
  for (const one of toTargets(target)) {
    for (const type of splitSpecs(spec)) {
      onOne(one, type, handler, options);
    }
  }
}

/**
 * `.hover(handlerIn, handlerOut)` -- shorthand for
 * `.on("mouseenter", handlerIn).on("mouseleave", handlerOut)`. A single
 * argument binds the same handler to both, as jQuery does.
 */
export function hover(
  target: EventTarget | ArrayLike<Element>,
  handlerIn: EventListener,
  handlerOut?: EventListener
): void {
  on(target, "mouseenter", handlerIn);
  on(target, "mouseleave", handlerOut ?? handlerIn);
}

function onOne(
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
 * `$(el).on("click", ".child", handler)` -- a *delegated* handler.
 *
 * The listener sits on `target`, but only runs for events originating inside
 * a descendant matching `selector`, and runs with that descendant as `this`
 * rather than the element the listener is attached to. There is no native
 * equivalent: `addEventListener` gives the bound element, and reconstructing
 * the match means walking the path yourself.
 *
 * jQuery walks from `event.target` up to but not including the delegate,
 * firing once for every matching ancestor, innermost first (`handlerQueue`
 * in src/event.js) -- so a nested match fires twice, not once. It stops that
 * walk when a handler calls `stopPropagation()`, which is why that call is
 * intercepted here.
 */
export function delegate(
  target: EventTarget | ArrayLike<Element>,
  spec: string,
  selector: string,
  handler: EventListener
): void {
  for (const one of toTargets(target)) {
    delegateOne(one, spec, selector, handler);
  }
}

function delegateOne(
  target: EventTarget,
  spec: string,
  selector: string,
  handler: EventListener
): void {
  on(target, spec, (event) => {
    // `nodeType`, not `instanceof Element`, for the same cross-realm reason
    // as `toTargets` above -- and because it is what jQuery itself checks.
    const origin = event.target as Node | null;
    if (origin === null || origin.nodeType !== 1) {
      return;
    }

    let stopped = false;
    const nativeStop = event.stopPropagation.bind(event);
    Object.defineProperty(event, "stopPropagation", {
      configurable: true,
      value: () => {
        stopped = true;
        nativeStop();
      },
    });

    try {
      let current: Element | null = origin as Element;
      while (current !== null && (current as EventTarget) !== target) {
        if (current.matches(selector)) {
          handler.call(current, event);
          // eslint-disable-next-line @typescript-eslint/no-unnecessary-condition -- real false positive from closure mutation: `stopped` is set by the stopPropagation wrapper installed above, which the rule doesn't track.
          if (stopped) {
            return;
          }
        }
        current = current.parentElement;
      }
    } finally {
      // Leave the event exactly as it was found: it may still be travelling
      // to other listeners after this one.
      delete (event as unknown as Record<string, unknown>)["stopPropagation"];
    }
  });
}

/**
 * `$(el).off("click.ns")` / `.off(".ns")` / `.off("click")`.
 *
 * An empty type matches every type (the `.ns` form); an empty namespace list
 * matches every namespace. Passing `handler` narrows further, for the
 * off-then-on replace idiom used at several call sites.
 */
export function off(
  target: EventTarget | ArrayLike<Element>,
  spec: string,
  handler?: EventListener
): void {
  for (const one of toTargets(target)) {
    for (const type of splitSpecs(spec)) {
      offOne(one, type, handler);
    }
  }
}

function offOne(
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
  target: EventTarget | ArrayLike<Element>,
  spec: string,
  detail?: unknown
): void {
  const { type, namespaces } = parseEventSpec(spec);
  if (type === "") {
    return;
  }

  for (const one of toTargets(target)) {
    // A fresh event per element: a single Event cannot be dispatched twice.
    const event = new CustomEvent(type, {
      bubbles: true,
      cancelable: true,
      detail,
    });
    triggeredNamespaces.set(event, namespaces);
    one.dispatchEvent(event);
  }
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

  return FX_SPEEDS["_default"] as number;
}

/**
 * jQuery's effect methods are overloaded: `.fadeOut(complete)`,
 * `.fadeOut(duration)` and `.fadeOut(duration, complete)` are all valid, and
 * at least 10 call sites pass the callback first. Normalise before use.
 */
function normalizeEffectArgs(
  duration?: number | string | (() => void),
  complete?: () => void
): { ms: number; done: (() => void) | undefined } {
  if (typeof duration === "function") {
    return {
      ms: resolveDuration(),
      done: duration,
    };
  }

  return {
    ms: resolveDuration(duration),
    done: complete,
  };
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
  el.style.setProperty(cssPropertyName(prop), String(value) + unit);
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
  duration?: number | string | (() => void),
  complete?: () => void
): void {
  const { ms, done: onComplete } = normalizeEffectArgs(duration, complete);

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

      const tween = new Tween(el, tweens, ms, onComplete, next);
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

// ── Animation: the fade and slide family ─────────────────────────────────
//
// From src/effects.js: fadeIn/fadeOut are `{opacity: "show"|"hide"}` and the
// slides are `genFx()`, which expands to height plus marginTop/Bottom and
// paddingTop/Bottom -- a slide moves the box's vertical spacing too, not just
// its height.
//
// The show/hide pass is what makes these compose with the display memory:
// showing calls show() first and animates *up to* the element's own value;
// hiding animates down to 0 and calls hide() at the end. Either way the
// original inline values are restored afterwards, so a faded-out element is
// left `display:none` with its opacity intact rather than stranded at 0.

/** Vertical box properties a slide animates, per `genFx()`. */
/**
 * `genFx("show", true)` -- what `.show(duration)`, `.hide(duration)` and
 * `.toggle(duration)` animate. Not just opacity and not just height: the
 * element's whole box collapses, margins and padding included, which is why
 * it reads as a fold rather than a fade.
 */
const SHOW_PROPS = [
  "height",
  "width",
  "opacity",
  "marginTop",
  "marginRight",
  "marginBottom",
  "marginLeft",
  "paddingTop",
  "paddingRight",
  "paddingBottom",
  "paddingLeft",
];

const SLIDE_PROPS = [
  "height",
  "marginTop",
  "marginBottom",
  "paddingTop",
  "paddingBottom",
];

type EffectMode = "show" | "hide" | "toggle";

function runEffect(
  target: Element | ArrayLike<Element>,
  propNames: string[],
  mode: EffectMode,
  duration?: number | string | (() => void),
  complete?: () => void
): void {
  const { ms, done: onComplete } = normalizeEffectArgs(duration, complete);

  for (const el of toElements(target)) {
    if (!(el instanceof HTMLElement)) {
      continue;
    }

    enqueue(el, (next) => {
      const hidden = isHiddenForDisplay(el);
      const showing = mode === "toggle" ? hidden : mode === "show";

      // jQuery skips a prop whose requested state already holds -- fadeIn on
      // a visible element animates nothing, but still runs the callback.
      if (mode !== "toggle" && showing !== hidden) {
        onComplete?.call(el);
        next();

        return;
      }

      const affectsBox = propNames.some(
        (p) => p === "height" || p === "width"
      );
      const originalOverflow = el.style.overflow;
      if (affectsBox) {
        el.style.overflow = "hidden";
      }

      // Record the element's own values before anything is touched: these are
      // both the tween's target when showing and what gets restored after.
      const originals = propNames.map((prop) => ({
        prop,
        inline: el.style.getPropertyValue(cssPropertyName(prop)),
      }));

      if (showing) {
        show(el);
      }

      const tweens = propNames.map((prop) => {
        const own = currentValue(el, prop);
        const unit = CSS_NUMBER.has(prop) ? "" : "px";
        const zero = prop === "height" || prop === "width" ? 1 : 0;

        return {
          prop,
          from: showing ? zero : own,
          to: showing ? own : 0,
          unit,
        };
      });

      const finish = (): void => {
        if (!showing) {
          hide(el);
        }
        if (affectsBox) {
          el.style.overflow = originalOverflow;
        }
        for (const { prop, inline } of originals) {
          if (inline === "") {
            el.style.removeProperty(cssPropertyName(prop));
          } else {
            el.style.setProperty(cssPropertyName(prop), inline);
          }
        }
        onComplete?.call(el);
      };

      const tween = new Tween(el, tweens, ms, finish, next);
      fxState(el).running = tween;
      tween.start();
    });
  }
}

/** `$(el).fadeIn(duration, complete)`. */
export function fadeIn(
  target: Element | ArrayLike<Element>,
  duration?: number | string | (() => void),
  complete?: () => void
): void {
  runEffect(target, ["opacity"], "show", duration, complete);
}

/** `$(el).fadeOut(duration, complete)`. */
export function fadeOut(
  target: Element | ArrayLike<Element>,
  duration?: number | string | (() => void),
  complete?: () => void
): void {
  runEffect(target, ["opacity"], "hide", duration, complete);
}

/** `$(el).fadeToggle(duration, complete)`. */
export function fadeToggle(
  target: Element | ArrayLike<Element>,
  duration?: number | string | (() => void),
  complete?: () => void
): void {
  runEffect(target, ["opacity"], "toggle", duration, complete);
}

/**
 * `$(el).fadeTo(duration, opacity, complete)` -- animates to an arbitrary
 * opacity. Unlike fadeOut it never hides the element, and unlike fadeIn it
 * shows a hidden one first so the fade is visible.
 */
export function fadeTo(
  target: Element | ArrayLike<Element>,
  duration: number | string,
  opacity: number,
  complete?: () => void
): void {
  for (const el of toElements(target)) {
    if (el instanceof HTMLElement && isHiddenForDisplay(el)) {
      show(el);
    }
  }
  animate(target, { opacity }, duration, complete);
}

/** `$(el).slideDown(duration, complete)`. */
export function slideDown(
  target: Element | ArrayLike<Element>,
  duration?: number | string | (() => void),
  complete?: () => void
): void {
  runEffect(target, SLIDE_PROPS, "show", duration, complete);
}

/** `$(el).slideUp(duration, complete)`. */
export function slideUp(
  target: Element | ArrayLike<Element>,
  duration?: number | string | (() => void),
  complete?: () => void
): void {
  runEffect(target, SLIDE_PROPS, "hide", duration, complete);
}

/** `$(el).slideToggle(duration, complete)`. */
export function slideToggle(
  target: Element | ArrayLike<Element>,
  duration?: number | string | (() => void),
  complete?: () => void
): void {
  runEffect(target, SLIDE_PROPS, "toggle", duration, complete);
}

// ── Geometry: offset, position and the box-model dimensions ──────────────
//
// None of jQuery's dimension methods is a native property under another
// name. Each starts from the border-box measurement and then adds or
// subtracts padding, border and margin according to the element's own
// `box-sizing`, which is why `.width()`, `.innerWidth()`, `.outerWidth()`
// and `.outerWidth(true)` are one measurement with four different extras
// rather than four different properties. `clientWidth` is the closest
// native analogue to `.innerWidth()` and still disagrees with it, because
// it excludes the scrollbar and jQuery's does not.
//
// Ported from src/css.js (`getWidthOrHeight`, `augmentWidthOrHeight`),
// src/dimensions.js and src/offset.js.

type BoxExtra = "content" | "padding" | "border" | "margin";

/**
 * The two sides `augmentWidthOrHeight` walks per axis -- it indexes
 * `cssExpand` (`["Top","Right","Bottom","Left"]`) from 1 for width and 0 for
 * height, stepping by 2.
 */
const BOX_SIDES: Record<"width" | "height", [string, string]> = {
  width: ["right", "left"],
  height: ["top", "bottom"],
};

function styleNumber(styles: CSSStyleDeclaration, property: string): number {
  return parseFloat(styles.getPropertyValue(property)) || 0;
}

function computedStyles(el: Element): CSSStyleDeclaration | null {
  return el.ownerDocument.defaultView?.getComputedStyle(el) ?? null;
}

/**
 * `augmentWidthOrHeight()`: the correction that turns the measurement we
 * have into the one that was asked for. Returns 0 when they already agree.
 */
function augmentBox(
  styles: CSSStyleDeclaration,
  name: "width" | "height",
  extra: BoxExtra,
  isBorderBox: boolean
): number {
  if (extra === (isBorderBox ? "border" : "content")) {
    return 0;
  }

  let val = 0;
  for (const side of BOX_SIDES[name]) {
    if (extra === "margin") {
      val += styleNumber(styles, `margin-${side}`);
    }

    if (isBorderBox) {
      // A border box already includes padding, so drop it for content.
      if (extra === "content") {
        val -= styleNumber(styles, `padding-${side}`);
      }
      // Anything but margin excludes the border.
      if (extra !== "margin") {
        val -= styleNumber(styles, `border-${side}-width`);
      }
    } else {
      val += styleNumber(styles, `padding-${side}`);
      if (extra !== "padding") {
        val += styleNumber(styles, `border-${side}-width`);
      }
    }
  }

  return val;
}

/**
 * `jQuery.swap()` -- apply styles, measure, put back exactly what was there
 * (an absent declaration comes back absent, not as an empty string).
 */
function swap<T>(
  el: HTMLElement,
  properties: Record<string, string>,
  callback: () => T
): T {
  const previous: Record<string, string> = {};
  for (const [property, value] of Object.entries(properties)) {
    previous[property] = el.style.getPropertyValue(property);
    el.style.setProperty(property, value);
  }

  const result = callback();

  for (const [property, value] of Object.entries(previous)) {
    if (value === "") {
      el.style.removeProperty(property);
    } else {
      el.style.setProperty(property, value);
    }
  }

  return result;
}

/** `rdisplayswap` -- `none`, or a table display other than -cell/-caption. */
const DISPLAY_SWAP = /^(none|table(?!-c[ea]).+)/;

/** `cssShow` -- laid out, measurable, and invisible while it happens. */
const CSS_SHOW: Record<string, string> = {
  position: "absolute",
  visibility: "hidden",
  display: "block",
};

/**
 * `jQuery.cssHooks.width/height.get`: a `display: none` element has no box, so
 * every measurement of it reads zero. jQuery briefly forces it into layout --
 * absolutely positioned and `visibility: hidden`, so nothing moves and nothing
 * is seen -- measures, and restores the inline styles.
 *
 * This is not an optimisation. switchbox measures its popup *before* toggling
 * it into view, and without the swap the width comes back as 0: the popup then
 * pins itself flush to the right edge of the viewport instead of 5px inside
 * it. Confirmed live before this was added, at exactly `right === viewport`.
 */
function boxSize(
  el: HTMLElement,
  name: "width" | "height",
  extra: BoxExtra
): number {
  if (el.offsetWidth === 0 && DISPLAY_SWAP.test(computedDisplay(el))) {
    return swap(el, CSS_SHOW, () => measureBox(el, name, extra));
  }

  return measureBox(el, name, extra);
}

/**
 * `getWidthOrHeight()`. `offsetWidth`/`offsetHeight` is the preferred source
 * because it is already a border-box number; the computed-style fallback
 * covers the elements that have no offset box at all, such as SVG.
 *
 * Two deliberate deviations, both narrow:
 *
 * - jQuery returns the raw string ("50%", "2em") and stops when the computed
 *   value is in a non-px unit. This always returns a number, because every
 *   call site does arithmetic on the result and the string form would give
 *   it NaN regardless.
 * - `valueIsBorderBox` drops jQuery's `support.boxSizingReliable()` probe,
 *   which is a workaround for browsers that predate this codebase's support
 *   floor by a decade; on every engine we run, it is `true`.
 */
function measureBox(
  el: HTMLElement,
  name: "width" | "height",
  extra: BoxExtra
): number {
  const styles = computedStyles(el);
  if (styles === null) {
    return 0;
  }

  const isBorderBox = styles.getPropertyValue("box-sizing") === "border-box";
  let valueIsBorderBox = true;
  let val = name === "width" ? el.offsetWidth : el.offsetHeight;

  if (val <= 0) {
    let raw = styles.getPropertyValue(name);
    if (parseFloat(raw) < 0) {
      raw = el.style.getPropertyValue(name);
    }
    valueIsBorderBox = isBorderBox;
    val = parseFloat(raw) || 0;
  }

  return val + augmentBox(styles, name, extra, valueIsBorderBox);
}

/** `.width()` -- content box, excluding padding and border. */
export function width(el: HTMLElement): number {
  return boxSize(el, "width", "content");
}

/** `.height()`. */
export function height(el: HTMLElement): number {
  return boxSize(el, "height", "content");
}

/** `.innerWidth()` -- content plus padding, excluding border. */
export function innerWidth(el: HTMLElement): number {
  return boxSize(el, "width", "padding");
}

/** `.innerHeight()`. */
export function innerHeight(el: HTMLElement): number {
  return boxSize(el, "height", "padding");
}

/** `.outerWidth()`, or `.outerWidth(true)` to include margins. */
export function outerWidth(el: HTMLElement, includeMargin = false): number {
  return boxSize(el, "width", includeMargin ? "margin" : "border");
}

/** `.outerHeight()`, or `.outerHeight(true)`. */
export function outerHeight(el: HTMLElement, includeMargin = false): number {
  return boxSize(el, "height", includeMargin ? "margin" : "border");
}

/**
 * `$(window).width()`. jQuery's window branch reads the document element's
 * `clientWidth`, *not* `innerWidth` -- the difference is the scrollbar, and
 * the one call site (`switchbox`) uses this to keep a popup on screen, where
 * counting the scrollbar as usable space is precisely the bug.
 */
export function windowWidth(win: Window = window): number {
  return win.document.documentElement.clientWidth;
}

/** `$(window).height()`. */
export function windowHeight(win: Window = window): number {
  return win.document.documentElement.clientHeight;
}

/**
 * `.offset()` -- position relative to the document. Returns `{top: 0, left:
 * 0}` for a detached node, as jQuery does, rather than throwing.
 */
export function offset(el: Element): { top: number; left: number } {
  const doc = el.ownerDocument;
  const docElem = doc.documentElement;
  if (!docElem.contains(el)) {
    return { top: 0, left: 0 };
  }

  const box = el.getBoundingClientRect();
  const win = doc.defaultView;

  return {
    top: box.top + (win?.pageYOffset ?? docElem.scrollTop) - docElem.clientTop,
    left:
      box.left + (win?.pageXOffset ?? docElem.scrollLeft) - docElem.clientLeft,
  };
}

/**
 * `.offsetParent()` -- the *positioned* ancestor, which is not
 * `el.offsetParent`: jQuery walks up past any ancestor whose computed
 * position is `static`, and falls back to the document element.
 */
export function offsetParent(el: HTMLElement): HTMLElement {
  const docElem = el.ownerDocument.documentElement;
  let parent = el.offsetParent as HTMLElement | null;

  while (
    parent != null &&
    parent.nodeName.toLowerCase() !== "html" &&
    computedStyles(parent)?.getPropertyValue("position") === "static"
  ) {
    parent = parent.offsetParent as HTMLElement | null;
  }

  return parent ?? docElem;
}

/**
 * `.position()` -- position relative to the offset parent, with the offset
 * parent's borders and the element's own margins taken out. That last
 * subtraction is the part a naive `offsetTop`/`offsetLeft` translation gets
 * wrong for any element with a margin.
 */
export function position(el: HTMLElement): { top: number; left: number } {
  const styles = computedStyles(el);
  let parentOffset = { top: 0, left: 0 };
  let elementOffset: { top: number; left: number };

  if (styles?.getPropertyValue("position") === "fixed") {
    // A fixed element is offset from the viewport, so its own rect is the
    // answer and the offset parent plays no part.
    const box = el.getBoundingClientRect();
    elementOffset = { top: box.top, left: box.left };
  } else {
    const parent = offsetParent(el);
    elementOffset = offset(el);
    if (parent.nodeName.toLowerCase() !== "html") {
      parentOffset = offset(parent);
    }

    const parentStyles = computedStyles(parent);
    if (parentStyles !== null) {
      parentOffset.top += styleNumber(parentStyles, "border-top-width");
      parentOffset.left += styleNumber(parentStyles, "border-left-width");
    }
  }

  return {
    top:
      elementOffset.top -
      parentOffset.top -
      (styles === null ? 0 : styleNumber(styles, "margin-top")),
    left:
      elementOffset.left -
      parentOffset.left -
      (styles === null ? 0 : styleNumber(styles, "margin-left")),
  };
}

/**
 * `.css(name)` -- the *getter*, which is the computed value, not the inline
 * one. Note the asymmetry with `width()`/`height()` above: those return a
 * number, while `.css("width")` returns a px string, and jQuery routes it
 * through the same box hooks so a hidden element still measures.
 */
export function cssValue(el: Element, name: string): string {
  const property = cssPropertyName(name);
  const styles = computedStyles(el);
  if (styles === null) {
    return "";
  }

  if ((property === "width" || property === "height") && el instanceof HTMLElement) {
    const isBorderBox = styles.getPropertyValue("box-sizing") === "border-box";

    return `${boxSize(el, property, isBorderBox ? "border" : "content")}px`;
  }

  return styles.getPropertyValue(property);
}

/**
 * `.css(name, value)`. The reason this exists rather than a direct
 * `style.foo =` at the call sites: jQuery appends "px" to a bare number for
 * every property outside `jQuery.cssNumber`, so `.css("left", 12)` sets
 * `left: 12px` while `.css("opacity", 0.5)` does not become `0.5px`. A
 * literal translation that assigned the number would silently set nothing.
 */
export function css(
  target: Element | ArrayLike<Element>,
  name: string,
  value: string | number
): void;
export function css(
  target: Element | ArrayLike<Element>,
  styles: Record<string, string | number>
): void;
export function css(
  target: Element | ArrayLike<Element>,
  nameOrStyles: string | Record<string, string | number>,
  value?: string | number
): void {
  const styles =
    typeof nameOrStyles === "string"
      ? { [nameOrStyles]: value as string | number }
      : nameOrStyles;

  for (const [name, propValue] of Object.entries(styles)) {
    // `jQuery.style` bails on NaN rather than writing "NaNpx" (its #7116).
    // The guard is load-bearing, not defensive: switchbox computes a
    // coordinate from a measurement that is legitimately absent when its
    // popup is not in the document yet.
    if (typeof propValue === "number" && Number.isNaN(propValue)) {
      continue;
    }

    const property = cssPropertyName(name);
    const resolved =
      typeof propValue === "number" && !CSS_NUMBER.has(name)
        ? `${propValue}px`
        : String(propValue);

    for (const el of toElements(target)) {
      if (el instanceof HTMLElement) {
        el.style.setProperty(property, resolved);
      }
    }
  }
}

// ── Set operations ───────────────────────────────────────────────────────
//
// jQuery methods are set operations with one consistent asymmetry: a setter
// writes to *every* element of the set, a getter reads the *first*, and both
// are silent no-ops on an empty set. Translating `$(".x").html(v)` to
// `querySelector(".x").innerHTML = v` quietly drops every match after the
// first, and throws outright when there are none -- which is why these are
// here rather than inlined at each call site.
//
// `.is()` here handles real CSS selectors only. jQuery's own pseudo-classes
// have no place in `matches()` -- `:visible` throws a SyntaxError there --
// so call sites use `isVisible()` and friends directly instead of hiding
// them behind a selector string.

/** `.html(value)` -- writes to every element. */
export function html(target: Element | ArrayLike<Element>, value: string): void {
  for (const el of toElements(target)) {
    el.innerHTML = value;
  }
}

/** `.html()` -- reads the first element, `undefined` for an empty set. */
export function htmlOf(target: Element | ArrayLike<Element>): string | undefined {
  return toElements(target)[0]?.innerHTML;
}

/** `.text(value)` -- writes to every element. */
export function text(target: Element | ArrayLike<Element>, value: string): void {
  for (const el of toElements(target)) {
    el.textContent = value;
  }
}

/**
 * `.text()` -- note this one *concatenates* across the whole set rather than
 * reading the first, unlike `.html()` and `.val()` (`jQuery.text()` over
 * `getText`). The odd one out, so it is spelled out here.
 */
export function textOf(target: Element | ArrayLike<Element>): string {
  return toElements(target)
    .map((el) => el.textContent)
    .join("");
}

/**
 * `.val()` -- reads the first element, `undefined` for an empty set.
 * jQuery's own getter has no special hook for a plain element (only
 * `select`/`radio`/`checkbox` do), so it falls through to that element's
 * own `.value` -- an inert, non-form-control elements included, own
 * property, never reflected in what renders. Faithfully mirrored here
 * rather than restricted to input/select/textarea, since
 * `updates_ext.ts`'s `#reset_ignore` (a `<div>`) is exactly such a caller.
 */
export function val(target: Element | ArrayLike<Element>): string | undefined {
  const first = toElements(target)[0];
  if (first === undefined) {
    return undefined;
  }

  return (first as HTMLInputElement).value;
}

/** `.val(value)` -- writes to every element; see `val()`'s own docblock. */
export function setVal(
  target: Element | ArrayLike<Element>,
  value: string
): void {
  for (const el of toElements(target)) {
    (el as HTMLInputElement).value = value;
  }
}

/** `.prop("checked", bool)` -- writes to every checkbox/radio in the set. */
export function setChecked(
  target: Element | ArrayLike<Element>,
  checked: boolean
): void {
  for (const el of toElements(target)) {
    if (el instanceof HTMLInputElement) {
      el.checked = checked;
    }
  }
}

/**
 * `.prop("disabled", bool)` -- writes to every element in the set. Not
 * restricted to a single element type: jQuery's own `.prop()` just does
 * `elem[name] = value` with no type check, and call sites use it on
 * `<button>` as readily as `<input>`.
 */
export function setDisabled(
  target: Element | ArrayLike<Element>,
  disabled: boolean
): void {
  for (const el of toElements(target)) {
    if ("disabled" in el) {
      (el as unknown as { disabled: boolean }).disabled = disabled;
    }
  }
}

/** `.addClass("a b")` -- space-separated, as jQuery splits it. */
export function addClass(
  target: Element | ArrayLike<Element>,
  names: string
): void {
  const list = names.match(/\S+/g) ?? [];
  for (const el of toElements(target)) {
    el.classList.add(...list);
  }
}

/** `.removeClass("a b")`. */
export function removeClass(
  target: Element | ArrayLike<Element>,
  names: string
): void {
  const list = names.match(/\S+/g) ?? [];
  for (const el of toElements(target)) {
    el.classList.remove(...list);
  }
}

/** `.hasClass(name)` -- true when *any* element carries it. */
export function hasClass(
  target: Element | ArrayLike<Element>,
  name: string
): boolean {
  return toElements(target).some((el) => el.classList.contains(name));
}

/** `.attr(name)` -- reads the first element. */
export function attrOf(
  target: Element | ArrayLike<Element>,
  name: string
): string | null | undefined {
  return toElements(target)[0]?.getAttribute(name);
}

/** `.attr(name, value)` -- writes to every element. */
export function attr(
  target: Element | ArrayLike<Element>,
  name: string,
  value: string
): void {
  for (const el of toElements(target)) {
    el.setAttribute(name, value);
  }
}

/** `.removeAttr(name)` -- removes an attribute from every element. */
export function removeAttr(
  target: Element | ArrayLike<Element>,
  name: string
): void {
  for (const el of toElements(target)) {
    el.removeAttribute(name);
  }
}

/** `.empty()` -- removes every child, of every element. */
export function empty(target: Element | ArrayLike<Element>): void {
  for (const el of toElements(target)) {
    el.replaceChildren();
  }
}

/** `.remove()` -- detaches every element from its parent. */
export function remove(target: Element | ArrayLike<Element>): void {
  for (const el of toElements(target)) {
    el.remove();
  }
}

/**
 * Renders the linked album breadcrumb the admin templates render
 * server-side, from the `breadcrumb`/`levelSeparator` an album API response
 * carries.
 *
 * Mirrors `HtmlService::getCatDisplayNameCache($uppercats,
 * 'admin.php?page=album-')` exactly: one `<a>` per segment, joined by
 * `<span>{separator}</span>`. Segment names arrive HTML-escaped, as
 * `fullname` does, so they are interpolated rather than assigned as text.
 *
 * Lives here rather than in one of the three call sites because all three
 * must agree with the server, and with each other.
 */
export function albumBreadcrumbHtml(
  breadcrumb: readonly { id: string; name: string }[] | undefined,
  levelSeparator: string
): string {
  if (breadcrumb === undefined) {
    return "";
  }

  return breadcrumb
    .map(
      (segment) =>
        `<a href="admin.php?page=album-${encodeURIComponent(segment.id)}">${segment.name}</a>`
    )
    .join(`<span>${levelSeparator}</span>`);
}

/**
 * Escapes a value for use as an id in a selector.
 *
 * `#1` is a valid Sizzle selector and an invalid CSS one:
 * `querySelectorAll` throws a `SyntaxError` on an identifier starting with a
 * digit, while jQuery's own engine accepts it. Every id built from a
 * database row id hits this, so it is the rule rather than the exception --
 * `CSS.escape` produces the conforming form (`#\31 `).
 */
export function escapeId(id: string | number): string {
  return CSS.escape(String(id));
}

/** `.is(selector)` -- true when *any* element matches. CSS selectors only. */
export function is(
  target: Element | ArrayLike<Element>,
  selector: string
): boolean {
  return toElements(target).some((el) => el.matches(selector));
}

/** `.find(selector)` -- every matching descendant of every element. */
export function find(
  target: Element | ArrayLike<Element>,
  selector: string
): Element[] {
  return toElements(target).flatMap((el) =>
    Array.from(el.querySelectorAll(selector))
  );
}

/**
 * `.children([selector])` -- direct children only (not every descendant,
 * unlike `find()`), optionally filtered.
 */
export function children(
  target: Element | ArrayLike<Element>,
  selector?: string
): Element[] {
  return toElements(target).flatMap((el) =>
    Array.from(el.children).filter((child) =>
      selector === undefined ? true : child.matches(selector)
    )
  );
}

/**
 * `.append(html)` -- parses once per element, because a node cannot be in
 * two parents at once (jQuery clones for every element after the first,
 * which comes to the same thing).
 */
export function append(
  target: Element | ArrayLike<Element>,
  markup: string
): void {
  for (const el of toElements(target)) {
    for (const node of parseHtml(markup)) {
      el.appendChild(node);
    }
  }
}

/**
 * `.prepend(html)` -- inserts as the first child(ren) of every element.
 * `reference` is captured once per element, before the loop: inserting
 * repeatedly before `el.firstChild` without it would insert each new node
 * ahead of the previous one, reversing their order.
 */
export function prepend(
  target: Element | ArrayLike<Element>,
  markup: string
): void {
  for (const el of toElements(target)) {
    const reference = el.firstChild;
    for (const node of parseHtml(markup)) {
      el.insertBefore(node, reference);
    }
  }
}

/** `.after(html)` -- inserts as the next sibling of every element. */
export function after(
  target: Element | ArrayLike<Element>,
  markup: string
): void {
  for (const el of toElements(target)) {
    for (const node of parseHtml(markup).reverse()) {
      el.parentElement?.insertBefore(node, el.nextSibling);
    }
  }
}

// ── Markup ───────────────────────────────────────────────────────────────

/**
 * `$(htmlString)` -- parse markup into its top-level elements.
 *
 * A `<template>` is used rather than a detached `<div>` because the HTML
 * parser applies the same content restrictions jQuery works around with its
 * `wrapMap`: a bare `<tr>` assigned to a div's innerHTML is discarded, while
 * a template parses it correctly. Text nodes between the elements are
 * dropped, which every call site here wants -- each parses one element out
 * of a template block.
 */
export function parseHtml(html: string): Element[] {
  const template = document.createElement("template");
  template.innerHTML = html;

  return Array.from(template.content.children);
}

// ── Document ready ───────────────────────────────────────────────────────

/**
 * `jQuery(document).ready(fn)` / `$(fn)`.
 *
 * Not interchangeable with a bare `DOMContentLoaded` listener, and the
 * difference is load-bearing here: P48 made every bundle a deferred module,
 * so a module's own top-level code frequently runs *after* DOMContentLoaded
 * has already fired. A listener registered then never fires at all, and the
 * whole file silently does nothing.
 *
 * Mirrors src/core/ready.js: when the document is already parsed, the
 * callback is scheduled asynchronously rather than run inline, so handlers
 * registered from different modules keep running in registration order
 * instead of some jumping ahead of others.
 */
export function ready(callback: () => void): void {
  if (document.readyState === "complete") {
    setTimeout(callback);

    return;
  }

  if (document.readyState !== "loading") {
    // Parsed but still loading subresources -- DOMContentLoaded has already
    // fired, so waiting for it would wait forever.
    setTimeout(callback);

    return;
  }

  document.addEventListener("DOMContentLoaded", () => {
    callback();
  });
}
