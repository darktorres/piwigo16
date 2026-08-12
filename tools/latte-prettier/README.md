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
- **A real structural bug**, found during a full manual review of every
  formatted file, not just the automated checks: `configuration_watermark
.latte`'s `<img id="wImg"></img>` — a void element given an explicit,
  invalid closing tag. Real browsers just discard an end tag for an element
  that isn't in the stack of open elements (a parse error with no DOM
  effect); this parser instead treated the mismatched `</img>` as "maybe
  belongs to an ancestor", which unwound _every_ real ancestor's own
  unclosed-propagation logic (span → li → ul → fieldset → div → form) all
  the way to the document root — flattening everything after that point out
  of its real nesting. Isolated to this one file (confirmed by grepping the
  whole tree for any void element with an explicit closing tag). Fixed by
  recognizing a closing tag that names a known void element as always-stray
  and discarding it on the spot, matching real HTML5 parser behavior,
  instead of letting it propagate.
- **The same class of bug, broader**, found the same way (manual review),
  in `install.latte`: `<td>{='Options'|translate}</options>` — a typo (every
  other cell in the same table is `<td>...</td>`; `<options>` isn't even a
  real HTML tag and is never opened anywhere in the tree). The
  void-element-only fix above didn't catch this, since `options` isn't
  void, so the same cascading-unclosed-ancestor flattening recurred
  (td → tr → table → fieldset → form). The real, general rule real
  browsers use isn't "void elements are always stray" — it's "any closing
  tag whose name isn't anywhere in the current stack of open elements is
  stray, no matter what it's named." Fixed by threading an explicit stack
  of open element names through the parser (`Scanner#openTags`, pushed/
  popped around an element's own children-parsing) and checking it before
  ever propagating a mismatched closing tag upward; this subsumes the
  void-element case entirely (void elements are never pushed, so they
  always fail the stack check too). What to do once a tag is recognized as
  stray still depends on what it names: a void element never has a
  legitimate open/close pair in *any* file, so it's dropped outright; any
  other name is preserved as literal passthrough, because a single-file
  parse can't tell "genuine typo" from "the other half of this element is
  in a sibling file" (`header.latte`/`footer.latte`'s own split — first
  draft of this fix got this backwards and started silently deleting
  `footer.latte`'s real `</div>`/`</body>`/`</html>`, caught by re-running
  the fix across the whole tree and diffing before/after rather than
  trusting the one file it was written against). Also fixed the *same*
  bug, previously undetected, in `batch_manager_unit.latte`,
  `photos_add_direct.latte`, `picture_modify.latte`, and
  `search_filters.inc.latte` — all four have a legitimate `<a>`/`<div>`
  opened independently in each branch of an `{if}`/`{else}` with a single
  shared closing tag after `{/if}`, which the same propagation bug quietly
  mis-indented the same way without fully flattening it, so earlier manual
  review passed over it as an acceptable case of the already-known "orphan
  tag indentation isn't perfectly matched" limitation instead of the
  distinct bug it actually was. One cosmetic loose end remains, deliberately
  not chased further: because this parser doesn't implement HTML5's
  "starting a new same-context element implicitly closes the previous one"
  rule (e.g. a second `<td>` auto-closing the first), a stray tag sitting
  between two such elements makes the second parse as a *nested child* of
  the first instead of a sibling — `install.latte`'s fixed output nests
  `<td colspan="2">` one level inside the unclosed `<td>` rather than next
  to it, and the immediately-enclosing `<tr>`'s own closing tag ends up one
  indent level deeper than its opening tag. Confirmed cosmetic only: real
  browsers apply that same auto-closing rule when *they* parse the output,
  so the rendered DOM is unaffected either way — same class of caveat as
  the existing orphan-tag-indentation note.
- **A real destructive bug**, also found by manual review, in three files
  with no HTML elements or Latte tags at all: `mail/text/html/global-mail-
css.latte`, `mail-css-clear.latte`, `mail-css-dark.latte` — raw CSS meant
  to be dropped verbatim into a sibling file's `<style>` block via
  `{$GLOBAL_MAIL_CSS|noescape}`. With nothing to split the file into
  separate items, the whole 100+-line file parsed as one continuous
  `HtmlText` node and went through the normal prose-reflow path, which
  collapses all internal whitespace (including every deliberate line
  break between CSS rules) down to single spaces — flattening
  hand-formatted, readable CSS onto one unreadable line. Confirmed
  harmless to the *rendered* page (CSS is whitespace-insensitive between
  tokens) but a real loss of source readability and diffability, so fixed
  rather than accepted: a `Document` whose children are 100% plain text
  (no element, no Latte construct — checked directly, not inferred) is
  now printed byte-verbatim instead of through the reflow path. Confirmed
  isolated to these three files by checking every `.latte` file with no
  `<` character in it at all in the whole tree; the same shape recurring
  nested inside a real `{if}`/`{foreach}` body elsewhere isn't handled
  (no such file exists today) — if one lands, the fix generalizes the
  same way, at that Document-relative body's own print site.

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
