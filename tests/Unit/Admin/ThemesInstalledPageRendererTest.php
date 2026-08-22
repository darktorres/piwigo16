<?php

declare(strict_types=1);

use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\Extensions\ExtensionLifecycle;
use Piwigo\Admin\Extensions\ExtensionRepository;
use Piwigo\Admin\Extensions\PemCatalog;
use Piwigo\Admin\Extensions\Projection\ThemeScanRow;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Admin\Projection\ThemeListRow;
use Piwigo\Admin\ThemesInstalledPageRenderer;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Auth\PasswordRepository;
use Piwigo\Auth\PasswordService;
use Piwigo\Cache\CacheFactory;
use Piwigo\Cache\TranslationsCachePool;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\FilterState;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Kernel;
use Piwigo\Core\Logger;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Group\GroupEntity;
use Piwigo\Lang\Translator;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\PluginConfig\PluginRegistry;
use Piwigo\PluginConfig\ThemeRegistry;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionService;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentPathsTestFactory;
use Piwigo\Tests\Support\CurrentUserTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\LangTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;

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
function callCompareThemes(ThemeListRow $a, ThemeListRow $b): int
{
    $method = new ReflectionMethod(ThemesInstalledPageRenderer::class, 'compareThemes');
    $instance = new ReflectionClass(ThemesInstalledPageRenderer::class)->newInstanceWithoutConstructor();

    /** @var int */
    return $method->invoke($instance, $a, $b);
}

/**
 * Minimal ThemeListRow builder for compareThemes()'s own tests -- only
 * isDefault/state/name affect its comparison, every other field is
 * irrelevant filler.
 */
function themeListRowForCompare(bool $isDefault, string $state, string $name): ThemeListRow
{
    return new ThemeListRow(
        id: 'x',
        name: $name,
        visitUrl: '',
        version: '',
        desc: '',
        author: '',
        authorUrl: null,
        isMobile: false,
        screenshot: '',
        adminUri: null,
        state: $state,
        isDefault: $isDefault,
        deactivable: false,
        activable: false,
        activableTooltip: null,
        deletable: false,
        deleteTooltip: null,
    );
}

// compareThemes() used to be tested with plain arrays, including several
// cases for a missing IS_DEFAULT/STATE key or a non-string NAME -- now
// that $tpl_theme is a real ThemeListRow (isDefault: bool, state: string,
// name: string, all non-nullable), those specific input shapes are no
// longer constructible at all, so that coverage is retired rather than
// carried forward as dead code.

test('the default theme always sorts first regardless of its own state or name', function (): void {
    $default = themeListRowForCompare(isDefault: true, state: 'inactive', name: 'zzz-theme');
    $other = themeListRowForCompare(isDefault: false, state: 'active', name: 'aaa-theme');

    expect(callCompareThemes($default, $other))
        ->toBe(-1)
        ->and(callCompareThemes($other, $default))
        ->toBe(1);
});

test('an active theme sorts before an inactive theme of the same non-default status', function (): void {
    $active = themeListRowForCompare(isDefault: false, state: 'active', name: 'zzz-theme');
    $inactive = themeListRowForCompare(isDefault: false, state: 'inactive', name: 'aaa-theme');

    expect(callCompareThemes($active, $inactive))
        ->toBe(-1)
        ->and(callCompareThemes($inactive, $active))
        ->toBe(1);
});

test('same-state themes tie-break on a case-insensitive name comparison', function (): void {
    $lower = themeListRowForCompare(isDefault: false, state: 'active', name: 'alpha');
    $upperLater = themeListRowForCompare(isDefault: false, state: 'active', name: 'BETA');

    expect(callCompareThemes($lower, $upperLater))
        ->toBeLessThan(0)
        ->and(callCompareThemes($upperLater, $lower))
        ->toBeGreaterThan(0);
});

test('identical state and name compare equal', function (): void {
    $a = themeListRowForCompare(isDefault: false, state: 'active', name: 'same-name');
    $b = themeListRowForCompare(isDefault: false, state: 'active', name: 'same-name');

    expect(callCompareThemes($a, $b))
        ->toBe(0);
});

test('an unrecognized STATE value falls back to the same sort weight as inactive', function (): void {
    // $s only maps 'active'=>0/'inactive'=>1; any other string reads
    // through the `?? 1` fallback -- confirmed by direct read of
    // compareThemes()'s own $s lookup.
    $unrecognized = themeListRowForCompare(isDefault: false, state: 'quarantined', name: 'zzz');
    $inactive = themeListRowForCompare(isDefault: false, state: 'inactive', name: 'aaa');

    // 'quarantined' !== 'inactive' as strings, so this does NOT take the
    // strcasecmp() name tie-break (that requires $a_state === $b_state
    // exactly) -- it falls through to the final weight comparison, where
    // both sides resolve to 1 and `1 >= 1` deterministically returns 1
    // regardless of either NAME.
    expect(callCompareThemes($unrecognized, $inactive))
        ->toBeGreaterThan(0);
});

test('an active theme sorts before a same-weight-1 unrecognized-state theme, not the reverse', function (): void {
    // Distinguishes the real $s[$b_state]??1 lookup from a hardcoded
    // literal 1: $b's real weight here (0, 'active') differs from its own
    // fallback (1), which only shows up when $a's weight is the boundary
    // value 1 itself... except that's 'inactive'/unrecognized, both of
    // which map to 1 -- so this specific direction (active vs
    // unrecognized) is what proves $b_state's fallback participates in the
    // comparison at all, as opposed to some unrelated defect.
    $active = themeListRowForCompare(isDefault: false, state: 'active', name: 'x');
    $unrecognized = themeListRowForCompare(isDefault: false, state: 'quarantined', name: 'y');

    expect(callCompareThemes($active, $unrecognized))
        ->toBe(-1);
});

test('an inactive theme and a same-weight-1 unrecognized-state theme deterministically sort inactive second', function (): void {
    $inactive = themeListRowForCompare(isDefault: false, state: 'inactive', name: 'x');
    $unrecognized = themeListRowForCompare(isDefault: false, state: 'quarantined', name: 'y');

    expect(callCompareThemes($inactive, $unrecognized))
        ->toBe(1);
});

// ---------------------------------------------------------------------
// buildTplTheme() -- real gap: active state/is-default/deactivation
// eligibility, activable detection, missing-parent-theme detection, and
// delete eligibility (see this file's own top-of-file docblock for the
// harness rationale).
// ---------------------------------------------------------------------

/**
 * @param  list<string>  $db_theme_ids
 */
function callBuildTplTheme(string $theme_id, ThemeScanRow $fs_theme, array $db_theme_ids, string $default_theme, ExtensionLifecycle $lifecycle): ThemeListRow
{
    $method = new ReflectionMethod(ThemesInstalledPageRenderer::class, 'buildTplTheme');
    $instance = new ReflectionClass(ThemesInstalledPageRenderer::class)->newInstanceWithoutConstructor();

    // buildTplTheme() reads $this->lang->t() directly (deactivate/activable/
    // missing-parent/delete tooltips) -- newInstanceWithoutConstructor()
    // never runs the constructor, so the readonly $lang property needs to be
    // hand-set via reflection the same way NotificationByMailSubControllerTest's
    // own nbmSubReflectSender() sets NotificationByMailSender's private
    // properties. Kernel is booted by this file's own beforeEach() before any
    // test body runs, so LangTestFactory::get() is a real, live container resolve.
    $langProp = new ReflectionProperty(ThemesInstalledPageRenderer::class, 'lang');
    $langProp->setValue($instance, LangTestFactory::get());

    /** @var ThemeListRow */
    return $method->invoke($instance, $theme_id, $fs_theme, $db_theme_ids, $default_theme, $lifecycle);
}

/**
 * Minimal fs_theme entry matching ExtensionScanner::scanTheme()'s own
 * guaranteed-field shape (id/name/version/uri/description/author/mobile/
 * screenshot always present, see that method's own docblock) -- every
 * buildTplTheme() read below stays within that documented contract.
 * $overrides keys match ThemeScanRow's own constructor parameter names
 * ('author uri' is the one exception, kept as the array-era key here
 * purely so every call site below didn't need touching for the
 * array-to-object conversion).
 *
 * @param  array<string, mixed>  $overrides
 */
function fsThemeEntry(array $overrides = []): ThemeScanRow
{
    $authorUri = $overrides['author uri'] ?? $overrides['authorUri'] ?? null;
    $useStandardPages = $overrides['use_standard_pages'] ?? $overrides['useStandardPages'] ?? null;
    $adminUri = $overrides['admin_uri'] ?? $overrides['adminUri'] ?? null;

    return new ThemeScanRow(
        id: is_string($overrides['id'] ?? null) ? $overrides['id'] : 'test-theme',
        name: is_string($overrides['name'] ?? null) ? $overrides['name'] : 'Test Theme',
        version: is_string($overrides['version'] ?? null) ? $overrides['version'] : '1.0',
        uri: is_string($overrides['uri'] ?? null) ? $overrides['uri'] : '',
        description: is_string($overrides['description'] ?? null) ? $overrides['description'] : '',
        author: is_string($overrides['author'] ?? null) ? $overrides['author'] : '',
        mobile: (bool) ($overrides['mobile'] ?? false),
        screenshot: is_string($overrides['screenshot'] ?? null) ? $overrides['screenshot'] : '',
        authorUri: is_string($authorUri) ? $authorUri : null,
        extension: is_string($overrides['extension'] ?? null) ? $overrides['extension'] : null,
        parent: is_string($overrides['parent'] ?? null) ? $overrides['parent'] : null,
        activable: is_bool($overrides['activable'] ?? null) ? $overrides['activable'] : null,
        useStandardPages: is_bool($useStandardPages) ? $useStandardPages : null,
        adminUri: is_string($adminUri) ? $adminUri : null,
    );
}

/**
 * Same "lazy DBAL connection, never actually queried" reasoning as
 * ExtensionUpdateCheckerTest's own extensionUpdateChecker() helper --
 * ExtensionRepository/ConfigService only satisfy ExtensionLifecycle's
 * constructor type here, never exercised by
 * missingParentTheme()/getChildrenThemes(). CurrentConfig/CurrentUser ARE
 * exercised by those 2 methods, which thread $this->currentConfig/
 * $this->currentUser into ExtensionScanner::scan()'s own params -- both
 * must be the real, shared CurrentConfigTestFactory::get()/
 * CurrentUserTestFactory::get() instances (not a fresh, disconnected one)
 * so scan()'s directory lookup actually sees this file's own
 * beforeEach()-configured fixture setThemesDir(), not CurrentConfig's own
 * unrelated default: a fresh CurrentConfig here would silently point
 * scan() at the real project themes/ directory instead of the disposable
 * fixture root.
 */
function themesInstalledLifecycle(): ExtensionLifecycle
{
    $conn = DbConnection::build();
    $repo = new ExtensionRepository(EntityManagerFactory::build($conn));
    $configRepo = EntityManagerFactory::build($conn)->getRepository(ConfigEntry::class);

    $currentLogger = new CurrentLogger();
    $currentLogger->set(new Logger([
        'severity' => Logger::OFF,
    ]));

    $activityRepo = EntityManagerFactory::build($conn)->getRepository(ActivityEntity::class);

    $pluginRegistry = Kernel::container()->get(PluginRegistry::class);
    $themeRegistry = Kernel::container()->get(ThemeRegistry::class);
    if (! $pluginRegistry instanceof PluginRegistry || ! $themeRegistry instanceof ThemeRegistry) {
        throw new LogicException('Container returned an unexpected type');
    }

    return new ExtensionLifecycle(LangTestFactory::get(), $repo, new PemCatalog(new ZipExtractor(), $currentLogger, CurrentPathsTestFactory::get(), new CurrentConfig()), UrlServiceTestFactory::build(), new ConfigService($configRepo, new CurrentConfig()), new ActivityService($activityRepo), themesInstalledLifecycleUserService(), HtmlServiceTestFactory::build(), CurrentConfigTestFactory::get(), CurrentPathsTestFactory::get(), CurrentUserTestFactory::get(), new EventDispatcher(), $pluginRegistry, $themeRegistry, EntityManagerFactory::build($conn));
}

/**
 * Same "lazy DBAL connection, never actually queried" reasoning as
 * themesInstalledLifecycle() itself -- ExtensionLifecycle's
 * missingParentTheme()/getChildrenThemes() never touch UserService.
 */
function themesInstalledLifecycleUserService(): UserService
{
    $conn = DbConnection::build();
    $currentConfig = new CurrentConfig();
    $currentUser = new CurrentUser($currentConfig);
    $accessLevelChecker = new AccessLevelChecker($currentUser, $currentConfig);
    $permissionService = new PermissionService(new PermissionRepository(EntityManagerFactory::build($conn)), EntityManagerFactory::build($conn)->getRepository(GroupEntity::class), new CategoryRepository(EntityManagerFactory::build($conn), $currentConfig), $currentUser, new FilterState(), $accessLevelChecker);

    return new UserService(
        LangTestFactory::get(),
        new UserRepository(EntityManagerFactory::build($conn), new EventDispatcher(), $currentConfig),
        EntityManagerFactory::build($conn)->getRepository(GroupEntity::class),
        new ActivityService(EntityManagerFactory::build($conn)->getRepository(ActivityEntity::class)),
        HtmlServiceTestFactory::build(),
        new SessionService(EntityManagerFactory::build($conn)->getRepository(SessionEntity::class), $currentConfig),
        new EventDispatcher(),
        new DeploymentPolicy(),
        $currentUser,
        $currentConfig,
        new InstallationFlag(),
        new ProcessCache(),
        CurrentPathsTestFactory::get(),
        EntityManagerFactory::build($conn),
        $permissionService,
        new CategoryService(LangTestFactory::get(), new CategoryRepository(EntityManagerFactory::build($conn), $currentConfig), $permissionService, $currentConfig, new EventDispatcher(), new Translator($currentConfig, new TranslationsCachePool(CacheFactory::create(namespace: 'piwigo.translations'))), $accessLevelChecker, new UserRepository(EntityManagerFactory::build($conn), new EventDispatcher(), $currentConfig)),
        new PasswordService(new PasswordRepository(EntityManagerFactory::build($conn)), new DeploymentPolicy()),
    );
}

/**
 * Writes a real themes/<id>/theme.json (+ a screenshot.png stub, see
 * this file's own top-of-file docblock for why) under the disposable
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
    $data = [
        'name' => $conf['name'] ?? $id,
        'version' => '1.0',
    ];
    if (isset($conf['parent'])) {
        $data['parent'] = $conf['parent'];
    }
    file_put_contents($dir . '/theme.json', json_encode($data, JSON_THROW_ON_ERROR));
    file_put_contents($dir . '/screenshot.png', 'x');
}

$themesInstalledFixtureRoot = null;

beforeEach(function () use (&$themesInstalledFixtureRoot): void {
    CurrentConfigTestFactory::get()->reset();
    $themesInstalledFixtureRoot = sys_get_temp_dir() . '/piwigo-themes-installed-page-renderer-test-' . bin2hex(random_bytes(4)) . '/';
    mkdir($themesInstalledFixtureRoot . 'themes', 0o777, true);
    Kernel::boot(Paths::fromRoot($themesInstalledFixtureRoot));
    CurrentConfigTestFactory::get()->themesDir = rtrim($themesInstalledFixtureRoot, '/') . '/themes';
});

afterEach(function () use (&$themesInstalledFixtureRoot): void {
    CurrentConfigTestFactory::get()->reset();
    Kernel::reset();
    if (is_string($themesInstalledFixtureRoot) && is_dir($themesInstalledFixtureRoot)) {
        FilesystemHelper::deltree($themesInstalledFixtureRoot);
    }
    $themesInstalledFixtureRoot = null;
});

test('buildTplTheme maps every fs_theme field to its own distinct template key', function (): void {
    // Every key below uses a distinct, non-symmetric value so that a
    // dropped key, a swapped key, or a coalesced-to-null optional field is
    // independently observable. 'parent' is set on the fs_theme input but
    // deliberately not asserted here: ThemeListRow drops PARENT entirely
    // (confirmed dead in themes_installed.latte's own real body).
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

    expect($tpl->id)
        ->toBe('theme-a')
        ->and($tpl->name)
        ->toBe('A Real Theme')
        ->and($tpl->visitUrl)
        ->toBe('https://example.test/theme')
        ->and($tpl->version)
        ->toBe('2.5.1')
        ->and($tpl->desc)
        ->toBe('A real description')
        ->and($tpl->author)
        ->toBe('Real Author')
        ->and($tpl->authorUrl)
        ->toBe('https://example.test/author')
        ->and($tpl->screenshot)
        ->toBe('/path/to/screenshot.png')
        ->and($tpl->isMobile)
        ->toBeTrue()
        ->and($tpl->adminUri)
        ->toBe('/admin/theme-a');
});

test('buildTplTheme defaults author_uri/admin_uri to null when absent from fs_theme', function (): void {
    $tpl = callBuildTplTheme('theme-a', fsThemeEntry(), [], 'default', themesInstalledLifecycle());

    expect($tpl->authorUrl)
        ->toBeNull()
        ->and($tpl->adminUri)
        ->toBeNull();
});

// --- active state / is-default / deactivation eligibility ---

test('an active, non-default theme with other themes installed is deactivable', function (): void {
    $tpl = callBuildTplTheme('theme-a', fsThemeEntry(), ['theme-a', 'theme-b'], 'theme-b', themesInstalledLifecycle());

    expect($tpl->state)
        ->toBe('active')
        ->and($tpl->isDefault)
        ->toBeFalse()
        ->and($tpl->deactivable)
        ->toBeTrue();
});

test('the default active theme is never deactivable, even with other themes installed', function (): void {
    $tpl = callBuildTplTheme('theme-a', fsThemeEntry(), ['theme-a', 'theme-b'], 'theme-a', themesInstalledLifecycle());

    expect($tpl->isDefault)
        ->toBeTrue()
        ->and($tpl->deactivable)
        ->toBeFalse();
});

test('the last remaining active theme is not deactivable even when it is not the default theme', function (): void {
    $tpl = callBuildTplTheme('theme-a', fsThemeEntry(), ['theme-a'], 'some-other-theme', themesInstalledLifecycle());

    expect($tpl->isDefault)
        ->toBeFalse()
        ->and($tpl->deactivable)
        ->toBeFalse();
});

test('when a theme is both the last remaining theme and the default theme, it is still not deactivable', function (): void {
    // Both guards are unconditional `if`s (not `elseif`), evaluated in a
    // fixed order (count<=1 first, then IS_DEFAULT) -- confirmed by direct
    // read of buildTplTheme(). A theme that is both trips both.
    $tpl = callBuildTplTheme('theme-a', fsThemeEntry(), ['theme-a'], 'theme-a', themesInstalledLifecycle());

    expect($tpl->deactivable)
        ->toBeFalse();
});

// --- activable detection ---

test('a theme explicitly marked non-activable in its own metadata is not activable', function (): void {
    $tpl = callBuildTplTheme('theme-a', fsThemeEntry([
        'activable' => false,
    ]), [], 'default', themesInstalledLifecycle());

    expect($tpl->state)
        ->toBe('inactive')
        ->and($tpl->activable)
        ->toBeFalse()
        ->and($tpl->activableTooltip)
        ->toBe('This theme was not designed to be directly activated');
});

test('a theme with no activable flag at all is activable by default', function (): void {
    $tpl = callBuildTplTheme('theme-a', fsThemeEntry(), [], 'default', themesInstalledLifecycle());

    expect($tpl->activable)
        ->toBeTrue()
        ->and($tpl->activableTooltip)
        ->toBeNull();
});

test('a theme explicitly marked activable stays activable', function (): void {
    $tpl = callBuildTplTheme('theme-a', fsThemeEntry([
        'activable' => true,
    ]), [], 'default', themesInstalledLifecycle());

    expect($tpl->activable)
        ->toBeTrue()
        ->and($tpl->activableTooltip)
        ->toBeNull();
});

// --- missing-parent-theme detection ---

test('a theme whose declared parent is not installed on disk is not activable, with a tooltip naming the missing parent', function (): void {
    $tpl = callBuildTplTheme('child-theme', fsThemeEntry([
        'parent' => 'totally-missing-parent-xyz',
    ]), [], 'default', themesInstalledLifecycle());

    expect($tpl->activable)
        ->toBeFalse()
        ->and($tpl->activableTooltip)
        ->toContain('totally-missing-parent-xyz');
});

test('a missing-parent tooltip overrides an otherwise-activable theme even when no activable flag was set', function (): void {
    // ACTIVABLE starts true (no 'activable' key at all) via the first
    // branch, then the missingParentTheme() check below it overwrites both
    // ACTIVABLE and ACTIVABLE_TOOLTIP -- confirmed by direct read, this
    // proves the overwrite actually happens rather than being skipped
    // because ACTIVABLE was already true.
    $tpl = callBuildTplTheme('child-theme', fsThemeEntry([
        'activable' => true,
        'parent' => 'totally-missing-parent-xyz',
    ]), [], 'default', themesInstalledLifecycle());

    expect($tpl->activable)
        ->toBeFalse()
        ->and($tpl->activableTooltip)
        ->toContain('totally-missing-parent-xyz');
});

test('a missing grandparent theme is surfaced through a real installed intermediate parent theme', function () use (&$themesInstalledFixtureRoot): void {
    // beforeEach() always sets this by-ref before any test body runs --
    // PHPStan just can't see across the closure boundary, same
    // by-ref-narrowing-loss pattern as elsewhere in this project.
    assert(is_string($themesInstalledFixtureRoot));
    writeThemesInstalledFixtureTheme($themesInstalledFixtureRoot, 'middle-theme', [
        'name' => 'Middle Theme',
        'parent' => 'totally-missing-ancestor-xyz',
    ]);

    $tpl = callBuildTplTheme('child-theme', fsThemeEntry([
        'parent' => 'middle-theme',
    ]), [], 'default', themesInstalledLifecycle());

    expect($tpl->activable)
        ->toBeFalse()
        ->and($tpl->activableTooltip)
        ->toContain('totally-missing-ancestor-xyz')
        ->and($tpl->activableTooltip)
        ->not->toContain('middle-theme');
});

test('a theme whose declared parent is actually installed on disk remains activable', function () use (&$themesInstalledFixtureRoot): void {
    assert(is_string($themesInstalledFixtureRoot));
    writeThemesInstalledFixtureTheme($themesInstalledFixtureRoot, 'real-parent-theme', [
        'name' => 'Real Parent',
    ]);

    $tpl = callBuildTplTheme('child-theme', fsThemeEntry([
        'parent' => 'real-parent-theme',
    ]), [], 'default', themesInstalledLifecycle());

    expect($tpl->activable)
        ->toBeTrue()
        ->and($tpl->activableTooltip)
        ->toBeNull();
});

// --- delete eligibility ---

test('a theme with no children on disk is deletable', function (): void {
    $tpl = callBuildTplTheme('theme-a', fsThemeEntry(), [], 'default', themesInstalledLifecycle());

    expect($tpl->deletable)
        ->toBeTrue()
        ->and($tpl->deleteTooltip)
        ->toBeNull();
});

test('a theme with one real child theme depending on it is not deletable, and the tooltip names the child', function () use (&$themesInstalledFixtureRoot): void {
    assert(is_string($themesInstalledFixtureRoot));
    writeThemesInstalledFixtureTheme($themesInstalledFixtureRoot, 'child-one', [
        'name' => 'Child One',
        'parent' => 'theme-a',
    ]);

    $tpl = callBuildTplTheme('theme-a', fsThemeEntry(), [], 'default', themesInstalledLifecycle());

    expect($tpl->deletable)
        ->toBeFalse()
        ->and($tpl->deleteTooltip)
        ->toContain('Child One');
});

test('a theme with multiple real child themes lists every dependent name in the tooltip', function () use (&$themesInstalledFixtureRoot): void {
    assert(is_string($themesInstalledFixtureRoot));
    writeThemesInstalledFixtureTheme($themesInstalledFixtureRoot, 'child-one', [
        'name' => 'Child One',
        'parent' => 'theme-a',
    ]);
    writeThemesInstalledFixtureTheme($themesInstalledFixtureRoot, 'child-two', [
        'name' => 'Child Two',
        'parent' => 'theme-a',
    ]);

    $tpl = callBuildTplTheme('theme-a', fsThemeEntry(), [], 'default', themesInstalledLifecycle());

    expect($tpl->deletable)
        ->toBeFalse()
        ->and($tpl->deleteTooltip)
        ->toContain('Child One')
        ->and($tpl->deleteTooltip)
        ->toContain('Child Two');
});

test('an unrelated real theme on disk (not a child) does not block deletion', function () use (&$themesInstalledFixtureRoot): void {
    assert(is_string($themesInstalledFixtureRoot));
    writeThemesInstalledFixtureTheme($themesInstalledFixtureRoot, 'unrelated-theme', [
        'name' => 'Unrelated Theme',
        'parent' => 'some-other-theme',
    ]);

    $tpl = callBuildTplTheme('theme-a', fsThemeEntry(), [], 'default', themesInstalledLifecycle());

    expect($tpl->deletable)
        ->toBeTrue()
        ->and($tpl->deleteTooltip)
        ->toBeNull();
});
