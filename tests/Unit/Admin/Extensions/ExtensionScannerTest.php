<?php

declare(strict_types=1);

use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\ConfigLoader;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Html\HtmlService;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;

// ExtensionType::scanDirectory() hardcodes real app paths (PluginLoader::pluginsPath()/
// CurrentConfig::themesPath()/CurrentPaths::get()->root.'language/') with no
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
    CurrentConfig::reset();
    ConfigLoader::applyDefaults();
    CurrentPaths::set(Paths::fromRoot(dirname(__DIR__, 4)));
});

afterEach(function (): void {
    CurrentConfig::reset();
    CurrentPaths::reset();
    CurrentUser::reset();
    Lang::reset();
});

test('scan finds the real bundled en_UK language via its common.po header', function (): void {
    $found = new ExtensionScanner()->scan(ExtensionType::Language, new UrlService(new HtmlService()), 'utf-8');

    expect($found)->toHaveKey('en_UK')
        ->and($found['en_UK']['name'])->toBe('English (Great Britain)')
        ->and($found['en_UK']['code'])->toBe('en_UK')
        ->and($found['en_UK']['version'])->not->toBe('0');
});

test('scan skips a language directory with no common.po', function (): void {
    $found = new ExtensionScanner()->scan(ExtensionType::Language, new UrlService(new HtmlService()), 'utf-8');

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
    CurrentPaths::set(Paths::fromRoot($root));
    CurrentConfig::setThemesDir(rtrim($root, '/') . '/themes');

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
            'activable' => true,
            'mobile' => true,
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
            $found = new ExtensionScanner()->scan(ExtensionType::Language, new UrlService(new HtmlService()), 'utf-8');
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

        $found = new ExtensionScanner()->scan(ExtensionType::Plugin, new UrlService(new HtmlService()));

        expect($found)->not->toHaveKey('no_main_plugin');
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
            $found = new ExtensionScanner()->scan(ExtensionType::Plugin, new UrlService(new HtmlService()));
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
        CurrentUser::set(User::fromUserArray(['id' => 1, 'status' => 'webmaster']));

        $found = new ExtensionScanner()->scan(ExtensionType::Plugin, new UrlService(new HtmlService()));

        expect($found)->toHaveKey('webmaster_gated_plugin')
            ->and($found['webmaster_gated_plugin']['hasSettings'])->toBeTrue()
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
        CurrentUser::set(User::fromUserArray(['id' => 2, 'status' => 'normal']));

        $found = new ExtensionScanner()->scan(ExtensionType::Plugin, new UrlService(new HtmlService()));

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
            $found = new ExtensionScanner()->scan(ExtensionType::Theme, new UrlService(new HtmlService()));
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

        $found = new ExtensionScanner()->scan(ExtensionType::Theme, new UrlService(new HtmlService()));

        expect($found)->toHaveKey('full_fixture_theme');
        $theme = $found['full_fixture_theme'];
        expect($theme['uri'])->toBe('https://example.com/extension_view.php?eid=999')
            ->and($theme['description'])->toBe('A fixture theme description for coverage.')
            ->and($theme['author uri'])->toBe('https://example.com/theme-author')
            // extractExtensionId() parses the eid straight out of the URI
            // above -- real end-to-end behaviour, not a separately-mocked
            // value.
            ->and($theme['extension'])->toBe('999')
            ->and($theme['activable'])->toBeTrue()
            ->and($theme['mobile'])->toBeTrue()
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
            $found = new ExtensionScanner()->scan(ExtensionType::Language, new UrlService(new HtmlService()), 'utf-8');
        } finally {
            restore_error_handler();
            chmod($poFile, 0o644);
        }

        expect($found)->not->toHaveKey('unreadable_lang');
    } finally {
        FilesystemHelper::deltree($root);
    }
});
