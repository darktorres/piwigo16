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
use Piwigo\Bootstrap\RouteDefinitions;
use Piwigo\Cache\CacheFactory;
use Piwigo\Cache\CalendarNavCachePool;
use Piwigo\Cache\CategoryTreeCachePool;
use Piwigo\Cache\ConfigCachePool;
use Piwigo\Cache\EffectivePermissionsCachePool;
use Piwigo\Cache\ExtensionUpdateCachePool;
use Piwigo\Cache\NotificationsCachePool;
use Piwigo\Cache\PermissionsCachePool;
use Piwigo\Cache\PersistentCache;
use Piwigo\Cache\PersistentFileCache;
use Piwigo\Cache\SearchResultsCachePool;
use Piwigo\Cache\SectionImageIdsCachePool;
use Piwigo\Cache\TagCloudCachePool;
use Piwigo\Cache\TranslationsCachePool;
use Piwigo\Caddie\CaddieEntity;
use Piwigo\Caddie\CaddieRepository;
use Piwigo\Comment\CommentEntity;
use Piwigo\Comment\CommentRepository;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigRepository;
use Piwigo\Config\CurrentConfig;
use Piwigo\Config\DeploymentPolicy;
use Piwigo\Core\ActivityLoggerInterface;
use Piwigo\Core\CommentCounterInterface;
use Piwigo\Core\DefaultLanguageProviderInterface;
use Piwigo\Core\FilterUpdaterInterface;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\MailerInterface;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\TelemetrySenderInterface;
use Piwigo\Core\TemplateInterface;
use Piwigo\Core\ThemeEntity;
use Piwigo\Core\ThemeRepository;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Core\WebmasterMailProviderInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbCredentials;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Db\SortRenderer;
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
use Piwigo\Job\MessengerFactory;
use Piwigo\Lang\LangRepository;
use Piwigo\Lang\LanguageEntity;
use Piwigo\Mail\MailRecipientRepository;
use Piwigo\Mail\MailRecipientRepositoryInterface;
use Piwigo\Mail\MailService;
use Piwigo\Notification\NotificationByMailRepository;
use Piwigo\Notification\UserMailNotificationEntity;
use Piwigo\PluginConfig\PluginEntity;
use Piwigo\PluginConfig\PluginMigrationEntity;
use Piwigo\PluginConfig\PluginMigrationRepository;
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
use Piwigo\Template\CurrentTemplate;
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
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\Messenger\Command\ConsumeMessagesCommand;
use function DI\factory;
use function DI\get;

// DI\autowire() is the default -- a service with only typed class-reference
// constructor params needs no entry here at all; PHP-DI resolves it via
// reflection. Add an explicit entry only for:
//   - interface bindings (e.g. SomeInterface::class => \DI\get(SomeImpl::class))
//   - non-obvious construction (config values, factory methods, conditional logic)
//   - unresolvable string/config parameters
//
// This grows incrementally, one entry at a time, only when a concrete
// class genuinely needs one -- never pre-populated ahead of need. See
// src/Piwigo/Core/Container.php.

/**
 * @return array<string, mixed>
 */
return [
    // Interface binding -- Piwigo\Mail\MailService is L3Presentation (real
    // Template dependency); L2aCoreDomain/L2bExtendedDomain classes
    // (UserService/CommentService) constructor-inject MailerInterface
    // instead of depending on the concrete class directly, per
    // deptrac.yaml's ruleset. See src/Piwigo/Core/MailerInterface.php's
    // own docblock.
    MailerInterface::class => get(MailService::class),

    // Interface binding -- Piwigo\Activity\ActivityService is
    // L2bExtendedDomain; Users\UserService/Group\GroupService/Auth\AuthService
    // (all L2aCoreDomain) constructor-inject ActivityLoggerInterface instead
    // of depending on the concrete class directly, per deptrac.yaml's
    // ruleset. See src/Piwigo/Core/ActivityLoggerInterface.php's own
    // docblock.
    ActivityLoggerInterface::class => get(ActivityService::class),

    // Interface binding -- Piwigo\Comment\CommentRepository is
    // L2bExtendedDomain; Category\CategoryDefaultRenderer (L2aCoreDomain)
    // constructor-injects CommentCounterInterface instead of depending on
    // the concrete class directly, per deptrac.yaml's ruleset. See
    // src/Piwigo/Core/CommentCounterInterface.php's own docblock.
    CommentCounterInterface::class => get(CommentRepository::class),

    // Interface binding -- Piwigo\Core\Lang::load() is a static
    // L1Infrastructure method that needs the DB-configured default
    // language (Users\UserService::getDefaultLanguage(), L2aCoreDomain).
    // Bound here for consistency with the other 2 interfaces above;
    // Lang::load() itself is populated via
    // Lang::setDefaultLanguageProvider() from include/common.inc.php (not
    // container-managed) -- see
    // src/Piwigo/Core/DefaultLanguageProviderInterface.php's own docblock
    // for why a static method can't just constructor-inject this.
    DefaultLanguageProviderInterface::class => get(UserService::class),

    // Interface binding -- Piwigo\Filter\FilterService is L2bExtendedDomain;
    // Category\CategoryService (L2aCoreDomain) constructor-injects
    // FilterUpdaterInterface instead of depending on the concrete class
    // directly, per deptrac.yaml's ruleset. See
    // src/Piwigo/Core/FilterUpdaterInterface.php's own docblock.
    FilterUpdaterInterface::class => get(FilterService::class),

    // Interface binding -- Piwigo\Html\HtmlService is L3Presentation; real
    // L1Infrastructure/L2aCoreDomain/L2bExtendedDomain callers constructor-
    // or method-inject HtmlRenderingInterface instead of depending on the
    // concrete class directly, per deptrac.yaml's ruleset. See
    // src/Piwigo/Core/HtmlRenderingInterface.php's own docblock.
    HtmlRenderingInterface::class => get(HtmlService::class),

    // Interface binding -- Piwigo\Bootstrap\RedirectService is L4Integration
    // (its real body calls Piwigo\Bootstrap\PageTail::render()); real
    // callers span every layer from L1Infrastructure to L4Integration, so
    // they constructor- or method-inject RedirectServiceInterface instead
    // of depending on the concrete class directly, per deptrac.yaml's
    // ruleset. See src/Piwigo/Core/RedirectServiceInterface.php's own
    // docblock.
    RedirectServiceInterface::class => get(RedirectService::class),

    // Interface binding -- Piwigo\Url\UrlService is L2bExtendedDomain; real
    // callers span every layer, so they constructor- or method-inject
    // UrlServiceInterface instead of depending on the concrete class
    // directly, per deptrac.yaml's ruleset. See
    // src/Piwigo/Core/UrlServiceInterface.php's own docblock.
    UrlServiceInterface::class => get(UrlService::class),

    // Abstract-class binding -- Piwigo\Cache\PersistentCache is abstract,
    // so PHP-DI can't autowire it without being told which concrete
    // implementation to construct. The value never actually varies per
    // request (always PersistentFileCache), so there's nothing genuinely
    // request-scoped to hold in a shared mutable instance -- an ordinary
    // binding is enough.
    PersistentCache::class => get(PersistentFileCache::class),

    // Interface binding -- Piwigo\Admin\PiwigoInfosSender is L4Integration;
    // Piwigo\Page\PageTailRenderer (L3Presentation) constructor-injects
    // TelemetrySenderInterface instead of depending on the concrete class
    // directly, per deptrac.yaml's ruleset. See
    // src/Piwigo/Core/TelemetrySenderInterface.php's own docblock.
    TelemetrySenderInterface::class => get(PiwigoInfosSender::class),

    // Interface binding -- Piwigo\Template\Template is L3Presentation; a
    // handful of L2aCoreDomain/L2bExtendedDomain callers method-inject
    // TemplateInterface instead of depending on the concrete class
    // directly, per deptrac.yaml's ruleset. A factory, not \DI\get(),
    // because the current request's Template is constructed dynamically
    // per-request (runtime theme/path parameters) -- see
    // src/Piwigo/Core/TemplateInterface.php's own docblock and
    // Piwigo\Template\CurrentTemplate.
    TemplateInterface::class => factory(static function (ContainerInterface $c): Template {
        $currentTemplate = $c->get(CurrentTemplate::class);
        if (! $currentTemplate instanceof CurrentTemplate) {
            throw new LogicException('Container returned an unexpected type for ' . CurrentTemplate::class);
        }

        return $currentTemplate->get();
    }),

    // Interface binding -- Piwigo\Users\UserRepository provides the
    // webmaster mail address; Piwigo\Mail\MailService takes
    // WebmasterMailProviderInterface as a real, required constructor
    // collaborator (still a genuine test seam -- unit tests substitute a
    // fake implementation). See
    // src/Piwigo/Core/WebmasterMailProviderInterface.php's own docblock.
    //
    // No equivalent entry for Piwigo\Core\ThemeConfProviderInterface: its
    // implementation is the request's own Piwigo\Template\Template
    // instance (constructed with runtime path/theme strings, never
    // container-managed) -- SrcImage::themeConf() reaches it via
    // Piwigo\Core\CurrentThemeConfProvider::current()->get()->themeConf($key)
    // instead.
    WebmasterMailProviderInterface::class => get(UserRepository::class),

    // Interface binding, same shape as WebmasterMailProviderInterface
    // above -- Piwigo\Mail\MailService's own required
    // $mailRecipientRepo constructor collaborator, still a genuine test
    // seam for unit tests substituting a fake implementation.
    MailRecipientRepositoryInterface::class => get(MailRecipientRepository::class),

    // RouteCollection has no container entry of its own -- a real
    // constructor param autowire can't provide.
    Router::class => factory(static fn (): Router => new Router(RouteDefinitions::all())),

    // Factory binding -- the value never actually varies per request
    // (always built from the same config/storage.php), so there's nothing
    // genuinely request-scoped to hold in a shared mutable instance;
    // $paths and $currentConfig are real, autowired factory params, the
    // same pattern DeploymentPolicy::class's own binding below uses.
    StorageRegistry::class => factory(static fn (Paths $paths, CurrentConfig $currentConfig): StorageRegistry => StorageRegistry::fromConfig(dirname(__DIR__) . '/config/storage.php', $paths, $currentConfig)),

    // Factory binding -- unlike StorageRegistry above, this value
    // genuinely can change mid-request (InstallWizard's own seed() call,
    // see that class's own docblock), so the shared instance is mutable
    // (reload()/seed() mutate it in place).
    DbCredentials::class => factory(static fn (): DbCredentials => DbCredentials::fromEnv()),

    // Factory binding -- $paths is autowired from the container's own
    // Paths::class binding (set by Kernel::boot() when a real Paths is
    // passed); shared/memoized for the container's lifetime like every
    // other autowired service.
    DeploymentPolicy::class => factory(static fn (Paths $paths): DeploymentPolicy => DeploymentPolicy::load($paths)),

    // Factory binding -- loadFromDb() calls its own repositories via a
    // fresh EntityManagerFactory::build(DbConnection::build()) internally,
    // so no Paths/Connection param is needed here; see that method's own
    // docblock for why it doesn't route through the container-shared EM.
    ImageStdParams::class => factory(static function (): ImageStdParams {
        $imageStdParams = new ImageStdParams();
        $imageStdParams->loadFromDb();
        return $imageStdParams;
    }),

    // Non-obvious construction -- PHP-DI's default autowiring only injects
    // REQUIRED constructor parameters via reflection; a parameter with a
    // default value (InputValidator's own `?HtmlRenderingInterface
    // $htmlRenderer = null`, kept optional so InputValidatorTest.php's `new
    // InputValidator()` sites keep their "never wired up" behavior) is left
    // at its default even when the interface itself is bound above and the
    // container is fully booted. Without this entry, every
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
    // parameter) is deferred until a real consumer exists.
    LoggerInterface::class => factory(static function (): LoggerInterface {
        $handler = new RotatingFileHandler(dirname(__DIR__) . '/_data/logs/piwigo.log', 30);
        $handler->setFormatter(new JsonFormatter());

        return new MonologLogger('app', [$handler]);
    }),

    // Non-obvious construction (adapter selection reads env vars --
    // Config::cacheAdapter() doesn't exist). The generic, unnamespaced
    // pool -- general()/rate_limiter() have no real consumer today; the
    // 10 real named pools each get their own distinct type below instead
    // (PHP-DI can't disambiguate 10 same-typed CacheItemPoolInterface
    // bindings by constructor type alone -- see AbstractNamedCachePool's
    // own docblock).
    CacheItemPoolInterface::class => factory(static fn (): CacheItemPoolInterface => CacheFactory::create()),

    // Each named pool's own namespace/TTL, matching CachePools::X()'s
    // former per-method configuration -- see each pool class's own
    // docblock for its caching rationale.
    CalendarNavCachePool::class => factory(static fn (): CalendarNavCachePool => new CalendarNavCachePool(CacheFactory::create(namespace: 'piwigo.calendar_nav', defaultLifetime: 30))),
    CategoryTreeCachePool::class => factory(static fn (): CategoryTreeCachePool => new CategoryTreeCachePool(CacheFactory::create(namespace: 'piwigo.category_tree', defaultLifetime: 300))),
    ConfigCachePool::class => factory(static fn (): ConfigCachePool => new ConfigCachePool(CacheFactory::create(namespace: 'piwigo.config'))),
    EffectivePermissionsCachePool::class => factory(static fn (): EffectivePermissionsCachePool => new EffectivePermissionsCachePool(CacheFactory::create(namespace: 'piwigo.effective_permissions', defaultLifetime: 30))),
    ExtensionUpdateCachePool::class => factory(static fn (): ExtensionUpdateCachePool => new ExtensionUpdateCachePool(CacheFactory::create(namespace: 'piwigo.extension_update', defaultLifetime: 86400))),
    NotificationsCachePool::class => factory(static fn (): NotificationsCachePool => new NotificationsCachePool(CacheFactory::create(namespace: 'piwigo.notifications', defaultLifetime: 30))),
    PermissionsCachePool::class => factory(static fn (): PermissionsCachePool => new PermissionsCachePool(CacheFactory::create(namespace: 'piwigo.permissions', defaultLifetime: 30))),
    SearchResultsCachePool::class => factory(static fn (): SearchResultsCachePool => new SearchResultsCachePool(CacheFactory::create(namespace: 'piwigo.search_results', defaultLifetime: 30))),
    SectionImageIdsCachePool::class => factory(static fn (): SectionImageIdsCachePool => new SectionImageIdsCachePool(CacheFactory::create(namespace: 'piwigo.section_image_ids', defaultLifetime: 30))),
    TagCloudCachePool::class => factory(static fn (): TagCloudCachePool => new TagCloudCachePool(CacheFactory::create(namespace: 'piwigo.tag_cloud', defaultLifetime: 300))),
    TranslationsCachePool::class => factory(static fn (): TranslationsCachePool => new TranslationsCachePool(CacheFactory::create(namespace: 'piwigo.translations'))),

    // PSR-16 wraps the same PSR-6 pool instance (container-shared by
    // default) rather than building a second one -- symfony/cache adapters
    // implement Symfony's own Contracts\Cache\CacheInterface, not PSR-16
    // directly; Psr16Cache is the real adapter for that. Plain interface
    // binding -- Psr16Cache's own constructor takes one required, typed
    // CacheItemPoolInterface param (already bound above), so autowiring
    // resolves the rest; same shape as MailerInterface::class => get(
    // MailService::class) two entries above.
    CacheInterface::class => get(Psr16Cache::class),

    // Pure factory, no Kernel-awareness -- see DbConnection's own docblock
    // for the server-session-mode deviation from the reference
    // implementation's equivalent.
    Connection::class => factory(static fn (): Connection => DbConnection::build()),

    // Delegates to EntityManagerFactory -- the same construction recipe
    // (metadata paths, enableNativeLazyObjects) is also needed by
    // callers that can't participate in constructor
    // injection at all (a static L1Infrastructure method, a self-managed
    // singleton's fallback branch) -- see that factory's own docblock.
    EntityManagerInterface::class => factory(static fn (Connection $conn): EntityManagerInterface => EntityManagerFactory::build($conn)),

    // Stateless apart from the Connection it renders against. An explicit
    // entry rather than plain autowiring so the one shared instance is
    // reused: TagService resolves it from the container per call (it is
    // constructed manually at ~10 sites, so a constructor param would
    // ripple to all of them), and autowiring would build a fresh renderer
    // each time.
    SortRenderer::class => factory(static fn (Connection $conn): SortRenderer => new SortRenderer($conn)),

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

    PluginMigrationRepository::class => factory(static fn (EntityManagerInterface $em): PluginMigrationRepository => $em->getRepository(PluginMigrationEntity::class)),

    ThemeRepository::class => factory(static fn (EntityManagerInterface $em): ThemeRepository => $em->getRepository(ThemeEntity::class)),

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
    // The actual construction (table_storage.table_name) lives in
    // MigrationDependencyFactory itself, not here, so both this container
    // entry and InstallWizard's own direct call share one implementation.
    DependencyFactory::class => factory(
        static fn (EntityManagerInterface $em): DependencyFactory => MigrationDependencyFactory::build($em),
    ),

    // MigrateCommand's constructor param is an OPTIONAL/nullable
    // DependencyFactory -- like every other optional class-typed
    // constructor param in this codebase, PHP-DI's default reflection
    // autowiring does NOT inject it, so it needs this explicit factory
    // entry.
    MigrateCommand::class => factory(
        static fn (DependencyFactory $df): MigrateCommand => new MigrateCommand($df),
    ),

    // [Finding 6] Makes the async Job/Messenger pipeline reachable (a real
    // worker to run it) without changing any existing behavior -- nothing
    // dispatches a job yet, so this is purely additive. $routableBus/
    // $receiverLocator come from MessengerFactory's own construction
    // logic, matching every other Messenger* entry point already built
    // there (transport()/sendingBus()/receivingBus()); a fresh Symfony
    // EventDispatcher is this command's own internal SIGINT/worker-stop
    // signal plumbing, unrelated to this app's PluginConfig\EventDispatcher.
    // 'async' as the sole default receiver name means `bin/piwigo
    // messenger:consume` (no receiver argument) just works.
    ConsumeMessagesCommand::class => factory(
        static fn (Connection $connection, Paths $paths): ConsumeMessagesCommand => new ConsumeMessagesCommand(
            MessengerFactory::routableBus($paths),
            MessengerFactory::receiverLocator(MessengerFactory::transport($connection, $paths)),
            new EventDispatcher(),
            null,
            ['async'],
        ),
    ),
];
