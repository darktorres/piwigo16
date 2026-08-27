import { beforeEach, describe, expect, it } from "vitest";

import {
  defaultDisplay,
  hide,
  isHiddenForDisplay,
  show,
  toggle,
} from "../../../themes/default/js/vendor/dom";

beforeEach(() => {
  document.head.innerHTML = "";
  document.body.innerHTML = "";
});

function mount(html: string): HTMLElement {
  document.body.innerHTML = html;
  return document.body.firstElementChild as HTMLElement;
}

describe("isHiddenForDisplay", () => {
  it("is display-based, not offset-based", () => {
    // The distinction matters: happy-dom reports offsetWidth/Height as 0 for
    // everything, so an offset-based test would call this hidden. jQuery uses
    // the display test for show/hide and the offset test only for `:visible`.
    const el = mount(`<div style="width:50px;height:20px">x</div>`);
    expect(isHiddenForDisplay(el)).toBe(false);
  });

  it("reports an inline display:none as hidden", () => {
    expect(isHiddenForDisplay(mount(`<div style="display:none">x</div>`))).toBe(
      true
    );
  });

  it("reports a detached element as hidden", () => {
    const orphan = document.createElement("div");
    expect(isHiddenForDisplay(orphan)).toBe(true);
  });
});

// happy-dom ships incomplete UA default styles: `div` resolves to "block"
// and `li` to "list-item", but `span` and `td` both resolve to "". So the
// branch of show() that substitutes defaultDisplay() for a tag can only be
// exercised here with the tags it does know, and the inline case -- a <span>
// coming back as `inline` rather than `block` -- has to be proven in a
// Browser test, where real UA defaults exist. The implementation is left
// faithful to jQuery rather than bent to suit this environment.
describe("hide() / show() remember the previous display", () => {
  it("restores an explicitly inline element to inline, not block", () => {
    // The whole point of the olddisplay memory. A naive
    // show() = display:block would turn this into a block.
    const el = mount(`<span style="display:inline">x</span>`);
    hide(el);
    expect(el.style.display).toBe("none");
    show(el);
    expect(el.style.display).toBe("inline");
  });

  it("restores an explicit inline style exactly", () => {
    const el = mount(`<div style="display:flex">x</div>`);
    hide(el);
    expect(el.style.display).toBe("none");
    show(el);
    expect(el.style.display).toBe("flex");
  });

  it("pins the computed display inline, rather than restoring cascade control", () => {
    // Surprising but real, and worth pinning: for an element with no inline
    // display, hide() stores the *computed* value ("block") and show() writes
    // that back as an inline style. The element does not go back to being
    // governed by the stylesheet -- so an element whose display comes from a
    // media query stays pinned to whatever it was when it was hidden.
    // Reproducing this is the point of porting rather than reimplementing.
    const el = mount(`<div>x</div>`);
    expect(el.style.display).toBe("");
    hide(el);
    show(el);
    expect(el.style.display).toBe("block");
  });

  it("show() on an element hidden by a stylesheet uses the tag default", () => {
    document.head.innerHTML = `<style>.hidden-by-css { display: none }</style>`;
    const el = mount(`<div class="hidden-by-css">x</div>`);
    show(el);
    // Not "" -- that would leave the stylesheet's none in force. jQuery
    // substitutes the browser default for the tag.
    expect(el.style.display).toBe("block");
  });

  it("hide() is idempotent and show() still restores the original", () => {
    const el = mount(`<span style="display:inline">x</span>`);
    hide(el);
    hide(el);
    show(el);
    expect(el.style.display).toBe("inline");
  });
});

describe("show()/hide() over a set", () => {
  it("applies to every element and remembers each one's own display", () => {
    document.body.innerHTML = `<span id="a" style="display:inline">a</span><div id="b" style="display:flex">b</div>`;
    const nodes = document.querySelectorAll("#a, #b");
    hide(nodes);
    expect((document.getElementById("a") as HTMLElement).style.display).toBe(
      "none"
    );
    expect((document.getElementById("b") as HTMLElement).style.display).toBe(
      "none"
    );

    show(nodes);
    expect((document.getElementById("a") as HTMLElement).style.display).toBe(
      "inline"
    );
    expect((document.getElementById("b") as HTMLElement).style.display).toBe(
      "flex"
    );
  });
});

describe("toggle()", () => {
  it("flips visibility and preserves the remembered display", () => {
    const el = mount(`<span style="display:inline">x</span>`);
    toggle(el);
    expect(el.style.display).toBe("none");
    toggle(el);
    expect(el.style.display).toBe("inline");
  });

  it("honours an explicit force argument", () => {
    const el = mount(`<span style="display:inline">x</span>`);
    toggle(el, true);
    expect(el.style.display).not.toBe("none");
    toggle(el, false);
    expect(el.style.display).toBe("none");
  });
});

describe("defaultDisplay", () => {
  it("returns the browser default for a tag", () => {
    // Only the tags happy-dom actually models -- see the note above.
    expect(defaultDisplay("DIV")).toBe("block");
    expect(defaultDisplay("LI")).toBe("list-item");
  });

  it("caches per nodeName", () => {
    expect(defaultDisplay("LI")).toBe(defaultDisplay("LI"));
  });
});
