# Latte Prettier plugin

A real Prettier plugin for `.latte` templates: a hand-written recursive-descent
parser (`parser.cjs`) producing a typed AST, printed through Prettier's own
plugin interface (`printer.cjs`, `plugin.cjs`) — the same architecture
`prettier-plugin-laravel-blade` uses for Blade, not a mask-and-delegate hack.
This is P32's ("Latte lint/format", see `docs/PLAN.md`) format-half; the
lint-half (`composer lint:latte`, `precompile:templates`, `phpstan-latte`) is
separate, untouched PHP-side tooling with real prior art in `16.x-rewrite`.

## Usage

```
bun run format:latte        # check
bun run format:latte:fix    # write
```

## Status

Built and verified against 4 real theme files (`header.latte`, `footer.latte`,
`comment_list.latte`, `register.latte`): every construct they contain parses,
formats, round-trips idempotently, and is AST-semantically-equivalent to the
original (see `tests/Unit/Latte/latte-prettier-plugin.test.ts`).

Run against the full real tree (109 `.latte` files under `themes/` as of this
writing), it currently formats **85/109** cleanly. It fails loudly (a real
parse/print error naming the exact construct) rather than silently mangling
anything it doesn't understand yet. The remaining ~24 files hit one of:

- **Unrecognized keywords**: `{for}`, `{define}`, `{breakIf}` — real Latte
  constructs never encountered in the original 4-file corpus, not yet in the
  parser's dispatch table.
- **Unrecognized expression syntax**: PHP's `??` null-coalescing operator,
  array literals (`[]`, `["a", "b"]`), casts (`(string) $x`), and
  backslash-qualified constants (`\JSON_UNESCAPED_UNICODE`) — the expression
  grammar only covers what the original corpus actually used.
- **An unresolved `{elseif}`/`{else}`/`{/spaceless}` structural mismatch** in
  a handful of files — needs real per-file investigation (something is
  desyncing the parser's position before it reaches the branch tag), not a
  guessed fix.

None of this is silent corruption risk — this is why `format:latte`/
`format:latte:fix` aren't wired into `lefthook` pre-commit or CI yet.
`tests/Unit/Latte/latte-prettier-plugin.test.ts` asserts a 85-file floor
across the real tree as a regression guard (must only go up, never down) plus
strict correctness (no-throw, idempotency, AST-equivalence) against the 4
verified corpus files.

## Architecture

- **Lexer**: integrated into the parser (no separate token-array pass). A
  `{` only starts a real Latte tag when immediately followed by `$`, `=`,
  `*`, `/`+letter, or a letter — `{` followed by whitespace is literal text,
  which is what makes CSS bodies like `.el{ width: ... }` inside `{capture}`
  safe, matching Latte's own real tag-lexer behavior.
- **Parser**: recursive-descent, typed AST (`Document`, `HtmlElement`,
  `Attribute`, `LatteIf`/`LatteForeach`/`LatteVar`/`LatteDo`/`LatteCapture`/
  `LatteSpaceless`/`LatteInclude`/`LatteComment`/`LatteOutput`, plus a small
  Latte-specific expression grammar: variables with `->`/`[]` access,
  literals, unary/binary ops, positional+named-arg calls, filter chains).
  Attribute _lists_ — not just attribute _values_ — can contain `{if}`/
  `{foreach}` directly (real Piwigo markup conditionally renders whole
  attributes: `<img {if $x}src="a"{else}src="b" data-src="c"{/if}>`).
- **Printer**: real Prettier `Doc` builders (`group`/`indent`/`line`/
  `softline`/`hardline`) — reindents HTML structure, wraps long attribute
  lists, applies canonical expression spacing. Attribute values and
  `{capture}` bodies print byte-verbatim (no wrapping) since a stray line
  break there would corrupt a quoted HTML attribute or raw CSS/JS.
