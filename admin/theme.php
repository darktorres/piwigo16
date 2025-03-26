<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\admin\inc\themes;
use Piwigo\inc\functions_user;

if (! defined('PHPWG_ROOT_PATH')) {
    exit('Hacking attempt!');
}

functions_user::check_status(ACCESS_ADMINISTRATOR);

if (empty($_GET['theme'])) {
    exit('Invalid theme URL');
}

$themes = new themes();

if (! in_array($_GET['theme'], array_keys($themes->fs_themes))) {
    exit('Invalid theme');
}

$filename = PHPWG_THEMES_PATH . $_GET['theme'] . '/admin/admin.php';

if (is_file($filename)) {
    require_once $filename;
} else {
    exit('Missing file ' . $filename);
}
