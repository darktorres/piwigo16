<?php

declare(strict_types=1);

namespace Piwigo\Controller;

use Piwigo\Config\Config;
use Piwigo\Core\ServiceLocator;
use Piwigo\Feed\FeedRepository;
use Piwigo\Http\ResponseFactory;
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
        require_once PHPWG_ROOT_PATH . 'include/feed_functions.php';

        check_input_parameter('feed', $_GET, false, '/^[0-9a-z]{50}$/i');

        $feed_id    = input_string('feed', '', $_GET);
        $image_only = input_string('image_only', null, $_GET) !== null;

        /** @var array<string, mixed> $user */
        $user = &$GLOBALS['user'];

        $feed_row = [];
        if (!empty($feed_id)) {
            $feed_row = ServiceLocator::get(FeedRepository::class)->findById((string) $feed_id);
            if (empty($feed_row)) {
                page_not_found(l10n('Unknown feed identifier'));
            }
            if ($feed_row !== null && $feed_row['user_id'] != $user['id']) {
                $user = build_user(is_numeric($feed_row['user_id']) ? (int) $feed_row['user_id'] : 0, true);
            }
        } else {
            $image_only = true;
            if (!is_a_guest()) {
                $user = build_user(Config::guestId(), true);
            }
        }

        check_status(ACCESS_GUEST);

        $dbnow = new \DateTimeImmutable()->format('Y-m-d H:i:s');
        set_make_full_url();

        $rss           = new \PiwigoFeedCreator();
        $rss->encoding = get_pwg_charset();
        $rss->title    = Config::galleryTitle();
        $username      = is_scalar($user['username'] ?? null) ? (string) $user['username'] : '';
        $rss->title   .= ' (as ' . stripslashes($username) . ')';
        $rss->link     = get_gallery_home_url();

        $news = [];
        if (!$image_only) {
            $last_check = isset($feed_row['last_check']) && is_scalar($feed_row['last_check'])
                ? (string) $feed_row['last_check'] : null;
            $news = news($last_check, $dbnow, true, true);

            if (count($news) > 0) {
                $item = new \FeedItem();
                $item->title = l10n('New on %s', format_date($dbnow));
                $item->link  = get_gallery_home_url();
                $item->description = '<ul>';
                foreach ($news as $line) {
                    $item->description .= '<li>' . $line . '</li>';
                }
                $item->description .= '</ul>';
                $item->descriptionHtmlSyndicated = true;
                $item->date   = ts_to_iso8601(datetime_to_ts($dbnow));
                $item->author = Config::rssReedAuthor();
                $item->guid   = sprintf('%s', $dbnow);
                $rss->addItem($item);

                ServiceLocator::get(FeedRepository::class)->updateLastCheck((string) $feed_id, $dbnow);
            }
        }

        if (!empty($feed_id) && empty($news)) {
            $lastCheck = isset($feed_row['last_check']) && is_scalar($feed_row['last_check'])
                ? (string) $feed_row['last_check'] : '';
            if (!isset($feed_row['last_check']) || time() - datetime_to_ts($lastCheck) > 30 * 24 * 3600) {
                $keepAliveDate = new \DateTimeImmutable($dbnow)->modify('+15 days')->format('Y-m-d H:i:s');
                ServiceLocator::get(FeedRepository::class)->updateLastCheck((string) $feed_id, $keepAliveDate);
            }
        }

        $dates = get_recent_post_dates_array(Config::recentPostDates()['RSS']);
        foreach ($dates as $date_detail) {
            if (!is_array($date_detail)) {
                continue;
            }
            $item  = new \FeedItem();
            $date  = is_scalar($date_detail['date_available'] ?? null) ? (string) $date_detail['date_available'] : '';
            $item->title = get_title_recent_post_date($date_detail);
            $item->link  = make_index_url([
                'chronology_field' => 'posted',
                'chronology_style' => 'monthly',
                'chronology_view'  => 'calendar',
                'chronology_date'  => explode('-', substr($date, 0, 10)),
            ]);
            $item->description = '<a href="' . make_index_url() . '">' . Config::galleryTitle() . '</a><br> ';
            $item->description .= get_html_description_recent_post_date($date_detail);
            $item->descriptionHtmlSyndicated = true;
            $item->date   = ts_to_iso8601(datetime_to_ts($date));
            $item->author = Config::rssReedAuthor();
            $item->guid   = sprintf('%s', 'pics-' . $date);
            $rss->addItem($item);
        }

        $fileName = PHPWG_ROOT_PATH . Config::dataLocation() . 'tmp';
        mkgetdir($fileName);
        $fileName .= '/feed.xml';
        echo $rss->saveFeed('RSS2.0', $fileName, true);

        return ResponseFactory::create(200);
    }
}
