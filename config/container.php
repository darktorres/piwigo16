<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\Configuration\EntityManager\ExistingEntityManager;
use Doctrine\Migrations\Configuration\Migration\ConfigurationArray;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Events;
use Doctrine\ORM\ORMSetup;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger as MonologLogger;
use Piwigo\Activity\ActivityService;
use Piwigo\Cache\CacheFactory;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigRepository;
use Piwigo\Core\ActivityLoggerInterface;
use Piwigo\Core\DefaultLanguageProviderInterface;
use Piwigo\Core\FilterUpdaterInterface;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\MailerInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\TablePrefixListener;
use Piwigo\Filter\FilterService;
use Piwigo\Html\HtmlService;
use Piwigo\Mail\MailService;
use Piwigo\Routing\Router;
use Piwigo\Users\UserService;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Psr16Cache;
use function DI\factory;

// DI\autowire() is the default -- a service with only typed class-reference
// constructor params needs no entry here at all; PHP-DI resolves it via
// reflection. Add an explicit entry only for:
//   - interface bindings (e.g. SomeInterface::class => \DI\get(SomeImpl::class))
//   - non-obvious construction (config values, factory methods, conditional logic)
//   - unresolvable string/config parameters
//
// This grows incrementally, one entry at a time, as later phases find a
// concrete class that genuinely needs one -- never pre-populated ahead of
// need. See src/Piwigo/Core/Container.php.

/**
 * @return array<string, mixed>
 */
return [
    // Interface binding (P23 batch 8c) -- Piwigo\Mail\MailService is
    // L3Presentation (real Template dependency); L2aCoreDomain/
    // L2bExtendedDomain classes (UserService/CommentService) constructor-
    // inject MailerInterface instead of depending on the concrete class
    // directly, per deptrac.yaml's ruleset. See src/Piwigo/Core/
    // MailerInterface.php's own docblock.
    MailerInterface::class => \DI\get(MailService::class),

    // Interface binding (P23 batch 8d) -- Piwigo\Activity\ActivityService is
    // L2bExtendedDomain; Users\UserService/Group\GroupService/Auth\AuthService
    // (all L2aCoreDomain) constructor-inject ActivityLoggerInterface instead
    // of depending on the concrete class directly, per deptrac.yaml's
    // ruleset. See src/Piwigo/Core/ActivityLoggerInterface.php's own
    // docblock.
    ActivityLoggerInterface::class => \DI\get(ActivityService::class),

    // Interface binding (P23 batch 8d) -- Piwigo\Core\Lang::load() is a
    // static L1Infrastructure method that needs the DB-configured default
    // language (Users\UserService::getDefaultLanguage(), L2aCoreDomain).
    // Bound here for consistency with the other 2 interfaces above, though
    // Lang::load() itself is populated via Lang::setDefaultLanguageProvider()
    // from include/common.inc.php (legacy code, not container-managed) --
    // see src/Piwigo/Core/DefaultLanguageProviderInterface.php's own
    // docblock for why a static method can't just constructor-inject this.
    DefaultLanguageProviderInterface::class => \DI\get(UserService::class),

    // Interface binding (P23 batch 8f-1) -- Piwigo\Filter\FilterService is
    // L2bExtendedDomain; Category\CategoryService (L2aCoreDomain)
    // constructor-injects FilterUpdaterInterface instead of depending on
    // the concrete class directly, per deptrac.yaml's ruleset. See
    // src/Piwigo/Core/FilterUpdaterInterface.php's own docblock.
    FilterUpdaterInterface::class => \DI\get(FilterService::class),

    // Interface binding (P23 batch 8f-3) -- Piwigo\Html\HtmlService is
    // L3Presentation; real L1Infrastructure/L2aCoreDomain/L2bExtendedDomain
    // callers constructor- or method-inject HtmlRenderingInterface instead
    // of depending on the concrete class directly, per deptrac.yaml's
    // ruleset. See src/Piwigo/Core/HtmlRenderingInterface.php's own
    // docblock.
    HtmlRenderingInterface::class => \DI\get(HtmlService::class),

    // Unresolvable string param (the routes file path) -- Router::fromFile()
    // needs a path autowire can't provide.
    Router::class => factory(static fn (): Router => Router::fromFile(dirname(__DIR__) . '/config/routes.php')),

    // Non-obvious construction (handler + formatter wiring). Monolog "app"
    // channel only -- the "security" channel (a named $securityLogger
    // parameter) is deferred until a real consumer exists (P11/P16/P28).
    LoggerInterface::class => factory(static function (): LoggerInterface {
        $handler = new RotatingFileHandler(dirname(__DIR__) . '/_data/logs/piwigo.log', 30);
        $handler->setFormatter(new JsonFormatter());

        return new MonologLogger('app', [$handler]);
    }),

    // Non-obvious construction (adapter selection reads env vars --
    // Config::cacheAdapter() doesn't exist yet, P13). No named pools yet
    // (config/permissions/category_tree/tag_cloud/general/rate_limiter) --
    // none have a real consumer today; each gets its own entry only when
    // its owning phase lands.
    CacheItemPoolInterface::class => factory(static fn (): CacheItemPoolInterface => CacheFactory::create()),

    // PSR-16 wraps the same PSR-6 pool instance (container-shared by
    // default) rather than building a second one -- symfony/cache adapters
    // implement Symfony's own Contracts\Cache\CacheInterface, not PSR-16
    // directly; Psr16Cache is the real adapter for that.
    CacheInterface::class => factory(static function (ContainerInterface $c): CacheInterface {
        $pool = $c->get(CacheItemPoolInterface::class);
        if (! $pool instanceof CacheItemPoolInterface) {
            throw new \LogicException('Container returned an unexpected type for ' . CacheItemPoolInterface::class);
        }

        return new Psr16Cache($pool);
    }),

    // Pure factory, no Kernel-awareness -- see DbConnection's own docblock
    // for the server-session-mode deviation from the reference
    // implementation's equivalent.
    Connection::class => factory(static fn (): Connection => DbConnection::build()),

    // ORMSetup::createAttributeMetadataConfig() (not the deprecated
    // ...Configuration() variant -- PHP 8.4+ triggers a deprecation
    // notice for that one, confirmed by reading the installed
    // doctrine/orm source). enableNativeLazyObjects(true): PHP 8.4+
    // lazy objects instead of generated proxy classes -- no proxyDir,
    // nothing for cache:clear to purge. isDevMode: true -- no
    // environment-detection mechanism exists yet to key this on
    // (revisit once one does; only affects metadata/query/result cache
    // behavior, not correctness). TablePrefixListener applies
    // Config::dbPrefix() to every entity's bare table name at metadata
    // load time (PHP attributes can't embed a runtime value directly).
    EntityManagerInterface::class => factory(static function (Connection $conn): EntityManagerInterface {
        $config = ORMSetup::createAttributeMetadataConfig(
            paths: [dirname(__DIR__) . '/src/Piwigo'],
            isDevMode: true,
        );
        $config->enableNativeLazyObjects(true);

        $em = new EntityManager($conn, $config);
        $em->getEventManager()
            ->addEventListener(Events::loadClassMetadata, new TablePrefixListener());

        return $em;
    }),

    // Not constructor-autowired -- EntityRepository's real constructor
    // takes ClassMetadata, which PHP-DI can't autowire. Resolved the
    // standard Doctrine way: EntityManager::getRepository() reads the
    // #[ORM\Entity(repositoryClass: ...)] attribute and instantiates the
    // custom repository class correctly.
    ConfigRepository::class => factory(static function (EntityManagerInterface $em): ConfigRepository {
        $repo = $em->getRepository(ConfigEntry::class);
        if (! $repo instanceof ConfigRepository) {
            throw new \LogicException('Container returned an unexpected type for ' . ConfigRepository::class);
        }

        return $repo;
    }),

    // Backs bin/piwigo's registered `migrations:migrate` command. Reusing
    // Doctrine's own real, fully-featured
    // Doctrine\Migrations\Tools\Console\Command\MigrateCommand (dry-run,
    // rollback, interactive confirmation) beats re-implementing a thinner
    // Piwigo\Command wrapper. config/migrations.php starts with an empty
    // migrations_paths directory -- P15 adds the first real migration.
    DependencyFactory::class => factory(static function (EntityManagerInterface $em): DependencyFactory {
        $raw = require dirname(__DIR__) . '/config/migrations.php';
        if (! is_array($raw)) {
            throw new \RuntimeException('config/migrations.php must return an array.');
        }

        /** @var array<string, mixed> $migrationsConfig */
        $migrationsConfig = [];
        foreach ($raw as $key => $value) {
            if (! is_string($key)) {
                throw new \RuntimeException('config/migrations.php keys must be strings.');
            }
            $migrationsConfig[$key] = $value;
        }

        return DependencyFactory::fromEntityManager(
            new ConfigurationArray($migrationsConfig),
            new ExistingEntityManager($em),
        );
    }),

    // MigrateCommand's constructor param is an OPTIONAL/nullable
    // DependencyFactory -- verified empirically that PHP-DI's default
    // reflection autowiring does NOT inject optional class-typed
    // constructor params (confirmed via a throwaway script showing the
    // property stayed null even with the DependencyFactory entry above
    // already registered), so it needs this explicit factory entry.
    MigrateCommand::class => factory(
        static fn (DependencyFactory $df): MigrateCommand => new MigrateCommand($df),
    ),
];
