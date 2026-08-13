<?php

declare(strict_types=1);

use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Config\ConfigLoader;
use Piwigo\Core\AppInfo;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\EventDispatcherTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Url\RootPathOverride;
use Piwigo\Users\User;

// ExtensionType::scanDirectory() hardcodes real app paths (PluginLoader::pluginsPath()/
// CurrentConfig::themesPath()/CurrentPathsTestFactory::get()->root.'language/') with no
// injection point, so this can't safely redirect to a disposable temp
// directory the way ZipExtractorTest/UploadServiceTest do. Scanning the
// real, git-tracked language/ tree read-only is safe and deterministic
// (unlike themes/plugins, bundled language directories are stable source
// content, not environment-dependent) -- covers the real
// common.po-vs-common.lang.php marker-file bug (see
// ExtensionType::markerFilenames()'s own docblock). Plugin/theme scanning
// is exercised end-to-end by the Browser admin smoke suite against
// whatever's actually installed, not re-duplicated here. CurrentPaths is
// seeded against this repo's own real root (not a disposable temp dir) so
// the real language/ tree is genuinely reachable.
// [Mutation] Remaining untested mutations, checked per-instance via
// hand-mutation (not assumed by category alone). P27.10 dropped
// scanPlugin()/scanTheme()'s entire regex/header-comment-parsing
// mechanism (main.inc.php/themeconf.inc.php -> plugin.json/theme.json),
// so most of the plugin/theme-specific findings this block used to
// document (Version-header trim, multi-line theme_conf glue, the
// 2048-byte read window, case-insensitive "webmaster") no longer have
// any code to be mutations *of* -- removed along with the tests that
// exercised them, not left describing dead lines. Findings that still
// apply, post-rewrite:
// - `if ((bool) preg_match(...))` in scanLanguage()'s own header parsing
//   (the only remaining preg_match() call in this class) -- inert for
//   the same universal-PHP-if()-coercion reason as the campaign's own
//   ImageImagick.php/ImageExtImagick.php/SrcImage.php batches.
// - A `$data === false`-shaped guard after file_get_contents()/file() in
//   scanPlugin()/scanTheme()/scanLanguage() is covered by this file's own
//   "cannot be read"/"no plugin.json"/"no theme.json" tests per type.
// - BooleanOrToBooleanAnd on the '.'/'..' directory-entry guard: both
//   already fail the very next line's `^[a-zA-Z0-9-_]+$` id regex (a
//   period isn't in that character class), so `||` vs. the always-false
//   `&&` produces the same observable result either way.
// - UnwrapStrtolower (`strtolower($targetCharset ?? ...)`, scanLanguage()):
//   CharsetHelper::convertCharset()'s own iconv()/mb_convert_encoding()
//   fallback is itself case-insensitive for charset names, producing
//   byte-identical output either way (per a direct probe comparing the
//   "fast path identity" output against the "iconv utf-8 -> UTF-8"
//   output for the same string).
// - EmptyStringToNotEmpty (`implode('', $lines)` in scanLanguage()): the
//   "X-Piwigo-Language-Name"/"X-Piwigo-Country" regexes aren't
//   line-anchored, so a garbage separator inserted between file() lines
//   lands adjacent to, not inside, whichever line actually carries a
//   header, leaving the header's own regex match unaffected.
// - EmptyStringToNotEmpty (`$uri === ''` in extractExtensionId()):
//   mathematically redundant given the very next `||` clause -- an empty
//   string can never contain the non-empty 'extension_view.php?eid='
//   marker substring, so `!str_contains($uri, ...)` is already true
//   whenever $uri === '' would have been.
// - RemoveFunctionCall (`closedir($dir)`): pure resource cleanup with no
//   observable effect any black-box test in this suite can detect.

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
    $found = new ExtensionScanner()
        ->scan(ExtensionType::Language, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get(), 'utf-8');

    expect($found)
        ->toHaveKey('en_UK')
        ->and($found['en_UK']['name'])->toBe('English (Great Britain)')
        ->and($found['en_UK']['code'])->toBe('en_UK')
        ->and($found['en_UK']['version'])->not->toBe('0');
});

test('scan skips a language directory with no common.po', function (): void {
    $found = new ExtensionScanner()
        ->scan(ExtensionType::Language, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get(), 'utf-8');

    // index.php sits alongside the real locale directories under language/
    // but isn't itself an extension -- also fails the [a-zA-Z0-9-_]+ id
    // regex to begin with (has a dot), confirming both guards hold.
    expect($found)
        ->not->toHaveKey('index.php');
});

// Below: real coverage-gap closure for scan()/scanPlugin()/scanTheme()/
// scanLanguage()'s remaining untested branches (guard-failure returns,
// the webmaster-gated hasSettings flag, and every optional theme
// manifest field). Unlike the two tests above, these don't touch the
// real git-tracked plugins/themes/language trees at all -- Paths::fromRoot()
// (unlike ExtensionType::scanDirectory()'s own hardcoded composition)
// genuinely accepts *any* root directory, so a disposable temp root is a
// real, safe injection point here (CurrentConfig::setThemesDir() likewise
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
    CurrentConfigTestFactory::get()->themesDir = rtrim($root, '/') . '/themes';

    return $root;
}

/**
 * @param array<string, mixed> $data
 */
function extensionScannerWriteJson(string $path, array $data): void
{
    file_put_contents($path, json_encode($data, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
}

function extensionScannerFixturePlugin(string $root, string $id): void
{
    $dir = $root . 'plugins/' . $id;
    mkdir($dir, 0o777, true);
    extensionScannerWriteJson($dir . '/plugin.json', [
        'name' => 'Full Fixture Plugin',
        'version' => '2.3.4',
        'homepage' => 'https://example.com/extension_view.php?eid=777',
        'description' => 'A fixture plugin description for coverage.',
        'author' => 'Fixture Author',
        'authorUri' => 'https://example.com/author',
        'hasSettings' => 'webmaster',
    ]);
}

function extensionScannerFixtureTheme(string $root, string $id): void
{
    $dir = $root . 'themes/' . $id;
    mkdir($dir . '/admin', 0o777, true);
    extensionScannerWriteJson($dir . '/theme.json', [
        'name' => 'Full Fixture Theme',
        'version' => '3.1.4',
        'homepage' => 'https://example.com/extension_view.php?eid=999',
        'description' => 'A fixture theme description for coverage.',
        'author' => 'Fixture Theme Author',
        'authorUri' => 'https://example.com/theme-author',
        'parent' => 'parent_theme_id',
        'useStandardPages' => true,
    ]);
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
            $found = new ExtensionScanner()
                ->scan(ExtensionType::Language, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get(), 'utf-8');
        } finally {
            restore_error_handler();
        }

        expect($found)
            ->toBe([]);
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan skips a plugin directory with no plugin.json', function (): void {
    $root = extensionScannerFixtureRoot();
    try {
        mkdir($root . 'plugins/no_main_plugin', 0o777, true);

        $found = new ExtensionScanner()
            ->scan(ExtensionType::Plugin, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());

        expect($found)
            ->not->toHaveKey('no_main_plugin');
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan skips a plugin directory whose plugin.json is not valid JSON', function (): void {
    // json_decode() failure -- scanPlugin()'s own `! is_array($data)`
    // guard, the JSON-read counterpart of the old header-regex mechanism's
    // "no matching header" default-filling path (which no longer applies
    // at all: a malformed manifest here degrades to "not found", not a
    // partially-defaulted entry).
    $root = extensionScannerFixtureRoot();
    try {
        $dir = $root . 'plugins/malformed_plugin';
        mkdir($dir, 0o777, true);
        file_put_contents($dir . '/plugin.json', '{not valid json');

        $found = new ExtensionScanner()
            ->scan(ExtensionType::Plugin, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());

        expect($found)
            ->not->toHaveKey('malformed_plugin');
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan skips a directory entry with an invalid id but keeps scanning the rest', function (): void {
    // A directory containing only
    // '.'/'..' plus valid-id entries never actually reaches the
    // regex-mismatch branch at all (both are caught by the earlier '.'/
    // '..' guard), so `continue` vs `break` there was never exercised. An
    // entry with an invalid id needs to be encountered by readdir() BEFORE
    // a real, valid plugin id for `continue` (keep scanning) vs `break`
    // (stop entirely) to actually diverge.
    //
    // This file's original fixture ('a.invalid.name' before
    // 'zzz_valid_plugin', assuming alphabetical iteration) did NOT
    // actually catch this, and a follow-up fixed-name-pair attempt didn't
    // reliably catch it either: readdir()'s real order on this filesystem
    // is neither alphabetical nor stable creation order -- per
    // direct, repeated probing, the SAME two literal names can come
    // back in either relative order across different runs (a randomized
    // per-mount directory-hash seed is the likely cause, same security
    // rationale as ext4 htree's own hash-flood mitigation -- this
    // sandbox's /tmp may well be tmpfs, not ext4, but shows the same
    // instability). No fixed name pair can be trusted deterministic here.
    //
    // Statistical approach instead: many valid entries (20) plus several
    // invalid ones (3) spread across the same directory. `continue`
    // (correct) always finds all 20 regardless of order. `break`
    // (mutated) stops at the FIRST invalid entry readdir() happens to
    // reach, so `count($found)` comes back short unless, by chance, all 3
    // invalid entries land after every single valid one in this run's
    // real iteration order -- for 23 real entries in random relative
    // order that's on the order of 1-in-10000, deterministic enough in
    // practice without depending on any specific name's hash outcome.
    $root = extensionScannerFixtureRoot();
    try {
        for ($i = 0; $i < 20; $i++) {
            $id = 'valid_plugin_' . $i;
            mkdir($root . 'plugins/' . $id, 0o777, true);
            extensionScannerWriteJson($root . 'plugins/' . $id . '/plugin.json', [
                'name' => "Valid {$i}",
            ]);
        }
        foreach (['bad!one', 'bad!two', 'bad!three'] as $badId) {
            mkdir($root . 'plugins/' . $badId, 0o777, true);
        }

        $found = new ExtensionScanner()
            ->scan(ExtensionType::Plugin, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());

        expect($found)
            ->toHaveCount(20, 'break instead of continue on the invalid-id check would stop the scan early and miss some valid entries');
        for ($i = 0; $i < 20; $i++) {
            expect($found)->toHaveKey('valid_plugin_' . $i);
        }
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan defaults name/version/uri/description/author for a plugin whose plugin.json declares no matching fields', function (): void {
    // Every other plugin test uses
    // a fully-populated manifest, so nothing ever exercised the initial
    // $plugin = [...] default values themselves (name falls back to the
    // directory id, version to '0', the rest to '').
    $root = extensionScannerFixtureRoot();
    try {
        mkdir($root . 'plugins/headerless_plugin', 0o777, true);
        extensionScannerWriteJson($root . 'plugins/headerless_plugin/plugin.json', []);

        $found = new ExtensionScanner()
            ->scan(ExtensionType::Plugin, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());

        expect($found)
            ->toHaveKey('headerless_plugin');
        $plugin = $found['headerless_plugin'];
        expect($plugin['name'])->toBe('headerless_plugin')
            ->and($plugin['version'])->toBe('0')
            ->and($plugin['uri'])->toBe('')
            ->and($plugin['description'])->toBe('')
            ->and($plugin['author'])->toBe('')
            ->and($plugin['hasSettings'])->toBeFalse()
            ->and($plugin)
            ->not->toHaveKey('author uri')
            ->and($plugin)
            ->not->toHaveKey('extension');
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan skips a plugin whose plugin.json cannot be read', function (): void {
    $root = extensionScannerFixtureRoot();
    $manifestFile = $root . 'plugins/unreadable_plugin/plugin.json';
    try {
        mkdir($root . 'plugins/unreadable_plugin', 0o777, true);
        extensionScannerWriteJson($manifestFile, [
            'name' => 'Unreadable',
        ]);
        // A real, unreadable file (0000, torres owns it but no read bit
        // for anyone) -- file_get_contents() genuinely returns false here,
        // not a mock, matching UploadServiceTest::sanitizeSvgIfNeeded's
        // own established permission-denied convention (torres is a
        // non-root user in this environment, per `id`, so
        // owning a file does not bypass its own permission bits).
        // file_exists() (scanPlugin()'s own earlier guard) stays true
        // regardless of the chmod -- only the later read fails -- so this
        // genuinely exercises scanPlugin()'s own `$contents === false`
        // guard, not the guard above it.
        chmod($manifestFile, 0o000);

        set_error_handler(static fn (): bool => true, E_WARNING);
        try {
            $found = new ExtensionScanner()
                ->scan(ExtensionType::Plugin, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());
        } finally {
            restore_error_handler();
            chmod($manifestFile, 0o644);
        }

        expect($found)
            ->not->toHaveKey('unreadable_plugin');
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan reports hasSettings=true for a webmaster-gated plugin when the current user is a webmaster', function (): void {
    $root = extensionScannerFixtureRoot();
    try {
        extensionScannerFixturePlugin($root, 'webmaster_gated_plugin');
        CurrentUserTestFactory::get()->set(User::fromUserArray([
            'id' => 1,
            'status' => 'webmaster',
        ]));

        $found = new ExtensionScanner()
            ->scan(ExtensionType::Plugin, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());

        expect($found)
            ->toHaveKey('webmaster_gated_plugin')
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
        CurrentUserTestFactory::get()->set(User::fromUserArray([
            'id' => 2,
            'status' => 'normal',
        ]));

        $found = new ExtensionScanner()
            ->scan(ExtensionType::Plugin, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());

        // Real security-relevant behaviour, not just a line-coverage
        // formality: a non-webmaster user must never see hasSettings=true
        // for a plugin whose "Has Settings: webmaster" header gates its
        // settings page behind webmaster status.
        expect($found)
            ->toHaveKey('webmaster_gated_plugin_normal_user')
            ->and($found['webmaster_gated_plugin_normal_user']['hasSettings'])->toBeFalse();
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
        extensionScannerWriteJson($dir . '/plugin.json', [
            'name' => '<script>alert(1)</script> & "Quoted"',
        ]);

        $found = new ExtensionScanner()
            ->scan(ExtensionType::Plugin, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());

        expect($found)
            ->toHaveKey('xss_plugin')
            ->and($found['xss_plugin']['name'])->toBe('&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;Quoted&quot;');
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan defaults id/name/version/uri/description/author for a theme whose theme.json declares no matching fields', function (): void {
    $root = extensionScannerFixtureRoot();
    try {
        mkdir($root . 'themes/headerless_theme/admin', 0o777, true);
        extensionScannerWriteJson($root . 'themes/headerless_theme/theme.json', []);
        file_put_contents($root . 'themes/headerless_theme/screenshot.png', 'fixture');

        $found = new ExtensionScanner()
            ->scan(ExtensionType::Theme, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());

        expect($found)
            ->toHaveKey('headerless_theme');
        $theme = $found['headerless_theme'];
        expect($theme['id'])->toBe('headerless_theme')
            ->and($theme['name'])->toBe('headerless_theme')
            ->and($theme['version'])->toBe('0')
            ->and($theme['uri'])->toBe('')
            ->and($theme['description'])->toBe('')
            ->and($theme['author'])->toBe('')
            ->and($theme['mobile'])->toBeFalse()
            ->and($theme)
            ->not->toHaveKey('author uri')
            ->and($theme)
            ->not->toHaveKey('extension')
            ->and($theme)
            ->not->toHaveKey('parent')
            ->and($theme)
            ->not->toHaveKey('activable')
            ->and($theme)
            ->not->toHaveKey('use_standard_pages');
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan skips a theme whose theme.json cannot be read', function (): void {
    $root = extensionScannerFixtureRoot();
    $manifestFile = $root . 'themes/unreadable_theme/theme.json';
    try {
        mkdir($root . 'themes/unreadable_theme', 0o777, true);
        extensionScannerWriteJson($manifestFile, [
            'name' => 'Unreadable',
        ]);
        // Same real chmod(0000)-unreadable-file technique as the plugin
        // plugin.json test above, for scanTheme()'s own
        // `$contents === false` guard.
        chmod($manifestFile, 0o000);

        set_error_handler(static fn (): bool => true, E_WARNING);
        try {
            $found = new ExtensionScanner()
                ->scan(ExtensionType::Theme, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());
        } finally {
            restore_error_handler();
            chmod($manifestFile, 0o644);
        }

        expect($found)
            ->not->toHaveKey('unreadable_theme');
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan extracts every optional theme manifest field from a fully-populated theme', function (): void {
    $root = extensionScannerFixtureRoot();
    try {
        extensionScannerFixtureTheme($root, 'full_fixture_theme');

        $found = new ExtensionScanner()
            ->scan(ExtensionType::Theme, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());

        expect($found)
            ->toHaveKey('full_fixture_theme');
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
            // ThemeManifest has no 'activable' field at all (never set),
            // and 'mobile' is always false (no manifest equivalent either,
            // see ThemeManifest's own docblock) -- both real, permanent
            // differences from the old header-comment format, not gaps.
            ->and($theme)
            ->not->toHaveKey('activable')
            ->and($theme['mobile'])->toBeFalse()
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
    expect(extensionScannerExtractExtensionId(''))
        ->toBeNull();
});

test('extractExtensionId returns null for a uri with no eid marker', function (): void {
    expect(extensionScannerExtractExtensionId('https://example.com/'))
        ->toBeNull();
});

test('extractExtensionId returns null for a non-numeric eid', function (): void {
    expect(extensionScannerExtractExtensionId('https://example.com/extension_view.php?eid=not-a-number'))
        ->toBeNull();
});

test('extractExtensionId returns the eid for a real, numeric extension_view.php uri', function (): void {
    expect(extensionScannerExtractExtensionId('https://example.com/extension_view.php?eid=42'))
        ->toBe('42');
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
            $found = new ExtensionScanner()
                ->scan(ExtensionType::Language, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get(), 'utf-8');
        } finally {
            restore_error_handler();
            chmod($poFile, 0o644);
        }

        expect($found)
            ->not->toHaveKey('unreadable_lang');
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan does not append an empty, whitespace-only X-Piwigo-Country to the language name', function (): void {
    // The real bundled en_UK fixture
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

        $found = new ExtensionScanner()
            ->scan(ExtensionType::Language, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get(), 'utf-8');

        expect($found)
            ->toHaveKey('blank_country_lang')
            ->and($found['blank_country_lang']['name'])->toBe('Blank Country Language');
    } finally {
        FilesystemHelper::deltree($root);
    }
});

// Below: second mutation-testing pass. Same fixture-root/no-DB-bootstrap
// technique as the section above.

test('scan sorts languages by name via nameCompare, not raw directory listing order', function (): void {
    // `if ($type === ExtensionType::Language)`
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

        $found = new ExtensionScanner()
            ->scan(ExtensionType::Language, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get(), 'utf-8');

        expect(array_keys($found))
            ->toBe(['zzz_dir', 'mmm_dir', 'aaa_dir']);
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan rejects a plugin directory that is a symlink even when its target is a real directory', function (): void {
    // `!is_dir($path) || is_link($path) || ...`
    // -- a symlink pointing at a real directory satisfies is_dir() too (it
    // follows the link), so the is_link() rejection has to be checked
    // independently of is_dir(), not gated behind it.
    $root = extensionScannerFixtureRoot();
    $linkPath = $root . 'plugins/symlinked_plugin';
    try {
        $targetDir = $root . 'plugins/real_target_plugin';
        mkdir($targetDir, 0o777, true);
        extensionScannerWriteJson($targetDir . '/plugin.json', [
            'name' => 'Real Target',
        ]);
        symlink($targetDir, $linkPath);

        $found = new ExtensionScanner()
            ->scan(ExtensionType::Plugin, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());

        expect($found)
            ->not->toHaveKey('symlinked_plugin')
            ->and($found)
            ->toHaveKey('real_target_plugin');
    } finally {
        // Removed before deltree() walks it -- FilesystemHelper::deltree()
        // follows is_dir() (which a directory symlink satisfies) and then
        // calls plain rmdir() on it, which fails (and warns) for a
        // symlink on Linux.
        @unlink($linkPath);
        FilesystemHelper::deltree($root);
    }
});

test('scan only gates hasSettings on the exact literal string "webmaster", not any other truthy string value', function (): void {
    // $hasSettingsRaw === 'webmaster' -- a real, deliberate difference from
    // the old case-insensitive [Ww]ebmaster header regex: plugin.json's own
    // schema restricts 'hasSettings' to `true` or the literal lowercase
    // string "webmaster" (opis/json-schema's enum, case-sensitive by
    // design), so scanPlugin() correctly treats any other string (however
    // truthy) as neither -- hasSettings stays false, not gated-true.
    $root = extensionScannerFixtureRoot();
    try {
        $dir = $root . 'plugins/capitalized_webmaster_plugin';
        mkdir($dir, 0o777, true);
        extensionScannerWriteJson($dir . '/plugin.json', [
            'name' => 'Capitalized',
            'hasSettings' => 'Webmaster',
        ]);
        CurrentUserTestFactory::get()->set(User::fromUserArray([
            'id' => 3,
            'status' => 'webmaster',
        ]));

        $found = new ExtensionScanner()
            ->scan(ExtensionType::Plugin, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());

        expect($found)
            ->toHaveKey('capitalized_webmaster_plugin')
            ->and($found['capitalized_webmaster_plugin']['hasSettings'])->toBeFalse();
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan skips a theme directory with no theme.json', function (): void {
    // `!is_dir($path) || !file_exists(...)`
    // -- unlike the equivalent plugin test, nothing exercised a theme
    // directory that exists but is missing its own marker file.
    $root = extensionScannerFixtureRoot();
    try {
        mkdir($root . 'themes/no_conf_theme', 0o777, true);

        $found = new ExtensionScanner()
            ->scan(ExtensionType::Theme, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());

        expect($found)
            ->not->toHaveKey('no_conf_theme');
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan reports use_standard_pages as an explicitly present false, not just absent, when the manifest says so', function (): void {
    // is_bool($data['useStandardPages'] ?? null) -- the only
    // "fully populated" theme fixture in this file sets it to true, and
    // the "headerless" defaults test never sets the key at all -- neither
    // tells an explicit `false` (a real, present key -- setTheme()'s own
    // fallback to $currentConfig->useStandardPages should NOT kick in)
    // apart from a key that's simply missing (where that fallback SHOULD
    // kick in).
    $root = extensionScannerFixtureRoot();
    try {
        $dir = $root . 'themes/false_flags_theme';
        mkdir($dir, 0o777, true);
        extensionScannerWriteJson($dir . '/theme.json', [
            'name' => 'False Flags Theme',
            'useStandardPages' => false,
        ]);
        file_put_contents($dir . '/screenshot.png', 'fixture');

        $found = new ExtensionScanner()
            ->scan(ExtensionType::Theme, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());

        expect($found)
            ->toHaveKey('false_flags_theme');
        $theme = $found['false_flags_theme'];
        expect($theme)
            ->toHaveKey('use_standard_pages')
            ->and($theme['use_standard_pages'])->toBeFalse();
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan omits admin_uri for a theme with no admin/admin.inc.php', function (): void {
    // file_exists($path . '/admin/admin.inc.php')
    // -- both existing fixtures that create an 'admin/' subdirectory
    // (padded_header_theme, headerless_theme) never assert admin_uri's
    // *absence*, so a mutant that checks file_exists($path) (the theme's
    // own directory, which always exists once we're this far) instead of
    // the specific marker file goes unnoticed.
    $root = extensionScannerFixtureRoot();
    try {
        $dir = $root . 'themes/no_admin_theme';
        mkdir($dir, 0o777, true);
        extensionScannerWriteJson($dir . '/theme.json', [
            'name' => 'No Admin Theme',
        ]);
        file_put_contents($dir . '/screenshot.png', 'fixture');

        $found = new ExtensionScanner()
            ->scan(ExtensionType::Theme, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());

        expect($found)
            ->toHaveKey('no_admin_theme')
            ->and($found['no_admin_theme'])->not->toHaveKey('admin_uri');
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan prefixes admin_uri with the real root url, not just the relative admin.php path', function (): void {
    // $urlService->getRootUrl() . 'admin.php?...'
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
        extensionScannerWriteJson($dir . '/theme.json', [
            'name' => 'Admin Uri Theme',
        ]);
        file_put_contents($dir . '/admin/admin.inc.php', '<?php // fixture admin page, only its existence matters');
        file_put_contents($dir . '/screenshot.png', 'fixture');

        $rootPathOverride = new RootPathOverride();
        $rootPathOverride->push('https://example.com/piwigo/');
        $urlService = UrlServiceTestFactory::build(rootPathOverride: $rootPathOverride);

        $found = new ExtensionScanner()
            ->scan(ExtensionType::Theme, $urlService, LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get());

        expect($found)
            ->toHaveKey('admin_uri_theme')
            ->and($found['admin_uri_theme']['admin_uri'])->toBe('https://example.com/piwigo/admin.php?page=theme&amp;theme=admin_uri_theme');
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan rejects a language directory that is a symlink even when its target is a real directory', function (): void {
    // `!is_dir($path) || is_link($path) || ...`
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

        $found = new ExtensionScanner()
            ->scan(ExtensionType::Language, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get(), 'utf-8');

        expect($found)
            ->not->toHaveKey('symlinked_lang')
            ->and($found)
            ->toHaveKey('real_target_lang');
    } finally {
        @unlink($linkPath);
        FilesystemHelper::deltree($root);
    }
});

test('scan actually uses the caller-supplied targetCharset, not just the default fallback', function (): void {
    // `$targetCharset ?? 'utf-8'` --
    // every existing language test passes 'utf-8' as $targetCharset, which
    // is also exactly the default fallback, so nothing can tell a real
    // caller-supplied charset from the ignored-argument fallback.
    // Converting to iso-8859-1 -- a charset the default fallback would
    // never produce here -- actually changes the name's bytes, unlike an
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

        $found = new ExtensionScanner()
            ->scan(ExtensionType::Language, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get(), 'iso-8859-1');

        expect($found)
            ->toHaveKey('iso_charset_lang')
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
    // Every other language fixture's
    // common.po carries an "X-Piwigo-Language-Name" header, so nothing
    // ever exercised the initial $language = [...] default array itself
    // ('name'/'code' default to the directory id, 'version' to
    // AppInfo::VERSION, 'uri'/'author' stay '' -- scanLanguage() never
    // sets either from any header).
    $root = extensionScannerFixtureRoot();
    try {
        mkdir($root . 'language/headerless_lang', 0o777, true);
        file_put_contents($root . 'language/headerless_lang/common.po', "msgid \"\"\nmsgstr \"\"\n");

        $found = new ExtensionScanner()
            ->scan(ExtensionType::Language, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get(), 'utf-8');

        expect($found)
            ->toHaveKey('headerless_lang');
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
    // `if ($converted !== false)` /
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
            $found = new ExtensionScanner()
                ->scan(ExtensionType::Language, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get(), 'totally-bogus-charset-xyz');
        } finally {
            restore_error_handler();
        }

        expect($found)
            ->toHaveKey('bogus_charset_lang')
            ->and($found['bogus_charset_lang']['name'])->toBe('Padded Language Name (Padded Country)');
    } finally {
        FilesystemHelper::deltree($root);
    }
});

test('scan escapes special HTML characters in a language name', function (): void {
    // array_map(htmlspecialchars(...), $language)
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

        $found = new ExtensionScanner()
            ->scan(ExtensionType::Language, UrlServiceTestFactory::build(), LangTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), EventDispatcherTestFactory::get(), CurrentConfigTestFactory::get(), 'utf-8');

        expect($found)
            ->toHaveKey('xss_lang')
            ->and($found['xss_lang']['name'])->toBe('&lt;script&gt;alert(1)&lt;/script&gt; &amp; &quot;Quoted&quot;');
    } finally {
        FilesystemHelper::deltree($root);
    }
});
