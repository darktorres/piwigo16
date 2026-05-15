<?php

declare(strict_types=1);

use function DI\factory;

use Doctrine\DBAL\Connection;
use Piwigo\Activity\ActivityRepository;
use Piwigo\Admin\AdminService;
use Piwigo\Admin\Album\AlbumsTabRenderer;
use Piwigo\Admin\BatchManager\FilterResolver;
use Piwigo\Admin\Category\CategoryAdminService;
use Piwigo\Admin\Config\SizesProcessor;
use Piwigo\Admin\Config\WatermarkProcessor;
use Piwigo\Admin\History\HistoryAdminService;
use Piwigo\Admin\Image\ImageAdminService;
use Piwigo\Admin\Metadata\MetadataAdminService;
use Piwigo\Admin\Notification\NotificationAdminService;
use Piwigo\Admin\Tag\TagAdminService;
use Piwigo\Admin\Upload\DirectPreparer;
use Piwigo\Admin\Upload\UploadService;
use Piwigo\Admin\Users\UserAdminService;
use Piwigo\Admin\Users\UserTabRenderer;
use Piwigo\Auth\AuthKeyRepository;
use Piwigo\Auth\CookieService;
use Piwigo\Auth\PasswordService;
use Piwigo\Cache\CacheFactory;
use Piwigo\Calendar\CalendarService;
use Piwigo\Category\CategoryCatsRenderer;
use Piwigo\Category\CategoryDefaultRenderer;
use Piwigo\Category\CategoryRepository;
use Piwigo\Category\CategoryService;
use Piwigo\Comment\CommentRepository;
use Piwigo\Comment\CommentService;
use Piwigo\Config\Config;
use Piwigo\Config\ConfigService;
use Piwigo\Core\DateService;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\PageState;
use Piwigo\Core\StringUtil;
use Piwigo\Core\Util;
use Piwigo\Db\DbConnection;
use Piwigo\Db\QueryHelper;
use Piwigo\Feed\FeedRepository;
use Piwigo\Filter\FilterService;
use Piwigo\Group\GroupRepository;
use Piwigo\History\HistoryRepository;
use Piwigo\Html\HtmlService;
use Piwigo\Http\Middleware\AuthMiddleware;
use Piwigo\Http\Middleware\ControllerInvokerMiddleware;
use Piwigo\Http\Middleware\CsrfMiddleware;
use Piwigo\Http\Middleware\ExceptionHandlerMiddleware;
use Piwigo\Http\Middleware\FilterMiddleware;
use Piwigo\Http\Middleware\RoutingMiddleware;
use Piwigo\Http\Middleware\SessionMiddleware;
use Piwigo\Image\DerivativeService;
use Piwigo\Image\ImageRepository;
use Piwigo\Job\MessengerFactory;
use Piwigo\Lang\LangService;
use Piwigo\Lang\Translator;
use Piwigo\Language\LanguageRepository;
use Piwigo\Mail\MailService;
use Piwigo\Menu\MenubarRenderer;
use Piwigo\Metadata\MetadataService;
use Piwigo\Notification\NotificationRepository;
use Piwigo\Notification\NotificationService;
use Piwigo\Page\NoPhotoYetRenderer;
use Piwigo\Permalink\PermalinkRepository;
use Piwigo\Permalink\PermalinkService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Picture\PictureCommentRenderer;
use Piwigo\Picture\PictureMetadataRenderer;
use Piwigo\Picture\PictureRateRenderer;
use Piwigo\Picture\PictureService;
use Piwigo\Plugin\PluginRepository;
use Piwigo\Plugin\PluginService;
use Piwigo\Rate\RateRepository;
use Piwigo\Rate\RateService;
use Piwigo\Routing\Router;
use Piwigo\Search\SearchFilterRenderer;
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
use Piwigo\Theme\ThemeRepository;
use Piwigo\Url\UrlGenerator;
use Piwigo\Url\UrlService;
use Piwigo\Users\AuthService;
use Piwigo\Users\PermissionService;
use Piwigo\Users\PreferencesService;
use Piwigo\Users\ProfileService;
use Piwigo\Users\UserRepository;
use Piwigo\Users\UserService;
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
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;

return [
    CacheItemPoolInterface::class => factory(static fn (): CacheItemPoolInterface => CacheFactory::create()),
    Config::class          => factory(fn () => Config::instance()),
    Translator::class      => factory(fn () => Translator::get()),
    PageState::class       => factory(fn () => PageState::current()),
    LoggerInterface::class => factory(
        fn () => LoggerRegistry::isInitialized() ? LoggerRegistry::current() : new NullLogger()
    ),
    StorageRegistry::class => factory(static function (): StorageRegistry {
        $registry = StorageRegistry::fromConfig(PHPWG_ROOT_PATH . 'config/storage.php');
        StorageRegistry::setInstance($registry);
        return $registry;
    }),

    // Doctrine DBAL shared connection — applies utf8mb4 and removes ONLY_FULL_GROUP_BY.
    Connection::class => factory(static fn (): Connection => DbConnection::build()),

    DerivativeService::class   => factory(static fn (Util $util): DerivativeService => new DerivativeService($util)),

    // Symfony Messenger bus — transports backed by the shared DBAL connection.
    MessageBusInterface::class => factory(static fn (Connection $conn): MessageBusInterface => MessengerFactory::build($conn)),

    // Domain services — stateless; inject only what they need.
    CookieService::class   => factory(static fn (): CookieService => new CookieService()),
    FilterService::class   => factory(static fn (): FilterService => new FilterService()),
    MetadataService::class => factory(static fn (LoggerInterface $log, StringUtil $s): MetadataService => new MetadataService($log, $s)),
    PictureService::class  => factory(static fn (ImageRepository $r): PictureService => new PictureService($r)),
    RateService::class     => factory(static fn (RateRepository $rate, ImageRepository $img, CookieService $c, PermissionService $perm): RateService => new RateService($rate, $img, $c, $perm)),
    CommentService::class  => factory(static fn (CommentRepository $repo, LangService $lang, MailService $mail, PermissionService $perm, StringUtil $s, UrlGenerator $ug, UrlService $u, Util $util): CommentService => new CommentService($repo, $lang, $mail, $perm, $s, $ug, $u, $util)),
    AuthService::class         => factory(static fn (UserRepository $u, AuthKeyRepository $ak, Connection $conn, StringUtil $s, Util $util, SessionService $sess, UrlGenerator $ug, UrlService $us, DateService $d): AuthService => new AuthService($u, $ak, $conn, $s, $util, $sess, $ug, $us, $d)),
    PasswordService::class     => factory(static fn (AuthService $auth, MailService $mail, PermissionService $perm, PreferencesService $pref, StringUtil $s, UrlGenerator $ug, UserRepository $u, UserService $us, Util $util): PasswordService => new PasswordService($auth, $mail, $perm, $pref, $s, $ug, $u, $us, $util)),
    CalendarService::class     => factory(static fn (CategoryService $cat, Connection $conn, Util $util, PermissionService $perm, UrlService $us, CacheItemPoolInterface $pool): CalendarService => new CalendarService($cat, $conn, $util, $perm, $us, $pool)),
    MailService::class         => factory(static fn (Connection $conn, StringUtil $s, UrlGenerator $ug, Util $util, LangService $lang, AuthService $auth, UrlService $us): MailService => new MailService($conn, $s, $ug, $util, $lang, $auth, $us)),
    PermissionService::class   => factory(static fn (Connection $conn, HtmlService $h): PermissionService => new PermissionService($conn, $h)),
    PreferencesService::class  => factory(static fn (Util $u, UserRepository $r): PreferencesService => new PreferencesService($u, $r)),
    UserService::class         => factory(static fn (UserRepository $u, Connection $conn, HistoryRepository $h, ActivityRepository $a, GroupRepository $g, AuthKeyRepository $ak, StringUtil $s, Util $util, LangService $lang, UrlGenerator $ug, MailService $mail, MessageBusInterface $bus, HtmlService $html, DateService $date, CategoryService $cat, UserAdminService $uas, SessionService $sess, AuthService $auth, PreferencesService $pref, PermissionService $perm): UserService => new UserService($u, $conn, $h, $a, $g, $ak, $s, $util, $lang, $ug, $mail, $bus, $html, $date, $cat, $uas, $sess, $auth, $pref, $perm)),
    ProfileService::class      => factory(static fn (Connection $conn, AuthService $auth, DateService $d, LangService $lang, MailService $mail, UserRepository $u, UserService $us, Util $util): ProfileService => new ProfileService($conn, $auth, $d, $lang, $mail, $u, $us, $util)),
    CategoryService::class     => factory(static fn (CategoryRepository $cat, Connection $conn, FilterService $f, PermissionService $perm): CategoryService => new CategoryService($cat, $conn, $f, $perm)),
    HtmlService::class         => factory(static fn (Connection $conn, StringUtil $s): HtmlService => new HtmlService($conn, $s)),
    NotificationService::class => factory(static fn (Connection $conn, HtmlService $h, UrlGenerator $ug, PermissionService $perm, UrlService $us, CacheItemPoolInterface $pool): NotificationService => new NotificationService($conn, $h, $ug, $perm, $us, $pool)),
    PluginService::class       => factory(static fn (PluginRepository $repo, StringUtil $s, Util $u): PluginService => new PluginService($repo, $s, $u)),
    SearchService::class       => factory(static fn (SearchRepository $repo, Connection $conn, LoggerInterface $log, CategoryService $cat, HtmlService $h, PermissionService $perm, PreferencesService $pref, StringUtil $s, TagService $tag, UrlService $u, UserService $us, CacheItemPoolInterface $pool): SearchService => new SearchService($repo, $conn, $log, $cat, $h, $perm, $pref, $s, $tag, $u, $us, $pool)),
    SessionService::class      => factory(static fn (SessionRepository $repo): SessionService => new SessionService($repo)),
    TagService::class          => factory(static fn (Connection $conn, HtmlService $h, TagRepository $repo, PermissionService $perm, CacheItemPoolInterface $pool): TagService => new TagService($conn, $h, $repo, $perm, $pool)),
    UrlService::class          => factory(static fn (Connection $conn, StringUtil $s, CategoryService $cat, HtmlService $h, TagService $tag, PermissionService $perm): UrlService => new UrlService($conn, $s, $cat, $h, $tag, $perm)),
    UrlGenerator::class          => factory(static fn (Router $r, UrlService $u): UrlGenerator => new UrlGenerator($r, $u)),
    SectionInitializer::class    => factory(static fn (Connection $conn, CalendarService $cal, CategoryService $cat, HtmlService $h, PermissionService $perm, SearchService $sr, SessionService $sess, StringUtil $s, TagService $tag, UrlService $u, UserRepository $ur, UserService $us, Util $util, CacheItemPoolInterface $pool): SectionInitializer => new SectionInitializer($conn, $cal, $cat, $h, $perm, $sr, $sess, $s, $tag, $u, $ur, $us, $util, $pool)),
    GeneralEndpoints::class          => factory(static fn (Connection $conn, AuthService $auth, CategoryRepository $catR, CommentRepository $com, ConfigService $cfg, CookieService $cookie, DateService $d, HistoryAdminService $hA, HtmlService $h, ImageAdminService $iA, ImageRepository $i, PermissionService $perm, PictureService $pic, RateService $rate, SearchRepository $sR, StringUtil $s, TagRepository $tR, UrlGenerator $ug, UrlService $us, UserAdminService $uA, UserRepository $u, Util $util, WsHelper $ws, CacheItemPoolInterface $pool): GeneralEndpoints => new GeneralEndpoints($conn, $auth, $catR, $com, $cfg, $cookie, $d, $hA, $h, $iA, $i, $perm, $pic, $rate, $sR, $s, $tR, $ug, $us, $uA, $u, $util, $ws, $pool)),
    TagsEndpoints::class             => factory(static fn (Connection $conn, CategoryService $cat, HtmlService $h, ImageRepository $i, TagAdminService $tA, TagRepository $tR, TagService $tag, UrlService $u, Util $util, WsHelper $ws): TagsEndpoints => new TagsEndpoints($conn, $cat, $h, $i, $tA, $tR, $tag, $u, $util, $ws)),
    CommentsEndpoints::class         => factory(static fn (Connection $conn, CommentService $com, DateService $d, UrlGenerator $ug, Util $util): CommentsEndpoints => new CommentsEndpoints($conn, $com, $d, $ug, $util)),
    PermissionsEndpoints::class      => factory(static fn (Connection $conn, CategoryAdminService $catA, CategoryService $cat, PermissionRepository $perm, Util $util): PermissionsEndpoints => new PermissionsEndpoints($conn, $catA, $cat, $perm, $util)),
    ExtensionsEndpoints::class       => factory(static fn (ConfigService $cfg, PermissionService $perm, UrlGenerator $ug, Util $util): ExtensionsEndpoints => new ExtensionsEndpoints($cfg, $perm, $ug, $util)),
    GroupsEndpoints::class           => factory(static fn (Connection $conn, GroupRepository $g, UserAdminService $uA, Util $util): GroupsEndpoints => new GroupsEndpoints($conn, $g, $uA, $util)),
    UsersEndpoints::class            => factory(static fn (Connection $conn, AuthService $auth, ConfigService $cfg, DateService $d, GroupRepository $g, ImageRepository $i, MailService $m, PermissionService $perm, PreferencesService $pref, UserAdminService $uA, UserRepository $u, UserService $us, Util $util, WsHelper $ws): UsersEndpoints => new UsersEndpoints($conn, $auth, $cfg, $d, $g, $i, $m, $perm, $pref, $uA, $u, $us, $util, $ws)),
    CategoriesEndpoints::class       => factory(static fn (Connection $conn, CategoryAdminService $catA, CategoryRepository $catR, CategoryService $cat, HtmlService $h, ImageAdminService $iA, ImageRepository $i, PermissionService $perm, UrlGenerator $ug, UrlService $us, UserAdminService $uA, UserRepository $u, Util $util, WsHelper $ws): CategoriesEndpoints => new CategoriesEndpoints($conn, $catA, $catR, $cat, $h, $iA, $i, $perm, $ug, $us, $uA, $u, $util, $ws)),
    ImagesEndpoints::class           => factory(static fn (Connection $conn, CategoryAdminService $catA, CategoryRepository $catR, CategoryService $cat, CommentService $com, HtmlService $h, ImageAdminService $iA, ImageRepository $i, MetadataAdminService $mA, PermissionService $perm, RateRepository $rR, RateService $rate, SearchService $sr, StringUtil $s, TagAdminService $tA, TagService $tag, UploadService $up, UrlService $u, UserAdminService $uA, Util $util, WsHelper $ws): ImagesEndpoints => new ImagesEndpoints($conn, $catA, $catR, $cat, $com, $h, $iA, $i, $mA, $perm, $rR, $rate, $sr, $s, $tA, $tag, $up, $u, $uA, $util, $ws)),
    AdminService::class              => factory(static fn (Connection $conn, CategoryRepository $catR, DateService $d, HistoryRepository $hR, ImageRepository $i, StringUtil $s, TagRepository $tR, UserRepository $u): AdminService => new AdminService($conn, $catR, $d, $hR, $i, $s, $tR, $u)),
    CategoryAdminService::class      => factory(static function (ContainerInterface $c): CategoryAdminService {
        return (new \ReflectionClass(CategoryAdminService::class))->newLazyProxy(
            static fn (): CategoryAdminService => new CategoryAdminService(
                $c->get(Connection::class),
                $c->get(CategoryRepository::class),
                $c->get(CategoryService::class),
                $c->get(ConfigService::class),
                $c->get(ImageAdminService::class),
                $c->get(ImageRepository::class),
                $c->get(UserAdminService::class),
                $c->get(UserRepository::class),
                $c->get(Util::class),
            )
        );
    }),
    ImageAdminService::class         => factory(static function (ContainerInterface $c): ImageAdminService {
        return (new \ReflectionClass(ImageAdminService::class))->newLazyProxy(
            static fn (): ImageAdminService => new ImageAdminService(
                $c->get(Connection::class),
                $c->get(CategoryAdminService::class),
                $c->get(CategoryRepository::class),
                $c->get(CommentRepository::class),
                $c->get(ConfigService::class),
                $c->get(ImageRepository::class),
                $c->get(StringUtil::class),
                $c->get(TagRepository::class),
                $c->get(UrlGenerator::class),
                $c->get(UserRepository::class),
                $c->get(Util::class),
            )
        );
    }),
    TagAdminService::class           => factory(static fn (Connection $conn, HtmlService $h, ImageAdminService $iA, TagRepository $tR, UserAdminService $uA, Util $util): TagAdminService => new TagAdminService($conn, $h, $iA, $tR, $uA, $util)),
    UserAdminService::class          => factory(static function (ContainerInterface $c): UserAdminService {
        return (new \ReflectionClass(UserAdminService::class))->newLazyProxy(
            static fn (): UserAdminService => new UserAdminService(
                $c->get(Connection::class),
                $c->get(ConfigService::class),
                $c->get(GroupRepository::class),
                $c->get(PermissionRepository::class),
                $c->get(SessionService::class),
                $c->get(UserRepository::class),
                $c->get(UserService::class),
                $c->get(Util::class),
                $c->get(CacheItemPoolInterface::class),
            )
        );
    }),
    NotificationAdminService::class  => factory(static fn (MailService $mail, NotificationRepository $nR, StringUtil $s, UrlGenerator $ug, UrlService $u, UserService $us, Util $util): NotificationAdminService => new NotificationAdminService($mail, $nR, $s, $ug, $u, $us, $util)),
    UploadService::class             => factory(static fn (Connection $conn, CategoryAdminService $catA, ConfigService $cfg, DerivativeService $der, ImageAdminService $iA, ImageRepository $i, MetadataAdminService $mA, StringUtil $s, UserAdminService $uA, Util $util): UploadService => new UploadService($conn, $catA, $cfg, $der, $iA, $i, $mA, $s, $uA, $util)),
    AlbumsTabRenderer::class         => factory(static fn (CategoryRepository $catR): AlbumsTabRenderer => new AlbumsTabRenderer($catR)),
    UserTabRenderer::class           => factory(static fn (): UserTabRenderer => new UserTabRenderer()),
    DirectPreparer::class            => factory(static fn (AdminService $a, CategoryRepository $catR, HtmlService $h, ImageRepository $i, UploadService $up, Util $util): DirectPreparer => new DirectPreparer($a, $catR, $h, $i, $up, $util)),
    FilterResolver::class            => factory(static fn (Connection $conn, HtmlService $h, TagAdminService $tA, Util $util, LangService $lang, UrlService $us): FilterResolver => new FilterResolver($conn, $h, $tA, $util, $lang, $us)),
    SizesProcessor::class            => factory(static fn (ImageAdminService $iA, UploadService $up, Util $util, PermissionService $perm): SizesProcessor => new SizesProcessor($iA, $up, $util, $perm)),
    WatermarkProcessor::class        => factory(static fn (ImageAdminService $iA, StringUtil $s, Util $util, PermissionService $perm): WatermarkProcessor => new WatermarkProcessor($iA, $s, $util, $perm)),
    MenubarRenderer::class           => factory(static fn (CategoryService $cat, PermissionService $perm, TagService $tag, UrlGenerator $ug, UrlService $u, Util $util): MenubarRenderer => new MenubarRenderer($cat, $perm, $tag, $ug, $u, $util)),
    SearchFilterRenderer::class      => factory(static fn (Connection $conn, ConfigService $cfg, DateService $d, HtmlService $h, LangService $lang, PermissionService $perm, SearchService $sr, TagService $tag, UrlService $u, CacheItemPoolInterface $pool): SearchFilterRenderer => new SearchFilterRenderer($conn, $cfg, $d, $h, $lang, $perm, $sr, $tag, $u, $pool)),
    CategoryCatsRenderer::class      => factory(static fn (Connection $conn, CategoryService $cat, DateService $d, FilterService $f, HtmlService $h, PermissionService $perm, UrlService $u, Util $util): CategoryCatsRenderer => new CategoryCatsRenderer($conn, $cat, $d, $f, $h, $perm, $u, $util)),
    CategoryDefaultRenderer::class   => factory(static fn (Connection $conn, CategoryService $cat, HtmlService $h, ImageRepository $i, SessionService $sess, StringUtil $s, UrlService $u, Util $util): CategoryDefaultRenderer => new CategoryDefaultRenderer($conn, $cat, $h, $i, $sess, $s, $u, $util)),
    SelectedTagsRenderer::class      => factory(static fn (UrlService $us): SelectedTagsRenderer => new SelectedTagsRenderer($us)),
    NoPhotoYetRenderer::class        => factory(static fn (ConfigService $cfg, ImageRepository $i, StringUtil $s, UrlGenerator $ug, Util $util, UrlService $us, PermissionService $perm): NoPhotoYetRenderer => new NoPhotoYetRenderer($cfg, $i, $s, $ug, $util, $us, $perm)),
    PictureRateRenderer::class       => factory(static fn (RateRepository $rR, PermissionService $perm, UrlService $us): PictureRateRenderer => new PictureRateRenderer($rR, $perm, $us)),
    PictureCommentRenderer::class    => factory(static fn (Connection $conn, CommentService $com, DateService $d, HtmlService $h, PermissionService $perm, SessionService $sess, UrlService $u, Util $util): PictureCommentRenderer => new PictureCommentRenderer($conn, $com, $d, $h, $perm, $sess, $u, $util)),
    PictureMetadataRenderer::class   => factory(static fn (MetadataService $m): PictureMetadataRenderer => new PictureMetadataRenderer($m)),
    MetadataAdminService::class      => factory(static fn (Connection $conn, ImageRepository $i, MetadataService $m, StringUtil $s, TagAdminService $tA): MetadataAdminService => new MetadataAdminService($conn, $i, $m, $s, $tA)),
    HistoryAdminService::class       => factory(static fn (Connection $conn, HistoryRepository $hR, StringUtil $s): HistoryAdminService => new HistoryAdminService($conn, $hR, $s)),
    WsHelper::class                  => factory(static fn (StringUtil $s, PermissionService $perm, UrlService $us): WsHelper => new WsHelper($s, $perm, $us)),
    StringUtil::class          => factory(static fn (): StringUtil => new StringUtil()),
    DateService::class         => factory(static fn (): DateService => new DateService()),
    LangService::class         => factory(static fn (): LangService => new LangService()),
    ConfigService::class       => factory(static fn (Connection $conn): ConfigService => new ConfigService($conn)),
    QueryHelper::class         => factory(static fn (Connection $conn): QueryHelper => new QueryHelper($conn)),
    Util::class                => factory(static fn (Connection $conn, LoggerInterface $log, LangService $lang, LanguageRepository $langRepo, ThemeRepository $themeRepo, UserRepository $u, PermissionService $perm, HtmlService $html, SessionService $sess, ConfigService $cfg, HistoryRepository $hist, HistoryAdminService $histA, CategoryAdminService $catA, AdminService $admin, StringUtil $s): Util => new Util($conn, $log, $lang, $langRepo, $themeRepo, $u, $perm, $html, $sess, $cfg, $hist, $histA, $catA, $admin, $s)),

    // Domain repositories — injected with the shared DBAL connection and table prefix.
    TagRepository::class          => factory(static fn (Connection $conn): TagRepository => new TagRepository($conn, Config::dbPrefix())),
    CommentRepository::class      => factory(static fn (Connection $conn): CommentRepository => new CommentRepository($conn, Config::dbPrefix())),
    SearchRepository::class       => factory(static fn (Connection $conn): SearchRepository => new SearchRepository($conn, Config::dbPrefix())),
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

    // ── PSR-7/15 routing infrastructure ──────────────────────────────────────
    Router::class                     => factory(static fn (): Router => new Router(dirname(__DIR__) . '/config/routes.php')),
    ExceptionHandlerMiddleware::class => factory(static fn (): ExceptionHandlerMiddleware => new ExceptionHandlerMiddleware()),
    SessionMiddleware::class          => factory(static fn (): SessionMiddleware => new SessionMiddleware()),
    AuthMiddleware::class             => factory(static fn (): AuthMiddleware => new AuthMiddleware()),
    FilterMiddleware::class           => factory(static fn (Connection $conn, CategoryService $cat, SessionService $sess, Util $util): FilterMiddleware => new FilterMiddleware($conn, $cat, $sess, $util)),
    CsrfMiddleware::class             => factory(static fn (Util $util): CsrfMiddleware => new CsrfMiddleware($util)),
    RoutingMiddleware::class          => factory(static fn (Router $r): RoutingMiddleware => new RoutingMiddleware($r)),
    ControllerInvokerMiddleware::class => factory(static fn (ContainerInterface $c): ControllerInvokerMiddleware => new ControllerInvokerMiddleware($c)),
];
