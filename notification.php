<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// +-----------------------------------------------------------------------+
// |                           initialization                              |
// +-----------------------------------------------------------------------+

use Piwigo\inc\dblayer\functions_mysqli;
use Piwigo\inc\functions;
use Piwigo\inc\functions_html;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_user;
use Piwigo\inc\menubar;

define('PHPWG_ROOT_PATH', './');
require_once __DIR__ . '/inc/common.php';

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
functions_user::check_status(ACCESS_GUEST);

functions_plugins::trigger_notify('loc_begin_notification');

// +-----------------------------------------------------------------------+
// |                          new feed creation                            |
// +-----------------------------------------------------------------------+

$page['feed'] = functions::find_available_feed_id();

$query = <<<SQL
    INSERT INTO user_feed
        (id, user_id, last_check)
    VALUES
        ('{$page['feed']}', {$user['id']}, NULL);
    SQL;
functions_mysqli::pwg_query($query);

$feed_url = './feed.php';

if (functions_user::is_a_guest()) {
    $feed_image_only_url = $feed_url;
    $feed_url .= '?feed=' . $page['feed'];
} else {
    $feed_url .= '?feed=' . $page['feed'];
    $feed_image_only_url = $feed_url . '&amp;image_only';
}

// +-----------------------------------------------------------------------+
// |                        template initialization                        |
// +-----------------------------------------------------------------------+

$title = functions::l10n('Notification');
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

if (! isset($themeconf['hide_menu_on']) ||
    ! in_array('theNotificationPage', $themeconf['hide_menu_on'])
) {
    menubar::initialize_menu();
}

// +-----------------------------------------------------------------------+
// |                           html code display                           |
// +-----------------------------------------------------------------------+
require __DIR__ . '/inc/page_header.php';
functions_plugins::trigger_notify('loc_end_notification');
functions_html::flush_page_messages();
$template->pparse('notification');
require __DIR__ . '/inc/page_tail.php';
