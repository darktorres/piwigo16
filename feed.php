<?php

declare(strict_types=1);

global $template, $user, $page, $persistent_cache, $lang;
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

define('PHPWG_ROOT_PATH', './');
require_once(PHPWG_ROOT_PATH.'include/common.inc.php');
\Piwigo\Core\Kernel::boot();
require_once(PHPWG_ROOT_PATH.'include/functions_notification.inc.php');

// +-----------------------------------------------------------------------+
// |                               functions                               |
// +-----------------------------------------------------------------------+

/**
 * creates a Unix timestamp (number of seconds since 1970-01-01 00:00:00
 * GMT) from a MySQL datetime format (2005-07-14 23:01:37)
 *
 *  string  mysql datetime format
 * @return int timestamp
 */
function datetime_to_ts(string $datetime): int
{
    return (int) strtotime($datetime);
}

/**
 * creates an ISO 8601 format date (2003-01-20T18:05:41+04:00) from Unix
 * timestamp (number of seconds since 1970-01-01 00:00:00 GMT)
 *
 * function copied from Dotclear project http://dotclear.net
 *
 *  int
 * @return string ISO 8601 date format
 */
function ts_to_iso8601(int $ts): string
{
    $tz = date('O', $ts);
    $tz = substr($tz, 0, -2).':'.substr($tz, -2);
    return date('Y-m-d\\TH:i:s', $ts).$tz;
}

// +-----------------------------------------------------------------------+
// |                            initialization                             |
// +-----------------------------------------------------------------------+

check_input_parameter('feed', $_GET, false, '/^[0-9a-z]{50}$/i');

$feed_id = input_string('feed', '', $_GET);
$image_only = input_string('image_only', null, $_GET) !== null;

// echo '<pre>'.generate_key(50).'</pre>';
$feed_row = [];
if (!empty($feed_id)) {
    $feed_row = \Piwigo\Core\ServiceLocator::get(\Piwigo\Feed\FeedRepository::class)
        ->findById((string) $feed_id);
    if (empty($feed_row)) {
        page_not_found(l10n('Unknown feed identifier'));
    }
    if ($feed_row !== null && $feed_row['user_id'] != $user['id']) { // new user
        $user = build_user(is_numeric($feed_row['user_id']) ? (int)$feed_row['user_id'] : 0, true);
    }
} else {
    $image_only = true;
    if (!is_a_guest()) {// auto session was created - so switch to guest
        $user = build_user(\Piwigo\Config\Config::guestId(), true);
    }
}

// Check the status now after the user has been loaded
check_status(ACCESS_GUEST);

$dbnow = (new \DateTimeImmutable())->format('Y-m-d H:i:s');

set_make_full_url();

// UniversalFeedCreator::$encoding is protected; subclass widens it.
class PiwigoFeedCreator extends UniversalFeedCreator
{
    /** @var string */
    public $encoding = 'UTF-8';
}
$rss = new PiwigoFeedCreator();
$rss->encoding = get_pwg_charset();
$rss->title = \Piwigo\Config\Config::galleryTitle();
$rss->title .= ' (as '.stripslashes($user['username']).')';

$rss->link = get_gallery_home_url();

// +-----------------------------------------------------------------------+
// |                            Feed creation                              |
// +-----------------------------------------------------------------------+

$news = array();
if (!$image_only) {
    $last_check = isset($feed_row['last_check']) ? (is_scalar($feed_row['last_check']) ? (string)$feed_row['last_check'] : null) : null;
    $news = news($last_check, $dbnow, true, true);

    if (count($news) > 0) {
        $item = new FeedItem();
        $item->title = l10n('New on %s', format_date($dbnow));
        $item->link = get_gallery_home_url();

        // content creation
        $item->description = '<ul>';
        foreach ($news as $line) {
            $item->description .= '<li>'.$line.'</li>';
        }
        $item->description .= '</ul>';
        $item->descriptionHtmlSyndicated = true;

        $item->date = ts_to_iso8601(datetime_to_ts($dbnow));
        $item->author = \Piwigo\Config\Config::rssReedAuthor();
        $item->guid = sprintf('%s', $dbnow);
        ;

        $rss->addItem($item);

        \Piwigo\Core\ServiceLocator::get(\Piwigo\Feed\FeedRepository::class)
            ->updateLastCheck((string) $feed_id, $dbnow);
    }
}

if (!empty($feed_id) and empty($news)) {// update the last check from time to time to avoid deletion by maintenance tasks
    if (!isset($feed_row['last_check'])
      or time() - datetime_to_ts(is_scalar($feed_row['last_check']) ? (string)$feed_row['last_check'] : '') > 30 * 24 * 3600) {
        $keepAliveDate = (new \DateTimeImmutable($dbnow))->modify('+15 days')->format('Y-m-d H:i:s');
        \Piwigo\Core\ServiceLocator::get(\Piwigo\Feed\FeedRepository::class)
            ->updateLastCheck((string) $feed_id, $keepAliveDate);
    }
}

$dates = get_recent_post_dates_array(\Piwigo\Config\Config::recentPostDates()['RSS']);

foreach ($dates as $date_detail) { // for each recent post date we create a feed item
    if (!is_array($date_detail)) {
        continue;
    }
    $item = new FeedItem();
    $date = is_scalar($date_detail['date_available']) ? (string) $date_detail['date_available'] : '';
    $item->title = get_title_recent_post_date($date_detail);
    $item->link = make_index_url(
        array(
            'chronology_field' => 'posted',
            'chronology_style' => 'monthly',
            'chronology_view' => 'calendar',
            'chronology_date' => explode('-', substr($date, 0, 10)),
          )
    );

    $item->description .=
      '<a href="'.make_index_url().'">'.\Piwigo\Config\Config::galleryTitle().'</a><br> ';

    $item->description .= get_html_description_recent_post_date($date_detail);

    $item->descriptionHtmlSyndicated = true;

    $item->date = ts_to_iso8601(datetime_to_ts($date));
    $item->author = \Piwigo\Config\Config::rssReedAuthor();
    $item->guid = sprintf('%s', 'pics-'.$date);
    ;

    $rss->addItem($item);
}

$fileName = PHPWG_ROOT_PATH.\Piwigo\Config\Config::dataLocation().'tmp';
mkgetdir($fileName); // just in case
$fileName .= '/feed.xml';
// send XML feed
echo $rss->saveFeed('RSS2.0', $fileName, true);
