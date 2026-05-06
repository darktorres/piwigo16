<?php

declare(strict_types=1);

use Piwigo\Admin\Tabsheet;
use Piwigo\Core\ServiceLocator;
use Piwigo\Exception\AuthException;
use Piwigo\Url\UrlGenerator;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

if (!defined('PHPWG_ROOT_PATH')) {
    throw new AuthException('Hacking attempt!');
}

global $template, $user, $page, $persistent_cache, $lang;


require_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
require_once(PHPWG_ROOT_PATH.'admin/include/functions_upload.inc.php');

define(
    'PHOTOS_ADD_BASE_URL',
    ServiceLocator::get(UrlGenerator::class)->admin('photos_add')
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
    if ('ploader' == $page['tab']) {
        $page['tab'] = 'applications';
    }
} else {
    $page['tab'] = 'direct';
}

$tabsheet = new Tabsheet();
$tabsheet->set_id('photos_add');
$tabsheet->select($page['tab']);
$tabsheet->assign();

// +-----------------------------------------------------------------------+
// |                             template init                             |
// +-----------------------------------------------------------------------+

$template->set_filenames(
    [
    'photos_add' => 'photos_add_'.$page['tab'].'.tpl',
    ]
);

// +-----------------------------------------------------------------------+
// |                             Load the tab                              |
// +-----------------------------------------------------------------------+

require(PHPWG_ROOT_PATH.'admin/photos_add_'.$page['tab'].'.php');
