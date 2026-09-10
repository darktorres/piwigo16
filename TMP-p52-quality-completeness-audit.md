# P52 (CSS) code-quality & modernization-completeness audit — temp working notes

Analysis-only workflow run (no files modified). 7 parallel survey
dimensions over ID-selector specificity hacks, print-style completeness,
a broader dark-mode parity sweep, unused custom properties, container-
query/modern-layout adoption, a regression re-check of item-6's sweep,
and `stylelint-suppressions.json` staleness. 31 agents, merged into 9
punch-list items. Explicitly instructed not to re-report the 2 prior
consolidation audit's own findings (the "Date created" dark-mode gap, the
Search-tips contrast bug, the z-index token family) — confirmed clean of
overlap.

Overall auditor summary: *"The P52 campaign's core architecture (12-layer
@layer cascade, 3-chain token system, zero !important, logical
properties) is sound and largely complete, but several pockets haven't
been brought up to that same standard. Roughly a third of the findings
are pre-@layer specificity hacks (ID selectors, redundant class
compounding) that are now provably unnecessary since layer order already
resolves them — including one case where the very lint rule
(no-descending-specificity) that would catch this is silently disabled
with no rationale. There is one genuine visible bug (a copy-paste color
leak making silver's dark-mode hover state flash cadmium's yellow) and
one real layout-fragility issue (an absolutely-positioned toolbar
hand-tuned across two breakpoints instead of using the flex container it
already sits in). The remainder is minor hygiene... None of this rises
to architectural risk — it's mechanical cleanup layered on an otherwise
coherent, modern stylesheet."*

## Prioritized punch list (9 items)

### 1. [bug, low risk] Silver skin's dark-mode link-hover renders cadmium's yellow instead of grey (copy-paste token leak)

**Files:** `standard_pages/skins/silver.css`, `cadmium.css`

`silver.css:24` sets `--color-dark-link-hover: #FFFBE6;` inside `.dark {}`
— every other token in the file is grayscale (`--color-accent:#b0b0b0`,
`--color-dark-link:#d1d1d1`). `#FFFBE6` is byte-identical to
`cadmium.css:24`'s own value — cadmium's native light-gradient end-stop, a
genuinely yellow palette. No comment documents this as intentional; a
real, user-visible bug distinct from the two dark-mode gaps the prior
audit already found.

**Suggested action:** Replace `--color-dark-link-hover: #FFFBE6;` in
`silver.css:24` with a grey consistent with the file's own dark-mode
tokens (e.g. a variant of `--color-dark-link`'s `#d1d1d1`).

### 2. [specificity-hack, low risk] ID-selector specificity escalation is dead weight now that @layer already decides the winner

**Files:** `profile.css`, `theme.css`, `password.css` (standard_pages), `theme-base.css` (admin)

Four confirmed sites use an ID (alone or compounded with a class) purely
to out-rank a same-property class-based rule, when the master `@layer`
order already resolves the winner unconditionally regardless of
specificity: (1) `profile.css:5` `#api_collapse.template-api{display:none}`
(in `@layer pages`) vs `theme.css:629` `.profile-section
.api-tab-collapse{display:grid}` (in `@layer theme-chain`, strictly
before `pages`) — the ID contributes nothing; (2) `theme.css:118`/`:413`
escalate against a base `.error-message` rule when both pages already
have a dedicated `@layer pages` file that would let a plain class win via
layer order alone; (3) `theme-base.css:9075-9080`/`:9105-9113`, two
`#configContent` rules whose own comments say they exist to "win outright
instead of relying on `!important`" — the file's own adjacent
class-only pattern already works. All four predate or were carried
through the `@layer` migration **without re-deriving the rationale**.

**Suggested action:** Drop the ID from each selector (or relocate to the
page's `@layer pages` file as a class-only rule). Update or remove each
site's stale "higher specificity"/"`!important`" comment to instead note
layer order as the real mechanism.

### 3. [specificity-hack, medium risk] Redundant class+class utility compounding (.x.u-hidden etc.) across 7 admin page files, with 3 comments that wrongly claim "higher ID specificity"

**Files:** 9 admin page/component CSS files

16 confirmed `.<class>.u-hidden{display:none}` blocks (19 selectors)
across 7 files compound a page/component class onto `.u-hidden` purely
for specificity, but `.u-hidden` already lives in the terminal `@layer
utilities` and wins unconditionally by layer order. Separately, **3
sites carry a "(higher ID specificity)" comment that is factually wrong
on two counts** — no ID is present at all (both selectors are
class+class), and the real reason it wins is cross-layer order, not
specificity.

**Suggested action:** Sweep the 16 `.u-hidden` compound blocks and drop
the redundant leading class; correct or remove the 3 wrong comments to
state "wins via layer order" instead. While in this area, also review
the related `.u-block`/`.u-inline` compounding in
`configuration_sizes.css` (same redundancy, different utility class).

### 4. [other, low risk] no-descending-specificity stylelint rule disabled repo-wide with no documented rationale or re-enable trigger

**Files:** `.stylelintrc.json`

`.stylelintrc.json:20` sets `"no-descending-specificity": null`
immediately above the 3 disabled naming-pattern rules — but unlike those
siblings (which have a documented reason and an explicit re-enable
trigger in the naming-audit report), this rule has **zero mention
anywhere** in `docs/PLAN.md` or either prior audit file, and has been
`null` since the file's first commit through 11+ later edits. It's
exactly the rule that would have flagged items 2 and 3 above.

**Suggested action:** After items 2 and 3 land, flip
`no-descending-specificity` back on and triage remaining hits — add
scoped `stylelint-suppressions.json` entries with real rationale for any
genuine exceptions rather than leaving the rule globally off.

### 5. [other, medium risk] user_list.css filter-bar toolbar uses 3 independently absolutely-positioned siblings with magic-number offsets instead of flex+gap

**Files:** `user_list.css`, `user_list.latte`

`.filtered-users`, `.advanced-filter-btn`, and `#search-user
.user-search-panel` are siblings inside `.user-manager-header`, which is
already `display:flex`, yet each child escapes that flow via its own
`position:absolute` + magic-number offset. Because none of the three
positions is computed relative to the others, **two separate breakpoints
then hand-retune all three offsets plus a width in lockstep** — a
fragile pattern with real overlap risk at intermediate widths, not just
a theoretical modernization gap.

**Suggested action:** Remove the absolute positioning from all three
children and let the parent's existing flex row lay them out using `gap`
plus `margin-inline-start:auto` on the first item; delete the two
magic-number breakpoint blocks. Verify visually at both former breakpoint
widths since this touches real layout.

### 6. [other, medium risk] 160 of 190 suppressed stylelint violations (animation-performance + reduced-motion) have no local, in-file justification

**Files:** 14 files carrying `no-low-performance-animation-properties`/`media-prefers-reduced-motion` suppressions

`stylelint-suppressions.json` shows 65 + 95 = 160 suppressed violations
across 14 files, but grepping all 14 for any reduced-motion/
animation-performance comment finds **nothing** — the rationale exists
only in one commit message and in `reset.css`'s own comment, which no
suppressed file points back to. This contrasts with the other 3
suppressed rules in the same file, which do carry real inline
justification at their flagged sites.

**Suggested action:** Add a short comment at each suppressed site (or one
file-level comment per file) referencing `reset.css`'s global
reduced-motion rationale and the commit that established it, so the
justification is discoverable without a git-log dig.

### 7. [unused-token, low risk] Three dead design tokens never consumed anywhere: --color-success, --color-surface-subtle, --space-8

**Files:** all 3 `tokens.css` files

`--color-success` has zero `var()` consumers and its source literal
doesn't exist anywhere else either. `--color-surface-subtle` is declared
with real per-chain values in 2 chains but has zero consumers, while the
`#fafafa` literal it was meant to replace persists hardcoded in 5+ files.
`--space-8` (4rem) is declared identically in all 3 chains with zero
consumers, unlike `--space-1`..`--space-7` which each have real usage.

**Suggested action:** Delete `--color-success` outright. For
`--color-surface-subtle`, either wire it into the real `#fafafa` literal
sites across 5 files, or delete it if not planned soon. Delete
`--space-8`'s registration and value from all three `tokens.css` files.

### 8. [other, low risk] Advanced-filter flex row simulates gap via :not(:last-child) margins, duplicated verbatim in admin/default and admin/roma

**Files:** `admin/default/css/components/general.css`, `admin/roma/css/components/general.css`

A `:not(:last-child)` margin pattern plus a last-child override are
duplicated verbatim in both files, even though the container is already
`display:flex` and the codebase relies on `gap` pervasively elsewhere. No
comment documents avoiding `gap` here.

**Suggested action:** Add `gap` to the flex container rule in both files
and drop both margin-based rules from both files (leave admin/roma's
separately-relocated `display:flex` in `theme-base.css` untouched — that
move serves an unrelated cascade-layer-inversion reason).

### 9. [other, low risk] Lint tooling has no case-enforcement for hex-color literals or property names, letting 459 uppercase hex values and a stray typo through

**Files:** 5 theme CSS files, `.stylelintrc.json`

459 live uppercase hex-color declarations exist across 23 files with no
lint coverage (stylelint dropped `color-hex-case` from core, and
`.stylelintrc.json` disables `value-keyword-case`). Separately,
`theme-base.css:6801` has a stray `Position:relative;` (capital P) — the
**sole non-lowercase property name in the whole tree** — which no
current rule catches either.

**Suggested action:** Fix the typo now (`theme-base.css:6801`). Add a
custom stylelint rule or configure `stylelint-declaration-strict-value`
for color case to catch new uppercase hex and property-case typos going
forward. Treat the 459 existing instances as a separate future
lowercasing pass, matching the deferral already given to the broader
raw-hex-outside-tokens campaign in `docs/PLAN.md`.

---

*Generated from workflow run `wf_adbcb0c0-319` (task w797tmocm). Untracked
scratch file — not committed, safe to delete once reviewed/acted on.*
