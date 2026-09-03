import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";
import { isDeepStrictEqual } from "node:util";
import * as prettier from "prettier";
import { describe, expect, it } from "vitest";
import plugin from "../../../tools/latte-prettier/plugin.cjs";
import { parse } from "../../../tools/latte-prettier/parser.cjs";

const REPO_ROOT = join(import.meta.dirname, "../../..");
const THEMES_DIR = join(REPO_ROOT, "themes");
// Matches format:latte/format:latte:fix's glob (package.json): every real
// .latte file in the tree. `template-extension/` was in both until P40
// Batch 1 deleted the template-extension feature (76fd0691c1) -- a glob
// tolerates a missing directory, readdirSync does not, so this list kept
// naming it long after it stopped existing.
const LATTE_ROOTS = ["themes"].map((d) => join(REPO_ROOT, d));

function findLatteFilesIn(dir: string): string[] {
  const out: string[] = [];
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, entry.name);
    if (entry.isDirectory()) out.push(...findLatteFilesIn(full));
    else if (entry.name.endsWith(".latte")) out.push(full);
  }
  return out;
}

function findLatteFiles(): string[] {
  return LATTE_ROOTS.flatMap(findLatteFilesIn);
}

async function format(src: string): Promise<string> {
  return prettier.format(src, { parser: "latte-ast", plugins: [plugin] });
}

// Normalizes an AST for semantic comparison: drops position info (real
// reformatting moves offsets) and collapses/trims HtmlText whitespace (real
// reformatting legitimately changes exact spacing) while preserving every
// other field — node types, conditions, loop variables, filter chains, named
// args. See tools/latte-prettier/README.md for why this check exists.
function normalizeAst(node: unknown): unknown {
  if (Array.isArray(node)) {
    return node.map(normalizeAst).filter((n) => {
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- every real AST node from this same parser is a plain object or null, this function's own recursive contract.
      const rec = n as Record<string, unknown> | null;
      return !(rec?.["type"] === "HtmlText" && rec["value"] === "");
    });
  }
  if (node === null || typeof node !== "object") return node;
  // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- `object` has no index signature; every real AST node from this same parser is plain-object-shaped.
  const rec = node as Record<string, unknown>;
  const out: Record<string, unknown> = {};
  for (const k of Object.keys(rec)) {
    if (
      k === "start" ||
      k === "end" ||
      k === "quote" ||
      k === "selfClosing" ||
      k === "unclosed" ||
      k === "unclosedAtEof"
    )
      continue;
    if (k === "value" && rec["type"] === "HtmlText") {
      // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- every real HtmlText AST node's own "value" field from this same parser is always a string.
      out["value"] = (rec["value"] as string).replace(/\s+/g, " ").trim();
      continue;
    }
    out[k] = normalizeAst(rec[k]);
  }
  return out;
}

const CORPUS_FILES = [
  // Replaced header.latte/footer.latte, which P41-G/H's asset-pipeline
  // swap deleted (00fd301ac5) and which this list kept naming, so
  // readFileSync threw at collection time and took the whole file down
  // before a single test ran. index.latte and layout.latte are the
  // closest real equivalents and cover strictly more: the
  // `{templateType}` / `{layout} `/ `{block}` trio the old corpus never
  // reached, plus layout.latte's 136-line generated `{varType}` block.
  "index.latte",
  "layout.latte",
  "comment_list.latte",
  "register.latte",
  // {varType} content containing a PHPStan array-shape type (`array{key:
  // type, ...}`) exercises nested-brace tag-body parsing; the whole file's
  // trailing raw CSS (after the {varType} block) exercises Document-level
  // byte-verbatim preservation with a leading non-text run -- both real
  // bugs found live when P33B added a {varType} block to every template.
  "mail/text/html/global-mail-css.latte",
];

describe("Latte Prettier plugin (tools/latte-prettier/)", () => {
  describe.each(CORPUS_FILES)("%s (verified corpus)", (name) => {
    const path = join(THEMES_DIR, "default/template", name);
    const original = readFileSync(path, "utf8");

    it("formats without throwing", async () => {
      await expect(format(original)).resolves.toBeTypeOf("string");
    });

    it("is idempotent (formatting twice converges)", async () => {
      const once = await format(original);
      const twice = await format(once);
      expect(twice).toBe(once);
    });

    it("preserves meaning (AST-equivalent to the original, modulo whitespace)", async () => {
      const formatted = await format(original);
      const before = normalizeAst(parse(original));
      const after = normalizeAst(parse(formatted));
      expect(after).toEqual(before);
    });
  });

  // Grammar coverage started at the 4 corpus files above and was extended,
  // one real root-caused construct at a time, until it reached full
  // coverage of the tree (135/135 across themes/ + template-extension/ as
  // of this writing). That's a hard requirement now, not a floor: P31
  // (Smarty -> Latte migration) is still in progress, so new templates keep
  // landing. If one hits an unsupported construct, that's real signal —
  // extend the grammar (see tools/latte-prettier/README.md's Architecture
  // section for how the existing constructs were each added from real
  // source, not guessed) or, if it's a deliberate carve-out, adjust this
  // test with a clear reason at that time. Silent regression is what this
  // guards against.
  it("formats every real .latte file in the tree without throwing", async () => {
    const files = findLatteFiles();
    const failures: string[] = [];
    for (const file of files) {
      try {
        await format(readFileSync(file, "utf8"));
      } catch (e) {
        // eslint-disable-next-line @typescript-eslint/no-unsafe-type-assertion -- prettier.format() and this file's own plugin/parser only ever throw real Error instances, standard JS practice.
        failures.push(`${file}: ${(e as Error).message.split("\n")[0]!}`);
      }
    }
    expect(
      failures,
      `${failures.length}/${files.length} real .latte files failed to format`,
    ).toEqual([]);
  });

  // Stronger than "doesn't throw" alone: rules out silent corruption. Every
  // real file in the tree must also be idempotent and produce an AST
  // equivalent to its original — a file that parses but silently mangles
  // content or never converges is a real bug, not a known gap.
  it("every real .latte file is idempotent and semantically unchanged", async () => {
    const files = findLatteFiles();
    const idempotencyFailures: string[] = [];
    const equivalenceFailures: string[] = [];
    for (const file of files) {
      const src = readFileSync(file, "utf8");
      const once = await format(src);
      const twice = await format(once);
      if (twice !== once) idempotencyFailures.push(file);
      if (
        !isDeepStrictEqual(normalizeAst(parse(once)), normalizeAst(parse(src)))
      ) {
        equivalenceFailures.push(file);
      }
    }
    expect(
      idempotencyFailures,
      "formatting twice should converge for every real file",
    ).toEqual([]);
    expect(
      equivalenceFailures,
      "formatted output should be AST-equivalent to its source for every real file",
    ).toEqual([]);
  });

  // The whole-tree tests above would pass even if the printer dropped the
  // `?`, since parsing `->` back gives an AST that normalizes the same way.
  // This pins the operator itself, on both a property read and a call.
  it("round-trips the nullsafe operator rather than flattening it to ->", async () => {
    const src =
      "{if $ferrors?->opacity !== null}{$ferrors?->opacity}{$a?->b()}{/if}\n";
    const formatted = await format(src);

    expect(formatted).toContain("$ferrors?->opacity !== null");
    expect(formatted).toContain("{$ferrors?->opacity}");
    expect(formatted).toContain("$a?->b()");
    expect(formatted).not.toContain("$ferrors->opacity");
  });
});
