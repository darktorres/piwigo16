<?php

declare(strict_types=1);

use Piwigo\Admin\Themes;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

if (!defined('PHPWG_ROOT_PATH')) {
    throw new \Piwigo\Exception\AuthException('Hacking attempt!');
}

include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
check_status(ACCESS_ADMINISTRATOR);

if (empty($_GET['theme'])) {
    throw new \Piwigo\Exception\ValidationException('Invalid theme URL');
}

$themes = new Themes();
if (!in_array($_GET['theme'], array_keys($themes->fs_themes))) {
    throw new \Piwigo\Exception\ValidationException('Invalid theme');
}

$theme_name = is_scalar($_GET['theme']) ? (string) $_GET['theme'] : '';
$filename = PHPWG_THEMES_PATH.$theme_name.'/admin/admin.inc.php';
if (is_file($filename)) {
    include_once($filename);
} else {
    throw new \Piwigo\Exception\NotFoundException('Missing file '.$filename);
}
