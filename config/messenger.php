<?php

declare(strict_types=1);

use Piwigo\Bootstrap\CoreDomainAccessor;
use Piwigo\Bootstrap\ExtendedDomainAccessor;
use Piwigo\Bootstrap\InfrastructureAccessor;
use Piwigo\Bootstrap\PresentationAccessor;
use Piwigo\Bootstrap\RequestBootstrap;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigService;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\InstallationFlag;
use Piwigo\Core\Lang;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbCredentials;
use Piwigo\Db\EntityManagerFactory;
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
use Piwigo\Mail\MailService;
use Piwigo\Metadata\MetadataRepository;
use Piwigo\Metadata\MetadataService;
use Piwigo\PluginConfig\EventDispatcher;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionService;
use Piwigo\Users\CurrentUser;

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
            new Lang(
                new Translator(RequestBootstrap::currentConfig()),
                PresentationAccessor::htmlService(),
                Paths::fromRoot(dirname(__DIR__)),
                new InstallationFlag(),
            ),
            PresentationAccessor::urlService(),
            InfrastructureAccessor::currentLogger(),
            InfrastructureAccessor::storageRegistry(),
            EventDispatcher::get(),
            new ConfigService(EntityManagerFactory::build(DbConnection::build())->getRepository(ConfigEntry::class), EventDispatcher::get(), new CurrentConfig()),
            InfrastructureAccessor::entityManager(),
            ExtendedDomainAccessor::activityService(),
            ExtendedDomainAccessor::metadataService(),
            CoreDomainAccessor::imageService(),
            RequestBootstrap::currentConfig(),
            InfrastructureAccessor::wsContext(),
            RequestBootstrap::currentUser(),
            Paths::fromRoot(dirname(__DIR__)),
            DbCredentials::current()
        ),
        GenerateDerivativeJob::class => static fn (): callable => new GenerateDerivativeHandler(new DerivativeCacheService(RequestBootstrap::currentConfig(), Paths::fromRoot(dirname(__DIR__)))),
        RegenerateAllDerivativesJob::class => static fn (): callable => new RegenerateAllDerivativesHandler(new DerivativeCacheService(RequestBootstrap::currentConfig(), Paths::fromRoot(dirname(__DIR__)))),
        ReindexImagesJob::class => static fn (): callable => new ReindexImagesHandler(new MetadataService(
            new Lang(
                new Translator(RequestBootstrap::currentConfig()),
                PresentationAccessor::htmlService(),
                Paths::fromRoot(dirname(__DIR__)),
                new InstallationFlag(),
            ),
            new MetadataRepository(EntityManagerFactory::build(DbConnection::build())),
            InfrastructureAccessor::currentLogger(),
            EventDispatcher::get(),
            RequestBootstrap::currentConfig(),
            RequestBootstrap::currentUser(),
            RequestBootstrap::sessionService(),
            RequestBootstrap::filterState(),
            Paths::fromRoot(dirname(__DIR__))
        )),
        SendNotificationEmailJob::class => static fn (): callable => new SendNotificationEmailHandler(new MailService(
            new Lang(
                new Translator(RequestBootstrap::currentConfig()),
                PresentationAccessor::htmlService(),
                Paths::fromRoot(dirname(__DIR__)),
                new InstallationFlag(),
            ),
            RequestBootstrap::currentConfig(),
            new DeploymentPolicy(),
            new PageState(),
            Paths::fromRoot(dirname(__DIR__)),
            new SessionService(EntityManagerFactory::build(DbConnection::build())->getRepository(SessionEntity::class), RequestBootstrap::currentConfig()),
            new Translator(RequestBootstrap::currentConfig()),
            EventDispatcher::get(),
            new CurrentUser(RequestBootstrap::currentConfig()),
            PresentationAccessor::urlService(),
        )),
    ],
];
