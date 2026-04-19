<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\admin\inc\c13y_internal;
use Piwigo\admin\inc\check_integrity;
use Piwigo\admin\inc\functions_admin;
use Piwigo\admin\inc\tabsheet;
use Piwigo\inc\functions;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;

if (! defined('PHPWG_ROOT_PATH')) {
    exit('Hacking attempt!');
}

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+

functions_user::check_status(ACCESS_ADMINISTRATOR);

// +-----------------------------------------------------------------------+
// | tabs                                                                  |
// +-----------------------------------------------------------------------+

if (isset($_GET['action']) &&
    $_GET['action'] == 'hide_newsletter_subscription'
) {
    functions_user::userprefs_update_param('show_newsletter_subscription', 'false');
    exit();
}

$my_base_url = functions_url::get_root_url() . 'admin.php?page=';

$tabsheet = new tabsheet();
$tabsheet->set_id('admin_home');
$tabsheet->select('');
$tabsheet->assign();

// +-----------------------------------------------------------------------+
// |                                actions                                |
// +-----------------------------------------------------------------------+

if (isset($page['nb_pending_comments'])) {
    $message = functions::l10n('User comments') . ' <i class="icon-chat"></i> ';
    $message .= '<a href="' . $link_start . 'comments">';
    $message .= functions::l10n('%d waiting for validation', $page['nb_pending_comments']);
    $message .= ' <i class="icon-right"></i></a>';

    $page['messages'][] = $message;
}

// any orphan photo?
$nb_orphans = $page['nb_orphans']; // already calculated in admin.php

if ($page['nb_photos_total'] >= 100000) { // but has not been calculated on a big gallery, so force it now
    $nb_orphans = functions_admin::count_orphans();
}

if ($nb_orphans > 0) {
    $orphans_url = './admin.php?page=batch_manager&amp;filter=prefilter-no_album';

    $message = '<a href="' . $orphans_url . '"><i class="icon-heart-broken"></i>';
    $message .= functions::l10n('Orphans') . '</a>';
    $message .= '<span class="adminMenubarCounter">' . $nb_orphans . '</span>';

    $page['warnings'][] = $message;
}

// locked album ?
$locked_album = functions_admin::get_nb_locked_albums();

if ($locked_album > 0) {
    $locked_album_url = './admin.php?page=cat_options&section=visible';

    $message = '<a href="' . $locked_album_url . '"><i class="icon-cone"></i>';
    $message .= functions::l10n('Locked album') . '</a>';
    $message .= '<span class="adminMenubarCounter">' . $locked_album . '</span>';

    $page['warnings'][] = $message;
}

// +-----------------------------------------------------------------------+
// |                             template init                             |
// +-----------------------------------------------------------------------+

$template->set_filenames([
    'intro' => 'intro.tpl',
]);

if ($conf->show_newsletter_subscription &&
    functions_user::userprefs_get_param('show_newsletter_subscription', true)
) {
    $template->assign(
        [
            'EMAIL' => $user['email'],
            'SUBSCRIBE_BASE_URL' => functions_admin::get_newsletter_subscribe_base_url($user['language']),
        ]
    );
}

$nb_photos = $page['nb_photos_total'];

$nb_categories = functions_admin::get_nb_categories();

$query = <<<SQL
    SELECT COUNT(*) AS "COUNT(*)"
    FROM tags;
    SQL;
[$nb_tags] = $conf->sql_backend::pwg_db_fetch_row($conf->sql_backend::pwg_query($query));

$query = <<<SQL
    SELECT COUNT(*) AS "COUNT(*)"
    FROM image_tag;
    SQL;
[$nb_image_tag] = $conf->sql_backend::pwg_db_fetch_row($conf->sql_backend::pwg_query($query));

$query = <<<SQL
    SELECT COUNT(*) AS "COUNT(*)"
    FROM users;
    SQL;
[$nb_users] = $conf->sql_backend::pwg_db_fetch_row($conf->sql_backend::pwg_query($query));

$query = <<<SQL
    SELECT COUNT(*) AS "COUNT(*)"
    FROM user_groups;
    SQL;
[$nb_groups] = $conf->sql_backend::pwg_db_fetch_row($conf->sql_backend::pwg_query($query));

$query = <<<SQL
    SELECT COUNT(*) AS "COUNT(*)"
    FROM rate;
    SQL;
[$nb_rates] = $conf->sql_backend::pwg_db_fetch_row($conf->sql_backend::pwg_query($query));

$query = <<<SQL
    SELECT SUM(nb_pages)
    FROM history_summary
    WHERE month IS NULL;
    SQL;
[$nb_views] = $conf->sql_backend::pwg_db_fetch_row($conf->sql_backend::pwg_query($query));

$disk_usage = functions_admin::get_images_disk_usage();

$du_decimals = 1;
$du_gb = $disk_usage / (1024 * 1024);

if ($du_gb > 100) {
    $du_decimals = 0;
}

$template->assign(
    [
        'NB_PHOTOS' => (int) $nb_photos,
        'NB_ALBUMS' => (int) $nb_categories,
        'NB_TAGS' => (int) $nb_tags,
        'NB_IMAGE_TAG' => (int) $nb_image_tag,
        'NB_USERS' => (int) $nb_users,
        'NB_GROUPS' => (int) $nb_groups,
        'NB_RATES' => (int) $nb_rates,
        'NB_VIEWS' => functions_admin::number_format_human_readable(isset($nb_views) ? (float) $nb_views : null),
        'NB_PLUGINS' => count($pwg_loaded_plugins),
        'STORAGE_USED' => str_replace(' ', '&nbsp;', functions::l10n('%sGB', number_format($du_gb, $du_decimals))),
        'U_QUICK_SYNC' => './admin.php?page=site_update&amp;site=1&amp;quick_sync=1&amp;pwg_token=' . functions::get_pwg_token(),
        'CHECK_FOR_UPDATES' => $conf->dashboard_check_for_updates,
    ]
);

if ($conf->activate_comments) {
    $query = <<<SQL
        SELECT COUNT(*) AS "COUNT(*)"
        FROM comments;
        SQL;
    [$nb_comments] = $conf->sql_backend::pwg_db_fetch_row($conf->sql_backend::pwg_query($query));
    $template->assign('NB_COMMENTS', $nb_comments);
} else {
    $template->assign('NB_COMMENTS', 0);
}

if ($conf->show_piwigo_latest_news) {
    $latest_news = functions_admin::get_piwigo_news();

    if (isset($latest_news['id']) &&
        $latest_news['posted_on'] > time() - 60 * 60 * 24 * 30
    ) {
        $page['messages'][] = sprintf(
            '%s <a href="%s" title="%s" target="_blank"><i class="icon-bell"></i> %s</a>',
            functions::l10n('Latest Piwigo news'),
            $latest_news['url'],
            functions::time_since($latest_news['posted_on'], 'year') . ' (' . $latest_news['posted'] . ')',
            $latest_news['subject']
        );
    }
}

functions_plugins::trigger_notify('loc_end_intro');

// +-----------------------------------------------------------------------+
// |                           get activity data                           |
// +-----------------------------------------------------------------------+

$nb_weeks = $conf->dashboard_activity_nb_weeks;

//Count mondays
$mondays = 0;
//Get mondays number for the chart legend
$week_number = [];
//Array for sorting days in circle size
$temp_data = [];

$activity_last_weeks = [];
$date = new DateTime();

//Get data from $nb_weeks last weeks
while ($mondays < $nb_weeks) {
    if ($date->format('D') === 'Mon') {
        $week_number[] = $date->format('W');
        ++$mondays;
    }

    $date->sub(new DateInterval('P1D'));
}

$week_number = array_reverse($week_number);
$date_string = $date->format('Y-m-d');

$_cached_activity = isset($conf->cache_activity_last_weeks)
    ? json_decode($conf->cache_activity_last_weeks, true)
    : null;

if ($_cached_activity === null ||
    $_cached_activity['calculated_on'] < strtotime('5 minutes ago')
) {
    $start_time = functions::get_moment();

    if ($conf->dblayer === 'mysqli') {
        $date_format_function = <<<SQL
            DATE_FORMAT(occurred_on, '%Y-%m-%d')
            SQL;
    }

    if ($conf->dblayer === 'pgsql') {
        $date_format_function = <<<SQL
            TO_CHAR(occurred_on, 'YYYY-MM-DD')
            SQL;
    }

    $query = <<<SQL
        SELECT {$date_format_function} AS activity_day, object, action, COUNT(*) AS activity_counter
        FROM activity
        WHERE occurred_on >= '{$date_string}'
        GROUP BY activity_day, object, action;
        SQL;
    $activity_actions = $conf->sql_backend::query2array($query);

    foreach ($activity_actions as $action) {
        // set the time to 12:00 (midday) so that it doesn't goes to previous/next day due to timezone offset
        $day_date = new DateTime($action['activity_day'] . ' 12:00:00');

        $week = 0;

        for ($i = 0; $i < $nb_weeks; $i++) {
            if ($week_number[$i] == $day_date->format('W')) {
                $week = $i;
            }
        }

        $day_nb = $day_date->format('N');

        $activity_last_weeks[$week][$day_nb]['details'][ucfirst($action['object'])][ucfirst($action['action'])] = $action['activity_counter'];
        $activity_last_weeks[$week][$day_nb]['number'] ??= 0;
        $activity_last_weeks[$week][$day_nb]['number'] += $action['activity_counter'];
        $activity_last_weeks[$week][$day_nb]['date'] = functions::format_date($day_date->getTimestamp());
    }

    $logger->debug('[admin/intro::' . __LINE__ . '] recent activity calculated in ' . functions::get_elapsed_time($start_time, functions::get_moment()));

    functions::conf_update_param('cache_activity_last_weeks', json_encode([
        'calculated_on' => time(),
        'data' => $activity_last_weeks,
    ]));
} else {
    $activity_last_weeks = $_cached_activity['data'];
}

foreach ($activity_last_weeks as $week => $i) {
    foreach ($i as $day => $j) {
        $details = $j['details'];
        ksort($details);
        $activity_last_weeks[$week][$day]['details'] = $details;

        if ($j['number'] > 0) {
            $temp_data[] = [
                'x' => $j['number'],
                'd' => $day,
                'w' => $week,
            ];
        }
    }
}

// Algorithm to sort days in circle size :
//  * Get the difference between sorted numbers of activity per day (only not null numbers)
//  * Split days max $circle_sizes time on the biggest difference (but not below 120%)
//  * Set the sizes according to the groups created

usort($temp_data, fn (array $a, array $b): int => $a['x'] <=> $b['x']);

//Get the percent difference
$diff_x = [];
$counter = count($temp_data);

for ($i = 1; $i < $counter; $i++) {
    $diff_x[] = $temp_data[$i]['x'] / $temp_data[$i - 1]['x'] * 100;
}

$split = 0;
//Split (split represented by -1)
if ($diff_x !== []) {
    while (max($diff_x) > 120) {
        $diff_x[array_search(max($diff_x), $diff_x, true)] = -1;
        $split++;
    }
}

//Fill empty chart data for the template
$chart_data = [];
for ($i = 0; $i < $nb_weeks; $i++) {
    for ($j = 1; $j <= 7; $j++) {
        $chart_data[$i][$j] = 0;
    }
}

$size = 1;

if (isset($temp_data[0])) {
    $chart_data[$temp_data[0]['w']][$temp_data[0]['d']] = $size;
}

//Set sizes in chart data
$counter = count($temp_data);

//Set sizes in chart data
for ($i = 1; $i < $counter; $i++) {
    if ($diff_x[$i - 1] == -1) {
        $size++;
    }

    $chart_data[$temp_data[$i]['w']][$temp_data[$i]['d']] = $size;
}

//Assign data for the template
$template->assign('ACTIVITY_WEEK_NUMBER', $week_number);
$template->assign('ACTIVITY_LAST_WEEKS', $activity_last_weeks);
$template->assign('ACTIVITY_CHART_DATA', $chart_data);
$template->assign('ACTIVITY_CHART_NUMBER_SIZES', $size);

$day_labels = [];

for ($i = 0; $i <= 6; $i++) {
    // first 3 letters of day name
    $day_labels[] = mb_substr($lang['day'][($i + 1) % 7], 0, 3);
}

$template->assign('DAY_LABELS', $day_labels);

// +-----------------------------------------------------------------------+
// |                           get storage data                            |
// +-----------------------------------------------------------------------+

$video_format = ['webm', 'webmv', 'ogg', 'ogv', 'mp4', 'm4v', 'mov'];
$data_storage = [];

$storage_by_ext = functions_admin::get_storage_by_ext();

foreach ($storage_by_ext['images'] as $ext => $ext_details) {
    if (in_array(strtolower($ext), $conf->picture_ext)) {
        $type = 'Photos';
    } elseif (in_array(strtolower($ext), $video_format, true)) {
        $type = 'Videos';
    } else {
        $type = 'Other';
    }

    $data_storage[$type]['total']['filesize'] ??= 0;
    $data_storage[$type]['total']['filesize'] += $ext_details['filesize'];
    $data_storage[$type]['total']['nb_files'] ??= 0;
    $data_storage[$type]['total']['nb_files'] += $ext_details['ext_counter'];

    $data_storage[$type]['details'][strtoupper($ext)] = [
        'filesize' => $ext_details['filesize'],
        'nb_files' => $ext_details['ext_counter'],
    ];
}

foreach ($storage_by_ext['formats'] as $ext => $ext_details) {
    $data_storage['Formats']['total']['filesize'] ??= 0;
    $data_storage['Formats']['total']['filesize'] += $ext_details['filesize'];
    $data_storage['Formats']['total']['nb_files'] ??= 0;
    $data_storage['Formats']['total']['nb_files'] += $ext_details['ext_counter'];

    $data_storage['Formats']['details'][strtoupper($ext)] = [
        'filesize' => $ext_details['filesize'],
        'nb_files' => $ext_details['ext_counter'],
    ];
}

// Add cache size if requested and known.
if ($conf->add_cache_to_storage_chart &&
    isset($conf->cache_sizes)
) {
    $cache_sizes = $conf->cache_sizes;

    if (isset($cache_sizes[0]['value'])) {
        $data_storage['Cache']['total']['filesize'] = $cache_sizes[0]['value'] / 1024;
    }
}

//Calculate total storage
$total_storage = 0;

foreach ($data_storage as $value) {
    $total_storage += $value['total']['filesize'];
}

//Pass data to HTML
$template->assign('STORAGE_TOTAL', $total_storage);
$template->assign('STORAGE_CHART_DATA', $data_storage);

// +-----------------------------------------------------------------------+
// |                           sending html code                           |
// +-----------------------------------------------------------------------+

require_once __DIR__ . '/../inc/vite_helper.php';
\Piwigo\Vite\vite_assign_modules($template, ['intro']);

$template->assign_var_from_handle('ADMIN_CONTENT', 'intro');

// Check integrity
$c13y = new check_integrity();
// add internal checks
new c13y_internal();
// check and display
$c13y->check();
$c13y->display();
