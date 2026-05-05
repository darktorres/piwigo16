<?php

declare(strict_types=1);

use Piwigo\Admin\Integrity\C13yInternal;
use Piwigo\Admin\Integrity\CheckIntegrity;
use Piwigo\Admin\Tabsheet;
use Piwigo\Category\CategoryRepository;
use Piwigo\Comment\CommentRepository;
use Piwigo\Config\Config;
use Piwigo\Core\LoggerRegistry;
use Piwigo\Core\PageState;
use Piwigo\Core\ServiceLocator;
use Piwigo\Exception\AuthException;
use Piwigo\Image\ImageRepository;
use Piwigo\Users\UserRepository;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

if (!defined('PHPWG_ROOT_PATH')) {
    throw new AuthException('Hacking attempt!');
}

global $template, $user, $page, $persistent_cache, $lang, $logger, $pwg_loaded_plugins;


require_once(PHPWG_ROOT_PATH.'admin/include/functions.php');

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+

check_status(ACCESS_ADMINISTRATOR);

// +-----------------------------------------------------------------------+
// | tabs                                                                  |
// +-----------------------------------------------------------------------+

if (isset($_GET['action']) and 'hide_newsletter_subscription' == $_GET['action']) {
    userprefs_update_param('show_newsletter_subscription', 'false');
    exit();
}

$my_base_url = get_root_url().'admin.php?page=';

$tabsheet = new Tabsheet();
$tabsheet->set_id('admin_home');
$tabsheet->select('');
$tabsheet->assign();

// +-----------------------------------------------------------------------+
// |                                actions                                |
// +-----------------------------------------------------------------------+

if (isset($page['nb_pending_comments'])) {
    $message = l10n('User comments').' <i class="icon-chat"></i> ';
    $message .= '<a href="'.$my_base_url.'comments">';
    $message .= l10n('%d waiting for validation', $page['nb_pending_comments']);
    $message .= ' <i class="icon-right"></i></a>';

    PageState::current()->addMessage($message);
}

// any orphan photo?
$nb_orphans = $page['nb_orphans']; // already calculated in admin.php

if ($page['nb_photos_total'] >= 100000) { // but has not been calculated on a big gallery, so force it now
    $nb_orphans = count_orphans();
}

if ($nb_orphans > 0) {
    $orphans_url = PHPWG_ROOT_PATH.'admin.php?page=batch_manager&amp;filter=prefilter-no_album';

    $message = '<a href="'.$orphans_url.'"><i class="icon-heart-broken"></i>';
    $message .= l10n('Orphans').'</a>';
    $message .= '<span class="adminMenubarCounter">'.$nb_orphans.'</span>';

    PageState::current()->addWarning($message);
}

// locked album ?
$locked_album = ServiceLocator::get(CategoryRepository::class)->countHidden();
if ($locked_album > 0) {
    $locked_album_url = PHPWG_ROOT_PATH.'admin.php?page=cat_options&section=visible';

    $message = '<a href="'.$locked_album_url.'"><i class="icon-cone"></i>';
    $message .= l10n('Locked album').'</a>';
    $message .= '<span class="adminMenubarCounter">'.$locked_album.'</span>';

    PageState::current()->addWarning($message);
}

fs_quick_check();

// +-----------------------------------------------------------------------+
// |                             template init                             |
// +-----------------------------------------------------------------------+

$template->set_filenames(['intro' => 'intro.tpl']);

if (Config::showNewsletterSubscription() and userprefs_get_param('show_newsletter_subscription', true)) {
    $register_date = ServiceLocator::get(UserRepository::class)
        ->findEarliestRegistrationDate();
    $nb_cats   = ServiceLocator::get(CategoryRepository::class)->countAll();
    $nb_images = ServiceLocator::get(ImageRepository::class)->countAll();

    $uagent_obj = new uagent_info();
    // To see the newsletter promote, the account must have 2 weeks ancient, 3 albums created and 30 photos uploaded

    if (!$uagent_obj->DetectIos() and strtotime((string) $register_date) < strtotime('2 weeks ago') and $nb_cats >= 3 and $nb_images >= 30) {
        $userLang = (is_array($user) && isset($user['language']) && is_string($user['language'])) ? $user['language'] : '';
        $userEmail = (is_array($user) && isset($user['email']) && is_string($user['email'])) ? $user['email'] : '';
        $intro_newsletter_data = [
            'email'              => $userEmail,
            'subscribe_base_url' => get_newsletter_subscribe_base_url($userLang),
            'old_newsletters_url' => get_old_newsletters_base_url($userLang),
            'str_subscribe_title' => l10n('Subscribe to our newsletter and stay updated!'),
            'str_subscribe_button' => l10n('Sign up to the newsletter'),
            'str_see_previous'   => l10n('See previous newsletters'),
            'str_dismiss'        => l10n('Understood, do not show again'),
        ];
    }

}


$stats = get_pwg_general_statitics();

$du_decimals = 1;
$du_gb = (is_numeric($stats['disk_usage']) ? (float) $stats['disk_usage'] : 0.0) / (1024 * 1024);
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
    'NB_VIEWS' => number_format_human_readable(is_numeric($stats['nb_views']) ? (float) $stats['nb_views'] : 0.0),
    'NB_PLUGINS' => count($pwg_loaded_plugins),
    'STORAGE_USED' => str_replace(' ', '&nbsp;', l10n('%sGB', number_format($du_gb, $du_decimals))),
    'U_QUICK_SYNC' => PHPWG_ROOT_PATH.'admin.php?page=site_update&amp;site=1&amp;quick_sync=1&amp;pwg_token='.get_pwg_token(),
    'CHECK_FOR_UPDATES' => Config::dashboardCheckForUpdates(),
    ]
);

if (Config::activateComments()) {
    $nb_comments = ServiceLocator::get(CommentRepository::class)->countAll();
    $template->assign('NB_COMMENTS', $nb_comments);
} else {
    $template->assign('NB_COMMENTS', 0);
}

if (Config::showPiwigoLatestNews()) {
    $latest_news = get_piwigo_news();

    if (isset($latest_news['id']) and $latest_news['posted_on'] > time() - 60 * 60 * 24 * 30) {
        PageState::current()->addMessage(sprintf(
            '%s <a href="%s" title="%s" target="_blank"><i class="icon-bell"></i> %s</a>',
            l10n('Latest Piwigo news'),
            is_scalar($latest_news['url']) ? (string) $latest_news['url'] : '',
            time_since(is_string($latest_news['posted_on']) || is_int($latest_news['posted_on']) ? $latest_news['posted_on'] : null, 'year').' ('.(is_scalar($latest_news['posted']) ? (string) $latest_news['posted'] : '').')',
            is_scalar($latest_news['subject']) ? (string) $latest_news['subject'] : ''
        ));
    }
}

trigger_notify('loc_end_intro');

// +-----------------------------------------------------------------------+
// |                           get activity data                           |
// +-----------------------------------------------------------------------+

$nb_weeks = Config::dashboardActivityNbWeeks();

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
        $mondays += 1;
    }

    $date->sub(new DateInterval('P1D'));
}

$week_number = array_reverse($week_number);
$date_string = $date->format('Y-m-d');

$cached_activity = is_array($_SESSION['cache_activity_last_weeks'] ?? null) ? $_SESSION['cache_activity_last_weeks'] : null;
if ($cached_activity === null or (is_numeric($cached_activity['calculated_on']) ? (int) $cached_activity['calculated_on'] : 0) < strtotime('5 minutes ago')) {
    $start_time = get_moment();

    $query = '
  SELECT
      DATE_FORMAT(occured_on , \'%Y-%m-%d\') AS activity_day,
      object,
      action,
      COUNT(*) AS activity_counter
    FROM `'.ACTIVITY_TABLE.'`
    WHERE occured_on >= \''.$date_string.'\'
    GROUP BY activity_day, object, action
  ;';
    $activity_actions = get_dbal_connection()->executeQuery($query)->fetchAllAssociative();

    foreach ($activity_actions as $action) {
        // set the time to 12:00 (midday) so that it doesn't goes to previous/next day due to timezone offset
        $day_date = new DateTime((is_scalar($action['activity_day']) ? (string) $action['activity_day'] : '').' 12:00:00');

        $week = 0;
        for ($i = 0; $i < $nb_weeks; $i++) {
            if ($week_number[$i] == $day_date->format('W')) {
                $week = $i;
            }
        }
        $day_nb = $day_date->format('N');

        $activity_last_weeks[$week][$day_nb]['details'][ucfirst(is_scalar($action['object']) ? (string) $action['object'] : '')][ucfirst(is_scalar($action['action']) ? (string) $action['action'] : '')] = $action['activity_counter'];
        $activity_last_weeks[$week][$day_nb]['number'] = ($activity_last_weeks[$week][$day_nb]['number'] ?? 0) + (is_numeric($action['activity_counter']) ? (int) $action['activity_counter'] : 0);
        $activity_last_weeks[$week][$day_nb]['date'] = format_date($day_date->getTimestamp());
    }

    LoggerRegistry::current()->debug('[admin/intro::'.__LINE__.'] recent activity calculated in '.get_elapsed_time($start_time, get_moment()));

    $_SESSION['cache_activity_last_weeks'] = [
      'calculated_on' => time(),
      'data' => $activity_last_weeks,
    ];
}

$cached_activity = is_array($_SESSION['cache_activity_last_weeks'] ?? null) ? $_SESSION['cache_activity_last_weeks'] : [];
$activity_last_weeks = is_array($cached_activity['data'] ?? null) ? $cached_activity['data'] : [];


foreach ($activity_last_weeks as $week => $i) {
    if (!is_array($i)) {
        continue;
    }
    foreach ($i as $day => $j) {
        if (!is_array($j)) {
            continue;
        }
        $details = is_array($j['details'] ?? null) ? $j['details'] : [];
        ksort($details);
        if (is_array($activity_last_weeks[$week] ?? null) && is_array($activity_last_weeks[$week][$day] ?? null)) {
            /** @var array<string,mixed> $dayEntry */
            $dayEntry = $activity_last_weeks[$week][$day];
            $dayEntry['details'] = $details;
            $activity_last_weeks[$week][$day] = $dayEntry;
        }
        $jNumber = is_numeric($j['number'] ?? null) ? (int) $j['number'] : 0;
        if ($jNumber > 0) {
            $temp_data[] = ['x' => $jNumber, 'd' => $day, 'w' => $week];
        }
    }
}

// Algorithm to sort days in circle size :
//  * Get the difference between sorted numbers of activity per day (only not null numbers)
//  * Split days max $circle_sizes time on the biggest difference (but not below 120%)
//  * Set the sizes according to the groups created

//Function to sort days by number of activity
/**
 * @param array<mixed> $a
 * @param array<mixed> $b
 */
function cmp_day(array $a, array $b): int
{
    return $a['x'] <=> $b['x'];
}

usort($temp_data, cmp_day(...));

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
$day_names = is_array($lang['day'] ?? null) ? $lang['day'] : [];
for ($i = 0; $i <= 6; $i++) {
    // first 3 letters of day name; empty string if the active locale
    // doesn't define $lang['day'] (e.g. en_GB without our patch).
    $name = $day_names[($i + 1) % 7] ?? '';
    $day_labels[] = mb_substr(is_string($name) ? $name : '', 0, 3);
}
$template->assign('DAY_LABELS', $day_labels);

// +-----------------------------------------------------------------------+
// |                           get storage data                            |
// +-----------------------------------------------------------------------+

$video_format = ['webm','webmv','ogg','ogv','mp4','m4v', 'mov'];
$data_storage = [];

//Select files in Image_Table
$query = '
SELECT
  COUNT(*) AS ext_counter,
   SUBSTRING_INDEX(path,".",-1) AS ext,
   SUM(filesize) AS filesize
  FROM `'.IMAGES_TABLE.'`
  GROUP BY ext
;';

$file_extensions = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), null, 'ext');

foreach ($file_extensions as $ext => $ext_details) {
    $type = null;
    if (in_array(strtolower((string) $ext), Config::pictureExtensions())) {
        $type = 'Photos';
    } elseif (in_array(strtolower((string) $ext), $video_format)) {
        $type = 'Videos';
    } else {
        $type = 'Other';
    }

    $data_storage[$type]['total']['filesize'] = ($data_storage[$type]['total']['filesize'] ?? 0) + (is_numeric($ext_details['filesize']) ? (int) $ext_details['filesize'] : 0);
    $data_storage[$type]['total']['nb_files'] = ($data_storage[$type]['total']['nb_files'] ?? 0) + (is_numeric($ext_details['ext_counter']) ? (int) $ext_details['ext_counter'] : 0);

    $data_storage[$type]['details'][strtoupper((string) $ext)] = [
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
  FROM `'.IMAGE_FORMAT_TABLE.'`
  GROUP BY ext
;';

$file_extensions = array_column(get_dbal_connection()->executeQuery($query)->fetchAllAssociative(), null, 'ext');
foreach ($file_extensions as $ext => $ext_details) {
    $type = 'Formats';

    $data_storage[$type]['total']['filesize'] = ($data_storage[$type]['total']['filesize'] ?? 0) + (is_numeric($ext_details['filesize']) ? (int) $ext_details['filesize'] : 0);
    $data_storage[$type]['total']['nb_files'] = ($data_storage[$type]['total']['nb_files'] ?? 0) + (is_numeric($ext_details['ext_counter']) ? (int) $ext_details['ext_counter'] : 0);

    $data_storage[$type]['details'][strtoupper((string) $ext)] = [
      'filesize' => $ext_details['filesize'],
      'nb_files' => $ext_details['ext_counter'],
    ];
}

// Add cache size if requested and known.
if (Config::addCacheToStorageChart() && Config::has('cache_sizes')) {
    $cache_sizes = unserialize((string)Config::cacheSizes());
    if (is_array($cache_sizes) && isset($cache_sizes[0]) && is_array($cache_sizes[0]) && isset($cache_sizes[0]['value'])) {
        $cacheValue = is_numeric($cache_sizes[0]['value']) ? (float) $cache_sizes[0]['value'] : 0.0;
        $data_storage['Cache']['total']['filesize'] = $cacheValue / 1024;
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

$translate_type = [];
foreach ($data_storage as $type => $_) {
    $translate_type[$type] = l10n($type);
}
$intro_dashboard_extras = [
    'check_for_updates'       => (bool) Config::dashboardCheckForUpdates(),
    'storage_total'           => $total_storage,
    'str_gb_used'             => l10n('%s GB used'),
    'str_mb_used'             => l10n('%s MB used'),
    'str_piwigo_need_update'  => l10n('A new version of Piwigo is available.'),
    'str_ext_need_update'     => l10n('Some upgrades are available for extensions.'),
];
if (isset($intro_newsletter_data)) {
    $intro_dashboard_extras['newsletter'] = $intro_newsletter_data;
}

$template->assign('page_data_json', json_encode([
    'storage_details' => $data_storage,
    'str_gb'          => l10n('%sGB'),
    'str_mb'          => l10n('%sMB'),
    'translate_type'  => $translate_type,
    'translate_files'  => l10n('%d files'),
    'dashboard'       => $intro_dashboard_extras,
], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE));

// +-----------------------------------------------------------------------+
// |                           sending html code                           |
// +-----------------------------------------------------------------------+

$template->assign_var_from_handle('ADMIN_CONTENT', 'intro');

// Check integrity
$c13y = new CheckIntegrity();
// add internal checks
new C13yInternal();
// check and display
$c13y->check();
$c13y->display();
