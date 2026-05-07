"""Scan src/ for all 'mixed' type usages and categorize them."""
import re
import glob

GLOBAL_VAR_NAMES = r'(?:page|user|lang|bmf|body|dim_filter|fs_filter|catData|pwg_loaded|page_infos_ref|pderivative|fields|session_code|code)'

cats = {
    'lambda_mixed': [],
    'docblock_global_var': [],
    'docblock_array_mixed': [],
    'return_mixed': [],
    'param_mixed': [],
    'other': [],
}

for path in sorted(glob.glob('src/**/*.php', recursive=True)):
    rel = path.replace('\\', '/')
    with open(path, encoding='utf-8') as f:
        lines = f.readlines()
    for i, line in enumerate(lines, 1):
        s = line.strip()
        if s.startswith('//') or s.startswith('#'):
            continue
        if 'mixed' not in line:
            continue
        if not re.search(r'\bmixed\b', line):
            continue

        loc = rel + ':' + str(i)

        if re.search(r'fn\s*\(mixed', line) or re.search(r'function\s*\(\s*mixed', line):
            cats['lambda_mixed'].append((loc, s[:110]))
        elif re.search(r'@var\s+array<string,\s*mixed>', s) and re.search(r'\$(?:' + GLOBAL_VAR_NAMES + r')\b', s):
            cats['docblock_global_var'].append((loc, s[:110]))
        elif re.search(r'@(?:param|return|var)\s+(?:array<[^>]*mixed[^>]*>|mixed)', s):
            cats['docblock_array_mixed'].append((loc, s[:110]))
        elif re.search(r'\):\s*mixed\b', line):
            cats['return_mixed'].append((loc, s[:110]))
        elif re.search(r'\bmixed\s+&?\$\w+', line) and ('function' in line or 'fn ' in line):
            cats['param_mixed'].append((loc, s[:110]))
        else:
            cats['other'].append((loc, s[:110]))

print('Category counts:')
for k, v in cats.items():
    print(f'  {k}: {len(v)}')
print()

for cat, items in cats.items():
    print(f'=== {cat} ({len(items)}) ===')
    for loc, text in items:
        print(f'  {loc}')
        print(f'    {text}')
    print()
