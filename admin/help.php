<?php

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';
include_once PHPWG_ROOT_PATH . 'admin/include/tabsheet.class.php';

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(ACCESS_ADMINISTRATOR);

$help_link = get_root_url() . 'admin.php?page=help&section=';
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

trigger_notify('loc_end_help');

$template->set_filenames([
    'help' => 'help.tpl',
]);

$template->assign(
    [
        'HELP_CONTENT' => load_language(
            'help/help_' . $tabsheet->selected . '.html',
            '',
            [
                'return' => true,
            ]
        ),
        'HELP_SECTION_TITLE' => $tabsheet->sheets[$tabsheet->selected]['caption'],
    ]
);

$language_prefix = substr($user['language'], 0, 3);
if ($language_prefix == 'en_') {
    $page['messages'][] = sprintf(
        'Need help to use Piwigo? <a href="%s" target="_blank">Check the online documentation</a> !',
        'https://upstream.example.invalid/help/'
    );
} elseif ($language_prefix == 'fr_') {
    $page['messages'][] = sprintf(
        'Besoin d\'aide pour utiliser Piwigo ? Consultez la <a href="%s" target="_blank">documentation en ligne</a> !',
        'https://upstream.example.invalid/help/fr/'
    );
}

// +-----------------------------------------------------------------------+
// |                           sending html code                           |
// +-----------------------------------------------------------------------+

$template->assign_var_from_handle('ADMIN_CONTENT', 'help');
