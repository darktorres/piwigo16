<?php

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
use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\functions;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;

if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+

functions_user::check_status(ACCESS_ADMINISTRATOR);

// +-----------------------------------------------------------------------+
// | tabs                                                                  |
// +-----------------------------------------------------------------------+

if (isset($_GET['action']) and
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
    $orphans_url = PHPWG_ROOT_PATH . 'admin.php?page=batch_manager&amp;filter=prefilter-no_album';

    $message = '<a href="' . $orphans_url . '"><i class="icon-heart-broken"></i>';
    $message .= functions::l10n('Orphans') . '</a>';
    $message .= '<span class="adminMenubarCounter">' . $nb_orphans . '</span>';

    $page['warnings'][] = $message;
}

// locked album ?
$query = '
SELECT COUNT(*)
  FROM ' . CATEGORIES_TABLE . '
  WHERE visible =\'false\'
;';
list($locked_album) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));

if ($locked_album > 0) {
    $locked_album_url = PHPWG_ROOT_PATH . 'admin.php?page=cat_options&section=visible';

    $message = '<a href="' . $locked_album_url . '"><i class="icon-cone"></i>';
    $message .= functions::l10n('Locked album') . '</a>';
    $message .= '<span class="adminMenubarCounter">' . $locked_album . '</span>';

    $page['warnings'][] = $message;
}

functions_admin::fs_quick_check();

// +-----------------------------------------------------------------------+
// |                             template init                             |
// +-----------------------------------------------------------------------+

$template->set_filenames([
    'intro' => 'intro.tpl',
]);

if ($conf['show_newsletter_subscription'] and
    functions_user::userprefs_get_param('show_newsletter_subscription', true)
) {
    $template->assign(
        [
            'EMAIL' => $user['email'],
            'SUBSCRIBE_BASE_URL' => functions_admin::get_newsletter_subscribe_base_url($user['language']),
        ]
    );
}

$query = '
SELECT COUNT(*)
  FROM ' . IMAGES_TABLE . '
;';
list($nb_photos) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));

$query = '
SELECT COUNT(*)
  FROM ' . CATEGORIES_TABLE . '
;';
list($nb_categories) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));

$query = '
SELECT COUNT(*)
  FROM ' . TAGS_TABLE . '
;';
list($nb_tags) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));

$query = '
SELECT COUNT(*)
  FROM ' . IMAGE_TAG_TABLE . '
;';
list($nb_image_tag) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));

$query = '
SELECT COUNT(*)
  FROM ' . USERS_TABLE . '
;';
list($nb_users) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));

$query = '
SELECT COUNT(*)
  FROM `' . GROUPS_TABLE . '`
;';
list($nb_groups) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));

$query = '
SELECT COUNT(*)
  FROM ' . RATE_TABLE . '
;';
list($nb_rates) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));

$query = '
SELECT
    SUM(nb_pages)
  FROM ' . HISTORY_SUMMARY_TABLE . '
  WHERE month IS NULL
;';
list($nb_views) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));

$query = '
SELECT
    SUM(filesize)
  FROM ' . IMAGES_TABLE . '
;';
list($disk_usage) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));

$query = '
SELECT
    SUM(filesize)
  FROM ' . IMAGE_FORMAT_TABLE . '
;';
list($formats_disk_usage) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));

$disk_usage += $formats_disk_usage;

$du_decimals = 1;
$du_gb = $disk_usage / (1024 * 1024);

if ($du_gb > 100) {
    $du_decimals = 0;
}

$template->assign(
    [
        'NB_PHOTOS' => $nb_photos,
        'NB_ALBUMS' => $nb_categories,
        'NB_TAGS' => $nb_tags,
        'NB_IMAGE_TAG' => $nb_image_tag,
        'NB_USERS' => $nb_users,
        'NB_GROUPS' => $nb_groups,
        'NB_RATES' => $nb_rates,
        'NB_VIEWS' => functions_admin::number_format_human_readable($nb_views),
        'NB_PLUGINS' => count($pwg_loaded_plugins),
        'STORAGE_USED' => str_replace(' ', '&nbsp;', functions::l10n('%sGB', number_format($du_gb, $du_decimals))),
        'U_QUICK_SYNC' => PHPWG_ROOT_PATH . 'admin.php?page=site_update&amp;site=1&amp;quick_sync=1&amp;pwg_token=' . functions::get_pwg_token(),
        'CHECK_FOR_UPDATES' => $conf['dashboard_check_for_updates'],
    ]
);

if ($conf['activate_comments']) {
    $query = '
SELECT COUNT(*)
  FROM ' . COMMENTS_TABLE . '
;';
    list($nb_comments) = functions_mysqli::pwg_db_fetch_row(functions_mysqli::pwg_query($query));
    $template->assign('NB_COMMENTS', $nb_comments);
} else {
    $template->assign('NB_COMMENTS', 0);
}

if ($conf['show_piwigo_latest_news']) {
    $latest_news = functions_admin::get_piwigo_news();

    if (isset($latest_news['id']) and
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

$nb_weeks = $conf['dashboard_activity_nb_weeks'];

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
    if ($date->format('D') == 'Mon') {
        $week_number[] = $date->format('W');
        ++$mondays;
    }

    $date->sub(new DateInterval('P1D'));
}

$week_number = array_reverse($week_number);
$date_string = $date->format('Y-m-d');

if (! isset($_SESSION['cache_activity_last_weeks']) or
    $_SESSION['cache_activity_last_weeks']['calculated_on'] < strtotime('5 minutes ago')
) {
    $start_time = functions::get_moment();

    $query = '
  SELECT
      DATE_FORMAT(occurred_on , \'%Y-%m-%d\') AS activity_day,
      object,
      action,
      COUNT(*) AS activity_counter
    FROM `' . ACTIVITY_TABLE . '`
    WHERE occurred_on >= \'' . $date_string . '\'
    GROUP BY activity_day, object, action
  ;';
    $activity_actions = functions_mysqli::query2array($query);

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

        @$activity_last_weeks[$week][$day_nb]['details'][ucfirst($action['object'])][ucfirst($action['action'])] = $action['activity_counter'];
        @$activity_last_weeks[$week][$day_nb]['number'] += $action['activity_counter'];
        @$activity_last_weeks[$week][$day_nb]['date'] = functions::format_date($day_date->getTimestamp());
    }

    $logger->debug('[admin/intro::' . __LINE__ . '] recent activity calculated in ' . functions::get_elapsed_time($start_time, functions::get_moment()));

    $_SESSION['cache_activity_last_weeks'] = [
        'calculated_on' => time(),
        'data' => $activity_last_weeks,
    ];
}

$activity_last_weeks = $_SESSION['cache_activity_last_weeks']['data'];

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

usort($temp_data, function ($a, $b) {
    //Function to sort days by number of activity
    if ($a['x'] == $b['x']) {
        return 0;
    }

    return ($a['x'] < $b['x']) ? -1 : 1;
});

//Get the percent difference
$diff_x = [];

for ($i = 1; $i < count($temp_data); $i++) {
    $diff_x[] = $temp_data[$i]['x'] / $temp_data[$i - 1]['x'] * 100;
}

$split = 0;
//Split (split represented by -1)
if (count($diff_x) > 0) {
    while (max($diff_x) > 120) {
        $diff_x[array_search(max($diff_x), $diff_x)] = -1;
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
for ($i = 1; $i < count($temp_data); $i++) {
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

//Select files in Image_Table
$query = '
SELECT
  COUNT(*) AS ext_counter,
   SUBSTRING_INDEX(path,".",-1) AS ext,
   SUM(filesize) AS filesize
  FROM `' . IMAGES_TABLE . '`
  GROUP BY ext
;';

$file_extensions = functions_mysqli::query2array($query, 'ext');

foreach ($file_extensions as $ext => $ext_details) {
    $type = null;

    if (in_array(strtolower($ext), $conf['picture_ext'])) {
        $type = 'Photos';
    } elseif (in_array(strtolower($ext), $video_format)) {
        $type = 'Videos';
    } else {
        $type = 'Other';
    }

    @$data_storage[$type]['total']['filesize'] += $ext_details['filesize'];
    @$data_storage[$type]['total']['nb_files'] += $ext_details['ext_counter'];

    @$data_storage[$type]['details'][strtoupper($ext)] = [
        'filesize' => $ext_details['filesize'],
        'nb_files' => $ext_details['ext_counter'],
    ];
}

//Select files from format table
$query = '
SELECT
    COUNT(*) AS ext_counter,
    ext,
    SUM(filesize) AS filesize
  FROM `' . IMAGE_FORMAT_TABLE . '`
  GROUP BY ext
;';

$file_extensions = functions_mysqli::query2array($query, 'ext');

foreach ($file_extensions as $ext => $ext_details) {
    $type = 'Formats';

    @$data_storage[$type]['total']['filesize'] += $ext_details['filesize'];
    @$data_storage[$type]['total']['nb_files'] += $ext_details['ext_counter'];

    @$data_storage[$type]['details'][strtoupper($ext)] = [
        'filesize' => $ext_details['filesize'],
        'nb_files' => $ext_details['ext_counter'],
    ];
}

// Add cache size if requested and known.
if ($conf['add_cache_to_storage_chart'] &&
    isset($conf['cache_sizes'])
) {
    $cache_sizes = unserialize($conf['cache_sizes']);

    if (isset($cache_sizes)) {
        if (isset($cache_sizes[0]) &&
            isset($cache_sizes[0]['value'])
        ) {
            @$data_storage['Cache']['total']['filesize'] = $cache_sizes[0]['value'] / 1024;
        }
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

$template->assign_var_from_handle('ADMIN_CONTENT', 'intro');

// Check integrity
$c13y = new check_integrity();
// add internal checks
new c13y_internal();
// check and display
$c13y->check();
$c13y->display();
