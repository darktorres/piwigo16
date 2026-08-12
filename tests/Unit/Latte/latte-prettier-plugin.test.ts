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
// .latte file in the tree, not just themes/.
const LATTE_ROOTS = ["themes", "template-extension"].map((d) =>
  join(REPO_ROOT, d),
);

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

function format(src: string): Promise<string> {
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
      const rec = n as Record<string, unknown> | null;
      return !(rec && rec.type === "HtmlText" && rec.value === "");
    });
  }
  if (node === null || typeof node !== "object") return node;
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
    if (k === "value" && rec.type === "HtmlText") {
      out.value = (rec.value as string).replace(/\s+/g, " ").trim();
      continue;
    }
    out[k] = normalizeAst(rec[k]);
  }
  return out;
}

const CORPUS_FILES = [
  "header.latte",
  "footer.latte",
  "comment_list.latte",
  "register.latte",
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
        failures.push(`${file}: ${(e as Error).message.split("\n")[0]}`);
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
});
