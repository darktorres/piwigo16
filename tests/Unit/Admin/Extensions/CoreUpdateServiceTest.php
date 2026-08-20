<?php

declare(strict_types=1);

use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\Extensions\CoreUpdateService;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Auth\AuthRepository;
use Piwigo\Auth\AuthService;
use Piwigo\Auth\CookieService;
use Piwigo\Auth\PasswordRepository;
use Piwigo\Auth\PasswordService;
use Piwigo\Auth\UserFailedLoginEntity;
use Piwigo\Bootstrap\RedirectService;
use Piwigo\Cache\CacheFactory;
use Piwigo\Cache\ExtensionUpdateCachePool;
use Piwigo\Cache\TranslationsCachePool;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\ConnectedWithSession;
use Piwigo\Core\FilterState;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Core\ProcessCache;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Group\GroupEntity;
use Piwigo\Lang\Translator;
use Piwigo\Mail\MailRecipientRepository;
use Piwigo\Mail\MailService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionService;
use Piwigo\Template\Renderer;
use Piwigo\Tests\Support\CurrentConfigTestFactory;
use Piwigo\Tests\Support\CurrentTemplateTestFactory;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Tests\Support\LayoutStateTestFactory;
use Piwigo\Tests\Support\PageStateTestFactory;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;

// Only containerVersionCompare() is covered here -- checkPiwigoUpgrade()/
// getPiwigoNewVersions()/notifyPiwigoNewVersions()/upgradeTo() all talk to
// the real PHPWG_URL over the network via Piwigo\Http\HttpClientService's
// static fetch()/fetchToFile(), with no injectable HTTP client seam,
// matching the project's own documented "piwigo.org outbound-call"
// flakiness class -- not exercised here. containerVersionCompare() also
// never touches the injected ConfigService, so this only needs a
// type-satisfying instance, never an actually-queried one -- Doctrine's
// DBAL connection is lazy (build() never opens a real connection until a
// query runs), so constructing a real ConfigRepository/ConfigService here
// is safe without a reachable test DB.
function core_update_service_test_activity_service(): ActivityService
{
    return new ActivityService(EntityManagerFactory::build(DbConnection::build())->getRepository(ActivityEntity::class));
}

// Never actually queried either -- same "type-satisfying instance is
// enough" reasoning as $repo/$configService above; containerVersionCompare()
// never touches the injected UserService.
function core_update_service_test_user_service(): UserService
{
    $conn = DbConnection::build();
    $currentConfig = new CurrentConfig();
    $currentUser = new CurrentUser($currentConfig);
    $accessLevelChecker = new AccessLevelChecker($currentUser, $currentConfig);
    $permissionService = new PermissionService(new PermissionRepository(EntityManagerFactory::build($conn)), EntityManagerFactory::build($conn)->getRepository(GroupEntity::class), new CategoryRepository(EntityManagerFactory::build($conn), $currentConfig), $currentUser, new FilterState(), $accessLevelChecker);

    return new UserService(
        core_update_service_test_lang(),
        new UserRepository(EntityManagerFactory::build($conn), new EventDispatcher(), $currentConfig),
        EntityManagerFactory::build($conn)->getRepository(GroupEntity::class),
        core_update_service_test_activity_service(),
        HtmlServiceTestFactory::build(),
        new SessionService(EntityManagerFactory::build($conn)->getRepository(SessionEntity::class), $currentConfig),
        new EventDispatcher(),
        new DeploymentPolicy(),
        $currentUser,
        $currentConfig,
        new InstallationFlag(),
        new ProcessCache(),
        Paths::fromRoot(sys_get_temp_dir()),
        EntityManagerFactory::build($conn),
        $permissionService,
        new CategoryService(core_update_service_test_lang(), new CategoryRepository(EntityManagerFactory::build($conn), $currentConfig), $permissionService, $currentConfig, new EventDispatcher(), new Translator($currentConfig, new TranslationsCachePool(CacheFactory::create(namespace: 'piwigo.translations'))), $accessLevelChecker, new UserRepository(EntityManagerFactory::build($conn), new EventDispatcher(), $currentConfig)),
        new PasswordService(new PasswordRepository(EntityManagerFactory::build($conn)), new DeploymentPolicy()),
    );
}

// Plain Unit test -- no Kernel::boot() anywhere in this file, so
// resolving the real container-shared Lang instance (no memoized
// pre-boot fallback) would throw; containerVersionCompare()/stepIs()/processObsoleteList()
// never touch the injected Lang either, same "type-satisfying instance
// is enough" reasoning as $activityService/$userService above.
function core_update_service_test_lang(): Lang
{
    return new Lang(new Translator(new CurrentConfig(), new TranslationsCachePool(CacheFactory::create(namespace: 'piwigo.translations'))), HtmlServiceTestFactory::build(), Paths::fromRoot(sys_get_temp_dir()), new InstallationFlag());
}

/**
 * Same "no Kernel::boot(), type-satisfying instance is enough" reasoning
 * as core_update_service_test_lang() above -- every MailService::
 * __construct() collaborator is built bare, DB-free.
 */
function core_update_service_test_mail_service(): MailService
{
    $conn = DbConnection::build();

    return new MailService(
        core_update_service_test_lang(),
        new CurrentConfig(),
        Paths::fromRoot(sys_get_temp_dir()),
        new SessionService(EntityManagerFactory::build($conn)->getRepository(SessionEntity::class), new CurrentConfig()),
        new Translator(new CurrentConfig(), new TranslationsCachePool(CacheFactory::create(namespace: 'piwigo.translations'))),
        new EventDispatcher(),
        new CurrentUser(new CurrentConfig()),
        UrlServiceTestFactory::build(),
        new UserRepository(EntityManagerFactory::build($conn), new EventDispatcher(), new CurrentConfig()),
        new MailRecipientRepository(EntityManagerFactory::build($conn)),
        new AuthService(
            new AuthRepository(EntityManagerFactory::build($conn)),
            new ActivityService(EntityManagerFactory::build($conn)->getRepository(ActivityEntity::class)),
            HtmlServiceTestFactory::build(),
            new PasswordService(new PasswordRepository(EntityManagerFactory::build($conn)), new DeploymentPolicy()),
            new CookieService(),
            EntityManagerFactory::build($conn)->getRepository(UserFailedLoginEntity::class),
            new SessionService(EntityManagerFactory::build($conn)->getRepository(SessionEntity::class), new CurrentConfig()),
            new EventDispatcher(),
            new PageState(),
            new CurrentUser(new CurrentConfig()),
            new CurrentConfig(),
            Paths::fromRoot(sys_get_temp_dir()),
            EntityManagerFactory::build($conn),
            new ConnectedWithSession(),
        ),
        core_update_service_test_user_service(),
    );
}

function core_update_service(): CoreUpdateService
{
    $repo = EntityManagerFactory::build(DbConnection::build())->getRepository(ConfigEntry::class);

    return new CoreUpdateService(core_update_service_test_lang(), new ZipExtractor(), new RedirectService(core_update_service_test_lang(), core_update_service_test_user_service(), new EventDispatcher(), LayoutStateTestFactory::get(), new Renderer(CurrentTemplateTestFactory::get())), UrlServiceTestFactory::build(), new ConfigService($repo, CurrentConfigTestFactory::get()), Paths::fromRoot(dirname(__DIR__, 4)), PageStateTestFactory::get(), CurrentTemplateTestFactory::get(), core_update_service_test_activity_service(), core_update_service_test_user_service(), core_update_service_test_mail_service(), CurrentConfigTestFactory::get(), new ExtensionUpdateCachePool(CacheFactory::create(namespace: 'piwigo.extension_update', defaultLifetime: 86400)));
}

test('containerVersionCompare orders by semantic version first', function (): void {
    expect(core_update_service()->containerVersionCompare('16.1.0a', '16.2.0a'))
        ->toBeLessThan(0)
        ->and(core_update_service()->containerVersionCompare('16.2.0a', '16.1.0a'))
        ->toBeGreaterThan(0);
});

test('containerVersionCompare falls back to the container letter suffix on a semantic tie', function (): void {
    expect(core_update_service()->containerVersionCompare('16.2.0a', '16.2.0b'))
        ->toBeTrue()
        ->and(core_update_service()->containerVersionCompare('16.2.0b', '16.2.0a'))
        ->toBeFalse();
});

test('containerVersionCompare treats an identical version as no earlier suffix', function (): void {
    expect(core_update_service()->containerVersionCompare('16.2.0a', '16.2.0a'))
        ->toBeFalse();
});

test('containerVersionCompare treats a null v1 as always earlier', function (): void {
    expect(core_update_service()->containerVersionCompare(null, '16.2.0a'))
        ->toBeLessThan(0);
});

function core_update_service_test_marker(): string
{
    /** @var string|null */
    static $marker = null;

    return $marker ??= sys_get_temp_dir() . '/piwigo-core-update-service-test-' . bin2hex(random_bytes(8));
}

beforeEach(function (): void {
    mkdir(core_update_service_test_marker(), 0o777, true);
});

afterEach(function (): void {
    $dir = core_update_service_test_marker();
    if (is_dir($dir)) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $path) {
            assert($path instanceof SplFileInfo);
            $path->isDir() ? rmdir($path->getPathname()) : unlink($path->getPathname());
        }
        rmdir($dir);
    }
});

function core_update_service_at(string $root): CoreUpdateService
{
    $repo = EntityManagerFactory::build(DbConnection::build())->getRepository(ConfigEntry::class);

    return new CoreUpdateService(core_update_service_test_lang(), new ZipExtractor(), new RedirectService(core_update_service_test_lang(), core_update_service_test_user_service(), new EventDispatcher(), LayoutStateTestFactory::get(), new Renderer(CurrentTemplateTestFactory::get())), UrlServiceTestFactory::build(), new ConfigService($repo, CurrentConfigTestFactory::get()), Paths::fromRoot($root), PageStateTestFactory::get(), CurrentTemplateTestFactory::get(), core_update_service_test_activity_service(), core_update_service_test_user_service(), core_update_service_test_mail_service(), CurrentConfigTestFactory::get(), new ExtensionUpdateCachePool(CacheFactory::create(namespace: 'piwigo.extension_update', defaultLifetime: 86400)));
}

function core_update_service_step_is(int|string $step, int $target): bool
{
    $method = new ReflectionMethod(core_update_service(), 'stepIs');

    /** @var bool */
    return $method->invoke(core_update_service(), $step, $target);
}

test('stepIs matches both the int and numeric-string form of the same step', function (): void {
    expect(core_update_service_step_is(2, 2))
        ->toBeTrue();
    expect(core_update_service_step_is('2', 2))
        ->toBeTrue();
    expect(core_update_service_step_is(3, 2))
        ->toBeFalse();
    expect(core_update_service_step_is('3', 2))
        ->toBeFalse();
});

function core_update_service_process_obsolete_list(CoreUpdateService $service, string $file): void
{
    $method = new ReflectionMethod($service, 'processObsoleteList');
    $method->invoke($service, $file);
}

test('processObsoleteList deletes every listed file plus the list itself, leaving an unlisted file untouched', function (): void {
    $root = core_update_service_test_marker() . '/';
    file_put_contents($root . 'stale.php', 'old code');
    file_put_contents($root . 'keep.php', 'still here');
    file_put_contents($root . 'obsolete.list', "stale.php\n");

    core_update_service_process_obsolete_list(core_update_service_at($root), 'obsolete.list');

    expect(file_exists($root . 'stale.php'))->toBeFalse();
    expect(file_exists($root . 'obsolete.list'))->toBeFalse();
    // A broken `$path = $this->paths
    // ->root . $oldFile` (dropping $oldFile) makes $path resolve to the
    // root directory itself on every loop iteration -- since the root is a
    // real directory, that mutation still deletes stale.php/obsolete.list
    // (via the is_dir() -> deltree() branch nuking the whole root), so
    // checking only their own absence can't tell a targeted per-file
    // delete from an accidental whole-root wipe. A 3rd, unlisted file
    // surviving is what actually proves the deletion was scoped to the
    // listed files.
    expect(file_exists($root . 'keep.php'))->toBeTrue();
});

test('processObsoleteList does nothing when the list file does not exist', function (): void {
    $root = core_update_service_test_marker() . '/';
    file_put_contents($root . 'keep.php', 'still here');

    core_update_service_process_obsolete_list(core_update_service_at($root), 'no-such-list.txt');

    expect(file_exists($root . 'keep.php'))->toBeTrue();
});

test('processObsoleteList does nothing when the list file exists but is empty', function (): void {
    $root = core_update_service_test_marker() . '/';
    file_put_contents($root . 'keep.php', 'still here');
    file_put_contents($root . 'obsolete.list', '');

    core_update_service_process_obsolete_list(core_update_service_at($root), 'obsolete.list');

    expect(file_exists($root . 'keep.php'))->toBeTrue();
    expect(file_exists($root . 'obsolete.list'))->toBeTrue();
});

test('processObsoleteList returns cleanly when file() itself fails despite file_exists() being true', function (): void {
    // Kills line 411's FalseToTrue (`$oldFiles === true` instead of
    // `=== false`): file()'s own return type is array|false, so it can
    // never actually equal true -- the mutant's check can never match a
    // real read failure and would fall through to `$oldFiles[] = $file;`
    // on a plain boolean (a "scalar value as array" warning) followed by
    // `foreach ($oldFiles as ...)` (a "must be of type array|object"
    // warning), instead of real code's single, immediate file()-open
    // warning and early return. An unreadable (but still existing) list
    // file makes file_exists() report true (existence, not readability)
    // while file() itself fails -- same chmod(0o000) + captured-warnings
    // technique as DerivativeCacheServiceTest's/DerivativeImageTest's own
    // use of it, since this project's phpunit.xml.dist (failOnWarning)
    // would otherwise convert any of these into a failure outright rather
    // than a countable, assertable signal.
    $root = core_update_service_test_marker() . '/';
    file_put_contents($root . 'keep.php', 'still here');
    file_put_contents($root . 'obsolete.list', "stale.php\n");
    chmod($root . 'obsolete.list', 0o000);

    $warnings = [];
    set_error_handler(function (int $errno, string $errstr) use (&$warnings): bool {
        $warnings[] = $errstr;

        return true;
    });
    try {
        core_update_service_process_obsolete_list(core_update_service_at($root), 'obsolete.list');
    } finally {
        restore_error_handler();
        chmod($root . 'obsolete.list', 0o644);
    }

    expect($warnings)
        ->toHaveCount(1);
    expect(file_exists($root . 'keep.php'))->toBeTrue();
    expect(file_exists($root . 'obsolete.list'))->toBeTrue();
});
