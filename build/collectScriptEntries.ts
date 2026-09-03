import { readdirSync, readFileSync } from "fs";
import { join, resolve } from "path";

// Real Vite bundle entries and knip's own `entry` field come from the
// same underlying fact: which `.ts` files a PHP `AssetContribution::script()`
// call registers. Deriving both from that PHP source directly means a
// file move/rename/merge only needs its PHP registration updated -- never
// a matching knip/Vite config edit (P51-B, docs/PLAN.md).
//
// `build/vitals.ts` (resolved through a separate, hardcoded
// `ViteManifest::resolve('build/vitals.ts')` call in `PageTailRenderer.php`,
// never an `AssetContribution::script()` call) and `build/noop.ts` (a pure
// `ViteManifestTest.php` fixture, no real page loads it) are the only 2
// real exceptions -- verified, not assumed: every other real bundle entry,
// including `themes/standard_pages/js/profile.ts`/`standard_pages.ts`, does
// have a real `AssetContribution::script()` registration.
const KNOWN_NON_REGISTERED_ENTRIES = ["build/vitals.ts", "build/noop.ts"];

// `import.meta.dirname` over `fileURLToPath(new URL(...))`: this module
// is loaded from 3 different contexts (vite.config.ts, knip.config.ts,
// and this file's own Vitest unit test) -- Vitest's own module transform
// doesn't hand back a real `file://`-scheme `import.meta.url`, so
// `new URL("../src", import.meta.url)` throws there. `import.meta.dirname`
// needs no URL parsing at all and works in all 3.
const srcDir = join(import.meta.dirname, "../src");

function findPhpFiles(dir: string): string[] {
  const files: string[] = [];
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = resolve(dir, entry.name);
    if (entry.isDirectory()) {
      files.push(...findPhpFiles(full));
    } else if (entry.name.endsWith(".php")) {
      files.push(full);
    }
  }
  return files;
}

/**
 * Real `AssetContribution::script('id', 'path.ts', ...)` registrations
 * always pass `$id`/`$path` positionally as plain string literals (the
 * only 2 exceptions are `PageAssets.php`'s own internal
 * `mergeScript()`/`withLoadMode()` helpers, which re-wrap an
 * already-known contribution via named `path: $variable` args -- these
 * have no literal second string and are skipped below, no special-casing
 * needed).
 */
function extractTsPath(callArgs: string): string | undefined {
  const strings = [...callArgs.matchAll(/'([^']*)'/g)].map((m) => m[1]);
  const [, path] = strings;
  return path?.endsWith(".ts") === true ? path : undefined;
}

export function collectScriptEntries(): string[] {
  const paths = new Set<string>(KNOWN_NON_REGISTERED_ENTRIES);

  for (const file of findPhpFiles(srcDir)) {
    const content = readFileSync(file, "utf8");
    const callPattern = /AssetContribution::script\(([^;]*?)\)/gs;
    let match;
    while ((match = callPattern.exec(content))) {
      const [, callArgs] = match;
      if (callArgs === undefined) {
        continue;
      }
      const tsPath = extractTsPath(callArgs);
      if (tsPath !== undefined) {
        paths.add(tsPath);
      }
    }
  }

  return [...paths].sort();
}
