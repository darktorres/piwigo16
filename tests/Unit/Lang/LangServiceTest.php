<?php

declare(strict_types=1);

use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Lang\LangService;
use Piwigo\Lang\Translator;
use Piwigo\Tests\Support\TranslatorTestFactory;

/**
 * Lang is a real, container-shared instance now (singleton/service-
 * locator elimination campaign, Phase 8) -- this file never boots
 * Kernel, so a throwaway instance is constructed directly instead of
 * resolving Lang::current(). A helper (rather than a `$this->lang`
 * built once in beforeEach) is used so the couple of tests below that
 * construct their own LangService against a different throwaway
 * $root/Paths can each get a properly-typed Lang for it too --
 * PHPStan can't narrow a Pest-bound `$this->...` property's type
 * across separate test closures. TranslatorTestFactory::get() is shared with this
 * Lang instance so loadArray()'s Translator-mirror side effect (see
 * Lang::loadArray()'s own docblock) lands in the same Translator
 * instance the file's own assertions (and LangService itself) read
 * from.
 */
function langServiceTestNewLang(Paths $paths): Lang
{
    return new Lang(TranslatorTestFactory::get(), HtmlServiceTestFactory::build(), $paths, new InstallationFlag());
}

beforeEach(function (): void {
    TranslatorTestFactory::get()->reset();
    $this->paths = Paths::fromRoot(dirname(__DIR__, 3));
    $this->lang = langServiceTestNewLang($this->paths);
    $this->service = new LangService($this->lang, $this->paths, TranslatorTestFactory::get());
});

afterEach(function (): void {
    TranslatorTestFactory::get()->reset();
});

test('t delegates to Lang::t', function (): void {
    $this->lang->loadArray(['greeting' => 'hi']);

    expect($this->service->t('greeting'))->toBe('hi');
});

test('t treats a null key as an empty string, matching l10n()s legacy contract', function (): void {
    expect($this->service->t(null))->toBe('');
});

test('l10n is an alias for t', function (): void {
    $this->lang->loadArray(['greeting' => 'hi']);

    expect($this->service->l10n('greeting'))->toBe($this->service->t('greeting'));
});

test('loadLanguageForPlugin rejects a locale not installed under language/', function (): void {
    expect($this->service->loadLanguageForPlugin(sys_get_temp_dir(), '../../../../etc'))->toBeFalse();
});

test('loadLanguageForPlugin rejects a locale with path traversal characters', function (): void {
    expect($this->service->loadLanguageForPlugin(sys_get_temp_dir(), '..'))->toBeFalse();
});

test('loadLanguageForPlugin returns false when the plugin has no PO file for a real installed locale', function (): void {
    expect($this->service->loadLanguageForPlugin(sys_get_temp_dir() . '/nonexistent-plugin-dir', 'en_UK'))->toBeFalse();
});

/**
 * Confirmed-equivalent: line 93's UnwrapRtrim (`$pluginDir . '/language/'`
 * instead of `rtrim($pluginDir, '/') . '/language/'`). A trailing slash
 * on $pluginDir left un-trimmed produces a doubled '//' where it meets
 * the literal '/language/' prefix -- both is_readable() and PHP's
 * underlying filesystem layer treat repeated path separators as
 * identical to a single one on this platform (live-verified directly:
 * is_readable() on both the rtrim'd and un-rtrim'd form of the exact
 * same real file returned true either way). Live sed-verified the
 * mutation itself against the full suite too.
 */
test('loadLanguageForPlugin rejects a syntactically-valid but not-actually-installed locale, never checking the plugin PO file', function (): void {
    // Kills line 90's RemoveEarlyReturn -- the sibling "rejects a locale
    // not installed" tests above use a path-traversal locale, which
    // still resolves to "false" even with the early return removed
    // (the resulting bogus po path is never readable either way, so
    // that mutation is unobservable there). 'zz_ZZ' matches the regex
    // shape exactly but has no real language/zz_ZZ/ directory in this
    // project -- a decoy PO file at the exact path the fallthrough
    // WOULD construct proves the early return is what stops it from
    // ever being reached.
    $pluginDir = sys_get_temp_dir() . '/piwigo-lang-service-test-earlyreturn';
    @mkdir($pluginDir . '/language/zz_ZZ', 0o777, true);
    file_put_contents($pluginDir . '/language/zz_ZZ/plugin.po', "msgid \"\"\nmsgstr \"\"\n");

    try {
        expect($this->service->loadLanguageForPlugin($pluginDir, 'zz_ZZ'))->toBeFalse();
    } finally {
        unlink($pluginDir . '/language/zz_ZZ/plugin.po');
        rmdir($pluginDir . '/language/zz_ZZ');
        rmdir($pluginDir . '/language');
        rmdir($pluginDir);
    }
});

test('loadLanguageForPlugin rejects a locale that fails the installed-locale format check, even when a matching directory and PO file genuinely exist', function (): void {
    // Kills line 106's FalseToTrue and RemoveEarlyReturn together.
    // '12x' fails the /^[a-z]{2,3}(_[A-Z]{2})?$/ format check (leading
    // digit) -- real code rejects it on that basis alone, never
    // consulting the filesystem at all. A private throwaway root (not
    // the shared project one) with a REAL language/12x/ directory and a
    // REAL matching PO file means: if the format guard is bypassed
    // (either mutation), BOTH of isInstalledLocale()'s own remaining
    // checks (the fallthrough is_dir(), or the my-format-check-doesn't-
    // matter FalseToTrue short-circuit) would let this same locale
    // through undetected by directory presence alone -- only the format
    // check itself distinguishes real code from either mutant here.
    $root = sys_get_temp_dir() . '/piwigo-lang-service-test-format-' . bin2hex(random_bytes(8));
    mkdir($root . '/language/12x', 0o777, true);
    $service = new LangService(langServiceTestNewLang(Paths::fromRoot($root)), Paths::fromRoot($root), TranslatorTestFactory::get());

    $pluginDir = sys_get_temp_dir() . '/piwigo-lang-service-test-format-plugin';
    @mkdir($pluginDir . '/language/12x', 0o777, true);
    file_put_contents($pluginDir . '/language/12x/plugin.po', "msgid \"\"\nmsgstr \"\"\n");

    try {
        expect($service->loadLanguageForPlugin($pluginDir, '12x'))->toBeFalse();
    } finally {
        unlink($pluginDir . '/language/12x/plugin.po');
        rmdir($pluginDir . '/language/12x');
        rmdir($pluginDir . '/language');
        rmdir($pluginDir);
        rmdir($root . '/language/12x');
        rmdir($root . '/language');
        rmdir($root);
    }
});

test('loadLanguageForPlugin checks the installed-locale directory under this service\'s own Paths root, not the working directory', function (): void {
    // Kills line 109's ConcatRemoveLeft (`is_dir('language/' . $locale)`
    // instead of prefixing $this->paths->root). A locale genuinely
    // installed only under a private throwaway root -- never under the
    // real process working directory's own language/ tree -- only
    // resolves as installed if the root prefix is genuinely applied.
    $root = sys_get_temp_dir() . '/piwigo-lang-service-test-root-' . bin2hex(random_bytes(8));
    mkdir($root . '/language/xx_YY', 0o777, true);
    $service = new LangService(langServiceTestNewLang(Paths::fromRoot($root)), Paths::fromRoot($root), TranslatorTestFactory::get());

    $pluginDir = sys_get_temp_dir() . '/piwigo-lang-service-test-root-plugin';
    @mkdir($pluginDir . '/language/xx_YY', 0o777, true);
    file_put_contents($pluginDir . '/language/xx_YY/plugin.po', "msgid \"\"\nmsgstr \"\"\n");

    try {
        expect($service->loadLanguageForPlugin($pluginDir, 'xx_YY'))->toBeTrue();
    } finally {
        unlink($pluginDir . '/language/xx_YY/plugin.po');
        rmdir($pluginDir . '/language/xx_YY');
        rmdir($pluginDir . '/language');
        rmdir($pluginDir);
        rmdir($root . '/language/xx_YY');
        rmdir($root . '/language');
        rmdir($root);
    }
});

test('loadLanguageForPlugin checks the exact locale subdirectory, not just whether the base language/ directory exists', function (): void {
    // Kills line 109's ConcatRemoveRight (`is_dir($this->paths->root .
    // 'language/')` instead of appending $locale) -- a root whose base
    // language/ directory genuinely exists, but with no ww_ZZ
    // subdirectory inside it, only returns false for real code; the
    // mutant's base-dir-only check would find the base dir regardless
    // and wrongly treat ww_ZZ as installed.
    $root = sys_get_temp_dir() . '/piwigo-lang-service-test-subdir-' . bin2hex(random_bytes(8));
    mkdir($root . '/language', 0o777, true);
    $service = new LangService(langServiceTestNewLang(Paths::fromRoot($root)), Paths::fromRoot($root), TranslatorTestFactory::get());

    $pluginDir = sys_get_temp_dir() . '/piwigo-lang-service-test-subdir-plugin';
    @mkdir($pluginDir . '/language/ww_ZZ', 0o777, true);
    file_put_contents($pluginDir . '/language/ww_ZZ/plugin.po', "msgid \"\"\nmsgstr \"\"\n");

    try {
        expect($service->loadLanguageForPlugin($pluginDir, 'ww_ZZ'))->toBeFalse();
    } finally {
        unlink($pluginDir . '/language/ww_ZZ/plugin.po');
        rmdir($pluginDir . '/language/ww_ZZ');
        rmdir($pluginDir . '/language');
        rmdir($pluginDir);
        rmdir($root . '/language');
        rmdir($root);
    }
});

test('loadLanguageForPlugin loads a real PO file for a real installed locale', function (): void {
    $pluginDir = sys_get_temp_dir() . '/piwigo-lang-service-test-plugin';
    @mkdir($pluginDir . '/language/en_UK', 0o777, true);
    file_put_contents($pluginDir . '/language/en_UK/plugin.po', <<<'PO'
        msgid ""
        msgstr ""
        "Plural-Forms: nplurals=2; plural=(n != 1);\n"

        msgid "plugin_greeting"
        msgstr "plugin hi"
        PO);

    try {
        expect($this->service->loadLanguageForPlugin($pluginDir, 'en_UK'))->toBeTrue()
            ->and(TranslatorTestFactory::get()->translate('plugin_greeting'))->toBe('plugin hi');
    } finally {
        unlink($pluginDir . '/language/en_UK/plugin.po');
        rmdir($pluginDir . '/language/en_UK');
        rmdir($pluginDir . '/language');
        rmdir($pluginDir);
    }
});
