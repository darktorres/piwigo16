<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Core\AccessLevel;
use Piwigo\Db\DbConnection;
use Piwigo\Feed\FeedRepository;
use Piwigo\Template\Template;

// +-----------------------------------------------------------------------+
// |                           initialization                              |
// +-----------------------------------------------------------------------+

define('PHPWG_ROOT_PATH', './');
include_once PHPWG_ROOT_PATH . 'include/common.inc.php';

// Bootstrap globals, set by include/common.inc.php.
/**
 * @var array<string, mixed> $page
 * @var Template $template
 * @var array<string, mixed> $user
 */
global $page, $template, $user;

/**
 * search an available feed_id
 *
 * @return string feed identifier
 */
function find_available_feed_id(FeedRepository $feed_repo): string
{
    while (true) {
        $key = generate_key(50);
        if (! $feed_repo->existsById($key)) {
            return $key;
        }
    }
}

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(AccessLevel::Guest);

trigger_notify('loc_begin_notification');

// +-----------------------------------------------------------------------+
// |                          new feed creation                            |
// +-----------------------------------------------------------------------+

$feed_repo = new FeedRepository(DbConnection::build());

// find_available_feed_id() always returns a string; $page['feed'] is kept
// in sync but the query/URLs below read this local variable so its type
// stays narrowed to string instead of widening back to mixed via $page.
$feed_id = find_available_feed_id($feed_repo);
$page['feed'] = $feed_id;

// $user['id'] (the logged in / guest user id) is never reassigned below.
$user_id = is_numeric($user['id']) ? (int) $user['id'] : 0;

$feed_repo->insert($feed_id, $user_id);

$feed_url = PHPWG_ROOT_PATH . 'feed.php';
if (is_a_guest()) {
    $feed_image_only_url = $feed_url;
    $feed_url .= '?feed=' . $feed_id;
} else {
    $feed_url .= '?feed=' . $feed_id;
    $feed_image_only_url = $feed_url . '&amp;image_only';
}

// +-----------------------------------------------------------------------+
// |                        template initialization                        |
// +-----------------------------------------------------------------------+

$title = l10n('Notification');
$page['body_id'] = 'theNotificationPage';
$page['meta_robots'] = [
    'noindex' => 1,
    'nofollow' => 1,
];

$template->set_filenames([
    'notification' => 'notification.tpl',
]);

$template->assign(
    [
        'U_FEED' => $feed_url,
        'U_FEED_IMAGE_ONLY' => $feed_image_only_url,
    ]
);

// include menubar
$themeconf = $template->get_template_vars('themeconf');
$themeconf = is_array($themeconf) ? $themeconf : [];
if (! isset($themeconf['hide_menu_on']) or ! is_array($themeconf['hide_menu_on']) or ! in_array('theNotificationPage', $themeconf['hide_menu_on'])) {
    include PHPWG_ROOT_PATH . 'include/menubar.inc.php';
}

// +-----------------------------------------------------------------------+
// |                           html code display                           |
// +-----------------------------------------------------------------------+
include PHPWG_ROOT_PATH . 'include/page_header.php';
trigger_notify('loc_end_notification');
flush_page_messages();
$template->pparse('notification');
include PHPWG_ROOT_PATH . 'include/page_tail.php';
