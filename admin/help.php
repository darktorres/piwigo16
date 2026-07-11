<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Admin\tabsheet;
use Piwigo\Template\Template;

// Bootstrap globals, set by include/common.inc.php.
/**
 * @var array<string, mixed> $page
 * @var Template $template
 * @var array<string, mixed> $user
 */
global $page, $template, $user;

include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+
check_status(ACCESS_ADMINISTRATOR);

$help_link = get_root_url() . 'admin.php?page=help&section=';
$selected = null;

if (! isset($_GET['section']) || ! is_string($_GET['section'])) {
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

if (! is_array($page['messages'] ?? null)) {
    $page['messages'] = [];
}

$user_language = $user['language'] ?? null;
$user_language = is_string($user_language) ? $user_language : 'en_UK';
$language_prefix = substr($user_language, 0, 3);
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
