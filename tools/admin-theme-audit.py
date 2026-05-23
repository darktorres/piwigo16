#!/usr/bin/env python3
"""Admin child-theme migration audit.

Classifies every selector in themes/admin/{dark,light}/theme.css
(plus dark/css/components/general.css) into migration buckets by
comparing against themes/admin/_base/css/**/*.css.

Buckets:
  A  — In dark + light + base (color override candidates).
  B  — In dark + light, NOT in base (misplaced — should be in base).
  C  — In dark + base, NOT in light (dark-only override).
  D  — In light + base, NOT in dark (light-only override).
  E  — Dark-exclusive (not in base, not in light).
  F  — Light-exclusive (not in base, not in dark).
  X  — In dark + light but with different property sets (asymmetric).

Run from repo root:  python3 tools/admin-theme-audit.py
"""

import re
import sys
from pathlib import Path
from collections import defaultdict

ROOT = Path(__file__).resolve().parent.parent

COLOR_PROPS = frozenset({
    'color', 'background-color', 'background', 'border-color',
    'border', 'border-top', 'border-bottom', 'border-left', 'border-right',
    'border-top-color', 'border-bottom-color', 'border-left-color',
    'border-right-color', 'outline', 'outline-color', 'box-shadow',
    'text-shadow', 'fill', 'stroke', 'text-decoration-color',
    'caret-color', 'opacity',
})


def strip_comments(css: str) -> str:
    return re.sub(r'/\*.*?\*/', '', css, flags=re.DOTALL)


def normalize_selector(sel: str) -> str:
    sel = ' '.join(sel.split())
    parts = [p.strip() for p in sel.split(',')]
    return ', '.join(sorted(parts))


def parse_rules(css: str) -> dict[str, dict[str, str]]:
    """Parse CSS text into {normalized_selector: {prop: value}}."""
    css = strip_comments(css)
    result: dict[str, dict[str, str]] = {}
    _extract(css, '', result)
    return result


def _extract(text: str, media: str, out: dict):
    i = 0
    n = len(text)
    while i < n:
        while i < n and text[i] in ' \t\r\n':
            i += 1
        if i >= n:
            break

        brace = text.find('{', i)
        if brace == -1:
            break

        selector = text[i:brace].strip()

        if selector.startswith('@media'):
            depth, j = 1, brace + 1
            while j < n and depth > 0:
                if text[j] == '{':
                    depth += 1
                elif text[j] == '}':
                    depth -= 1
                j += 1
            inner = text[brace + 1 : j - 1]
            _extract(inner, selector, out)
            i = j
        elif selector.startswith('@'):
            depth, j = 1, brace + 1
            while j < n and depth > 0:
                if text[j] == '{':
                    depth += 1
                elif text[j] == '}':
                    depth -= 1
                j += 1
            i = j
        else:
            close = text.find('}', brace + 1)
            if close == -1:
                break
            body = text[brace + 1 : close].strip()
            props = {}
            for decl in body.split(';'):
                decl = decl.strip()
                if ':' not in decl:
                    continue
                colon = decl.index(':')
                prop = decl[:colon].strip().lower()
                val = decl[colon + 1 :].strip()
                if prop:
                    props[prop] = val

            if selector and props:
                norm = normalize_selector(selector)
                if media:
                    norm = f'{media} {{ {norm} }}'
                if norm in out:
                    out[norm].update(props)
                else:
                    out[norm] = dict(props)

            i = close + 1


def is_color_only(props: dict) -> bool:
    return all(p in COLOR_PROPS for p in props if not p.startswith('--'))


def main():
    dark_path = ROOT / 'themes/admin/dark/theme.css'
    light_path = ROOT / 'themes/admin/light/theme.css'
    general_path = ROOT / 'themes/admin/dark/css/components/general.css'

    dark_map = parse_rules(dark_path.read_text())
    light_map = parse_rules(light_path.read_text())
    general_map = parse_rules(general_path.read_text())

    dark_map.pop(':root', None)
    for sel, props in general_map.items():
        if sel == ':root':
            continue
        dark_map.setdefault(sel, {}).update(props)

    base_selectors: set[str] = set()
    base_files = list((ROOT / 'themes/admin/_base/css').rglob('*.css'))
    for extra in ['themes/admin/_base/theme.css', 'themes/admin/_base/print.css']:
        p = ROOT / extra
        if p.exists():
            base_files.append(p)

    for f in base_files:
        try:
            for sel in parse_rules(f.read_text()):
                base_selectors.add(sel)
        except Exception:
            pass

    all_sels = set(dark_map) | set(light_map)
    buckets: dict[str, list[str]] = defaultdict(list)

    for sel in sorted(all_sels):
        in_dark = sel in dark_map
        in_light = sel in light_map
        in_base = sel in base_selectors

        if in_dark and in_light:
            dark_keys = set(k for k in dark_map[sel] if not k.startswith('--'))
            light_keys = set(k for k in light_map[sel] if not k.startswith('--'))
            if dark_keys != light_keys:
                buckets['X'].append(sel)
            elif in_base:
                buckets['A'].append(sel)
            else:
                buckets['B'].append(sel)
        elif in_dark:
            buckets['C' if in_base else 'E'].append(sel)
        else:
            buckets['D' if in_base else 'F'].append(sel)

    print('# Admin Child-Theme Migration Audit\n')
    print(f'Dark selectors: {len(dark_map)}')
    print(f'Light selectors: {len(light_map)}')
    print(f'Base selectors: {len(base_selectors)}\n')

    meanings = {
        'A': 'Both children + base (override)',
        'B': 'Both children, NOT base (misplaced)',
        'C': 'Dark + base (dark override)',
        'D': 'Light + base (light override)',
        'E': 'Dark-exclusive',
        'F': 'Light-exclusive',
        'X': 'Asymmetric prop sets',
    }

    print('| Bucket | Meaning | Count |')
    print('|--------|---------|------:|')
    for code in ['A', 'B', 'C', 'D', 'E', 'F', 'X']:
        count = len(buckets[code])
        print(f'| {code} | {meanings[code]} | {count} |')
    total = sum(len(v) for v in buckets.values())
    print(f'| **Total** | | **{total}** |\n')

    for code in ['A', 'B', 'C', 'D', 'E', 'F', 'X']:
        sels = buckets[code]
        if not sels:
            continue
        print(f'\n## Bucket {code} — {meanings[code]} ({len(sels)})\n')
        for sel in sels:
            props = dark_map.get(sel, light_map.get(sel, {}))
            non_custom = {k: v for k, v in props.items() if not k.startswith('--')}
            co = ' [color-only]' if is_color_only(non_custom) else ''
            prop_names = ', '.join(sorted(non_custom.keys()))
            print(f'- `{sel}` — {prop_names}{co}')


if __name__ == '__main__':
    main()
