<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\inc\functions_user;

if (! defined('PHPWG_ROOT_PATH')) {
    exit('Hacking attempt!');
}

functions_user::check_status(ACCESS_ADMINISTRATOR);

$sections = explode('/', $_GET['section']);
$counter = count($sections);

for ($i = 0; $i < $counter; $i++) {
    if (empty($sections[$i])) {
        unset($sections[$i]);
        $i--;
        continue;
    }

    if ($sections[$i] === '..' ||
        ! preg_match('/^[a-zA-Z0-9_\.-]+$/', $sections[$i])
    ) {
        exit('invalid section token [' . htmlentities($sections[$i]) . ']');
    }
}

if (count($sections) < 2) {
    exit('Invalid plugin URL');
}

$plugin_id = $sections[0];

if (! preg_match('/^[\w-]+$/', $plugin_id)) {
    exit('Invalid plugin identifier');
}

if (! isset($pwg_loaded_plugins[$plugin_id])) {
    exit('Invalid URL - plugin ' . $plugin_id . ' not active');
}

$filename = PHPWG_PLUGINS_PATH . implode('/', $sections);

if (is_file($filename)) {
    require_once $filename;
} else {
    exit('Missing file ' . htmlentities($filename));
}
