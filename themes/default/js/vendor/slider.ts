import { addClass, css, off, on, removeClass } from "./dom";

/**
 * Port of jQuery UI 1.10.4's `ui.slider` widget (real source read from
 * `jquery-ui@1.10.4/ui/jquery.ui.slider.js`, vendored via the combined
 * `jquery-ui.js` CDN bundle this app loads). Horizontal only -- no real
 * call site (`user_list.ts`, `plugins_new.ts`, `doubleSlider.ts`'s own
 * `pwgDoubleSlider`) ever sets `orientation`, `animate`, or `distance`.
 * Mouse + keyboard only: no touch-normalization plugin
 * (jquery-ui-touch-punch or similar) is registered anywhere in this
 * app, so the original never had real touch support to preserve.
 * Re-initializing an already-initialized element *is* real (`mcs.ts`'s
 * filesize/height/width filters re-run `pwgDoubleSlider` on their
 * "clear" button), so `init()`'s own handle-reuse logic is ported, not
 * skipped.
 *
 * DOM/class output matches the original exactly (`ui-slider`,
 * `ui-slider-handle`, `ui-slider-range`, `ui-state-active`, ...) so the
 * existing `jquery-ui.css` theme this app already loads (kept
 * registered -- still real, needed by `pwgDatepicker`, P49-B group 5,
 * not yet ported) keeps rendering it identically.
 *
 * One deliberate simplification: the original starts a distinct
 * "key sliding" session on keydown and only fires `stop`/`change` on
 * the matching keyup (so holding a key down fires `slide` repeatedly
 * but `stop`/`change` only once, at release). This treats every
 * keydown as an atomic slide+stop+change instead. No real call site's
 * `slide`/`change`/`stop` callbacks differ in a way that distinguishes
 * "still holding" from "released" (`user_list.ts`'s all three re-render
 * the same label; `stop` additionally commits the value) and no test
 * can practically simulate raw key-repeat through a real browser
 * driver, so the two are observably identical here.
 */
export interface SliderUIParams {
  value?: number;
  values?: number[];
}

export interface SliderOptions {
  range?: boolean | "min" | "max";
  min?: number;
  max?: number;
  value?: number;
  values?: number[];
  step?: number;
  slide?: (event: Event, ui: SliderUIParams) => unknown;
  change?: (event: Event, ui: SliderUIParams) => void;
  stop?: (event: Event, ui: SliderUIParams) => void;
}

interface SliderState {
  min: number;
  max: number;
  step: number;
  range: boolean | "min" | "max";
  isRange: boolean;
  values: number[];
  lastChangedValue: number;
  handles: HTMLAnchorElement[];
  rangeEl: HTMLDivElement | null;
  slide?: ((event: Event, ui: SliderUIParams) => unknown) | undefined;
  change?: ((event: Event, ui: SliderUIParams) => void) | undefined;
  stop?: ((event: Event, ui: SliderUIParams) => void) | undefined;
}

const instances = new WeakMap<Element, SliderState>();

function toArray(target: Element | ArrayLike<Element>): Element[] {
  return target instanceof Element ? [target] : Array.from(target);
}

function trimAlign(state: SliderState, val: number): number {
  if (val <= state.min) {
    return state.min;
  }
  if (val >= state.max) {
    return state.max;
  }
  const step = state.step > 0 ? state.step : 1;
  const mod = (val - state.min) % step;
  let aligned = val - mod;
  if (Math.abs(mod) * 2 >= step) {
    aligned += mod > 0 ? step : -step;
  }

  return parseFloat(aligned.toFixed(5));
}

function refreshValue(_el: HTMLElement, state: SliderState): void {
  if (state.isRange) {
    let lastPercent = 0;
    state.handles.forEach((handle, i) => {
      const percent =
        ((state.values[i]! - state.min) / (state.max - state.min)) * 100;
      css(handle, "left", `${percent}%`);
      if (i === 0 && state.rangeEl !== null) {
        css(state.rangeEl, "left", `${percent}%`);
      }
      if (i === 1 && state.rangeEl !== null) {
        css(state.rangeEl, "width", `${percent - lastPercent}%`);
      }
      lastPercent = percent;
    });

    return;
  }

  const value = state.values[0]!;
  const percent =
    state.max !== state.min
      ? ((value - state.min) / (state.max - state.min)) * 100
      : 0;
  css(state.handles[0]!, "left", `${percent}%`);

  if (state.rangeEl !== null) {
    if (state.range === "min") {
      css(state.rangeEl, "width", `${percent}%`);
    } else if (state.range === "max") {
      css(state.rangeEl, "width", `${100 - percent}%`);
    }
  }
}

function currentUi(state: SliderState, index: number): SliderUIParams {
  if (state.isRange) {
    return { value: state.values[index]!, values: state.values.slice() };
  }

  return { value: state.values[0]! };
}

function fireChange(
  state: SliderState,
  index: number,
  event: Event = new Event("change")
): void {
  state.lastChangedValue = index;
  state.change?.(event, currentUi(state, index));
}

function slideTo(
  el: HTMLElement,
  state: SliderState,
  index: number,
  newValRaw: number,
  event: Event
): void {
  let newVal = newValRaw;

  if (state.isRange) {
    const otherIndex = index === 0 ? 1 : 0;
    const otherVal = state.values[otherIndex]!;
    if (
      (index === 0 && newVal > otherVal) ||
      (index === 1 && newVal < otherVal)
    ) {
      newVal = otherVal;
    }
    if (newVal === state.values[index]) {
      return;
    }

    const newValues = state.values.slice();
    newValues[index] = newVal;
    const allowed = state.slide?.(event, { value: newVal, values: newValues });
    if (allowed !== false) {
      state.values[index] = newVal;
      refreshValue(el, state);
    }

    return;
  }

  if (newVal === state.values[0]) {
    return;
  }

  const allowed = state.slide?.(event, { value: newVal });
  if (allowed !== false) {
    state.values[0] = newVal;
    refreshValue(el, state);
  }
}

function closestHandleIndex(state: SliderState, normValue: number): number {
  let closestIndex = 0;
  let closestDistance = Infinity;
  state.values.forEach((v, i) => {
    const distance = Math.abs(normValue - v);
    if (
      distance < closestDistance ||
      (distance === closestDistance &&
        (i === state.lastChangedValue || v === state.min))
    ) {
      closestDistance = distance;
      closestIndex = i;
    }
  });

  return closestIndex;
}

function normValueFromMouse(el: HTMLElement, state: SliderState, event: MouseEvent): number {
  const rect = el.getBoundingClientRect();
  const percent = Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width));

  return trimAlign(state, state.min + percent * (state.max - state.min));
}

/**
 * Looks up the *current* state fresh on every event rather than closing
 * over the state object `bindMouse`/`bindKeyboard` were first called
 * with: a re-init (`init()`'s own re-init path) replaces `el`'s state
 * in `instances` without rebinding these listeners (they're attached
 * once, to `el`/its handles, which a re-init reuses), so reading a
 * captured reference here would silently keep acting on pre-re-init
 * min/max/values/callbacks.
 */
function currentState(el: HTMLElement): SliderState | undefined {
  return instances.get(el);
}

function bindMouse(el: HTMLElement): void {
  on(el, "mousedown", (e) => {
    const state = currentState(el);
    if (state === undefined) {
      return;
    }
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- "mousedown" always dispatches a real MouseEvent; on()'s own handler param is typed generically via the native EventListener interface.
    const event = e as MouseEvent;
    event.preventDefault();

    const normValue = normValueFromMouse(el, state, event);
    const index = closestHandleIndex(state, normValue);
    const handle = state.handles[index]!;
    addClass(handle, "ui-state-active");
    handle.focus();

    slideTo(el, state, index, normValue, event);

    const onMouseMove = (moveEvent: Event): void => {
      const liveState = currentState(el);
      if (liveState === undefined) {
        return;
      }
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- "mousemove" always dispatches a real MouseEvent; on()'s own handler param is typed generically via the native EventListener interface.
      const nv = normValueFromMouse(el, liveState, moveEvent as MouseEvent);
      slideTo(el, liveState, index, nv, moveEvent);
    };
    const onMouseUp = (upEvent: Event): void => {
      off(document, "mousemove", onMouseMove);
      off(document, "mouseup", onMouseUp);
      removeClass(handle, "ui-state-active");
      const liveState = currentState(el);
      if (liveState === undefined) {
        return;
      }
      liveState.stop?.(upEvent, currentUi(liveState, index));
      fireChange(liveState, index, upEvent);
    };
    on(document, "mousemove", onMouseMove);
    on(document, "mouseup", onMouseUp);
  });
}

function bindKeyboard(el: HTMLElement, handle: HTMLAnchorElement, index: number): void {
  on(handle, "click", (e) => {
    e.preventDefault();
  });
  on(handle, "keydown", (e) => {
    const state = currentState(el);
    if (state === undefined) {
      return;
    }
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- "keydown" always dispatches a real KeyboardEvent; on()'s own handler param is typed generically via the native EventListener interface.
    const event = e as KeyboardEvent;
    const curVal = state.values[index]!;
    let newVal: number;

    switch (event.key) {
      case "Home":
        newVal = state.min;
        break;
      case "End":
        newVal = state.max;
        break;
      case "PageUp":
        newVal = trimAlign(state, curVal + (state.max - state.min) / 5);
        break;
      case "PageDown":
        newVal = trimAlign(state, curVal - (state.max - state.min) / 5);
        break;
      case "ArrowUp":
      case "ArrowRight":
        if (curVal === state.max) {
          return;
        }
        newVal = trimAlign(state, curVal + state.step);
        break;
      case "ArrowDown":
      case "ArrowLeft":
        if (curVal === state.min) {
          return;
        }
        newVal = trimAlign(state, curVal - state.step);
        break;
      default:
        return;
    }

    event.preventDefault();
    addClass(handle, "ui-state-active");
    slideTo(el, state, index, newVal, event);
    removeClass(handle, "ui-state-active");
    state.stop?.(event, currentUi(state, index));
    fireChange(state, index, event);
  });
}

function createHandle(): HTMLAnchorElement {
  const handle = document.createElement("a");
  handle.className = "ui-slider-handle ui-state-default ui-corner-all";
  handle.href = "#";
  handle.tabIndex = 0;

  return handle;
}

/**
 * `mcs.ts`'s own filesize/height/width filters re-run `pwgDoubleSlider`
 * on the same `[data-slider=...]` element every time its "clear" button
 * is clicked -- a real, confirmed re-init, not a hypothetical one. The
 * original's own `_createHandles()` prunes/reuses existing handles for
 * exactly this case; this does the same rather than appending duplicate
 * handle nodes on every re-init.
 */
function init(el: HTMLElement, options: SliderOptions): void {
  const min = options.min ?? 0;
  const max = options.max ?? 100;
  const step = options.step ?? 1;
  const isRange = options.range === true;
  const values = isRange ? (options.values ?? [min, min]) : [options.value ?? 0];
  const handleCount = isRange ? 2 : 1;

  const existing = instances.get(el);
  const handles = existing?.handles.slice(0, handleCount) ?? [];
  for (const stale of existing?.handles.slice(handleCount) ?? []) {
    stale.remove();
  }
  while (handles.length < handleCount) {
    const handle = createHandle();
    el.appendChild(handle);
    bindKeyboard(el, handle, handles.length);
    handles.push(handle);
  }

  let rangeEl = existing?.rangeEl ?? null;
  if (options.range !== undefined && options.range !== false) {
    if (rangeEl === null) {
      rangeEl = document.createElement("div");
      el.appendChild(rangeEl);
    }
    let cls = "ui-slider-range ui-widget-header ui-corner-all";
    if (options.range === "min" || options.range === "max") {
      cls += ` ui-slider-range-${options.range}`;
    }
    rangeEl.className = cls;
  } else if (rangeEl !== null) {
    rangeEl.remove();
    rangeEl = null;
  }

  addClass(
    el,
    "ui-slider ui-slider-horizontal ui-widget ui-widget-content ui-corner-all"
  );

  const state: SliderState = {
    min,
    max,
    step,
    range: options.range ?? false,
    isRange,
    values,
    lastChangedValue: 0,
    handles,
    rangeEl,
    slide: options.slide,
    change: options.change,
    stop: options.stop,
  };
  instances.set(el, state);

  if (existing === undefined) {
    bindMouse(el);
  }
  refreshValue(el, state);
}

export function slider(
  elements: Element | ArrayLike<Element>,
  options: SliderOptions
): void;
export function slider(
  elements: Element | ArrayLike<Element>,
  method: "option",
  key: "value"
): number | undefined;
export function slider(
  elements: Element | ArrayLike<Element>,
  method: "option",
  key: "value",
  value: number
): void;
export function slider(
  elements: Element | ArrayLike<Element>,
  method: "option",
  key: "values"
): number[] | undefined;
export function slider(
  elements: Element | ArrayLike<Element>,
  method: "value"
): number | undefined;
export function slider(
  elements: Element | ArrayLike<Element>,
  method: "values",
  index: number,
  value: number
): void;
export function slider(
  elements: Element | ArrayLike<Element>,
  optionsOrMethod: SliderOptions | "option" | "value" | "values",
  keyOrIndex?: "value" | "values" | number,
  value?: number
): number | number[] | undefined | void {
  const els = toArray(elements).filter(
    (el): el is HTMLElement => el instanceof HTMLElement
  );

  if (typeof optionsOrMethod === "object") {
    for (const el of els) {
      init(el, optionsOrMethod);
    }

    return;
  }

  const el = els[0];
  if (el === undefined) {
    return undefined;
  }
  const state = instances.get(el);
  if (state === undefined) {
    return undefined;
  }

  if (optionsOrMethod === "value") {
    return state.values[0];
  }

  if (optionsOrMethod === "values") {
    // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- the jQuery-UI-style ("values", index, value) call convention always pairs this branch with a real numeric index; TS can't correlate two separate parameters like this.
    const index = keyOrIndex as number;
    state.values[index] = trimAlign(state, value!);
    refreshValue(el, state);
    fireChange(state, index);

    return;
  }

  // optionsOrMethod === "option"
  if (keyOrIndex === "value") {
    if (value !== undefined) {
      state.values[0] = trimAlign(state, value);
      refreshValue(el, state);
      fireChange(state, 0);

      return;
    }

    return state.values[0];
  }

  if (keyOrIndex === "values") {
    return state.values.slice();
  }

  return undefined;
}
