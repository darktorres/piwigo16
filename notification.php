<?php

declare(strict_types=1);

global $template, $user, $page, $persistent_cache, $lang;
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// +-----------------------------------------------------------------------+
// |                           initialization                              |
// +-----------------------------------------------------------------------+

define('PHPWG_ROOT_PATH', './');
require_once(PHPWG_ROOT_PATH.'include/common.inc.php');
\Piwigo\Core\Kernel::boot();

/**
 * search an available feed_id
 *
 * @return string feed identifier
 */
function find_available_feed_id()
{
    $feedRepo = \Piwigo\Core\ServiceLocator::get(\Piwigo\Feed\FeedRepository::class);
    while (true) {
        $key = generate_key(50);
        if (!$feedRepo->existsById($key)) {
            return $key;
        }
    }
}

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(ACCESS_GUEST);

trigger_notify('loc_begin_notification');

// +-----------------------------------------------------------------------+
// |                          new feed creation                            |
// +-----------------------------------------------------------------------+

$page['feed'] = find_available_feed_id();

\Piwigo\Core\ServiceLocator::get(\Piwigo\Feed\FeedRepository::class)
    ->insert((string) $page['feed'], is_numeric($user['id']) ? (int) $user['id'] : 0);


$feed_url = PHPWG_ROOT_PATH.'feed.php';
if (is_a_guest()) {
    $feed_image_only_url = $feed_url;
    $feed_url .= '?feed='.$page['feed'];
} else {
    $feed_url .= '?feed='.$page['feed'];
    $feed_image_only_url = $feed_url.'&amp;image_only';
}

// +-----------------------------------------------------------------------+
// |                        template initialization                        |
// +-----------------------------------------------------------------------+

$title = l10n('Notification');
$page['body_id'] = 'theNotificationPage';
$page['meta_robots'] = array('noindex' => 1, 'nofollow' => 1);


$template->set_filenames(array('notification' => 'notification.tpl'));

$template->assign(
    array(
    'U_FEED' => $feed_url,
    'U_FEED_IMAGE_ONLY' => $feed_image_only_url,
    )
);

// include menubar
$themeconf = $template->get_template_vars('themeconf');
if (!isset($themeconf['hide_menu_on']) or !in_array('theNotificationPage', $themeconf['hide_menu_on'])) {
    require(PHPWG_ROOT_PATH.'include/menubar.inc.php');
}

// +-----------------------------------------------------------------------+
// |                           html code display                           |
// +-----------------------------------------------------------------------+
require(PHPWG_ROOT_PATH.'include/page_header.php');
trigger_notify('loc_end_notification');
flush_page_messages();
$template->pparse('notification');
require(PHPWG_ROOT_PATH.'include/page_tail.php');
