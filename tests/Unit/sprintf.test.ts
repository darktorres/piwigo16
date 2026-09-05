import { describe, expect, it } from "vitest";

import { getRandomInt, sprintf } from "../../themes/default/js/sprintf";

describe("sprintf()", () => {
  it("substitutes %d", () => {
    expect(sprintf("%d of %d photos selected", 3, 10)).toBe(
      "3 of 10 photos selected",
    );
  });

  it("substitutes %s", () => {
    expect(sprintf("%s and %s", "cats", "dogs")).toBe("cats and dogs");
  });

  it("collapses a literal %% to a single percent sign", () => {
    expect(sprintf("literal %% percent")).toBe("literal % percent");
  });

  it("passes plain literal text through unchanged", () => {
    expect(sprintf("no specifiers here")).toBe("no specifiers here");
  });

  it("zero-pads %d to the requested width", () => {
    expect(sprintf("%05d", 42)).toBe("00042");
  });

  it("formats %f to the requested precision", () => {
    expect(sprintf("%.2f", 3.14159)).toBe("3.14");
  });

  it("truncates %s to a requested precision", () => {
    expect(sprintf("%.3s", "abcdef")).toBe("abc");
  });

  it("formats %x/%b/%c", () => {
    expect(sprintf("%x", 255)).toBe("ff");
    expect(sprintf("%b", 5)).toBe("101");
    expect(sprintf("%c", 65)).toBe("A");
  });

  it("mixes literal text, %%, and a specifier in one pattern", () => {
    expect(sprintf("100%% of %d done", 7)).toBe("100% of 7 done");
  });

  it("throws when a specifier has no matching argument", () => {
    expect(() => sprintf("%d and %d", 1)).toThrow("Too few arguments.");
  });
});

describe("getRandomInt()", () => {
  it("stays within [min, max)", () => {
    for (let i = 0; i < 200; i++) {
      const value = getRandomInt(8, 15);
      expect(value).toBeGreaterThanOrEqual(8);
      expect(value).toBeLessThan(15);
    }
  });

  it("returns min when the range is empty", () => {
    expect(getRandomInt(5, 5)).toBe(5);
    expect(getRandomInt(5, 3)).toBe(5);
  });
});
