<?php

declare(strict_types=1);

use Piwigo\Admin\Extensions\ExtensionIgnoredUpdateEntity;
use Piwigo\Admin\Extensions\ExtensionIgnoredUpdateRepository;
use Piwigo\Admin\Extensions\ExtensionScanner;
use Piwigo\Admin\Extensions\ExtensionType;
use Piwigo\Admin\Extensions\ExtensionUpdateChecker;
use Piwigo\Admin\Extensions\PemCatalog;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Config\ConfigLoader;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Users\CurrentUser;

// ExtensionScanner/PemCatalog are both `final` with no interface, and
// PemCatalog::getServerExtensions()/getVersionsToCheck() talk to the real
// PEM server via Piwigo\Http\HttpClientService's static fetch() (no
// injectable-client seam for the static entry point -- see
// HttpClientServiceTest's own instance-level MockHttpClient coverage,
// which doesn't help here since PemCatalog never goes through an
// injected instance). There is therefore no way to hand-construct a fake
// PemCatalog (can't subclass a final class) nor to make the real one
// return controlled server-side data in a Unit test -- same limitation
// CoreUpdateServiceTest already documents for its own network-touching
// methods, and the same "confUpdateParam()-needs-a-real-DB" limitation
// ConfigServiceTest defers to tests/Integration/ConfigServiceTest.php.
//
// What IS real and controllable here: AppInfo::DOMAIN is deliberately
// 'upstream.example.invalid' (RFC 2606 -- guaranteed never to resolve),
// so every PemCatalog PEM call in this fork fails fast and
// deterministically (DNS/SSRF-guard failure, not a flaky timeout) --
// this is a real, designed, always-true behaviour in this codebase, not
// a test-only assumption. getPendingUpdates() therefore always returns
// null (its own documented "PEM server couldn't be reached" contract),
// checkUpdatedExtensions() never has a version to catch up to, and
// getMergedExtensions()/getMissingExtensions() always see an empty
// server-side data set -- real, exercised branches below, not vacuous
// ones. checkExtensions() itself is still not covered here at all: every
// one of its own ExtensionIgnoredUpdateRepository reads/writes lives
// inside the `if ($pending === null) { continue; }`-gated loop body, which
// never runs in this environment for the same PEM-unreachable reason as
// getPendingUpdates() above -- calling it here would only exercise the
// trivial "$_SESSION reset, nothing else happens" no-op path, not the
// actual repository interaction this migration added.
//
// ExtensionType::scanDirectory() for Plugin/Language both resolve
// against CurrentPaths (like ExtensionScannerTest already relies on for
// Language); Theme resolves against CurrentConfig::themesDir(), which
// defaults to the *relative* 'themes/' -- left uncontrolled, that would
// resolve against the real, git-tracked themes/ directory this
// project ships (whatever the test runner's cwd happens to be), and
// ExtensionScanner::scanTheme() falls back to a real
// Piwigo\Users\PreferencesService(...)->getAdminThemePref() DB read whenever a
// theme directory has no screenshot.png (true of themes/default/ here) --
// a hidden DB dependency getMissingExtensions() would otherwise trip
// over purely as a side effect of iterating ExtensionType::cases().
// CurrentConfig::setThemesDir() is pointed at an empty fixture directory
// below specifically to keep every scan in this file free of both the
// real repo tree and the real DB.
//
// [Mutation] Every remaining untested mutation after mutation testing is
// confirmed genuinely inert via hand-mutation + a full filtered rerun --
// zero new tests needed, all downstream of the SAME "PEM server always
// unreachable" architecture this file's own docblock above already
// establishes:
// - getPendingUpdates()'s own $fsExtensionIds-building loop (foreach/
//   ??/is_scalar/(string) cast, plus the $new/$betaTest args passed
//   into getServerExtensions()) is entirely inert because
//   getServerExtensions() itself returns null from its OWN very first
//   check (getVersionsToCheck() also always fails) -- before it ever
//   looks at $fsExtensionIds, $new, or $betaTest at all. Their exact
//   values are structurally unobservable regardless of what real fs
//   data feeds them.
// - checkExtensions()'s own `foreach (ExtensionType::cases() as $type)`
//   loop (ForeachEmptyIterable) and its `if ($pending === null) {
//   continue; }` guard (ContinueToBreak) are both inert for the same
//   underlying reason as above: getPendingUpdates() returns null for
//   EVERY type, so every iteration hits the same no-op branch
//   regardless of whether the loop runs 3 times, 0 times, or stops
//   after the first hit -- $extensionsNeedUpdate (and the final
//   $_SESSION write) ends up identically [] in every case.
// - checkUpdatedExtensions()'s own `!is_array(...) || ... === []`
//   guard (BooleanOrToBooleanAnd) is inert too, verified by tracing
//   BOTH ways the mutation can misfire (a real non-empty record for
//   one type, null/[] for another): the `??` operator on the very next
//   line already suppresses any warning from indexing a wrong-shaped
//   $typeUpdatesRaw, and is_string(null) is always false regardless --
//   the mutation lets extra, unnecessary fs scans run for types with
//   no pending record, but never changes the final observable result.
// - $fsVersion's own '' vs '/(sentinel)' default (Line 210,
//   EmptyStringToNotEmpty) is inert here specifically because every
//   fixture plugin/theme/language this file constructs always carries
//   a real string Version -- the ternary's false branch never actually
//   executes with real fixture data one way or the other.
// - getMergedExtensions()'s own `return $merged;` (Line 246,
//   AlwaysReturnEmptyArray) is the identical PemCatalog.php pattern:
//   $mergedExtensionUrl is hardcoded to the same never-resolves
//   'upstream.example.invalid' domain, so $merged can never become
//   non-empty in this environment either.
// - getMissingExtensions()'s own (string) casts (Lines 274/288) and its
//   `getServerExtensions(...) ?? []` (Line 281, CoalesceRemoveLeft +
//   both FalseToTrue variants) are the same "always-null
//   getServerExtensions(), args never actually inspected" pattern as
//   getPendingUpdates() above.
// - The remaining (bool) casts (Line 212) are inert for the universal
//   `if((bool)X)` === `if(X)`/`and (bool)X` === `and X` PHP semantics
//   reason established throughout this whole campaign.
function fixturePlugin(string $root, string $id, string $version, string $eid, string $name): void
{
    $dir = $root . 'plugins/' . $id;
    mkdir($dir, 0o777, true);
    file_put_contents($dir . '/main.inc.php', <<<PHP
        <?php
        /*
        Plugin Name: {$name}
        Version: {$version}
        Description: Fixture plugin for ExtensionUpdateCheckerTest.
        Author: Fixture Author
        Plugin URI: https://upstream.example.invalid/extension_view.php?eid={$eid}
        */
        PHP);
}

// PHPStan can't see across the beforeEach()/test() closure boundary that
// $fixtureRoot is always a real string by the time a test body runs
// (Pest always runs beforeEach() first) -- it only sees the `null` from
// this file's own top-level declaration. Narrows for real rather than
// widening fixturePlugin()/fixtureLanguage()'s own honest `string $root`
// signature to accept null.
function requireFixtureRoot(?string $fixtureRoot): string
{
    if (! is_string($fixtureRoot)) {
        throw new RuntimeException('fixtureRoot not initialized -- beforeEach() must run first');
    }

    return $fixtureRoot;
}

function fixtureLanguage(string $root, string $code, string $name): void
{
    $dir = $root . 'language/' . $code;
    mkdir($dir, 0o777, true);
    file_put_contents($dir . '/common.po', <<<PO
        msgid ""
        msgstr ""
        "X-Piwigo-Language-Name: {$name}\\n"

        PO);
}

// Includes a screenshot.png specifically to dodge ExtensionScanner::
// scanTheme()'s no-screenshot fallback (real PreferencesService/DB read --
// see this file's own top-of-file docblock), the same landmine already
// documented there, while still carrying a real PEM extension_view.php?eid=
// URI so getMissingExtensions()'s own fsExtensionIds computation is
// non-empty for this fixture (mirrors fixturePlugin()'s own URI shape).
function fixtureTheme(string $root, string $id, string $version, string $eid, string $name): void
{
    $dir = $root . 'themes/' . $id;
    mkdir($dir, 0o777, true);
    file_put_contents($dir . '/themeconf.inc.php', <<<PHP
        <?php
        /*
        Theme Name: {$name}
        Version: {$version}
        Description: Fixture theme for ExtensionUpdateCheckerTest.
        Author: Fixture Author
        Theme URI: https://upstream.example.invalid/extension_view.php?eid={$eid}
        */
        PHP);
    file_put_contents($dir . '/screenshot.png', 'fixture');
}

function extensionUpdateChecker(): ExtensionUpdateChecker
{
    // Same "lazy DBAL connection, never actually queried by the methods
    // under test here" reasoning as CoreUpdateServiceTest's own
    // core_update_service() helper -- the repository is only needed to
    // satisfy ExtensionUpdateChecker's constructor type; none of
    // getPendingUpdates()/checkUpdatedExtensions()/getMergedExtensions()/
    // getMissingExtensions() (the methods under test here) ever touch it,
    // only checkExtensions() does, and that method is still not covered
    // here at all -- see this file's own docblock on why (real PEM
    // network access, unavailable in this environment).
    //
    // CurrentUser/CurrentConfig ARE exercised though:
    // getMissingExtensions() threads both into
    // ExtensionScanner::scan()'s own NOCTOR-shaped params, which need the
    // real, shared CurrentConfigTestFactory::get() (reflecting this file's own
    // beforeEach()-booted fixture Paths) and CurrentUserTestFactory::get() --
    // not a fresh, disconnected instance -- for scan()'s own themesDir()
    // lookup and PreferencesService::getAdminThemePref() fallback to
    // resolve correctly. Found live: a fresh CurrentConfig here silently
    // pointed scan() at the real project themes/ directory instead of
    // the disposable fixture root, and a fresh, never-.set() CurrentUser
    // threw once scan() actually reached a real theme with no
    // screenshot.png.
    $repo = EntityManagerFactory::build(DbConnection::build())->getRepository(ExtensionIgnoredUpdateEntity::class);

    return new ExtensionUpdateChecker(
        LangTestFactory::get(),
        new ExtensionScanner(),
        new PemCatalog(new ZipExtractor(), new CurrentLogger(), new CurrentUser(new CurrentConfig()), CurrentPathsTestFactory::get(), new CurrentConfig()),
        UrlServiceTestFactory::build(),
        $repo,
        CurrentPathsTestFactory::get(),
        CurrentUserTestFactory::get(),
        new EventDispatcher(),
        CurrentConfigTestFactory::get(),
    );
}

$fixtureRoot = null;

beforeEach(function () use (&$fixtureRoot): void {
    CurrentConfigTestFactory::get()->reset();
    ConfigLoader::applyDefaults();

    $fixtureRoot = sys_get_temp_dir() . '/piwigo-extension-update-checker-test-' . bin2hex(random_bytes(4)) . '/';
    mkdir($fixtureRoot, 0o777, true);
    Kernel::boot(Paths::fromRoot($fixtureRoot));

    // Empty, disposable plugins/themes/language dirs, pre-created even
    // when a given test's fixture helpers don't populate them --
    // ExtensionScanner::scan() calls a bare opendir() with no existence
    // guard, which emits a PHP warning (not just an empty-array return)
    // against a scan directory that doesn't exist yet, and this repo's
    // phpunit.xml.dist runs with failOnWarning="true". See this file's
    // own top-of-file docblock for why Theme in particular must never
    // reach the real, git-tracked themes/ tree.
    mkdir($fixtureRoot . 'plugins', 0o777, true);
    mkdir($fixtureRoot . 'themes', 0o777, true);
    mkdir($fixtureRoot . 'language', 0o777, true);
    CurrentConfigTestFactory::get()->themesDir = rtrim($fixtureRoot, '/') . '/themes';

    unset($_SESSION['extensions_need_update']);
});

afterEach(function () use (&$fixtureRoot): void {
    CurrentConfigTestFactory::get()->reset();
    Kernel::reset();
    unset($_SESSION['extensions_need_update']);
    if (is_string($fixtureRoot) && is_dir($fixtureRoot)) {
        FilesystemHelper::deltree($fixtureRoot);
    }
    $fixtureRoot = null;
});

test('getPendingUpdates returns null for Plugin when the PEM server is unreachable', function () use (&$fixtureRoot): void {
    fixturePlugin(requireFixtureRoot($fixtureRoot), 'zzz_custom_plugin', '0.9.2', '777', 'Custom Gallery Widget');

    expect(extensionUpdateChecker()->getPendingUpdates(ExtensionType::Plugin))->toBeNull();
});

test('getPendingUpdates returns null for Language when the PEM server is unreachable', function () use (&$fixtureRoot): void {
    fixtureLanguage(requireFixtureRoot($fixtureRoot), 'xx_ZZ', 'Test Language');

    expect(extensionUpdateChecker()->getPendingUpdates(ExtensionType::Language))->toBeNull();
});

test('getMergedExtensions always returns an empty list -- the fork-safe PEM domain never resolves', function (): void {
    $checker = extensionUpdateChecker();

    // Two very different $version inputs: the request itself never sends
    // $version at all (only used to filter a response body that's never
    // reached), so both must come back identically empty.
    expect($checker->getMergedExtensions('16.3.0'))
        ->toBe([])
        ->and($checker->getMergedExtensions('1.0.0'))
        ->toBe([]);
});

test('getMissingExtensions excludes a bundled default plugin id but flags a genuine custom plugin as missing', function () use (&$fixtureRoot): void {
    fixturePlugin(requireFixtureRoot($fixtureRoot), 'LocalFilesEditor', '3.1.4', '105', 'Local Files Editor');
    fixturePlugin(requireFixtureRoot($fixtureRoot), 'zzz_custom_plugin', '0.9.2', '777', 'Custom Gallery Widget');

    $missing = extensionUpdateChecker()
        ->getMissingExtensions('16.3.0');

    expect($missing)
        ->toHaveKey('plugin')
        ->and($missing['plugin'])->toHaveCount(1)
        ->and($missing['plugin'][0]['name'])->toBe('Custom Gallery Widget')
        ->and($missing['plugin'][0]['version'])->toBe('0.9.2')
        ->and($missing['plugin'][0]['extension'])->toBe('777');
});

test('getMissingExtensions never reports a language type at all -- language entries carry no PEM extension id', function () use (&$fixtureRoot): void {
    fixtureLanguage(requireFixtureRoot($fixtureRoot), 'xx_ZZ', 'Test Language');

    $missing = extensionUpdateChecker()
        ->getMissingExtensions('16.3.0');

    // ExtensionScanner::scanLanguage() never sets an 'extension' key (a
    // language's 'uri' is always ''), so getMissingExtensions()'s own
    // fsExtensionIds-empty guard skips the whole type via `continue` --
    // not merely "no language happens to be missing", the key is absent
    // entirely.
    expect($missing)
        ->not->toHaveKey('language');
});

test('getMissingExtensions reports no missing plugin at all when every installed plugin is a bundled default', function () use (&$fixtureRoot): void {
    fixturePlugin(requireFixtureRoot($fixtureRoot), 'LocalFilesEditor', '3.1.4', '105', 'Local Files Editor');
    fixturePlugin(requireFixtureRoot($fixtureRoot), 'AdminTools', '2.0.0', '106', 'Admin Tools');

    $missing = extensionUpdateChecker()
        ->getMissingExtensions('16.3.0');

    expect($missing)
        ->toBe([]);
});

test('getMissingExtensions keeps scanning later types after skipping an earlier type with no PEM-linked extensions', function () use (&$fixtureRoot): void {
    // No plugin fixture at all -- Plugin's own fsExtensionIds ends up []
    // (nothing to scan in the empty plugins/ dir from beforeEach()),
    // hitting the "no PEM ids for this type at all" guard first, since
    // Plugin is ExtensionType::cases()'s first case.
    fixtureTheme(requireFixtureRoot($fixtureRoot), 'custom_theme', '1.0.0', '888', 'Custom Theme');

    $missing = extensionUpdateChecker()
        ->getMissingExtensions('16.3.0');

    // Only reached if skipping 'plugin' actually moves on to 'theme'
    // instead of aborting the whole ExtensionType::cases() loop outright.
    expect($missing)
        ->toHaveKey('theme')
        ->and($missing['theme'])->toHaveCount(1)
        ->and($missing['theme'][0]['name'])->toBe('Custom Theme')
        ->and($missing['theme'][0]['extension'])->toBe('888');
});

test('checkUpdatedExtensions is a no-op and never re-triggers checkExtensions when the session has no pending-update record', function (): void {
    $_SESSION = [
        'unrelated_key' => 'sentinel_value',
    ];

    extensionUpdateChecker()
        ->checkUpdatedExtensions();

    // checkExtensions() unconditionally starts by setting
    // $_SESSION['extensions_need_update'] = [] before doing (and DB-
    // writing) anything else -- asserting the session is byte-for-byte
    // unchanged proves it was never called, not just "didn't throw".
    expect($_SESSION)
        ->toBe([
            'unrelated_key' => 'sentinel_value',
        ]);
});

test('checkUpdatedExtensions does not re-trigger checkExtensions while the installed plugin has not yet caught up', function () use (&$fixtureRoot): void {
    fixturePlugin(requireFixtureRoot($fixtureRoot), 'zzz_custom_plugin', '0.9.2', '777', 'Custom Gallery Widget');
    $_SESSION['extensions_need_update'] = [
        'plugin' => [
            'zzz_custom_plugin' => '5.0.0',
        ],
    ];

    extensionUpdateChecker()
        ->checkUpdatedExtensions();

    // 0.9.2 >= 5.0.0 is false, so the fs scan never finds a caught-up
    // entry -- checkExtensions() (and its real DB write) must never run,
    // leaving the recorded pending-update session data untouched.
    expect($_SESSION['extensions_need_update'])->toBe([
        'plugin' => [
            'zzz_custom_plugin' => '5.0.0',
        ],
    ]);
});

test('checkUpdatedExtensions re-triggers checkExtensions once the installed plugin has caught up', function () use (&$fixtureRoot): void {
    fixturePlugin(requireFixtureRoot($fixtureRoot), 'zzz_custom_plugin', '5.0.0', '777', 'Custom Gallery Widget');
    $_SESSION['extensions_need_update'] = [
        'plugin' => [
            'zzz_custom_plugin' => '0.9.2',
        ],
    ];

    extensionUpdateChecker()
        ->checkUpdatedExtensions();

    // 5.0.0 >= 0.9.2 is true, so the fs scan finds the installed plugin
    // has caught up -- checkExtensions() itself runs. Its own DB-touching
    // branch is never reached here (getPendingUpdates() always returns
    // null in this fork, see this file's own top-of-file docblock), but
    // it still unconditionally resets $_SESSION['extensions_need_update']
    // to [] before returning -- the only observable proof it actually ran,
    // as opposed to the previous test's untouched non-empty session data.
    expect($_SESSION['extensions_need_update'])->toBe([]);
});

test('checkUpdatedExtensions keeps checking later extension types after skipping one with no pending-update record', function () use (&$fixtureRoot): void {
    fixtureLanguage(requireFixtureRoot($fixtureRoot), 'xx_ZZ', 'Test Language');
    // No 'plugin'/'theme' entry in the session record at all -- both hit
    // the guard's `continue` (no fs scan ever happens for either) before
    // the loop reaches 'language', the only type with a real
    // pending-update record below and the last of ExtensionType::cases()'s
    // 3 cases.
    $_SESSION['extensions_need_update'] = [
        'language' => [
            'xx_ZZ' => '0',
        ],
    ];

    extensionUpdateChecker()
        ->checkUpdatedExtensions();

    // scanLanguage() always reports 'version' => AppInfo::VERSION (a
    // bundled language has no independent version of its own), which is
    // always >= '0' -- so 'language' is the type whose scan actually
    // triggers checkExtensions(), proven the same way as the test above.
    // If skipping 'plugin' ever aborted the whole loop instead of moving
    // on to 'theme' then 'language', this never runs and the session stays
    // untouched.
    expect($_SESSION['extensions_need_update'])->toBe([]);
});
