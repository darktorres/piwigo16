<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Admin\themes;
use Piwigo\Config\Config;
use Piwigo\Core\AccessLevel;

if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
check_status(AccessLevel::Administrator);

if (empty($_GET['theme']) || ! is_string($_GET['theme'])) {
    die('Invalid theme URL');
}
$theme = $_GET['theme'];

$themes = new themes();
if (! in_array($theme, array_keys($themes->fs_themes))) {
    die('Invalid theme');
}

$filename = Config::themesPath() . $theme . '/admin/admin.inc.php';
if (is_file($filename)) {
    include_once $filename;
} else {
    die('Missing file ' . $filename);
}
