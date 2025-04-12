<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\functions;
use Piwigo\inc\functions_html;
use Piwigo\inc\functions_notification;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;

define('PHPWG_ROOT_PATH', './');
require_once __DIR__ . '/inc/common.php';

// +-----------------------------------------------------------------------+
// |                            initialization                             |
// +-----------------------------------------------------------------------+

functions::check_input_parameter('feed', $_GET, false, '/^[0-9a-z]{50}$/i');

$feed_id = $_GET['feed'] ?? '';
$image_only = isset($_GET['image_only']);

// echo '<pre>'.\Piwigo\inc\functions_session::generate_key(50).'</pre>';
if (! empty($feed_id)) {
    $query = <<<SQL
        SELECT user_id, last_check
        FROM user_feed
        WHERE id = '{$feed_id}';
        SQL;
    $feed_row = functions_mysqli::pwg_db_fetch_assoc(functions_mysqli::pwg_query($query));

    if (empty($feed_row)) {
        functions_html::page_not_found(functions::l10n('Unknown feed identifier'));
    }

    if ($feed_row['user_id'] != $user['id']) { // new user
        $user = functions_user::build_user($feed_row['user_id'], true);
    }

} else {
    $image_only = true;

    if (! functions_user::is_a_guest()) { // auto session was created - so switch to guest
        $user = functions_user::build_user($conf->guest_id, true);
    }
}

// Check the status now after the user has been loaded
functions_user::check_status(ACCESS_GUEST);

[$dbnow] = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query('SELECT NOW();'));

functions_url::set_make_full_url();

$rss = new UniversalFeedCreator();
$rss->title = $conf->gallery_title;
$rss->title .= ' (as ' . stripslashes($user['username']) . ')';

$rss->link = functions_url::get_gallery_home_url();

// +-----------------------------------------------------------------------+
// |                            Feed creation                              |
// +-----------------------------------------------------------------------+

$news = [];
if (! $image_only) {
    $news = functions_notification::news($feed_row['last_check'], $dbnow, true, true);

    if ($news !== []) {
        $item = new FeedItem();
        $item->title = functions::l10n('New on %s', functions::format_date($dbnow));
        $item->link = functions_url::get_gallery_home_url();

        // content creation
        $item->description = '<ul>';

        foreach ($news as $line) {
            $item->description .= '<li>' . $line . '</li>';
        }

        $item->description .= '</ul>';
        $item->descriptionHtmlSyndicated = true;

        $item->date = functions::ts_to_iso8601(functions::datetime_to_ts($dbnow));
        $item->author = $conf->rss_feed_author;
        $item->guid = sprintf('%s', $dbnow);

        $rss->addItem($item);

        $query = <<<SQL
            UPDATE user_feed
            SET last_check = '{$dbnow}'
            WHERE id = '{$feed_id}';
            SQL;
        functions_mysqli::pwg_query($query);
    }
}

// update the last check from time to time to avoid deletion by maintenance tasks
if (! empty($feed_id) && $news === [] && (! isset($feed_row['last_check']) || time() - functions::datetime_to_ts($feed_row['last_check']) > 30 * 24 * 3600)) {
    $last_check_expr = functions_mysqli::pwg_db_get_recent_period_expression(-15, $dbnow);
    $query = <<<SQL
            UPDATE user_feed
            SET last_check = {$last_check_expr}
            WHERE id = '{$feed_id}';
            SQL;
    functions_mysqli::pwg_query($query);
}

$dates = functions_notification::get_recent_post_dates_array($conf->recent_post_dates['RSS']);

foreach ($dates as $date_detail) { // for each recent post date we create a feed item
    $item = new FeedItem();
    $date = $date_detail['date_available'];
    $item->title = functions_notification::get_title_recent_post_date($date_detail);
    $item->link = functions_url::make_index_url(
        [
            'chronology_field' => 'posted',
            'chronology_style' => 'monthly',
            'chronology_view' => 'calendar',
            'chronology_date' => explode('-', substr($date, 0, 10)),
        ]
    );

    $item->description .= '<a href="' . functions_url::make_index_url() . '">' . $conf->gallery_title . '</a><br> ';
    $item->description .= functions_notification::get_html_description_recent_post_date($date_detail);

    $item->descriptionHtmlSyndicated = true;

    $item->date = functions::ts_to_iso8601(functions::datetime_to_ts($date));
    $item->author = $conf->rss_feed_author;
    $item->guid = 'pics-' . $date;

    $rss->addItem($item);
}

$fileName = './' . $conf->data_location . 'tmp';
functions::mkgetdir($fileName); // just in case
$fileName .= '/feed.xml';
// send XML feed
echo $rss->saveFeed('RSS2.0', $fileName, true);
