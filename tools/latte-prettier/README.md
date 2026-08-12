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

**Full real-tree coverage: 135/135.** Every `.latte` file under `themes/` and
`template-extension/` as of this writing parses, formats, converges on a
second pass (idempotent), and is AST-semantically-equivalent to its source —
verified across the whole tree, not sampled (see
`tests/Unit/Latte/latte-prettier-plugin.test.ts`).

Coverage started at 4 real theme files (`header.latte`, `footer.latte`,
`comment_list.latte`, `register.latte`) and was extended to the full tree one
real, root-caused construct at a time — never a guessed fix. Along the way:

- **Structural**: HTML elements deliberately left unclosed (legacy markup
  relying on implicit closing, e.g. a `<td>` never closed before the next
  `{else}`) needed the enclosing `{if}`/`{foreach}`/`{spaceless}` branch's
  stop-keywords threaded into their own children-parsing. Separately, real
  markup also _overlaps_ HTML and Latte scopes (`<span>{spaceless}...{/if}
</span>{/spaceless}` — the `</span>` belongs to a `<span>` opened _outside_
  `{spaceless}`'s own body, since `{spaceless}` isn't HTML-aware at compile
  time). Both fixed by generalizing the same "absorb an unowned closing tag
  as literal passthrough" mechanism the top-level document already used for
  its own header/footer split-fragment case.
- **A real silent-corruption bug**, caught only by the AST-equivalence check
  (not by "doesn't throw"): an unquoted attribute value (`id={$key}`) was
  captured as literal text instead of a real Latte tag, turning a live
  variable substitution into 7 literal characters once reprinted.
- **Expression grammar**: `??` null-coalescing, array literals, PHP casts,
  backslash-qualified global constants (`\JSON_UNESCAPED_UNICODE`), and a
  ternary (`cond ? then : else`).
- **New tag keywords**: `{for INIT; COND; STEP}` (with a small
  assignment/increment statement grammar for INIT/STEP that a general
  expression position doesn't allow), `{define name}...{/define}` (a named
  fragment invoked later via `{include name, arg: val}`), and `{breakIf}`.
- **Bare output**: `{funcName(...)}` and `{(...)...}` with no leading `$`/`=`
  are real, valid Latte for an implicit output expression (e.g.
  `{count($x)}`), not unrecognized tags.
- **`{contentType text}`**: a template-header pragma (`mail/text/plain/
*.latte`'s plain-text email templates) declaring the output content type.
- **A real non-idempotency bug**, surfaced by rebasing onto a branch with 22
  more real templates: a document ending via a genuinely-unclosed element
  (`mail/text/html/header.latte` never closes any of its tags) whose source
  has zero trailing whitespace grew one extra trailing newline on every
  reformat, because the Document-level "ensure the file ends in a newline"
  and a nested unclosed element's own trailing-whitespace handling could
  both fire for the same gap. Fixed by distinguishing "unclosed because
  parsing hit real EOF" from "unclosed because it's yielding to an
  ancestor's closing tag or Latte branch keyword" (e.g. a `<td>` before
  `{else}`, where that trailing gap is real, meaningful spacing and must
  stay) — only the former defers to the Document level instead of also
  contributing its own trailing break.

None of this is wired into `lefthook` pre-commit or CI — that's a deliberate,
separate decision left for whoever wants it, not assumed here.
`tests/Unit/Latte/latte-prettier-plugin.test.ts` asserts, as a hard
requirement (not a floor): every real `.latte` file in the tree formats
without throwing, is idempotent, and is AST-equivalent to its source. P31
(Smarty → Latte migration) is still in progress, so new templates keep
landing — if one hits an unsupported construct, that test goes red with the
exact file and error, which is the intended signal to extend the grammar the
same way every construct above was added.

## Architecture

- **Lexer**: integrated into the parser (no separate token-array pass). A
  `{` only starts a real Latte tag when immediately followed by `$`, `=`,
  `*`, `(`, `/`+letter, or a letter — `{` followed by whitespace is literal
  text, which is what makes CSS bodies like `.el{ width: ... }` inside
  `{capture}` safe, matching Latte's own real tag-lexer behavior.
  `parseBodyLoop` gives every "body" context (an `{if}` branch, a
  `{foreach}`/`{spaceless}`/`{for}`/`{define}` body, the top-level document)
  the ability to absorb a closing tag it doesn't own as literal passthrough
  instead of erroring, for markup where HTML and Latte scopes overlap rather
  than nest.
- **Parser**: recursive-descent, typed AST (`Document`, `HtmlElement`,
  `Attribute`, `LatteIf`/`LatteForeach`/`LatteFor`/`LatteVar`/`LatteDo`/
  `LatteBreakIf`/`LatteCapture`/`LatteSpaceless`/`LatteDefine`/
  `LatteInclude`/`LatteComment`/`LatteOutput`, plus a small Latte-specific
  expression grammar: variables with `->`/`[]` access, literals, unary/
  binary ops incl. `??`, ternary, array literals, casts, positional+named-arg
  calls, filter chains — and a separate tiny statement grammar, used only by
  `{for}`'s init/step clauses and `{do}`, for assignment and
  post-increment/decrement, which Latte doesn't allow in a general
  expression position). Attribute _lists_ — not just attribute _values_ —
  can contain `{if}`/`{foreach}` directly (real Piwigo markup conditionally
  renders whole attributes: `<img {if $x}src="a"{else}src="b"
data-src="c"{/if}>`).
- **Printer**: real Prettier `Doc` builders (`group`/`indent`/`line`/
  `softline`/`hardline`) — reindents HTML structure, wraps long attribute
  lists, applies canonical expression spacing. Attribute values and
  `{capture}` bodies print byte-verbatim (no wrapping) since a stray line
  break there would corrupt a quoted HTML attribute or raw CSS/JS.
