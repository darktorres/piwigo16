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
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;

return [
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

    DerivativeService::class   => factory(static fn (): DerivativeService => new DerivativeService()),

    // Symfony Messenger bus — transports backed by the shared DBAL connection.
    MessageBusInterface::class => factory(static fn (Connection $conn): MessageBusInterface => MessengerFactory::build($conn)),

    // Domain services — stateless; inject only what they need.
    CookieService::class   => factory(static fn (): CookieService => new CookieService()),
    FilterService::class   => factory(static fn (): FilterService => new FilterService()),
    MetadataService::class => factory(static fn (LoggerInterface $log): MetadataService => new MetadataService($log)),
    PictureService::class  => factory(static fn (ImageRepository $r): PictureService => new PictureService($r)),
    RateService::class     => factory(static fn (RateRepository $rate, ImageRepository $img, CookieService $c): RateService => new RateService($rate, $img, $c)),
    CommentService::class  => factory(static fn (CommentRepository $repo): CommentService => new CommentService($repo)),
    AuthService::class         => factory(static fn (UserRepository $u, AuthKeyRepository $ak, Connection $conn, StringUtil $s, Util $util, SessionService $sess, UrlGenerator $ug, UrlService $us, DateService $d): AuthService => new AuthService($u, $ak, $conn, $s, $util, $sess, $ug, $us, $d)),
    PasswordService::class     => factory(static fn (AuthService $auth, MailService $mail, PermissionService $perm, PreferencesService $pref, StringUtil $s, UrlGenerator $ug, UserRepository $u, UserService $us, Util $util): PasswordService => new PasswordService($auth, $mail, $perm, $pref, $s, $ug, $u, $us, $util)),
    CalendarService::class     => factory(static fn (): CalendarService => new CalendarService()),
    MailService::class         => factory(static fn (Connection $conn): MailService => new MailService($conn)),
    PermissionService::class   => factory(static fn (Connection $conn, HtmlService $h): PermissionService => new PermissionService($conn, $h)),
    PreferencesService::class  => factory(static fn (Util $u, UserRepository $r): PreferencesService => new PreferencesService($u, $r)),
    UserService::class         => factory(static fn (UserRepository $u, Connection $conn, HistoryRepository $h, ActivityRepository $a, GroupRepository $g, AuthKeyRepository $ak, StringUtil $s, Util $util, LangService $lang, UrlGenerator $ug, MailService $mail, MessageBusInterface $bus, HtmlService $html, DateService $date, CategoryService $cat, UserAdminService $uas, SessionService $sess, AuthService $auth, PreferencesService $pref, PermissionService $perm): UserService => new UserService($u, $conn, $h, $a, $g, $ak, $s, $util, $lang, $ug, $mail, $bus, $html, $date, $cat, $uas, $sess, $auth, $pref, $perm)),
    ProfileService::class      => factory(static fn (): ProfileService => new ProfileService()),
    CategoryService::class     => factory(static fn (CategoryRepository $cat, Connection $conn): CategoryService => new CategoryService($cat, $conn)),
    HtmlService::class         => factory(static fn (): HtmlService => new HtmlService()),
    NotificationService::class => factory(static fn (Connection $conn): NotificationService => new NotificationService($conn)),
    PluginService::class       => factory(static fn (PluginRepository $repo, StringUtil $s, Util $u): PluginService => new PluginService($repo, $s, $u)),
    SearchService::class       => factory(static fn (SearchRepository $repo, Connection $conn, LoggerInterface $log): SearchService => new SearchService($repo, $conn, $log)),
    SessionService::class      => factory(static fn (SessionRepository $repo): SessionService => new SessionService($repo)),
    TagService::class          => factory(static fn (TagRepository $repo): TagService => new TagService($repo)),
    UrlService::class          => factory(static fn (Connection $conn, StringUtil $s, CategoryService $cat, HtmlService $h, TagService $tag, PermissionService $perm): UrlService => new UrlService($conn, $s, $cat, $h, $tag, $perm)),
    UrlGenerator::class          => factory(static fn (Router $r, UrlService $u): UrlGenerator => new UrlGenerator($r, $u)),
    SectionInitializer::class    => factory(static fn (Connection $conn, CalendarService $cal, CategoryService $cat, HtmlService $h, PermissionService $perm, SearchService $sr, SessionService $sess, StringUtil $s, TagService $tag, UrlService $u, UserRepository $ur, UserService $us, Util $util): SectionInitializer => new SectionInitializer($conn, $cal, $cat, $h, $perm, $sr, $sess, $s, $tag, $u, $ur, $us, $util)),
    GeneralEndpoints::class          => factory(static fn (Connection $conn, AuthService $auth, CategoryRepository $catR, CommentRepository $com, ConfigService $cfg, CookieService $cookie, DateService $d, HistoryAdminService $hA, HtmlService $h, ImageAdminService $iA, ImageRepository $i, PermissionService $perm, PictureService $pic, RateService $rate, SearchRepository $sR, StringUtil $s, TagRepository $tR, UrlGenerator $ug, UrlService $us, UserAdminService $uA, UserRepository $u, Util $util, WsHelper $ws): GeneralEndpoints => new GeneralEndpoints($conn, $auth, $catR, $com, $cfg, $cookie, $d, $hA, $h, $iA, $i, $perm, $pic, $rate, $sR, $s, $tR, $ug, $us, $uA, $u, $util, $ws)),
    TagsEndpoints::class             => factory(static fn (Connection $conn, CategoryService $cat, HtmlService $h, ImageRepository $i, TagAdminService $tA, TagRepository $tR, TagService $tag, UrlService $u, Util $util, WsHelper $ws): TagsEndpoints => new TagsEndpoints($conn, $cat, $h, $i, $tA, $tR, $tag, $u, $util, $ws)),
    CommentsEndpoints::class         => factory(static fn (Connection $conn, CommentService $com, DateService $d, UrlGenerator $ug, Util $util): CommentsEndpoints => new CommentsEndpoints($conn, $com, $d, $ug, $util)),
    PermissionsEndpoints::class      => factory(static fn (Connection $conn, CategoryAdminService $catA, CategoryService $cat, PermissionRepository $perm, Util $util): PermissionsEndpoints => new PermissionsEndpoints($conn, $catA, $cat, $perm, $util)),
    ExtensionsEndpoints::class       => factory(static fn (ConfigService $cfg, PermissionService $perm, UrlGenerator $ug, Util $util): ExtensionsEndpoints => new ExtensionsEndpoints($cfg, $perm, $ug, $util)),
    GroupsEndpoints::class           => factory(static fn (Connection $conn, GroupRepository $g, UserAdminService $uA, Util $util): GroupsEndpoints => new GroupsEndpoints($conn, $g, $uA, $util)),
    UsersEndpoints::class            => factory(static fn (Connection $conn, AuthService $auth, ConfigService $cfg, DateService $d, GroupRepository $g, ImageRepository $i, MailService $m, PermissionService $perm, PreferencesService $pref, UserAdminService $uA, UserRepository $u, UserService $us, Util $util, WsHelper $ws): UsersEndpoints => new UsersEndpoints($conn, $auth, $cfg, $d, $g, $i, $m, $perm, $pref, $uA, $u, $us, $util, $ws)),
    CategoriesEndpoints::class       => factory(static fn (Connection $conn, CategoryAdminService $catA, CategoryRepository $catR, CategoryService $cat, HtmlService $h, ImageAdminService $iA, ImageRepository $i, PermissionService $perm, UrlGenerator $ug, UrlService $us, UserAdminService $uA, UserRepository $u, Util $util, WsHelper $ws): CategoriesEndpoints => new CategoriesEndpoints($conn, $catA, $catR, $cat, $h, $iA, $i, $perm, $ug, $us, $uA, $u, $util, $ws)),
    ImagesEndpoints::class           => factory(static fn (Connection $conn, CategoryAdminService $catA, CategoryRepository $catR, CategoryService $cat, CommentService $com, HtmlService $h, ImageAdminService $iA, ImageRepository $i, MetadataAdminService $mA, PermissionService $perm, RateRepository $rR, RateService $rate, SearchService $sr, StringUtil $s, TagAdminService $tA, TagService $tag, UploadService $up, UrlService $u, UserAdminService $uA, Util $util, WsHelper $ws): ImagesEndpoints => new ImagesEndpoints($conn, $catA, $catR, $cat, $com, $h, $iA, $i, $mA, $perm, $rR, $rate, $sr, $s, $tA, $tag, $up, $u, $uA, $util, $ws)),
    AdminService::class              => factory(static fn (Connection $conn): AdminService => new AdminService($conn)),
    CategoryAdminService::class      => factory(static fn (Connection $conn, CategoryRepository $catR, CategoryService $cat, ConfigService $cfg, ImageAdminService $iA, ImageRepository $i, UserAdminService $uA, UserRepository $u, Util $util): CategoryAdminService => new CategoryAdminService($conn, $catR, $cat, $cfg, $iA, $i, $uA, $u, $util)),
    ImageAdminService::class         => factory(static fn (Connection $conn, CategoryAdminService $catA, CategoryRepository $catR, CommentRepository $com, ConfigService $cfg, ImageRepository $i, StringUtil $s, TagRepository $tR, UrlGenerator $ug, UserRepository $u, Util $util): ImageAdminService => new ImageAdminService($conn, $catA, $catR, $com, $cfg, $i, $s, $tR, $ug, $u, $util)),
    TagAdminService::class           => factory(static fn (Connection $conn): TagAdminService => new TagAdminService($conn)),
    UserAdminService::class          => factory(static fn (): UserAdminService => new UserAdminService()),
    NotificationAdminService::class  => factory(static fn (): NotificationAdminService => new NotificationAdminService()),
    UploadService::class             => factory(static fn (Connection $conn, CategoryAdminService $catA, ConfigService $cfg, DerivativeService $der, ImageAdminService $iA, ImageRepository $i, MetadataAdminService $mA, StringUtil $s, UserAdminService $uA, Util $util): UploadService => new UploadService($conn, $catA, $cfg, $der, $iA, $i, $mA, $s, $uA, $util)),
    AlbumsTabRenderer::class         => factory(static fn (): AlbumsTabRenderer => new AlbumsTabRenderer()),
    UserTabRenderer::class           => factory(static fn (): UserTabRenderer => new UserTabRenderer()),
    DirectPreparer::class            => factory(static fn (): DirectPreparer => new DirectPreparer()),
    FilterResolver::class            => factory(static fn (): FilterResolver => new FilterResolver()),
    SizesProcessor::class            => factory(static fn (): SizesProcessor => new SizesProcessor()),
    WatermarkProcessor::class        => factory(static fn (): WatermarkProcessor => new WatermarkProcessor()),
    MenubarRenderer::class           => factory(static fn (CategoryService $cat, PermissionService $perm, TagService $tag, UrlGenerator $ug, UrlService $u, Util $util): MenubarRenderer => new MenubarRenderer($cat, $perm, $tag, $ug, $u, $util)),
    SearchFilterRenderer::class      => factory(static fn (Connection $conn, ConfigService $cfg, DateService $d, HtmlService $h, LangService $lang, PermissionService $perm, SearchService $sr, TagService $tag, UrlService $u): SearchFilterRenderer => new SearchFilterRenderer($conn, $cfg, $d, $h, $lang, $perm, $sr, $tag, $u)),
    CategoryCatsRenderer::class      => factory(static fn (): CategoryCatsRenderer => new CategoryCatsRenderer()),
    CategoryDefaultRenderer::class   => factory(static fn (): CategoryDefaultRenderer => new CategoryDefaultRenderer()),
    SelectedTagsRenderer::class      => factory(static fn (): SelectedTagsRenderer => new SelectedTagsRenderer()),
    NoPhotoYetRenderer::class        => factory(static fn (): NoPhotoYetRenderer => new NoPhotoYetRenderer()),
    PictureRateRenderer::class       => factory(static fn (): PictureRateRenderer => new PictureRateRenderer()),
    PictureCommentRenderer::class    => factory(static fn (): PictureCommentRenderer => new PictureCommentRenderer()),
    PictureMetadataRenderer::class   => factory(static fn (): PictureMetadataRenderer => new PictureMetadataRenderer()),
    MetadataAdminService::class      => factory(static fn (): MetadataAdminService => new MetadataAdminService()),
    HistoryAdminService::class       => factory(static fn (): HistoryAdminService => new HistoryAdminService()),
    WsHelper::class                  => factory(static fn (): WsHelper => new WsHelper()),
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
    PermalinkService::class        => factory(static fn (): PermalinkService => new PermalinkService()),
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
    CsrfMiddleware::class             => factory(static fn (): CsrfMiddleware => new CsrfMiddleware()),
    RoutingMiddleware::class          => factory(static fn (Router $r): RoutingMiddleware => new RoutingMiddleware($r)),
    ControllerInvokerMiddleware::class => factory(static fn (ContainerInterface $c): ControllerInvokerMiddleware => new ControllerInvokerMiddleware($c)),
];
