import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import {
  closePhotoSwipe,
  openPhotoSwipe,
  photoswipe,
  type PhotoSwipeItem,
} from "../../../../themes/default/js/vendor/widgets/photoswipe";
import { qs } from "../dom-test-helpers";

const ITEMS: PhotoSwipeItem[] = [
  { src: "one.jpg", w: 800, h: 600, title: "First" },
  { src: "two.jpg", w: 800, h: 600, title: "Second" },
  { src: "three.jpg", w: 800, h: 600 },
];

/** jsdom never actually loads an <img>; every real gallery-content path
 * routes through it, so tests that need a slide fully "loaded" fire this
 * once per newly-created <img class="pswp__img"> inside the gallery. */
function resolvePendingImageLoads(): void {
  for (const img of document.querySelectorAll(".pswp .pswp__img")) {
    img.dispatchEvent(new Event("load"));
  }
}

beforeEach(() => {
  vi.useFakeTimers();
  document.body.innerHTML = "";
});

afterEach(() => {
  closePhotoSwipe();
  vi.runAllTimers();
  vi.useRealTimers();
  document.body.innerHTML = "";
});

describe("openPhotoSwipe()", () => {
  it("builds the real pswp DOM structure and shows the requested slide", () => {
    openPhotoSwipe(ITEMS, 1, {});
    resolvePendingImageLoads();

    expect(qs(".pswp")).toBeDefined();
    expect(qs(".pswp__bg")).toBeDefined();
    expect(qs(".pswp__counter").textContent).toBe("2 / 3");
    expect(qs(".pswp__caption__center").textContent).toBe("Second");
  });

  it("clamps an out-of-range index into bounds instead of throwing", () => {
    openPhotoSwipe(ITEMS, 99, {});
    expect(qs(".pswp__counter").textContent).toBe("3 / 3");
  });

  it("does nothing for an empty items array", () => {
    openPhotoSwipe([], 0, {});
    expect(document.querySelector(".pswp")).toBeNull();
  });

  it("replaces an already-open gallery rather than stacking two", () => {
    openPhotoSwipe(ITEMS, 0, {});
    openPhotoSwipe(ITEMS, 2, {});
    expect(document.querySelectorAll(".pswp")).toHaveLength(1);
    expect(qs(".pswp__counter").textContent).toBe("3 / 3");
  });
});

describe("keyboard navigation", () => {
  it("ArrowRight/ArrowLeft move next/prev and wrap around (loop)", () => {
    openPhotoSwipe(ITEMS, 2, {});
    expect(qs(".pswp__counter").textContent).toBe("3 / 3");

    document.dispatchEvent(new KeyboardEvent("keydown", { key: "ArrowRight" }));
    expect(qs(".pswp__counter").textContent).toBe("1 / 3");

    document.dispatchEvent(new KeyboardEvent("keydown", { key: "ArrowLeft" }));
    expect(qs(".pswp__counter").textContent).toBe("3 / 3");
  });

  it("Escape closes the gallery", () => {
    openPhotoSwipe(ITEMS, 0, {});
    document.dispatchEvent(new KeyboardEvent("keydown", { key: "Escape" }));
    vi.runAllTimers();

    expect(document.querySelector(".pswp")).toBeNull();
  });
});

describe("photoswipe() click registration", () => {
  it("opens the clicked element's own group, at its real position", () => {
    document.body.innerHTML = `
      <a id="a" href="a.jpg" rel="grid"></a>
      <a id="b" href="b.jpg" rel="grid"></a>
    `;
    const els = document.querySelectorAll("a");
    photoswipe(els, { getItems: () => ITEMS.slice(0, 2) });

    qs("#b").dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true }));

    expect(qs(".pswp__counter").textContent).toBe("2 / 2");
  });

  it("ignores modified clicks (used for open-in-new-tab)", () => {
    document.body.innerHTML = `<a id="a" href="a.jpg"></a>`;
    photoswipe(qs("#a"), { getItems: () => ITEMS });

    qs("#a").dispatchEvent(new MouseEvent("click", { bubbles: true, cancelable: true, ctrlKey: true }));

    expect(document.querySelector(".pswp")).toBeNull();
  });
});

describe("onChange / onClose callbacks", () => {
  it("fires onChange with the real new index on navigation", () => {
    const onChange = vi.fn();
    openPhotoSwipe(ITEMS, 0, { onChange });

    document.dispatchEvent(new KeyboardEvent("keydown", { key: "ArrowRight" }));

    expect(onChange).toHaveBeenCalledWith(1, expect.objectContaining({ src: "two.jpg" }));
  });

  it("fires onClose once the close animation finishes", () => {
    const onClose = vi.fn();
    openPhotoSwipe(ITEMS, 0, { onClose });

    closePhotoSwipe();
    vi.runAllTimers();

    expect(onClose).toHaveBeenCalledTimes(1);
  });
});
