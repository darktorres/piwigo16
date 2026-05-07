"""
Replace remaining free-function calls in src/ with direct service calls.
No delegates — callers use the service methods directly.
"""
import re
import glob

REPLACEMENTS = [
    # (free function pattern, service replacement, use class to add if missing)
    (r'(?<![>\-:a-zA-Z0-9_$])safe_unserialize\s*\(',
     'StringUtil::safeUnserialize(',
     'Piwigo\\Core\\StringUtil'),
    (r'(?<![>\-:a-zA-Z0-9_$])get_elapsed_time\s*\(',
     'StringUtil::getElapsedTime(',
     'Piwigo\\Core\\StringUtil'),
    (r'(?<![>\-:a-zA-Z0-9_$])fatal_error\s*\(',
     'HtmlService::fatalError(',
     'Piwigo\\Html\\HtmlService'),
    (r'(?<![>\-:a-zA-Z0-9_$])load_conf_from_db\s*\(',
     'ServiceLocator::get(ConfigService::class)->loadConfFromDb(',
     'Piwigo\\Config\\ConfigService'),
    (r'(?<![>\-:a-zA-Z0-9_$])load_language\s*\(',
     'LangService::get()->loadLanguage(',
     'Piwigo\\Lang\\LangService'),
    (r'(?<![>\-:a-zA-Z0-9_$])redirect\s*\(',
     'Util::get()->redirect(',
     'Piwigo\\Core\\Util'),
    (r'(?<![>\-:a-zA-Z0-9_$])mkgetdir\s*\(',
     'Util::get()->mkgetdir(',
     'Piwigo\\Core\\Util'),
]

QUOTED_RE = re.compile(
    r"('(?:[^'\\]|\\.)*'|\"(?:[^\"\\]|\\.)*\"|<<<'?([A-Za-z_]+)'?\n.*?\n\2;)",
    re.DOTALL,
)


def replace_in_code(content, pat, repl):
    """Replace pat with repl only outside quoted strings."""
    parts = []
    last = 0
    for m in QUOTED_RE.finditer(content):
        # outside
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
    # Insert after the last consecutive 'use ' block before class/function
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

    # Skip files that are method definitions with these names (Util.php owns redirect/mkgetdir)
    skip_method_defs = path.replace('\\', '/').endswith('Core/Util.php')

    for pat, repl, use_class in REPLACEMENTS:
        if skip_method_defs and repl.startswith('Util::get()'):
            continue
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
