<?php

declare(strict_types=1);

use Piwigo\inc\functions;

if (! defined('PHPWG_ROOT_PATH')) {
    exit('Hacking attempt!');
}

$edited_file = PHPWG_PLUGINS_PATH . 'PersonalPlugin/main.php';

if (file_exists($edited_file)) {
    $content_file = file_get_contents($edited_file);
} else {
    $plugin_name = functions::l10n('locfiledit_onglet_plug');
    $content_file = <<<PHP
        <?php
        /*
        Plugin Name: {$plugin_name}
        Version: 1.0
        Description: {$plugin_name}
        Plugin URI: http://piwigo.org
        Author:
        Author URI:
        */





        ?>
        PHP;
}

$codemirror_mode = 'application/x-httpd-php';
