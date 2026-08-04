<?php

declare(strict_types=1);

use Piwigo\Admin\Extensions\ExtensionLifecycle;
use Piwigo\Admin\Extensions\ExtensionRepository;
use Piwigo\Admin\Extensions\PemCatalog;
use Piwigo\Admin\Extensions\PluginMigrationEntity;
use Piwigo\Admin\Extensions\PluginMigrationRepository;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Admin\ThemesInstalledPageRenderer;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentPaths;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\Kernel;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Html\HtmlService;
use Piwigo\Url\UrlService;

/**
 * ThemesInstalledPageRenderer::render() itself needs a real DB connection,
 * template engine, and at least a second real theme on disk to exercise its
 * per-theme loop body meaningfully (see tests/Browser/ThemesInstalledPageRendererTest.php
 * for that coverage, and its own docblock for why writing a second theme
 * under the live, Apache-shared themes/ root is out of scope) -- but its
 * own compareThemes() is pure array-math with no DB/template/global state,
 * directly testable in isolation via reflection, the same pattern
 * tests/Unit/Admin/CatModifyPageRendererTest.php uses for getMinLocalDir().
 *
 * render()'s own per-theme row-building logic (state/is-default/
 * deactivation eligibility/activable/missing-parent/deletable -- the real
 * gap this file's own second half below closes) was extracted out of the
 * foreach body into buildTplTheme() for the exact same reason: it's
 * reachable via reflection with plain $db_theme_ids/$default_theme inputs
 * plus a REAL ExtensionLifecycle (its missingParentTheme()/
 * getChildrenThemes() are filesystem-only, no DB query either ever
 * reaches), so this never needs the full render()/DB/template stack.
 * ExtensionLifecycle is built with a real-but-never-queried Doctrine
 * connection/repo (Doctrine DBAL connections are lazy -- never open a
 * socket until a query actually runs), the same "lazy connection, never
 * actually queried by the methods under test" reasoning
 * tests/Unit/Admin/Extensions/ExtensionUpdateCheckerTest.php's own
 * extensionUpdateChecker() helper already documents. Real fixture theme
 * directories are written under a disposable sys_get_temp_dir() root
 * (CurrentConfig::setThemesDir() pointed at it) rather than the live,
 * git-tracked themes/ root -- same reasoning as
 * ExtensionUpdateCheckerTest's own docblock: scanning the real theme
 * directory tree would also risk ExtensionScanner::scanTheme()'s hidden
 * PreferencesService DB fallback for any fixture dir missing a
 * screenshot.png, which every fixture theme written below avoids by
 * always including one.
 */
/**
 * @param  array<string, mixed>  $a
 * @param  array<string, mixed>  $b
 */
function callCompareThemes(array $a, array $b): int
{
    $method = new ReflectionMethod(ThemesInstalledPageRenderer::class, 'compareThemes');
    $instance = new ReflectionClass(ThemesInstalledPageRenderer::class)->newInstanceWithoutConstructor();

    /** @var int */
    return $method->invoke($instance, $a, $b);
}

test('the default theme always sorts first regardless of its own state or name', function (): void {
    $default = ['IS_DEFAULT' => true, 'STATE' => 'inactive', 'NAME' => 'zzz-theme'];
    $other = ['IS_DEFAULT' => false, 'STATE' => 'active', 'NAME' => 'aaa-theme'];

    expect(callCompareThemes($default, $other))->toBe(-1)
        ->and(callCompareThemes($other, $default))->toBe(1);
});

test('an active theme sorts before an inactive theme of the same non-default status', function (): void {
    $active = ['IS_DEFAULT' => false, 'STATE' => 'active', 'NAME' => 'zzz-theme'];
    $inactive = ['IS_DEFAULT' => false, 'STATE' => 'inactive', 'NAME' => 'aaa-theme'];

    expect(callCompareThemes($active, $inactive))->toBe(-1)
        ->and(callCompareThemes($inactive, $active))->toBe(1);
});

test('same-state themes tie-break on a case-insensitive name comparison', function (): void {
    $lower = ['IS_DEFAULT' => false, 'STATE' => 'active', 'NAME' => 'alpha'];
    $upperLater = ['IS_DEFAULT' => false, 'STATE' => 'active', 'NAME' => 'BETA'];

    expect(callCompareThemes($lower, $upperLater))->toBeLessThan(0)
        ->and(callCompareThemes($upperLater, $lower))->toBeGreaterThan(0);
});

test('identical state and name compare equal', function (): void {
    $a = ['IS_DEFAULT' => false, 'STATE' => 'active', 'NAME' => 'same-name'];
    $b = ['IS_DEFAULT' => false, 'STATE' => 'active', 'NAME' => 'same-name'];

    expect(callCompareThemes($a, $b))->toBe(0);
});

test('a theme with no IS_DEFAULT key at all on the $a side is treated as not default', function (): void {
    // Every other test explicitly sets IS_DEFAULT => false -- distinct
    // from a genuinely absent key, which also falls back to false via the
    // same `?? false`.
    $noKeyA = ['STATE' => 'active', 'NAME' => 'aaa'];
    $withDefault = ['IS_DEFAULT' => true, 'STATE' => 'inactive', 'NAME' => 'zzz'];

    expect(callCompareThemes($noKeyA, $withDefault))->toBe(1);
});

test('a theme with no IS_DEFAULT key at all on the $b side is treated as not default', function (): void {
    // $a must clear its own IS_DEFAULT check first (real, not a
    // short-circuit) for this to reach $b's own fallback.
    $active = ['IS_DEFAULT' => false, 'STATE' => 'active', 'NAME' => 'aaa'];
    $noKeyB = ['STATE' => 'inactive', 'NAME' => 'zzz'];

    expect(callCompareThemes($active, $noKeyB))->toBe(-1);
});

test('an unrecognized STATE value falls back to the same sort weight as inactive', function (): void {
    // $s only maps 'active'=>0/'inactive'=>1; any other string (or a
    // missing key entirely) reads through the `?? 1` fallback -- confirmed
    // by direct read of compareThemes()'s own $s lookup.
    $unrecognized = ['IS_DEFAULT' => false, 'STATE' => 'quarantined', 'NAME' => 'zzz'];
    $inactive = ['IS_DEFAULT' => false, 'STATE' => 'inactive', 'NAME' => 'aaa'];

    // 'quarantined' !== 'inactive' as strings, so this does NOT take the
    // strcasecmp() name tie-break (that requires $a_state === $b_state
    // exactly) -- it falls through to the final weight comparison, where
    // both sides resolve to 1 and `1 >= 1` deterministically returns 1
    // regardless of either NAME.
    expect(callCompareThemes($unrecognized, $inactive))->toBeGreaterThan(0);
});

test('an active theme sorts before a same-weight-1 unrecognized-state theme, not the reverse', function (): void {
    // Distinguishes the real $s[$b_state]??1 lookup from a hardcoded
    // literal 1: $b's real weight here (0, 'active') differs from its own
    // fallback (1), which only shows up when $a's weight is the boundary
    // value 1 itself... except that's 'inactive'/unrecognized, both of
    // which map to 1 -- so this specific direction (active vs
    // unrecognized) is what proves $b_state's fallback participates in the
    // comparison at all, as opposed to some unrelated defect.
    $active = ['IS_DEFAULT' => false, 'STATE' => 'active', 'NAME' => 'x'];
    $unrecognized = ['IS_DEFAULT' => false, 'STATE' => 'quarantined', 'NAME' => 'y'];

    expect(callCompareThemes($active, $unrecognized))->toBe(-1);
});

test('an inactive theme and a same-weight-1 unrecognized-state theme deterministically sort inactive second', function (): void {
    $inactive = ['IS_DEFAULT' => false, 'STATE' => 'inactive', 'NAME' => 'x'];
    $unrecognized = ['IS_DEFAULT' => false, 'STATE' => 'quarantined', 'NAME' => 'y'];

    expect(callCompareThemes($inactive, $unrecognized))->toBe(1);
});

test('a missing STATE key on both sides is treated as the empty string, not a crash', function (): void {
    $a = ['IS_DEFAULT' => false, 'NAME' => 'aaa'];
    $b = ['IS_DEFAULT' => false, 'NAME' => 'zzz'];

    expect(callCompareThemes($a, $b))->toBeLessThan(0);
});

test('a non-string NAME on either side is treated as the empty string, not a crash', function (): void {
    $nonStringName = ['IS_DEFAULT' => false, 'STATE' => 'active', 'NAME' => ['not-a-string']];
    $realName = ['IS_DEFAULT' => false, 'STATE' => 'active', 'NAME' => 'aaa'];

    expect(callCompareThemes($nonStringName, $realName))->toBeLessThan(0)
        ->and(callCompareThemes($realName, $nonStringName))->toBeGreaterThan(0);
});

// ---------------------------------------------------------------------
// buildTplTheme() -- real gap: active state/is-default/deactivation
// eligibility, activable detection, missing-parent-theme detection, and
// delete eligibility (see this file's own top-of-file docblock for the
// harness rationale).
// ---------------------------------------------------------------------

/**
 * @param  array<string, mixed>  $fs_theme
 * @param  list<string>  $db_theme_ids
 * @return array<string, mixed>
 */
function callBuildTplTheme(string $theme_id, array $fs_theme, array $db_theme_ids, string $default_theme, ExtensionLifecycle $lifecycle): array
{
    $method = new ReflectionMethod(ThemesInstalledPageRenderer::class, 'buildTplTheme');
    $instance = new ReflectionClass(ThemesInstalledPageRenderer::class)->newInstanceWithoutConstructor();

    /** @var array<string, mixed> */
    return $method->invoke($instance, $theme_id, $fs_theme, $db_theme_ids, $default_theme, $lifecycle);
}

/**
 * Minimal fs_theme entry matching ExtensionScanner::scanTheme()'s own
 * guaranteed-key shape (id/name/version/uri/description/author/mobile/
 * screenshot always present, see that method's own docblock) -- every
 * buildTplTheme() read below stays within that documented contract.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function fsThemeEntry(array $overrides = []): array
{
    return array_merge([
        'name' => 'Test Theme',
        'version' => '1.0',
        'uri' => '',
        'description' => '',
        'author' => '',
        'mobile' => false,
        'screenshot' => '',
    ], $overrides);
}

/**
 * Same "lazy DBAL connection, never actually queried" reasoning as
 * ExtensionUpdateCheckerTest's own extensionUpdateChecker() helper --
 * ExtensionRepository/ConfigService/PluginMigrationRepository only satisfy
 * ExtensionLifecycle's constructor type here, never exercised by
 * missingParentTheme()/getChildrenThemes().
 */
function themesInstalledLifecycle(): ExtensionLifecycle
{
    $conn = DbConnection::build();
    $repo = new ExtensionRepository(EntityManagerFactory::build($conn));
    $configRepo = EntityManagerFactory::build($conn)->getRepository(ConfigEntry::class);
    $pluginMigrationRepo = EntityManagerFactory::build($conn)->getRepository(PluginMigrationEntity::class);
    expect($pluginMigrationRepo)->toBeInstanceOf(PluginMigrationRepository::class);

    $currentLogger = new \Piwigo\Core\CurrentLogger();
    $currentLogger->set(new \Piwigo\Core\Logger(['severity' => \Piwigo\Core\Logger::OFF]));

    return new ExtensionLifecycle($repo, new PemCatalog(new ZipExtractor(), $currentLogger), new UrlService(new HtmlService(), new \Piwigo\Url\RootPathOverride()), new ConfigService($configRepo, new \Piwigo\PluginConfig\EventDispatcher()), $pluginMigrationRepo);
}

/**
 * Writes a real themes/<id>/themeconf.inc.php (+ a screenshot.png stub,
 * see this file's own top-of-file docblock for why) under the disposable
 * fixture root -- same file shape as
 * tests/Integration/ExtensionLifecycleTest.php's own writeThemeConf(),
 * just rooted at a throwaway temp dir instead of the live themes/ tree.
 *
 * @param  array{name?: string, parent?: string}  $conf
 */
function writeThemesInstalledFixtureTheme(string $fixtureRoot, string $id, array $conf = []): void
{
    $dir = $fixtureRoot . 'themes/' . $id;
    mkdir($dir, 0o777, true);
    $name = $conf['name'] ?? $id;
    $lines = "<?php\n/*\nTheme Name: {$name}\nVersion: 1.0\n*/\n";
    if (isset($conf['parent'])) {
        $lines .= "\$theme_conf['parent'] = '{$conf['parent']}';\n";
    }
    file_put_contents($dir . '/themeconf.inc.php', $lines);
    file_put_contents($dir . '/screenshot.png', 'x');
}

$themesInstalledFixtureRoot = null;

beforeEach(function () use (&$themesInstalledFixtureRoot): void {
    CurrentConfig::reset();
    $themesInstalledFixtureRoot = sys_get_temp_dir() . '/piwigo-themes-installed-page-renderer-test-' . bin2hex(random_bytes(4)) . '/';
    mkdir($themesInstalledFixtureRoot . 'themes', 0o777, true);
    Kernel::boot(Paths::fromRoot($themesInstalledFixtureRoot));
    CurrentConfig::setThemesDir(rtrim($themesInstalledFixtureRoot, '/') . '/themes');
});

afterEach(function () use (&$themesInstalledFixtureRoot): void {
    CurrentConfig::reset();
    Kernel::reset();
    if (is_string($themesInstalledFixtureRoot) && is_dir($themesInstalledFixtureRoot)) {
        FilesystemHelper::deltree($themesInstalledFixtureRoot);
    }
    $themesInstalledFixtureRoot = null;
});

test('buildTplTheme maps every fs_theme field to its own distinct template key', function (): void {
    // Every key below uses a distinct, non-symmetric value so that a
    // dropped key, a swapped key, or a coalesced-to-null optional field is
    // independently observable.
    $tpl = callBuildTplTheme('theme-a', fsThemeEntry([
        'name' => 'A Real Theme',
        'uri' => 'https://example.test/theme',
        'version' => '2.5.1',
        'description' => 'A real description',
        'author' => 'Real Author',
        'author uri' => 'https://example.test/author',
        'parent' => 'a-real-parent',
        'screenshot' => '/path/to/screenshot.png',
        'mobile' => true,
        'admin_uri' => '/admin/theme-a',
    ]), [], 'default', themesInstalledLifecycle());

    expect($tpl['ID'])->toBe('theme-a')
        ->and($tpl['NAME'])->toBe('A Real Theme')
        ->and($tpl['VISIT_URL'])->toBe('https://example.test/theme')
        ->and($tpl['VERSION'])->toBe('2.5.1')
        ->and($tpl['DESC'])->toBe('A real description')
        ->and($tpl['AUTHOR'])->toBe('Real Author')
        ->and($tpl['AUTHOR_URL'])->toBe('https://example.test/author')
        ->and($tpl['PARENT'])->toBe('a-real-parent')
        ->and($tpl['SCREENSHOT'])->toBe('/path/to/screenshot.png')
        ->and($tpl['IS_MOBILE'])->toBeTrue()
        ->and($tpl['ADMIN_URI'])->toBe('/admin/theme-a');
});

test('buildTplTheme defaults author_uri/parent/admin_uri to null when absent from fs_theme', function (): void {
    $tpl = callBuildTplTheme('theme-a', fsThemeEntry(), [], 'default', themesInstalledLifecycle());

    expect($tpl['AUTHOR_URL'])->toBeNull()
        ->and($tpl['PARENT'])->toBeNull()
        ->and($tpl['ADMIN_URI'])->toBeNull();
});

// --- active state / is-default / deactivation eligibility ---

test('an active, non-default theme with other themes installed is deactivable with no tooltip', function (): void {
    $tpl = callBuildTplTheme('theme-a', fsThemeEntry(), ['theme-a', 'theme-b'], 'theme-b', themesInstalledLifecycle());

    expect($tpl['STATE'])->toBe('active')
        ->and($tpl['IS_DEFAULT'])->toBeFalse()
        ->and($tpl['DEACTIVABLE'])->toBeTrue()
        ->and($tpl)->not->toHaveKey('DEACTIVATE_TOOLTIP');
});

test('the default active theme is never deactivable, even with other themes installed', function (): void {
    $tpl = callBuildTplTheme('theme-a', fsThemeEntry(), ['theme-a', 'theme-b'], 'theme-a', themesInstalledLifecycle());

    expect($tpl['IS_DEFAULT'])->toBeTrue()
        ->and($tpl['DEACTIVABLE'])->toBeFalse()
        ->and($tpl['DEACTIVATE_TOOLTIP'])->toBe('Impossible to deactivate the default theme.');
});

test('the last remaining active theme is not deactivable even when it is not the default theme', function (): void {
    $tpl = callBuildTplTheme('theme-a', fsThemeEntry(), ['theme-a'], 'some-other-theme', themesInstalledLifecycle());

    expect($tpl['IS_DEFAULT'])->toBeFalse()
        ->and($tpl['DEACTIVABLE'])->toBeFalse()
        ->and($tpl['DEACTIVATE_TOOLTIP'])->toBe('Impossible to deactivate this theme, you need at least one theme.');
});

test('when a theme is both the last remaining theme and the default theme, the default-theme guard runs last and its tooltip wins', function (): void {
    // Both guards are unconditional `if`s (not `elseif`), evaluated in a
    // fixed order (count<=1 first, then IS_DEFAULT) -- confirmed by direct
    // read of buildTplTheme(). A theme that is both trips both, and the
    // IS_DEFAULT guard, running second, overwrites DEACTIVATE_TOOLTIP.
    $tpl = callBuildTplTheme('theme-a', fsThemeEntry(), ['theme-a'], 'theme-a', themesInstalledLifecycle());

    expect($tpl['DEACTIVABLE'])->toBeFalse()
        ->and($tpl['DEACTIVATE_TOOLTIP'])->toBe('Impossible to deactivate the default theme.');
});

// --- activable detection ---

test('a theme explicitly marked non-activable in its own metadata is not activable', function (): void {
    $tpl = callBuildTplTheme('theme-a', fsThemeEntry(['activable' => false]), [], 'default', themesInstalledLifecycle());

    expect($tpl['STATE'])->toBe('inactive')
        ->and($tpl['ACTIVABLE'])->toBeFalse()
        ->and($tpl['ACTIVABLE_TOOLTIP'])->toBe('This theme was not designed to be directly activated');
});

test('a theme with no activable flag at all is activable by default', function (): void {
    $tpl = callBuildTplTheme('theme-a', fsThemeEntry(), [], 'default', themesInstalledLifecycle());

    expect($tpl['ACTIVABLE'])->toBeTrue()
        ->and($tpl)->not->toHaveKey('ACTIVABLE_TOOLTIP');
});

test('a theme explicitly marked activable stays activable', function (): void {
    $tpl = callBuildTplTheme('theme-a', fsThemeEntry(['activable' => true]), [], 'default', themesInstalledLifecycle());

    expect($tpl['ACTIVABLE'])->toBeTrue()
        ->and($tpl)->not->toHaveKey('ACTIVABLE_TOOLTIP');
});

// --- missing-parent-theme detection ---

test('a theme whose declared parent is not installed on disk is not activable, with a tooltip naming the missing parent', function (): void {
    $tpl = callBuildTplTheme('child-theme', fsThemeEntry(['parent' => 'totally-missing-parent-xyz']), [], 'default', themesInstalledLifecycle());

    expect($tpl['ACTIVABLE'])->toBeFalse()
        ->and($tpl['ACTIVABLE_TOOLTIP'])->toContain('totally-missing-parent-xyz');
});

test('a missing-parent tooltip overrides an otherwise-activable theme even when no activable flag was set', function (): void {
    // ACTIVABLE starts true (no 'activable' key at all) via the first
    // branch, then the missingParentTheme() check below it overwrites both
    // ACTIVABLE and ACTIVABLE_TOOLTIP -- confirmed by direct read, this
    // proves the overwrite actually happens rather than being skipped
    // because ACTIVABLE was already true.
    $tpl = callBuildTplTheme('child-theme', fsThemeEntry(['activable' => true, 'parent' => 'totally-missing-parent-xyz']), [], 'default', themesInstalledLifecycle());

    expect($tpl['ACTIVABLE'])->toBeFalse()
        ->and($tpl['ACTIVABLE_TOOLTIP'])->toContain('totally-missing-parent-xyz');
});

test('a missing grandparent theme is surfaced through a real installed intermediate parent theme', function () use (&$themesInstalledFixtureRoot): void {
    // beforeEach() always sets this by-ref before any test body runs --
    // PHPStan just can't see across the closure boundary, same
    // by-ref-narrowing-loss pattern as elsewhere in this project.
    assert(is_string($themesInstalledFixtureRoot));
    writeThemesInstalledFixtureTheme($themesInstalledFixtureRoot, 'middle-theme', ['name' => 'Middle Theme', 'parent' => 'totally-missing-ancestor-xyz']);

    $tpl = callBuildTplTheme('child-theme', fsThemeEntry(['parent' => 'middle-theme']), [], 'default', themesInstalledLifecycle());

    expect($tpl['ACTIVABLE'])->toBeFalse()
        ->and($tpl['ACTIVABLE_TOOLTIP'])->toContain('totally-missing-ancestor-xyz')
        ->and($tpl['ACTIVABLE_TOOLTIP'])->not->toContain('middle-theme');
});

test('a theme whose declared parent is actually installed on disk remains activable', function () use (&$themesInstalledFixtureRoot): void {
    assert(is_string($themesInstalledFixtureRoot));
    writeThemesInstalledFixtureTheme($themesInstalledFixtureRoot, 'real-parent-theme', ['name' => 'Real Parent']);

    $tpl = callBuildTplTheme('child-theme', fsThemeEntry(['parent' => 'real-parent-theme']), [], 'default', themesInstalledLifecycle());

    expect($tpl['ACTIVABLE'])->toBeTrue()
        ->and($tpl)->not->toHaveKey('ACTIVABLE_TOOLTIP');
});

// --- delete eligibility ---

test('a theme with no children on disk is deletable', function (): void {
    $tpl = callBuildTplTheme('theme-a', fsThemeEntry(), [], 'default', themesInstalledLifecycle());

    expect($tpl['DELETABLE'])->toBeTrue()
        ->and($tpl)->not->toHaveKey('DELETE_TOOLTIP');
});

test('a theme with one real child theme depending on it is not deletable, and the tooltip names the child', function () use (&$themesInstalledFixtureRoot): void {
    assert(is_string($themesInstalledFixtureRoot));
    writeThemesInstalledFixtureTheme($themesInstalledFixtureRoot, 'child-one', ['name' => 'Child One', 'parent' => 'theme-a']);

    $tpl = callBuildTplTheme('theme-a', fsThemeEntry(), [], 'default', themesInstalledLifecycle());

    expect($tpl['DELETABLE'])->toBeFalse()
        ->and($tpl['DELETE_TOOLTIP'])->toContain('Child One');
});

test('a theme with multiple real child themes lists every dependent name in the tooltip', function () use (&$themesInstalledFixtureRoot): void {
    assert(is_string($themesInstalledFixtureRoot));
    writeThemesInstalledFixtureTheme($themesInstalledFixtureRoot, 'child-one', ['name' => 'Child One', 'parent' => 'theme-a']);
    writeThemesInstalledFixtureTheme($themesInstalledFixtureRoot, 'child-two', ['name' => 'Child Two', 'parent' => 'theme-a']);

    $tpl = callBuildTplTheme('theme-a', fsThemeEntry(), [], 'default', themesInstalledLifecycle());

    expect($tpl['DELETABLE'])->toBeFalse()
        ->and($tpl['DELETE_TOOLTIP'])->toContain('Child One')
        ->and($tpl['DELETE_TOOLTIP'])->toContain('Child Two');
});

test('an unrelated real theme on disk (not a child) does not block deletion', function () use (&$themesInstalledFixtureRoot): void {
    assert(is_string($themesInstalledFixtureRoot));
    writeThemesInstalledFixtureTheme($themesInstalledFixtureRoot, 'unrelated-theme', ['name' => 'Unrelated Theme', 'parent' => 'some-other-theme']);

    $tpl = callBuildTplTheme('theme-a', fsThemeEntry(), [], 'default', themesInstalledLifecycle());

    expect($tpl['DELETABLE'])->toBeTrue()
        ->and($tpl)->not->toHaveKey('DELETE_TOOLTIP');
});
