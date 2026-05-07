"""
Replaces remaining pre-boot free function calls with class method calls.
"""
import re, os

BASE = r'C:\Apache24\htdocs\piwigo16'
SKIP = {'functions.inc.php', 'migrate-preboot-functions.py'}

REPS = [
    # fatal_error -> HtmlService::fatalError (static)
    ('fatal_error',             'HtmlService::fatalError'),
    # load_language -> LangService::get()->loadLanguage
    ('load_language',           'LangService::get()->loadLanguage'),
    # redirect* -> Util::get()->redirect*
    ('redirect_http',           'Util::get()->redirectHttp'),
    ('redirect_html',           'Util::get()->redirectHtml'),
    ('redirect',                'Util::get()->redirect'),
    # l10n helpers -> LangService::get()
    ('get_l10n_args',           'LangService::get()->getL10nArgs'),
    ('l10n_args',               'LangService::get()->l10nArgs'),
    ('get_parent_language',     'LangService::get()->getParentLanguage'),
    # generate_key -> StringUtil::generateKey (static)
    ('generate_key',            'StringUtil::generateKey'),
    # get_branch_from_version -> AppInfo::branchFromVersion (static)
    ('get_branch_from_version', 'AppInfo::branchFromVersion'),
    # script_basename -> StringUtil::scriptBasename (static)
    ('script_basename',         'StringUtil::scriptBasename'),
    # get_languages -> Util::get()->getLanguages  (or ServiceLocator::get(Util::class)->getPwgLanguages)
    ('get_languages',           'Util::get()->getLanguages'),
    # load_conf_from_db -> ConfigService::loadConfFromDb (but needs pre-boot too)
    # For src/ callers it's post-boot, so use ServiceLocator
    ('load_conf_from_db',       'ServiceLocator::get(ConfigService::class)->loadConfFromDb'),
    # mkgetdir -> Util::get()->mkgetdir
    ('mkgetdir',                'Util::get()->mkgetdir'),
    # get_name_from_file_delegate -> ServiceLocator::get(StringUtil::class)->getNameFromFile
    ('get_name_from_file_delegate', 'ServiceLocator::get(StringUtil::class)->getNameFromFile'),
]

USES = {
    'HtmlService::': 'use Piwigo\\Html\\HtmlService;',
    'LangService::': 'use Piwigo\\Lang\\LangService;',
    'Util::get()':   'use Piwigo\\Core\\Util;',
    'StringUtil::':  'use Piwigo\\Core\\StringUtil;',
    'AppInfo::':     'use Piwigo\\Core\\AppInfo;',
    'ConfigService::': 'use Piwigo\\Config\\ConfigService;',
}
SL_USE = 'use Piwigo\\Core\\ServiceLocator;'


def split_strings(code):
    return re.split(r"""('(?:[^'\\]|\\.)*'|"(?:[^"\\]|\\.)*")""", code, flags=re.DOTALL)


def process(path):
    with open(path, 'r', encoding='utf-8') as f:
        content = f.read()
    orig = content
    parts = split_strings(content)
    for old, new in REPS:
        parts = [re.sub(r'(?<!\-\>)(?<!::)(?<!function\s)(?<!\bpublic\s)(?<!\bprivate\s)(?<!\bprotected\s)\b' + re.escape(old) + r'\b(?=\s*\()', new, p)
                 if i % 2 == 0 else p
                 for i, p in enumerate(parts)]
    content = ''.join(parts)
    if content == orig:
        return False

    # Add missing use statements
    needed = set()
    for prefix, use_stmt in USES.items():
        if prefix in content and use_stmt not in content and 'namespace ' in content:
            needed.add(use_stmt)
    if 'ServiceLocator::' in content and SL_USE not in content and 'namespace ' in content:
        needed.add(SL_USE)

    if needed:
        lines = content.split('\n')
        last = max((i for i, l in enumerate(lines) if re.match(r'^use [A-Z\\]', l)), default=-1)
        if last >= 0:
            insert = '\n'.join(sorted(needed))
            lines.insert(last + 1, insert)
            content = '\n'.join(lines)

    with open(path, 'w', encoding='utf-8') as f:
        f.write(content)
    return True


changed = 0
for dp, _, fnames in os.walk(BASE):
    if 'vendor' in dp.split(os.sep):
        continue
    for fn in fnames:
        if fn.endswith('.php') and fn not in SKIP:
            if process(os.path.join(dp, fn)):
                changed += 1
                print(f'Updated: {os.path.relpath(os.path.join(dp, fn), BASE)}')
print(f'\nDone. {changed} files updated.')
