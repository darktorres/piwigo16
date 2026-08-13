<?php

declare(strict_types=1);

use Piwigo\Auth\AccessLevelChecker;
use Piwigo\Bootstrap\InfrastructureAccessor;
use Piwigo\Bootstrap\PresentationAccessor;
use Piwigo\Bootstrap\RequestBootstrap;
use Piwigo\Category\CategoryRepository;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Group\GroupEntity;
use Piwigo\Image\DerivativeCacheService;
use Piwigo\Job\BatchUploadJob;
use Piwigo\Job\GenerateDerivativeJob;
use Piwigo\Job\Handler\BatchUploadHandler;
use Piwigo\Job\Handler\GenerateDerivativeHandler;
use Piwigo\Job\Handler\RegenerateAllDerivativesHandler;
use Piwigo\Job\Handler\ReindexImagesHandler;
use Piwigo\Job\Handler\SendNotificationEmailHandler;
use Piwigo\Job\RegenerateAllDerivativesJob;
use Piwigo\Job\ReindexImagesJob;
use Piwigo\Job\SendNotificationEmailJob;
use Piwigo\Lang\Translator;
use Piwigo\Metadata\MetadataRepository;
use Piwigo\Metadata\MetadataService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;

/**
 * Transport + routing + handler-factory configuration for
 * Piwigo\Job\MessengerFactory. Each handler entry is a lazy closure,
 * matching config/storage.php's own shape -- no DI container involvement
 * (this project's real container, PHP-DI, is arch-test-restricted to
 * Bootstrap/ and index.php). Doctrine (DB-polling, FOR UPDATE SKIP
 * LOCKED) transport only this phase, matching this project's existing
 * DB-first infrastructure preference -- Redis transport switch
 * (PIWIGO_REDIS_DSN) deferred, no such config exists yet.
 */
return [
    'transport_table' => 'messenger_messages',
    'transport_queue' => 'async',

    // message class => sender alias (all 5 route to the single 'async'
    // transport this phase; no fan-out/priority routing needed yet)
    'routing' => [
        BatchUploadJob::class => 'async',
        GenerateDerivativeJob::class => 'async',
        RegenerateAllDerivativesJob::class => 'async',
        ReindexImagesJob::class => 'async',
        SendNotificationEmailJob::class => 'async',
    ],

    // message class => handler factory
    'handlers' => [
        // BatchUploadHandler resolves UploadService itself via
        // Bootstrap\AdminAccessor::uploadService() (see that handler's
        // own docblock) -- urlService is its only real remaining
        // constructor dependency.
        BatchUploadJob::class => static fn (): callable => new BatchUploadHandler(
            PresentationAccessor::urlService(),
        ),
        GenerateDerivativeJob::class => static fn (): callable => new GenerateDerivativeHandler(new DerivativeCacheService(RequestBootstrap::currentConfig(), Paths::fromRoot(dirname(__DIR__)))),
        RegenerateAllDerivativesJob::class => static fn (): callable => new RegenerateAllDerivativesHandler(new DerivativeCacheService(RequestBootstrap::currentConfig(), Paths::fromRoot(dirname(__DIR__)))),
        ReindexImagesJob::class => static fn (): callable => new ReindexImagesHandler(new MetadataService(
            new Lang(
                new Translator(RequestBootstrap::currentConfig(), InfrastructureAccessor::translationsCachePool()),
                PresentationAccessor::htmlService(),
                Paths::fromRoot(dirname(__DIR__)),
                new InstallationFlag(),
            ),
            new MetadataRepository(InfrastructureAccessor::entityManager()),
            InfrastructureAccessor::currentLogger(),
            RequestBootstrap::eventDispatcher(),
            RequestBootstrap::currentConfig(),
            RequestBootstrap::currentUser(),
            RequestBootstrap::sessionService(),
            Paths::fromRoot(dirname(__DIR__))
        ), new PermissionService(
            new PermissionRepository(InfrastructureAccessor::entityManager()),
            InfrastructureAccessor::entityManager()->getRepository(GroupEntity::class),
            new CategoryRepository(InfrastructureAccessor::entityManager(), RequestBootstrap::currentConfig()),
            RequestBootstrap::currentUser(),
            RequestBootstrap::filterState(),
            new AccessLevelChecker(RequestBootstrap::currentUser(), RequestBootstrap::currentConfig()),
        ), InfrastructureAccessor::entityManager()),
        SendNotificationEmailJob::class => static fn (): callable => new SendNotificationEmailHandler(PresentationAccessor::mailService()),
    ],
];
