<?php

declare(strict_types=1);

use Piwigo\Exception\AuthException;
// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

// +-----------------------------------------------------------------------+
// |                           initialization                              |
// +-----------------------------------------------------------------------+

define('PHPWG_ROOT_PATH', '../');

global $template, $user, $page, $persistent_cache, $lang;
define('PWG_HELP', true);
define('IN_ADMIN', true);
require_once(PHPWG_ROOT_PATH.'include/common.inc.php');

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(ACCESS_ADMINISTRATOR);

if (!isset($_GET['output']) or 'content_only' != $_GET['output']) {
    // Note on 2023-09-28 : calling popuphelp.php without output=content_only no longer occurs in Piwigo core.
    $page['body_id'] = 'thePopuphelpPage';
    $title = l10n('Piwigo Help');
    $page['page_banner'] = '<h1>'.$title.'</h1>';
    $page['meta_robots'] = ['noindex' => 1, 'nofollow' => 1];

    // set required template variables to avoid "Undefined array key" with PHP 8
    $template->assign(
        [
        'U_RETURN' => '',
        'USERNAME' => '',
        'U_FAQ' => '',
        'U_CHANGE_THEME' => '',
        'U_LOGOUT' => '',
    ]
    );

    require(PHPWG_ROOT_PATH.'include/page_header.php');
}

$helpPage = is_scalar($_GET['page'] ?? null) ? (string) $_GET['page'] : '';
if (
    isset($_GET['page'])
    and preg_match('/^[a-z_]*$/', $helpPage)
) {
    $help_content = load_language(
        'help/'.$helpPage.'.html',
        '',
        [
        'force_fallback' => 'en_UK',
        'return' => true,
        ]
    );

    if ($help_content == false) {
        $help_content = '';
    }

    $help_content = trigger_change('get_popup_help_content', $help_content, $_GET['page']);
} else {
    throw new AuthException('Hacking attempt!');
}

$template->set_filename('popuphelp', 'popuphelp.tpl');

$template->assign(
    [
    'HELP_CONTENT' => $help_content,
  ]
);

if (isset($_GET['output']) and 'content_only' == $_GET['output']) {
    echo $help_content;
    exit();
}

// +-----------------------------------------------------------------------+
// |                           html code display                           |
// +-----------------------------------------------------------------------+

$template->pparse('popuphelp');

require(PHPWG_ROOT_PATH.'include/page_tail.php');
