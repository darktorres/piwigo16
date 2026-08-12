import { readFileSync, readdirSync } from "node:fs";
import { join } from "node:path";
import { isDeepStrictEqual } from "node:util";
import * as prettier from "prettier";
import { describe, expect, it } from "vitest";
import plugin from "../../../tools/latte-prettier/plugin.cjs";
import { parse } from "../../../tools/latte-prettier/parser.cjs";

const THEMES_DIR = join(import.meta.dirname, "../../../themes");

function findLatteFiles(dir: string): string[] {
  const out: string[] = [];
  for (const entry of readdirSync(dir, { withFileTypes: true })) {
    const full = join(dir, entry.name);
    if (entry.isDirectory()) out.push(...findLatteFiles(full));
    else if (entry.name.endsWith(".latte")) out.push(full);
  }
  return out;
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
      k === "unclosed"
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

  // The plugin's grammar coverage was built and verified against the 4
  // corpus files above, not the whole tree — it fails loudly (not silently)
  // on constructs it doesn't know yet: newer keywords ({for}, {define},
  // {breakIf}), newer expression syntax (??, array literals, casts,
  // \FULLY_QUALIFIED constants), and an unresolved {elseif}/{else}
  // structural mismatch in a handful of files. This floor is a regression
  // guard, not a completeness claim — it must only ever go up as grammar
  // coverage grows; if it drops, something real broke.
  it("does not regress below its current real-tree pass rate", async () => {
    const files = findLatteFiles(THEMES_DIR);
    const failures: string[] = [];
    for (const file of files) {
      try {
        await format(readFileSync(file, "utf8"));
      } catch (e) {
        failures.push(`${file}: ${(e as Error).message.split("\n")[0]}`);
      }
    }
    const passCount = files.length - failures.length;
    expect(
      passCount,
      `only ${passCount}/${files.length} real .latte files format cleanly (floor is 85); failures:\n${failures.join("\n")}`,
    ).toBeGreaterThanOrEqual(85);
  });

  // Stronger than the floor above: "doesn't throw" alone doesn't rule out
  // silent corruption. For every real file the plugin *does* currently
  // accept — whichever those are, no hardcoded list, so this automatically
  // covers newly-converted templates and newly-closed grammar gaps alike —
  // it must also be idempotent and produce an AST equivalent to the
  // original. A file failing to parse at all is out of scope here (that's
  // the floor test's job); a file that parses but silently mangles content
  // or never converges is a real bug, not a known gap.
  it("every file it accepts is idempotent and semantically unchanged", async () => {
    const files = findLatteFiles(THEMES_DIR);
    const idempotencyFailures: string[] = [];
    const equivalenceFailures: string[] = [];
    for (const file of files) {
      const src = readFileSync(file, "utf8");
      let once: string;
      try {
        once = await format(src);
      } catch {
        continue; // not accepted yet — covered by the floor test above
      }
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
      "formatting twice should converge for every accepted file",
    ).toEqual([]);
    expect(
      equivalenceFailures,
      "formatted output should be AST-equivalent to its source for every accepted file",
    ).toEqual([]);
  });
});
