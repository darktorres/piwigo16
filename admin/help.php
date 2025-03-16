<?php

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\admin\inc\tabsheet;
use Piwigo\inc\functions;
use Piwigo\inc\functions_plugins;
use Piwigo\inc\functions_url;
use Piwigo\inc\functions_user;

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
functions_user::check_status(ACCESS_ADMINISTRATOR);

$help_link = functions_url::get_root_url() . 'admin.php?page=help&section=';
$selected = null;

if (! isset($_GET['section'])) {
    $selected = 'add_photos';
} else {
    $selected = $_GET['section'];
}

$tabsheet = new tabsheet();
$tabsheet->set_id('help');
$tabsheet->select($selected);
$tabsheet->assign();

functions_plugins::trigger_notify('loc_end_help');

$template->set_filenames([
    'help' => 'help.tpl',
]);

$template->assign(
    [
        'HELP_CONTENT' => functions::load_language(
            'help/help_' . $tabsheet->selected . '.html',
            '',
            [
                'return' => true,
            ]
        ),
        'HELP_SECTION_TITLE' => $tabsheet->sheets[$tabsheet->selected]['caption'],
    ]
);

if (substr($user['language'], 0, 3) == 'fr_') {
    $page['messages'][] = sprintf(
        'Besoin d\'aide pour utiliser Piwigo ? Consultez la <a href="%s" target="_blank">documentation en ligne</a> !',
        'https://doc-fr.piwigo.org/'
    );
}

// +-----------------------------------------------------------------------+
// |                           sending html code                           |
// +-----------------------------------------------------------------------+

$template->assign_var_from_handle('ADMIN_CONTENT', 'help');
