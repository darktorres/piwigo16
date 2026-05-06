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
    AuthService::class         => factory(static fn (UserRepository $u, AuthKeyRepository $ak, Connection $conn): AuthService => new AuthService($u, $ak, $conn)),
    CalendarService::class     => factory(static fn (): CalendarService => new CalendarService()),
    MailService::class         => factory(static fn (Connection $conn): MailService => new MailService($conn)),
    PermissionService::class   => factory(static fn (Connection $conn): PermissionService => new PermissionService($conn)),
    PreferencesService::class  => factory(static fn (): PreferencesService => new PreferencesService()),
    UserService::class         => factory(static fn (UserRepository $u, Connection $conn, HistoryRepository $h, ActivityRepository $a, GroupRepository $g, AuthKeyRepository $ak): UserService => new UserService($u, $conn, $h, $a, $g, $ak)),
    CategoryService::class     => factory(static fn (CategoryRepository $cat, Connection $conn): CategoryService => new CategoryService($cat, $conn)),
    HtmlService::class         => factory(static fn (): HtmlService => new HtmlService()),
    NotificationService::class => factory(static fn (Connection $conn): NotificationService => new NotificationService($conn)),
    PluginService::class       => factory(static fn (PluginRepository $repo): PluginService => new PluginService($repo)),
    SearchService::class       => factory(static fn (SearchRepository $repo, Connection $conn, LoggerInterface $log): SearchService => new SearchService($repo, $conn, $log)),
    SessionService::class      => factory(static fn (SessionRepository $repo): SessionService => new SessionService($repo)),
    TagService::class          => factory(static fn (TagRepository $repo): TagService => new TagService($repo)),
    UrlService::class          => factory(static fn (): UrlService => new UrlService()),
    UrlGenerator::class          => factory(static fn (Router $r, UrlService $u): UrlGenerator => new UrlGenerator($r, $u)),
    SectionInitializer::class    => factory(static fn (): SectionInitializer => new SectionInitializer()),
    GeneralEndpoints::class          => factory(static fn (): GeneralEndpoints => new GeneralEndpoints()),
    TagsEndpoints::class             => factory(static fn (): TagsEndpoints => new TagsEndpoints()),
    CommentsEndpoints::class         => factory(static fn (): CommentsEndpoints => new CommentsEndpoints()),
    PermissionsEndpoints::class      => factory(static fn (): PermissionsEndpoints => new PermissionsEndpoints()),
    ExtensionsEndpoints::class       => factory(static fn (): ExtensionsEndpoints => new ExtensionsEndpoints()),
    GroupsEndpoints::class           => factory(static fn (): GroupsEndpoints => new GroupsEndpoints()),
    UsersEndpoints::class            => factory(static fn (): UsersEndpoints => new UsersEndpoints()),
    CategoriesEndpoints::class       => factory(static fn (): CategoriesEndpoints => new CategoriesEndpoints()),
    ImagesEndpoints::class           => factory(static fn (): ImagesEndpoints => new ImagesEndpoints()),
    AdminService::class              => factory(static fn (Connection $conn): AdminService => new AdminService($conn)),
    CategoryAdminService::class      => factory(static fn (Connection $conn): CategoryAdminService => new CategoryAdminService($conn)),
    ImageAdminService::class         => factory(static fn (): ImageAdminService => new ImageAdminService()),
    TagAdminService::class           => factory(static fn (Connection $conn): TagAdminService => new TagAdminService($conn)),
    UserAdminService::class          => factory(static fn (): UserAdminService => new UserAdminService()),
    NotificationAdminService::class  => factory(static fn (): NotificationAdminService => new NotificationAdminService()),
    UploadService::class             => factory(static fn (): UploadService => new UploadService()),
    AlbumsTabRenderer::class         => factory(static fn (): AlbumsTabRenderer => new AlbumsTabRenderer()),
    UserTabRenderer::class           => factory(static fn (): UserTabRenderer => new UserTabRenderer()),
    DirectPreparer::class            => factory(static fn (): DirectPreparer => new DirectPreparer()),
    FilterResolver::class            => factory(static fn (): FilterResolver => new FilterResolver()),
    SizesProcessor::class            => factory(static fn (): SizesProcessor => new SizesProcessor()),
    WatermarkProcessor::class        => factory(static fn (): WatermarkProcessor => new WatermarkProcessor()),
    MenubarRenderer::class           => factory(static fn (): MenubarRenderer => new MenubarRenderer()),
    SearchFilterRenderer::class      => factory(static fn (): SearchFilterRenderer => new SearchFilterRenderer()),
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
    Util::class                => factory(static fn (Connection $conn, LoggerInterface $log): Util => new Util($conn, $log)),

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
    FilterMiddleware::class           => factory(static fn (): FilterMiddleware => new FilterMiddleware()),
    CsrfMiddleware::class             => factory(static fn (): CsrfMiddleware => new CsrfMiddleware()),
    RoutingMiddleware::class          => factory(static fn (Router $r): RoutingMiddleware => new RoutingMiddleware($r)),
    ControllerInvokerMiddleware::class => factory(static fn (ContainerInterface $c): ControllerInvokerMiddleware => new ControllerInvokerMiddleware($c)),
];
