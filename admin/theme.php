<?php

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\admin\inc\themes;
use Piwigo\inc\functions_user;

if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

functions_user::check_status(ACCESS_ADMINISTRATOR);

if (empty($_GET['theme'])) {
    die('Invalid theme URL');
}

$themes = new themes();
if (! in_array($_GET['theme'], array_keys($themes->fs_themes))) {
    die('Invalid theme');
}

$filename = PHPWG_THEMES_PATH . $_GET['theme'] . '/admin/admin.php';
if (is_file($filename)) {
    include_once($filename);
} else {
    die('Missing file ' . $filename);
}
