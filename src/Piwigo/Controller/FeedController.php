<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use DateTimeImmutable;
use Override;
use Piwigo\Auth\AccessControl;
use Piwigo\Common\ValueObject\UserId;
use Piwigo\Config\CurrentConfig;
use Piwigo\Controller\Request\FeedRequest;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\CharsetHelper;
use Piwigo\Core\DateHelper;
use Piwigo\Core\Env;
use Piwigo\Core\FilesystemHelper;
use Piwigo\Core\HtmlRenderingInterface;
use Piwigo\Core\Lang;
use Piwigo\Core\Paths;
use Piwigo\Core\RedirectServiceInterface;
use Piwigo\Core\UrlServiceInterface;
use Piwigo\Db\DbConnection;
use Piwigo\Db\EntityManagerFactory;
use Piwigo\Feed\FeedEntity;
use Piwigo\Feed\FeedHelper;
use Piwigo\Http\ControllerInterface;
use Piwigo\Http\ResponseFactory;
use Piwigo\Notification\NotificationService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\User;
use Piwigo\Users\UserService;
use Piwigo\Validation\InputValidator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Replaces feed.php -- generates the RSS2 gallery feed. Unlike every other
 * controller, this page never touches Template/page_header.php/
 * page_tail.php at all (it's a plain XML response, not an HTML page), so
 * there's no LegacyRenderCapture involved -- the whole body is built as a
 * plain string and returned via ResponseFactory::raw() with the feed's own
 * Content-Type/Content-Disposition headers.
 *
 * page_not_found() happens before any output is built, so it's fine to
 * leave outside of any capture -- same exit()-based-termination limitation
 * as every other controller.
 */
final class FeedController implements ControllerInterface
{
    public function __construct(
        private readonly Lang $lang,
        private readonly AccessControl $accessControl,
        private readonly RedirectServiceInterface $redirectService,
        private readonly UrlServiceInterface $urlService,
        private readonly CurrentUser $currentUser,
        private readonly NotificationService $notificationService,
        private readonly UserService $userService,
        private readonly HtmlRenderingInterface $htmlRenderer,
        private readonly CurrentConfig $currentConfig,
        private readonly InputValidator $inputValidator,
        private readonly Paths $paths,
    ) {}

    #[Override]
    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $htmlRenderer = $this->htmlRenderer;

        $feed_helper = new FeedHelper();
        $conn = DbConnection::build();
        $feed_repo = EntityManagerFactory::build($conn)->getRepository(FeedEntity::class);
        $notificationService = $this->notificationService;

        $feedRequest = FeedRequest::fromGlobals($this->inputValidator);
        $feed_id = $feedRequest->feedId;
        $image_only = $feedRequest->imageOnly;
        // Only read below when $image_only is false, which implies $feed_id
        // was non-empty and the branch below already populated it.
        $feed_last_check = null;

        if ($feed_id !== '') {
            $feed_row = $feed_repo->findById($feed_id);
            if ($feed_row === null) {
                $htmlRenderer->pageNotFound($this->redirectService, $this->lang->t('Unknown feed identifier'));
            }
            $feed_last_check = $feed_row->lastCheck;
            if ($feed_row->userId !== $this->currentUser->get()->id->value) { // new user
                $feed_owner = $this->userService->buildUser(UserId::from($feed_row->userId));
                // The feed is per-user-token, so this request's "current user"
                // genuinely becomes the feed owner, not the real session user.
                $this->currentUser->set(User::fromUserArray($feed_owner));
            }
        } else {
            $image_only = true;
            if (! $this->accessControl->isAGuest()) {// auto session was created - so switch to guest
                $guest_id = $this->currentConfig->guestId;
                $guest_user = $this->userService->buildUser(UserId::from($guest_id));
                $this->currentUser->set(User::fromUserArray($guest_user));
            }
        }

        // Check the status now after the user has been loaded
        $this->accessControl->checkStatus(AccessLevel::Guest);

        // Env::now() rather than SQL's NOW() -- the real DB-server clock,
        // invisible to Env::now()'s own PIWIGO_TEST_NOW freeze, same
        // reasoning as every other NOW()-reading call site this migration
        // already retargeted.
        $dbnow = Env::now()->format('Y-m-d H:i:s');

        $this->urlService->setMakeFullUrl();

        $rss_encoding = CharsetHelper::getPwgCharset();

        $conf_gallery_title = $this->currentConfig->galleryTitle;
        $conf_rss_feed_author = $this->currentConfig->rssReedAuthor;
        $user_username = $this->currentUser->get()
            ->username->value ?? '';

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
                    'title' => $this->lang->t('New on %s', DateHelper::formatDate($dbnow)),
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
        $rss_config = $this->currentConfig->recentPostDates
            ->rss;
        $rss_recent_post_dates_args = [
            'max_dates' => $rss_config->maxDates,
            'max_elements' => $rss_config->maxElements,
            'max_cats' => $rss_config->maxCats,
        ];

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

        $data_location = $this->currentConfig->dataLocation;

        $fileName = $this->paths->root . $data_location . 'tmp';
        FilesystemHelper::mkgetdir($fileName, $this->currentConfig); // just in case
        $fileName .= '/feed.xml';
        file_put_contents($fileName, $feed_content);

        // send XML feed
        return ResponseFactory::raw($feed_content, [
            'Content-Type' => 'application/rss+xml; charset=' . $rss_encoding . '; filename=' . basename($fileName),
            'Content-Disposition' => 'inline; filename=' . basename($fileName),
        ]);
    }
}
