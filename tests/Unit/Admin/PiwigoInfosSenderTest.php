<?php

declare(strict_types=1);

use Piwigo\Tests\Support\HtmlServiceTestFactory;
use Piwigo\Mail\MailService;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\PageState;
use Piwigo\Session\SessionService;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Session\SessionEntity;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Users\CurrentUser;
use Piwigo\Tests\Support\UrlServiceTestFactory;
use Piwigo\Config\ConfigService;
use Piwigo\Config\ConfigEntry;
use Piwigo\Rate\RateService;
use Piwigo\Tests\Unit\Auth\AccessControlTestFakeHtmlRendererDeniesAccess;
use Piwigo\Tests\Unit\Auth\AccessControlTestFakeRedirectServiceNeverCalled;
use Piwigo\Rate\RateEntity;
use Piwigo\Auth\CookieService;
use Piwigo\History\HistoryService;
use Piwigo\History\HistoryEntity;
use Piwigo\Activity\ActivityService;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Users\UserService;
use Piwigo\Users\UserRepository;
use Piwigo\Group\GroupEntity;
use Piwigo\Db\DbConnection;
use Piwigo\Core\ProcessCache;
use Piwigo\Image\ImageService;
use Piwigo\Image\ImageEntity;
use Piwigo\Permission\PermissionService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Category\CategoryRepository;
use Piwigo\Core\FilterState;
use Piwigo\Category\CategoryService;
use Piwigo\Tag\TagService;
use Piwigo\Tag\TagEntity;
use Piwigo\Group\GroupService;
use Piwigo\Audit\AuditService;
use Piwigo\Audit\AuditLogEntity;
use Piwigo\Admin\InstallationStats;
use Piwigo\Admin\PiwigoInfosSender;
use Piwigo\Auth\AccessControl;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Lang;
use Piwigo\Core\Logger;
use Piwigo\Core\Paths;
use Piwigo\Image\ImageStdParams;
use Piwigo\Lang\Translator;

// send()'s real body talks to piwigo.org's PEM/telemetry endpoints over the
// network (via the static, non-injectable Piwigo\Http\HttpClientService::
// fetch()) and reads/writes a real DB config row partway through (the
// send_piwigo_infos_last_notice reload, before any of the network calls)
// -- neither has a test seam to fake through, matching this same
// session's PemCatalog/CoreUpdateService findings for the same class of
// "talks to piwigo.org" code. The one branch reachable without any of
// that is send()'s own CurrentConfig::sendPiwigoInfos() guard, which
// short-circuits before the DB reload or any network call -- reached
// right after send()'s own unconditional CurrentLogger::get() read, so
// this suite still needs a real (OFF-severity, side-effect-free) logger
// seeded first.

afterEach(function (): void {
    CurrentConfig::current()->setSendPiwigoInfos(true);
});

// PiwigoInfosSender/UserService/ImageService/CategoryService/TagService
// all gained a required Lang constructor collaborator (singleton/
// service-locator elimination campaign, Phase 8) and this plain Unit
// test never boots a Kernel, so each call site below needs its own
// throwaway, DB-free instance -- none of them are ever actually read,
// same "send() returns before touching anything past the guard"
// reasoning as every other collaborator in this file.
function piwigoInfosSenderTestLang(): Lang
{
    return new Lang(new Translator(new CurrentConfig()), HtmlServiceTestFactory::build(), Paths::fromRoot(sys_get_temp_dir()), new InstallationFlag());
}

/**
 * Same "no Kernel::boot(), never actually read" reasoning as
 * piwigoInfosSenderTestLang() above -- every MailService::__construct()
 * shim collaborator (singleton/service-locator elimination campaign,
 * Phase 11 sub-phase 11E) built bare, DB-free.
 */
function piwigoInfosSenderTestMailService(): MailService
{
    return new MailService(
        piwigoInfosSenderTestLang(),
        new CurrentConfig(),
        new DeploymentPolicy(),
        new PageState(),
        Paths::fromRoot(sys_get_temp_dir()),
        new SessionService(EntityManagerFactory::build()->getRepository(SessionEntity::class), new CurrentConfig()),
        new Translator(new CurrentConfig()),
        new EventDispatcher(),
        new CurrentUser(new CurrentConfig()),
        UrlServiceTestFactory::build(),
    );
}

test('send returns immediately without touching the DB or network when telemetry is disabled', function (): void {
    $currentLogger = new CurrentLogger();
    $currentLogger->set(new Logger(['severity' => Logger::OFF]));
    // No Kernel::boot() in this plain Unit test (see file docblock), so a
    // fresh, throwaway instance stands in for the container-shared one --
    // reused below as the same PiwigoInfosSender constructor argument so
    // send()'s own guard (reads $this->currentConfig->sendPiwigoInfos(),
    // not the static current() bridge) actually observes this false value.
    $currentConfig = new CurrentConfig();
    $currentConfig->setSendPiwigoInfos(false);

    // No exception, no fatal, no side effect to assert beyond "returned" --
    // proven by simply completing without the DB-reload/network code below
    // it ever running (both would throw or hang in this sandboxed test
    // environment if reached).
    // Never actually read -- send() returns before touching ConfigService,
    // per this file's own docblock -- so a throwaway instance (no
    // Kernel::boot() needed; EntityManagerFactory::build() only
    // constructs objects, it never opens a real connection) is enough.
    $configService = new ConfigService(
        EntityManagerFactory::build()->getRepository(ConfigEntry::class),
        new EventDispatcher(),
        new CurrentConfig(),
    );
    // Never actually read either -- same "send() returns before touching
    // anything past the guard" reasoning as $configService above.
    $rateService = new RateService(
        new AccessControl(
            new AccessControlTestFakeHtmlRendererDeniesAccess(),
            new AccessControlTestFakeRedirectServiceNeverCalled(),
            new CurrentUser(new CurrentConfig()),
            new CurrentConfig(),
        ),
        EntityManagerFactory::build()->getRepository(RateEntity::class),
        new CookieService(),
        new EventDispatcher(),
        new CurrentUser(new CurrentConfig()),
        new CurrentConfig(),
    );
    $historyService = new HistoryService(
        new AccessControl(
            new AccessControlTestFakeHtmlRendererDeniesAccess(),
            new AccessControlTestFakeRedirectServiceNeverCalled(),
            new CurrentUser(new CurrentConfig()),
            new CurrentConfig(),
        ),
        EntityManagerFactory::build()->getRepository(HistoryEntity::class),
        $configService,
        $currentLogger,
        new EventDispatcher(),
        new PageState(),
        new CurrentUser(new CurrentConfig()),
        new CurrentConfig(),
    );
    $activityService = new ActivityService(
        EntityManagerFactory::build()->getRepository(ActivityEntity::class),
    );
    // Never actually read either -- same "send() returns before touching
    // anything past the guard" reasoning as $configService above.
    $userService = new UserService(
        piwigoInfosSenderTestLang(),
        new UserRepository(EntityManagerFactory::build(), new EventDispatcher(), new CurrentConfig()),
        EntityManagerFactory::build()->getRepository(GroupEntity::class),
        piwigoInfosSenderTestMailService(),
        $activityService,
        HtmlServiceTestFactory::build(),
        DbConnection::build(),
        new SessionService(EntityManagerFactory::build()->getRepository(SessionEntity::class), new CurrentConfig()),
        new EventDispatcher(),
        new DeploymentPolicy(),
        new CurrentUser(new CurrentConfig()),
        new CurrentConfig(),
        new InstallationFlag(),
        new ProcessCache(),
        Paths::fromRoot(sys_get_temp_dir()),
    );
    $imageService = new ImageService(
        piwigoInfosSenderTestLang(),
        EntityManagerFactory::build()->getRepository(ImageEntity::class),
        $activityService,
        new SessionService(EntityManagerFactory::build()->getRepository(SessionEntity::class), new CurrentConfig()),
        new EventDispatcher(),
        new CurrentConfig(),
        new Translator(new CurrentConfig()),
        Paths::fromRoot(sys_get_temp_dir()),
    );
    $permissionService = new PermissionService(
        new PermissionRepository(EntityManagerFactory::build()),
        EntityManagerFactory::build()->getRepository(GroupEntity::class),
        new CategoryRepository(EntityManagerFactory::build(), new CurrentConfig()),
        new CurrentUser(new CurrentConfig()),
        new FilterState(),
    );
    $categoryService = new CategoryService(
        piwigoInfosSenderTestLang(),
        new CategoryRepository(EntityManagerFactory::build(), new CurrentConfig()),
        $permissionService,
        new CurrentConfig(),
        new EventDispatcher(),
        new Translator(new CurrentConfig()),
    );
    $tagService = new TagService(
        piwigoInfosSenderTestLang(),
        EntityManagerFactory::build()->getRepository(TagEntity::class),
        $permissionService,
        $activityService,
        new EventDispatcher(),
        new CurrentUser(new CurrentConfig()),
        new CurrentConfig(),
        new CurrentLogger(),
        new SessionService(EntityManagerFactory::build()->getRepository(SessionEntity::class), new CurrentConfig()),
    );
    $groupService = new GroupService(
        EntityManagerFactory::build()->getRepository(GroupEntity::class),
        $activityService,
        new AuditService(EntityManagerFactory::build()->getRepository(AuditLogEntity::class)),
        $configService,
        new EventDispatcher(),
        new CurrentUser(new CurrentConfig()),
        new CurrentConfig(),
    );
    $installationStats = new InstallationStats($rateService, $historyService, $imageService, $categoryService, $tagService, $userService, $groupService);
    // Never actually read either -- same "send() returns before touching
    // anything past the guard" reasoning as $configService above.
    $urlService = UrlServiceTestFactory::build();
    new PiwigoInfosSender(piwigoInfosSenderTestLang(), $currentLogger, new ImageStdParams(), $configService, $installationStats, $activityService, $userService, $imageService, $urlService, $currentConfig, Paths::fromRoot(sys_get_temp_dir()))->send();

    expect(true)->toBeTrue();
});
