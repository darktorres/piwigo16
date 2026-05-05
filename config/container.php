<?php

declare(strict_types=1);

use function DI\factory;

use Doctrine\DBAL\Connection;
use Piwigo\Activity\ActivityRepository;
use Piwigo\Auth\AuthKeyRepository;
use Piwigo\Auth\CookieService;
use Piwigo\Category\CategoryRepository;
use Piwigo\Comment\CommentService;
use Piwigo\Html\HtmlService;
use Piwigo\Comment\CommentRepository;
use Piwigo\Config\Config;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\PageState;
use Piwigo\Db\DbConnection;
use Piwigo\Feed\FeedRepository;
use Piwigo\Filter\FilterService;
use Piwigo\Group\GroupRepository;
use Piwigo\Metadata\MetadataService;
use Piwigo\Tag\TagService;
use Piwigo\Url\UrlService;
use Piwigo\History\HistoryRepository;
use Piwigo\Image\ImageRepository;
use Piwigo\Lang\Translator;
use Piwigo\Language\LanguageRepository;
use Piwigo\Notification\NotificationRepository;
use Piwigo\Permalink\PermalinkRepository;
use Piwigo\Picture\PictureService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Plugin\PluginRepository;
use Piwigo\Rate\RateRepository;
use Piwigo\Rate\RateService;
use Piwigo\Search\SearchRepository;
use Piwigo\Session\SessionRepository;
use Piwigo\Site\SiteRepository;
use Piwigo\Storage\StorageRegistry;
use Piwigo\Tag\TagRepository;
use Piwigo\Theme\ThemeRepository;
use Piwigo\Users\UserRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

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

    // Domain services — stateless; inject only what they need.
    CookieService::class   => factory(static fn (): CookieService => new CookieService()),
    FilterService::class   => factory(static fn (): FilterService => new FilterService()),
    MetadataService::class => factory(static fn (LoggerInterface $log): MetadataService => new MetadataService($log)),
    PictureService::class  => factory(static fn (ImageRepository $r): PictureService => new PictureService($r)),
    RateService::class     => factory(static fn (RateRepository $rate, ImageRepository $img, CookieService $c): RateService => new RateService($rate, $img, $c)),
    CommentService::class  => factory(static fn (CommentRepository $repo): CommentService => new CommentService($repo)),
    HtmlService::class     => factory(static fn (): HtmlService => new HtmlService()),
    TagService::class      => factory(static fn (TagRepository $repo): TagService => new TagService($repo)),
    UrlService::class      => factory(static fn (): UrlService => new UrlService()),

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
];
