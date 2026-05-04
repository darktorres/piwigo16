<?php

declare(strict_types=1);
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

$raw_section = $_GET['section'] ?? '';
$sections = explode('/', is_scalar($raw_section) ? (string) $raw_section : '');
for ($i = 0; $i < count($sections); $i++) {
    if (empty($sections[$i])) {
        unset($sections[$i]);
        $i--;
        continue;
    }

    if ($sections[$i] == '..' or !preg_match('/^[a-zA-Z0-9_\.-]+$/', $sections[$i])) {
        throw new \Piwigo\Exception\ValidationException('invalid section token ['.htmlentities($sections[$i]).']');
    }
}

if (count($sections) < 2) {
    throw new \Piwigo\Exception\ValidationException('Invalid plugin URL');
}

$plugin_id = $sections[0];

if (!preg_match('/^[\w-]+$/', $plugin_id)) {
    throw new \Piwigo\Exception\ValidationException('Invalid plugin identifier');
}

if (!isset($pwg_loaded_plugins[$plugin_id])) {
    throw new \Piwigo\Exception\AuthException('Invalid URL - plugin '.$plugin_id.' not active');
}

$filename = PHPWG_PLUGINS_PATH.implode('/', $sections);
if (is_file($filename)) {
    include_once($filename);
} else {
    throw new \Piwigo\Exception\NotFoundException('Missing file '.htmlentities($filename));
}
