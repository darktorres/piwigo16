import { beforeEach, describe, expect, it, vi } from "vitest";

import {
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
