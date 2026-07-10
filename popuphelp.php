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

define('PHPWG_ROOT_PATH', './');
define('PWG_HELP', true);

// Bootstrap global, set by include/common.inc.php below.
/** @var \Template $template */
global $template;

include_once PHPWG_ROOT_PATH . 'include/common.inc.php';

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(ACCESS_GUEST);

/** @var array<string, mixed> $page */
$page['body_id'] = 'thePopuphelpPage';
$title = l10n('Piwigo Help');
$page['page_banner'] = '';
$page['meta_robots'] = [
    'noindex' => 1,
    'nofollow' => 1,
];
include PHPWG_ROOT_PATH . 'include/page_header.php';

if (
    isset($_GET['page'])
    and is_string($_GET['page'])
    and (bool) preg_match('/^[a-z_]*$/', $_GET['page'])
) {
    $help_page = $_GET['page'];

    $help_content =
      load_language('help/' . $help_page . '.html', '', [
          'return' => true,
      ]);

    if ($help_content == false) {
        $help_content = '';
    }

    $help_content = trigger_change(
        'get_popup_help_content',
        $help_content,
        $help_page
    );
} else {
    die('Hacking attempt!');
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

include PHPWG_ROOT_PATH . 'include/page_tail.php';
