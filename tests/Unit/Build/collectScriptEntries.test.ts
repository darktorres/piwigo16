import { existsSync } from "node:fs";
import { join } from "node:path";
import { describe, expect, it } from "vitest";
import { collectScriptEntries } from "../../../build/collectScriptEntries";

const REPO_ROOT = join(import.meta.dirname, "../../..");

describe("collectScriptEntries()", () => {
  it("returns a non-empty list of real, existing .ts files with no duplicates", () => {
    const entries = collectScriptEntries();

    expect(entries.length).toBeGreaterThan(0);
    expect(entries).toHaveLength(new Set(entries).size);
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

  it("discovers a theme- and a plugin-owned AssetContribution::script() call under themes/*/src and plugins/*/src (P29.6 -- confirmed real and latent until a theme/plugin actually registers a script)", () => {
    const fixtureRoot = join(import.meta.dirname, "../../Fixtures/Build/CollectScriptEntries");
    const entries = collectScriptEntries({
      src: join(fixtureRoot, "no-such-core-src-dir"),
      themes: join(fixtureRoot, "themes"),
      plugins: join(fixtureRoot, "plugins"),
    });

    expect(entries).toContain("themes/fixture-theme/src/fixture-theme.ts");
    expect(entries).toContain("plugins/fixture-plugin/src/fixture-plugin.ts");
  });

  it("tolerates a src/themes/plugins root that doesn't exist, rather than throwing", () => {
    const fixtureRoot = join(import.meta.dirname, "../../Fixtures/Build/CollectScriptEntries");
    expect(() =>
      collectScriptEntries({
        src: join(fixtureRoot, "no-such-core-src-dir"),
        themes: join(fixtureRoot, "no-such-themes-dir"),
        plugins: join(fixtureRoot, "no-such-plugins-dir"),
      }),
    ).not.toThrow();
  });
});
