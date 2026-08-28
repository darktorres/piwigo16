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
 * A glob, not the hashed filename: the hash changes on every build.
 *
 * Built from the chunk's own `name` rather than by stripping a hash off the
 * filename -- a hash may itself contain `-`, so a regex over the filename
 * turns `page-data-HASH.js` into `page-*.js`, which is both wrong and broad
 * enough to swallow unrelated chunks.
 */
function globFor(key) {
  const { name, file } = manifest[key];
  const dir = file.slice(0, file.lastIndexOf("/") + 1);

  // Not every emit is hashed: `vitals.js` is written unhashed to the dist
  // root, and `vitals-*.js` matches nothing at all -- which size-limit
  // reports as "can't find files" rather than as a zero, so it fails loudly
  // rather than silently budgeting nothing.
  return file.endsWith(`/${name}.js`) || file === `${name}.js`
    ? file
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
  .map(([key, chunk]) => ({
    name: chunk.name,
    chunks: [...closure(key)],
    paths: [...closure(key)].map((k) => `dist/${globFor(k)}`).sort(),
  }))
  .sort((a, b) => a.name.localeCompare(b.name));

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
      Math.ceil((entry.brotli + MIN_HEADROOM_BYTES) / 1024)
    )} KB`,
    // See the note at the top of this file: without this, size-limit spawns
    // a headless Chrome per entry to time it.
    running: false,
  }));
  writeFileSync(BUDGET, JSON.stringify(budget, null, 2) + "\n");
  const total = measured.reduce((t, e) => t + e.brotli, 0);
  console.log(
    `Wrote ${budget.length} entry budgets to ${BUDGET} ` +
      `(${(total / 1024).toFixed(1)} KB brotli summed across entries, shared chunks counted per page).`
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
      `"${entry.name}" loads a different set of chunks than its budget lists`
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

console.log(`Size budgets cover all ${entries.length} entries and match the build.`);
