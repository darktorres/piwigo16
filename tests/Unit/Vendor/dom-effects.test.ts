import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import {
  fadeIn,
  fadeOut,
  fadeTo,
  fadeToggle,
  hide,
  show,
  slideDown,
  slideToggle,
  slideUp,
  toggle,
} from "../../../themes/default/js/vendor/dom";

let el: HTMLElement;

beforeEach(() => {
  vi.useFakeTimers();
  document.body.innerHTML = `<div id="t" style="opacity:1;height:40px">x</div>`;
  el = document.getElementById("t") as HTMLElement;
});

afterEach(() => {
  vi.useRealTimers();
});

describe("fadeOut", () => {
  it("hides the element at the end rather than leaving it at opacity 0", () => {
    fadeOut(el, 100);
    vi.advanceTimersByTime(300);

    expect(el.style.display).toBe("none");
  });

  it("restores the original opacity so the element is reusable", () => {
    // jQuery restores the recorded inline values once the animation is done.
    // Leaving opacity:0 behind would make a later show() reveal nothing --
    // the kind of bug that only appears on the second interaction.
    fadeOut(el, 100);
    vi.advanceTimersByTime(300);

    expect(el.style.opacity).toBe("1");
  });

  it("runs the completion callback", () => {
    const done = vi.fn();
    fadeOut(el, 100, done);
    vi.advanceTimersByTime(300);

    expect(done).toHaveBeenCalledTimes(1);
  });
});

describe("the callback-first overload", () => {
  it("accepts fadeOut(complete) with no duration", () => {
    // jQuery's effect methods are overloaded and at least 10 call sites pass
    // the callback first -- toaster.ts's own `template.fadeOut(() => ...)`
    // among them. Treating that argument as a duration would silently never
    // run the callback.
    const done = vi.fn();
    fadeOut(el, done);
    vi.advanceTimersByTime(600);

    expect(done).toHaveBeenCalledTimes(1);
    expect(el.style.display).toBe("none");
  });

  it("uses the default 400ms duration in that form", () => {
    const done = vi.fn();
    fadeOut(el, done);

    vi.advanceTimersByTime(300);
    expect(done).not.toHaveBeenCalled();

    vi.advanceTimersByTime(200);
    expect(done).toHaveBeenCalledTimes(1);
  });

  it("accepts slideToggle(complete) too", () => {
    const done = vi.fn();
    slideToggle(el, done);
    vi.advanceTimersByTime(600);

    expect(done).toHaveBeenCalledTimes(1);
  });
});

describe("fadeIn", () => {
  it("shows a hidden element and restores its display", () => {
    hide(el);
    expect(el.style.display).toBe("none");

    fadeIn(el, 100);
    vi.advanceTimersByTime(300);

    expect(el.style.display).not.toBe("none");
  });

  it("is a no-op on an already visible element but still calls back", () => {
    // jQuery's show/hide pass skips a prop whose state already holds.
    const done = vi.fn();
    fadeIn(el, 100, done);
    vi.advanceTimersByTime(300);

    expect(el.style.display).not.toBe("none");
    expect(done).toHaveBeenCalledTimes(1);
  });

  it("round-trips with fadeOut", () => {
    fadeOut(el, 50);
    vi.advanceTimersByTime(200);
    expect(el.style.display).toBe("none");

    fadeIn(el, 50);
    vi.advanceTimersByTime(200);
    expect(el.style.display).not.toBe("none");
    expect(el.style.opacity).toBe("1");
  });
});

describe("fadeToggle", () => {
  it("flips in both directions, same as slideToggle", () => {
    fadeToggle(el, 50);
    vi.advanceTimersByTime(200);
    expect(el.style.display).toBe("none");

    fadeToggle(el, 50);
    vi.advanceTimersByTime(200);
    expect(el.style.display).not.toBe("none");
  });
});

describe("fadeTo", () => {
  it("animates to an arbitrary opacity without hiding", () => {
    fadeTo(el, 100, 0.4);
    vi.advanceTimersByTime(300);

    expect(el.style.opacity).toBe("0.4");
    expect(el.style.display).not.toBe("none");
  });

  it("shows a hidden element first so the fade is visible", () => {
    hide(el);
    fadeTo(el, 100, 0.4);
    vi.advanceTimersByTime(300);

    expect(el.style.display).not.toBe("none");
  });
});

describe("slides", () => {
  it("slideUp hides the element at the end", () => {
    slideUp(el, 100);
    vi.advanceTimersByTime(300);

    expect(el.style.display).toBe("none");
  });

  it("slideUp restores the original height rather than leaving it at 0", () => {
    slideUp(el, 100);
    vi.advanceTimersByTime(300);

    expect(el.style.height).toBe("40px");
  });

  it("slideUp restores overflow, which it sets to hidden while running", () => {
    slideUp(el, 100);
    vi.advanceTimersByTime(50);
    expect(el.style.overflow).toBe("hidden");

    vi.advanceTimersByTime(300);
    expect(el.style.overflow).toBe("");
  });

  it("slideDown shows a hidden element", () => {
    hide(el);
    slideDown(el, 100);
    vi.advanceTimersByTime(300);

    expect(el.style.display).not.toBe("none");
  });

  it("slideToggle flips in both directions", () => {
    slideToggle(el, 50);
    vi.advanceTimersByTime(200);
    expect(el.style.display).toBe("none");

    slideToggle(el, 50);
    vi.advanceTimersByTime(200);
    expect(el.style.display).not.toBe("none");
  });
});

describe("show/hide/toggle durations", () => {
  beforeEach(() => {
    vi.useFakeTimers();
    document.body.innerHTML = '<div id="box">content</div>';
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it("shows instantly with no argument", () => {
    const el = document.getElementById("box") as HTMLElement;
    hide(el);

    show(el);

    // No tween ran, so nothing is left mid-animation.
    expect(el.style.display).not.toBe("none");
    expect(el.style.opacity).toBe("");
  });

  it("treats a boolean as a forced state, not a duration", () => {
    const el = document.getElementById("box") as HTMLElement;

    toggle(el, false);

    expect(el.style.display).toBe("none");
    // Instant: an animated toggle would have left the box mid-collapse.
    expect(el.style.height).toBe("");
  });

  it("animates the whole box, not just opacity, when given a duration", () => {
    const el = document.getElementById("box") as HTMLElement;
    hide(el);

    show(el, 400);
    vi.advanceTimersByTime(200);

    // jQuery's genFx("show", true) collapses height, width, opacity and
    // every margin and padding together.
    expect(el.style.opacity).not.toBe("");
    expect(el.style.height).not.toBe("");
    expect(el.style.width).not.toBe("");
    expect(el.style.paddingTop).not.toBe("");
    expect(el.style.marginLeft).not.toBe("");
  });

  it("restores the element's own styles when the animation ends", () => {
    const el = document.getElementById("box") as HTMLElement;
    hide(el);

    show(el, 400);
    vi.advanceTimersByTime(1000);

    expect(el.style.display).not.toBe("none");
    // Every property the tween touched is handed back, not pinned at its
    // final tween value.
    expect(el.style.height).toBe("");
    expect(el.style.width).toBe("");
    expect(el.style.opacity).toBe("");
    expect(el.style.paddingTop).toBe("");
  });

  it("runs the callback of an animated hide once it is hidden", () => {
    const el = document.getElementById("box") as HTMLElement;
    const done = vi.fn();

    hide(el, 400, done);
    vi.advanceTimersByTime(1000);

    expect(done).toHaveBeenCalledTimes(1);
    expect(el.style.display).toBe("none");
  });
});
