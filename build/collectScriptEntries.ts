import { existsSync, readdirSync, readFileSync } from "fs";
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
// Every theme/plugin owns its own PSR-4 `src/` (registered at boot time via
// its own `theme.json`/`plugin.json` `autoload.psr-4`, not the root
// composer.json -- see `ThemeRegistry::registerAutoload()`), so a theme- or
// plugin-owned `AssetContribution::script()` call lives outside `srcDir`
// above and was never scanned for -- confirmed real and currently latent
// (zero theme/plugin registers a script today; P29.6, the modus port, is
// what first triggers it).
const themesDir = join(import.meta.dirname, "../themes");
const pluginsDir = join(import.meta.dirname, "../plugins");

function findThemeAndPluginSrcDirs(root: string): string[] {
  if (!existsSync(root)) {
    return [];
  }
  return readdirSync(root, { withFileTypes: true })
    .filter((entry) => entry.isDirectory())
    .map((entry) => join(root, entry.name, "src"))
    .filter((dir) => existsSync(dir));
}

function findPhpFiles(dir: string): string[] {
  if (!existsSync(dir)) {
    return [];
  }
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

/**
 * @param roots Overridable only for `collectScriptEntries.test.ts`'s own
 *   fixture-tree assertions -- every real caller (`vite.config.ts`,
 *   `knip.config.ts`) uses the default, real repo-relative roots.
 */
export function collectScriptEntries(
  roots: { src: string; themes: string; plugins: string } = {
    src: srcDir,
    themes: themesDir,
    plugins: pluginsDir,
  },
): string[] {
  const paths = new Set<string>(KNOWN_NON_REGISTERED_ENTRIES);
  const scanDirs = [
    roots.src,
    ...findThemeAndPluginSrcDirs(roots.themes),
    ...findThemeAndPluginSrcDirs(roots.plugins),
  ];

  for (const dir of scanDirs) {
    for (const file of findPhpFiles(dir)) {
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
  }

  return [...paths].sort((a, b) => a.localeCompare(b));
}
