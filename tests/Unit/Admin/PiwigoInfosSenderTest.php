<?php

declare(strict_types=1);

use Piwigo\Admin\PiwigoInfosSender;
use Piwigo\Config\CurrentConfig;
use Piwigo\Core\CurrentLogger;
use Piwigo\Core\Logger;
use Piwigo\Image\ImageStdParams;

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
    CurrentConfig::setSendPiwigoInfos(true);
});

test('send returns immediately without touching the DB or network when telemetry is disabled', function (): void {
    $currentLogger = new CurrentLogger();
    $currentLogger->set(new Logger(['severity' => Logger::OFF]));
    CurrentConfig::setSendPiwigoInfos(false);

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
    );
    // Never actually read either -- same "send() returns before touching
    // anything past the guard" reasoning as $configService above.
    $rateService = new \Piwigo\Rate\RateService(
        \Piwigo\Db\EntityManagerFactory::build()->getRepository(\Piwigo\Rate\RateEntity::class),
        new \Piwigo\Auth\CookieService(),
        new \Piwigo\PluginConfig\EventDispatcher(),
        new \Piwigo\Users\CurrentUser(),
    );
    $historyService = new \Piwigo\History\HistoryService(
        \Piwigo\Db\EntityManagerFactory::build()->getRepository(\Piwigo\History\HistoryEntity::class),
        $configService,
        $currentLogger,
        new \Piwigo\PluginConfig\EventDispatcher(),
        new \Piwigo\Core\PageState(),
        new \Piwigo\Users\CurrentUser(),
    );
    $activityService = new \Piwigo\Activity\ActivityService(
        \Piwigo\Db\EntityManagerFactory::build()->getRepository(\Piwigo\Activity\ActivityEntity::class),
    );
    // Never actually read either -- same "send() returns before touching
    // anything past the guard" reasoning as $configService above.
    $userService = new \Piwigo\Users\UserService(
        \Piwigo\Db\EntityManagerFactory::build()->getRepository(\Piwigo\Users\UserInfoEntity::class),
        \Piwigo\Db\EntityManagerFactory::build()->getRepository(\Piwigo\Group\GroupEntity::class),
        new \Piwigo\Mail\MailService(),
        $activityService,
        new \Piwigo\Html\HtmlService(),
        \Piwigo\Db\DbConnection::build(),
        new \Piwigo\Session\SessionService(\Piwigo\Db\EntityManagerFactory::build()->getRepository(\Piwigo\Session\SessionEntity::class)),
        new \Piwigo\PluginConfig\EventDispatcher(),
        new \Piwigo\Config\DeploymentPolicy(),
        new \Piwigo\Users\CurrentUser(),
    );
    $imageService = new \Piwigo\Image\ImageService(
        \Piwigo\Db\EntityManagerFactory::build()->getRepository(\Piwigo\Image\ImageEntity::class),
        $activityService,
        new \Piwigo\Session\SessionService(\Piwigo\Db\EntityManagerFactory::build()->getRepository(\Piwigo\Session\SessionEntity::class)),
        new \Piwigo\PluginConfig\EventDispatcher(),
    );
    $permissionService = new \Piwigo\Permission\PermissionService(
        new \Piwigo\Permission\PermissionRepository(\Piwigo\Db\EntityManagerFactory::build()),
        \Piwigo\Db\EntityManagerFactory::build()->getRepository(\Piwigo\Group\GroupEntity::class),
        \Piwigo\Db\EntityManagerFactory::build()->getRepository(\Piwigo\Category\CategoryEntity::class),
    );
    $categoryService = new \Piwigo\Category\CategoryService(
        \Piwigo\Db\EntityManagerFactory::build()->getRepository(\Piwigo\Category\CategoryEntity::class),
        $permissionService,
    );
    $tagService = new \Piwigo\Tag\TagService(
        \Piwigo\Db\EntityManagerFactory::build()->getRepository(\Piwigo\Tag\TagEntity::class),
        $permissionService,
        $activityService,
        new \Piwigo\PluginConfig\EventDispatcher(),
        new \Piwigo\Users\CurrentUser(),
    );
    $groupService = new \Piwigo\Group\GroupService(
        \Piwigo\Db\EntityManagerFactory::build()->getRepository(\Piwigo\Group\GroupEntity::class),
        $activityService,
        new \Piwigo\Audit\AuditService(\Piwigo\Db\EntityManagerFactory::build()->getRepository(\Piwigo\Audit\AuditLogEntity::class)),
        $configService,
        new \Piwigo\PluginConfig\EventDispatcher(),
        new \Piwigo\Users\CurrentUser(),
    );
    $installationStats = new \Piwigo\Admin\InstallationStats($rateService, $historyService, $imageService, $categoryService, $tagService, $userService, $groupService);
    // Never actually read either -- same "send() returns before touching
    // anything past the guard" reasoning as $configService above.
    $urlService = new \Piwigo\Url\UrlService(new \Piwigo\Html\HtmlService(), new \Piwigo\Url\RootPathOverride());
    new PiwigoInfosSender($currentLogger, new ImageStdParams(), $configService, $installationStats, $activityService, $userService, $imageService, $urlService)->send();

    expect(true)->toBeTrue();
});
