import { describe, expect, it } from "vitest";

describe("JS test scaffold", () => {
  it("runs in happy-dom environment", () => {
    expect(typeof window).toBe("object");
  });

  it("TypeScript types resolve", () => {
    const x = 42;
    // eslint-disable-next-line sonarjs/no-trivial-assertions -- intentional: this scaffold test's whole point is confirming the test runner can execute real TypeScript at all, not asserting meaningful business logic.
    expect(x).toBe(42);
  });
});
