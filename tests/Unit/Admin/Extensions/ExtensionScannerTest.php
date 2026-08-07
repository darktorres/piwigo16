<?php

declare(strict_types=1);

use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Config\ConfigLoader;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Core\AppInfo;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Url\RootPathOverride;
use Piwigo\Users\User;

// ExtensionType::scanDirectory() hardcodes real app paths (PluginLoader::pluginsPath()/
// CurrentConfig::themesPath()/CurrentPathsTestFactory::get()->root.'language/') with no
// injection point, so this can't safely redirect to a disposable temp
// directory the way ZipExtractorTest/UploadServiceTest do. Scanning the
// real, git-tracked language/ tree read-only is safe and deterministic
// (unlike themes/plugins, bundled language directories are stable source
// content, not environment-dependent) -- covers the real
// common.po-vs-common.lang.php marker-file bug this batch fixed (see
// ExtensionType::markerFilename()'s own docblock). Plugin/theme scanning
// is exercised end-to-end by the Browser admin smoke suite against
// whatever's actually installed, not re-duplicated here. CurrentPaths is
// seeded against this repo's own real root (not a disposable temp dir) so
// the real language/ tree is genuinely reachable.
beforeEach(function (): void {
    CurrentConfigTestFactory::get()->reset();
    ConfigLoader::applyDefaults();
    Kernel::boot(Paths::fromRoot(dirname(__DIR__, 4)));
});

afterEach(function (): void {
    CurrentConfigTestFactory::get()->reset();
    // LangTestFactory::get() (unlike CurrentUserTestFactory::get()) has no memoized
    // pre-boot fallback -- see its own docblock -- so it must resolve
    // (and get reset) while the container is still up, before
    // Kernel::reset() tears it down.
    LangTestFactory::get()->reset();
    Kernel::reset();
    CurrentUserTestFactory::get()->reset();
});

test('scan finds the real bundled en_UK language via its common.po header', function (): void {
    $found = new ExtensionScanner()->scan(ExtensionType::Language, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get(), 'utf-8');

    expect($found)->toHaveKey('en_UK')
        ->and($found['en_UK']['name'])->toBe('English (Great Britain)')
        ->and($found['en_UK']['code'])->toBe('en_UK')
        ->and($found['en_UK']['version'])->not->toBe('0');
});

test('scan skips a language directory with no common.po', function (): void {
    $found = new ExtensionScanner()->scan(ExtensionType::Language, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get(), 'utf-8');

    // index.php sits alongside the real locale directories under language/
    // but isn't itself an extension -- also fails the [a-zA-Z0-9-_]+ id
    // regex to begin with (has a dot), confirming both guards hold.
    expect($found)->not->toHaveKey('index.php');
});

// ---------------------------------------------------------------------
// Below: real coverage-gap closure for scan()/scanPlugin()/scanTheme()/
// scanLanguage()'s remaining untested branches (guard-failure returns,
// the Lang::load() description success path, the webmaster-gated
// hasSettings flag, and every optional theme header field). Unlike the
// two tests above, these don't touch the real git-tracked plugins/
// themes/language trees at all -- Paths::fromRoot() (unlike
// ExtensionType::scanDirectory()'s own hardcoded composition) genuinely
// accepts *any* root directory, so a disposable temp root is a real,
// safe injection point here (CurrentConfig::setThemesDir() likewise
// accepts an absolute override -- same technique
// ExtensionUpdateCheckerTest/ThemesInstalledPageRendererTest/
// InstallServiceTest already rely on). scanTheme()'s only real DB
// dependency (PreferencesService, when a theme has no screenshot.png)
// is deliberately sidestepped below by always providing a screenshot.png
// fixture file, keeping this file's own "Unit-testable without a full
// app bootstrap" docblock claim genuinely true for every test in it.

function extensionScannerFixtureRoot(): string
{
    $root = sys_get_temp_dir() . '/piwigo-extension-scanner-test-' . bin2hex(random_bytes(4)) . '/';
    mkdir($root, 0o777, true);
    mkdir($root . 'plugins', 0o777, true);
    mkdir($root . 'themes', 0o777, true);
    mkdir($root . 'language', 0o777, true);
    // Kernel is already booted (beforeEach()'s own default real-repo-root
    // boot) by the time any test calls this -- a bare Kernel::boot() here
    // would silently no-op against its own idempotency guard, leaving
    // Paths::class pointed at the wrong (real repo) root instead of this
    // fixture's own throwaway one. Reset first so the new root actually
    // takes.
    Kernel::reset();
    Kernel::boot(Paths::fromRoot($root));
    CurrentConfigTestFactory::get()->setThemesDir(rtrim($root, '/') . '/themes');

    return $root;
}

function extensionScannerFixturePlugin(string $root, string $id): void
{
    $dir = $root . 'plugins/' . $id;
    mkdir($dir, 0o777, true);
    file_put_contents($dir . '/main.inc.php', <<<PHP
        <?php
        /*
        Plugin Name: Full Fixture Plugin
        Version: 2.3.4
        Plugin URI: https://example.com/extension_view.php?eid=777
        Author: Fixture Author
        Author URI: https://example.com/author
        Has Settings: webmaster
        */
        PHP);
    // Lang::load('description.txt', $path.'/', ['return' => true]) appends
    // its own 'language/' + AppInfo::DEFAULT_LANGUAGE ('en_UK', since no
    // DefaultLanguageProvider is installed in this bare Unit test) --
    // see Lang::load()'s own $dirname/$languages composition.
    mkdir($dir . '/language/en_UK', 0o777, true);
    file_put_contents($dir . '/language/en_UK/description.txt', 'A fixture plugin description for coverage.');
}

function extensionScannerFixtureTheme(string $root, string $id): void
{
    $dir = $root . 'themes/' . $id;
    mkdir($dir . '/admin', 0o777, true);
    file_put_contents($dir . '/themeconf.inc.php', <<<PHP
        <?php
        /*
        Theme Name: Full Fixture Theme
        Version: 3.1.4
        Theme URI: https://example.com/extension_view.php?eid=999
        Author: Fixture Theme Author
        Author URI: https://example.com/theme-author
        */
        \$theme_conf = array(
            'parent' => 'parent_theme_id',
            'activable' => true,
            'mobile' => true,
            'use_standard_pages' => true,
        );
        PHP);
    mkdir($dir . '/language/en_UK', 0o777, true);
    file_put_contents($dir . '/language/en_UK/description.txt', 'A fixture theme description for coverage.');
    // Presence is all scanTheme() checks (file_exists()) -- providing this
    // is what keeps this test off the PreferencesService/DB fallback
    // branch (see this section's own header comment).
    file_put_contents($dir . '/screenshot.png', 'not a real png -- only its existence is checked');
    file_put_contents($dir . '/admin/admin.inc.php', '<?php // fixture admin page, only its existence matters');
}

test('scan returns an empty array when the scan directory itself does not exist', function (): void {
    $root = extensionScannerFixtureRoot();
    try {
        // Deliberately remove the language/ dir the helper just created:
        // opendir() against a missing directory emits a real E_WARNING (not
        // just a clean false return), which this project's
        // phpunit.xml.dist (failOnWarning="true") would otherwise turn
        // into a test failure -- captured and discarded here (same
        // technique as ZipExtractorTest's own set_error_handler() use) to
        // exercise scan()'s own `$dir === false` guard instead of
        // tripping the harness.
        rmdir($root . 'language');

        set_error_handler(static fn (): bool => true, E_WARNING);
        try {
            $found = new ExtensionScanner()->scan(ExtensionType::Language, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get(), 'utf-8');
        } finally {
            restore_error_handler();
        }

        expect($found)->toBe([]);
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan skips a plugin directory with no main.inc.php', function (): void {
    $root = extensionScannerFixtureRoot();
    try {
        mkdir($root . 'plugins/no_main_plugin', 0o777, true);

        $found = new ExtensionScanner()->scan(ExtensionType::Plugin, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());

        expect($found)->not->toHaveKey('no_main_plugin');
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan trims trailing whitespace from every regex-captured plugin header value', function (): void {
    // Real gap, found via mutation testing: the "full fixture plugin"
    // header block has no trailing whitespace on any line, so trim()
    // never actually had anything to strip -- these patterns' `.+`
    // capture is greedy up to the newline, so trailing spaces before it
    // land in the captured group and only trim() removes them.
    $root = extensionScannerFixtureRoot();
    try {
        $dir = $root . 'plugins/padded_header_plugin';
        mkdir($dir, 0o777, true);
        // Trailing tabs (not spaces) after each value -- unambiguous and
        // won't get silently stripped by an editor/tool trimming trailing
        // whitespace the way a trailing space could.
        file_put_contents($dir . '/main.inc.php', "<?php\n/*\n"
            . "Plugin Name: Padded Plugin\t\n"
            . "Version: 1.2.3\t\n"
            . "Plugin URI: https://example.com/padded\t\n"
            . "Description: Padded description\t\n"
            . "Author: Padded Author\t\n"
            . "Author URI: https://example.com/padded-author\t\n"
            . "*/\n");

        $found = new ExtensionScanner()->scan(ExtensionType::Plugin, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());

        expect($found)->toHaveKey('padded_header_plugin');
        $plugin = $found['padded_header_plugin'];
        expect($plugin['name'])->toBe('Padded Plugin')
            ->and($plugin['version'])->toBe('1.2.3')
            ->and($plugin['uri'])->toBe('https://example.com/padded')
            ->and($plugin['description'])->toBe('Padded description')
            ->and($plugin['author'])->toBe('Padded Author')
            ->and($plugin['author uri'])->toBe('https://example.com/padded-author');
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan skips a directory entry with an invalid id but keeps scanning the rest', function (): void {
    // Real gap, found via mutation testing: a directory containing only
    // '.'/'..' plus valid-id entries never actually reaches the
    // regex-mismatch branch at all (both are caught by the earlier '.'/
    // '..' guard), so `continue` vs `break` there was never exercised.
    // An entry with a dot in its name fails the [a-zA-Z0-9-_]+ id regex
    // (invalid) placed alphabetically *before* a real, valid plugin id
    // forces `continue` to matter: `break` would stop scanning right
    // there and never reach the valid one afterward.
    $root = extensionScannerFixtureRoot();
    try {
        mkdir($root . 'plugins/a.invalid.name', 0o777, true);
        mkdir($root . 'plugins/zzz_valid_plugin', 0o777, true);
        file_put_contents($root . 'plugins/zzz_valid_plugin/main.inc.php', "<?php\n/*\nPlugin Name: Valid\n*/\n");

        $found = new ExtensionScanner()->scan(ExtensionType::Plugin, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());

        expect($found)->not->toHaveKey('a.invalid.name')
            ->and($found)->toHaveKey('zzz_valid_plugin');
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan defaults name/version/uri/description/author for a plugin whose main.inc.php has no matching headers', function (): void {
    // Real gap, found via mutation testing: every other plugin test uses
    // a fully-populated header block, so nothing ever exercised the
    // initial $plugin = [...] default values themselves (name falls back
    // to the directory id, version to '0', the rest to '').
    $root = extensionScannerFixtureRoot();
    try {
        mkdir($root . 'plugins/headerless_plugin', 0o777, true);
        file_put_contents($root . 'plugins/headerless_plugin/main.inc.php', "<?php\n// no header block at all\n");

        $found = new ExtensionScanner()->scan(ExtensionType::Plugin, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());

        expect($found)->toHaveKey('headerless_plugin');
        $plugin = $found['headerless_plugin'];
        expect($plugin['name'])->toBe('headerless_plugin')
            ->and($plugin['version'])->toBe('0')
            ->and($plugin['uri'])->toBe('')
            ->and($plugin['description'])->toBe('')
            ->and($plugin['author'])->toBe('')
            ->and($plugin['hasSettings'])->toBeFalse()
            ->and($plugin)->not->toHaveKey('author uri')
            ->and($plugin)->not->toHaveKey('extension');
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan skips a plugin whose main.inc.php cannot be read', function (): void {
    $root = extensionScannerFixtureRoot();
    $mainFile = $root . 'plugins/unreadable_plugin/main.inc.php';
    try {
        mkdir($root . 'plugins/unreadable_plugin', 0o777, true);
        file_put_contents($mainFile, "<?php\n/*\nPlugin Name: Unreadable\n*/\n");
        // A real, unreadable file (0000, torres owns it but no read bit
        // for anyone) -- file_get_contents() genuinely returns false here,
        // not a mock, matching UploadServiceTest::sanitizeSvgIfNeeded's
        // own established permission-denied convention (torres is a
        // non-root user in this environment, confirmed via `id`, so
        // owning a file does not bypass its own permission bits). A
        // directory standing in for main.inc.php was tried first and
        // rejected: file_get_contents() against a directory returns ''
        // (an E_NOTICE, not E_WARNING) on this PHP version, not false --
        // it would silently fall through to a defaulted, non-null return
        // instead of exercising this guard at all, confirmed via direct
        // `php -r` experimentation before writing this test.
        // file_exists() (scanPlugin()'s own earlier guard) stays true
        // regardless of the chmod -- only the later read fails -- so this
        // genuinely exercises scanPlugin()'s own `$data === false` guard,
        // not the guard above it.
        chmod($mainFile, 0o000);

        set_error_handler(static fn (): bool => true, E_WARNING);
        try {
            $found = new ExtensionScanner()->scan(ExtensionType::Plugin, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());
        } finally {
            restore_error_handler();
            chmod($mainFile, 0o644);
        }

        expect($found)->not->toHaveKey('unreadable_plugin');
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan reports hasSettings=true for a webmaster-gated plugin when the current user is a webmaster', function (): void {
    $root = extensionScannerFixtureRoot();
    try {
        extensionScannerFixturePlugin($root, 'webmaster_gated_plugin');
        CurrentUserTestFactory::get()->set(User::fromUserArray(['id' => 1, 'status' => 'webmaster']));

        $found = new ExtensionScanner()->scan(ExtensionType::Plugin, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());

        expect($found)->toHaveKey('webmaster_gated_plugin')
            ->and($found['webmaster_gated_plugin']['hasSettings'])->toBeTrue()
            ->and($found['webmaster_gated_plugin']['name'])->toBe('Full Fixture Plugin')
            ->and($found['webmaster_gated_plugin']['version'])->toBe('2.3.4')
            ->and($found['webmaster_gated_plugin']['uri'])->toBe('https://example.com/extension_view.php?eid=777')
            ->and($found['webmaster_gated_plugin']['extension'])->toBe('777')
            ->and($found['webmaster_gated_plugin']['author'])->toBe('Fixture Author')
            ->and($found['webmaster_gated_plugin']['description'])->toBe('A fixture plugin description for coverage.')
            ->and($found['webmaster_gated_plugin']['author uri'])->toBe('https://example.com/author');
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan reports hasSettings=false for a webmaster-gated plugin when the current user is not a webmaster', function (): void {
    $root = extensionScannerFixtureRoot();
    try {
        extensionScannerFixturePlugin($root, 'webmaster_gated_plugin_normal_user');
        CurrentUserTestFactory::get()->set(User::fromUserArray(['id' => 2, 'status' => 'normal']));

        $found = new ExtensionScanner()->scan(ExtensionType::Plugin, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());

        // Real security-relevant behaviour, not just a line-coverage
        // formality: a non-webmaster user must never see hasSettings=true
        // for a plugin whose "Has Settings: webmaster" header gates its
        // settings page behind webmaster status.
        expect($found)->toHaveKey('webmaster_gated_plugin_normal_user')
            ->and($found['webmaster_gated_plugin_normal_user']['hasSettings'])->toBeFalse();
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan trims trailing whitespace from every regex-captured theme header value', function (): void {
    $root = extensionScannerFixtureRoot();
    try {
        $dir = $root . 'themes/padded_header_theme';
        mkdir($dir . '/admin', 0o777, true);
        file_put_contents($dir . '/themeconf.inc.php', "<?php\n/*\n"
            . "Theme Name: Padded Theme\t\n"
            . "Version: 4.5.6\t\n"
            . "Theme URI: https://example.com/padded-theme\t\n"
            . "Description: Padded theme description\t\n"
            . "Author: Padded Theme Author\t\n"
            . "Author URI: https://example.com/padded-theme-author\t\n"
            . "*/\n");
        file_put_contents($dir . '/screenshot.png', 'fixture');

        $found = new ExtensionScanner()->scan(ExtensionType::Theme, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());

        expect($found)->toHaveKey('padded_header_theme');
        $theme = $found['padded_header_theme'];
        expect($theme['name'])->toBe('Padded Theme')
            ->and($theme['version'])->toBe('4.5.6')
            ->and($theme['uri'])->toBe('https://example.com/padded-theme')
            ->and($theme['description'])->toBe('Padded theme description')
            ->and($theme['author'])->toBe('Padded Theme Author')
            ->and($theme['author uri'])->toBe('https://example.com/padded-theme-author');
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan escapes special HTML characters in every string field it returns', function (): void {
    // "IMPORTANT SECURITY!" pass (scanPlugin()'s/scanTheme()'s/
    // scanLanguage()'s own htmlspecialchars() call) -- never actually
    // exercised with a value containing anything to escape.
    $root = extensionScannerFixtureRoot();
    try {
        $dir = $root . 'plugins/xss_plugin';
        mkdir($dir, 0o777, true);
        file_put_contents($dir . '/main.inc.php', "<?php\n/*\n"
            . "Plugin Name: <script>alert(1)</script> & \"Quoted\"\n"
            . "*/\n");

        $found = new ExtensionScanner()->scan(ExtensionType::Plugin, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());

        expect($found)->toHaveKey('xss_plugin')
            ->and($found['xss_plugin']['name'])->toBe('&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;Quoted&quot;');
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan defaults id/name/version/uri/description/author for a theme whose themeconf.inc.php has no matching headers', function (): void {
    $root = extensionScannerFixtureRoot();
    try {
        mkdir($root . 'themes/headerless_theme/admin', 0o777, true);
        file_put_contents($root . 'themes/headerless_theme/themeconf.inc.php', "<?php\n// no header block at all\n");
        file_put_contents($root . 'themes/headerless_theme/screenshot.png', 'fixture');

        $found = new ExtensionScanner()->scan(ExtensionType::Theme, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());

        expect($found)->toHaveKey('headerless_theme');
        $theme = $found['headerless_theme'];
        expect($theme['id'])->toBe('headerless_theme')
            ->and($theme['name'])->toBe('headerless_theme')
            ->and($theme['version'])->toBe('0')
            ->and($theme['uri'])->toBe('')
            ->and($theme['description'])->toBe('')
            ->and($theme['author'])->toBe('')
            ->and($theme['mobile'])->toBeFalse()
            ->and($theme)->not->toHaveKey('author uri')
            ->and($theme)->not->toHaveKey('extension')
            ->and($theme)->not->toHaveKey('parent')
            ->and($theme)->not->toHaveKey('activable')
            ->and($theme)->not->toHaveKey('use_standard_pages');
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan skips a theme whose themeconf.inc.php cannot be read', function (): void {
    $root = extensionScannerFixtureRoot();
    $themeConfFile = $root . 'themes/unreadable_theme/themeconf.inc.php';
    try {
        mkdir($root . 'themes/unreadable_theme', 0o777, true);
        file_put_contents($themeConfFile, "<?php\n/*\nTheme Name: Unreadable\n*/\n");
        // Same real chmod(0000)-unreadable-file technique as the plugin
        // main.inc.php test above (see its own comment for why a
        // directory standing in for the file doesn't work), for
        // scanTheme()'s own `$lines === false` guard.
        chmod($themeConfFile, 0o000);

        set_error_handler(static fn (): bool => true, E_WARNING);
        try {
            $found = new ExtensionScanner()->scan(ExtensionType::Theme, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());
        } finally {
            restore_error_handler();
            chmod($themeConfFile, 0o644);
        }

        expect($found)->not->toHaveKey('unreadable_theme');
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan extracts every optional theme header field from a fully-populated theme', function (): void {
    $root = extensionScannerFixtureRoot();
    try {
        extensionScannerFixtureTheme($root, 'full_fixture_theme');

        $found = new ExtensionScanner()->scan(ExtensionType::Theme, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());

        expect($found)->toHaveKey('full_fixture_theme');
        $theme = $found['full_fixture_theme'];
        expect($theme['name'])->toBe('Full Fixture Theme')
            ->and($theme['version'])->toBe('3.1.4')
            ->and($theme['author'])->toBe('Fixture Theme Author')
            ->and($theme['uri'])->toBe('https://example.com/extension_view.php?eid=999')
            ->and($theme['description'])->toBe('A fixture theme description for coverage.')
            ->and($theme['author uri'])->toBe('https://example.com/theme-author')
            // extractExtensionId() parses the eid straight out of the URI
            // above -- real end-to-end behaviour, not a separately-mocked
            // value.
            ->and($theme['extension'])->toBe('999')
            ->and($theme['parent'])->toBe('parent_theme_id')
            ->and($theme['activable'])->toBeTrue()
            ->and($theme['mobile'])->toBeTrue()
            ->and($theme['use_standard_pages'])->toBeTrue()
            // Real string, not a bool -- htmlspecialchars() escaping (the
            // "IMPORTANT SECURITY!" pass) turns '&' into '&amp;', so this
            // deliberately checks for a substring rather than an exact
            // scheme-and-host string that would depend on the untouched
            // request-scoped RootPathOverride/SectionContextRegistry
            // state this bare Unit test never seeds.
            ->and($theme['admin_uri'])->toContain('admin.php?page=theme&amp;theme=full_fixture_theme')
            ->and($theme['screenshot'])->toBe($root . 'themes/full_fixture_theme/screenshot.png');
    } finally {
        FilesystemHelper::deltree($root);
    }
});

function extensionScannerExtractExtensionId(string $uri): ?string
{
    $method = new ReflectionMethod(ExtensionScanner::class, 'extractExtensionId');
    $result = $method->invoke(new ExtensionScanner(), $uri);

    return is_string($result) ? $result : null;
}

test('extractExtensionId returns null for an empty uri', function (): void {
    expect(extensionScannerExtractExtensionId(''))->toBeNull();
});

test('extractExtensionId returns null for a uri with no eid marker', function (): void {
    expect(extensionScannerExtractExtensionId('https://example.com/'))->toBeNull();
});

test('extractExtensionId returns null for a non-numeric eid', function (): void {
    expect(extensionScannerExtractExtensionId('https://example.com/extension_view.php?eid=not-a-number'))->toBeNull();
});

test('extractExtensionId returns the eid for a real, numeric extension_view.php uri', function (): void {
    expect(extensionScannerExtractExtensionId('https://example.com/extension_view.php?eid=42'))->toBe('42');
});

test('scan skips a language whose common.po cannot be read', function (): void {
    $root = extensionScannerFixtureRoot();
    $poFile = $root . 'language/unreadable_lang/common.po';
    try {
        mkdir($root . 'language/unreadable_lang', 0o777, true);
        file_put_contents($poFile, "msgid \"\"\nmsgstr \"\"\n");
        // Same real chmod(0000)-unreadable-file technique as the plugin/
        // theme tests above, for scanLanguage()'s own `$lines === false`
        // guard.
        chmod($poFile, 0o000);

        set_error_handler(static fn (): bool => true, E_WARNING);
        try {
            $found = new ExtensionScanner()->scan(ExtensionType::Language, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get(), 'utf-8');
        } finally {
            restore_error_handler();
            chmod($poFile, 0o644);
        }

        expect($found)->not->toHaveKey('unreadable_lang');
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan does not append an empty, whitespace-only X-Piwigo-Country to the language name', function (): void {
    // Real gap, found via mutation testing: the real bundled en_UK fixture
    // (see the very first test in this file) always has a genuinely
    // non-empty country ("Great Britain"), so it can't tell a real
    // trim()-then-empty-check from a removed one -- a header value that's
    // whitespace only exercises both: without trim(), "   " !== '' would
    // wrongly append "(   )"; without the empty check, it would append
    // "()" -- only the real, correct behaviour skips it entirely.
    $root = extensionScannerFixtureRoot();
    $poFile = $root . 'language/blank_country_lang/common.po';
    try {
        mkdir($root . 'language/blank_country_lang', 0o777, true);
        file_put_contents($poFile, <<<PO
            msgid ""
            msgstr ""
            "X-Piwigo-Language-Name: Blank Country Language\\n"
            "X-Piwigo-Country:    \\n"

            PO);

        $found = new ExtensionScanner()->scan(ExtensionType::Language, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get(), 'utf-8');

        expect($found)->toHaveKey('blank_country_lang')
            ->and($found['blank_country_lang']['name'])->toBe('Blank Country Language');
    } finally {
        FilesystemHelper::deltree($root);
    }
});

// ---------------------------------------------------------------------
// Below: second mutation-testing pass. Same fixture-root/no-DB-bootstrap
// technique as the section above.

test('scan sorts languages by name via nameCompare, not raw directory listing order', function (): void {
    // Real gap, found via mutation testing: `if ($type === ExtensionType::Language)`
    // gates the uasort()/nameCompare() call -- neither of this file's
    // existing language tests scans more than one language whose name
    // ordering could differ from its directory-listing order, so nothing
    // ever told "sorted by name" from "left in whatever order readdir()
    // happened to return". Directory ids are deliberately the *opposite*
    // alphabetical order from their own "X-Piwigo-Language-Name" values,
    // so an unsorted result can't coincidentally match this test's
    // expected order.
    $root = extensionScannerFixtureRoot();
    try {
        mkdir($root . 'language/mmm_dir', 0o777, true);
        file_put_contents($root . 'language/mmm_dir/common.po', <<<PO
            msgid ""
            msgstr ""
            "X-Piwigo-Language-Name: Bravo Language\\n"

            PO);
        mkdir($root . 'language/aaa_dir', 0o777, true);
        file_put_contents($root . 'language/aaa_dir/common.po', <<<PO
            msgid ""
            msgstr ""
            "X-Piwigo-Language-Name: Charlie Language\\n"

            PO);
        mkdir($root . 'language/zzz_dir', 0o777, true);
        file_put_contents($root . 'language/zzz_dir/common.po', <<<PO
            msgid ""
            msgstr ""
            "X-Piwigo-Language-Name: Alpha Language\\n"

            PO);

        $found = new ExtensionScanner()->scan(ExtensionType::Language, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get(), 'utf-8');

        expect(array_keys($found))->toBe(['zzz_dir', 'mmm_dir', 'aaa_dir']);
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan rejects a plugin directory that is a symlink even when its target is a real directory', function (): void {
    // Real gap, found via mutation testing: `!is_dir($path) || is_link($path) || ...`
    // -- a symlink pointing at a real directory satisfies is_dir() too (it
    // follows the link), so the is_link() rejection has to be checked
    // independently of is_dir(), not gated behind it.
    $root = extensionScannerFixtureRoot();
    $linkPath = $root . 'plugins/symlinked_plugin';
    try {
        $targetDir = $root . 'plugins/real_target_plugin';
        mkdir($targetDir, 0o777, true);
        file_put_contents($targetDir . '/main.inc.php', "<?php\n/*\nPlugin Name: Real Target\n*/\n");
        symlink($targetDir, $linkPath);

        $found = new ExtensionScanner()->scan(ExtensionType::Plugin, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());

        expect($found)->not->toHaveKey('symlinked_plugin')
            ->and($found)->toHaveKey('real_target_plugin');
    } finally {
        // Removed before deltree() walks it -- FilesystemHelper::deltree()
        // follows is_dir() (which a directory symlink satisfies) and then
        // calls plain rmdir() on it, which fails (and warns) for a
        // symlink on Linux.
        @unlink($linkPath);
        FilesystemHelper::deltree($root);
    }
});

test('scan only reads the first 2048 bytes of a plugin main.inc.php header block', function (): void {
    // Real gap, found via mutation testing: file_get_contents()'s own
    // offset (0) and length (2048) arguments -- every other plugin
    // fixture's header block sits well within that window, so nothing
    // ever told the exact (offset=0, length=2048) read from an
    // off-by-one on either argument. Pads the file so a 10-char value
    // straddles byte 2047/2048: only the real, exact window captures
    // precisely the first 8 of its characters.
    $root = extensionScannerFixtureRoot();
    try {
        $dir = $root . 'plugins/byte_boundary_plugin';
        mkdir($dir, 0o777, true);
        $prefix = "<?php\n/*\n";
        $label = 'Plugin Name: ';
        $valueStart = 2040;
        $padding = str_repeat('X', $valueStart - strlen($prefix) - strlen($label));
        $value = '0123456789';
        file_put_contents($dir . '/main.inc.php', $prefix . $padding . $label . $value . "\n*/\n");

        $found = new ExtensionScanner()->scan(ExtensionType::Plugin, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());

        expect($found)->toHaveKey('byte_boundary_plugin')
            ->and($found['byte_boundary_plugin']['name'])->toBe('01234567');
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan gates hasSettings on a case-insensitive "webmaster" marker, not just the lowercase literal', function (): void {
    // Real gap, found via mutation testing: strtolower($val[1]) === 'webmaster'
    // -- every existing webmaster-gated fixture already writes the header
    // in lowercase ("Has Settings: webmaster"), which can't tell a real
    // strtolower() from a removed one. The regex's own [Ww]ebmaster class
    // allows a capitalized "Webmaster" too; only a case-insensitive
    // comparison correctly keeps it gated for a non-webmaster user.
    $root = extensionScannerFixtureRoot();
    try {
        $dir = $root . 'plugins/capitalized_webmaster_plugin';
        mkdir($dir, 0o777, true);
        file_put_contents($dir . '/main.inc.php', "<?php\n/*\nPlugin Name: Capitalized\nHas Settings: Webmaster\n*/\n");
        CurrentUserTestFactory::get()->set(User::fromUserArray(['id' => 3, 'status' => 'normal']));

        $found = new ExtensionScanner()->scan(ExtensionType::Plugin, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());

        expect($found)->toHaveKey('capitalized_webmaster_plugin')
            ->and($found['capitalized_webmaster_plugin']['hasSettings'])->toBeFalse();
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan skips a theme directory with no themeconf.inc.php', function (): void {
    // Real gap, found via mutation testing: `!is_dir($path) || !file_exists(...)`
    // -- unlike the equivalent plugin test, nothing exercised a theme
    // directory that exists but is missing its own marker file.
    $root = extensionScannerFixtureRoot();
    try {
        mkdir($root . 'themes/no_conf_theme', 0o777, true);

        $found = new ExtensionScanner()->scan(ExtensionType::Theme, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());

        expect($found)->not->toHaveKey('no_conf_theme');
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan falls back to the header Description when Lang::load() returns an empty plugin description.txt', function (): void {
    // Real gap, found via mutation testing: is_string($desc) && $desc !== ''
    // -- every plugin fixture with a description.txt writes real,
    // non-empty content, so nothing ever exercised the empty-string case
    // (an empty description.txt genuinely returns '' from Lang::load(),
    // not false -- it's a plain file_get_contents() read under the
    // 'return' option).
    $root = extensionScannerFixtureRoot();
    try {
        $dir = $root . 'plugins/empty_desc_plugin';
        mkdir($dir, 0o777, true);
        file_put_contents($dir . '/main.inc.php', "<?php\n/*\nPlugin Name: Empty Desc\nDescription: Header fallback description\n*/\n");
        mkdir($dir . '/language/en_UK', 0o777, true);
        file_put_contents($dir . '/language/en_UK/description.txt', '');

        $found = new ExtensionScanner()->scan(ExtensionType::Plugin, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());

        expect($found)->toHaveKey('empty_desc_plugin')
            ->and($found['empty_desc_plugin']['description'])->toBe('Header fallback description');
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan trims whitespace from a lang-loaded plugin description', function (): void {
    // Real gap, found via mutation testing: trim($desc) -- the shared
    // extensionScannerFixturePlugin() helper's description.txt content has
    // no surrounding whitespace, so trim() never had anything to strip.
    $root = extensionScannerFixtureRoot();
    try {
        $dir = $root . 'plugins/padded_desc_plugin';
        mkdir($dir, 0o777, true);
        file_put_contents($dir . '/main.inc.php', "<?php\n/*\nPlugin Name: Padded Desc\n*/\n");
        mkdir($dir . '/language/en_UK', 0o777, true);
        file_put_contents($dir . '/language/en_UK/description.txt', "  Padded lang-loaded description  \n");

        $found = new ExtensionScanner()->scan(ExtensionType::Plugin, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());

        expect($found)->toHaveKey('padded_desc_plugin')
            ->and($found['padded_desc_plugin']['description'])->toBe('Padded lang-loaded description');
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan falls back to the header Description when Lang::load() returns an empty theme description.txt', function (): void {
    $root = extensionScannerFixtureRoot();
    try {
        $dir = $root . 'themes/empty_desc_theme';
        mkdir($dir, 0o777, true);
        file_put_contents($dir . '/themeconf.inc.php', "<?php\n/*\nTheme Name: Empty Desc Theme\nDescription: Theme header fallback description\n*/\n");
        mkdir($dir . '/language/en_UK', 0o777, true);
        file_put_contents($dir . '/language/en_UK/description.txt', '');
        file_put_contents($dir . '/screenshot.png', 'fixture');

        $found = new ExtensionScanner()->scan(ExtensionType::Theme, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());

        expect($found)->toHaveKey('empty_desc_theme')
            ->and($found['empty_desc_theme']['description'])->toBe('Theme header fallback description');
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan trims whitespace from a lang-loaded theme description', function (): void {
    $root = extensionScannerFixtureRoot();
    try {
        $dir = $root . 'themes/padded_desc_theme';
        mkdir($dir, 0o777, true);
        file_put_contents($dir . '/themeconf.inc.php', "<?php\n/*\nTheme Name: Padded Desc Theme\n*/\n");
        mkdir($dir . '/language/en_UK', 0o777, true);
        file_put_contents($dir . '/language/en_UK/description.txt', "  Padded theme lang-loaded description  \n");
        file_put_contents($dir . '/screenshot.png', 'fixture');

        $found = new ExtensionScanner()->scan(ExtensionType::Theme, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());

        expect($found)->toHaveKey('padded_desc_theme')
            ->and($found['padded_desc_theme']['description'])->toBe('Padded theme lang-loaded description');
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan reports activable/mobile/use_standard_pages as false, not just non-empty, for an explicitly false theme_conf', function (): void {
    // Real gap, found via mutation testing: SqlDialect::getBoolean($val[1])
    // -- the only "fully populated" theme fixture in this file sets all 3
    // flags to true, and SqlDialect::getBoolean()'s own (bool) cast
    // fallback treats *any* non-"false" non-empty string as true --
    // including $val[0] (the entire regex match, e.g. "'activable' =>
    // false", which is non-empty and isn't literally the word "false")
    // -- so a true-only fixture can't tell $val[1] (the captured
    // "true"/"false" word alone) apart from $val[0]; only an
    // explicitly-false fixture can.
    $root = extensionScannerFixtureRoot();
    try {
        $dir = $root . 'themes/false_flags_theme';
        mkdir($dir, 0o777, true);
        file_put_contents($dir . '/themeconf.inc.php', <<<PHP
            <?php
            /*
            Theme Name: False Flags Theme
            */
            \$theme_conf = array(
                'activable' => false,
                'mobile' => false,
                'use_standard_pages' => false,
            );
            PHP);
        file_put_contents($dir . '/screenshot.png', 'fixture');

        $found = new ExtensionScanner()->scan(ExtensionType::Theme, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());

        expect($found)->toHaveKey('false_flags_theme');
        $theme = $found['false_flags_theme'];
        expect($theme['activable'])->toBeFalse()
            ->and($theme['mobile'])->toBeFalse()
            ->and($theme['use_standard_pages'])->toBeFalse();
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan omits admin_uri for a theme with no admin/admin.inc.php', function (): void {
    // Real gap, found via mutation testing: file_exists($path . '/admin/admin.inc.php')
    // -- both existing fixtures that create an 'admin/' subdirectory
    // (padded_header_theme, headerless_theme) never assert admin_uri's
    // *absence*, so a mutant that checks file_exists($path) (the theme's
    // own directory, which always exists once we're this far) instead of
    // the specific marker file goes unnoticed.
    $root = extensionScannerFixtureRoot();
    try {
        $dir = $root . 'themes/no_admin_theme';
        mkdir($dir, 0o777, true);
        file_put_contents($dir . '/themeconf.inc.php', "<?php\n/*\nTheme Name: No Admin Theme\n*/\n");
        file_put_contents($dir . '/screenshot.png', 'fixture');

        $found = new ExtensionScanner()->scan(ExtensionType::Theme, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());

        expect($found)->toHaveKey('no_admin_theme')
            ->and($found['no_admin_theme'])->not->toHaveKey('admin_uri');
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan prefixes admin_uri with the real root url, not just the relative admin.php path', function (): void {
    // Real gap, found via mutation testing: $urlService->getRootUrl() . 'admin.php?...'
    // -- the existing "full_fixture_theme" test's own toContain() check is
    // deliberately a substring match (its own comment explains why: the
    // untouched RootPathOverride/SectionContextRegistry state makes
    // getRootUrl() return '' in that bare setup), which can't tell a
    // missing prefix from a present-but-empty one. Seeding
    // RootPathOverride directly makes getRootUrl() return a known,
    // non-empty value here, so the prefix is actually observable.
    $root = extensionScannerFixtureRoot();
    try {
        $dir = $root . 'themes/admin_uri_theme';
        mkdir($dir . '/admin', 0o777, true);
        file_put_contents($dir . '/themeconf.inc.php', "<?php\n/*\nTheme Name: Admin Uri Theme\n*/\n");
        file_put_contents($dir . '/admin/admin.inc.php', '<?php // fixture admin page, only its existence matters');
        file_put_contents($dir . '/screenshot.png', 'fixture');

        $rootPathOverride = new RootPathOverride();
        $rootPathOverride->push('https://example.com/piwigo/');
        $urlService = UrlServiceTestFactory::build(rootPathOverride: $rootPathOverride);

        $found = new ExtensionScanner()->scan(ExtensionType::Theme, $urlService, LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());

        expect($found)->toHaveKey('admin_uri_theme')
            ->and($found['admin_uri_theme']['admin_uri'])->toBe('https://example.com/piwigo/admin.php?page=theme&amp;theme=admin_uri_theme');
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan reconstructs a multi-line theme_conf value by rejoining file() lines without inserting a separator', function (): void {
    // Real gap, found via mutation testing: implode('', $lines) -- every
    // other theme fixture's header/config values sit entirely on a single
    // physical line, so nothing tells a real empty-glue join from a
    // non-empty one: inserted glue text lands strictly *between* file()
    // lines, and every `.+`-based header pattern already stops at its own
    // line's trailing \n regardless of what follows it. The 'parent'
    // field's own pattern instead uses `[^"\']+`, which -- unlike `.` --
    // DOES cross real newlines, so a value deliberately split across 2
    // file() lines (inside its own quotes) is the one place a wrong
    // separator actually lands inside the captured group itself.
    $root = extensionScannerFixtureRoot();
    try {
        $dir = $root . 'themes/multiline_parent_theme';
        mkdir($dir, 0o777, true);
        file_put_contents($dir . '/themeconf.inc.php', "<?php\n/*\nTheme Name: Multiline Parent Theme\n*/\n\$theme_conf = array(\n    'parent' => 'parent_theme\n_id',\n);\n");
        file_put_contents($dir . '/screenshot.png', 'fixture');

        $found = new ExtensionScanner()->scan(ExtensionType::Theme, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());

        expect($found)->toHaveKey('multiline_parent_theme')
            ->and($found['multiline_parent_theme']['parent'])->toBe("parent_theme\n_id");
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan rejects a language directory that is a symlink even when its target is a real directory', function (): void {
    // Real gap, found via mutation testing: `!is_dir($path) || is_link($path) || ...`
    // -- language's own guard has the exact same is_link()-gated-behind-
    // is_dir() shape as the plugin guard above, and needs the same real,
    // non-mocked symlink to distinguish.
    $root = extensionScannerFixtureRoot();
    $linkPath = $root . 'language/symlinked_lang';
    try {
        $targetDir = $root . 'language/real_target_lang';
        mkdir($targetDir, 0o777, true);
        file_put_contents($targetDir . '/common.po', <<<PO
            msgid ""
            msgstr ""
            "X-Piwigo-Language-Name: Real Target Lang\\n"

            PO);
        symlink($targetDir, $linkPath);

        $found = new ExtensionScanner()->scan(ExtensionType::Language, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get(), 'utf-8');

        expect($found)->not->toHaveKey('symlinked_lang')
            ->and($found)->toHaveKey('real_target_lang');
    } finally {
        @unlink($linkPath);
        FilesystemHelper::deltree($root);
    }
});

test('scan actually uses the caller-supplied targetCharset, not just CharsetHelper::getPwgCharset()', function (): void {
    // Real gap, found via mutation testing: `$targetCharset ?? CharsetHelper::getPwgCharset()`
    // -- every existing language test passes 'utf-8' as $targetCharset,
    // which is also exactly what CharsetHelper::getPwgCharset() itself
    // returns by default (PWG_CHARSET is never defined in this test suite
    // -- see CharsetHelperTest's own docblock), so nothing can tell a real
    // caller-supplied charset from the ignored-argument fallback.
    // Converting to iso-8859-1 -- a charset getPwgCharset() would never
    // return here -- actually changes the name's bytes, unlike an
    // identity utf-8-to-utf-8 "conversion".
    $root = extensionScannerFixtureRoot();
    $poFile = $root . 'language/iso_charset_lang/common.po';
    try {
        mkdir($root . 'language/iso_charset_lang', 0o777, true);
        file_put_contents($poFile, <<<PO
            msgid ""
            msgstr ""
            "X-Piwigo-Language-Name: café\\n"

            PO);

        $found = new ExtensionScanner()->scan(ExtensionType::Language, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get(), 'iso-8859-1');

        expect($found)->toHaveKey('iso_charset_lang')
            // Not an exact literal: htmlspecialchars()'s own UTF-8
            // decoding of the now-iso-8859-1-encoded byte for 'é'
            // (ENT_SUBSTITUTE's exact replacement) isn't what's under
            // test here -- only that the conversion demonstrably
            // happened at all, changing the bytes away from the
            // untouched, still-valid-UTF-8 'café'.
            ->and($found['iso_charset_lang']['name'])->not->toBe('café')
            ->and($found['iso_charset_lang']['name'])->toStartWith('caf');
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan defaults uri/author for a language whose common.po has no matching Language-Name header', function (): void {
    // Real gap, found via mutation testing: every other language fixture's
    // common.po carries an "X-Piwigo-Language-Name" header, so nothing
    // ever exercised the initial $language = [...] default array itself
    // ('name'/'code' default to the directory id, 'version' to
    // AppInfo::VERSION, 'uri'/'author' stay '' -- scanLanguage() never
    // sets either from any header).
    $root = extensionScannerFixtureRoot();
    try {
        mkdir($root . 'language/headerless_lang', 0o777, true);
        file_put_contents($root . 'language/headerless_lang/common.po', "msgid \"\"\nmsgstr \"\"\n");

        $found = new ExtensionScanner()->scan(ExtensionType::Language, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get(), 'utf-8');

        expect($found)->toHaveKey('headerless_lang');
        $language = $found['headerless_lang'];
        expect($language['name'])->toBe('headerless_lang')
            ->and($language['code'])->toBe('headerless_lang')
            ->and($language['version'])->toBe(AppInfo::VERSION)
            ->and($language['uri'])->toBe('')
            ->and($language['author'])->toBe('');
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan does not corrupt language name/country when charset conversion fails for an unrecognized target charset', function (): void {
    // Real gap, found via mutation testing: `if ($converted !== false)` /
    // `if ($convertedCountry !== false)` -- every existing language test
    // passes 'utf-8' as $targetCharset, which CharsetHelper::convertCharset()
    // short-circuits as an identity no-op (source === dest, never returns
    // false), so nothing ever exercised what real conversion *failure*
    // does. A deliberately unrecognized target charset routes into
    // CharsetHelper's own iconv() fallback branch, which genuinely
    // returns false (with a real E_WARNING, suppressed the same way as
    // this file's other unreadable-file tests) -- the real, correct code
    // must then keep the already-trimmed, unconverted value rather than
    // overwriting it with that `false` (which would otherwise blow up
    // array_map(htmlspecialchars(...), ...) under strict_types). The
    // padding on both header values also exercises trim() on each.
    $root = extensionScannerFixtureRoot();
    $poFile = $root . 'language/bogus_charset_lang/common.po';
    try {
        mkdir($root . 'language/bogus_charset_lang', 0o777, true);
        file_put_contents($poFile, <<<PO
            msgid ""
            msgstr ""
            "X-Piwigo-Language-Name:   Padded Language Name   \\n"
            "X-Piwigo-Country:   Padded Country   \\n"

            PO);

        set_error_handler(static fn (): bool => true, E_WARNING);
        try {
            $found = new ExtensionScanner()->scan(ExtensionType::Language, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get(), 'totally-bogus-charset-xyz');
        } finally {
            restore_error_handler();
        }

        expect($found)->toHaveKey('bogus_charset_lang')
            ->and($found['bogus_charset_lang']['name'])->toBe('Padded Language Name (Padded Country)');
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan escapes special HTML characters in a language name', function (): void {
    // Real gap, found via mutation testing: array_map(htmlspecialchars(...), $language)
    // -- the existing "escapes special HTML characters" test above only
    // covers scanPlugin()'s own htmlspecialchars() pass, not
    // scanLanguage()'s separate one.
    $root = extensionScannerFixtureRoot();
    $poFile = $root . 'language/xss_lang/common.po';
    try {
        mkdir($root . 'language/xss_lang', 0o777, true);
        file_put_contents($poFile, <<<PO
            msgid ""
            msgstr ""
            "X-Piwigo-Language-Name: <script>alert(1)</script> & "Quoted"\\n"

            PO);

        $found = new ExtensionScanner()->scan(ExtensionType::Language, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get(), 'utf-8');

        expect($found)->toHaveKey('xss_lang')
            ->and($found['xss_lang']['name'])->toBe('&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;Quoted&quot;');
    } finally {
        FilesystemHelper::deltree($root);
    }
});
