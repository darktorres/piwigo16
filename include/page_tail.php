<?php

declare(strict_types=1);

global $persistent_cache, $title, $debug, $t2;

use Piwigo\Admin\Updates;
use Piwigo\Template\TemplateRegistry;

// +-----------------------------------------------------------------------+
// | This file is part of Piwigo.                                          |
// |                                                                       |
// | For copyright and license information, please view the COPYING.txt    |
// | file that was distributed with this source code.                      |
// +-----------------------------------------------------------------------+

$template = TemplateRegistry::current();
$page = &$GLOBALS['page'];
if (!is_array($page)) {
    $page = [];
}

$template->set_filenames(['tail' => 'footer.tpl']);

trigger_notify('loc_begin_page_tail');

$template->assign(
    [
    'VERSION' => \Piwigo\Config\Config::showVersion() ? PHPWG_VERSION : '',
    'PHPWG_URL' => defined('PHPWG_URL') ? str_replace('http:', 'https:', PHPWG_URL) : '',
    ]
);

//--------------------------------------------------------------------- contact

if (!is_a_guest()) {
    $template->assign(
        'CONTACT_MAIL',
        get_webmaster_mail_address()
    );
}

//--------------------------------------------------------- update notification
if (\Piwigo\Config\Config::updateNotifyCheckPeriod() > 0) {
    $check_for_updates = false;
    if (\Piwigo\Config\Config::has('update_notify_last_check')) {
        if (strtotime((string) \Piwigo\Config\Config::updateNotifyLastCheck()) < strtotime(\Piwigo\Config\Config::updateNotifyCheckPeriod().' seconds ago')) {
            $check_for_updates = true;
        }
    } else {
        $check_for_updates = true;
    }

    if ($check_for_updates) {
        $exec_id = pwg_unique_exec_begins('check_for_updates');
        if (false !== $exec_id) {
            include_once(PHPWG_ROOT_PATH.'admin/include/functions.php');
            $updates = new Updates();
            $updates->notify_piwigo_new_versions();

            pwg_unique_exec_ends('check_for_updates');
        }
    }
}

send_piwigo_infos();

//------------------------------------------------------------- generation time
$debug_vars = [];

if (\Piwigo\Config\Config::showQueries()) {
    $debug_vars = array_merge($debug_vars, ['QUERIES_LIST' => $debug]);
}

if (\Piwigo\Config\Config::showGt()) {
    $pageState = \Piwigo\Core\PageState::current();
    $time = get_elapsed_time($t2, get_moment());

    $debug_vars = array_merge(
        $debug_vars,
        ['TIME' => $time,
            'NB_QUERIES' => $pageState->countQueries,
            'SQL_TIME' => number_format($pageState->queriesTime, 3, '.', ' ').' s']
    );
}

$template->assign('debug', $debug_vars);

//------------------------------------------------------------- mobile version
if (!empty(\Piwigo\Config\Config::mobilTheme()) && (get_device() != 'desktop' || mobile_theme())) {
    $template->assign(
        'TOGGLE_MOBILE_THEME_URL',
        add_url_params(
            htmlspecialchars(is_scalar($_SERVER['REQUEST_URI'] ?? null) ? (string) $_SERVER['REQUEST_URI'] : ''),
            ['mobile' => mobile_theme() ? 'false' : 'true']
        )
    );
}

trigger_notify('loc_end_page_tail');
//
// Generate the page
//
$template->parse('tail');
$template->p();
