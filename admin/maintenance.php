<?php

declare(strict_types=1);

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

use Piwigo\Admin\tabsheet;
use Piwigo\Core\AccessLevel;
use Piwigo\Template\Template;

if (! defined('PHPWG_ROOT_PATH')) {
    die('Hacking attempt!');
}

// Bootstrap globals. $page is set by admin.php before including this page;
// $template by include/common.inc.php.
/**
 * @var array<string, mixed> $page
 * @var Template $template
 */
global $page, $template;

// Explicit `global` for $maint_actions (assigned as a bare `$maint_actions =
// [...]` below) so admin/maintenance_actions.php's and
// admin/maintenance_sys.php's own `global $maint_actions;` reads see this
// array. A no-op today, while this file is still include_once'd from real
// top-level script scope -- but load-bearing the moment that inclusion is
// wrapped in a function/method call frame (e.g. a future dispatcher), which
// would otherwise make the bare assignment invisible to those includes.
global $maint_actions;

include_once PHPWG_ROOT_PATH . 'admin/include/functions.php';

// +-----------------------------------------------------------------------+
// | Check Access and exit when user status is not ok                      |
// +-----------------------------------------------------------------------+

check_status(AccessLevel::Administrator);

if (isset($_GET['action'])) {
    check_pwg_token();
}
// +-----------------------------------------------------------------------+
// | Commons parameters                                                    |
// +-----------------------------------------------------------------------+

$maint_actions = [
    'derivatives' => [
        'icon' => 'icon-trash-1',
        'label' => l10n('Delete multiple size images'),
    ],
    'lock_gallery' => [
        'icon' => 'icon-lock',
        'label' => l10n('Lock gallery'),
    ],
    'unlock_gallery' => [
        'icon' => 'icon-lock',
        'label' => l10n('Unlock gallery'),
    ],
    'categories' => [
        'icon' => 'icon-folder-open',
        'label' => l10n('Update albums informations'),
    ],
    'images' => [
        'icon' => 'icon-info-circled-1',
        'label' => l10n('Update photos information'),
    ],
    'empty_lounge' => [
        'icon' => 'icon-thumbs-up',
        'label' => l10n('Empty lounge'),
    ],
    'delete_orphan_tags' => [
        'icon' => 'icon-tags',
        'label' => l10n('Delete orphan tags'),
    ],
    'user_cache' => [
        'icon' => 'icon-user-1',
        'label' => l10n('Purge user cache'),
    ],
    'history_detail' => [
        'icon' => 'icon-back-in-time',
        'label' => l10n('Purge history detail'),
    ],
    'history_summary' => [
        'icon' => 'icon-back-in-time',
        'label' => l10n('Purge history summary'),
    ],
    'sessions' => [
        'icon' => 'icon-th-list',
        'label' => l10n('Purge sessions'),
    ],
    'feeds' => [
        'icon' => 'icon-bell',
        'label' => l10n('Purge never used notification feeds'),
    ],
    'database' => [
        'icon' => 'icon-database',
        'label' => l10n('Repair and optimize database'),
    ],
    'c13y' => [
        'icon' => 'icon-ok',
        'label' => l10n('Reinitialize check integrity'),
    ],
    'search' => [
        'icon' => 'icon-search',
        'label' => l10n('Purge search history'),
    ],
    'compiled-templates' => [
        'icon' => 'icon-file-code',
        'label' => l10n('Purge compiled templates'),
    ],
];

// +-----------------------------------------------------------------------+
// | tabs                                                                  |
// +-----------------------------------------------------------------------+

$my_base_url = get_root_url() . 'admin.php?page=';

if (isset($_GET['tab'])) {
    check_input_parameter('tab', $_GET, false, '/^(actions|env|sys)$/');
    // check_input_parameter() validates the raw value against the pattern
    // above (fatal_error()-ing on anything else) but does not narrow its
    // type for static analysis -- $_GET values are string|array<mixed> at
    // best, so re-check it is a string before trusting it as the tab name.
    $tab = $_GET['tab'];
    $page['tab'] = is_string($tab) ? $tab : 'actions';
} else {
    $page['tab'] = 'actions';
}

$tabsheet = new tabsheet();
$tabsheet->set_id('maintenance');
$tabsheet->select($page['tab']);
$tabsheet->assign();

include PHPWG_ROOT_PATH . 'admin/maintenance_' . $page['tab'] . '.php';

$template->assign(
    [
        'ADMIN_PAGE_TITLE' => l10n('Maintenance'),
    ]
);
