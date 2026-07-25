<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Piwigo\Category\CategoryRepository;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\Lang;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Feed\FeedHelper;
use Piwigo\Feed\FeedRepository;
use Piwigo\Group\GroupRepository;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Notification\NotificationRepository;
use Piwigo\Notification\NotificationService;
use Piwigo\Permission\PermissionRepository;
use Piwigo\Permission\PermissionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces feed.php -- generates the RSS2 gallery feed. Unlike every other
 * P22 controller, this page never touches Template/page_header.php/
 * page_tail.php at all (it's a plain XML response, not an HTML page), so
 * there's no LegacyRenderCapture involved -- the whole body is built as a
 * plain string and returned via ResponseFactory::raw() with the feed's own
 * Content-Type/Content-Disposition headers.
 *
 * page_not_found() happens before any output is built, so it's fine to
 * leave outside of any capture -- same exit()-based-termination limitation
 * as every other controller this phase.
 */
final class FeedController implements ControllerInterface
{
    public function __construct(
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
    ) {}

    private static function userService(Connection $conn): \Piwigo\Users\UserService
    {
        return new \Piwigo\Users\UserService(new \Piwigo\Users\UserRepository($conn), new GroupRepository($conn), new \Piwigo\Mail\MailService(), new \Piwigo\Activity\ActivityService(new \Piwigo\Activity\ActivityRepository($conn)), new HtmlService(), $conn);
    }

    #[\Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $htmlRenderer = new HtmlService();

        $feed_helper = new FeedHelper();
        $conn = DbConnection::build();
        $feed_repo = new FeedRepository($conn);
        $notificationService = new NotificationService(
            new NotificationRepository($conn),
            new PermissionService(new PermissionRepository($conn), new GroupRepository($conn), new CategoryRepository($conn)),
            $htmlRenderer,
            $this->urlService
        );

        new \Piwigo\Validation\InputValidator()
            ->validate('feed', $_GET, false, '/^[0-9a-z]{50}$/i');

        $feed_id = $_GET['feed'] ?? '';
        $feed_id = is_string($feed_id) ? $feed_id : '';
        $image_only = isset($_GET['image_only']);
        // Only read below when $image_only is false, which implies $feed_id
        // was non-empty and the branch below already populated it.
        $feed_last_check = null;

        if ($feed_id !== '') {
            $feed_row = $feed_repo->findById($feed_id);
            if ($feed_row === null) {
                $htmlRenderer->pageNotFound($this->redirectService, Lang::t('Unknown feed identifier'));
            }
            $feed_last_check = $feed_row['lastCheck'];
            if ($feed_row['userId'] !== \Piwigo\Users\CurrentUser::get()->id) { // new user
                $feed_owner = self::userService($conn)->buildUser($feed_row['userId'], true);
                // The feed is per-user-token, so this request's "current user"
                // genuinely becomes the feed owner, not the real session user.
                \Piwigo\Users\CurrentUser::set(\Piwigo\Users\User::fromUserArray($feed_owner));
            }
        } else {
            $image_only = true;
            if (! \Piwigo\Auth\AccessControl::isAGuest()) {// auto session was created - so switch to guest
                $guest_id = \Piwigo\Config\CurrentConfig::guestId();
                $guest_user = self::userService($conn)->buildUser($guest_id, true);
                \Piwigo\Users\CurrentUser::set(\Piwigo\Users\User::fromUserArray($guest_user));
            }
        }

        // Check the status now after the user has been loaded
        \Piwigo\Auth\AccessControl::checkStatus(AccessLevel::Guest);

        $dbnow = $conn->fetchOne('SELECT NOW();');
        // NOW() is a MySQL builtin that always returns a valid non-null
        // datetime value, never a nullable column read.
        assert(is_string($dbnow));

        $this->urlService->setMakeFullUrl();

        $rss_encoding = \Piwigo\Core\CharsetHelper::getPwgCharset();

        $conf_gallery_title = \Piwigo\Config\CurrentConfig::galleryTitle();
        $conf_rss_feed_author = \Piwigo\Config\CurrentConfig::rssReedAuthor();
        $user_username = \Piwigo\Users\CurrentUser::get()->username;

        $rss_title = $conf_gallery_title . ' (as ' . stripslashes($user_username) . ')';
        $rss_link = $this->urlService->getGalleryHomeUrl();
        $rss_items = [];

        // +-------------------------------------------------------------------+
        // |                            Feed creation                          |
        // +-------------------------------------------------------------------+

        $news = [];
        if (! $image_only) {
            $news = $notificationService->news($feed_last_check?->format('Y-m-d H:i:s'), $dbnow, true, true);

            if (count($news) > 0) {
                // content creation
                $description = '<ul>';
                foreach ($news as $line) {
                    $description .= '<li>' . $line . '</li>';
                }
                $description .= '</ul>';

                $dbnow_ts = $feed_helper->datetimeToTs($dbnow);
                // $dbnow is a NOW() value straight from the database,
                // always a valid datetime string
                assert($dbnow_ts !== false);

                $rss_items[] = [
                    'title' => Lang::t('New on %s', \Piwigo\Core\DateHelper::formatDate($dbnow)),
                    'link' => $this->urlService->getGalleryHomeUrl(),
                    'description' => $description,
                    'html' => true,
                    'date' => $feed_helper->ts8601($dbnow_ts),
                    'author' => $conf_rss_feed_author,
                    'guid' => sprintf('%s', $dbnow),
                ];

                if ($feed_id !== '') {
                    $feed_repo->updateLastCheck($feed_id, new DateTimeImmutable($dbnow));
                }
            }
        }

        if ($feed_id !== '' && count($news) === 0) {// update the last check from time to time to avoid deletion by maintenance tasks
            if ($feed_last_check === null
              || time() - $feed_last_check->getTimestamp() > 30 * 24 * 3600) {
                // SUBDATE($dbnow, INTERVAL -15 DAY) computed in PHP instead
                // -- cross-provider safe (SUBDATE() is MySQL-only), same
                // reasoning as FeedRepository::updateLastCheck()'s own doc
                // comment.
                $feed_repo->updateLastCheck($feed_id, new DateTimeImmutable($dbnow)->modify('+15 days'));
            }
        }

        // Real bug found via PHPStan: CurrentConfig::recentPostDates() was retyped
        // to return a NotificationConfig object, but this caller still
        // checked is_array() against it -- always false, so RSS recent-post-
        // date limits always fell back to getRecentPostDatesArray()'s own
        // hardcoded 3/3/3 defaults, ignoring any configured values.
        $rss_config = \Piwigo\Config\CurrentConfig::recentPostDates()->rss;
        $rss_recent_post_dates_args = [
            'max_dates' => $rss_config->maxDates,
            'max_elements' => $rss_config->maxElements,
            'max_cats' => $rss_config->maxCats,
        ];

        /** @var array<int, array<string, mixed>> $dates */
        $dates = $notificationService->getRecentPostDatesArray($rss_recent_post_dates_args);

        foreach ($dates as $date_detail) { // for each recent post date we create a feed item
            $date = $date_detail['date_available'];
            // date_available is a NOT NULL datetime column, always a
            // string.
            assert(is_string($date));
            $link = $this->urlService->makeIndexUrl(
                [
                    'chronology_field' => 'posted',
                    'chronology_style' => 'monthly',
                    'chronology_view' => 'calendar',
                    'chronology_date' => explode('-', substr($date, 0, 10)),
                ]
            );

            $description = '<a href="' . $this->urlService->makeIndexUrl() . '">' . $conf_gallery_title . '</a><br> ';
            $description .= $notificationService->getHtmlDescriptionRecentPostDate($date_detail);

            $date_ts = $feed_helper->datetimeToTs($date);
            // $date is a date_available value straight from the database,
            // always a valid datetime string
            assert($date_ts !== false);

            $rss_items[] = [
                'title' => $notificationService->getTitleRecentPostDate($date_detail),
                'link' => $link,
                'description' => $description,
                'html' => true,
                'date' => $feed_helper->ts8601($date_ts),
                'author' => $conf_rss_feed_author,
                'guid' => sprintf('%s', 'pics-' . $date),
            ];
        }

        $feed_content = $feed_helper->generateRss2Feed(
            [
                'title' => $rss_title,
                'link' => $rss_link,
                'encoding' => $rss_encoding,
            ],
            $rss_items
        );

        $data_location = \Piwigo\Config\CurrentConfig::dataLocation();

        $fileName = \Piwigo\Core\CurrentPaths::get()->root . $data_location . 'tmp';
        \Piwigo\Core\FilesystemHelper::mkgetdir($fileName); // just in case
        $fileName .= '/feed.xml';
        file_put_contents($fileName, $feed_content);

        // send XML feed
        return ResponseFactory::raw($feed_content, [
            'Content-Type' => 'application/rss+xml; charset=' . $rss_encoding . '; filename=' . basename($fileName),
            'Content-Disposition' => 'inline; filename=' . basename($fileName),
        ]);
    }
}
