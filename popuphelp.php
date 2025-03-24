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

use Piwigo\inc\functions;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_user;

define('PHPWG_ROOT_PATH', './');
define('PWG_HELP', true);
require_once __DIR__ . '/inc/common.php';

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
functions_user::check_status(ACCESS_GUEST);

$page['body_id'] = 'thePopuphelpPage';
$title = functions::l10n('Piwigo Help');
$page['page_banner'] = '';
$page['meta_robots'] = [
    'noindex' => 1,
    'nofollow' => 1,
];
require __DIR__ . '/inc/page_header.php';

if (isset($_GET['page']) &&
    preg_match('/^[a-z_]*$/', $_GET['page'])
) {
    $help_content =
      functions::load_language('help/' . $_GET['page'] . '.html', '', [
          'return' => true,
      ]);

    if ($help_content == false) {
        $help_content = '';
    }

    $help_content = functions_plugins::trigger_change(
        'get_popup_help_content',
        $help_content,
        $_GET['page']
    );
} else {
    exit('Hacking attempt!');
}

$template->set_filename('popuphelp', 'popuphelp.tpl');

$template->assign(
    [
        'HELP_CONTENT' => $help_content,
    ]
);

// +-----------------------------------------------------------------------+
// |                           html code display                           |
// +-----------------------------------------------------------------------+

$template->pparse('popuphelp');

require __DIR__ . '/inc/page_tail.php';
