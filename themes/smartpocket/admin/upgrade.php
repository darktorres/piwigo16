<?php

declare(strict_types=1);

use Piwigo\inc\functions;

if (! defined('PHPWG_ROOT_PATH')) {
    exit('Hacking attempt!');
}

global $conf;

if (! isset($conf->smartpocket)) {
    $config = [
        'loop' => true,
        //true - false
        'autohide' => 5000,
        //5000 - 0
    ];

    functions::conf_update_param('smartpocket', $config, true);
} elseif (count($conf->smartpocket) != 2) {
    $conff = $conf->smartpocket;
    $config = [
        'loop' => (empty($conff['loop'])) ? true : $conff['loop'],
        'autohide' => (empty($conff['autohide'])) ? 5000 : $conff['autohide'],
    ];
    functions::conf_update_param('smartpocket', $config, true);
}
