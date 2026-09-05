#!/usr/bin/env node
// Generates and verifies .size-limit.json from the real Vite manifest.
//
// size-limit measures the files it is given. A page's actual payload is its
// entry chunk *plus every chunk that entry transitively imports*, which only
// the manifest knows -- pointing a budget at the entry file alone understates
// a page by everything it shares. So the budget list is generated from the
// manifest's own import graph rather than hand-written.
//
//   node tools/size-budget.mjs --verify   fail if the committed paths no
//                                         longer match the manifest
//   node tools/size-budget.mjs --update   regenerate paths and budgets from
//                                         the current build
//
// Run it through `bun run size:update`, which formats the result afterwards
// -- this writes valid JSON but not prettier's exact array wrapping, and the
// committed file has to satisfy `bun run format`.
//
// Budgets are brotli bytes at quality 11 -- the same thing size-limit itself
// reports (`@size-limit/file` uses BROTLI_PARAM_QUALITY: 11), so the numbers
// written here and the numbers enforced are the same measurement. They are
// set with headroom so ordinary churn does not fail the build while a
// library landing in a bundle does.
//
// Every generated check carries `running: false`. `@size-limit/preset-app`
// installs `@size-limit/time`, which measures execution time by driving a
// real headless Chrome through puppeteer -- three launches per entry
// (`get-running-time.js`'s own retry loop), plus a calibration run. That was
// tolerable against the single placeholder budget this file replaced; across
// 72 entries it is ~216 Chrome processes, which OOM-killed the machine twice
// before the cause was found. `running: false` is the documented opt-out
// (`@size-limit/time/index.js` checks it) and costs nothing here: this gate
// is about bytes, which is what the phase asked for.
import { existsSync, readFileSync, writeFileSync } from "node:fs";
import { brotliCompressSync, constants } from "node:zlib";

const MANIFEST = "dist/.vite/manifest.json";
const BUDGET = ".size-limit.json";
// Enough to absorb a rename or a few hundred bytes of ordinary churn, not
// enough to hide a library arriving.
const HEADROOM = 1.15;
const MIN_HEADROOM_BYTES = 2 * 1024;

if (!existsSync(MANIFEST)) {
  console.error(`${MANIFEST} is missing -- run \`bun run build\` first.`);
  process.exit(1);
}

const manifest = JSON.parse(readFileSync(MANIFEST, "utf8"));

/** Every chunk a page loads: the entry plus its transitive imports. */
function closure(key, seen = new Set()) {
  if (seen.has(key)) return seen;
  seen.add(key);
  for (const imported of manifest[key].imports ?? []) closure(imported, seen);
  return seen;
}

/**
 * A stable, unique budget key for an entry -- the manifest key (the real
 * source path) with the `themes/<chain>/js/` (or `build/`) prefix and the
 * `.ts` extension stripped, e.g. `themes/admin/default/js/categories/
 * list.ts` -> `categories/list`.
 *
 * `chunk.name` (Rollup's own per-entry name, the source file's basename)
 * looks like the obvious choice but is NOT unique across entries: several
 * pairs of entries share a basename across different subdirectories
 * (`categories/list.ts` and `users/list.ts` both name `"list"`;
 * `configuration/comments.ts` collides with the top-level `comments.ts`;
 * `languages/new.ts`/`plugins/new.ts`; `categories/search.ts`/
 * `configuration/search.ts`) -- confirmed live via the manifest itself,
 * not assumed. Keying budgets on `chunk.name` silently collapsed those
 * pairs onto one JSON object apiece, so `--verify` reported one of each
 * pair as a phantom "no longer matches any entry" / "new entry" mismatch
 * even right after a fresh `--update`. The full relative path has no such
 * collision (two different files can never share one path) and stays
 * short and readable for the common case of an entry with no colliding
 * sibling. Used unconditionally for the *budget's own* JSON key, unlike
 * `globFor()` below, since this key never has to match a real filename.
 */
function nameFor(key) {
  const jsDir = key.lastIndexOf("/js/");
  const rel = jsDir === -1 ? key : key.slice(jsDir + "/js/".length);
  return rel.endsWith(".ts") ? rel.slice(0, -".ts".length) : rel;
}

/**
 * Same collision this file's own basename-keyed logic hits, but for the
 * *real build output*: `vite.config.ts`'s `entryFileNames` only
 * disambiguates an entry chunk's own filename (folding its `nameFor()`
 * path onto one `-`-joined segment, mirroring `nameFor()` above) when its
 * plain basename collides with another real entry's -- every other entry
 * chunk, and every shared/imported chunk (`dom`, `ajax`, `common`, ...),
 * keeps Rollup's own plain `[name]-[hash].js`. `globFor()` below has to
 * know which case it is looking at to build a glob that actually matches
 * the real file.
 */
const collidingEntryBasenames = (() => {
  const seen = new Set();
  const dupes = new Set();
  for (const chunk of Object.values(manifest)) {
    if (!chunk.isEntry) continue;
    (seen.has(chunk.name) ? dupes : seen).add(chunk.name);
  }
  return dupes;
})();

/**
 * A glob, not the hashed filename: the hash changes on every build.
 *
 * For a shared/imported chunk, or an entry whose basename never collided
 * with another real entry's, `manifest[key].name` is exactly what
 * `vite.config.ts` named the real file with (Rollup's own default
 * `[name]-[hash].js`) -- used as-is, same as before this file's own
 * P52-A-era fix. For a *colliding* entry, `vite.config.ts` disambiguates
 * the real filename to `nameFor(key)` with `/` flattened to `-`
 * (`disambiguatedName()` there, kept in sync with `nameFor()` here by
 * construction: both derive the same "relative to the nearest `js/`
 * directory" path) -- a glob built from the plain, still-colliding
 * `manifest[key].name` here instead would match both
 * `categories-list-*.js` and `users-list-*.js` via `list-*.js`, exactly
 * the original bug. Not built by stripping a hash off the real filename
 * instead of using either name source: a hash may itself contain `-`, so
 * a regex over the filename turns `page-data-HASH.js` into `page-*.js`,
 * which is both wrong and broad enough to swallow unrelated chunks.
 */
function globFor(key) {
  const chunk = manifest[key];
  const name =
    chunk.isEntry && collidingEntryBasenames.has(chunk.name)
      ? nameFor(key).replaceAll("/", "-")
      : chunk.name;
  const dir = chunk.file.slice(0, chunk.file.lastIndexOf("/") + 1);

  // Not every emit is hashed: `vitals.js` is written unhashed to the dist
  // root, and `vitals-*.js` matches nothing at all -- which size-limit
  // reports as "can't find files" rather than as a zero, so it fails loudly
  // rather than silently budgeting nothing.
  return chunk.file.endsWith(`/${name}.js`) || chunk.file === `${name}.js`
    ? chunk.file
    : `${dir}${name}-*.js`;
}

/**
 * Deliberately does not measure. `--verify` only compares path lists, and it
 * is the mode that runs on every build; compressing every chunk of every
 * entry to answer a question about filenames is ~400 needless gzips.
 * `--update` asks for the bytes separately.
 */
const entries = Object.entries(manifest)
  .filter(([, chunk]) => chunk.isEntry)
  .map(([key]) => ({
    name: nameFor(key),
    chunks: [...closure(key)],
    paths: [...closure(key)].map((k) => `dist/${globFor(k)}`).sort(),
  }))
  .sort((a, b) => a.name.localeCompare(b.name));

// nameFor() is meant to be collision-proof (it's the real source path,
// and two different files can't share one), but the whole reason this
// check exists is that the previous scheme *looked* collision-proof too
// -- fail loudly instead of silently overwriting one budget with another
// if that assumption is ever wrong again.
{
  const seen = new Set();
  for (const entry of entries) {
    if (seen.has(entry.name)) {
      console.error(`Duplicate size-budget entry name: "${entry.name}"`);
      process.exit(1);
    }
    seen.add(entry.name);
  }
}

function brotliBytes(chunks) {
  return chunks.reduce((total, key) => {
    const path = `dist/${manifest[key].file}`;
    if (!existsSync(path)) return total;

    return (
      total +
      brotliCompressSync(readFileSync(path), {
        params: { [constants.BROTLI_PARAM_QUALITY]: 11 },
      }).length
    );
  }, 0);
}

const mode = process.argv[2];

if (mode === "--update") {
  const measured = entries.map((entry) => ({
    ...entry,
    brotli: brotliBytes(entry.chunks),
  }));
  const budget = measured.map((entry) => ({
    name: entry.name,
    path: entry.paths,
    limit: `${Math.max(
      Math.ceil((entry.brotli * HEADROOM) / 1024),
      Math.ceil((entry.brotli + MIN_HEADROOM_BYTES) / 1024),
    )} KB`,
    // See the note at the top of this file: without this, size-limit spawns
    // a headless Chrome per entry to time it.
    running: false,
  }));
  writeFileSync(BUDGET, JSON.stringify(budget, null, 2) + "\n");
  const total = measured.reduce((t, e) => t + e.brotli, 0);
  process.stdout.write(
    `Wrote ${budget.length} entry budgets to ${BUDGET} ` +
      `(${(total / 1024).toFixed(1)} KB brotli summed across entries, shared chunks counted per page).\n`,
  );
  process.exit(0);
}

if (mode !== "--verify") {
  console.error("usage: size-budget.mjs --verify | --update");
  process.exit(2);
}

if (!existsSync(BUDGET)) {
  console.error(`${BUDGET} is missing -- run \`bun run size:update\`.`);
  process.exit(1);
}

const committed = JSON.parse(readFileSync(BUDGET, "utf8"));
const byName = new Map(committed.map((b) => [b.name, b]));
const problems = [];

for (const entry of entries) {
  const budget = byName.get(entry.name);
  if (budget === undefined) {
    problems.push(`new entry "${entry.name}" has no budget`);
    continue;
  }
  const want = JSON.stringify(entry.paths);
  const have = JSON.stringify([...budget.path].sort());
  if (want !== have) {
    // The chunk graph moved: the committed budget is now measuring a
    // different set of files than the page actually loads, so the number it
    // enforces is meaningless until regenerated.
    problems.push(
      `"${entry.name}" loads a different set of chunks than its budget lists`,
    );
  }
  byName.delete(entry.name);
}

for (const name of byName.keys()) {
  problems.push(`budget "${name}" no longer matches any entry`);
}

if (problems.length > 0) {
  console.error("Size budgets are stale:\n  " + problems.join("\n  "));
  console.error("\nRun `bun run size:update` and review the diff.");
  process.exit(1);
}

process.stdout.write(
  `Size budgets cover all ${entries.length} entries and match the build.\n`,
);
