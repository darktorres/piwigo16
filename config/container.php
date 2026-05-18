<?php

declare(strict_types=1);

use function DI\factory;
use function DI\get;

use Doctrine\DBAL\Connection;
use Piwigo\Activity\ActivityLogger;
use Piwigo\Activity\ActivityRepository;
use Piwigo\Admin\AdminPageRegistry;
use Piwigo\Admin\AdminService;
use Piwigo\Admin\Album\AlbumsTabRenderer;
use Piwigo\Admin\BatchManager\FilterResolver;
use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Admin\Config\SizesProcessor;
use Piwigo\Admin\Config\WatermarkProcessor;
use Piwigo\Admin\Extensions\IgnoredUpdatesRepository;
use Piwigo\Admin\History\HistoryAdminService;
use Piwigo\Admin\Image\ImageAdminService;
use Piwigo\Admin\Metadata\MetadataAdminService;
use Piwigo\Admin\Notification\NotificationAdminService;
use Piwigo\Admin\Tag\TagAdminService;
use Piwigo\Admin\Upload\DirectPreparer;
use Piwigo\Admin\Upload\UploadService;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Admin\Users\UserTabRenderer;
use Piwigo\Asset\AssetService;
use Piwigo\Auth\AuthKeyRepository;
use Piwigo\Auth\CookieService;
use Piwigo\Auth\EphemeralKeyService;
use Piwigo\Auth\PasswordService;
use Piwigo\Cache\CacheFactory;
use Piwigo\Caddie\CaddieRepository;
use Piwigo\Calendar\CalendarRepository;
use Piwigo\Calendar\CalendarService;
use Piwigo\Category\CategoryCatsRenderer;
use Piwigo\Category\CategoryDefaultRenderer;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Comment\CommentRepository;
use Piwigo\Comment\CommentService;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigRepository;
use Piwigo\Config\ConfigService;
use Piwigo\Core\DateService;
use Piwigo\Core\DebugCollector;
use Piwigo\Core\ExecutionMutex;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\PageState;
use Piwigo\Core\Paths;
use Piwigo\Csrf\CsrfService;
use Piwigo\Db\DbConnection;
use Piwigo\Db\DbMaintenanceRepository;
use Piwigo\Feed\FeedRepository;
use Piwigo\Filter\FilterService;
use Piwigo\Group\GroupRepository;
use Piwigo\History\HistoryRepository;
use Piwigo\Html\HtmlService;
use Piwigo\Http\DeviceDetectionService;
use Piwigo\Http\Middleware\AuthMiddleware;
use Piwigo\Http\Middleware\ControllerInvokerMiddleware;
use Piwigo\Http\Middleware\CsrfMiddleware;
use Piwigo\Http\Middleware\ExceptionHandlerMiddleware;
use Piwigo\Http\Middleware\FilterMiddleware;
use Piwigo\Http\Middleware\RoutingMiddleware;
use Piwigo\Http\Middleware\SessionMiddleware;
use Piwigo\Http\RedirectResponder;
use Piwigo\Image\DerivativePipeline;
use Piwigo\Image\DerivativeService;
use Piwigo\Image\ImageRepository;
use Piwigo\Job\MessengerFactory;
use Piwigo\Job\MessengerRepository;
use Piwigo\Lang\LangService;
use Piwigo\Lang\Translator;
use Piwigo\Language\LanguageRepository;
use Piwigo\Language\LanguageService;
use Piwigo\Listener\CoreSubscribers;
use Piwigo\Mail\MailService;
use Piwigo\Menu\MenubarLayoutRepository;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Metadata\MetadataService;
use Piwigo\Notification\NotificationRepository;
use Piwigo\Notification\NotificationService;
use Piwigo\Page\NoPhotoYetRenderer;
use Piwigo\Page\PaginationService;
use Piwigo\Permalink\PermalinkRepository;
use Piwigo\Permalink\PermalinkService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Picture\PictureCommentRenderer;
use Piwigo\Picture\PictureMetadataRenderer;
use Piwigo\Picture\PictureRateRenderer;
use Piwigo\Picture\PictureService;
use Piwigo\Plugin\Migration\PluginMigrationLedger;
use Piwigo\Plugin\Migration\PluginMigrationRunner;
use Piwigo\Plugin\PluginRegistry;
use Piwigo\Plugin\PluginRepository;
use Piwigo\Rate\RateRepository;
use Piwigo\Rate\RateService;
use Piwigo\Routing\Router;
use Piwigo\Search\SearchFilterRenderer;
use Piwigo\Search\SearchFilterViewRepository;
use Piwigo\Search\SearchRepository;
use Piwigo\Search\SearchService;
use Piwigo\Section\SectionInitializer;
use Piwigo\Session\SessionRepository;
use Piwigo\Session\SessionService;
use Piwigo\Site\SiteRepository;
use Piwigo\Storage\StorageRegistry;
use Piwigo\Tag\SelectedTagsRenderer;
use Piwigo\Tag\TagRepository;
use Piwigo\Tag\TagService;
use Piwigo\Telemetry\TelemetryService;
use Piwigo\Theme\TemplateResolver;
use Piwigo\Theme\ThemeRegistry;
use Piwigo\Theme\ThemeRepository;
use Piwigo\Theme\ThemeService;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\AuthService;
use Piwigo\Users\PermissionService;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\ProfileService;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;
use Piwigo\Validation\InputValidator;
use Piwigo\Ws\Method\CategoriesEndpoints;
use Piwigo\Ws\Method\CommentsEndpoints;
use Piwigo\Ws\Method\ExtensionsEndpoints;
use Piwigo\Ws\Method\GeneralEndpoints;
use Piwigo\Ws\Method\GroupsEndpoints;
use Piwigo\Ws\Method\ImagesEndpoints;
use Piwigo\Ws\Method\PermissionsEndpoints;
use Piwigo\Ws\Method\TagsEndpoints;
use Piwigo\Ws\Method\UsersEndpoints;
use Piwigo\Ws\WsHelper;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface as SymfonyEventDispatcherInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\MessageBusInterface;

return [
    CacheItemPoolInterface::class => factory(static fn (): CacheItemPoolInterface => CacheFactory::create()),
    EventDispatcherInterface::class => factory(static function (ContainerInterface $c): EventDispatcherInterface {
        $dispatcher = new EventDispatcher();
        // Subscribers register lazily: the subscriber service is only
        // resolved (and thus instantiated, with all its DI deps) the first
        // time its event actually fires. This keeps boot cheap and lets
        // tests build the dispatcher without pulling DB-bound services.
        foreach (CoreSubscribers::ALL as $subscriberClass) {
            \assert(is_subclass_of($subscriberClass, EventSubscriberInterface::class));
            foreach ($subscriberClass::getSubscribedEvents() as $eventClass => $spec) {
                // getSubscribedEvents() per-entry shape (Symfony idiom):
                //   string                           → single handler, priority 0
                //   array{0:string, 1?:int}          → single handler with priority
                //   list<array{0:string, 1?:int}>    → multiple handlers
                // Normalize to list<array{string,int}> so the addListener
                // loop has one branch and Psalm sees concrete types.
                $handlers = [];
                if (is_string($spec)) {
                    $handlers[] = [$spec, 0];
                } elseif (isset($spec[0]) && is_string($spec[0])) {
                    /** @var array{0:string,1?:int} $spec */
                    $handlers[] = [$spec[0], $spec[1] ?? 0];
                } else {
                    /** @var list<array{0:string,1?:int}> $spec */
                    foreach ($spec as $entry) {
                        $handlers[] = [$entry[0], $entry[1] ?? 0];
                    }
                }
                foreach ($handlers as [$method, $priority]) {
                    $dispatcher->addListener(
                        $eventClass,
                        static function (object $event) use ($c, $subscriberClass, $method): void {
                            $c->get($subscriberClass)->{$method}($event);
                        },
                        $priority,
                    );
                }
            }
        }
        return $dispatcher;
    }),
    EventDispatcher::class                 => get(EventDispatcherInterface::class),
    SymfonyEventDispatcherInterface::class => get(EventDispatcherInterface::class),
    Config::class          => factory(fn () => Config::instance()),
    Translator::class      => factory(fn () => Translator::get()),
    PageState::class       => factory(fn () => PageState::current()),
    // Paths is bound explicitly by Container::build() from the entry point
    // that knows its own location (Paths::fromIndex(__FILE__) in index.php).
    // No factory needed here; PHP-DI sees the explicit binding from
    // Container::build's second addDefinitions() call.
    LoggerInterface::class => factory(
        fn () => LoggerRegistry::isInitialized() ? LoggerRegistry::current() : new NullLogger()
    ),
    StorageRegistry::class => factory(static function (Paths $paths): StorageRegistry {
        $registry = StorageRegistry::fromConfig($paths->config . 'storage.php', $paths);
        StorageRegistry::setInstance($registry);
        return $registry;
    }),

    // Doctrine DBAL shared connection — applies utf8mb4 and removes ONLY_FULL_GROUP_BY.
    Connection::class => factory(static fn (): Connection => DbConnection::build()),

    DerivativeService::class   => factory(static fn (EventDispatcherInterface $d, Paths $paths): DerivativeService => new DerivativeService($d, $paths)),
    DerivativePipeline::class  => factory(static fn (Paths $paths): DerivativePipeline => new DerivativePipeline($paths)),

    // Symfony Messenger bus — transports backed by the shared DBAL connection.
    MessageBusInterface::class => factory(static fn (Connection $conn, Paths $paths): MessageBusInterface => MessengerFactory::build($conn, $paths)),

    // Domain services — stateless; inject only what they need.
    CookieService::class   => factory(static fn (): CookieService => new CookieService()),
    FilterService::class   => factory(static fn (): FilterService => new FilterService()),
    MetadataService::class => factory(static fn (LoggerInterface $log, EventDispatcherInterface $d): MetadataService => new MetadataService($log, $d)),
    PictureService::class  => factory(static fn (ImageRepository $r): PictureService => new PictureService($r)),
    RateService::class     => factory(static fn (RateRepository $rate, ImageRepository $img, CookieService $c, PermissionService $perm, EventDispatcherInterface $d): RateService => new RateService($rate, $img, $c, $perm, $d)),
    CommentService::class  => factory(static fn (CommentRepository $repo, LangService $lang, MailService $mail, PermissionService $perm, UrlGenerator $ug, UrlService $u, EphemeralKeyService $eks, EventDispatcherInterface $dispatcher): CommentService => new CommentService($repo, $lang, $mail, $perm, $ug, $u, $eks, $dispatcher)),
    AuthService::class         => factory(static fn (UserRepository $u, AuthKeyRepository $ak, ActivityLogger $al, SessionService $sess, UrlGenerator $ug, UrlService $us, DateService $d, LanguageService $lang, EventDispatcherInterface $dispatcher, Paths $paths): AuthService => new AuthService($u, $ak, $al, $sess, $ug, $us, $d, $lang, $dispatcher, $paths)),
    PasswordService::class     => factory(static fn (AuthService $auth, MailService $mail, PermissionService $perm, PreferencesService $pref, UrlGenerator $ug, UserRepository $u, UserService $us, ActivityLogger $al): PasswordService => new PasswordService($auth, $mail, $perm, $pref, $ug, $u, $us, $al)),
    CalendarRepository::class  => factory(static fn (Connection $conn): CalendarRepository => new CalendarRepository($conn, Config::dbPrefix())),
    CalendarService::class     => factory(static fn (CalendarRepository $cr, CategoryService $cat, DebugCollector $dbg, PermissionService $perm, UrlService $us, CacheItemPoolInterface $pool): CalendarService => new CalendarService($cr, $cat, $dbg, $perm, $us, $pool)),
    MailService::class         => factory(static fn (UrlGenerator $ug, UserRepository $u, LangService $lang, AuthService $auth, UrlService $us, EventDispatcherInterface $dispatcher, Paths $paths): MailService => new MailService($ug, $u, $lang, $auth, $us, $dispatcher, $paths)),
    PermissionService::class   => factory(static fn (CategoryRepository $catR, PermissionRepository $permR, HtmlService $h): PermissionService => new PermissionService($catR, $permR, $h)),
    PreferencesService::class  => factory(static fn (LanguageService $lang, UserRepository $r): PreferencesService => new PreferencesService($lang, $r)),
    UserService::class         => factory(static fn (UserRepository $u, HistoryRepository $h, ActivityRepository $a, GroupRepository $g, AuthKeyRepository $ak, ActivityLogger $al, ExecutionMutex $mutex, LangService $lang, UrlGenerator $ug, MailService $mail, MessageBusInterface $bus, HtmlService $html, DateService $date, CategoryService $cat, ImageRepository $i, UserAdminService $uas, SessionService $sess, AuthService $auth, PreferencesService $pref, PermissionService $perm, LanguageService $langSvc, ThemeService $theme, EventDispatcherInterface $dispatcher): UserService => new UserService($u, $h, $a, $g, $ak, $al, $mutex, $lang, $ug, $mail, $bus, $html, $date, $cat, $i, $uas, $sess, $auth, $pref, $perm, $langSvc, $theme, $dispatcher)),
    ProfileService::class      => factory(static fn (AuthService $auth, DateService $d, LangService $lang, MailService $mail, UserRepository $u, UserService $us, ActivityLogger $al, CsrfService $csrf, RedirectResponder $redirect, LanguageService $langSvc, ThemeService $theme, EventDispatcherInterface $dispatcher): ProfileService => new ProfileService($auth, $d, $lang, $mail, $u, $us, $al, $csrf, $redirect, $langSvc, $theme, $dispatcher)),
    CategoryService::class     => factory(static fn (CategoryRepository $cat, FilterService $f, PermissionService $perm, EventDispatcherInterface $d): CategoryService => new CategoryService($cat, $f, $perm, $d)),
    HtmlService::class         => factory(static fn (CategoryRepository $catR, EventDispatcherInterface $d): HtmlService => new HtmlService($catR, $d)),
    NotificationService::class => factory(static fn (NotificationRepository $nR, HtmlService $h, UrlGenerator $ug, PermissionService $perm, UrlService $us, CacheItemPoolInterface $pool): NotificationService => new NotificationService($nR, $h, $ug, $perm, $us, $pool)),
    PluginMigrationLedger::class  => factory(static fn (Connection $conn): PluginMigrationLedger => new PluginMigrationLedger($conn, Config::dbPrefix())),
    PluginMigrationRunner::class  => factory(static fn (Connection $conn, PluginMigrationLedger $ledger, LoggerInterface $log): PluginMigrationRunner => new PluginMigrationRunner($conn, $ledger, $log)),
    PluginRegistry::class      => factory(static fn (PluginRepository $repo, LoggerInterface $log, PluginMigrationRunner $runner, Paths $paths): PluginRegistry => new PluginRegistry($repo, $log, $paths->root . 'plugins', $paths->root . 'docs/schemas/plugin.schema.json', $runner)),
    SearchService::class       => factory(static fn (SearchRepository $repo, SearchFilterViewRepository $fv, CategoryRepository $catR, LoggerInterface $log, CategoryService $cat, HtmlService $h, PermissionService $perm, PreferencesService $pref, TagRepository $tR, TagService $tag, UrlService $u, UserService $us, CacheItemPoolInterface $pool, EventDispatcherInterface $d): SearchService => new SearchService($repo, $fv, $catR, $log, $cat, $h, $perm, $pref, $tR, $tag, $u, $us, $pool, $d)),
    SessionService::class      => factory(static fn (SessionRepository $repo): SessionService => new SessionService($repo)),
    TagService::class          => factory(static fn (HtmlService $h, TagRepository $repo, PermissionService $perm, CacheItemPoolInterface $pool, EventDispatcherInterface $dispatcher): TagService => new TagService($h, $repo, $perm, $pool, $dispatcher)),
    UrlService::class          => factory(static fn (CategoryService $cat, HtmlService $h, TagService $tag, PermissionService $perm, UserRepository $u, EventDispatcherInterface $dispatcher): UrlService => new UrlService($cat, $h, $tag, $perm, $u, $dispatcher)),
    UrlGenerator::class          => factory(static fn (Router $r, UrlService $u): UrlGenerator => new UrlGenerator($r, $u)),
    SectionInitializer::class    => factory(static fn (CalendarService $cal, CategoryRepository $catR, CategoryService $cat, HtmlService $h, ImageRepository $i, PermissionService $perm, SearchService $sr, SessionService $sess, TagService $tag, UrlService $u, UserRepository $ur, UserService $us, RedirectResponder $redirect, CacheItemPoolInterface $pool, EventDispatcherInterface $dispatcher): SectionInitializer => new SectionInitializer($cal, $catR, $cat, $h, $i, $perm, $sr, $sess, $tag, $u, $ur, $us, $redirect, $pool, $dispatcher)),
    GeneralEndpoints::class          => factory(static fn (ActivityRepository $aR, AuthService $auth, CaddieRepository $cadR, CategoryRepository $catR, CommentRepository $com, CookieService $cookie, DateService $d, HistoryAdminService $hA, HtmlService $h, ImageAdminService $iA, ImageRepository $i, PermissionService $perm, PictureService $pic, RateRepository $rR, RateService $rate, SearchRepository $sR, TagRepository $tR, UrlGenerator $ug, UrlService $us, UserAdminService $uA, UserRepository $u, WsHelper $ws, CacheItemPoolInterface $pool, ActivityLogger $al, CsrfService $csrf, InputValidator $iv, EventDispatcherInterface $dispatcher): GeneralEndpoints => new GeneralEndpoints($aR, $auth, $cadR, $catR, $com, $cookie, $d, $hA, $h, $iA, $i, $perm, $pic, $rR, $rate, $sR, $tR, $ug, $us, $uA, $u, $ws, $pool, $al, $csrf, $iv, $dispatcher)),
    TagsEndpoints::class             => factory(static fn (CategoryService $cat, HtmlService $h, ImageRepository $i, TagAdminService $tA, TagRepository $tR, TagService $tag, UrlService $u, ActivityLogger $al, CsrfService $csrf, WsHelper $ws, EventDispatcherInterface $d): TagsEndpoints => new TagsEndpoints($cat, $h, $i, $tA, $tR, $tag, $u, $al, $csrf, $ws, $d)),
    CommentsEndpoints::class         => factory(static fn (CommentRepository $cR, CommentService $com, DateService $d, UrlGenerator $ug, CsrfService $csrf, EventDispatcherInterface $dispatcher): CommentsEndpoints => new CommentsEndpoints($cR, $com, $d, $ug, $csrf, $dispatcher)),
    PermissionsEndpoints::class      => factory(static fn (CategoryAdminService $catA, CategoryRepository $catR, CategoryService $cat, PermissionRepository $perm, CsrfService $csrf): PermissionsEndpoints => new PermissionsEndpoints($catA, $catR, $cat, $perm, $csrf)),
    ExtensionsEndpoints::class       => factory(static fn (PermissionService $perm, UrlGenerator $ug, ActivityLogger $al, CsrfService $csrf, RedirectResponder $redirect, IgnoredUpdatesRepository $iu): ExtensionsEndpoints => new ExtensionsEndpoints($perm, $ug, $al, $csrf, $redirect, $iu)),
    GroupsEndpoints::class           => factory(static fn (GroupRepository $g, UserAdminService $uA, ActivityLogger $al, CsrfService $csrf): GroupsEndpoints => new GroupsEndpoints($g, $uA, $al, $csrf)),
    UsersEndpoints::class            => factory(static fn (AuthService $auth, ConfigService $cfg, DateService $d, GroupRepository $g, ImageRepository $i, MailService $m, PermissionService $perm, PreferencesService $pref, UserAdminService $uA, UserRepository $u, UserService $us, CsrfService $csrf, WsHelper $ws, EventDispatcherInterface $dispatcher): UsersEndpoints => new UsersEndpoints($auth, $cfg, $d, $g, $i, $m, $perm, $pref, $uA, $u, $us, $csrf, $ws, $dispatcher)),
    CategoriesEndpoints::class       => factory(static fn (CategoryAdminService $catA, CategoryRepository $catR, CategoryService $cat, HtmlService $h, ImageAdminService $iA, ImageRepository $i, PermissionService $perm, UrlGenerator $ug, UrlService $us, UserAdminService $uA, UserRepository $u, ActivityLogger $al, CsrfService $csrf, WsHelper $ws, EventDispatcherInterface $d): CategoriesEndpoints => new CategoriesEndpoints($catA, $catR, $cat, $h, $iA, $i, $perm, $ug, $us, $uA, $u, $al, $csrf, $ws, $d)),
    ImagesEndpoints::class           => factory(static fn (CategoryAdminService $catA, CategoryRepository $catR, CategoryService $cat, CommentRepository $comR, CommentService $com, HtmlService $h, ImageAdminService $iA, ImageRepository $i, MetadataAdminService $mA, PermissionService $perm, RateRepository $rR, RateService $rate, SearchService $sr, TagAdminService $tA, TagService $tag, UploadService $up, UrlService $u, UserAdminService $uA, ActivityLogger $al, CsrfService $csrf, WsHelper $ws, EphemeralKeyService $eks, EventDispatcherInterface $d, Paths $paths): ImagesEndpoints => new ImagesEndpoints($catA, $catR, $cat, $comR, $com, $h, $iA, $i, $mA, $perm, $rR, $rate, $sr, $tA, $tag, $up, $u, $uA, $al, $csrf, $ws, $eks, $d, $paths)),
    AdminPageRegistry::class         => factory(static fn (): AdminPageRegistry => new AdminPageRegistry()),
    AssetService::class              => factory(static fn (LoggerInterface $log, Paths $paths): AssetService => new AssetService($paths->root . 'plugins', $log)),
    AdminService::class              => factory(static fn (CategoryRepository $catR, DateService $d, DbMaintenanceRepository $dbM, HistoryRepository $hR, ImageRepository $i, TagRepository $tR, UserRepository $u, CacheItemPoolInterface $pool): AdminService => new AdminService($catR, $d, $dbM, $hR, $i, $tR, $u, $pool)),
    CategoryAdminService::class      => factory(static function (ContainerInterface $c): CategoryAdminService {
        return (new \ReflectionClass(CategoryAdminService::class))->newLazyProxy(
            static fn (): CategoryAdminService => new CategoryAdminService(
                $c->get(CategoryRepository::class),
                $c->get(CategoryService::class),
                $c->get(ImageAdminService::class),
                $c->get(ImageRepository::class),
                $c->get(PermissionRepository::class),
                $c->get(SiteRepository::class),
                $c->get(UserAdminService::class),
                $c->get(ActivityLogger::class),
                $c->get(ExecutionMutex::class),
                $c->get(EventDispatcherInterface::class),
            )
        );
    }),
    ImageAdminService::class         => factory(static function (ContainerInterface $c): ImageAdminService {
        return (new \ReflectionClass(ImageAdminService::class))->newLazyProxy(
            static fn (): ImageAdminService => new ImageAdminService(
                $c->get(CategoryAdminService::class),
                $c->get(CategoryRepository::class),
                $c->get(ConfigService::class),
                $c->get(ImageRepository::class),
                $c->get(UrlGenerator::class),
                $c->get(ActivityLogger::class),
                $c->get(EventDispatcherInterface::class),
                $c->get(Paths::class),
            )
        );
    }),
    TagAdminService::class           => factory(static fn (HtmlService $h, ImageAdminService $iA, TagRepository $tR, UserAdminService $uA, ActivityLogger $al, EventDispatcherInterface $dispatcher): TagAdminService => new TagAdminService($h, $iA, $tR, $uA, $al, $dispatcher)),
    UserAdminService::class          => factory(static function (ContainerInterface $c): UserAdminService {
        return (new \ReflectionClass(UserAdminService::class))->newLazyProxy(
            static fn (): UserAdminService => new UserAdminService(
                $c->get(ConfigService::class),
                $c->get(GroupRepository::class),
                $c->get(SessionService::class),
                $c->get(UserRepository::class),
                $c->get(UserService::class),
                $c->get(ActivityLogger::class),
                $c->get(CacheItemPoolInterface::class),
                $c->get(EventDispatcherInterface::class),
            )
        );
    }),
    NotificationAdminService::class  => factory(static fn (MailService $mail, NotificationRepository $nR, UrlGenerator $ug, UrlService $u, UserService $us): NotificationAdminService => new NotificationAdminService($mail, $nR, $ug, $u, $us)),
    UploadService::class             => factory(static fn (CategoryAdminService $catA, ConfigService $cfg, DerivativeService $der, ImageAdminService $iA, ImageRepository $i, MetadataAdminService $mA, UserAdminService $uA, ActivityLogger $al, EventDispatcherInterface $dispatcher, Paths $paths): UploadService => new UploadService($catA, $cfg, $der, $iA, $i, $mA, $uA, $al, $dispatcher, $paths)),
    AlbumsTabRenderer::class         => factory(static fn (CategoryRepository $catR): AlbumsTabRenderer => new AlbumsTabRenderer($catR)),
    UserTabRenderer::class           => factory(static fn (): UserTabRenderer => new UserTabRenderer()),
    DirectPreparer::class            => factory(static fn (AdminService $a, CategoryRepository $catR, HtmlService $h, ImageRepository $i, UploadService $up, CsrfService $csrf, InputValidator $iv): DirectPreparer => new DirectPreparer($a, $catR, $h, $i, $up, $csrf, $iv)),
    FilterResolver::class            => factory(static fn (CategoryRepository $catR, HtmlService $h, TagAdminService $tA, CsrfService $csrf, LangService $lang, UrlService $us, EventDispatcherInterface $dispatcher): FilterResolver => new FilterResolver($catR, $h, $tA, $csrf, $lang, $us, $dispatcher)),
    SizesProcessor::class            => factory(static fn (ImageAdminService $iA, UploadService $up, ActivityLogger $al, PermissionService $perm): SizesProcessor => new SizesProcessor($iA, $up, $al, $perm)),
    WatermarkProcessor::class        => factory(static fn (ImageAdminService $iA, ActivityLogger $al, PermissionService $perm, Paths $paths): WatermarkProcessor => new WatermarkProcessor($iA, $al, $perm, $paths)),
    MenubarRenderer::class           => factory(static fn (CategoryService $cat, CommentService $com, PermissionService $perm, TagService $tag, UrlGenerator $ug, UrlService $u, FilterService $f, MenubarLayoutRepository $ml, EventDispatcherInterface $dispatcher): MenubarRenderer => new MenubarRenderer($cat, $com, $perm, $tag, $ug, $u, $f, $ml, $dispatcher)),
    MenubarLayoutRepository::class   => factory(static fn (ConfigService $cfg): MenubarLayoutRepository => new MenubarLayoutRepository($cfg)),
    IgnoredUpdatesRepository::class  => factory(static fn (Connection $conn): IgnoredUpdatesRepository => new IgnoredUpdatesRepository($conn)),
    SearchFilterRenderer::class      => factory(static fn (CategoryRepository $catR, DateService $d, HtmlService $h, LangService $lang, PermissionService $perm, SearchRepository $sR, SearchService $sr, SearchFilterViewRepository $fv, TagService $tag, UrlService $u, UserRepository $u2, CacheItemPoolInterface $pool): SearchFilterRenderer => new SearchFilterRenderer($catR, $d, $h, $lang, $perm, $sR, $sr, $fv, $tag, $u, $u2, $pool)),
    CategoryCatsRenderer::class      => factory(static fn (CategoryRepository $catR, CategoryService $cat, DateService $d, FilterService $f, HtmlService $h, ImageRepository $i, PermissionService $perm, UrlService $u, DebugCollector $dbg, PaginationService $pag, EventDispatcherInterface $dispatcher): CategoryCatsRenderer => new CategoryCatsRenderer($catR, $cat, $d, $f, $h, $i, $perm, $u, $dbg, $pag, $dispatcher)),
    CategoryDefaultRenderer::class   => factory(static fn (CategoryService $cat, CommentRepository $cr, HtmlService $h, ImageRepository $i, SessionService $sess, UrlService $u, DebugCollector $dbg, EventDispatcherInterface $dispatcher): CategoryDefaultRenderer => new CategoryDefaultRenderer($cat, $cr, $h, $i, $sess, $u, $dbg, $dispatcher)),
    SelectedTagsRenderer::class      => factory(static fn (UrlService $us, EventDispatcherInterface $dispatcher): SelectedTagsRenderer => new SelectedTagsRenderer($us, $dispatcher)),
    NoPhotoYetRenderer::class        => factory(static fn (ConfigService $cfg, ImageRepository $i, UrlGenerator $ug, RedirectResponder $redirect, UrlService $us, PermissionService $perm, EventDispatcherInterface $dispatcher, Paths $paths): NoPhotoYetRenderer => new NoPhotoYetRenderer($cfg, $i, $ug, $redirect, $us, $perm, $dispatcher, $paths)),
    PictureRateRenderer::class       => factory(static fn (RateRepository $rR, PermissionService $perm, UrlService $us): PictureRateRenderer => new PictureRateRenderer($rR, $perm, $us)),
    PictureCommentRenderer::class    => factory(static fn (CommentRepository $cR, CommentService $com, DateService $d, HtmlService $h, PermissionService $perm, SessionService $sess, UrlService $u, CsrfService $csrf, EphemeralKeyService $eks, PaginationService $pag, EventDispatcherInterface $dispatcher): PictureCommentRenderer => new PictureCommentRenderer($cR, $com, $d, $h, $perm, $sess, $u, $csrf, $eks, $pag, $dispatcher)),
    PictureMetadataRenderer::class   => factory(static fn (MetadataService $m): PictureMetadataRenderer => new PictureMetadataRenderer($m)),
    MetadataAdminService::class      => factory(static fn (CategoryRepository $catR, ImageRepository $i, MetadataService $m, TagAdminService $tA, Paths $paths): MetadataAdminService => new MetadataAdminService($catR, $i, $m, $tA, $paths)),
    HistoryAdminService::class       => factory(static fn (HistoryRepository $hR, ImageRepository $i): HistoryAdminService => new HistoryAdminService($hR, $i)),
    WsHelper::class                  => factory(static fn (PermissionService $perm, UrlService $us): WsHelper => new WsHelper($perm, $us)),
    DateService::class         => factory(static fn (): DateService => new DateService()),
    LangService::class         => factory(static fn (Paths $paths): LangService => new LangService($paths)),
    ConfigRepository::class    => factory(static fn (Connection $conn): ConfigRepository => new ConfigRepository($conn, Config::dbPrefix())),
    ConfigService::class       => factory(static fn (ConfigRepository $repo): ConfigService => new ConfigService($repo)),
    ActivityLogger::class      => factory(static fn (ActivityRepository $aR, HistoryRepository $hist, HistoryAdminService $histA, PermissionService $perm, UserRepository $u, EventDispatcherInterface $d): ActivityLogger => new ActivityLogger($aR, $hist, $histA, $perm, $u, $d)),
    CsrfService::class         => factory(static fn (HtmlService $h): CsrfService => new CsrfService($h)),
    DebugCollector::class      => factory(static fn (): DebugCollector => new DebugCollector()),
    DeviceDetectionService::class => factory(static fn (SessionService $sess): DeviceDetectionService => new DeviceDetectionService($sess)),
    EphemeralKeyService::class => factory(static fn (): EphemeralKeyService => new EphemeralKeyService()),
    ExecutionMutex::class      => factory(static fn (ConfigRepository $repo, ConfigService $cfg, LoggerInterface $log): ExecutionMutex => new ExecutionMutex($repo, $cfg, $log)),
    InputValidator::class      => factory(static fn (): InputValidator => new InputValidator()),
    LanguageService::class     => factory(static fn (LanguageRepository $r, Paths $paths): LanguageService => new LanguageService($r, $paths)),
    PaginationService::class   => factory(static fn (): PaginationService => new PaginationService()),
    RedirectResponder::class   => factory(static fn (LangService $lang, EventDispatcherInterface $dispatcher, Paths $paths): RedirectResponder => new RedirectResponder($lang, $dispatcher, $paths)),
    TelemetryService::class    => factory(static fn (ActivityRepository $aR, ConfigService $cfg, ImageRepository $i, LoggerInterface $log, AdminService $admin, ExecutionMutex $mutex, UserRepository $u): TelemetryService => new TelemetryService($aR, $cfg, $i, $log, $admin, $mutex, $u)),
    ThemeService::class        => factory(static fn (ThemeRepository $r, EventDispatcherInterface $dispatcher): ThemeService => new ThemeService($r, $dispatcher)),
    ThemeRegistry::class       => factory(static fn (ThemeRepository $r, LoggerInterface $log, EventDispatcherInterface $dispatcher, Paths $paths): ThemeRegistry => new ThemeRegistry($r, $log, $paths->root . 'themes', $paths->root . 'docs/schemas/theme.schema.json', $dispatcher)),
    TemplateResolver::class    => factory(static fn (ThemeRegistry $reg, Paths $paths): TemplateResolver => new TemplateResolver($reg, $paths->root . 'themes')),

    // Domain repositories — injected with the shared DBAL connection and table prefix.
    TagRepository::class          => factory(static fn (Connection $conn): TagRepository => new TagRepository($conn, Config::dbPrefix())),
    CommentRepository::class      => factory(static fn (Connection $conn): CommentRepository => new CommentRepository($conn, Config::dbPrefix())),
    SearchRepository::class       => factory(static fn (Connection $conn): SearchRepository => new SearchRepository($conn, Config::dbPrefix())),
    DbMaintenanceRepository::class => factory(static fn (Connection $conn): DbMaintenanceRepository => new DbMaintenanceRepository($conn, Config::dbPrefix())),
    CaddieRepository::class       => factory(static fn (Connection $conn): CaddieRepository => new CaddieRepository($conn, Config::dbPrefix())),
    CategoryRepository::class     => factory(static fn (Connection $conn): CategoryRepository => new CategoryRepository($conn, Config::dbPrefix())),
    ImageRepository::class        => factory(static fn (Connection $conn): ImageRepository => new ImageRepository($conn, Config::dbPrefix())),
    UserRepository::class         => factory(static fn (Connection $conn): UserRepository => new UserRepository($conn, Config::dbPrefix())),
    PluginRepository::class       => factory(static fn (Connection $conn): PluginRepository => new PluginRepository($conn, Config::dbPrefix())),
    HistoryRepository::class      => factory(static fn (Connection $conn): HistoryRepository => new HistoryRepository($conn, Config::dbPrefix())),
    NotificationRepository::class => factory(static fn (Connection $conn): NotificationRepository => new NotificationRepository($conn, Config::dbPrefix())),
    SessionRepository::class      => factory(static fn (Connection $conn): SessionRepository => new SessionRepository($conn, Config::dbPrefix())),
    RateRepository::class         => factory(static fn (Connection $conn): RateRepository => new RateRepository($conn, Config::dbPrefix())),
    GroupRepository::class        => factory(static fn (Connection $conn): GroupRepository => new GroupRepository($conn, Config::dbPrefix())),
    ThemeRepository::class        => factory(static fn (Connection $conn): ThemeRepository => new ThemeRepository($conn, Config::dbPrefix())),
    LanguageRepository::class     => factory(static fn (Connection $conn): LanguageRepository => new LanguageRepository($conn, Config::dbPrefix())),
    PermalinkRepository::class    => factory(static fn (Connection $conn): PermalinkRepository => new PermalinkRepository($conn, Config::dbPrefix())),
    PermalinkService::class        => factory(static fn (PermalinkRepository $repo): PermalinkService => new PermalinkService($repo)),
    PermissionRepository::class   => factory(static fn (Connection $conn): PermissionRepository => new PermissionRepository($conn, Config::dbPrefix())),
    SiteRepository::class         => factory(static fn (Connection $conn): SiteRepository => new SiteRepository($conn, Config::dbPrefix())),
    ActivityRepository::class     => factory(static fn (Connection $conn): ActivityRepository => new ActivityRepository($conn, Config::dbPrefix())),
    AuthKeyRepository::class      => factory(static fn (Connection $conn): AuthKeyRepository => new AuthKeyRepository($conn, Config::dbPrefix())),
    FeedRepository::class         => factory(static fn (Connection $conn): FeedRepository => new FeedRepository($conn, Config::dbPrefix())),
    MessengerRepository::class    => factory(static fn (Connection $conn): MessengerRepository => new MessengerRepository($conn, Config::dbPrefix())),

    // ── PSR-7/15 routing infrastructure ──────────────────────────────────────
    Router::class                     => factory(static fn (): Router => new Router(dirname(__DIR__) . '/config/routes.php')),
    ExceptionHandlerMiddleware::class => factory(static fn (): ExceptionHandlerMiddleware => new ExceptionHandlerMiddleware()),
    SessionMiddleware::class          => factory(static fn (): SessionMiddleware => new SessionMiddleware()),
    AuthMiddleware::class             => factory(static fn (): AuthMiddleware => new AuthMiddleware()),
    FilterMiddleware::class           => factory(static fn (CategoryService $cat, ImageRepository $i, SessionService $sess, FilterService $f): FilterMiddleware => new FilterMiddleware($cat, $i, $sess, $f)),
    CsrfMiddleware::class             => factory(static fn (CsrfService $csrf): CsrfMiddleware => new CsrfMiddleware($csrf)),
    RoutingMiddleware::class          => factory(static fn (Router $r): RoutingMiddleware => new RoutingMiddleware($r)),
    ControllerInvokerMiddleware::class => factory(static fn (ContainerInterface $c): ControllerInvokerMiddleware => new ControllerInvokerMiddleware($c)),
];
