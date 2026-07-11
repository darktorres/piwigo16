<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Admin\tabsheet;
use Piwigo\Template\Template;

if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

// Bootstrap globals, set by include/common.inc.php.
/** @var Template $template */
global $template;

// Bootstrap $page, set by admin.php before including this tab dispatcher.
/** @var array<string, mixed> $page */
global $page;

include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
include_once PHPWG_ROOT_PATH . 'admin/include/functions_upload.inc.php';

define(
    'PHOTOS_ADD_BASE_URL',
    get_root_url() . 'admin.php?page=photos_add'
);

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+

check_status(ACCESS_ADMINISTRATOR);

// +-----------------------------------------------------------------------+
// |                          Load configuration                           |
// +-----------------------------------------------------------------------+

$upload_form_config = get_upload_form_config();

// +-----------------------------------------------------------------------+
// |                                 Tabs                                  |
// +-----------------------------------------------------------------------+
if (isset($_GET['section'])) {
    $page['tab'] = is_string($_GET['section']) ? $_GET['section'] : 'direct';

    // backward compatibility
    if ($page['tab'] == 'ploader') {
        $page['tab'] = 'applications';
    }
} else {
    $page['tab'] = 'direct';
}

$tabsheet = new tabsheet();
$tabsheet->set_id('photos_add');
$tabsheet->select($page['tab']);
$tabsheet->assign();

// +-----------------------------------------------------------------------+
// |                             template init                             |
// +-----------------------------------------------------------------------+

$template->set_filenames(
    [
        'photos_add' => 'photos_add_' . $page['tab'] . '.tpl',
    ]
);

// +-----------------------------------------------------------------------+
// |                             Load the tab                              |
// +-----------------------------------------------------------------------+

include PHPWG_ROOT_PATH . 'admin/photos_add_' . $page['tab'] . '.php';
