import { describe, expect, it } from "vitest";

describe("JS test scaffold", () => {
  it("runs in happy-dom environment", () => {
    expect(typeof window).toBe("object");
  });

  it("TypeScript types resolve", () => {
    const x = 42;
    expect(x).toBe(42);
  });
});
