<?php

declare(strict_types=1);

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
    return new Lang(new Translator(new CurrentConfig()), \Piwigo\Tests\Support\HtmlServiceTestFactory::build(), Paths::fromRoot(sys_get_temp_dir()), new InstallationFlag());
}

/**
 * Same "no Kernel::boot(), never actually read" reasoning as
 * piwigoInfosSenderTestLang() above -- every MailService::__construct()
 * shim collaborator (singleton/service-locator elimination campaign,
 * Phase 11 sub-phase 11E) built bare, DB-free.
 */
function piwigoInfosSenderTestMailService(): \Piwigo\Mail\MailService
{
    return new \Piwigo\Mail\MailService(
        piwigoInfosSenderTestLang(),
        new CurrentConfig(),
        new \Piwigo\Config\DeploymentPolicy(),
        new \Piwigo\Core\PageState(),
        Paths::fromRoot(sys_get_temp_dir()),
        new \Piwigo\Session\SessionService(\Piwigo\Db\EntityManagerFactory::build()->getRepository(\Piwigo\Session\SessionEntity::class), new CurrentConfig()),
        new Translator(new CurrentConfig()),
        new \Piwigo\PluginConfig\EventDispatcher(),
        new \Piwigo\Users\CurrentUser(new CurrentConfig()),
        \Piwigo\Tests\Support\UrlServiceTestFactory::build(),
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
    $configService = new \Piwigo\Config\ConfigService(
        \Piwigo\Db\EntityManagerFactory::build()->getRepository(\Piwigo\Config\ConfigEntry::class),
        new \Piwigo\PluginConfig\EventDispatcher(),
        new \Piwigo\Config\CurrentConfig(),
    );
    // Never actually read either -- same "send() returns before touching
    // anything past the guard" reasoning as $configService above.
    $rateService = new \Piwigo\Rate\RateService(
        new AccessControl(
            new \Piwigo\Tests\Unit\Auth\AccessControlTestFakeHtmlRendererDeniesAccess(),
            new \Piwigo\Tests\Unit\Auth\AccessControlTestFakeRedirectServiceNeverCalled(),
            new \Piwigo\Users\CurrentUser(new \Piwigo\Config\CurrentConfig()),
            new \Piwigo\Config\CurrentConfig(),
        ),
        \Piwigo\Db\EntityManagerFactory::build()->getRepository(\Piwigo\Rate\RateEntity::class),
        new \Piwigo\Auth\CookieService(),
        new \Piwigo\PluginConfig\EventDispatcher(),
        new \Piwigo\Users\CurrentUser(new \Piwigo\Config\CurrentConfig()),
        new \Piwigo\Config\CurrentConfig(),
    );
    $historyService = new \Piwigo\History\HistoryService(
        new AccessControl(
            new \Piwigo\Tests\Unit\Auth\AccessControlTestFakeHtmlRendererDeniesAccess(),
            new \Piwigo\Tests\Unit\Auth\AccessControlTestFakeRedirectServiceNeverCalled(),
            new \Piwigo\Users\CurrentUser(new \Piwigo\Config\CurrentConfig()),
            new \Piwigo\Config\CurrentConfig(),
        ),
        \Piwigo\Db\EntityManagerFactory::build()->getRepository(\Piwigo\History\HistoryEntity::class),
        $configService,
        $currentLogger,
        new \Piwigo\PluginConfig\EventDispatcher(),
        new \Piwigo\Core\PageState(),
        new \Piwigo\Users\CurrentUser(new \Piwigo\Config\CurrentConfig()),
        new \Piwigo\Config\CurrentConfig(),
    );
    $activityService = new \Piwigo\Activity\ActivityService(
        \Piwigo\Db\EntityManagerFactory::build()->getRepository(\Piwigo\Activity\ActivityEntity::class),
    );
    // Never actually read either -- same "send() returns before touching
    // anything past the guard" reasoning as $configService above.
    $userService = new \Piwigo\Users\UserService(
        piwigoInfosSenderTestLang(),
        new \Piwigo\Users\UserRepository(\Piwigo\Db\EntityManagerFactory::build(), new \Piwigo\PluginConfig\EventDispatcher(), new \Piwigo\Config\CurrentConfig()),
        \Piwigo\Db\EntityManagerFactory::build()->getRepository(\Piwigo\Group\GroupEntity::class),
        piwigoInfosSenderTestMailService(),
        $activityService,
        \Piwigo\Tests\Support\HtmlServiceTestFactory::build(),
        \Piwigo\Db\DbConnection::build(),
        new \Piwigo\Session\SessionService(\Piwigo\Db\EntityManagerFactory::build()->getRepository(\Piwigo\Session\SessionEntity::class), new \Piwigo\Config\CurrentConfig()),
        new \Piwigo\PluginConfig\EventDispatcher(),
        new \Piwigo\Config\DeploymentPolicy(),
        new \Piwigo\Users\CurrentUser(new \Piwigo\Config\CurrentConfig()),
        new \Piwigo\Config\CurrentConfig(),
        new \Piwigo\Core\InstallationFlag(),
        new \Piwigo\Core\ProcessCache(),
    );
    $imageService = new \Piwigo\Image\ImageService(
        piwigoInfosSenderTestLang(),
        \Piwigo\Db\EntityManagerFactory::build()->getRepository(\Piwigo\Image\ImageEntity::class),
        $activityService,
        new \Piwigo\Session\SessionService(\Piwigo\Db\EntityManagerFactory::build()->getRepository(\Piwigo\Session\SessionEntity::class), new \Piwigo\Config\CurrentConfig()),
        new \Piwigo\PluginConfig\EventDispatcher(),
        new \Piwigo\Config\CurrentConfig(),
        new \Piwigo\Lang\Translator(new \Piwigo\Config\CurrentConfig()),
    );
    $permissionService = new \Piwigo\Permission\PermissionService(
        new \Piwigo\Permission\PermissionRepository(\Piwigo\Db\EntityManagerFactory::build()),
        \Piwigo\Db\EntityManagerFactory::build()->getRepository(\Piwigo\Group\GroupEntity::class),
        new \Piwigo\Category\CategoryRepository(\Piwigo\Db\EntityManagerFactory::build(), new \Piwigo\Config\CurrentConfig()),
    );
    $categoryService = new \Piwigo\Category\CategoryService(
        piwigoInfosSenderTestLang(),
        new \Piwigo\Category\CategoryRepository(\Piwigo\Db\EntityManagerFactory::build(), new \Piwigo\Config\CurrentConfig()),
        $permissionService,
        new \Piwigo\Config\CurrentConfig(),
        new \Piwigo\PluginConfig\EventDispatcher(),
        new \Piwigo\Lang\Translator(new \Piwigo\Config\CurrentConfig()),
    );
    $tagService = new \Piwigo\Tag\TagService(
        piwigoInfosSenderTestLang(),
        \Piwigo\Db\EntityManagerFactory::build()->getRepository(\Piwigo\Tag\TagEntity::class),
        $permissionService,
        $activityService,
        new \Piwigo\PluginConfig\EventDispatcher(),
        new \Piwigo\Users\CurrentUser(new \Piwigo\Config\CurrentConfig()),
        new \Piwigo\Config\CurrentConfig(),
        new \Piwigo\Core\CurrentLogger(),
        new \Piwigo\Session\SessionService(\Piwigo\Db\EntityManagerFactory::build()->getRepository(\Piwigo\Session\SessionEntity::class), new \Piwigo\Config\CurrentConfig()),
    );
    $groupService = new \Piwigo\Group\GroupService(
        \Piwigo\Db\EntityManagerFactory::build()->getRepository(\Piwigo\Group\GroupEntity::class),
        $activityService,
        new \Piwigo\Audit\AuditService(\Piwigo\Db\EntityManagerFactory::build()->getRepository(\Piwigo\Audit\AuditLogEntity::class)),
        $configService,
        new \Piwigo\PluginConfig\EventDispatcher(),
        new \Piwigo\Users\CurrentUser(new \Piwigo\Config\CurrentConfig()),
        new \Piwigo\Config\CurrentConfig(),
    );
    $installationStats = new \Piwigo\Admin\InstallationStats($rateService, $historyService, $imageService, $categoryService, $tagService, $userService, $groupService);
    // Never actually read either -- same "send() returns before touching
    // anything past the guard" reasoning as $configService above.
    $urlService = \Piwigo\Tests\Support\UrlServiceTestFactory::build();
    new PiwigoInfosSender(piwigoInfosSenderTestLang(), $currentLogger, new ImageStdParams(), $configService, $installationStats, $activityService, $userService, $imageService, $urlService, $currentConfig)->send();

    expect(true)->toBeTrue();
});
