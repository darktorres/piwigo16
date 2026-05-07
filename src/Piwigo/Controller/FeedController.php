<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Config\Config;
use Piwigo\Core\AccessLevel;
use Piwigo\Core\DateService;
use Piwigo\Core\Lang;
use Piwigo\Core\ServiceLocator;
use Piwigo\Core\StringUtil;
use Piwigo\Core\Util;
use Piwigo\Feed\FeedHelper;
use Piwigo\Feed\FeedRepository;
use Piwigo\Feed\PiwigoFeedCreator;
use Piwigo\Html\HtmlService;
use Piwigo\Http\ResponseFactory;
use Piwigo\Notification\NotificationService;
use Piwigo\Url\UrlService;
use Piwigo\Users\PermissionService;
use Piwigo\Users\UserService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Generates the RSS 2.0 feed and sends it directly as XML output.
 * Corresponds to the former feed.php entry-point.
 */
final class FeedController implements ControllerInterface
{
    public function __invoke(ServerRequestInterface $request, array $args = []): ResponseInterface
    {

        ServiceLocator::get(Util::class)->checkInputParameter('feed', $_GET, false, '/^[0-9a-z]{50}$/i');

        $feed_id    = StringUtil::get()->inputString('feed', '', $_GET);
        $image_only = StringUtil::get()->inputString('image_only', null, $_GET) !== null;

        /** @var array<string, mixed> $user */
        $user = &$GLOBALS['user'];

        $feed_row = [];
        if (!empty($feed_id)) {
            $feed_row = ServiceLocator::get(FeedRepository::class)->findById((string) $feed_id);
            if (empty($feed_row)) {
                ServiceLocator::get(HtmlService::class)->pageNotFound(Lang::t('Unknown feed identifier'));
            }
            if ($feed_row !== null && $feed_row['user_id'] != $user['id']) {
                $user = UserService::get()->buildUser(is_numeric($feed_row['user_id']) ? (int) $feed_row['user_id'] : 0, true);
            }
        } else {
            $image_only = true;
            if (!PermissionService::get()->isAGuest()) {
                $user = UserService::get()->buildUser(Config::guestId(), true);
            }
        }

        PermissionService::get()->checkStatus(AccessLevel::Guest);

        $dbnow = new \DateTimeImmutable()->format('Y-m-d H:i:s');
        UrlService::get()->setMakeFullUrl();

        $rss           = new PiwigoFeedCreator();
        $rss->encoding = ServiceLocator::get(StringUtil::class)->getPwgCharset();
        $rss->title    = Config::galleryTitle();
        $username      = is_scalar($user['username'] ?? null) ? (string) $user['username'] : '';
        $rss->title   .= ' (as ' . stripslashes($username) . ')';
        $rss->link     = UrlService::get()->getGalleryHomeUrl();

        $news = [];
        if (!$image_only) {
            $last_check = isset($feed_row['last_check']) && is_scalar($feed_row['last_check'])
                ? (string) $feed_row['last_check'] : null;
            $news = ServiceLocator::get(NotificationService::class)->news($last_check, $dbnow, true, true);

            if (count($news) > 0) {
                $item = new \FeedItem();
                $item->title = Lang::t('New on %s', ServiceLocator::get(DateService::class)->formatDate($dbnow));
                $item->link  = UrlService::get()->getGalleryHomeUrl();
                $item->description = '<ul>';
                foreach ($news as $line) {
                    $item->description .= '<li>' . $line . '</li>';
                }
                $item->description .= '</ul>';
                $item->descriptionHtmlSyndicated = true;
                $item->date   = FeedHelper::tsToIso8601(FeedHelper::datetimeToTs($dbnow));
                $item->author = Config::rssReedAuthor();
                $item->guid   = sprintf('%s', $dbnow);
                $rss->addItem($item);

                ServiceLocator::get(FeedRepository::class)->updateLastCheck((string) $feed_id, $dbnow);
            }
        }

        if (!empty($feed_id) && empty($news)) {
            $lastCheck = isset($feed_row['last_check']) && is_scalar($feed_row['last_check'])
                ? (string) $feed_row['last_check'] : '';
            if (!isset($feed_row['last_check']) || time() - FeedHelper::datetimeToTs($lastCheck) > 30 * 24 * 3600) {
                $keepAliveDate = new \DateTimeImmutable($dbnow)->modify('+15 days')->format('Y-m-d H:i:s');
                ServiceLocator::get(FeedRepository::class)->updateLastCheck((string) $feed_id, $keepAliveDate);
            }
        }

        $dates = ServiceLocator::get(NotificationService::class)->getRecentPostDatesArray(Config::recentPostDates()['RSS']);
        foreach ($dates as $date_detail) {
            if (!is_array($date_detail)) {
                continue;
            }
            $item  = new \FeedItem();
            $date  = is_scalar($date_detail['date_available'] ?? null) ? (string) $date_detail['date_available'] : '';
            $item->title = ServiceLocator::get(NotificationService::class)->getTitleRecentPostDate($date_detail);
            $item->link  = UrlService::get()->makeIndexUrl([
                'chronology_field' => 'posted',
                'chronology_style' => 'monthly',
                'chronology_view'  => 'calendar',
                'chronology_date'  => explode('-', substr($date, 0, 10)),
            ]);
            $item->description = '<a href="' . UrlService::get()->makeIndexUrl() . '">' . Config::galleryTitle() . '</a><br> ';
            $item->description .= ServiceLocator::get(NotificationService::class)->getHtmlDescriptionRecentPostDate($date_detail);
            $item->descriptionHtmlSyndicated = true;
            $item->date   = FeedHelper::tsToIso8601(FeedHelper::datetimeToTs($date));
            $item->author = Config::rssReedAuthor();
            $item->guid   = sprintf('%s', 'pics-' . $date);
            $rss->addItem($item);
        }

        $fileName = PHPWG_ROOT_PATH . Config::dataLocation() . 'tmp';
        Util::mkgetdir($fileName);
        $fileName .= '/feed.xml';
        $feedOutput = $rss->saveFeed('RSS2.0', $fileName, true);
        echo is_string($feedOutput) ? $feedOutput : '';

        return ResponseFactory::create(200);
    }
}
