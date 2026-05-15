<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Config\Config;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\DateService;
use Piwigo\Core\Lang;
use Piwigo\Core\StringUtil;
use Piwigo\Core\Util;
use Piwigo\Feed\FeedHelper;
use Piwigo\Feed\FeedItem;
use Piwigo\Feed\FeedRepository;
use Piwigo\Feed\PiwigoFeedCreator;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ResponseFactory;
use Piwigo\Notification\NotificationService;
use Piwigo\Url\UrlService;
use Piwigo\Users\CurrentUser;
use Piwigo\Users\PermissionService;
use Piwigo\Users\UserService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Generates the RSS 2.0 feed and sends it directly as XML output.
 * Corresponds to the former feed.php entry-point.
 */
final readonly class FeedController implements ControllerInterface
{
    public function __construct(
        private DateService $dateService,
        private FeedRepository $feedRepository,
        private HtmlService $htmlService,
        private NotificationService $notificationService,
        private PermissionService $permissionService,
        private StringUtil $stringUtil,
        private UrlService $urlService,
        private UserService $userService,
        private Util $util,
    ) {
    }

    #[\Override]
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {

        $this->util->checkInputParameter('feed', $_GET, false, '/^[0-9a-z]{50}$/i');

        $feed_id    = $this->stringUtil->inputString('feed', '', $_GET);
        $image_only = $this->stringUtil->inputString('image_only', null, $_GET) !== null;

        /** @var array<string, mixed> $user */
        $user = CurrentUser::get()->rawAttributes;

        $feed_row = [];
        if ($feed_id !== null && $feed_id !== '') {
            $feed_row = $this->feedRepository->findById($feed_id);
            if ($feed_row === null || count($feed_row) === 0) {
                $this->htmlService->pageNotFound(Lang::t('Unknown feed identifier'));
            }
            if ($feed_row !== null && $feed_row['user_id'] != $user['id']) {
                $user = $this->userService->buildUser(is_numeric($feed_row['user_id']) ? (int) $feed_row['user_id'] : 0, true);
            }
        } else {
            $image_only = true;
            if (!$this->permissionService->isAGuest()) {
                $user = $this->userService->buildUser(Config::guestId(), true);
            }
        }

        $this->permissionService->checkStatus(AccessLevel::Guest);

        $dbnow = new \DateTimeImmutable()->format('Y-m-d H:i:s');
        $this->urlService->setMakeFullUrl();

        $rss           = new PiwigoFeedCreator();
        $rss->encoding = $this->stringUtil->getPwgCharset();
        $username      = is_string($user['username'] ?? null) ? $user['username'] : '';
        $rss->title    = Config::galleryTitle() . ' (as ' . stripslashes($username) . ')';
        $rss->link     = $this->urlService->getGalleryHomeUrl();

        $news = [];
        if (!$image_only) {
            $last_check = isset($feed_row['last_check']) && is_scalar($feed_row['last_check'])
                ? (string) $feed_row['last_check'] : null;
            $news = $this->notificationService->news($last_check, $dbnow, true, true);

            if (count($news) > 0) {
                $item = new FeedItem();
                $item->title = Lang::t('New on %s', $this->dateService->formatDate($dbnow));
                $item->link  = $this->urlService->getGalleryHomeUrl();
                $item->description = '<ul>';
                foreach ($news as $line) {
                    $item->description .= '<li>' . $line . '</li>';
                }
                $item->description .= '</ul>';
                $item->descriptionHtmlSyndicated = true;
                $item->date   = new \DateTimeImmutable($dbnow);
                $item->author = Config::rssReedAuthor();
                $item->guid   = $dbnow;
                $rss->addItem($item);

                $this->feedRepository->updateLastCheck((string) $feed_id, $dbnow);
            }
        }

        if (($feed_id !== null && $feed_id !== '') && count($news) === 0) {
            $lastCheck = isset($feed_row['last_check']) && is_scalar($feed_row['last_check'])
                ? (string) $feed_row['last_check'] : '';
            if (!isset($feed_row['last_check']) || time() - FeedHelper::datetimeToTs($lastCheck) > 30 * 24 * 3600) {
                $keepAliveDate = new \DateTimeImmutable($dbnow)->modify('+15 days')->format('Y-m-d H:i:s');
                $this->feedRepository->updateLastCheck($feed_id, $keepAliveDate);
            }
        }

        $dates = $this->notificationService->getRecentPostDatesArray(Config::recentPostDates()['RSS']);
        foreach ($dates as $date_detail) {
            if (!is_array($date_detail)) {
                continue;
            }
            $item  = new FeedItem();
            $date  = is_string($date_detail['date_available'] ?? null) ? $date_detail['date_available'] : '';
            $item->title = $this->notificationService->getTitleRecentPostDate($date_detail);
            $item->link  = $this->urlService->makeIndexUrl([
                'chronology_field' => 'posted',
                'chronology_style' => 'monthly',
                'chronology_view'  => 'calendar',
                'chronology_date'  => explode('-', substr($date, 0, 10)),
            ]);
            $item->description = '<a href="' . $this->urlService->makeIndexUrl() . '">' . Config::galleryTitle() . '</a><br> ';
            $item->description .= $this->notificationService->getHtmlDescriptionRecentPostDate($date_detail);
            $item->descriptionHtmlSyndicated = true;
            $item->date   = new \DateTimeImmutable($date);
            $item->author = Config::rssReedAuthor();
            $item->guid   = 'pics-' . $date;
            $rss->addItem($item);
        }

        echo $rss->toRss20Xml();

        return ResponseFactory::create(200);
    }
}
