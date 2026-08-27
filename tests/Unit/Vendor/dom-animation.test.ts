import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import {
  animate,
  delay,
  resolveDuration,
  stop,
  swing,
} from "../../../themes/default/js/vendor/dom";

let el: HTMLElement;

beforeEach(() => {
  vi.useFakeTimers();
  document.body.innerHTML = `<div id="t" style="opacity:1"></div>`;
  el = document.getElementById("t") as HTMLElement;
});

afterEach(() => {
  vi.useRealTimers();
});

describe("swing easing", () => {
  it("matches jQuery's curve at the ends and midpoint", () => {
    expect(swing(0)).toBeCloseTo(0);
    expect(swing(0.5)).toBeCloseTo(0.5);
    expect(swing(1)).toBeCloseTo(1);
  });

  it("is not linear -- it eases in", () => {
    expect(swing(0.25)).toBeLessThan(0.25);
    expect(swing(0.75)).toBeGreaterThan(0.75);
  });
});

describe("resolveDuration", () => {
  it("maps jQuery's named speeds and defaults to 400", () => {
    expect(resolveDuration("slow")).toBe(600);
    expect(resolveDuration("fast")).toBe(200);
    expect(resolveDuration()).toBe(400);
    expect(resolveDuration(1000)).toBe(1000);
  });
});

describe("animate()", () => {
  it("reaches the target value and runs the completion callback", () => {
    const done = vi.fn();
    animate(el, { opacity: 0 }, 100, done);

    vi.advanceTimersByTime(200);

    expect(el.style.opacity).toBe("0");
    expect(done).toHaveBeenCalledTimes(1);
  });

  it("is still mid-flight partway through", () => {
    animate(el, { opacity: 0 }, 1000);
    vi.advanceTimersByTime(500);

    const value = parseFloat(el.style.opacity);
    expect(value).toBeGreaterThan(0);
    expect(value).toBeLessThan(1);
  });

  it("uses px for length properties and no unit for opacity", () => {
    animate(el, { top: 50 }, 10);
    vi.advanceTimersByTime(50);
    expect(el.style.top).toBe("50px");

    animate(el, { opacity: 0.5 }, 10);
    vi.advanceTimersByTime(50);
    expect(el.style.opacity).toBe("0.5");
  });

  it("honours a unit given in the target value", () => {
    // group_list.ts animates `top: "20%"` -- the unit must survive.
    animate(el, { top: "20%" }, 10);
    vi.advanceTimersByTime(50);
    expect(el.style.top).toBe("20%");
  });
});

describe("the fx queue", () => {
  it("runs queued animations in order rather than concurrently", () => {
    const order: string[] = [];
    animate(el, { opacity: 0 }, 100, () => order.push("first"));
    animate(el, { opacity: 1 }, 100, () => order.push("second"));

    vi.advanceTimersByTime(50);
    expect(order).toEqual([]);

    vi.advanceTimersByTime(100);
    expect(order).toEqual(["first"]);

    vi.advanceTimersByTime(150);
    expect(order).toEqual(["first", "second"]);
  });

  it("delay() defers the next step without blocking earlier ones", () => {
    const done = vi.fn();
    delay(el, 1500);
    animate(el, { opacity: 0 }, 500, done);

    vi.advanceTimersByTime(1000);
    expect(done).not.toHaveBeenCalled();

    vi.advanceTimersByTime(1200);
    expect(done).toHaveBeenCalledTimes(1);
    expect(el.style.opacity).toBe("0");
  });
});

describe("stop()", () => {
  it("stop(false, true) jumps to the end and runs the callback", () => {
    const done = vi.fn();
    animate(el, { opacity: 0 }, 1000, done);
    vi.advanceTimersByTime(100);

    stop(el, false, true);

    expect(el.style.opacity).toBe("0");
    expect(done).toHaveBeenCalledTimes(1);
  });

  it("stop() without jumpToEnd freezes partway and skips the callback", () => {
    const done = vi.fn();
    animate(el, { opacity: 0 }, 1000, done);
    vi.advanceTimersByTime(500);
    const midway = parseFloat(el.style.opacity);

    stop(el);

    expect(done).not.toHaveBeenCalled();
    vi.advanceTimersByTime(1000);
    expect(parseFloat(el.style.opacity)).toBeCloseTo(midway);
  });

  it("stop(false, true) leaves the rest of the queue intact", () => {
    // The real idiom: stop the running notification fade, but keep the
    // delay+fadeOut that was queued behind it.
    const second = vi.fn();
    animate(el, { opacity: 0 }, 1000);
    animate(el, { opacity: 1 }, 100, second);

    stop(el, false, true);
    vi.advanceTimersByTime(200);

    expect(second).toHaveBeenCalledTimes(1);
  });

  it("stop(true) drops everything still queued", () => {
    const second = vi.fn();
    animate(el, { opacity: 0 }, 1000);
    animate(el, { opacity: 1 }, 100, second);

    stop(el, true, false);
    vi.advanceTimersByTime(2000);

    expect(second).not.toHaveBeenCalled();
  });
});
