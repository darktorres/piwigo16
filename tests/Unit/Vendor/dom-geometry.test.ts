import { beforeEach, describe, expect, it } from "vitest";

import {
  css,
  cssValue,
  height,
  innerHeight,
  innerWidth,
  offset,
  offsetParent,
  outerHeight,
  outerWidth,
  position,
  width,
  windowHeight,
  windowWidth,
} from "../../../themes/default/js/vendor/dom";

// happy-dom reports offsetWidth/offsetHeight as 0 and getBoundingClientRect
// as an all-zero rect, so every measurement below exercises the
// computed-style fallback path -- which is also the path that runs in a real
// browser for a `display: none` element, the case switchbox depends on.
// Absolute viewport geometry (offset(), position() against a laid-out page)
// is not observable here and is covered by the Browser tests instead.

function box(style: string): HTMLElement {
  document.body.innerHTML = `<div id="probe" style="${style}"></div>`;

  return document.getElementById("probe") as HTMLElement;
}

describe("box-model dimensions", () => {
  it("reports the content box for width()/height()", () => {
    const el = box("width:100px;height:50px;padding:5px;border:2px solid");

    expect(width(el)).toBe(100);
    expect(height(el)).toBe(50);
  });

  it("adds padding but not border for innerWidth()/innerHeight()", () => {
    const el = box("width:100px;height:50px;padding:5px;border:2px solid");

    expect(innerWidth(el)).toBe(110);
    expect(innerHeight(el)).toBe(60);
  });

  it("adds padding and border for outerWidth()/outerHeight()", () => {
    const el = box("width:100px;height:50px;padding:5px;border:2px solid");

    expect(outerWidth(el)).toBe(114);
    expect(outerHeight(el)).toBe(64);
  });

  it("adds margins only when asked", () => {
    const el = box(
      "width:100px;height:50px;padding:5px;border:2px solid;margin:3px 7px"
    );

    expect(outerWidth(el)).toBe(114);
    expect(outerWidth(el, true)).toBe(128);
    expect(outerHeight(el)).toBe(64);
    expect(outerHeight(el, true)).toBe(70);
  });

  it("treats a declared width as the border box under border-box sizing", () => {
    const el = box(
      "box-sizing:border-box;width:100px;height:50px;padding:5px;border:2px solid"
    );

    // The 100px now *is* the outer measurement, so the extras come off
    // rather than going on.
    expect(outerWidth(el)).toBe(100);
    expect(innerWidth(el)).toBe(96);
    expect(width(el)).toBe(86);
    expect(outerHeight(el)).toBe(50);
    expect(height(el)).toBe(36);
  });

  it("still measures an element that is display:none", () => {
    const el = box("display:none;width:80px;padding:10px;border:1px solid");

    expect(width(el)).toBe(80);
    expect(outerWidth(el)).toBe(102);
  });

  it("forces a hidden element into layout to measure it", () => {
    // The failure this guards against was found live, not here: a
    // display:none element has no box, so every measurement of it reads
    // zero unless it is briefly laid out first. Stand in for a real
    // engine by making offsetWidth answer only while the swap is applied.
    const el = box("display:none");
    let sawSwap: Record<string, string> | null = null;
    Object.defineProperty(el, "offsetWidth", {
      configurable: true,
      get(): number {
        if (el.style.display !== "block") {
          return 0;
        }
        sawSwap = {
          position: el.style.position,
          visibility: el.style.visibility,
          display: el.style.display,
        };

        return 120;
      },
    });

    expect(outerWidth(el)).toBe(120);
    // Laid out, but absolutely positioned and invisible, so measuring it
    // cannot reflow the page or flash it on screen.
    expect(sawSwap).toEqual({
      position: "absolute",
      visibility: "hidden",
      display: "block",
    });
  });

  it("leaves no trace of the swap behind", () => {
    const el = box("display:none;position:relative;width:40px");
    const before = el.style.cssText;

    outerWidth(el);

    // `visibility` was never declared and must come back undeclared, not
    // as an empty leftover; `position` must come back as it was.
    expect(el.style.cssText).toBe(before);
    expect(el.style.getPropertyValue("visibility")).toBe("");
  });

  it("does not swap an element that is merely invisible", () => {
    // `visibility: hidden` still occupies a box, so there is nothing to
    // force -- only `display: none` and the table displays qualify.
    const el = box("visibility:hidden;width:60px");
    let swapped = false;
    Object.defineProperty(el, "offsetWidth", {
      configurable: true,
      get(): number {
        swapped = swapped || el.style.display === "block";

        return 0;
      },
    });

    expect(width(el)).toBe(60);
    expect(swapped).toBe(false);
  });

  it("falls back to zero when nothing is declared", () => {
    const el = box("");

    expect(width(el)).toBe(0);
    expect(outerWidth(el, true)).toBe(0);
  });

  it("ignores a non-px declared size rather than returning NaN", () => {
    // jQuery returns the raw "50%" string here and stops; this returns a
    // number, the one deliberate deviation, and the padding still counts.
    const el = box("width:50%;padding:4px");

    expect(outerWidth(el)).toBe(58);
    expect(Number.isNaN(outerWidth(el))).toBe(false);
  });
});

describe("window dimensions", () => {
  it("reads the document element's client box, not window.innerWidth", () => {
    // The two differ by the scrollbar; jQuery uses the former.
    Object.defineProperty(document.documentElement, "clientWidth", {
      configurable: true,
      value: 1000,
    });
    Object.defineProperty(document.documentElement, "clientHeight", {
      configurable: true,
      value: 700,
    });

    expect(windowWidth()).toBe(1000);
    expect(windowHeight()).toBe(700);
    expect(window.innerWidth).not.toBe(1000);
  });
});

describe("offset and offsetParent", () => {
  it("returns a zeroed offset for a detached node rather than throwing", () => {
    const orphan = document.createElement("div");

    expect(offset(orphan)).toEqual({ top: 0, left: 0 });
  });

  it("walks past statically positioned ancestors", () => {
    document.body.innerHTML =
      '<div id="positioned" style="position:relative">' +
      '<div id="static" style="position:static">' +
      '<span id="leaf"></span>' +
      "</div></div>";
    const leaf = document.getElementById("leaf") as HTMLElement;
    const positioned = document.getElementById("positioned") as HTMLElement;
    const staticParent = document.getElementById("static") as HTMLElement;

    // happy-dom leaves offsetParent undefined, so drive the walk explicitly:
    // it must skip the static ancestor and stop at the relative one.
    Object.defineProperty(leaf, "offsetParent", {
      configurable: true,
      value: staticParent,
    });
    Object.defineProperty(staticParent, "offsetParent", {
      configurable: true,
      value: positioned,
    });

    expect(offsetParent(leaf)).toBe(positioned);
  });

  it("falls back to the document element when there is no offset parent", () => {
    const el = box("");

    expect(offsetParent(el)).toBe(document.documentElement);
  });
});

describe("position", () => {
  it("subtracts the element's own margins", () => {
    const el = box("margin-top:11px;margin-left:13px");

    // Every rect is zero here, so what is left is exactly the margin
    // correction -- the part an offsetTop/offsetLeft translation drops.
    expect(position(el)).toEqual({ top: -11, left: -13 });
  });

  it("ignores the offset parent for a fixed element", () => {
    document.body.innerHTML =
      '<div style="position:relative;border:5px solid">' +
      '<div id="probe" style="position:fixed;margin:4px"></div>' +
      "</div>";
    const el = document.getElementById("probe") as HTMLElement;

    // No parent border correction is applied on this branch, so only the
    // element's own margin comes off.
    expect(position(el)).toEqual({ top: -4, left: -4 });
  });
});

describe("css", () => {
  beforeEach(() => {
    document.body.innerHTML = "";
  });

  it("appends px to a bare number", () => {
    const el = box("");

    css(el, "left", 12);

    expect(el.style.left).toBe("12px");
  });

  it("leaves a unitless property unitless", () => {
    const el = box("");

    css(el, "opacity", 0.5);
    css(el, "zIndex", 3);

    expect(el.style.opacity).toBe("0.5");
    expect(el.style.zIndex).toBe("3");
  });

  it("passes a string through untouched", () => {
    const el = box("");

    css(el, "width", "50%");

    expect(el.style.width).toBe("50%");
  });

  it("hyphenates a camelCase property name", () => {
    const el = box("");

    css(el, "marginTop", 6);

    expect(el.style.marginTop).toBe("6px");
  });

  it("applies to every element of a set", () => {
    document.body.innerHTML =
      '<i class="t"></i><i class="t"></i><i class="t"></i>';
    const all = document.querySelectorAll<HTMLElement>(".t");

    css(all, "visibility", "hidden");

    expect(
      Array.from(all).every((el) => el.style.visibility === "hidden")
    ).toBe(true);
  });
});

describe("css NaN guard", () => {
  it("writes nothing rather than 'NaNpx'", () => {
    document.body.innerHTML = '<div id="probe" style="left:4px"></div>';
    const el = document.getElementById("probe") as HTMLElement;

    css(el, "left", Number.NaN);

    expect(el.style.left).toBe("4px");
  });
});

describe("cssValue", () => {
  it("reads the computed value, not the inline one", () => {
    document.body.innerHTML =
      '<div id="probe" style="background-color:rgb(1, 2, 3)"></div>';
    const el = document.getElementById("probe") as HTMLElement;

    expect(cssValue(el, "background-color")).toBe("rgb(1, 2, 3)");
  });

  it("accepts a camelCase property name", () => {
    document.body.innerHTML = '<div id="probe" style="margin-top:9px"></div>';
    const el = document.getElementById("probe") as HTMLElement;

    expect(cssValue(el, "marginTop")).toBe("9px");
  });

  it("returns width as a px string, unlike width() which returns a number", () => {
    document.body.innerHTML =
      '<div id="probe" style="width:70px;padding:3px"></div>';
    const el = document.getElementById("probe") as HTMLElement;

    // The asymmetry is jQuery's: .css("width") is a string, .width() a
    // number, and both mean the content box for a content-box element.
    expect(cssValue(el, "width")).toBe("70px");
    expect(width(el)).toBe(70);
  });

  it("measures width through the box hooks for a hidden element", () => {
    document.body.innerHTML =
      '<div id="probe" style="display:none;width:45px"></div>';
    const el = document.getElementById("probe") as HTMLElement;

    expect(cssValue(el, "width")).toBe("45px");
  });

  it("returns an empty string for an undeclared property", () => {
    document.body.innerHTML = '<div id="probe"></div>';
    const el = document.getElementById("probe") as HTMLElement;

    expect(cssValue(el, "z-index")).toBe("");
  });
});
