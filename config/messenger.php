<?php

declare(strict_types=1);

use Piwigo\Db\DbConnection;
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
use Piwigo\Mail\MailService;
use Piwigo\Metadata\MetadataRepository;
use Piwigo\Metadata\MetadataService;

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
        // ConfigService is built directly here (same EntityManagerFactory
        // recipe as ReindexImagesJob's MetadataService below), not via the
        // CurrentConfigService shim -- every handler factory in this map
        // is invoked eagerly when the bus is built, not lazily per
        // dispatch, so a shim that throws when nothing has activated it
        // yet would break every job type's construction, not just this
        // one's.
        BatchUploadJob::class => static fn (): callable => new BatchUploadHandler(
            new \Piwigo\Core\Lang(
                new \Piwigo\Lang\Translator(\Piwigo\Bootstrap\RequestBootstrap::currentConfig()),
                \Piwigo\Bootstrap\PresentationAccessor::htmlService(),
                \Piwigo\Core\Paths::fromRoot(dirname(__DIR__)),
                new \Piwigo\Core\InstallationFlag(),
            ),
            \Piwigo\Bootstrap\PresentationAccessor::urlService(),
            \Piwigo\Bootstrap\InfrastructureAccessor::currentLogger(),
            \Piwigo\Bootstrap\InfrastructureAccessor::storageRegistry(),
            \Piwigo\PluginConfig\EventDispatcher::get(),
            new \Piwigo\Config\ConfigService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Config\ConfigEntry::class), \Piwigo\PluginConfig\EventDispatcher::get(), new \Piwigo\Config\CurrentConfig()),
            \Piwigo\Bootstrap\InfrastructureAccessor::entityManager(),
            \Piwigo\Bootstrap\ExtendedDomainAccessor::activityService(),
            \Piwigo\Bootstrap\ExtendedDomainAccessor::metadataService(),
            \Piwigo\Bootstrap\CoreDomainAccessor::imageService(),
            \Piwigo\Bootstrap\RequestBootstrap::currentConfig(),
            \Piwigo\Bootstrap\InfrastructureAccessor::wsContext(),
            \Piwigo\Bootstrap\RequestBootstrap::currentUser()
        ),
        GenerateDerivativeJob::class => static fn (): callable => new GenerateDerivativeHandler(new DerivativeCacheService(\Piwigo\Bootstrap\RequestBootstrap::currentConfig())),
        RegenerateAllDerivativesJob::class => static fn (): callable => new RegenerateAllDerivativesHandler(new DerivativeCacheService(\Piwigo\Bootstrap\RequestBootstrap::currentConfig())),
        ReindexImagesJob::class => static fn (): callable => new ReindexImagesHandler(new MetadataService(
            new \Piwigo\Core\Lang(
                new \Piwigo\Lang\Translator(\Piwigo\Bootstrap\RequestBootstrap::currentConfig()),
                \Piwigo\Bootstrap\PresentationAccessor::htmlService(),
                \Piwigo\Core\Paths::fromRoot(dirname(__DIR__)),
                new \Piwigo\Core\InstallationFlag(),
            ),
            new MetadataRepository(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())),
            \Piwigo\Bootstrap\InfrastructureAccessor::currentLogger(),
            \Piwigo\PluginConfig\EventDispatcher::get(),
            \Piwigo\Bootstrap\RequestBootstrap::currentConfig(),
            \Piwigo\Bootstrap\RequestBootstrap::currentUser(),
            \Piwigo\Bootstrap\RequestBootstrap::sessionService()
        )),
        SendNotificationEmailJob::class => static fn (): callable => new SendNotificationEmailHandler(new MailService(
            new \Piwigo\Core\Lang(
                new \Piwigo\Lang\Translator(\Piwigo\Bootstrap\RequestBootstrap::currentConfig()),
                \Piwigo\Bootstrap\PresentationAccessor::htmlService(),
                \Piwigo\Core\Paths::fromRoot(dirname(__DIR__)),
                new \Piwigo\Core\InstallationFlag(),
            ),
            \Piwigo\Bootstrap\RequestBootstrap::currentConfig(),
            new \Piwigo\Config\DeploymentPolicy(),
            new \Piwigo\Core\PageState(),
            \Piwigo\Core\Paths::fromRoot(dirname(__DIR__)),
            new \Piwigo\Session\SessionService(\Piwigo\Db\EntityManagerFactory::build(DbConnection::build())->getRepository(\Piwigo\Session\SessionEntity::class), \Piwigo\Bootstrap\RequestBootstrap::currentConfig()),
            new \Piwigo\Lang\Translator(\Piwigo\Bootstrap\RequestBootstrap::currentConfig()),
            \Piwigo\PluginConfig\EventDispatcher::get(),
            new \Piwigo\Users\CurrentUser(\Piwigo\Bootstrap\RequestBootstrap::currentConfig()),
            \Piwigo\Bootstrap\PresentationAccessor::urlService(),
        )),
    ],
];
