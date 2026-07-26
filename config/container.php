<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Logger as MonologLogger;
use Piwigo\Activity\ActivityService;
use Piwigo\Admin\PiwigoInfosSender;
use Piwigo\Audit\AuditLogEntity;
use Piwigo\Audit\AuditRepository;
use Piwigo\Bootstrap\RedirectService;
use Piwigo\Cache\CacheFactory;
use Piwigo\Comment\CommentRepository;
use Piwigo\Config\ConfigEntry;
use Piwigo\Config\ConfigRepository;
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
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Filter\FilterService;
use Piwigo\Group\GroupEntity;
use Piwigo\Group\GroupRepository;
use Piwigo\Html\HtmlService;
use Piwigo\Lang\LangRepository;
use Piwigo\Lang\LanguageEntity;
use Piwigo\Mail\MailService;
use Piwigo\Routing\Router;
use Piwigo\Session\SessionEntity;
use Piwigo\Session\SessionRepository;
use Piwigo\Template\Template;
use Piwigo\Url\UrlService;
use Piwigo\Users\UserRepository;
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
    TemplateInterface::class => factory(static fn (): Template => \Piwigo\Template\CurrentTemplate::get()),

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
    // path/theme strings in include/common.inc.php, never
    // container-managed), handed to SrcImage::setThemeConfProvider() via
    // the established static-setter pattern instead.
    WebmasterMailProviderInterface::class => \DI\get(UserRepository::class),

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
];
