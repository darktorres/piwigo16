import { existsSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";
import { collectScriptEntries } from "../../../build/collectScriptEntries";

const REPO_ROOT = join(import.meta.dirname, "../../..");

describe("collectScriptEntries()", () => {
  it("returns a non-empty list of real, existing .ts files with no duplicates", () => {
    const entries = collectScriptEntries();

    expect(entries.length).toBeGreaterThan(0);
    expect(entries.length).toBe(new Set(entries).size);
    for (const entry of entries) {
      expect(entry.endsWith(".ts")).toBe(true);
      expect(existsSync(join(REPO_ROOT, entry))).toBe(true);
    }
  });

  it("includes the 2 known build-tooling entries not reachable via AssetContribution::script()", () => {
    const entries = collectScriptEntries();

    expect(entries).toContain("build/vitals.ts");
    expect(entries).toContain("build/noop.ts");
  });

  it("includes real, long-lived registered pages, as a floor against a broken regex", () => {
    const entries = collectScriptEntries();

    expect(entries).toContain("themes/admin/default/js/users/list.ts");
    expect(entries).toContain("themes/admin/default/js/tags.ts");
    expect(entries).toContain("themes/default/js/mcs.ts");
  });
});
