<?php

declare(strict_types=1);

use Piwigo\Activity\ActivityService;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Users\UserService;
use Piwigo\Users\UserRepository;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Config\CurrentConfig;
use Piwigo\Group\GroupEntity;
use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Session\SessionService;
use Piwigo\Session\SessionEntity;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Users\CurrentUser;
use Piwigo\Core\ProcessCache;
use Piwigo\Mail\MailService;
use Piwigo\Core\PageState;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Template\CurrentTemplate;
use Piwigo\Admin\Extensions\CoreUpdateService;
use Piwigo\Admin\Extensions\ZipExtractor;
use Piwigo\Bootstrap\RedirectService;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigService;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Lang\Translator;

// Only containerVersionCompare() is covered here -- checkPiwigoUpgrade()/
// getPiwigoNewVersions()/notifyPiwigoNewVersions()/upgradeTo() all talk to
// the real PHPWG_URL over the network via Piwigo\Http\HttpClientService's
// static fetch()/fetchToFile() (P23 batch 8d -- was the legacy fetchRemote()
// free function; still no injectable HTTP client seam, same limitation),
// matching the project's own documented "piwigo.org outbound-call"
// flakiness class -- not exercised here. containerVersionCompare() also
// never touches the injected ConfigService (Legacy Coupling Retirement
// Phase 5), so this only needs a type-satisfying instance, never an
// actually-queried one -- Doctrine's DBAL connection is lazy (build()
// never opens a real connection until a query runs), so constructing a
// real ConfigRepository/ConfigService here is safe without a reachable
// test DB.
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

    return new UserService(
        core_update_service_test_lang(),
        new UserRepository(EntityManagerFactory::build($conn), new EventDispatcher(), new CurrentConfig()),
        EntityManagerFactory::build($conn)->getRepository(GroupEntity::class),
        core_update_service_test_mail_service(),
        core_update_service_test_activity_service(),
        HtmlServiceTestFactory::build(),
        $conn,
        new SessionService(EntityManagerFactory::build($conn)->getRepository(SessionEntity::class), new CurrentConfig()),
        new EventDispatcher(),
        new DeploymentPolicy(),
        new CurrentUser(new CurrentConfig()),
        new CurrentConfig(),
        new InstallationFlag(),
        new ProcessCache(),
    );
}

// Plain Unit test -- no Kernel::boot() anywhere in this file, so
// Lang::current() (no memoized pre-boot fallback, see its own docblock)
// would throw; containerVersionCompare()/stepIs()/processObsoleteList()
// never touch the injected Lang either, same "type-satisfying instance
// is enough" reasoning as $activityService/$userService above.
function core_update_service_test_lang(): Lang
{
    return new Lang(new Translator(new CurrentConfig()), HtmlServiceTestFactory::build(), Paths::fromRoot(sys_get_temp_dir()), new InstallationFlag());
}

/**
 * Same "no Kernel::boot(), type-satisfying instance is enough" reasoning
 * as core_update_service_test_lang() above -- every MailService::
 * __construct() shim collaborator (singleton/service-locator elimination
 * campaign, Phase 11 sub-phase 11E) built bare, DB-free.
 */
function core_update_service_test_mail_service(): MailService
{
    return new MailService(
        core_update_service_test_lang(),
        new CurrentConfig(),
        new DeploymentPolicy(),
        new PageState(),
        Paths::fromRoot(sys_get_temp_dir()),
        new SessionService(EntityManagerFactory::build(DbConnection::build())->getRepository(SessionEntity::class), new CurrentConfig()),
        new Translator(new CurrentConfig()),
        new EventDispatcher(),
        new CurrentUser(new CurrentConfig()),
        UrlServiceTestFactory::build(),
    );
}

function core_update_service(): CoreUpdateService
{
    $repo = EntityManagerFactory::build(DbConnection::build())->getRepository(ConfigEntry::class);

    return new CoreUpdateService(core_update_service_test_lang(), new ZipExtractor(), new RedirectService(core_update_service_test_lang(), core_update_service_test_user_service()), UrlServiceTestFactory::build(), new ConfigService($repo, new EventDispatcher(), CurrentConfig::current()), Paths::fromRoot(dirname(__DIR__, 4)), PageState::current(), CurrentTemplate::current(), core_update_service_test_activity_service(), core_update_service_test_user_service(), core_update_service_test_mail_service(), CurrentConfig::current());
}

test('containerVersionCompare orders by semantic version first', function (): void {
    expect(core_update_service()->containerVersionCompare('16.1.0a', '16.2.0a'))->toBeLessThan(0)
        ->and(core_update_service()->containerVersionCompare('16.2.0a', '16.1.0a'))->toBeGreaterThan(0);
});

test('containerVersionCompare falls back to the container letter suffix on a semantic tie', function (): void {
    expect(core_update_service()->containerVersionCompare('16.2.0a', '16.2.0b'))->toBeTrue()
        ->and(core_update_service()->containerVersionCompare('16.2.0b', '16.2.0a'))->toBeFalse();
});

test('containerVersionCompare treats an identical version as no earlier suffix', function (): void {
    expect(core_update_service()->containerVersionCompare('16.2.0a', '16.2.0a'))->toBeFalse();
});

test('containerVersionCompare treats a null v1 as always earlier', function (): void {
    expect(core_update_service()->containerVersionCompare(null, '16.2.0a'))->toBeLessThan(0);
});

function core_update_service_test_marker(): string
{
    /** @var string|null $marker */
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

    return new CoreUpdateService(core_update_service_test_lang(), new ZipExtractor(), new RedirectService(core_update_service_test_lang(), core_update_service_test_user_service()), UrlServiceTestFactory::build(), new ConfigService($repo, new EventDispatcher(), CurrentConfig::current()), Paths::fromRoot($root), PageState::current(), CurrentTemplate::current(), core_update_service_test_activity_service(), core_update_service_test_user_service(), core_update_service_test_mail_service(), CurrentConfig::current());
}

function core_update_service_step_is(int|string $step, int $target): bool
{
    $method = new ReflectionMethod(core_update_service(), 'stepIs');

    /** @var bool */
    return $method->invoke(core_update_service(), $step, $target);
}

test('stepIs matches both the int and numeric-string form of the same step', function (): void {
    expect(core_update_service_step_is(2, 2))->toBeTrue();
    expect(core_update_service_step_is('2', 2))->toBeTrue();
    expect(core_update_service_step_is(3, 2))->toBeFalse();
    expect(core_update_service_step_is('3', 2))->toBeFalse();
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
    // Real gap, found via mutation testing: a broken `$path = $this->paths
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
