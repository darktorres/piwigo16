<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

define('PHPWG_ROOT_PATH', './');
include_once PHPWG_ROOT_PATH . 'include/common.inc.php';
include_once PHPWG_ROOT_PATH . 'include/functions_notification.inc.php';

// Bootstrap globals, set by include/common.inc.php.
global $conf, $user;

// +-----------------------------------------------------------------------+
// |                               functions                               |
// +-----------------------------------------------------------------------+

/**
 * creates a Unix timestamp (number of seconds since 1970-01-01 00:00:00
 * GMT) from a MySQL datetime format (2005-07-14 23:01:37)
 *
 * @param string $datetime mysql datetime format
 * @return int timestamp
 */
function datetime_to_ts($datetime): int|false
{
    return strtotime($datetime);
}

/**
 * creates an ISO 8601 format date (2003-01-20T18:05:41+04:00) from Unix
 * timestamp (number of seconds since 1970-01-01 00:00:00 GMT)
 *
 * function copied from Dotclear project http://dotclear.net
 *
 * @param int $ts timestamp
 * @return string ISO 8601 date format
 */
function ts_to_iso8601($ts): string
{
    $tz = date('O', $ts);
    $tz = substr($tz, 0, -2) . ':' . substr($tz, -2);
    return date('Y-m-d\\TH:i:s', $ts) . $tz;
}

/**
 * Builds a well-formed RSS 2.0 feed from channel metadata and items. Covers
 * exactly this file's own usage (no image/language/copyright/enclosure/etc.
 * -- feed.php never sets any of those), not a general-purpose feed library.
 *
 * @param array<string, mixed> $channel keys: title, link, encoding
 * @param array<int, array<string, mixed>> $items each: title, link, description, html (bool -- wrap
 *        description in CDATA instead of escaping it), date (ISO 8601
 *        string), author, guid
 */
function pwg_generate_rss2_feed(array $channel, array $items): string
{
    $feed = '<?xml version="1.0" encoding="' . $channel['encoding'] . '"?>' . "\n";
    $feed .= "<rss version=\"2.0\">\n";
    $feed .= "  <channel>\n";
    $feed .= '    <title>' . htmlspecialchars((string) $channel['title']) . "</title>\n";
    $feed .= '    <link>' . htmlspecialchars((string) $channel['link']) . "</link>\n";
    $feed .= "    <description></description>\n";
    $feed .= '    <lastBuildDate>' . new DateTimeImmutable()->format(DATE_RFC2822) . "</lastBuildDate>\n";

    foreach ($items as $item) {
        $feed .= "    <item>\n";
        $feed .= '      <title>' . htmlspecialchars(strip_tags((string) $item['title'])) . "</title>\n";
        $feed .= '      <link>' . htmlspecialchars((string) $item['link']) . "</link>\n";
        $feed .= '      <description>' . (! empty($item['html']) ? '<![CDATA[' . $item['description'] . ']]>' : htmlspecialchars((string) $item['description'])) . "</description>\n";
        if (! empty($item['author'])) {
            $feed .= '      <author>' . htmlspecialchars((string) $item['author']) . "</author>\n";
        }
        if (! empty($item['date'])) {
            $feed .= '      <pubDate>' . new DateTimeImmutable($item['date'])->format(DATE_RFC2822) . "</pubDate>\n";
        }
        $feed .= '      <guid isPermaLink="false">' . htmlspecialchars($item['guid'] !== '' ? $item['guid'] : $item['link']) . "</guid>\n";
        $feed .= "    </item>\n";
    }

    $feed .= "  </channel>\n";
    $feed .= "</rss>\n";
    return $feed;
}

// +-----------------------------------------------------------------------+
// |                            initialization                             |
// +-----------------------------------------------------------------------+

check_input_parameter('feed', $_GET, false, '/^[0-9a-z]{50}$/i');

$feed_id = $_GET['feed'] ?? '';
$image_only = isset($_GET['image_only']);
// Only read below when $image_only is false, which implies $feed_id was
// non-empty and the branch below already populated it.
$feed_row = [];

// echo '<pre>'.generate_key(50).'</pre>';
if (! empty($feed_id)) {
    $query = '
SELECT user_id,
       last_check
  FROM ' . USER_FEED_TABLE . '
  WHERE id = \'' . $feed_id . '\'
;';
    $feed_row = pwg_db_fetch_assoc(pwg_query($query));
    if (empty($feed_row)) {
        page_not_found(l10n('Unknown feed identifier'));
    }
    if ($feed_row['user_id'] != $user['id']) { // new user
        $user = build_user($feed_row['user_id'], true);
    }
} else {
    $image_only = true;
    if (! is_a_guest()) {// auto session was created - so switch to guest
        $user = build_user($conf['guest_id'], true);
    }
}

// Check the status now after the user has been loaded
check_status(ACCESS_GUEST);

$row = pwg_db_fetch_row(pwg_query('SELECT NOW();'));
assert($row !== null);
[$dbnow] = $row;

set_make_full_url();

$rss_encoding = get_pwg_charset();
$rss_title = $conf['gallery_title'] . ' (as ' . stripslashes((string) $user['username']) . ')';
$rss_link = get_gallery_home_url();
$rss_items = [];

// +-----------------------------------------------------------------------+
// |                            Feed creation                              |
// +-----------------------------------------------------------------------+

$news = [];
if (! $image_only) {
    $news = news($feed_row['last_check'], $dbnow, true, true);

    if (count($news) > 0) {
        // content creation
        $description = '<ul>';
        foreach ($news as $line) {
            $description .= '<li>' . $line . '</li>';
        }
        $description .= '</ul>';

        $dbnow_ts = datetime_to_ts($dbnow);
        // $dbnow is a NOW() value straight from the database, always a
        // valid datetime string
        assert($dbnow_ts !== false);

        $rss_items[] = [
            'title' => l10n('New on %s', format_date($dbnow)),
            'link' => get_gallery_home_url(),
            'description' => $description,
            'html' => true,
            'date' => ts_to_iso8601($dbnow_ts),
            'author' => $conf['rss_feed_author'],
            'guid' => sprintf('%s', $dbnow),
        ];

        $query = '
UPDATE ' . USER_FEED_TABLE . '
  SET last_check = \'' . $dbnow . '\'
  WHERE id = \'' . $feed_id . '\'
;';
        pwg_query($query);
    }
}

if (! empty($feed_id) and empty($news)) {// update the last check from time to time to avoid deletion by maintenance tasks
    if (! isset($feed_row['last_check'])
      or time() - datetime_to_ts($feed_row['last_check']) > 30 * 24 * 3600) {
        $query = '
UPDATE ' . USER_FEED_TABLE . '
  SET last_check = ' . pwg_db_get_recent_period_expression(-15, $dbnow) . '
  WHERE id = \'' . $feed_id . '\'
;';
        pwg_query($query);
    }
}

$dates = get_recent_post_dates_array($conf['recent_post_dates']['RSS']);

foreach ($dates as $date_detail) { // for each recent post date we create a feed item
    $date = $date_detail['date_available'];
    $link = make_index_url(
        [
            'chronology_field' => 'posted',
            'chronology_style' => 'monthly',
            'chronology_view' => 'calendar',
            'chronology_date' => explode('-', substr((string) $date, 0, 10)),
        ]
    );

    $description = '<a href="' . make_index_url() . '">' . $conf['gallery_title'] . '</a><br> ';
    $description .= get_html_description_recent_post_date($date_detail);

    $date_ts = datetime_to_ts($date);
    // $date is a date_available value straight from the database,
    // always a valid datetime string
    assert($date_ts !== false);

    $rss_items[] = [
        'title' => get_title_recent_post_date($date_detail),
        'link' => $link,
        'description' => $description,
        'html' => true,
        'date' => ts_to_iso8601($date_ts),
        'author' => $conf['rss_feed_author'],
        'guid' => sprintf('%s', 'pics-' . $date),
    ];
}

$feed_content = pwg_generate_rss2_feed(
    [
        'title' => $rss_title,
        'link' => $rss_link,
        'encoding' => $rss_encoding,
    ],
    $rss_items
);

$fileName = PHPWG_ROOT_PATH . $conf['data_location'] . 'tmp';
mkgetdir($fileName); // just in case
$fileName .= '/feed.xml';
file_put_contents($fileName, $feed_content);

// send XML feed
header('Content-Type: application/rss+xml; charset=' . $rss_encoding . '; filename=' . basename($fileName));
header('Content-Disposition: inline; filename=' . basename($fileName));
echo $feed_content;
