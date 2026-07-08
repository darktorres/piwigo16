<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

$upgrade_description = 'Add more options to email_admin_on_new_user';

[$old_value] = pwg_db_fetch_row(pwg_query('SELECT value FROM ' . PREFIX_TABLE . 'config WHERE param = "email_admin_on_new_user"'));

$new_value = 'all';
if ($old_value == 'false') {
    $new_value = 'none';
}

conf_update_param('email_admin_on_new_user', $new_value);

echo "\n"
. $upgrade_description
. "\n"
;
