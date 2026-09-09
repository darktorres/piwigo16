#!/usr/bin/env python3
"""
P52 finding: `@layer` does not safely guarantee an override for the admin
theme's 20-year-old CSS. `docs/PLAN.md`'s P52-B design text describes
skin-wins-over-parent as "an outcome of layer position" -- true only for
rules with genuinely identical selector text. Everywhere else, the real
authors wrote plain specificity + load-order overrides, decades before
`@layer` existed, so a generic selector that happens to land in a
later-declared layer than a more specific competitor can silently start
winning unconditionally the moment both get wrapped -- with nothing in
the CSS text itself signalling the conflict. This bug shape was found
repeatedly by hand during the `@layer` migration (jqtree, selectize,
jquery-ui datepicker icons, `updates_pwg.css` bare `li`, and others --
see `docs/PLAN.md`'s P52 sub-phase notes and the "deep-exploring-
starlight" plan for the worked examples), each only caught by running
the full visual-regression suite and live-diagnosing the failure.

That bug class isn't a one-shot migration concern: any future
contributor adding a rule to any of these files can reintroduce it with
no guardrail beyond another full VR run + manual root-causing. This
tool is the permanent guardrail -- a real cascade-resolution engine
(the same one built and validated during the `@layer` migration itself,
promoted here rather than left as a throwaway scratch script) that
statically re-derives, for every declared property, which real
declaration wins under this codebase's actual `@layer` order and CSS
`!important` semantics (verified against MDN: layer priority is
*normal*-priority for plain declarations -- later-declared layer wins
-- but *reverses* for `!important` declarations, where the
earlier-declared layer wins, and unlayered `!important` has the
*lowest* priority of all).

Two independent checks:

  --inversions (the ongoing regression guard; wired into
    `composer check:css-layers`): flags a specific selector whose
    declaration for some property is beaten by a *more generic*
    selector for the same property with a *different* value, purely
    because of layer position -- i.e. plain CSS specificity would have
    picked the specific one, but the real winner (computed under this
    codebase's real layer order) is the generic one instead. "More
    generic" is deliberately narrow here: selector B is only considered
    generic-relative-to-A when B's compound-selector chain is an exact
    trailing subsequence of A's (A adds ancestor scoping on top of B) --
    every real bug found during the migration had this shape (e.g.
    `ul.jqtree_common` vs `ul.jqtree-tree ul.jqtree_common`,
    `.selectize-input` vs `.batch-filter-tags .selectize-input`,
    `[class^="icon-"]:before` vs
    `.admin-menubar dd [class^="icon-"]::before`). This intentionally
    will not catch every conceivable specificity inversion (arbitrary
    subsequence-with-gaps relationships, sibling-combinator
    generalizations) -- narrower and slower to false-positive beats
    broader and unusable, matching this codebase's own "positional
    subset-containment... restricted to differing property values" bar
    that got the false-positive rate down to something worth trusting.
    A flagged finding is a *candidate*, not a confirmed bug: two
    selectors can share this exact shape and still never really
    conflict if they never co-render on the same page (a real false
    positive hit during the migration: `cat_modify.css`'s `.warnings`
    vs `theme.css`'s `.std_pgs_theme_info.warnings` -- different pages
    entirely, despite matching selector shape). Confirm co-rendering
    before treating a finding as a real bug, exactly as the migration
    itself did.

  --important: classifies every `!important` declaration in scope as
    LOAD_BEARING (removing `!important` changes the winner -- keep it,
    and document why), REDUNDANT (removing it changes nothing -- the
    real layer order already picks the same winner; dead weight, safe
    to delete), or ALREADY_DEAD (it doesn't even win today -- something
    else already beats it). Only a diagnostic aid now that the admin
    theme carries zero `!important` (stylelint's `declaration-no-important`
    rule already hard-bans regressions at the syntax level, repo-wide
    except the documented `ignoreFiles`) -- kept for the next
    `!important`-bearing theme this campaign reaches (P52-E/F: `default`/
    `standard_pages`, ~278 real `!important` declarations, neither
    wrapped in `@layer` at all yet).

    KNOWN BLIND SPOT, found the hard way during P52-E/F Step 7 (not
    fixed here -- see below): this classification only compares a
    declaration against OTHER declarations sharing its *exact* selector
    text (grouped by `(selector, prop)`). It cannot see a same-
    specificity, *different*-selector-text competitor in a *later*
    layer -- exactly `check_inversions()`'s whole job, but that check
    deliberately excludes `!important` declarations from both sides
    (its priority model is the reversed one, not the normal one).
    Concretely: `standard_pages/theme.css`'s `.profile-section
    .username { border: none !important; }` and `skins/*.css`'s
    `.light .input-container { border: 1px solid ...; }` share the DOM
    element (the div carries both classes) and the same 2-class
    specificity, with `.username` in the earlier `theme-chain` layer --
    this tool reported REDUNDANT (nothing else declares `border` for
    the literal selector `.profile-section .username`), but removing
    it in practice let the skin's border win, breaking the page's
    layout. Found only by the real `composer test:visual` run this
    tool's own docstring says is the final word -- not a hole to
    special-case away here, a reason the VR suite stays mandatory
    after every `--important`-driven removal, not just a
    nice-to-have.

Known, deliberate scope limits (same as the migration's own VR suite):
`@media`/`@supports`/pseudo-class-gated content (`:hover`, `:focus`,
print styles, `prefers-reduced-motion`) is parsed structurally but its
*declarations* are skipped -- a static, non-interactive, single-viewport
scan structurally cannot know whether a generic rule inside a mismatched
condition ever actually wins for real content. This tool proves nothing
about conditional rules either way; a "clean" report from it is not a
substitute for the real, Chromium-rendered visual-regression suite.

Running `--inversions` against the real, VR-verified admin theme still
finds candidates -- currently 118, all triaged and confirmed harmless
(same reasoning as the `cat_modify.css`/`theme.css` false positive
above: e.g. `install.css`'s bare `select`/`input[type="text"]`/`p`
rules are a deliberate blunt per-page reset for the standalone install
wizard, and every "specific" competitor flagged against them belongs to
a component class -- `.mergeoptionscontainer`, `.picture-content-panel`,
etc. -- that never appears in `install.latte`'s own tiny markup, so the
two rules can never really collide despite both loading on that page).
A hard CI gate on the raw count would be permanent, useless noise from
day one. Following this exact codebase's own precedent
(`stylelint-suppressions.json`), known/triaged findings are tracked in
`tools/css-layers/known-findings.json`; the exit code (and therefore
`composer check:css-layers`) only goes non-zero for findings NOT in
that baseline -- i.e. a genuinely *new* inversion introduced by a
future change. Regenerate the baseline after triaging new findings
(confirm each is a real false positive first, the same way every
finding above was) with `--update-baseline`.

Usage:
  python3 tools/css-layers/scan.py --important
  python3 tools/css-layers/scan.py --inversions
  python3 tools/css-layers/scan.py --inversions --skin roma
  python3 tools/css-layers/scan.py --json /tmp/report.json
  python3 tools/css-layers/scan.py --inversions --update-baseline
  composer check:css-layers

P52-E/F generalization: `--chain {admin,default,standard_pages}` (default
`admin`, so `composer check:css-layers`'s own bare invocation keeps
scanning exactly what it always has) selects which theme's real
`AssetContribution::css()` file set and skin list to build the pool
from -- `default`'s own "skin" dimension is its 2 search-page
colorscheme variants (`dark-search`/`clear-search`, the only per-page
alternate-CSS choice that theme has), and `standard_pages`' is its real
11-skin system. Each chain's universal set includes the real, confirmed
cross-chain files pages in that chain actually load (found by tracing
`AssetContribution::css()` call sites, not assumed from directory
layout) -- e.g. `default`'s search page cross-loads 4 admin-namespaced
files via `SearchFiltersView`'s own composition of `AlbumSelectorView`/
`ColorboxView`. Findings are namespaced by chain (`inversion_fingerprint()`
includes it) so `known-findings.json` stays one shared baseline file
across all 3 chains without cross-chain fingerprint collisions.
"""

from __future__ import annotations

import argparse
import json
import re
import sys
from collections import defaultdict
from pathlib import Path
from typing import cast, TypedDict

ROOT = Path(__file__).resolve().parents[2]

# The admin theme's real master `@layer` order, declared once in
# `themes/admin/default/theme-base.css` (Step 9 moved it there from the
# old monolithic `theme.css`). Kept here as a literal, not parsed out of
# that file, so a corrupted or missing declaration in the CSS doesn't
# silently disable this tool's own priority model -- if the two ever
# drift, `--inversions`/`--important` findings will contradict what a
# browser actually renders, which is the intended failure mode (loud,
# not silent).
LAYER_ORDER = [
    "reset",
    "tokens",
    "base",
    "theme-skin-base",
    "vendor-base",
    "components-base",
    "pages-base",
    "theme-chain",
    "theme-skin",
    "components",
    "pages",
    "utilities",
]
LAYER_INDEX = {name: i for i, name in enumerate(LAYER_ORDER)}

# Every file the admin theme's own `AssetContribution::css()` call sites
# (`ThemeBaseAssets::forAdminLayout()` + each admin `*View.php`) register
# unconditionally across skins, plus every per-page/per-component file --
# globbed wholesale rather than mirrored 1:1 against each page's own
# registration list, since which *pages* load a given file doesn't change
# whether two rules in it can conflict with the universal/skin files
# below. Keep in sync by hand with `ThemeBaseAssets::forAdminLayout()`
# if a new universal file is ever registered there.
ADMIN_UNIVERSAL = (
    [
        "themes/default/css/reset.css",
        "themes/admin/default/css/tokens.css",
        "themes/default/css/base.css",
        "themes/admin/default/fontello/css/fontello.css",
        "themes/admin/default/fontello/css/animation.css",
        "themes/admin/default/css/utilities.css",
        "themes/admin/default/theme-base.css",
        "themes/admin/default/theme.css",
    ]
    + sorted(
        str(p.relative_to(ROOT))
        for p in ROOT.glob("themes/admin/default/css/components/*.css")
        if "selectize-clear" not in p.name and "selectize-dark" not in p.name
    )
    + sorted(str(p.relative_to(ROOT)) for p in ROOT.glob("themes/admin/default/css/pages/*.css"))
)

# `roma`'s own asset id is `dark` (`themes/admin/roma/theme.json`'s
# `theme_id`) -- kept as the literal skin key here to match
# `ThemeBaseAssets::forAdminLayout()`'s directory name (`admin/roma/`)
# on the file-path side while matching the live `admin_theme` preference
# value (`roma`) that `AdminRomaThemeTest`/`H::setSessionPreference` use
# on the runtime side; neither name is wrong, they're just two different
# real identifiers for the same skin.
ADMIN_SKINS = {
    "clear": [
        "themes/admin/clear/theme-base.css",
        "themes/admin/clear/theme.css",
        "themes/admin/clear/css/components/general.css",
        "themes/admin/default/css/components/selectize-clear.css",
    ],
    "roma": [
        "themes/admin/roma/theme-base.css",
        "themes/admin/roma/theme.css",
        "themes/admin/roma/css/components/general.css",
        "themes/admin/default/css/components/selectize-dark.css",
    ],
}

# `default`'s (public gallery) real universal set, P52-E/F Step 6. Its
# only per-page alternate-CSS choice is the search page's colorscheme
# (below, modeled as this chain's "skin" dimension) -- `default` has no
# whole-theme skin system at all. The 4 admin-namespaced files are real,
# confirmed cross-chain loads (P52-E/F Step 3's audit): `jquery-ui.css`/
# `animation.css` directly and `album_selector.css`/`colorbox.css` via
# `SearchFiltersView`'s own composition of `AlbumSelectorView`/
# `ColorboxView` -- search page only, but co-occurrence for this tool's
# purposes only needs "can load on the same page," not "always loads."
DEFAULT_UNIVERSAL = (
    [
        "themes/default/css/reset.css",
        "themes/default/css/tokens.css",
        "themes/default/css/base.css",
        "themes/default/theme.css",
        "themes/default/print.css",
        "themes/default/vendor/fontello/css/gallery-icon.css",
        "themes/default/css/utilities.css",
        "themes/default/css/search.css",
        "themes/default/css/help/quick_search.css",
        "themes/admin/default/css/components/jquery-ui.css",
        "themes/admin/default/fontello/css/animation.css",
        "themes/admin/default/css/components/album_selector.css",
        "themes/admin/default/css/components/colorbox.css",
    ]
    + sorted(str(p.relative_to(ROOT)) for p in ROOT.glob("themes/default/css/components/*.css"))
    + sorted(str(p.relative_to(ROOT)) for p in ROOT.glob("themes/default/css/pages/*.css"))
)

DEFAULT_SKINS = {
    "dark-search": ["themes/default/css/dark-search.css"],
    "clear-search": ["themes/default/css/clear-search.css"],
}

# `standard_pages`' real universal set. `reset.css`/`base.css` are
# shared with `default` (Step 1); the 2 admin-namespaced cross-chain
# files are real, confirmed loads (`gallery-icon.css` on most of this
# chain's own pages, `fontello.css` on `ProfileView` only -- same
# "can co-occur" standard as `default`'s own cross-chain entries above).
STANDARD_PAGES_UNIVERSAL = (
    [
        "themes/default/css/reset.css",
        "themes/default/css/base.css",
        "themes/standard_pages/css/tokens.css",
        "themes/standard_pages/theme.css",
        "themes/standard_pages/css/utilities.css",
        "themes/default/vendor/fontello/css/gallery-icon.css",
        "themes/admin/default/fontello/css/fontello.css",
    ]
    + sorted(str(p.relative_to(ROOT)) for p in ROOT.glob("themes/standard_pages/css/pages/*.css"))
)

STANDARD_PAGES_SKINS = {
    name: [f"themes/standard_pages/skins/{name}.css"]
    for name in (
        "cadmium",
        "cobalt",
        "default",
        "fuchsia",
        "green",
        "lime",
        "purple",
        "red",
        "sienna",
        "silver",
        "teal",
    )
}

# One entry per real theme chain this tool understands -- `--chain`
# selects among these (default `admin`, so a bare `composer
# check:css-layers` keeps scanning exactly what it always has).
CHAINS: dict[str, dict[str, object]] = {
    "admin": {"universal": ADMIN_UNIVERSAL, "skins": ADMIN_SKINS},
    "default": {"universal": DEFAULT_UNIVERSAL, "skins": DEFAULT_SKINS},
    "standard_pages": {"universal": STANDARD_PAGES_UNIVERSAL, "skins": STANDARD_PAGES_SKINS},
}

SPEC_ID_RE = re.compile(r"#[a-zA-Z0-9_-]+")
SPEC_CLASS_RE = re.compile(r"\.[a-zA-Z0-9_-]+|\[[^\]]*\]|:[a-zA-Z-]+(?:\([^)]*\))?")
SPEC_ELEM_RE = re.compile(r"(?<![.\#\[:\w-])[a-zA-Z][a-zA-Z0-9-]*")
PSEUDO_ELEM_RE = re.compile(r"::[a-zA-Z-]+")
IMPORTANT_RE = re.compile(r"\s*!\s*important\s*$", re.IGNORECASE)


class Decl(TypedDict):
    selector: str
    compounds: tuple[str, ...]
    prop: str
    value: str
    important: bool
    specificity: tuple[int, int, int]
    layer: str | None
    file: str
    order: int


def _specificity_compound(compound: str) -> tuple[int, int, int]:
    working = compound
    pseudo_elems = PSEUDO_ELEM_RE.findall(working)
    working = PSEUDO_ELEM_RE.sub(" ", working)
    ids = len(SPEC_ID_RE.findall(working))
    classes = len(SPEC_CLASS_RE.findall(working))
    working_no_class = SPEC_CLASS_RE.sub(" ", SPEC_ID_RE.sub(" ", working))
    elems = len(SPEC_ELEM_RE.findall(working_no_class)) + len(pseudo_elems)
    return (ids, classes, elems)


def split_top_level(s: str, seps: str) -> list[str]:
    """Splits `s` on any character in `seps`, but only at paren-depth 0 --
    a comma or space inside a pseudo-class function's argument list
    (`:is(:-webkit-autofill, :autofill)`, `:not(.a .b)`) is part of that
    one selector, not a real selector-list/compound/combinator boundary.
    Found live: without this, `input:is(:-webkit-autofill, :autofill)`
    (P52-E/F's `standard_pages` skins) silently split into two bogus
    "selectors" at both the top-level comma-splitter and
    split_compounds()'s own whitespace split, corrupting every
    specificity/inversion finding touching that real selector. No
    admin-theme selector ever exercised this (confirmed: no `:is/:not/
    :where(...,...)` with an internal comma anywhere in `themes/admin`),
    which is why this went unnoticed until this chain's own content."""
    parts = []
    depth = 0
    buf = ""
    for ch in s:
        if ch == "(":
            depth += 1
            buf += ch
        elif ch == ")":
            depth -= 1
            buf += ch
        elif depth == 0 and ch in seps:
            parts.append(buf)
            buf = ""
        else:
            buf += ch
    parts.append(buf)
    return parts


def split_compounds(selector: str) -> tuple[str, ...]:
    compounds: list[str] = []
    for combinator_part in split_top_level(selector.strip(), ">+~"):
        compounds.extend(p for p in split_top_level(combinator_part, " \t\n\r\f") if p)
    return tuple(compounds)


def total_specificity(selector: str) -> tuple[int, int, int]:
    total = (0, 0, 0)
    for p in split_compounds(selector):
        s = _specificity_compound(p)
        total = (total[0] + s[0], total[1] + s[1], total[2] + s[2])
    return total


def norm_selector(s: str) -> str:
    # Whitespace-normalize only -- NOT case-folded. CSS class/ID
    # selectors match the HTML `class`/`id` attribute case-sensitively
    # (only element-type selectors and pseudo-class/element names are
    # case-insensitive); this codebase has real, deliberately
    # PascalCase classes (`.AddIcon`, `.AddIconTitle`, ...) that a
    # blanket `.lower()` would silently conflate with an unrelated
    # lowercase selector, manufacturing a phantom conflict where none
    # exists. Confirmed the hard way: an earlier version of this
    # function did lowercase, and its first real run reported
    # `.linkedalbumpopincontainer .addicontitle` losing to a generic
    # `.addicontitle` -- neither selector exists anywhere in the
    # codebase; the real, correctly-matching pair is
    # `.linkedAlbumPopInContainer .AddIconTitle` (component, popup-
    # specific) vs `.AddIconTitle` (page, generic), case preserved.
    return re.sub(r"\s*([>+~])\s*", r" \1 ", re.sub(r"\s+", " ", s.strip()))


def _strip_comments(text: str) -> str:
    return re.sub(r"/\*.*?\*/", lambda m: re.sub(r"[^\n]", " ", m.group(0)), text, flags=re.DOTALL)


def parse_file(path: Path) -> list[Decl]:
    """Walks the file tracking `@layer` nesting (only one level deep --
    this codebase never nests a second `@layer` inside another) and
    skips `@media`/`@supports`/`@keyframes`/etc. content entirely (see
    the module docstring's scope-limit note)."""
    raw = path.read_text(encoding="utf-8")
    text = _strip_comments(raw)
    # Drop the one-time master `@layer` order statement (ends in `;`,
    # no brace) -- it isn't a rule block and would otherwise confuse the
    # brace walker below.
    text = re.sub(r"@layer\s+[a-z-]+(?:\s*,\s*[a-z-]+)*\s*;", "", text)

    relpath = str(path.relative_to(ROOT))
    out: list[Decl] = []
    order = [0]
    n = len(text)

    def skip_balanced(i: int) -> int:
        depth = 1
        i += 1
        while i < n and depth > 0:
            if text[i] == "{":
                depth += 1
            elif text[i] == "}":
                depth -= 1
            i += 1
        return i

    def parse_props(body: str) -> list[tuple[str, str, bool]]:
        res = []
        for decl in body.split(";"):
            decl = decl.strip()
            if ":" not in decl:
                continue
            k, v = decl.split(":", 1)
            k = k.strip().lower()
            if not k:
                continue
            important = bool(IMPORTANT_RE.search(v))
            v = re.sub(r"\s+", " ", IMPORTANT_RE.sub("", v).strip())
            res.append((k, v, important))
        return res

    def walk(i: int, end: int, layer: str | None) -> None:
        buf = ""
        while i < end:
            c = text[i]
            if c != "{":
                buf += c
                i += 1
                continue
            token = buf.strip()
            buf = ""
            if token.startswith("@layer"):
                m = re.match(r"@layer\s+([a-z-]+)\s*$", token)
                inner_layer = m.group(1) if m else layer
                close = skip_balanced(i)
                walk(i + 1, close - 1, inner_layer)
                i = close
                continue
            if token.startswith("@"):
                i = skip_balanced(i)
                continue
            close = skip_balanced(i)
            body = text[i + 1 : close - 1]
            for part in (p.strip() for p in split_top_level(token, ",")):
                if not part:
                    continue
                compounds = split_compounds(part)
                spec = total_specificity(part)
                for prop, val, important in parse_props(body):
                    order[0] += 1
                    out.append(
                        Decl(
                            selector=norm_selector(part),
                            compounds=compounds,
                            prop=prop,
                            value=val,
                            important=important,
                            specificity=spec,
                            layer=layer,
                            file=relpath,
                            order=order[0],
                        )
                    )
            i = close

    walk(0, n, None)
    return out


def normal_priority(layer: str | None) -> int:
    return len(LAYER_ORDER) if layer is None else LAYER_INDEX[layer]


def important_priority(layer: str | None) -> int:
    return -1000 if layer is None else -LAYER_INDEX[layer]


def resolve_winner(decls: list[Decl]) -> Decl | None:
    imp = [d for d in decls if d["important"]]
    pool = imp if imp else [d for d in decls if not d["important"]]
    if not pool:
        return None
    prio = important_priority if imp else normal_priority
    return max(pool, key=lambda d: (prio(d["layer"]), d["specificity"], d["order"]))


def build_pool(chain: str, skin: str, files: list[str] | None = None) -> list[Decl]:
    if files is not None:
        file_list = files
    else:
        chain_def = CHAINS[chain]
        universal = cast("list[str]", chain_def["universal"])
        skins = cast("dict[str, list[str]]", chain_def["skins"])
        file_list = universal + skins[skin]
    pool: list[Decl] = []
    for f in file_list:
        pool.extend(parse_file(ROOT / f))
    return pool


def check_important(pool: list[Decl], chain: str, skin: str) -> list[dict]:
    by_key: dict[tuple[str, str], list[Decl]] = defaultdict(list)
    for d in pool:
        by_key[(d["selector"], d["prop"])].append(d)

    findings = []
    for decls in by_key.values():
        imp_decls = [d for d in decls if d["important"]]
        if not imp_decls:
            continue
        current_winner = resolve_winner(decls)
        for d in imp_decls:
            without_this_one = [cast(Decl, {**x, "important": False}) if x is d else x for x in decls]
            new_winner = resolve_winner(without_this_one)

            def same(a: Decl | None, b: Decl | None) -> bool:
                return a is b or (a is not None and b is not None and a["file"] == b["file"] and a["order"] == b["order"])

            if not same(current_winner, d):
                classification = "ALREADY_DEAD"
            elif same(new_winner, d):
                classification = "REDUNDANT"
            else:
                classification = "LOAD_BEARING"

            findings.append(
                {
                    "chain": chain,
                    "skin": skin,
                    "classification": classification,
                    "file": d["file"],
                    "selector": d["selector"],
                    "prop": d["prop"],
                    "value": d["value"],
                }
            )
    return findings


def check_inversions(pool: list[Decl], chain: str, skin: str) -> list[dict]:
    """A declaration A is flagged when a strictly more generic selector B
    (B's compound chain is an exact trailing subsequence of A's) carries
    a *different* value for the same property, and the real winner under
    this codebase's layer order is B -- even though plain specificity
    (ignoring layer position entirely) would have picked A. Both sides
    restricted to non-`!important` declarations; `!important` has its
    own reversed priority model and its own check above."""
    by_prop: dict[str, list[Decl]] = defaultdict(list)
    for d in pool:
        if not d["important"]:
            by_prop[d["prop"]].append(d)

    findings = []
    seen: set[tuple[str, int, str, int]] = set()
    for decls in by_prop.values():
        for a in decls:
            for b in decls:
                if a is b or a["value"] == b["value"]:
                    continue
                if a["selector"] == b["selector"]:
                    continue
                a_compounds, b_compounds = a["compounds"], b["compounds"]
                if len(b_compounds) >= len(a_compounds):
                    continue
                if a_compounds[len(a_compounds) - len(b_compounds) :] != b_compounds:
                    continue  # b isn't a trailing generalization of a
                if a["specificity"] <= b["specificity"]:
                    continue  # not actually more specific by the numbers

                winner = resolve_winner([a, b])
                if winner is not b:
                    continue  # specificity already decides correctly; no inversion

                key = (a["file"], a["order"], b["file"], b["order"])
                if key in seen:
                    continue
                seen.add(key)
                findings.append(
                    {
                        "chain": chain,
                        "skin": skin,
                        "prop": a["prop"],
                        "specific_selector": a["selector"],
                        "specific_file": a["file"],
                        "specific_value": a["value"],
                        "specific_layer": a["layer"],
                        "generic_selector": b["selector"],
                        "generic_file": b["file"],
                        "generic_value": b["value"],
                        "generic_layer": b["layer"],
                    }
                )
    return findings


DEFAULT_BASELINE = Path(__file__).with_name("known-findings.json")


def inversion_fingerprint(f: dict) -> str:
    # Deliberately excludes the two `*_value` fields: a future edit that
    # only changes which value a known, already-triaged-harmless pairing
    # carries isn't a new inversion to re-triage, it's the same pairing.
    # `chain` leads (P52-E/F generalization) so `standard_pages`' own
    # `default` skin name can never collide with the `default` *chain*'s
    # fingerprints, or with any other chain's skin names.
    return "|".join(
        [
            f["chain"],
            f["skin"],
            f["prop"],
            f["specific_selector"],
            f["specific_file"],
            f["generic_selector"],
            f["generic_file"],
        ]
    )


def load_baseline(path: Path) -> set[str]:
    if not path.exists():
        return set()
    return set(json.loads(path.read_text(encoding="utf-8")))


def save_baseline(path: Path, findings: list[dict], chain: str) -> None:
    # Merges rather than overwrites: the baseline is one shared file
    # across all 3 chains (P52-E/F generalization), but a single
    # --update-baseline run only ever re-scans one chain at a time.
    # Overwriting wholesale would silently wipe every other chain's
    # already-triaged findings. Every fingerprint leads with its own
    # chain (inversion_fingerprint()), so the other chains' entries are
    # identified by prefix, not by re-running their own scan here.
    existing = load_baseline(path)
    other_chains = {fp for fp in existing if not fp.startswith(f"{chain}|")}
    fingerprints = sorted(other_chains | {inversion_fingerprint(f) for f in findings})
    path.write_text(json.dumps(fingerprints, indent=2) + "\n", encoding="utf-8")


def _print_important(findings: list[dict]) -> None:
    by_class = defaultdict(int)
    for f in findings:
        by_class[f["classification"]] += 1
    print(f"!important: {len(findings)} declarations across scanned skins", file=sys.stderr)
    for cls in ("LOAD_BEARING", "REDUNDANT", "ALREADY_DEAD"):
        print(f"  {cls}: {by_class.get(cls, 0)}", file=sys.stderr)
    for f in findings:
        if f["classification"] != "LOAD_BEARING":
            print(f"  [{f['classification']}] {f['chain']}/{f['skin']}: {f['file']}  {f['selector']} {{ {f['prop']}: {f['value']} !important }}", file=sys.stderr)


def _print_inversion(f: dict) -> None:
    print(
        f"  [{f['chain']}/{f['skin']}] {f['prop']}: "
        f"specific `{f['specific_selector']}` ({f['specific_file']}, layer={f['specific_layer']}) = {f['specific_value']!r} "
        f"loses to generic `{f['generic_selector']}` ({f['generic_file']}, layer={f['generic_layer']}) = {f['generic_value']!r}",
        file=sys.stderr,
    )


def _print_inversions(findings: list[dict], baseline: set[str]) -> list[dict]:
    """Prints the full report, split into known (baselined) vs. new
    findings, and returns just the new ones -- the only ones that should
    affect the exit code."""
    new = [f for f in findings if inversion_fingerprint(f) not in baseline]
    known = [f for f in findings if inversion_fingerprint(f) in baseline]
    print(
        f"layer-priority inversions: {len(findings)} candidate(s) "
        f"({len(known)} known/baselined, {len(new)} new)",
        file=sys.stderr,
    )
    for f in new:
        print("  NEW:", file=sys.stderr)
        _print_inversion(f)
    return new


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    parser.add_argument(
        "--chain",
        choices=sorted(CHAINS),
        default="admin",
        help="which theme chain to scan (default: admin, so a bare `composer check:css-layers` is unchanged)",
    )
    parser.add_argument("--skin", default="both", help="a skin name for the chosen --chain, or 'both' (default) for all of that chain's skins")
    parser.add_argument("--important", action="store_true", help="run the !important classification")
    parser.add_argument("--inversions", action="store_true", help="run the layer-priority-inversion check")
    parser.add_argument("--json", metavar="PATH", help="write the raw findings as JSON to PATH")
    parser.add_argument(
        "--baseline",
        metavar="PATH",
        default=str(DEFAULT_BASELINE),
        help=f"known/triaged inversion findings to suppress (default: {DEFAULT_BASELINE.name})",
    )
    parser.add_argument(
        "--update-baseline",
        action="store_true",
        help="write the current --inversions findings as the new baseline instead of checking one",
    )
    args = parser.parse_args()

    chain_skins = cast("dict[str, list[str]]", CHAINS[args.chain]["skins"])
    if args.skin != "both" and args.skin not in chain_skins:
        parser.error(f"--skin {args.skin!r} isn't valid for --chain {args.chain!r} (choices: {', '.join(sorted(chain_skins))}, or 'both')")

    run_important = args.important
    run_inversions = args.inversions
    if not run_important and not run_inversions:
        run_important = run_inversions = True
    if args.update_baseline:
        run_inversions = True

    skins = sorted(chain_skins) if args.skin == "both" else [args.skin]

    important_findings: list[dict] = []
    inversion_findings: list[dict] = []
    for skin in skins:
        pool = build_pool(args.chain, skin)
        if run_important:
            important_findings += check_important(pool, args.chain, skin)
        if run_inversions:
            inversion_findings += check_inversions(pool, args.chain, skin)

    if run_important:
        _print_important(important_findings)

    new_inversions: list[dict] = []
    if run_inversions:
        if args.update_baseline:
            save_baseline(Path(args.baseline), inversion_findings, args.chain)
            print(f"wrote {len(inversion_findings)} finding(s) for chain={args.chain} to {args.baseline}", file=sys.stderr)
        else:
            baseline = load_baseline(Path(args.baseline))
            new_inversions = _print_inversions(inversion_findings, baseline)

    if args.json:
        payload = {}
        if run_important:
            payload["important"] = important_findings
        if run_inversions:
            payload["inversions"] = inversion_findings
        Path(args.json).write_text(json.dumps(payload, indent=2))

    if args.update_baseline:
        return 0
    # REDUNDANT/ALREADY_DEAD !important is dead weight, not a live
    # regression risk -- informational only, doesn't fail the build.
    # A layer-priority inversion NOT already in the baseline is exactly
    # the silent-regression shape this tool exists to catch, so it does.
    return 1 if new_inversions else 0


if __name__ == "__main__":
    sys.exit(main())
