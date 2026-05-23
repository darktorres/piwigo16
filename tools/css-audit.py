#!/usr/bin/env python3
"""CSS dead-selector audit.

Walks every selector in our base CSS directories and reports those
whose target classes/IDs do not appear in any template, JS/TS, PHP, or
HTML source. The output is a CANDIDATE list — manual review is still
required before deletion (classes constructed dynamically in JS or
referenced by external plugins won't be detected).

Run from repo root: `python3 tools/css-audit.py`
"""

import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent

CSS_GLOBS = [
    # admin base layer
    "themes/admin/_base/css/**/*.css",
    "themes/admin/_base/theme.css",
    "themes/admin/_base/print.css",
    "themes/admin/_base/js/**/*.css",
    # admin child themes
    "themes/admin/dark/**/*.css",
    "themes/admin/light/**/*.css",
    # frontend base layer
    "themes/_base/css/**/*.css",
    "themes/_base/theme.css",
    "themes/_base/print.css",
    "themes/_base/iconset.css",
    # standard_pages (login/register/profile/etc)
    "themes/standard_pages/**/*.css",
    # ws.htm tester
    "tools/ws/*.css",
]

CORPUS_GLOBS = [
    "themes/**/*.latte",
    "themes/**/*.tpl",
    "themes/**/*.js",
    "themes/**/*.ts",
    "themes/**/*.html",
    "themes/**/*.htm",
    "src/**/*.php",
    "include/**/*.php",
    "admin/**/*.php",
    "install/**/*.php",
    "tools/**/*.js",
    "tools/**/*.ts",
    "tools/**/*.html",
    "tools/**/*.htm",
    "tools/**/*.latte",
    "language/**/*.html",
    "*.php",
    "*.htm",
    "*.html",
]

ALLOWLIST_PREFIXES = (
    # third-party widgets whose class names are emitted by the lib
    "jconfirm",
    "ts-",
    "tom-select",
    "flatpickr",
    "tiptip",
    "datepicker",
    "ui-",
    "swiper",
    "sortable",
    "select2",
    "noUi",
    "moxie",
    "fa-",
    "cbox",         # Colorbox modal — loaded by several admin templates
    "dataTable",    # DataTables.js — loaded by rating_user.ts
    "sorting_",     # DataTables sort markers
    "tagLevel",     # dynamic: emitted as `tagLevel{$tag.level}` in tags.latte
)

# Whole-identifier allowlist (not a prefix — exact match)
ALLOWLIST_EXACT = {
    # DataTables generates id-suffixed elements: <id>_length, <id>_filter, etc.
    "userList_length", "userList_filter", "userList_paginate",
    "userList_info", "userList_processing",
    # jconfirm buttons — generated at runtime by the library
    "btn-default", "btn-red",
}

AT_RULE_SKIP_BODY = {"keyframes", "font-face", "page", "property",
                     "font-feature-values", "counter-style"}


def strip_comments(css: str) -> str:
    return re.sub(r"/\*.*?\*/", "", css, flags=re.DOTALL)


def extract_selectors(css: str):
    """Yield (selector_text, line_number) for each rule selector."""
    text = strip_comments(css)
    n = len(text)
    buf = []
    line = 1
    i = 0
    while i < n:
        ch = text[i]
        if ch == "\n":
            line += 1
            buf.append(ch)
            i += 1
        elif ch == "{":
            sel = "".join(buf).strip()
            buf = []
            if sel.startswith("@"):
                m = re.match(r"@([a-zA-Z-]+)", sel)
                at_name = m.group(1) if m else ""
                if at_name in AT_RULE_SKIP_BODY:
                    depth = 1
                    i += 1
                    while i < n and depth > 0:
                        if text[i] == "{":
                            depth += 1
                        elif text[i] == "}":
                            depth -= 1
                        elif text[i] == "\n":
                            line += 1
                        i += 1
                    continue
                # @media / @supports / @container / @layer — scan their body
            else:
                if sel:
                    yield (sel, line)
            i += 1
        elif ch == "}":
            buf = []
            i += 1
        elif ch == ";":
            joined = "".join(buf).strip()
            if joined.startswith("@"):
                buf = []
            else:
                buf.append(ch)
            i += 1
        else:
            buf.append(ch)
            i += 1


def extract_target_idents(selector_text: str):
    """For a comma-separated selector list, yield (group_sel, ids, classes,
    chain_idents) per group.

    `ids`/`classes` are the identifiers on the rightmost target element
    only. `chain_idents` is every class/id appearing anywhere in the
    selector chain — if ANY of those is missing from the source corpus,
    the rule's element never exists (a live rightmost with a dead
    parent never matches)."""
    results = []
    for sel in selector_text.split(","):
        sel = sel.strip()
        if not sel:
            continue
        parts = re.split(r"(?<!\\)[\s>+~]+", sel)
        rightmost = parts[-1] if parts else sel
        rightmost = re.sub(r"::?[a-zA-Z-]+(\([^)]*\))?", "", rightmost)
        rightmost = re.sub(r"\[[^\]]*\]", "", rightmost)
        ids = re.findall(r"#([a-zA-Z_][\w-]*)", rightmost)
        classes = re.findall(r"\.([a-zA-Z_][\w-]*)", rightmost)

        # full-chain idents — strip pseudo + attribute syntax from the
        # entire selector then extract every class/id
        chain = re.sub(r"::?[a-zA-Z-]+(\([^)]*\))?", "", sel)
        chain = re.sub(r"\[[^\]]*\]", "", chain)
        chain_ids = re.findall(r"#([a-zA-Z_][\w-]*)", chain)
        chain_classes = re.findall(r"\.([a-zA-Z_][\w-]*)", chain)
        chain_idents = sorted(set(chain_ids + chain_classes))

        results.append((sel, sorted(set(ids)), sorted(set(classes)),
                        chain_idents))
    return results


def is_allowlisted(ident: str) -> bool:
    if ident in ALLOWLIST_EXACT:
        return True
    return any(ident.startswith(p) for p in ALLOWLIST_PREFIXES)


def build_corpus() -> str:
    chunks = []
    files = []
    for g in CORPUS_GLOBS:
        files.extend(sorted(ROOT.glob(g)))
    for f in files:
        try:
            chunks.append(f.read_text(encoding="utf-8", errors="replace"))
        except OSError:
            pass
    print(f"  corpus: {len(files)} files", file=sys.stderr)
    return "\n".join(chunks)


def main():
    css_files = []
    for g in CSS_GLOBS:
        css_files.extend(sorted(ROOT.glob(g)))
    print(f"Scanning {len(css_files)} CSS files…", file=sys.stderr)

    print("Building corpus…", file=sys.stderr)
    corpus = build_corpus()

    print("Auditing…", file=sys.stderr)
    dead_by_file: dict[str, list[tuple[int, str]]] = {}
    total_selectors = 0
    for cf in css_files:
        rel = cf.relative_to(ROOT).as_posix()
        text = cf.read_text(encoding="utf-8", errors="replace")
        for sel_text, line_no in extract_selectors(text):
            for group_sel, ids, classes, chain in extract_target_idents(sel_text):
                total_selectors += 1
                idents = ids + classes
                if not idents:
                    # tag-only rightmost — still flag if any chain ident is dead
                    if not chain:
                        continue
                    if any(is_allowlisted(i) for i in chain):
                        continue
                    if all(i in corpus for i in chain):
                        continue
                    dead_by_file.setdefault(rel, []).append((line_no, group_sel))
                    continue
                if any(is_allowlisted(i) for i in idents):
                    continue
                # rightmost is dead OR any chain ident is dead → rule never matches
                rightmost_dead = not any(i in corpus for i in idents)
                chain_break = any(i not in corpus and not is_allowlisted(i)
                                  for i in chain if i not in idents)
                if not (rightmost_dead or chain_break):
                    continue
                dead_by_file.setdefault(rel, []).append((line_no, group_sel))

    # Report
    for f in sorted(dead_by_file):
        print(f"\n{f}:")
        seen = set()
        for line_no, sel in dead_by_file[f]:
            key = (line_no, sel)
            if key in seen:
                continue
            seen.add(key)
            print(f"  L{line_no}: {sel[:140]}")

    dead_count = sum(len(set(v)) for v in dead_by_file.values())
    print(f"\nScanned {total_selectors} selector groups across "
          f"{len(css_files)} files.", file=sys.stderr)
    print(f"Dead candidates: {dead_count} in {len(dead_by_file)} files.",
          file=sys.stderr)


if __name__ == "__main__":
    main()
