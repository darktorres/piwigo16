import { beforeEach, describe, expect, it, vi } from "vitest";

import {
  delegate,
  hover,
  off,
  on,
  parseEventSpec,
  trigger,
} from "../../../themes/default/js/vendor/dom";

let el: HTMLElement;

beforeEach(() => {
  document.body.innerHTML = `<div id="t"></div>`;
  el = document.getElementById("t") as HTMLElement;
});

describe("parseEventSpec", () => {
  it("splits type from namespaces on the first dot", () => {
    expect(parseEventSpec("click.apply")).toEqual({
      type: "click",
      namespaces: ["apply"],
    });
  });

  it("sorts multiple namespaces so match order does not matter", () => {
    expect(parseEventSpec("click.b.a")).toEqual({
      type: "click",
      namespaces: ["a", "b"],
    });
  });

  it("treats a leading dot as namespace-only", () => {
    expect(parseEventSpec(".apikey")).toEqual({
      type: "",
      namespaces: ["apikey"],
    });
  });
});

describe("namespaced off()", () => {
  it("removes only the named namespace, leaving other handlers bound", () => {
    const a = vi.fn();
    const b = vi.fn();
    on(el, "click.apply", a);
    on(el, "click.other", b);

    off(el, "click.apply");
    el.click();

    expect(a).not.toHaveBeenCalled();
    expect(b).toHaveBeenCalledTimes(1);
  });

  it("removes every type carrying a namespace when given .ns alone", () => {
    // The real teardown idiom in profile.ts: `.off(".apikey")` after binding
    // both a click and a keydown under that namespace.
    const click = vi.fn();
    const keydown = vi.fn();
    const unrelated = vi.fn();
    on(el, "click.apikey", click);
    on(el, "keydown.apikey", keydown);
    on(el, "click.kept", unrelated);

    off(el, ".apikey");
    el.click();
    el.dispatchEvent(new Event("keydown", { bubbles: true }));

    expect(click).not.toHaveBeenCalled();
    expect(keydown).not.toHaveBeenCalled();
    expect(unrelated).toHaveBeenCalledTimes(1);
  });

  it("removes every handler of a type when given a bare type", () => {
    const a = vi.fn();
    const b = vi.fn();
    on(el, "click.one", a);
    on(el, "click.two", b);

    off(el, "click");
    el.click();

    expect(a).not.toHaveBeenCalled();
    expect(b).not.toHaveBeenCalled();
  });

  it("supports the off-then-on replace idiom without stacking handlers", () => {
    // batchManagerFilter.ts and user_list.ts both rebind this way; without
    // real namespace removal the old handler would survive and fire twice.
    const first = vi.fn();
    const second = vi.fn();
    on(el, "click.apply", first);
    off(el, "click.apply");
    on(el, "click.apply", second);

    el.click();

    expect(first).not.toHaveBeenCalled();
    expect(second).toHaveBeenCalledTimes(1);
  });

  it("narrows by handler reference when one is passed", () => {
    const a = vi.fn();
    const b = vi.fn();
    on(el, "click.ns", a);
    on(el, "click.ns", b);

    off(el, "click.ns", a);
    el.click();

    expect(a).not.toHaveBeenCalled();
    expect(b).toHaveBeenCalledTimes(1);
  });
});

describe("trigger() with namespaces", () => {
  it("reaches a handler bound with the same namespace", () => {
    // jqtree's tree.open/tree.close/tree.move are exactly this shape: type
    // `tree` with a namespace, not a literal `tree.open` event type.
    const handler = vi.fn();
    on(el, "tree.open", handler);

    trigger(el, "tree.open");

    expect(handler).toHaveBeenCalledTimes(1);
  });

  it("does not reach a handler bound under a different namespace", () => {
    const open = vi.fn();
    const close = vi.fn();
    on(el, "tree.open", open);
    on(el, "tree.close", close);

    trigger(el, "tree.open");

    expect(open).toHaveBeenCalledTimes(1);
    expect(close).not.toHaveBeenCalled();
  });

  it("reaches every handler of the type when triggered without a namespace", () => {
    const open = vi.fn();
    const close = vi.fn();
    on(el, "tree.open", open);
    on(el, "tree.close", close);

    trigger(el, "tree");

    expect(open).toHaveBeenCalledTimes(1);
    expect(close).toHaveBeenCalledTimes(1);
  });

  it("carries detail and bubbles", () => {
    const seen: unknown[] = [];
    on(document.body, "tree.move", (event) => {
      seen.push((event as CustomEvent).detail);
    });

    trigger(el, "tree.move", { moved: 3 });

    expect(seen).toEqual([{ moved: 3 }]);
  });

  it("a real native event reaches every handler of its type", () => {
    const nsHandler = vi.fn();
    on(el, "click.apply", nsHandler);

    el.click();

    expect(nsHandler).toHaveBeenCalledTimes(1);
  });
});

describe("whitespace-separated specs", () => {
  let multi: HTMLElement;

  beforeEach(() => {
    document.body.innerHTML = '<div id="multi"></div>';
    multi = document.getElementById("multi") as HTMLElement;
  });

  it("binds one handler to several types", () => {
    const handler = vi.fn();
    on(multi, "mouseleave click", handler);

    multi.dispatchEvent(new Event("mouseleave"));
    multi.click();

    expect(handler).toHaveBeenCalledTimes(2);
  });

  it("unbinds every type in the spec", () => {
    const handler = vi.fn();
    on(multi, "mouseleave click", handler);
    off(multi, "mouseleave click", handler);

    multi.dispatchEvent(new Event("mouseleave"));
    multi.click();

    expect(handler).not.toHaveBeenCalled();
  });

  it("unbinds only the types named", () => {
    const handler = vi.fn();
    on(multi, "mouseleave click focus", handler);
    off(multi, "click", handler);

    multi.click();
    multi.dispatchEvent(new Event("mouseleave"));

    expect(handler).toHaveBeenCalledTimes(1);
  });

  it("keeps per-type namespaces when several types are given", () => {
    const handler = vi.fn();
    on(multi, "click.apply mouseleave.apply", handler);

    off(multi, ".apply");
    multi.click();

    expect(handler).not.toHaveBeenCalled();
  });

  it("an empty spec still means every type", () => {
    const handler = vi.fn();
    on(multi, "click", handler);
    off(multi, "");

    multi.click();

    expect(handler).not.toHaveBeenCalled();
  });

  it("tolerates surrounding and repeated whitespace", () => {
    const handler = vi.fn();
    on(multi, "  click   mouseleave  ", handler);

    multi.click();
    multi.dispatchEvent(new Event("mouseleave"));

    expect(handler).toHaveBeenCalledTimes(2);
  });
});

describe("delegated handlers", () => {
  let root: HTMLElement;

  beforeEach(() => {
    document.body.innerHTML =
      '<div id="root">' +
      '<a class="pick" href="#"><span id="inner">x</span></a>' +
      '<b id="other"></b>' +
      "</div>";
    root = document.getElementById("root") as HTMLElement;
  });

  it("runs for an event originating inside a match", () => {
    const handler = vi.fn();
    delegate(root, "click", ".pick", handler);

    (document.getElementById("inner") as HTMLElement).click();

    expect(handler).toHaveBeenCalledTimes(1);
  });

  it("calls the handler with the matched descendant, not the delegate", () => {
    let seen = "";
    delegate(root, "click", ".pick", function (this: unknown) {
      seen = (this as Element).className;
    });

    (document.getElementById("inner") as HTMLElement).click();

    // `this` is the anchor the selector matched -- the whole point of
    // delegation, and what a bare addEventListener cannot give.
    expect(seen).toBe("pick");
  });

  it("ignores an event with no matching ancestor", () => {
    const handler = vi.fn();
    delegate(root, "click", ".pick", handler);

    (document.getElementById("other") as HTMLElement).click();

    expect(handler).not.toHaveBeenCalled();
  });

  it("does not treat the delegate itself as a candidate", () => {
    const handler = vi.fn();
    // The walk stops *before* the delegate, so a selector the delegate
    // itself matches still does not fire for it.
    delegate(root, "click", "#root", handler);

    (document.getElementById("inner") as HTMLElement).click();

    expect(handler).not.toHaveBeenCalled();
  });

  it("fires once per matching ancestor, innermost first", () => {
    document.body.innerHTML =
      '<div id="root"><div class="m" id="outer"><div class="m" id="mid">' +
      '<i id="leaf"></i></div></div></div>';
    const seen: string[] = [];
    delegate(
      document.getElementById("root") as HTMLElement,
      "click",
      ".m",
      function (this: unknown) {
        seen.push((this as Element).id);
      }
    );

    (document.getElementById("leaf") as HTMLElement).click();

    expect(seen).toEqual(["mid", "outer"]);
  });

  it("stops the walk when a handler stops propagation", () => {
    document.body.innerHTML =
      '<div id="root"><div class="m" id="outer"><div class="m" id="mid">' +
      '<i id="leaf"></i></div></div></div>';
    const seen: string[] = [];
    delegate(
      document.getElementById("root") as HTMLElement,
      "click",
      ".m",
      function (this: unknown, event) {
        seen.push((this as Element).id);
        event.stopPropagation();
      }
    );

    (document.getElementById("leaf") as HTMLElement).click();

    expect(seen).toEqual(["mid"]);
  });

  it("leaves the event's own stopPropagation in place afterwards", () => {
    const handler = vi.fn();
    delegate(root, "click", ".pick", handler);

    let ownProperty = true;
    document.body.addEventListener("click", (event) => {
      ownProperty = Object.prototype.hasOwnProperty.call(
        event,
        "stopPropagation"
      );
    });

    (document.getElementById("inner") as HTMLElement).click();

    expect(ownProperty).toBe(false);
  });
});

describe("binding to a whole set", () => {
  let set: NodeListOf<HTMLElement>;

  beforeEach(() => {
    document.body.innerHTML =
      '<b class="s"></b><b class="s"></b><b class="s"></b>';
    set = document.body.querySelectorAll<HTMLElement>(".s");
  });

  it("binds every element, as jQuery does", () => {
    const handler = vi.fn();
    on(set, "click", handler);

    set.forEach((item) => {
      item.click();
    });

    expect(handler).toHaveBeenCalledTimes(3);
  });

  it("unbinds every element", () => {
    const handler = vi.fn();
    on(set, "click.ns", handler);
    off(set, ".ns");

    set.forEach((item) => {
      item.click();
    });

    expect(handler).not.toHaveBeenCalled();
  });

  it("triggers a fresh event per element", () => {
    // One Event object cannot be dispatched twice, so a shared instance
    // would reach only the first element.
    const seen: EventTarget[] = [];
    on(set, "custom", (event) => {
      if (event.currentTarget !== null) {
        seen.push(event.currentTarget);
      }
    });

    trigger(set, "custom");

    expect(seen).toHaveLength(3);
  });

  it("treats a single element as a set of one", () => {
    const handler = vi.fn();
    const [first] = Array.from(set);
    on(first as HTMLElement, "click", handler);

    (first as HTMLElement).click();

    expect(handler).toHaveBeenCalledTimes(1);
  });
});

describe("hover", () => {
  it("binds separate mouseenter/mouseleave handlers", () => {
    const onIn = vi.fn();
    const onOut = vi.fn();
    hover(el, onIn, onOut);

    el.dispatchEvent(new MouseEvent("mouseenter"));
    expect(onIn).toHaveBeenCalledTimes(1);
    expect(onOut).not.toHaveBeenCalled();

    el.dispatchEvent(new MouseEvent("mouseleave"));
    expect(onOut).toHaveBeenCalledTimes(1);
  });

  it("binds the same handler to both when only one is given", () => {
    const handler = vi.fn();
    hover(el, handler);

    el.dispatchEvent(new MouseEvent("mouseenter"));
    el.dispatchEvent(new MouseEvent("mouseleave"));

    expect(handler).toHaveBeenCalledTimes(2);
  });
});
