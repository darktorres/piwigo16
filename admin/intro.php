<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

// Bootstrap globals. $link_start is set by admin.php before including this
// page; the rest by include/common.inc.php.
/**
 * @var array<string, mixed> $conf
 * @var array<string, mixed> $lang
 * @var string $link_start
 * @var \Logger $logger
 * @var array<string, mixed> $page
 * @var array<string, mixed> $pwg_loaded_plugins
 * @var \Template $template
 * @var array<string, mixed> $user
 */
global $conf, $lang, $link_start, $logger, $page, $pwg_loaded_plugins, $template, $user;

if (! is_array($page['messages'] ?? null)) {
    $page['messages'] = [];
}
if (! is_array($page['warnings'] ?? null)) {
    $page['warnings'] = [];
}

include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
include_once PHPWG_ROOT_PATH . 'admin/include/check_integrity.class.php';
include_once PHPWG_ROOT_PATH . 'admin/include/c13y_internal.class.php';

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+

check_status(ACCESS_ADMINISTRATOR);

// +-----------------------------------------------------------------------+
// | tabs                                                                  |
// +-----------------------------------------------------------------------+

if (isset($_GET['action']) and $_GET['action'] == 'hide_newsletter_subscription') {
    userprefs_update_param('show_newsletter_subscription', 'false');
    exit();
}

include_once PHPWG_ROOT_PATH . 'admin/include/tabsheet.class.php';

$my_base_url = get_root_url() . 'admin.php?page=';

$tabsheet = new tabsheet();
$tabsheet->set_id('admin_home');
$tabsheet->select('');
$tabsheet->assign();

// +-----------------------------------------------------------------------+
// |                                actions                                |
// +-----------------------------------------------------------------------+

if (isset($page['nb_pending_comments'])) {
    $message = l10n('User comments') . ' <i class="icon-chat"></i> ';
    $message .= '<a href="' . $link_start . 'comments">';
    $message .= l10n('%d waiting for validation', $page['nb_pending_comments']);
    $message .= ' <i class="icon-right"></i></a>';

    $page['messages'][] = $message;
}

// any orphan photo?
$nb_orphans = $page['nb_orphans']; // already calculated in admin.php
$nb_orphans = is_numeric($nb_orphans) ? (int) $nb_orphans : 0;

if ($page['nb_photos_total'] >= 100000) { // but has not been calculated on a big gallery, so force it now
    $nb_orphans = count_orphans();
    $nb_orphans = is_numeric($nb_orphans) ? (int) $nb_orphans : 0;
}

if ($nb_orphans > 0) {
    $orphans_url = PHPWG_ROOT_PATH . 'admin.php?page=batch_manager&amp;filter=prefilter-no_album';

    $message = '<a href="' . $orphans_url . '"><i class="icon-heart-broken"></i>';
    $message .= l10n('Orphans') . '</a>';
    $message .= '<span class="adminMenubarCounter">' . $nb_orphans . '</span>';

    $page['warnings'][] = $message;
}

// locked album ?
$query = '
SELECT COUNT(*)
  FROM ' . CATEGORIES_TABLE . '
  WHERE visible =\'false\'
;';
$row = pwg_db_fetch_row(pwg_query($query));
assert($row !== null);
[$locked_album] = $row;
if ($locked_album > 0) {
    $locked_album_url = PHPWG_ROOT_PATH . 'admin.php?page=cat_options&section=visible';

    $message = '<a href="' . $locked_album_url . '"><i class="icon-cone"></i>';
    $message .= l10n('Locked album') . '</a>';
    $message .= '<span class="adminMenubarCounter">' . $locked_album . '</span>';

    $page['warnings'][] = $message;
}

fs_quick_check();

// +-----------------------------------------------------------------------+
// |                             template init                             |
// +-----------------------------------------------------------------------+

$template->set_filenames([
    'intro' => 'intro.tpl',
]);

if ((bool) $conf['show_newsletter_subscription'] and (bool) userprefs_get_param('show_newsletter_subscription', true)) {
    $query = '
  SELECT registration_date
    FROM ' . USER_INFOS_TABLE . '
    WHERE registration_date IS NOT NULL
    ORDER BY user_id ASC
    LIMIT 1
  ;';
    $row = pwg_db_fetch_row(pwg_query($query));
    $register_date = $row !== null ? $row[0] : null;

    $query = '
  SELECT COUNT(*)
    FROM ' . CATEGORIES_TABLE . '
  ;';
    $row = pwg_db_fetch_row(pwg_query($query));
    assert($row !== null);
    [$nb_cats] = $row;

    $query = '
  SELECT COUNT(*)
    FROM ' . IMAGES_TABLE . '
  ;';
    $row = pwg_db_fetch_row(pwg_query($query));
    assert($row !== null);
    [$nb_images] = $row;

    // To see the newsletter promote, the account must have 2 weeks ancient, 3 albums created and 30 photos uploaded

    if (strtotime((string) $register_date) < strtotime('2 weeks ago') and $nb_cats >= 3 and $nb_images >= 30) {
        $user_language = $user['language'] ?? null;
        $user_language = is_string($user_language) ? $user_language : 'en_UK';

        $template->assign(
            [
                'EMAIL' => $user['email'],
                'SUBSCRIBE_BASE_URL' => get_newsletter_subscribe_base_url($user_language),
                'OLD_NEWSLETTERS_URL' => get_old_newsletters_base_url($user_language),
            ]
        );
    }

}

$stats = get_pwg_general_statitics();

// get_pwg_general_statitics() is declared to return array<string, mixed>,
// so PHPStan can't see that these two keys are already coerced from the
// SUM() DB values to int within that function; narrow locally.
$disk_usage = $stats['disk_usage'];
$disk_usage = is_numeric($disk_usage) ? (float) $disk_usage : 0.0;

$nb_views = $stats['nb_views'];
$nb_views = is_numeric($nb_views) ? (float) $nb_views : 0.0;

$du_decimals = 1;
$du_gb = $disk_usage / (1024 * 1024);
if ($du_gb > 100) {
    $du_decimals = 0;
}

$template->assign(
    [
        'NB_PHOTOS' => $stats['nb_photos'],
        'NB_ALBUMS' => $stats['nb_categories'],
        'NB_TAGS' => $stats['nb_tags'],
        'NB_IMAGE_TAG' => $stats['nb_image_tag'],
        'NB_USERS' => $stats['nb_users'],
        'NB_GROUPS' => $stats['nb_groups'],
        'NB_RATES' => $stats['nb_rates'],
        'NB_VIEWS' => number_format_human_readable($nb_views),
        'NB_PLUGINS' => count($pwg_loaded_plugins),
        'STORAGE_USED' => str_replace(' ', '&nbsp;', l10n('%sGB', number_format($du_gb, $du_decimals))),
        'U_QUICK_SYNC' => PHPWG_ROOT_PATH . 'admin.php?page=site_update&amp;site=1&amp;quick_sync=1&amp;pwg_token=' . get_pwg_token(),
        'CHECK_FOR_UPDATES' => $conf['dashboard_check_for_updates'],
    ]
);

if ((bool) $conf['activate_comments']) {
    $query = '
SELECT COUNT(*)
  FROM ' . COMMENTS_TABLE . '
;';
    $row = pwg_db_fetch_row(pwg_query($query));
    assert($row !== null);
    [$nb_comments] = $row;
    $template->assign('NB_COMMENTS', $nb_comments);
} else {
    $template->assign('NB_COMMENTS', 0);
}

if ((bool) $conf['show_piwigo_latest_news']) {
    $latest_news = get_piwigo_news();

    // get_piwigo_news() is declared to return mixed (it can come straight
    // back out of unserialize() on the cache file), so every field needs a
    // real runtime check before use below.
    $news_posted_on = is_array($latest_news) ? ($latest_news['posted_on'] ?? null) : null;

    if (
        is_array($latest_news)
        && isset($latest_news['id'])
        && (is_int($news_posted_on) || is_string($news_posted_on))
        && $news_posted_on > time() - 60 * 60 * 24 * 30
    ) {
        $news_url = $latest_news['url'] ?? null;
        $news_url = is_string($news_url) ? $news_url : '';

        $news_posted = $latest_news['posted'] ?? null;
        $news_posted = is_string($news_posted) ? $news_posted : '';

        $news_subject = $latest_news['subject'] ?? null;
        $news_subject = is_string($news_subject) ? $news_subject : '';

        $page['messages'][] = sprintf(
            '%s <a href="%s" title="%s" target="_blank"><i class="icon-bell"></i> %s</a>',
            l10n('Latest Piwigo news'),
            $news_url,
            time_since($news_posted_on, 'year') . ' (' . $news_posted . ')',
            $news_subject
        );
    }
}

trigger_notify('loc_end_intro');

// +-----------------------------------------------------------------------+
// |                           get activity data                           |
// +-----------------------------------------------------------------------+

$nb_weeks = $conf['dashboard_activity_nb_weeks'];

// Count mondays
$mondays = 0;
// Get mondays number for the chart legend
$week_number = [];
// Array for sorting days in circle size
$temp_data = [];

$activity_last_weeks = [];
$date = pwg_now();

// Get data from $nb_weeks last weeks
while ($mondays < $nb_weeks) {
    if ($date->format('D') == 'Mon') {
        $week_number[] = $date->format('W');
        ++$mondays;
    }

    $date->sub(new DateInterval('P1D'));
}

$week_number = array_reverse($week_number);
$date_string = $date->format('Y-m-d');

$session_cache_activity = $_SESSION['cache_activity_last_weeks'] ?? null;
$session_cache_calculated_on = is_array($session_cache_activity) ? ($session_cache_activity['calculated_on'] ?? null) : null;
$session_cache_calculated_on = is_numeric($session_cache_calculated_on) ? (int) $session_cache_calculated_on : null;

if ($session_cache_calculated_on === null or $session_cache_calculated_on < pwg_now()->getTimestamp() - 300) {
    $start_time = get_moment();

    $query = '
  SELECT
      DATE_FORMAT(occured_on , \'%Y-%m-%d\') AS activity_day,
      object,
      action,
      COUNT(*) AS activity_counter
    FROM `' . ACTIVITY_TABLE . '`
    WHERE occured_on >= \'' . $date_string . '\'
    GROUP BY activity_day, object, action
  ;';
    $activity_actions = query2array($query);

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

        // COUNT(*) always returns a numeric string from the DB layer.
        $activity_counter = $action['activity_counter'] ?? null;
        $activity_counter = is_numeric($activity_counter) ? (int) $activity_counter : 0;

        $activity_last_weeks[$week][$day_nb]['details'][ucfirst((string) $action['object'])][ucfirst((string) $action['action'])] = $activity_counter;

        // 'number' is only ever set below to an int (this same accumulation),
        // so this is always int|missing -- the ?? 0 fallback covers both.
        $current_number = $activity_last_weeks[$week][$day_nb]['number'] ?? 0;
        $activity_last_weeks[$week][$day_nb]['number'] = $current_number + $activity_counter;

        $activity_last_weeks[$week][$day_nb]['date'] = format_date($day_date->getTimestamp());
    }

    $logger->debug('[admin/intro::' . __LINE__ . '] recent activity calculated in ' . get_elapsed_time($start_time, get_moment()));

    $_SESSION['cache_activity_last_weeks'] = [
        'calculated_on' => pwg_now()
            ->getTimestamp(),
        'data' => $activity_last_weeks,
    ];
}

$session_cache_activity = $_SESSION['cache_activity_last_weeks'] ?? null;
$cached_activity_data = is_array($session_cache_activity) ? ($session_cache_activity['data'] ?? null) : null;
$raw_activity_last_weeks = is_array($cached_activity_data) ? $cached_activity_data : [];

$activity_last_weeks = [];

foreach ($raw_activity_last_weeks as $week => $i) {
    if (! is_array($i)) {
        continue;
    }

    foreach ($i as $day => $j) {
        if (! is_array($j)) {
            continue;
        }

        $details = $j['details'] ?? null;
        $details = is_array($details) ? $details : [];
        ksort($details);

        $number = $j['number'] ?? null;
        $number = is_numeric($number) ? $number : 0;

        $activity_last_weeks[$week][$day] = $j;
        $activity_last_weeks[$week][$day]['details'] = $details;

        if ($number > 0) {
            $temp_data[] = [
                'x' => $number,
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

// Function to sort days by number of activity
/**
 * @param array<string, mixed> $a
 * @param array<string, mixed> $b
 */
function cmp_day(array $a, array $b): int
{
    return $a['x'] <=> $b['x'];
}

usort($temp_data, cmp_day(...));

// Get the percent difference
$diff_x = [];

for ($i = 1; $i < count($temp_data); $i++) {
    // the 'x' key is always the numeric $number built earlier (guarded by
    // is_numeric()+> 0 above).
    $current_x = $temp_data[$i]['x'];
    $previous_x = $temp_data[$i - 1]['x'];
    $diff_x[] = $current_x / $previous_x * 100;
}

$split = 0;
// Split (split represented by -1)
if (count($diff_x) > 0) {
    while (max($diff_x) > 120) {
        $diff_x[array_search(max($diff_x), $diff_x)] = -1;
        $split++;
    }
}

// Fill empty chart data for the template
$chart_data = [];
for ($i = 0; $i < $nb_weeks; $i++) {
    for ($j = 1; $j <= 7; $j++) {
        $chart_data[$i][$j] = 0;
    }
}

$size = 1;

// 'w'/'d' are always the $week/$day foreach keys built earlier, always
// int|string (PHP's own array-key invariant).
if (isset($temp_data[0])) {
    $chart_w = $temp_data[0]['w'];
    $chart_d = $temp_data[0]['d'];
    $chart_data[$chart_w][$chart_d] = $size;
}

// Set sizes in chart data
for ($i = 1; $i < count($temp_data); $i++) {
    if ($diff_x[$i - 1] == -1) {
        $size++;
    }
    $chart_w = $temp_data[$i]['w'];
    $chart_d = $temp_data[$i]['d'];
    $chart_data[$chart_w][$chart_d] = $size;
}

// Assign data for the template
$template->assign('ACTIVITY_WEEK_NUMBER', $week_number);
$template->assign('ACTIVITY_LAST_WEEKS', $activity_last_weeks);
$template->assign('ACTIVITY_CHART_DATA', $chart_data);
$template->assign('ACTIVITY_CHART_NUMBER_SIZES', $size);

$lang_days = $lang['day'] ?? null;
$lang_days = is_array($lang_days) ? $lang_days : [];

$day_labels = [];
for ($i = 0; $i <= 6; $i++) {
    // first 3 letters of day name
    $day_name = $lang_days[($i + 1) % 7] ?? null;
    $day_name = is_string($day_name) ? $day_name : '';
    $day_labels[] = mb_substr($day_name, 0, 3);
}
$template->assign('DAY_LABELS', $day_labels);

// +-----------------------------------------------------------------------+
// |                           get storage data                            |
// +-----------------------------------------------------------------------+

$video_format = ['webm', 'webmv', 'ogg', 'ogv', 'mp4', 'm4v', 'mov'];
/** @var array<string, array<string, array<string, mixed>>> $data_storage */
$data_storage = [];

$picture_ext = $conf['picture_ext'] ?? null;
$picture_ext = is_array($picture_ext) ? $picture_ext : [];

// Select files in Image_Table
$query = '
SELECT
  COUNT(*) AS ext_counter,
   SUBSTRING_INDEX(path,".",-1) AS ext,
   SUM(filesize) AS filesize
  FROM `' . IMAGES_TABLE . '`
  GROUP BY ext
;';

$file_extensions = query2array($query, 'ext');

foreach ($file_extensions as $ext => $ext_details) {
    $type = null;
    if (in_array(strtolower((string) $ext), $picture_ext)) {
        $type = 'Photos';
    } elseif (in_array(strtolower((string) $ext), $video_format)) {
        $type = 'Videos';
    } else {
        $type = 'Other';
    }

    // SUM()/COUNT(*) always return numeric strings (or NULL for an empty
    // group) from the DB layer.
    $ext_filesize = $ext_details['filesize'] ?? null;
    $ext_filesize = is_numeric($ext_filesize) ? (float) $ext_filesize : 0.0;

    $ext_counter = $ext_details['ext_counter'] ?? null;
    $ext_counter = is_numeric($ext_counter) ? (int) $ext_counter : 0;

    $current_filesize = $data_storage[$type]['total']['filesize'] ?? 0;
    $current_filesize = is_numeric($current_filesize) ? (float) $current_filesize : 0.0;
    $data_storage[$type]['total']['filesize'] = $current_filesize + $ext_filesize;

    $current_nb_files = $data_storage[$type]['total']['nb_files'] ?? 0;
    $current_nb_files = is_numeric($current_nb_files) ? (int) $current_nb_files : 0;
    $data_storage[$type]['total']['nb_files'] = $current_nb_files + $ext_counter;

    $data_storage[$type]['details'][strtoupper((string) $ext)] = [
        'filesize' => $ext_filesize,
        'nb_files' => $ext_counter,
    ];
}

// Select files from format table
$query = '
SELECT
    COUNT(*) AS ext_counter,
    ext,
    SUM(filesize) AS filesize
  FROM `' . IMAGE_FORMAT_TABLE . '`
  GROUP BY ext
;';

$file_extensions = query2array($query, 'ext');
foreach ($file_extensions as $ext => $ext_details) {
    $type = 'Formats';

    // SUM()/COUNT(*) always return numeric strings (or NULL for an empty
    // group) from the DB layer.
    $ext_filesize = $ext_details['filesize'] ?? null;
    $ext_filesize = is_numeric($ext_filesize) ? (float) $ext_filesize : 0.0;

    $ext_counter = $ext_details['ext_counter'] ?? null;
    $ext_counter = is_numeric($ext_counter) ? (int) $ext_counter : 0;

    $current_filesize = $data_storage[$type]['total']['filesize'] ?? 0;
    $current_filesize = is_numeric($current_filesize) ? (float) $current_filesize : 0.0;
    $data_storage[$type]['total']['filesize'] = $current_filesize + $ext_filesize;

    $current_nb_files = $data_storage[$type]['total']['nb_files'] ?? 0;
    $current_nb_files = is_numeric($current_nb_files) ? (int) $current_nb_files : 0;
    $data_storage[$type]['total']['nb_files'] = $current_nb_files + $ext_counter;

    $data_storage[$type]['details'][strtoupper((string) $ext)] = [
        'filesize' => $ext_filesize,
        'nb_files' => $ext_counter,
    ];
}

// Add cache size if requested and known.
// $conf['cache_sizes'] is normally the serialized string as loaded from the
// config table, but conf_update_param(..., true) can also leave the raw
// array in place within the same request (see admin/maintenance_env.php).
if ((bool) $conf['add_cache_to_storage_chart'] && isset($conf['cache_sizes'])) {
    $cache_sizes = null;
    if (is_string($conf['cache_sizes'])) {
        $unserialized_cache_sizes = unserialize($conf['cache_sizes']);
        if (is_array($unserialized_cache_sizes)) {
            $cache_sizes = $unserialized_cache_sizes;
        }
    } elseif (is_array($conf['cache_sizes'])) {
        $cache_sizes = $conf['cache_sizes'];
    }

    if (is_array($cache_sizes)) {
        $cache_size_zero = $cache_sizes[0] ?? null;
        $cache_size_value = is_array($cache_size_zero) ? ($cache_size_zero['value'] ?? null) : null;
        if (is_numeric($cache_size_value)) {
            @$data_storage['Cache']['total']['filesize'] = $cache_size_value / 1024;
        }
    }
}

// Calculate total storage
$total_storage = 0;
foreach ($data_storage as $value) {
    $storage_filesize = $value['total']['filesize'] ?? 0;
    $storage_filesize = is_numeric($storage_filesize) ? (float) $storage_filesize : 0.0;
    $total_storage += $storage_filesize;
}

// Pass data to HTML
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
