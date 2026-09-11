#!/usr/bin/env node
// Bundles already-ported plugins'/themes' own JS entries (sibling
// ../piwigo16-plugins, ../piwigo16-themes repos, <id>_17.0.0/js/*.ts) into
// self-contained ES modules, written back into that same port's own dist/ --
// so it becomes part of the sibling repo's own tracked content and ships
// inside its zip. Globbed fresh on every run, so a newly ported extension's
// own js/ directory is picked up with no config change here (same
// "no ../piwigo16-* directory means nothing to do, exit 0" shape as
// analyse-ported-extensions.sh).
//
// Why this exists: a ported extension's own PHP AssetContribution::script()
// call resolves through PageAssets::resolvePath() -- when ViteManifest has no
// matching entry (true for every extension-owned path, since this repo's own
// Vite build never scans outside its own tree), it falls back to serving the
// given path as-is. That already IS how the majority of this app's own theme
// JS is served today (un-bundled .ts sources are never valid browser JS, so
// only entries already migrated to plain/bundled .js work this way) -- this
// script produces that same shape for a ported extension, whose real .ts
// source lives outside this repo and can never become a rollupOptions.input
// entry of this repo's own vite.config.ts. The zip that ships from the
// sibling repo must already contain the built output ("PEM serves zip files
// that are fully built" -- there is no build step on a live install).
//
// Run manually whenever a ported extension's own JS source changes, then
// commit the resulting dist/ output in that same sibling repo alongside the
// source -- this is not part of `bun run build` and never touches this
// repo's own dist/.
//
//   node tools/build-ported-extension-assets.mjs
import { existsSync, readdirSync, rmSync } from "node:fs";
import { join } from "node:path";
import { build } from "vite";

const SIBLING_DIRS = ["../piwigo16-plugins", "../piwigo16-themes"];

function findPortDirs() {
  const ports = [];
  for (const siblingDir of SIBLING_DIRS) {
    if (!existsSync(siblingDir)) continue;
    for (const entry of readdirSync(siblingDir, { withFileTypes: true })) {
      if (entry.isDirectory() && entry.name.endsWith("_17.0.0")) {
        ports.push(join(siblingDir, entry.name));
      }
    }
  }
  return ports;
}

function findJsEntries(portDir) {
  const jsDir = join(portDir, "js");
  if (!existsSync(jsDir)) return {};

  const entries = {};
  for (const entry of readdirSync(jsDir, { withFileTypes: true })) {
    if (
      entry.isFile() &&
      entry.name.endsWith(".ts") &&
      !entry.name.endsWith(".d.ts")
    ) {
      entries[entry.name.slice(0, -".ts".length)] = join(jsDir, entry.name);
    }
  }
  return entries;
}

const portDirs = findPortDirs();
if (portDirs.length === 0) {
  console.error(
    "No ported plugin/theme directories found next to this repo (../piwigo16-plugins, ../piwigo16-themes) -- nothing to build.",
  );
  process.exit(0);
}

let builtAny = false;
for (const portDir of portDirs) {
  const entries = findJsEntries(portDir);
  if (Object.keys(entries).length === 0) continue;

  builtAny = true;
  const entryNames = Object.keys(entries);
  console.error(
    `Building ${entryNames.length} entr${entryNames.length === 1 ? "y" : "ies"} for ${portDir}...`,
  );

  // One entry per portDir gets its own real page (menuh.ts loads
  // globally, settings.ts only on the admin settings page -- never both
  // at once), so each is built in its own separate build() call rather
  // than one multi-entry call. Real bug caught by checking the actual
  // dist/ output: a single multi-entry build lets Rollup extract any
  // module 2+ entries import (e.g. dom.ts) into a shared chunk file,
  // leaving a real `import` statement in each entry -- silently breaking
  // the "self-contained, ready to serve as a plain <script>" contract
  // this tool exists for once a second entry shares any import with the
  // first. A separate build per entry duplicates that shared code
  // instead, which costs nothing here since the 2 files are never
  // fetched on the same page.
  const outDir = join(portDir, "dist");
  rmSync(outDir, { recursive: true, force: true });

  for (const [entryName, entryPath] of Object.entries(entries)) {
    await build({
      // Real bug, caught by checking the actual dist/ output rather than
      // the exit code: without this, Vite auto-discovers and uses this
      // repo's OWN vite.config.ts (CWD is this repo's root) instead of the
      // options object below -- the entire main app (72+ entries, vitals.js
      // included) got built into the port's own dist/ the first time this
      // ran. `configFile: false` disables that auto-discovery outright.
      configFile: false,
      // Vite's own default publicDir ("<root>/public") is this repo's real
      // web-root directory, not this tool's business -- same reasoning as
      // vite.config.ts's own `publicDir: false` (a symlinked _data/combined
      // under it makes the default copy step recurse into itself).
      publicDir: false,
      logLevel: "warn",
      build: {
        outDir,
        emptyOutDir: false,
        target: "esnext",
        lib: {
          entry: entryPath,
          formats: ["es"],
          fileName: () => `${entryName}.js`,
        },
      },
    });
  }
}

if (!builtAny) {
  console.error(
    "No ported extension has a js/ directory with any .ts entry -- nothing to build.",
  );
}
