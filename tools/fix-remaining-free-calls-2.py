"""
Second pass: replace remaining free-function calls in src/ with direct service calls.
"""
import re
import glob

REPLACEMENTS = [
    # (free function pattern, service replacement, use class)
    (r'(?<![>\-:a-zA-Z0-9_$])l10n\s*\(',
     'Lang::t(',
     'Piwigo\\Core\\Lang'),
    (r'(?<![>\-:a-zA-Z0-9_$])input_int\s*\(',
     'StringUtil::inputInt(',
     'Piwigo\\Core\\StringUtil'),
    (r'(?<![>\-:a-zA-Z0-9_$])input_string\s*\(',
     'StringUtil::inputString(',
     'Piwigo\\Core\\StringUtil'),
    (r'(?<![>\-:a-zA-Z0-9_$])input_bool\s*\(',
     'StringUtil::inputBool(',
     'Piwigo\\Core\\StringUtil'),
    (r'(?<![>\-:a-zA-Z0-9_$])conf_update_param\s*\(',
     'ServiceLocator::get(ConfigService::class)->confUpdateParam(',
     'Piwigo\\Config\\ConfigService'),
    (r'(?<![>\-:a-zA-Z0-9_$])check_input_parameter\s*\(',
     'Util::get()->checkInputParameter(',
     'Piwigo\\Core\\Util'),
    (r'(?<![>\-:a-zA-Z0-9_$])pwg_activity\s*\(',
     'ServiceLocator::get(Util::class)->pwgActivity(',
     'Piwigo\\Core\\Util'),
    (r'(?<![>\-:a-zA-Z0-9_$])name_compare\s*\(',
     'ServiceLocator::get(HtmlService::class)->nameCompare(',
     'Piwigo\\Html\\HtmlService'),
]

# Matches single-quoted, double-quoted strings, and nowdoc/heredoc
QUOTED_RE = re.compile(
    r"'(?:[^'\\]|\\.)*'"       # single-quoted
    r'|"(?:[^"\\]|\\.)*"'      # double-quoted
    r"|<<<'([A-Za-z_]+)'\n.*?\n\1;"   # nowdoc
    r'|<<<([A-Za-z_]+)\n.*?\n\2;',    # heredoc
    re.DOTALL,
)


def replace_in_code(content, pat, repl):
    parts = []
    last = 0
    for m in QUOTED_RE.finditer(content):
        chunk = re.sub(pat, repl, content[last:m.start()])
        parts.append(chunk)
        parts.append(m.group(0))
        last = m.end()
    parts.append(re.sub(pat, repl, content[last:]))
    return ''.join(parts)


def add_use(content, use_class):
    use_line = f'use {use_class};'
    if use_line in content:
        return content
    return re.sub(
        r'(^use [^\n]+;\n)(?!use )',
        lambda m: m.group(0) + use_line + '\n',
        content, count=1, flags=re.MULTILINE,
    )


files = glob.glob('src/**/*.php', recursive=True)

changed = []
for path in files:
    with open(path, encoding='utf-8-sig') as f:
        original = f.read()
    content = original

    for pat, repl, use_class in REPLACEMENTS:
        new = replace_in_code(content, pat, repl)
        if new != content:
            new = add_use(new, use_class)
            content = new

    if content != original:
        changed.append(path)
        with open(path, 'w', encoding='utf-8', newline='') as f:
            f.write(content)

print(f'Modified {len(changed)} files:')
for p in sorted(changed):
    print(' ', p)
