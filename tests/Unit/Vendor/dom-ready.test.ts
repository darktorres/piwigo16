import { afterEach, beforeEach, describe, expect, it, vi } from "vitest";

import { ready } from "../../../themes/default/js/vendor/dom";

function setReadyState(state: DocumentReadyState): void {
  Object.defineProperty(document, "readyState", {
    configurable: true,
    get: () => state,
  });
}

beforeEach(() => {
  vi.useFakeTimers();
});

afterEach(() => {
  vi.useRealTimers();
  setReadyState("complete");
});

describe("ready()", () => {
  it("still runs the callback when the document is already parsed", () => {
    // The case a bare DOMContentLoaded listener gets wrong. P48 made every
    // bundle a deferred module, so module top-level code routinely runs
    // after DOMContentLoaded has fired -- registering a listener then would
    // never fire, and the file would silently do nothing.
    setReadyState("complete");
    const fn = vi.fn();

    ready(fn);
    expect(fn).not.toHaveBeenCalled(); // scheduled, not inline

    vi.runAllTimers();
    expect(fn).toHaveBeenCalledTimes(1);
  });

  it("also runs when parsing finished but subresources are still loading", () => {
    setReadyState("interactive");
    const fn = vi.fn();

    ready(fn);
    vi.runAllTimers();

    expect(fn).toHaveBeenCalledTimes(1);
  });

  it("waits for DOMContentLoaded while the document is still loading", () => {
    setReadyState("loading");
    const fn = vi.fn();

    ready(fn);
    vi.runAllTimers();
    expect(fn).not.toHaveBeenCalled();

    document.dispatchEvent(new Event("DOMContentLoaded"));
    expect(fn).toHaveBeenCalledTimes(1);
  });

  it("preserves registration order when already parsed", () => {
    // jQuery schedules rather than running inline precisely so that two
    // modules registering in sequence still run in that sequence.
    setReadyState("complete");
    const order: string[] = [];

    ready(() => order.push("first"));
    ready(() => order.push("second"));
    vi.runAllTimers();

    expect(order).toEqual(["first", "second"]);
  });
});
