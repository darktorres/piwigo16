<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Admin\themes;

if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
check_status(ACCESS_ADMINISTRATOR);

if (empty($_GET['theme']) || ! is_string($_GET['theme'])) {
    die('Invalid theme URL');
}
$theme = $_GET['theme'];

$themes = new themes();
if (! in_array($theme, array_keys($themes->fs_themes))) {
    die('Invalid theme');
}

$filename = PHPWG_THEMES_PATH . $theme . '/admin/admin.inc.php';
if (is_file($filename)) {
    include_once $filename;
} else {
    die('Missing file ' . $filename);
}
