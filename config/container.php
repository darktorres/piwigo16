<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\Migrations\DependencyFactory;
use Doctrine\Migrations\Tools\Console\Command\MigrateCommand;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger as MonologLogger;
use Piwigo\Activity\ActivityEntity;
use Piwigo\Activity\ActivityRepository;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\Extensions\ExtensionIgnoredUpdateEntity;
use Piwigo\Admin\Extensions\ExtensionIgnoredUpdateRepository;
use Piwigo\Admin\Integrity\IntegrityIgnoredAnomalyEntity;
use Piwigo\Admin\Integrity\IntegrityIgnoredAnomalyRepository;
use Piwigo\Admin\PiwigoInfosSender;
use Piwigo\Audit\AuditLogEntity;
use Piwigo\Audit\AuditRepository;
use Piwigo\Auth\UserFailedLoginEntity;
use Piwigo\Auth\UserFailedLoginRepository;
use Piwigo\Bootstrap\RedirectService;
use Piwigo\Cache\CacheFactory;
use Piwigo\Cache\PersistentCache;
use Piwigo\Cache\PersistentFileCache;
use Piwigo\Caddie\CaddieEntity;
use Piwigo\Caddie\CaddieRepository;
use Piwigo\Comment\CommentEntity;
use Piwigo\Comment\CommentRepository;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigRepository;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\ActivityLoggerInterface;
use Piwigo\Core\CommentCounterInterface;
use Piwigo\Core\DefaultLanguageProviderInterface;
use Piwigo\Core\FilterUpdaterInterface;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\MailerInterface;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\TelemetrySenderInterface;
use Piwigo\Core\TemplateInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\WebmasterMailProviderInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbCredentials;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\MigrationDependencyFactory;
use Piwigo\Feed\FeedEntity;
use Piwigo\Feed\FeedRepository;
use Piwigo\Filter\FilterService;
use Piwigo\Group\GroupEntity;
use Piwigo\Group\GroupRepository;
use Piwigo\History\HistoryEntity;
use Piwigo\History\HistoryRepository;
use Piwigo\Html\HtmlService;
use Piwigo\Image\ImageEntity;
use Piwigo\Image\ImageRepository;
use Piwigo\Image\ImageStdParams;
use Piwigo\Lang\LangRepository;
use Piwigo\Lang\LanguageEntity;
use Piwigo\Mail\MailService;
use Piwigo\Notification\NotificationByMailRepository;
use Piwigo\Notification\UserMailNotificationEntity;
use Piwigo\PluginConfig\PluginEntity;
use Piwigo\PluginConfig\PluginRepository;
use Piwigo\Rate\RateEntity;
use Piwigo\Rate\RateRepository;
use Piwigo\Routing\Router;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionRepository;
use Piwigo\Site\SiteEntity;
use Piwigo\Site\SiteRepository;
use Piwigo\Storage\StorageRegistry;
use Piwigo\Tag\TagEntity;
use Piwigo\Tag\TagRepository;
use Piwigo\Template\Template;
use Piwigo\Url\UrlService;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;
use Piwigo\Validation\InputValidator;
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

    // Interface binding (Legacy Coupling Retirement: DI+DBAL migration
    // Phase 1a) -- Piwigo\Comment\CommentRepository is L2bExtendedDomain;
    // Category\CategoryDefaultRenderer (L2aCoreDomain) constructor-injects
    // CommentCounterInterface instead of depending on the concrete class
    // directly, per deptrac.yaml's ruleset. See src/Piwigo/Core/
    // CommentCounterInterface.php's own docblock.
    CommentCounterInterface::class => \DI\get(CommentRepository::class),

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

    // Interface binding (Legacy Coupling Retirement Phase 4b) --
    // Piwigo\Bootstrap\RedirectService is L4Integration (its real body
    // calls Piwigo\Bootstrap\PageTail::render()); real callers span every
    // layer from L1Infrastructure to L4Integration, so they constructor-
    // or method-inject RedirectServiceInterface instead of depending on
    // the concrete class directly, per deptrac.yaml's ruleset. See
    // src/Piwigo/Core/RedirectServiceInterface.php's own docblock.
    RedirectServiceInterface::class => \DI\get(RedirectService::class),

    // Interface binding (Legacy Coupling Retirement Phase 4c) --
    // Piwigo\Url\UrlService is L2bExtendedDomain; real callers span every
    // layer, so they constructor- or method-inject UrlServiceInterface
    // instead of depending on the concrete class directly, per
    // deptrac.yaml's ruleset. See src/Piwigo/Core/UrlServiceInterface.php's
    // own docblock.
    UrlServiceInterface::class => \DI\get(UrlService::class),

    // Abstract-class binding (singleton/service-locator elimination
    // campaign, Phase 0 pilot) -- Piwigo\Cache\PersistentCache is abstract,
    // so PHP-DI can't autowire it without being told which concrete
    // implementation to construct. Replaces the former
    // Piwigo\Cache\CurrentPersistentCache static facade (deleted): the
    // value never actually varied per request (always PersistentFileCache),
    // so there was nothing genuinely request-scoped to hold in a shared
    // mutable instance -- an ordinary binding is enough.
    PersistentCache::class => \DI\get(PersistentFileCache::class),

    // Interface binding (P23 batch 8f-4) -- Piwigo\Admin\PiwigoInfosSender
    // is L4Integration; Piwigo\Page\PageTailRenderer (L3Presentation)
    // constructor-injects TelemetrySenderInterface instead of depending on
    // the concrete class directly, per deptrac.yaml's ruleset. See
    // src/Piwigo/Core/TelemetrySenderInterface.php's own docblock.
    TelemetrySenderInterface::class => \DI\get(PiwigoInfosSender::class),

    // Interface binding (Legacy Coupling Retirement Track A) --
    // Piwigo\Template\Template is L3Presentation; a handful of
    // L2aCoreDomain/L2bExtendedDomain callers method-inject
    // TemplateInterface instead of depending on the concrete class
    // directly, per deptrac.yaml's ruleset. A factory, not \DI\get(),
    // because the current request's Template is constructed dynamically
    // per-request (runtime theme/path parameters) -- see
    // src/Piwigo/Core/TemplateInterface.php's own docblock and
    // Piwigo\Template\CurrentTemplate.
    TemplateInterface::class => factory(static fn (): Template => \Piwigo\Template\CurrentTemplate::current()->get()),

    // Interface binding (P23 batch 8f-4) -- Piwigo\Users\UserRepository
    // provides the webmaster mail address; Piwigo\Mail\MailService takes
    // WebmasterMailProviderInterface as an optional constructor test seam
    // (lazily defaulting to the real UserRepository). Bound here for
    // consistency with the other Core interfaces. See
    // src/Piwigo/Core/WebmasterMailProviderInterface.php's own docblock.
    //
    // No equivalent entry for Piwigo\Core\ThemeConfProviderInterface (also
    // P23 batch 8f-4): its implementation is the request's own
    // Piwigo\Template\Template instance (constructed with runtime
    // path/theme strings, never container-managed) -- SrcImage::themeConf()
    // reaches it via Piwigo\Template\CurrentTemplate::current()->get()
    // instead (singleton/service-locator elimination campaign, Phase 6).
    WebmasterMailProviderInterface::class => \DI\get(UserRepository::class),

    // Unresolvable string param (the routes file path) -- Router::fromFile()
    // needs a path autowire can't provide.
    Router::class => factory(static fn (): Router => Router::fromFile(dirname(__DIR__) . '/config/routes.php')),

    // Factory binding (singleton/service-locator elimination campaign,
    // Phase 2) -- replaces Piwigo\Storage\StorageRegistry's former
    // self-managed static singleton (current()/set()/reset()): the value
    // never actually varies per request (always built from the same
    // config/storage.php), so there's nothing genuinely request-scoped to
    // hold in a shared mutable instance, same "delete the facade, use a
    // plain factory" outcome as the Phase 0 pilot (CurrentPersistentCache).
    StorageRegistry::class => factory(static fn (): StorageRegistry => StorageRegistry::fromConfig(dirname(__DIR__) . '/config/storage.php')),

    // Factory binding (singleton/service-locator elimination campaign,
    // Phase 3) -- replaces Piwigo\Db\DbCredentials's former self-managed
    // static memo (current()/reset()/seed()). Unlike StorageRegistry
    // above, this value genuinely can change mid-request (InstallWizard's
    // own seed() call, see that class's own docblock), so the shared
    // instance is mutable (reload()/seed() mutate it in place) rather than
    // deleted outright.
    DbCredentials::class => factory(static fn (): DbCredentials => DbCredentials::fromEnv()),

    // Factory binding (singleton/service-locator elimination campaign,
    // Phase 4) -- replaces DeploymentPolicy's former self-managed static
    // memo (current()/set()/reset()). $paths is autowired from the
    // container's own Paths::class binding (set by Kernel::boot() when a
    // real Paths is passed); shared/memoized for the container's lifetime
    // like every other autowired service, matching the former per-request
    // memo exactly.
    DeploymentPolicy::class => factory(static fn (\Piwigo\Core\Paths $paths): DeploymentPolicy => DeploymentPolicy::load($paths)),

    // Factory binding (singleton/service-locator elimination campaign,
    // Phase 4) -- replaces ImageStdParams's former self-managed static
    // memo (get_defined_type_map()/get_by_type()/etc., all `private
    // static` properties written by load_from_db()). load_from_db() calls
    // its own repositories via a fresh EntityManagerFactory::build(
    // DbConnection::build()) internally -- no Paths/Connection param
    // needed here, same reasoning as its own docblock already gives for
    // not routing through the container-shared EM.
    ImageStdParams::class => factory(static function (): ImageStdParams {
        $imageStdParams = new ImageStdParams();
        $imageStdParams->load_from_db();
        return $imageStdParams;
    }),

    // Non-obvious construction -- confirmed bug fix, found during this
    // phase's full-suite verification (WsHistoryTest.php's
    // assertHistorySearchIsAHackingAttempt() cases): PHP-DI's default
    // autowiring only injects REQUIRED constructor parameters via
    // reflection; a parameter with a default value (InputValidator's own
    // `?HtmlRenderingInterface $htmlRenderer = null`, kept optional so
    // InputValidatorTest.php's `new InputValidator()` sites keep their
    // "never wired up" behavior) is left at its default even when the
    // interface itself is bound above and the container is fully booted --
    // confirmed empirically, not assumed. Without this entry, every
    // container-resolved InputValidator (i.e. every real WS/admin caller
    // via createStatic()) silently loses its renderer, so fatalError()
    // falls through to a bare RuntimeException instead of the intended
    // ResponseReadyException (HtmlService::fatalError()'s own real "[Hacking
    // attempt]" error page) -- caught by ExceptionHandlerMiddleware's
    // generic catch-all and flattened to a content-free 500. This factory
    // makes the intent (real renderer whenever one exists) explicit instead
    // of relying on autowiring to do something it never did.
    InputValidator::class => factory(static fn (HtmlRenderingInterface $htmlRenderer): InputValidator => new InputValidator($htmlRenderer)),

    // Non-obvious construction (handler + formatter wiring). Monolog "app"
    // channel only -- the "security" channel (a named $securityLogger
    // parameter) is deferred until a real consumer exists (P11/P16/P27).
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

    // Delegates to EntityManagerFactory -- the same construction recipe
    // (metadata paths, enableNativeLazyObjects, TablePrefixListener) is
    // also needed by callers that can't participate in constructor
    // injection at all (a static L1Infrastructure method, a self-managed
    // singleton's fallback branch) -- see that factory's own docblock.
    EntityManagerInterface::class => factory(static fn (Connection $conn): EntityManagerInterface => EntityManagerFactory::build($conn)),

    // Not constructor-autowired -- EntityRepository's real constructor
    // takes ClassMetadata, which PHP-DI can't autowire. Resolved the
    // standard Doctrine way: EntityManager::getRepository() reads the
    // #[ORM\Entity(repositoryClass: ...)] attribute and instantiates the
    // custom repository class correctly -- guaranteed by that same
    // attribute, so the declared return type is enough (PHP itself throws
    // a TypeError if it's ever wrong; phpstan-doctrine's own generic
    // tracking confirms it statically too, once installed).
    ConfigRepository::class => factory(static fn (EntityManagerInterface $em): ConfigRepository => $em->getRepository(ConfigEntry::class)),

    LangRepository::class => factory(static fn (EntityManagerInterface $em): LangRepository => $em->getRepository(LanguageEntity::class)),

    AuditRepository::class => factory(static fn (EntityManagerInterface $em): AuditRepository => $em->getRepository(AuditLogEntity::class)),

    SessionRepository::class => factory(static fn (EntityManagerInterface $em): SessionRepository => $em->getRepository(SessionEntity::class)),

    GroupRepository::class => factory(static fn (EntityManagerInterface $em): GroupRepository => $em->getRepository(GroupEntity::class)),

    TagRepository::class => factory(static fn (EntityManagerInterface $em): TagRepository => $em->getRepository(TagEntity::class)),

    ImageRepository::class => factory(static fn (EntityManagerInterface $em): ImageRepository => $em->getRepository(ImageEntity::class)),

    CaddieRepository::class => factory(static fn (EntityManagerInterface $em): CaddieRepository => $em->getRepository(CaddieEntity::class)),

    NotificationByMailRepository::class => factory(static fn (EntityManagerInterface $em): NotificationByMailRepository => $em->getRepository(UserMailNotificationEntity::class)),

    SiteRepository::class => factory(static fn (EntityManagerInterface $em): SiteRepository => $em->getRepository(SiteEntity::class)),

    FeedRepository::class => factory(static fn (EntityManagerInterface $em): FeedRepository => $em->getRepository(FeedEntity::class)),

    ActivityRepository::class => factory(static fn (EntityManagerInterface $em): ActivityRepository => $em->getRepository(ActivityEntity::class)),

    CommentRepository::class => factory(static fn (EntityManagerInterface $em): CommentRepository => $em->getRepository(CommentEntity::class)),

    PluginRepository::class => factory(static fn (EntityManagerInterface $em): PluginRepository => $em->getRepository(PluginEntity::class)),

    RateRepository::class => factory(static fn (EntityManagerInterface $em): RateRepository => $em->getRepository(RateEntity::class)),

    HistoryRepository::class => factory(static fn (EntityManagerInterface $em): HistoryRepository => $em->getRepository(HistoryEntity::class)),

    ExtensionIgnoredUpdateRepository::class => factory(static fn (EntityManagerInterface $em): ExtensionIgnoredUpdateRepository => $em->getRepository(ExtensionIgnoredUpdateEntity::class)),

    UserFailedLoginRepository::class => factory(static fn (EntityManagerInterface $em): UserFailedLoginRepository => $em->getRepository(UserFailedLoginEntity::class)),

    IntegrityIgnoredAnomalyRepository::class => factory(static fn (EntityManagerInterface $em): IntegrityIgnoredAnomalyRepository => $em->getRepository(IntegrityIgnoredAnomalyEntity::class)),

    // Backs bin/piwigo's registered `migrations:migrate` command --
    // CLI usage only. Piwigo\Admin\Install\InstallWizard deliberately does
    // NOT resolve this container entry: its own constructor docblock
    // explains why (PHP-DI caches this container's first resolution of
    // Connection::class/EntityManagerInterface::class for the rest of the
    // request, which would permanently bind to whatever stale, pre-seed
    // credentials were current before install's own mid-request
    // DbCredentials::seed() call runs) -- it calls
    // MigrationDependencyFactory::build() directly instead, from its own
    // already-seeded connection, following the same pattern already
    // established there for every other DB-touching service it needs.
    //
    // The actual construction (table_storage.table_name prefixing --
    // every other table in this schema carries PIWIGO_DB_PREFIX so
    // multiple installs can share one database; an unprefixed
    // doctrine_migration_versions ledger table would silently defeat that
    // guarantee) lives in MigrationDependencyFactory itself, not here, so
    // both this container entry and InstallWizard's own direct call share
    // one implementation.
    DependencyFactory::class => factory(
        static fn (EntityManagerInterface $em, DbCredentials $dbCredentials): DependencyFactory => MigrationDependencyFactory::build($em, $dbCredentials),
    ),

    // MigrateCommand's constructor param is an OPTIONAL/nullable
    // DependencyFactory -- verified (same as every other optional
    // class-typed constructor param in this codebase) that PHP-DI's
    // default reflection autowiring does NOT inject it, so it needs this
    // explicit factory entry ([[feedback_phpdi_skips_optional_constructor_params]]).
    MigrateCommand::class => factory(
        static fn (DependencyFactory $df): MigrateCommand => new MigrateCommand($df),
    ),
];
